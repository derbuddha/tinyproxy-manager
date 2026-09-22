<?php
/**
 * OIDC redirect target - the "Valid redirect URI" registered in Keycloak
 * (https://<gui-host>/auth-callback.php).
 *
 * Exchanges the authorization code for tokens, verifies the ID token, checks
 * the group/role allow-list and only then creates the GUI session.
 */
require_once __DIR__ . '/auth.php';

if (!authEnabled()) {
    header('Location: /', true, 302);
    exit;
}

$config = authConfig();
if (!empty($config['errors'])) {
    authRenderNotice(
        'Login is misconfigured',
        'KEYCLOAK_ENABLED is on, but the OIDC settings are incomplete:',
        $config['errors'],
        503
    );
}

authStartSession();

// Keycloak reports its own failures (access_denied, consent_required, ...) here.
if (isset($_GET['error'])) {
    $description = isset($_GET['error_description']) ? (string)$_GET['error_description'] : '';
    authRenderNotice(
        'Keycloak refused the login',
        $description !== '' ? $description : 'Keycloak returned: ' . (string)$_GET['error'],
        [],
        403
    );
}

$state = isset($_GET['state']) ? (string)$_GET['state'] : '';
$code  = isset($_GET['code']) ? (string)$_GET['code'] : '';
$expectedState = $_SESSION['auth_state'] ?? null;
$expectedNonce = $_SESSION['auth_nonce'] ?? null;
$verifier      = $_SESSION['auth_verifier'] ?? null;
$returnTo      = authSafeReturnPath($_SESSION['auth_return_to'] ?? '/');

// The login attempt is single-use: drop it before anything can go wrong below,
// so a replayed callback URL can't be used twice.
unset($_SESSION['auth_state'], $_SESSION['auth_nonce'], $_SESSION['auth_verifier'], $_SESSION['auth_return_to']);

if ($code === '' || $expectedState === null || !hash_equals((string)$expectedState, $state)) {
    // Also the normal case for a stale bookmark of the callback URL.
    authRenderNotice(
        'This login attempt is no longer valid',
        'The login state did not match - it may have expired, or the page was opened directly. Start again from the GUI.',
        [],
        400
    );
}

$discovery = authDiscovery();
if ($discovery === null || empty($discovery['token_endpoint'])) {
    authRenderNotice(
        'Keycloak is unreachable',
        'The token endpoint could not be resolved from the issuer configuration.',
        [$config['issuer']],
        503
    );
}

$response = authHttpRequest($discovery['token_endpoint'], [
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'redirect_uri'  => authRedirectUri(),
    'client_id'     => $config['client_id'],
    'client_secret' => $config['client_secret'],
    'code_verifier' => (string)$verifier,
]);

$tokens = $response !== null ? json_decode($response, true) : null;
if (!is_array($tokens) || empty($tokens['id_token'])) {
    authRenderNotice(
        'Token exchange failed',
        'Keycloak did not return an ID token for this login. Check the client id, client secret and the registered redirect URI.',
        [authRedirectUri()],
        502
    );
}

$error = null;
$claims = authVerifyIdToken($tokens['id_token'], $expectedNonce, $error);
if ($claims === null) {
    authRenderNotice('The login could not be verified', (string)$error, [], 403);
}

$reason = null;
if (!authIsAuthorized($claims, $reason)) {
    error_log('[tinyproxy-gui/auth] access denied for ' . ($claims['preferred_username'] ?? $claims['sub'] ?? '?') . ': ' . $reason);
    authRenderNotice(
        'Access denied',
        'You signed in successfully, but your account is not allowed to use the Tinyproxy Manager.',
        $reason !== null ? [$reason] : [],
        403,
        false
    );
}

// New session id now that the identity changed - a pre-login cookie must not
// become an authenticated one.
session_regenerate_id(true);
$_SESSION['auth_user'] = authUserFromClaims($claims);
// Kept only so logout can hand Keycloak an id_token_hint.
$_SESSION['auth_id_token'] = (string)$tokens['id_token'];

error_log('[tinyproxy-gui/auth] signed in: ' . $_SESSION['auth_user']['name']);

header('Location: ' . $returnTo, true, 302);
exit;
