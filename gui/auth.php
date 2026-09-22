<?php
/**
 * Optional Keycloak (OpenID Connect) login for the Tinyproxy Manager GUI.
 *
 * The GUI mounts the Docker socket, so anyone who can reach it effectively has
 * root on the host. This module puts the whole GUI behind a Keycloak login
 * without requiring a reverse proxy or an extra sidecar container.
 *
 * It is OFF by default: with KEYCLOAK_ENABLED unset every entry point behaves
 * exactly as before. Once enabled, the module fails closed - a broken or
 * incomplete configuration blocks access instead of silently letting everyone
 * in.
 *
 * Flow: Authorization Code + PKCE against the realm's discovery document.
 * The ID token is verified locally (RS* signature via the realm JWKS, plus
 * iss/aud/exp/nonce) before a session is created.
 *
 * Configuration (environment variables, see README):
 *   KEYCLOAK_ENABLED                  1/true/yes to turn login on
 *   KEYCLOAK_ISSUER                   https://<host>/realms/<realm>
 *   KEYCLOAK_CLIENT_ID                confidential client id
 *   KEYCLOAK_CLIENT_SECRET            its client secret
 *   KEYCLOAK_REDIRECT_URI             optional; derived from the request if unset
 *   KEYCLOAK_SCOPES                   default "openid profile email"
 *   KEYCLOAK_ALLOWED_GROUPS           comma-separated; empty = any realm user
 *   KEYCLOAK_GROUPS_CLAIM             default "groups"
 *   KEYCLOAK_ALLOWED_ROLES            comma-separated realm/client roles
 *   KEYCLOAK_SESSION_TTL              seconds, default 43200 (12h)
 *   KEYCLOAK_POST_LOGOUT_REDIRECT     where Keycloak returns after logout
 *   KEYCLOAK_CA_BUNDLE                CA file for a private Keycloak CA
 *   KEYCLOAK_INSECURE_SKIP_TLS_VERIFY 1 to skip TLS verification (testing only)
 */

define('AUTH_COOKIE_NAME', 'TPM_SESSION');
define('AUTH_CALLBACK_PATH', '/auth-callback.php');
define('AUTH_LOGIN_PATH', '/login.php');
define('AUTH_CLOCK_LEEWAY', 60);
define('AUTH_DISCOVERY_TTL', 3600);

/**
 * Reads an environment variable, treating "unset" and "empty" the same way -
 * docker-compose happily passes through empty values for unset .env keys.
 */
function authEnv($name, $default = '') {
    $value = getenv($name);
    if ($value === false) {
        return $default;
    }
    $value = trim($value);
    return $value === '' ? $default : $value;
}

