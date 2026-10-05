<?php
/** POST-only logout: clear session state and expire the session cookie before redirecting. */
require __DIR__ . '/../includes/bootstrap.php';
post_only();

$_SESSION = [];
$params = session_get_cookie_params();
$cookieOptions = [
    'expires' => time() - 42000,
    'path' => $params['path'] !== '' ? $params['path'] : '/',
    'secure' => (bool)$params['secure'],
    'httponly' => true,
    'samesite' => $params['samesite'] !== '' ? $params['samesite'] : 'Lax',
];
if ($params['domain'] !== '') {
    $cookieOptions['domain'] = $params['domain'];
}
setcookie(session_name(), '', $cookieOptions);
session_destroy();
go('/');
