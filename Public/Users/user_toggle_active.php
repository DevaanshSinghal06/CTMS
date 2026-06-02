<?php
require_once __DIR__ . '/../../App/Config/bootstrap.php';

require_role("admin");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: " . BASE_URL . "/Users/users.php");
    exit;
}

if (!verify_csrf()) {
    http_response_code(400);
    exit("Invalid or expired request token.");
}

$userId = $_POST["id"] ?? null;
$action = $_POST["action"] ?? "";

if (!$userId || !is_numeric($userId)) {
    header("Location: " . BASE_URL . "/Users/users.php");
    exit;
}

$userId = (int) $userId;
$currentUserId = (int) ($_SESSION["user_id"] ?? 0);

if ($userId === $currentUserId && $action === "deactivate") {
    header("Location: " . BASE_URL . "/Users/users.php?self_deactivate_denied=1");
    exit;
}

if (!in_array($action, ["activate", "deactivate"], true)) {
    header("Location: " . BASE_URL . "/Users/users.php");
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, first_name, last_name, email, active
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: " . BASE_URL . "/Users/users.php");
    exit;
}

$newActiveValue = $action === "activate" ? 1 : 0;

$stmt = $pdo->prepare("
    UPDATE users
    SET active = ?
    WHERE id = ?
");
$stmt->execute([$newActiveValue, $userId]);

$userNameForLog = trim(($user["first_name"] ?? "") . " " . ($user["last_name"] ?? ""));
$userEmailForLog = $user["email"] ?? "";

if ($action === "activate") {
    log_action(
        "activated",
        "user",
        $userId,
        "Activated user: " . $userNameForLog . " (" . $userEmailForLog . ")"
    );

    header("Location: " . BASE_URL . "/Users/users.php?activated=1");
    exit;
}

log_action(
    "deactivated",
    "user",
    $userId,
    "Deactivated user: " . $userNameForLog . " (" . $userEmailForLog . ")"
);

header("Location: " . BASE_URL . "/Users/users.php?deactivated=1");
exit;