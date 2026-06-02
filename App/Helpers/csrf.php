<?php
// App/Helpers/csrf.php

function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["csrf_token"];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

function verify_csrf(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $sessionToken = $_SESSION["csrf_token"] ?? "";
    $postedToken = $_POST["csrf_token"] ?? "";

    return $sessionToken !== ""
        && is_string($postedToken)
        && hash_equals($sessionToken, $postedToken);
}