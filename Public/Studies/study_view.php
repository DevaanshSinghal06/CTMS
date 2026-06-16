<?php
require_once __DIR__ . '/../../App/Config/bootstrap.php';

require_any_role(["admin", "coordinator"]);

$isAdmin = $_SESSION["role"] === "admin";
$currentUserId = (int) ($_SESSION["user_id"] ?? 0);

$dashboardLink = $isAdmin
    ? BASE_URL . "/Dashboards/admin_dashboard.php"
    : BASE_URL . "/Dashboards/coordinator_dashboard.php";

$portalLabel = $isAdmin ? "Admin Portal" : "Research Coordinator Portal";

$studyId = $_GET["id"] ?? null;

if (!$studyId || !is_numeric($studyId)) {
    header("Location: " . BASE_URL . "/Studies/studies.php");
    exit;
}

$studyId = (int) $studyId;

$stmt = $pdo->prepare("
    SELECT *
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

if (!$isAdmin) {
    $stmt = $pdo->prepare("
        SELECT id
        FROM study_assignments
        WHERE study_id = ?
            AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$studyId, $currentUserId]);
    $assignment = $stmt->fetch();

    if (!$assignment) {
        header("Location: " . BASE_URL . "/Studies/studies.php?access_denied=1");
        exit;
    }
}

