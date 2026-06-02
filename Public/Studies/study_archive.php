<?php
require_once __DIR__ . '/../../App/Config/bootstrap.php';

require_role("admin");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: " . BASE_URL . "/Studies/studies.php");
    exit;
}

if (!verify_csrf()) {
    http_response_code(400);
    exit("Invalid or expired request token.");
}

$studyId = $_POST["id"] ?? null;

if (!$studyId || !is_numeric($studyId)) {
    header("Location: " . BASE_URL . "/Studies/studies.php");
    exit;
}

$studyId = (int) $studyId;

$stmt = $pdo->prepare("
    SELECT study_code, study_name
    FROM studies
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$studyId]);
$study = $stmt->fetch();

if (!$study) {
    header("Location: " . BASE_URL . "/Studies/studies.php");
    exit;
}

$stmt = $pdo->prepare("
    UPDATE studies
    SET status = 'archived'
    WHERE id = ?
");

$stmt->execute([$studyId]);

$studyCodeForLog = $study["study_code"] ?? "No Code";
$studyNameForLog = $study["study_name"] ?? "Unknown Study";

log_action(
    "archived",
    "study",
    $studyId,
    "Archived study from studies table: " . $studyCodeForLog . " - " . $studyNameForLog
);

header("Location: " . BASE_URL . "/Studies/studies.php?archived=1");
exit;