function authFlag($name) {
    $value = strtolower(authEnv($name, ''));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function authEnabled() {
    return authFlag('KEYCLOAK_ENABLED');
}

/**
 * Splits a comma/whitespace separated env value into a clean list.
 */
function authList($name) {
    $raw = authEnv($name, '');
    if ($raw === '') {
        return [];
    }
    $parts = preg_split('/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_unique(array_map('trim', $parts)));
}

/**
 * Full configuration plus the list of problems that make it unusable. Callers
 * must treat a non-empty 'errors' as "block the request".
 */
function authConfig() {
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = [
        'issuer'         => rtrim(authEnv('KEYCLOAK_ISSUER', ''), '/'),
        'client_id'      => authEnv('KEYCLOAK_CLIENT_ID', ''),
        'client_secret'  => authEnv('KEYCLOAK_CLIENT_SECRET', ''),
        'redirect_uri'   => authEnv('KEYCLOAK_REDIRECT_URI', ''),
        'scopes'         => authEnv('KEYCLOAK_SCOPES', 'openid profile email'),
        'groups'         => authList('KEYCLOAK_ALLOWED_GROUPS'),
        'groups_claim'   => authEnv('KEYCLOAK_GROUPS_CLAIM', 'groups'),
        'roles'          => authList('KEYCLOAK_ALLOWED_ROLES'),
        'ttl'            => max(300, (int)authEnv('KEYCLOAK_SESSION_TTL', '43200')),
        'post_logout'    => authEnv('KEYCLOAK_POST_LOGOUT_REDIRECT', ''),
        'ca_bundle'      => authEnv('KEYCLOAK_CA_BUNDLE', ''),
        'insecure_tls'   => authFlag('KEYCLOAK_INSECURE_SKIP_TLS_VERIFY'),
        'errors'         => [],
    ];

    if ($config['issuer'] === '') {
        $config['errors'][] = 'KEYCLOAK_ISSUER is not set.';
    } elseif (!preg_match('#^https?://#i', $config['issuer'])) {
        $config['errors'][] = 'KEYCLOAK_ISSUER must be a full URL, e.g. https://keycloak.example.com/realms/myrealm';
    }
    if ($config['client_id'] === '') {
        $config['errors'][] = 'KEYCLOAK_CLIENT_ID is not set.';
    }
    if ($config['client_secret'] === '') {
        $config['errors'][] = 'KEYCLOAK_CLIENT_SECRET is not set (the client must be confidential).';
    }
    if ($config['redirect_uri'] !== '' && !preg_match('#^https?://#i', $config['redirect_uri'])) {
        $config['errors'][] = 'KEYCLOAK_REDIRECT_URI must be a full URL.';
    }

    return $config;
}

/* -------------------------------------------------------------------------
 * Request / URL helpers
 * ---------------------------------------------------------------------- */

/**
 * External scheme+host of this GUI. Honors the X-Forwarded-* headers set by a
 * reverse proxy (NPM, Traefik, ...), since the container itself only ever
 * speaks plain HTTP on port 80.
 */
function authBaseUrl() {
    $scheme = 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $scheme = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
    } elseif (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        $scheme = 'https';
    }

    $host = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $host = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
    } elseif (!empty($_SERVER['HTTP_HOST'])) {
        $host = $_SERVER['HTTP_HOST'];
    } elseif (!empty($_SERVER['SERVER_NAME'])) {
        $host = $_SERVER['SERVER_NAME'];
    }

    return $scheme . '://' . $host;
}

function authIsHttps() {
    return strpos(authBaseUrl(), 'https://') === 0;
}

function authRedirectUri() {
    $configured = authConfig()['redirect_uri'];
    return $configured !== '' ? $configured : authBaseUrl() . AUTH_CALLBACK_PATH;
}

/**
 * Path the user was heading for, so the login can return them there. Only
 * same-site paths are ever accepted as a return target.
 */
function authCurrentPath() {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    return authSafeReturnPath($uri);
}

function authSafeReturnPath($path) {
    $path = (string)$path;
    // "//evil.com" and "https://evil.com" would redirect off-site after login.
    if ($path === '' || $path[0] !== '/' || (isset($path[1]) && $path[1] === '/')) {
        return '/';
    }
    return $path;
}

/* -------------------------------------------------------------------------
 * Session handling
 * ---------------------------------------------------------------------- */

