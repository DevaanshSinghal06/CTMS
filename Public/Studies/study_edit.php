<?php
require_once __DIR__ . '/../../App/Config/bootstrap.php';

require_any_role(["admin", "coordinator"]);

$isAdmin = $_SESSION["role"] === "admin";
$dashboardLink = $isAdmin
    ? BASE_URL . "/Dashboards/admin_dashboard.php"
    : BASE_URL . "/Dashboards/coordinator_dashboard.php";

$portalLabel = $isAdmin ? "Admin Portal" : "Research Coordinator Portal";

$error = "";

$studyId = $_GET["id"] ?? null;

if (!$studyId || !is_numeric($studyId)) {
    header("Location: " . BASE_URL . "/Studies/studies.php");
    exit;
}

$studyId = (int) $studyId;

$stmt = $pdo->prepare("SELECT * FROM studies WHERE id = ? LIMIT 1");
$stmt->execute([$studyId]);
$study = $stmt->fetch();

if (!$study) {
    header("Location: " . BASE_URL . "/Studies/studies.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verify_csrf()) {
        http_response_code(400);
        exit("Invalid or expired request token.");
    }

    $studyName = trim($_POST["study_name"] ?? "");
    $protocolNumber = trim($_POST["protocol_number"] ?? "");
    $sponsor = trim($_POST["sponsor"] ?? "");
    $croName = trim($_POST["cro_name"] ?? "");
    $principalInvestigator = trim($_POST["principal_investigator"] ?? "");
    $status = $_POST["status"] ?? "setup";
    $startDate = $_POST["start_date"] ?: null;
    $endDate = $_POST["end_date"] ?: null;
    $notes = trim($_POST["notes"] ?? "");

    $allowedStatuses = ["enrolling", "closed_to_enrollment", "terminated"];

    // Archived studies stay archived through an edit. The dropdown can't
    // express "archived", so preserve the loaded status and skip status
    // validation when the study is already archived.
    $isArchived = ($study["status"] === "archived");

    if ($studyName === "") {
        $error = "Study name is required.";
    } elseif (!$isArchived && !in_array($status, $allowedStatuses, true)) {
        $error = "Invalid study status.";
    } else {
        $finalStatus = $isArchived ? "archived" : $status;

        $stmt = $pdo->prepare("
            UPDATE studies
            SET
                study_name = ?,
                protocol_number = ?,
                sponsor = ?,
                cro_name = ?,
                principal_investigator = ?,
                status = ?,
                start_date = ?,
                end_date = ?,
                notes = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $studyName,
            $protocolNumber,
            $sponsor,
            $croName,
            $principalInvestigator,
            $finalStatus,
            $startDate,
            $endDate,
            $notes,
            $studyId
        ]);

        $studyCodeForLog = $study["study_code"] ?? "No Code";

        log_action(
            "updated",
            "study",
            $studyId,
            "Updated study " . $studyCodeForLog . ": " . $studyName
        );

        if ($finalStatus === "archived") {
            header("Location: " . BASE_URL . "/Studies/archived_studies.php?updated=1");
            exit;
        }

        header("Location: " . BASE_URL . "/Studies/studies.php?updated=1");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Study | CTMS</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/Assets/CSS/style.css">
</head>
<body>

<header class="site-header">
    <div class="top-bar">
        <div>Clinical Trial Management System</div>
        <div><?php echo htmlspecialchars($portalLabel); ?></div>
    </div>

    <nav class="main-nav">
        <a href="<?php echo BASE_URL; ?>/Auth/portal.php" class="brand">CTMS <span>Portal</span></a>

        <div class="nav-links">
            <a href="<?php echo htmlspecialchars($dashboardLink); ?>">Dashboard</a>
            <a href="<?php echo BASE_URL; ?>/Studies/studies.php">Studies</a>
            <a href="<?php echo BASE_URL; ?>/Studies/archived_studies.php">Archived</a>
            <a href="<?php echo BASE_URL; ?>/Auth/logout.php">Logout</a>
        </div>
    </nav>
</header>

<main class="page-wrapper">
    <section class="page-title">
        <h1>Edit Study</h1>
        <p>Update study details, status, dates, sponsor information, and notes.</p>
    </section>

    <?php if ($error !== ""): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <section class="card">
        <form method="POST" action="<?php echo BASE_URL; ?>/Studies/study_edit.php?id=<?php echo htmlspecialchars($studyId); ?>">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label for="study_code">Internal Study Code</label>
                <input 
                    type="text" 
                    id="study_code" 
                    value="<?php echo htmlspecialchars($study["study_code"] ?? ""); ?>"
                    readonly
                >
            </div>

            <div class="form-group">
                <label for="study_name">Study Name</label>
                <input 
                    type="text" 
                    id="study_name" 
                    name="study_name" 
                    value="<?php echo htmlspecialchars($study["study_name"] ?? ""); ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="protocol_number">Protocol Number</label>
                <input 
                    type="text" 
                    id="protocol_number" 
                    name="protocol_number"
                    value="<?php echo htmlspecialchars($study["protocol_number"] ?? ""); ?>"
                >
            </div>

            <div class="form-group">
                <label for="sponsor">Sponsor</label>
                <input 
                    type="text" 
                    id="sponsor" 
                    name="sponsor"
                    value="<?php echo htmlspecialchars($study["sponsor"] ?? ""); ?>"
                >
            </div>

            <div class="form-group">
                <label for="cro_name">CRO Name</label>
                <input 
                    type="text" 
                    id="cro_name" 
                    name="cro_name"
                    value="<?php echo htmlspecialchars($study["cro_name"] ?? ""); ?>"
                >
            </div>

            <div class="form-group">
                <label for="principal_investigator">Principal Investigator</label>
                <input 
                    type="text" 
                    id="principal_investigator" 
                    name="principal_investigator"
                    value="<?php echo htmlspecialchars($study["principal_investigator"] ?? ""); ?>"
                >
            </div>

            <div class="form-group">
                <label for="status">Status</label>
                <?php if ($study["status"] === "archived"): ?>
                    <input
                        type="text"
                        id="status"
                        value="Archived"
                        readonly
                    >
                    <p style="color: var(--text-muted); margin-top: 6px;">
                        Status is locked while the study is archived. Restore it from the Archived page to change it.
                    </p>
                <?php else: ?>
                    <select id="status" name="status">
                        <option value="enrolling" <?php echo $study["status"] === "enrolling" ? "selected" : ""; ?>>Enrolling</option>
                        <option value="closed_to_enrollment" <?php echo $study["status"] === "closed_to_enrollment" ? "selected" : ""; ?>>Closed to Enrollment</option>
                        <option value="terminated" <?php echo $study["status"] === "terminated" ? "selected" : ""; ?>>Terminated</option>
                    </select>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="start_date">Start Date</label>
                <input 
                    type="date" 
                    id="start_date" 
                    name="start_date"
                    value="<?php echo htmlspecialchars($study["start_date"] ?? ""); ?>"
                >
            </div>

            <div class="form-group">
                <label for="end_date">End Date</label>
                <input 
                    type="date" 
                    id="end_date" 
                    name="end_date"
                    value="<?php echo htmlspecialchars($study["end_date"] ?? ""); ?>"
                >
            </div>

            <div class="form-group">
                <label for="notes">Notes</label>
                <textarea 
                    id="notes" 
                    name="notes" 
                    rows="5"
                ><?php echo htmlspecialchars($study["notes"] ?? ""); ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary">
                Save Changes
            </button>

            <a 
                href="<?php echo $study["status"] === "archived" ? BASE_URL . "/Studies/archived_studies.php" : BASE_URL . "/Studies/studies.php"; ?>" 
                class="btn btn-secondary"
            >
                Cancel
            </a>
        </form>
    </section>
</main>

</body>
</html>