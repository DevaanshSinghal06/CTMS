<?php
require_once __DIR__ . '/../../App/Config/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

session_unset();
session_destroy();

header("Location: " . BASE_URL . "/Auth/login.php");
exit;