function authStartSession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(AUTH_COOKIE_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => authIsHttps(),
        'httponly' => true,
        // Lax keeps the cookie off cross-site POSTs, which is what protects
        // the api.php write endpoints from CSRF once a session cookie exists.
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * The authenticated user, or null. Sessions past their TTL are dropped here,
 * which sends the next page view back through Keycloak (silently, if the
 * Keycloak SSO session is still alive).
 */
function authCurrentUser() {
    if (!authEnabled()) {
        return null;
    }
    authStartSession();

    $user = $_SESSION['auth_user'] ?? null;
    if (!is_array($user)) {
        return null;
    }
    if (($user['expires_at'] ?? 0) < time()) {
        unset($_SESSION['auth_user']);
        return null;
    }
    return $user;
}

function authClearSession() {
    authStartSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

/* -------------------------------------------------------------------------
 * Entry points used by the pages
 * ---------------------------------------------------------------------- */

/**
 * Guards a normal HTML page: redirects to Keycloak when no valid session.
 */
function authRequirePage() {
    if (!authEnabled()) {
        return;
    }

    $config = authConfig();
    if (!empty($config['errors'])) {
        authRenderNotice(
            'Login is misconfigured',
            'KEYCLOAK_ENABLED is on, but the OIDC settings are incomplete, so access is blocked:',
            $config['errors'],
            503
        );
    }

    if (authCurrentUser() !== null) {
        return;
    }

    // Deliberately not a straight redirect to Keycloak: an unauthenticated hit
    // gets the login dialog, so the jump to the identity provider only ever
    // happens on an explicit click (and a bookmarked deep link doesn't turn
    // into a surprise cross-site redirect).
    authRenderLoginPage(authCurrentPath());
}

/**
 * URL of the endpoint that actually starts the OIDC flow, carrying the path
 * the user was heading for.
 */
function authLoginUrl($returnTo = '/') {
    $returnTo = authSafeReturnPath($returnTo);
    if ($returnTo === '/') {
        return AUTH_LOGIN_PATH;
    }
    return AUTH_LOGIN_PATH . '?' . http_build_query(['return' => $returnTo]);
}

/**
 * Guards a JSON endpoint: answers 401 instead of redirecting, so fetch() calls
 * get a parseable body rather than Keycloak's login HTML.
 */
function authRequireApi() {
    if (!authEnabled()) {
        return;
    }

    $config = authConfig();
    if (!empty($config['errors'])) {
        authJsonError(503, 'Keycloak login is enabled but misconfigured: ' . implode(' ', $config['errors']), false);
    }

    if (authCurrentUser() !== null) {
        return;
    }

    authJsonError(401, 'Your session has expired. Please sign in again.', true);
}

function authJsonError($status, $message, $authRequired) {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    // api.php buffers its output; drop anything queued so the body stays valid JSON.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode([
        'success'       => false,
        'message'       => $message,
        'auth_required' => $authRequired,
        'login_url'     => '/',
    ]);
    exit;
}

/**
 * Starts the Authorization Code + PKCE flow and redirects to Keycloak.
 */
function authBeginLogin($returnTo = '/') {
    $config = authConfig();
    $discovery = authDiscovery();
    if ($discovery === null || empty($discovery['authorization_endpoint'])) {
        authRenderNotice(
            'Keycloak is unreachable',
            'The OIDC discovery document could not be loaded from the configured issuer.',
            [$config['issuer'] . '/.well-known/openid-configuration'],
            503
        );
    }

    authStartSession();
    // A fresh id for every login attempt - nothing from the pre-login session
    // should survive into the authenticated one.
    session_regenerate_id(true);

    $state = authRandomString();
    $nonce = authRandomString();
    $verifier = authRandomString(64);

    $_SESSION['auth_state']     = $state;
    $_SESSION['auth_nonce']     = $nonce;
    $_SESSION['auth_verifier']  = $verifier;
    $_SESSION['auth_return_to'] = authSafeReturnPath($returnTo);
    $_SESSION['auth_started']   = time();

    $params = [
        'client_id'             => $config['client_id'],
        'response_type'         => 'code',
        'scope'                 => $config['scopes'],
        'redirect_uri'          => authRedirectUri(),
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge'        => authBase64UrlEncode(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
    ];

    header('Location: ' . $discovery['authorization_endpoint'] . '?' . http_build_query($params), true, 302);
    exit;
}

/* -------------------------------------------------------------------------
 * OIDC plumbing
 * ---------------------------------------------------------------------- */

