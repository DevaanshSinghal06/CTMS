<?php
require_once __DIR__ . '/../../App/Config/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION["user_id"]) || !isset($_SESSION["role"])) {
    header("Location: " . BASE_URL . "/index.php");
    exit;
}

if ($_SESSION["role"] === "admin") {
    header("Location: " . BASE_URL . "/Dashboards/admin_dashboard.php");
    exit;
}

if ($_SESSION["role"] === "coordinator") {
    header("Location: " . BASE_URL . "/Dashboards/coordinator_dashboard.php");
    exit;
}

session_unset();
session_destroy();

header("Location: " . BASE_URL . "/index.php");
exit;