$stmt = $pdo->prepare("
    SELECT
        study_assignments.assignment_role,
        users.first_name,
        users.last_name,
        users.email
    FROM study_assignments
    INNER JOIN users
        ON study_assignments.user_id = users.id
    WHERE study_assignments.study_id = ?
    ORDER BY FIELD(study_assignments.assignment_role, 'lead', 'backup')
");
$stmt->execute([$studyId]);
$assignments = $stmt->fetchAll();

$leadCoordinator = "Unassigned";
$backupCoordinator = "Unassigned";

foreach ($assignments as $assignment) {
    $coordinatorName = trim(($assignment["first_name"] ?? "") . " " . ($assignment["last_name"] ?? ""));

    if ($coordinatorName === "") {
        $coordinatorName = $assignment["email"] ?? "Unknown User";
    }

    if ($assignment["assignment_role"] === "lead") {
        $leadCoordinator = $coordinatorName;
    }

    if ($assignment["assignment_role"] === "backup") {
        $backupCoordinator = $coordinatorName;
    }
}

$statusLabels = [
    "enrolling" => "Enrolling",
    "closed_to_enrollment" => "Closed to Enrollment",
    "terminated" => "Terminated",
    "archived" => "Archived"
];

$statusLabel = $statusLabels[$study["status"]] ?? $study["status"];

$stmt = $pdo->prepare("
    SELECT COUNT(*) AS total
    FROM study_subjects
    WHERE study_id = ?
");
$stmt->execute([$studyId]);
$subjectCountRow = $stmt->fetch();
$subjectCount = $subjectCountRow["total"] ?? 0;

$competitiveEnrollmentLabel = "N/A";

if ($study["competitive_enrollment"] !== null) {
    $competitiveEnrollmentLabel = (int) $study["competitive_enrollment"] === 1 ? "Yes" : "No";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Study Details | CTMS</title>
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

            <?php if ($isAdmin): ?>
                <a href="<?php echo BASE_URL; ?>/Studies/study_assignments.php">Assignments</a>
                <a href="<?php echo BASE_URL; ?>/Users/users.php">Users</a>
                <a href="<?php echo BASE_URL; ?>/Audit/audit_logs.php">Audit Log</a>
            <?php endif; ?>

            <a href="<?php echo BASE_URL; ?>/Auth/logout.php">Logout</a>
        </div>
    </nav>
</header>

<main class="page-wrapper">
    <section class="page-title">
        <h1><?php echo htmlspecialchars($study["study_name"]); ?></h1>
        <p>
            <?php echo htmlspecialchars($study["study_code"] ?? ""); ?>
            <?php if (!empty($study["protocol_number"])): ?>
                | Protocol: <?php echo htmlspecialchars($study["protocol_number"]); ?>
            <?php endif; ?>
        </p>
    </section>

    <section class="card-grid">
        <div class="card">
            <h3>Status</h3>
            <div class="stat-number" style="font-size: 24px;">
                <?php echo htmlspecialchars($statusLabel); ?>
            </div>
            <p>Current study status.</p>
        </div>

        <div class="card">
            <h3>Lead Coordinator</h3>
            <div class="stat-number" style="font-size: 22px;">
                <?php echo htmlspecialchars($leadCoordinator); ?>
            </div>
            <p>Main coordinator assigned to this study.</p>
        </div>

        <div class="card">
            <h3>Backup Coordinator</h3>
            <div class="stat-number" style="font-size: 22px;">
                <?php echo htmlspecialchars($backupCoordinator); ?>
            </div>
            <p>Backup coordinator assigned to this study.</p>
        </div>

        <a 
            href="<?php echo BASE_URL; ?>/Studies/study_edit.php?id=<?php echo htmlspecialchars($study["id"]); ?>" 
            class="card card-link"
        >
            <h3>Edit Study</h3>
            <div class="stat-number">✎</div>
            <p>Update study overview information.</p>
        </a>
    </section>

    <section class="card" style="margin-bottom: 28px;">
        <h3>Overview</h3>

        <table>
            <tbody>
                <tr>
                    <th>Study Code</th>
                    <td><?php echo htmlspecialchars($study["study_code"] ?? ""); ?></td>
                </tr>
                <tr>
                    <th>Study Name</th>
                    <td><?php echo htmlspecialchars($study["study_name"] ?? ""); ?></td>
                </tr>
                <tr>
                    <th>Protocol Number</th>
                    <td><?php echo htmlspecialchars($study["protocol_number"] ?? ""); ?></td>
                </tr>
                <tr>
                    <th>Sponsor</th>
                    <td><?php echo htmlspecialchars($study["sponsor"] ?? ""); ?></td>
                </tr>
                <tr>
                    <th>CRO</th>
                    <td><?php echo htmlspecialchars($study["cro_name"] ?? ""); ?></td>
                </tr>
                <tr>
                    <th>Principal Investigator</th>
                    <td><?php echo htmlspecialchars($study["principal_investigator"] ?? ""); ?></td>
                </tr>
                <tr>
                    <th>Start Date</th>
                    <td><?php echo htmlspecialchars($study["start_date"] ?: "N/A"); ?></td>
                </tr>
                <tr>
                    <th>End Date</th>
                    <td><?php echo htmlspecialchars($study["end_date"] ?: "N/A"); ?></td>
                </tr>
                <tr>
                    <th>FPFV Date</th>
                    <td><?php echo htmlspecialchars($study["fpfv_date"] ?: "N/A"); ?></td>
                </tr>
                <tr>
                    <th>LPFV Date</th>
                    <td><?php echo htmlspecialchars($study["lpfv_date"] ?: "N/A"); ?></td>
                </tr>
                <tr>
                    <th>LPLV Date</th>
                    <td><?php echo htmlspecialchars($study["lplv_date"] ?: "N/A"); ?></td>
                </tr>
                <tr>
                    <th>Enrollment Closing Date</th>
                    <td><?php echo htmlspecialchars($study["enrollment_closing_date"] ?: "N/A"); ?></td>
                </tr>
                <tr>
                    <th>Study Termination Date</th>
                    <td><?php echo htmlspecialchars($study["study_termination_date"] ?: "N/A"); ?></td>
                </tr>
                <tr>
                    <th>Competitive Enrollment</th>
                    <td><?php echo htmlspecialchars($competitiveEnrollmentLabel); ?></td>
                </tr>
                <tr>
                    <th>Budgeted Enrollment Number</th>
                    <td><?php echo htmlspecialchars($study["budgeted_enrollment_number"] ?? "N/A"); ?></td>
                </tr>
                <tr>
                    <th>Internal Site Target</th>
                    <td><?php echo htmlspecialchars($study["site_enrollment_target"] ?? "N/A"); ?></td>
                </tr>
                <tr>
                    <th>Notes</th>
                    <td><?php echo nl2br(htmlspecialchars($study["notes"] ?? "")); ?></td>
                </tr>
            </tbody>
        </table>
    </section>

    <section class="card-grid">
        <a 
            href="<?php echo BASE_URL; ?>/Studies/study_subjects.php?id=<?php echo htmlspecialchars($study["id"]); ?>" 
            class="card card-link"
        >
            <h3>Subjects / Screening</h3>
            <div class="stat-number"><?php echo htmlspecialchars($subjectCount); ?></div>
            <p>Link subjects to this study and update screening/enrollment status.</p>
        </a>

        <div class="card">
            <h3>Contacts</h3>
            <div class="stat-number">0</div>
            <p>CRA, CTL, medical monitor, and sponsor/CRO contacts.</p>
        </div>

        <div class="card">
            <h3>Recruitment</h3>
            <div class="stat-number">0</div>
            <p>Track site target, budgeted enrollment, and recruitment status.</p>
        </div>

        <a 
            href="<?php echo BASE_URL; ?>/Studies/study_startup_checklist.php?id=<?php echo htmlspecialchars($study["id"]); ?>" 
            class="card card-link"
        >
            <h3>Startup Checklist</h3>
            <div class="stat-number">Open</div>
            <p>Track startup activities, regulatory items, training, SIV, and activation.</p>
        </a>
    </section>
</main>

</body>
</html>