function authBase64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function authBase64UrlDecode($data) {
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

function authRandomString($bytes = 32) {
    return authBase64UrlEncode(random_bytes($bytes));
}

function authCacheDir() {
    $dir = sys_get_temp_dir() . '/tinyproxy-gui-oidc';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

/**
 * GETs JSON with a short timeout. Returns null on any transport/parse error -
 * callers turn that into a visible "Keycloak unreachable" page rather than a
 * half-open session.
 */
function authHttpGetJson($url) {
    $response = authHttpRequest($url, null);
    if ($response === null) {
        return null;
    }
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

function authHttpRequest($url, $postFields) {
    $config = authConfig();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => !$config['insecure_tls'],
        CURLOPT_SSL_VERIFYHOST => $config['insecure_tls'] ? 0 : 2,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    if ($config['ca_bundle'] !== '') {
        curl_setopt($ch, CURLOPT_CAINFO, $config['ca_bundle']);
    }
    if ($postFields !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    }

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $error !== '' || $status < 200 || $status >= 300) {
        error_log('[tinyproxy-gui/auth] request to ' . $url . ' failed (HTTP ' . $status . ') ' . $error);
        return null;
    }
    return $body;
}

/**
 * The realm's discovery document, cached on disk so every page view doesn't
 * hit Keycloak.
 */
function authDiscovery() {
    static $discovery = null;
    if ($discovery !== null) {
        return $discovery;
    }

    $config = authConfig();
    $cacheFile = authCacheDir() . '/discovery-' . sha1($config['issuer']) . '.json';

    if (is_readable($cacheFile) && (time() - (int)filemtime($cacheFile)) < AUTH_DISCOVERY_TTL) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['authorization_endpoint'])) {
            $discovery = $cached;
            return $discovery;
        }
    }

    $fetched = authHttpGetJson($config['issuer'] . '/.well-known/openid-configuration');
    if ($fetched === null || empty($fetched['authorization_endpoint'])) {
        return null;
    }

    @file_put_contents($cacheFile, json_encode($fetched), LOCK_EX);
    @chmod($cacheFile, 0600);
    $discovery = $fetched;
    return $discovery;
}

/**
 * The realm's signing keys. $forceRefresh re-fetches when a token arrives with
 * an unknown kid, which is how key rotation is picked up.
 */
function authJwks($forceRefresh = false) {
    $discovery = authDiscovery();
    if ($discovery === null || empty($discovery['jwks_uri'])) {
        return null;
    }

    $cacheFile = authCacheDir() . '/jwks-' . sha1($discovery['jwks_uri']) . '.json';
    if (!$forceRefresh && is_readable($cacheFile) && (time() - (int)filemtime($cacheFile)) < AUTH_DISCOVERY_TTL) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['keys'])) {
            return $cached;
        }
    }

    $fetched = authHttpGetJson($discovery['jwks_uri']);
    if ($fetched === null || empty($fetched['keys'])) {
        return null;
    }
    @file_put_contents($cacheFile, json_encode($fetched), LOCK_EX);
    @chmod($cacheFile, 0600);
    return $fetched;
}

/**
 * Builds a PEM public key from a JWK. PHP has no JWK importer, so the RSA
 * modulus/exponent are DER-encoded into a SubjectPublicKeyInfo by hand.
 */
function authPemFromJwk($jwk) {
    if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
        return null;
    }

    $modulus  = authDerInteger(authBase64UrlDecode($jwk['n']));
    $exponent = authDerInteger(authBase64UrlDecode($jwk['e']));
    $rsaKey   = authDerSequence($modulus . $exponent);

    // AlgorithmIdentifier: OID 1.2.840.113549.1.1.1 (rsaEncryption) + NULL
    $algorithm = authDerSequence(hex2bin('06092a864886f70d010101') . hex2bin('0500'));
    $bitString = "\x03" . authDerLength(strlen($rsaKey) + 1) . "\x00" . $rsaKey;
    $spki      = authDerSequence($algorithm . $bitString);

    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($spki), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

function authDerLength($length) {
    if ($length < 0x80) {
        return chr($length);
    }
    $bytes = ltrim(pack('N', $length), "\x00");
    return chr(0x80 | strlen($bytes)) . $bytes;
}

function authDerInteger($value) {
    $value = ltrim($value, "\x00");
    // A leading bit of 1 would make the INTEGER negative, so pad it.
    if ($value === '' || ord($value[0]) > 0x7f) {
        $value = "\x00" . $value;
    }
    return "\x02" . authDerLength(strlen($value)) . $value;
}

