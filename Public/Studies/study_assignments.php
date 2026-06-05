<?php
require_once __DIR__ . '/../../App/Config/bootstrap.php';

require_role("admin");

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $studyId = $_POST["study_id"] ?? null;
    $leadCoordinatorId = $_POST["lead_coordinator_id"] ?? "";
    $backupCoordinatorId = $_POST["backup_coordinator_id"] ?? "";

    if (!$studyId || !is_numeric($studyId)) {
        $error = "Invalid study selected.";
    } else {
        $studyId = (int) $studyId;
        $leadCoordinatorId = $leadCoordinatorId !== "" ? (int) $leadCoordinatorId : null;
        $backupCoordinatorId = $backupCoordinatorId !== "" ? (int) $backupCoordinatorId : null;

        if ($leadCoordinatorId !== null && $backupCoordinatorId !== null && $leadCoordinatorId === $backupCoordinatorId) {
            $error = "Lead and backup coordinator cannot be the same person.";
        } else {
            $stmt = $pdo->prepare("
                SELECT id, study_code, study_name
                FROM studies
                WHERE id = ? AND status != 'archived'
                LIMIT 1
            ");
            $stmt->execute([$studyId]);
            $study = $stmt->fetch();

            if (!$study) {
                $error = "Study not found or archived.";
            } else {
                $stmt = $pdo->query("
                    SELECT id
                    FROM users
                    WHERE role = 'coordinator' AND active = 1
                ");
                $validCoordinatorIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

                $validCoordinatorIds = array_map("intval", $validCoordinatorIds);

                $leadIsValid = $leadCoordinatorId === null || in_array($leadCoordinatorId, $validCoordinatorIds, true);
                $backupIsValid = $backupCoordinatorId === null || in_array($backupCoordinatorId, $validCoordinatorIds, true);

                if (!$leadIsValid || !$backupIsValid) {
                    $error = "Invalid coordinator selected.";
                } else {
                    try {
                        $pdo->beginTransaction();

                        $assignmentData = [
                            "lead" => $leadCoordinatorId,
                            "backup" => $backupCoordinatorId
                        ];

                        foreach ($assignmentData as $assignmentRole => $coordinatorId) {
                            if ($coordinatorId === null) {
                                $stmt = $pdo->prepare("
                                    DELETE FROM study_assignments
                                    WHERE study_id = ? AND assignment_role = ?
                                ");
                                $stmt->execute([$studyId, $assignmentRole]);
                            } else {
                                $stmt = $pdo->prepare("
                                    INSERT INTO study_assignments
                                    (
                                        study_id,
                                        user_id,
                                        assignment_role,
                                        assigned_by
                                    )
                                    VALUES
                                    (?, ?, ?, ?)
                                    ON DUPLICATE KEY UPDATE
                                        user_id = VALUES(user_id),
                                        assigned_by = VALUES(assigned_by),
                                        assigned_at = CURRENT_TIMESTAMP
                                ");

                                $stmt->execute([
                                    $studyId,
                                    $coordinatorId,
                                    $assignmentRole,
                                    $_SESSION["user_id"] ?? null
                                ]);
                            }
                        }

                        $pdo->commit();

                        log_action(
                            "updated",
                            "study",
                            $studyId,
                            "Updated coordinator assignments for " . ($study["study_code"] ?? "Study") . ": " . $study["study_name"]
                        );

                        header("Location: " . BASE_URL . "/Studies/study_assignments.php?updated=1");
                        exit;
                    } catch (PDOException $e) {
                        $pdo->rollBack();
                        $error = "Coordinator assignments could not be saved.";
                    }
                }
            }
        }
    }
}

if (isset($_GET["updated"])) {
    $success = "Study assignments updated successfully.";
}

$stmt = $pdo->query("
    SELECT
        id,
        first_name,
        last_name,
        email
    FROM users
    WHERE role = 'coordinator' AND active = 1
    ORDER BY last_name ASC, first_name ASC
");
$coordinators = $stmt->fetchAll();

$stmt = $pdo->query("
    SELECT
        id,
        study_code,
        study_name,
        protocol_number,
        sponsor,
        status
    FROM studies
    WHERE status != 'archived'
    ORDER BY study_code ASC, study_name ASC
");
$studies = $stmt->fetchAll();

$stmt = $pdo->query("
    SELECT
        study_assignments.study_id,
        study_assignments.user_id,
        study_assignments.assignment_role
    FROM study_assignments
");
$assignmentRows = $stmt->fetchAll();

$assignmentMap = [];

foreach ($assignmentRows as $assignment) {
    $assignmentMap[$assignment["study_id"]][$assignment["assignment_role"]] = $assignment["user_id"];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Study Assignments | CTMS</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/Assets/CSS/style.css">
</head>
<body>

<header class="site-header">
    <div class="top-bar">
        <div>Clinical Trial Management System</div>
        <div>Admin Portal</div>
    </div>

    <nav class="main-nav">
        <a href="<?php echo BASE_URL; ?>/Auth/portal.php" class="brand">CTMS <span>Portal</span></a>

        <div class="nav-links">
            <a href="<?php echo BASE_URL; ?>/Dashboards/admin_dashboard.php">Dashboard</a>
            <a href="<?php echo BASE_URL; ?>/Studies/studies.php">Studies</a>
            <a href="<?php echo BASE_URL; ?>/Studies/archived_studies.php">Archived</a>
            <a href="<?php echo BASE_URL; ?>/Studies/study_assignments.php">Assignments</a>
            <a href="<?php echo BASE_URL; ?>/Users/users.php">Users</a>
            <a href="<?php echo BASE_URL; ?>/Audit/audit_logs.php">Audit Log</a>
            <a href="<?php echo BASE_URL; ?>/Auth/logout.php">Logout</a>
        </div>
    </nav>
</header>

<main class="page-wrapper">
    <section class="page-title">
        <h1>Study Assignments</h1>
        <p>Assign lead and backup coordinators to active studies.</p>
    </section>

    <?php if ($error !== ""): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <?php if (count($coordinators) === 0): ?>
        <div class="alert alert-danger">
            No active coordinators found. Add or reactivate a coordinator before assigning studies.
        </div>
    <?php endif; ?>

    <section class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Study</th>
                    <th>Protocol</th>
                    <th>Sponsor</th>
                    <th>Lead Coordinator</th>
                    <th>Backup Coordinator</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($studies) === 0): ?>
                    <tr>
                        <td colspan="7">No active studies found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($studies as $study): ?>
                        <?php
                            $currentLeadId = $assignmentMap[$study["id"]]["lead"] ?? "";
                            $currentBackupId = $assignmentMap[$study["id"]]["backup"] ?? "";
                        ?>

                        <tr>
                            <td><?php echo htmlspecialchars($study["study_code"] ?? ""); ?></td>
                            <td><?php echo htmlspecialchars($study["study_name"]); ?></td>
                            <td><?php echo htmlspecialchars($study["protocol_number"] ?? ""); ?></td>
                            <td><?php echo htmlspecialchars($study["sponsor"] ?? ""); ?></td>

                            <form method="POST" action="<?php echo BASE_URL; ?>/Studies/study_assignments.php">
                                <input 
                                    type="hidden" 
                                    name="study_id" 
                                    value="<?php echo htmlspecialchars($study["id"]); ?>"
                                >

                                <td>
                                    <select name="lead_coordinator_id">
                                        <option value="">Unassigned</option>

                                        <?php foreach ($coordinators as $coordinator): ?>
                                            <?php
                                                $coordinatorName = trim(
                                                    ($coordinator["first_name"] ?? "") . " " . ($coordinator["last_name"] ?? "")
                                                );
                                            ?>

                                            <option 
                                                value="<?php echo htmlspecialchars($coordinator["id"]); ?>"
                                                <?php echo (int)$currentLeadId === (int)$coordinator["id"] ? "selected" : ""; ?>
                                            >
                                                <?php echo htmlspecialchars($coordinatorName); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>

                                <td>
                                    <select name="backup_coordinator_id">
                                        <option value="">Unassigned</option>

                                        <?php foreach ($coordinators as $coordinator): ?>
                                            <?php
                                                $coordinatorName = trim(
                                                    ($coordinator["first_name"] ?? "") . " " . ($coordinator["last_name"] ?? "")
                                                );
                                            ?>

                                            <option 
                                                value="<?php echo htmlspecialchars($coordinator["id"]); ?>"
                                                <?php echo (int)$currentBackupId === (int)$coordinator["id"] ? "selected" : ""; ?>
                                            >
                                                <?php echo htmlspecialchars($coordinatorName); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>

                                <td>
                                    <button type="submit" class="btn btn-primary btn-small">
                                        Save
                                    </button>
                                </td>
                            </form>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>

</body>
</html>