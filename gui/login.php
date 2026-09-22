<?php
/**
 * Starts the Keycloak login. This is what the "Sign in with Keycloak" button
 * on the login dialog points at - the redirect to the identity provider is
 * never triggered by merely opening a page.
 *
 * ?return=<path> carries the page the user originally asked for; only
 * same-site paths are accepted (authSafeReturnPath).
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
        'KEYCLOAK_ENABLED is on, but the OIDC settings are incomplete, so access is blocked:',
        $config['errors'],
        503
    );
}

$returnTo = authSafeReturnPath($_GET['return'] ?? '/');

// Already signed in (second tab, back button): no point bouncing off Keycloak.
if (authCurrentUser() !== null) {
    header('Location: ' . $returnTo, true, 302);
    exit;
}

authBeginLogin($returnTo);