function authDerSequence($contents) {
    return "\x30" . authDerLength(strlen($contents)) . $contents;
}

/**
 * Verifies an ID token end to end: RS* signature against the realm JWKS, then
 * issuer, audience, expiry and the nonce from this browser's session.
 * Returns the claims, or null (with $error filled in) when anything is off.
 */
function authVerifyIdToken($idToken, $expectedNonce, &$error = null) {
    $parts = explode('.', (string)$idToken);
    if (count($parts) !== 3) {
        $error = 'The ID token is malformed.';
        return null;
    }

    [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
    $header = json_decode(authBase64UrlDecode($encodedHeader), true);
    $claims = json_decode(authBase64UrlDecode($encodedPayload), true);
    if (!is_array($header) || !is_array($claims)) {
        $error = 'The ID token could not be decoded.';
        return null;
    }

    $algorithms = ['RS256' => OPENSSL_ALGO_SHA256, 'RS384' => OPENSSL_ALGO_SHA384, 'RS512' => OPENSSL_ALGO_SHA512];
    $alg = $header['alg'] ?? '';
    if (!isset($algorithms[$alg])) {
        $error = 'Unsupported ID token signature algorithm: ' . $alg;
        return null;
    }

    $key = authFindSigningKey($header['kid'] ?? null);
    if ($key === null) {
        $error = 'No matching signing key was found in the realm JWKS.';
        return null;
    }

    $pem = authPemFromJwk($key);
    if ($pem === null) {
        $error = 'The realm signing key is not an RSA key.';
        return null;
    }

    $verified = openssl_verify(
        $encodedHeader . '.' . $encodedPayload,
        authBase64UrlDecode($encodedSignature),
        $pem,
        $algorithms[$alg]
    );
    if ($verified !== 1) {
        $error = 'The ID token signature is invalid.';
        return null;
    }

    $config = authConfig();
    $now = time();

    if (($claims['iss'] ?? '') !== $config['issuer']) {
        $error = 'The ID token was issued by an unexpected issuer.';
        return null;
    }

    $audience = $claims['aud'] ?? [];
    $audience = is_array($audience) ? $audience : [$audience];
    if (!in_array($config['client_id'], $audience, true)) {
        $error = 'The ID token was not issued for this client.';
        return null;
    }
    // With several audiences the authorized party must still be us.
    if (count($audience) > 1 && ($claims['azp'] ?? $config['client_id']) !== $config['client_id']) {
        $error = 'The ID token was authorized for a different client.';
        return null;
    }

    if (!isset($claims['exp']) || ($claims['exp'] + AUTH_CLOCK_LEEWAY) < $now) {
        $error = 'The ID token has expired.';
        return null;
    }
    if (isset($claims['nbf']) && ($claims['nbf'] - AUTH_CLOCK_LEEWAY) > $now) {
        $error = 'The ID token is not valid yet.';
        return null;
    }
    if ($expectedNonce !== null && !hash_equals((string)$expectedNonce, (string)($claims['nonce'] ?? ''))) {
        $error = 'The ID token nonce does not match this login attempt.';
        return null;
    }

    return $claims;
}

function authFindSigningKey($kid) {
    foreach ([false, true] as $forceRefresh) {
        $jwks = authJwks($forceRefresh);
        if ($jwks === null) {
            continue;
        }
        foreach ($jwks['keys'] as $key) {
            if (($key['use'] ?? 'sig') !== 'sig') {
                continue;
            }
            if ($kid === null || ($key['kid'] ?? null) === $kid) {
                return $key;
            }
        }
    }
    return null;
}

/* -------------------------------------------------------------------------
 * Authorization (who may actually use the GUI)
 * ---------------------------------------------------------------------- */

/**
 * All group and role names carried by the token, normalized. Keycloak writes
 * groups with a leading slash ("/tinyproxy-admins"), so both spellings are
 * accepted in KEYCLOAK_ALLOWED_GROUPS.
 */
function authClaimGroups($claims) {
    $config = authConfig();
    $raw = $claims[$config['groups_claim']] ?? [];
    $raw = is_array($raw) ? $raw : [$raw];

    $groups = [];
    foreach ($raw as $group) {
        if (!is_scalar($group)) {
            continue;
        }
        $group = (string)$group;
        $groups[] = $group;
        $groups[] = ltrim($group, '/');
    }
    return array_values(array_unique($groups));
}

function authClaimRoles($claims) {
    $config = authConfig();
    $roles = $claims['realm_access']['roles'] ?? [];
    $roles = is_array($roles) ? $roles : [];

    $clientRoles = $claims['resource_access'][$config['client_id']]['roles'] ?? [];
    if (is_array($clientRoles)) {
        $roles = array_merge($roles, $clientRoles);
    }
    return array_values(array_unique(array_filter($roles, 'is_string')));
}

/**
 * Checks the group/role allow-lists. With neither configured, every user the
 * realm authenticates may in - which is why the README pushes for a dedicated
 * group.
 */
function authIsAuthorized($claims, &$reason = null) {
    $config = authConfig();
    if (empty($config['groups']) && empty($config['roles'])) {
        return true;
    }

    if (!empty($config['groups']) && array_intersect($config['groups'], authClaimGroups($claims))) {
        return true;
    }
    if (!empty($config['roles']) && array_intersect($config['roles'], authClaimRoles($claims))) {
        return true;
    }

    $required = array_merge($config['groups'], $config['roles']);
    $reason = 'Your account is not a member of: ' . implode(', ', $required);
    return false;
}

/**
 * Condenses the verified claims into what the session actually needs.
 */
function authUserFromClaims($claims) {
    $name = $claims['preferred_username']
        ?? $claims['name']
        ?? $claims['email']
        ?? $claims['sub']
        ?? 'unknown';

    return [
        'sub'        => (string)($claims['sub'] ?? ''),
        'name'       => (string)$name,
        'email'      => (string)($claims['email'] ?? ''),
        'groups'     => authClaimGroups($claims),
        'roles'      => authClaimRoles($claims),
        'login_at'   => time(),
        'expires_at' => time() + authConfig()['ttl'],
    ];
}

/* -------------------------------------------------------------------------
 * Logout
 * ---------------------------------------------------------------------- */

/**
 * RP-initiated logout URL, so signing out here also ends the Keycloak SSO
 * session instead of bouncing straight back in.
 */
function authKeycloakLogoutUrl($idTokenHint = null) {
    $discovery = authDiscovery();
    $config = authConfig();
    if ($discovery === null || empty($discovery['end_session_endpoint'])) {
        return null;
    }

    $postLogout = $config['post_logout'] !== '' ? $config['post_logout'] : authBaseUrl() . '/';
    $params = ['post_logout_redirect_uri' => $postLogout, 'client_id' => $config['client_id']];
    if ($idTokenHint) {
        $params['id_token_hint'] = $idTokenHint;
    }

    return $discovery['end_session_endpoint'] . '?' . http_build_query($params);
}

/* -------------------------------------------------------------------------
 * Rendering
 * ---------------------------------------------------------------------- */

/**
 * The login dialog: a modal with a single "Sign in with Keycloak" button.
 * Shown instead of the app whenever an unauthenticated browser asks for a
 * page. The button is the only way into the OIDC flow - clicking it hits
 * login.php, which mints state/nonce/PKCE and redirects to Keycloak.
 *
 * Reachable without a session, so it must not expose anything from the app.
 */
function authRenderLoginPage($returnTo = '/') {
    if (!headers_sent()) {
        http_response_code(401);
        header('Content-Type: text/html; charset=utf-8');
        // A cached login page would keep showing up after signing in.
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }
    $styleVersion = @filemtime(__DIR__ . '/style.css') ?: time();
    $loginUrl = authLoginUrl($returnTo);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in - Tinyproxy Manager</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="stylesheet" href="/style.css?v=<?php echo $styleVersion; ?>">
    <script>
        // Same theme the app uses, applied before paint so the dialog does not flash.
        document.documentElement.setAttribute('data-theme', localStorage.getItem('theme') || 'dark');
    </script>
</head>
<body class="login-body">
    <div class="login-overlay">
        <div class="login-dialog" role="dialog" aria-modal="true" aria-labelledby="login-title">
            <div class="login-dialog-icon">🔒</div>
            <h1 class="login-dialog-title" id="login-title">Tinyproxy Manager</h1>
            <p class="login-dialog-text">Sign in to continue.</p>
            <a class="login-dialog-btn" href="<?php echo htmlspecialchars($loginUrl); ?>">
                <span class="login-dialog-btn-icon">🔑</span>
                Sign in with Keycloak
            </a>
        </div>
    </div>
</body>
</html>
    <?php
    exit;
}

/**
 * Standalone notice page (misconfiguration, denied access, failed login). It
 * carries the GUI's stylesheet but nothing else - it is reachable without a
 * session, so it must not expose any of the app.
 */
function authRenderNotice($title, $message, $details = [], $status = 403, $showRetry = true) {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
    }
    $styleVersion = @filemtime(__DIR__ . '/style.css') ?: time();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> - Tinyproxy Manager</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="stylesheet" href="/style.css?v=<?php echo $styleVersion; ?>">
</head>
<body>
    <div class="container auth-notice">
        <h1>🔒 Tinyproxy Manager</h1>
        <div class="card">
            <h2><?php echo htmlspecialchars($title); ?></h2>
            <p class="help-text"><?php echo htmlspecialchars($message); ?></p>
            <?php if (!empty($details)): ?>
            <ul class="auth-notice-details">
                <?php foreach ($details as $detail): ?>
                <li><?php echo htmlspecialchars($detail); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <div class="auth-notice-actions">
                <?php if ($showRetry): ?>
                <a class="btn-sync-allow" href="/">↻ Try again</a>
                <?php endif; ?>
                <a class="btn-restart" href="/logout.php">🚪 Sign out</a>
            </div>
        </div>
    </div>
</body>
</html>
    <?php
    exit;
}

