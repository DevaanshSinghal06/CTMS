<?php

function require_login(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION["user_id"])) {
        header("Location: " . BASE_URL . "/Auth/login.php");
        exit;
    }
}

function require_role(string $role): void
{
    require_login();

    if ($_SESSION["role"] !== $role) {
        if ($_SESSION["role"] === "admin") {
            header("Location: " . BASE_URL . "/Dashboards/admin_dashboard.php");
            exit;
        }

        if ($_SESSION["role"] === "coordinator") {
            header("Location: " . BASE_URL . "/Dashboards/coordinator_dashboard.php");
            exit;
        }

        header("Location: " . BASE_URL . "/index.php");
        exit;
    }
}

function require_any_role(array $roles): void
{
    require_login();

    if (!in_array($_SESSION["role"], $roles, true)) {
        header("Location: " . BASE_URL . "/Auth/portal.php");
        exit;
    }
}