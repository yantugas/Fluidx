<?php

if (session_status() === PHP_SESSION_NONE) {

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,   // only send cookie over HTTPS when available
        'httponly' => true,       // JS can't read the session cookie
        'samesite' => 'Lax',      // basic CSRF mitigation
    ]);

    session_start();
}