/**
 * Wraps fetch() so an expired session doesn't surface as a cryptic API error:
 * api.php answers 401 with auth_required, and the page then re-enters the
 * login (silently, if the Keycloak SSO session is still alive).
 * Renders nothing when login is off.
 */
function authRenderSessionGuardScript() {
    if (!authEnabled()) {
        return;
    }
    ?>
    <script>
        // Session guard - added by auth.php while Keycloak login is enabled.
        (function () {
            const originalFetch = window.fetch.bind(window);
            window.fetch = async function (...args) {
                const response = await originalFetch(...args);
                if (response.status === 401) {
                    try {
                        const body = await response.clone().json();
                        if (body && body.auth_required) {
                            window.location.href = body.login_url || '/';
                            // Never settles: keeps the caller from flashing an
                            // error while the browser is already navigating.
                            return new Promise(() => {});
                        }
                    } catch (e) {
                        // Not our JSON - hand the response back untouched.
                    }
                }
                return response;
            };
        })();
    </script>
    <?php
}

/**
 * The "signed in as ..." chip in the page's top bar. Renders nothing when
 * login is off, so both pages can call it unconditionally.
 */
function authRenderUserBadge() {
    $user = authCurrentUser();
    if ($user === null) {
        return;
    }
    ?>
    <div class="user-badge" title="Signed in via Keycloak<?php echo $user['email'] !== '' ? ' (' . htmlspecialchars($user['email']) . ')' : ''; ?>">
        <span class="user-badge-icon">👤</span>
        <span class="user-badge-name"><?php echo htmlspecialchars($user['name']); ?></span>
        <a class="user-badge-logout" href="/logout.php" title="Sign out">Sign out</a>
    </div>
    <?php
}
