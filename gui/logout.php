<?php
/**
 * Ends the GUI session and, when Keycloak supports RP-initiated logout, the
 * Keycloak SSO session too - otherwise the next page view would silently sign
 * the same user straight back in.
 */
require_once __DIR__ . '/auth.php';

if (!authEnabled()) {
    header('Location: /', true, 302);
    exit;
}

authStartSession();
$idTokenHint = $_SESSION['auth_id_token'] ?? null;
authClearSession();

$logoutUrl = authKeycloakLogoutUrl($idTokenHint);
header('Location: ' . ($logoutUrl ?? '/'), true, 302);
exit;
