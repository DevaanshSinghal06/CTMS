<?php
require_once __DIR__ . '/../../App/Config/bootstrap.php';

require_any_role(["admin", "coordinator"]);

$isAdmin = $_SESSION["role"] === "admin";
$currentUserId = (int) ($_SESSION["user_id"] ?? 0);

$dashboardLink = $isAdmin
    ? BASE_URL . "/Dashboards/admin_dashboard.php"
    : BASE_URL . "/Dashboards/coordinator_dashboard.php";

$portalLabel = $isAdmin ? "Admin Portal" : "Research Coordinator Portal";

$error = "";
$success = "";

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

$defaultTasks = [
    ["Feasibility & Site Selection", "CDA/NDA executed"],
    ["Feasibility & Site Selection", "Feasibility questionnaire completed"],
    ["Feasibility & Site Selection", "Site capabilities reviewed"],
    ["Feasibility & Site Selection", "Recruitment potential assessed"],
    ["Feasibility & Site Selection", "Investigator CV submitted"],
    ["Feasibility & Site Selection", "Site selected by sponsor"],

    ["Budget & Contract", "Budget reviewed"],
    ["Budget & Contract", "Budget negotiated"],
    ["Budget & Contract", "Startup fees negotiated"],
    ["Budget & Contract", "CTA reviewed"],
    ["Budget & Contract", "CTA executed"],
    ["Budget & Contract", "W-9 submitted"],

    ["Regulatory Documents", "Protocol received"],
    ["Regulatory Documents", "Investigator Brochure received"],
    ["Regulatory Documents", "ICF template received"],
    ["Regulatory Documents", "Form FDA 1572 completed"],
    ["Regulatory Documents", "Delegation Log created"],
    ["Regulatory Documents", "Regulatory Binder established"],

    ["IRB / Ethics Committee", "IRB submission prepared"],
    ["IRB / Ethics Committee", "Protocol submitted"],
    ["IRB / Ethics Committee", "ICF submitted"],
    ["IRB / Ethics Committee", "IRB approval received"],
    ["IRB / Ethics Committee", "Approved ICF received"],

    ["Study Team Training", "Protocol training completed"],
    ["Study Team Training", "EDC training completed"],
    ["Study Team Training", "IRT training completed"],
    ["Study Team Training", "Safety training completed"],
    ["Study Team Training", "Training documentation filed"],

    ["Vendor Setup", "Central lab account established"],
    ["Vendor Setup", "Lab kits received"],
    ["Vendor Setup", "Imaging vendor setup completed"],
    ["Vendor Setup", "Courier account established"],

    ["Pharmacy / Investigational Product", "Pharmacy manual reviewed"],
    ["Pharmacy / Investigational Product", "Temperature monitoring installed"],
    ["Pharmacy / Investigational Product", "Drug storage area designated"],
    ["Pharmacy / Investigational Product", "Initial drug shipment received"],

    ["Laboratory Readiness", "Lab manual reviewed"],
    ["Laboratory Readiness", "Processing requirements understood"],
    ["Laboratory Readiness", "Shipment supplies received"],
    ["Laboratory Readiness", "Reference ranges filed"],

    ["Recruitment Preparation", "Recruitment plan created"],
    ["Recruitment Preparation", "Prescreening worksheet developed"],
    ["Recruitment Preparation", "Referral physicians notified"],
    ["Recruitment Preparation", "Candidate list generated"],

    ["Site Initiation Visit", "SIV scheduled"],
    ["Site Initiation Visit", "SIV completed"],
    ["Site Initiation Visit", "Action items resolved"],
    ["Site Initiation Visit", "Site activation received"],

    ["First Patient Ready", "Site activated"],
    ["First Patient Ready", "Lab kits available"],
    ["First Patient Ready", "Drug available"],
    ["First Patient Ready", "Screening visit scheduled"]
];

$stmt = $pdo->prepare("
    INSERT IGNORE INTO study_startup_tasks
    (
        study_id,
        section_name,
        task_name
    )
    VALUES
    (?, ?, ?)
");

foreach ($defaultTasks as $task) {
    $stmt->execute([
        $studyId,
        $task[0],
        $task[1]
    ]);
}

$allowedStatuses = [
    "not_started",
    "in_progress",
    "complete",
    "blocked",
    "not_applicable"
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verify_csrf()) {
        http_response_code(400);
        exit("Invalid or expired request token.");
    }

    $taskId = $_POST["task_id"] ?? null;
    $status = $_POST["status"] ?? "";
    $notes = trim($_POST["notes"] ?? "");

    if (!$taskId || !is_numeric($taskId)) {
        $error = "Invalid checklist task.";
    } elseif (!in_array($status, $allowedStatuses, true)) {
        $error = "Invalid checklist status.";
    } else {
        $taskId = (int) $taskId;

        $stmt = $pdo->prepare("
            SELECT id
            FROM study_startup_tasks
            WHERE id = ?
                AND study_id = ?
            LIMIT 1
        ");
        $stmt->execute([$taskId, $studyId]);
        $task = $stmt->fetch();

        if (!$task) {
            $error = "Checklist task not found.";
        } else {
            if ($status === "complete") {
                $stmt = $pdo->prepare("
                    UPDATE study_startup_tasks
                    SET
                        status = ?,
                        notes = ?,
                        completed_by = ?,
                        completed_at = COALESCE(completed_at, NOW())
                    WHERE id = ?
                        AND study_id = ?
                ");

                $stmt->execute([
                    $status,
                    $notes,
                    $currentUserId,
                    $taskId,
                    $studyId
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE study_startup_tasks
                    SET
                        status = ?,
                        notes = ?,
                        completed_by = NULL,
                        completed_at = NULL
                    WHERE id = ?
                        AND study_id = ?
                ");

                $stmt->execute([
                    $status,
                    $notes,
                    $taskId,
                    $studyId
                ]);
            }

            log_action(
                "updated",
                "study",
                $studyId,
                "Updated startup checklist task for " . ($study["study_code"] ?? "Study")
            );

            header("Location: " . BASE_URL . "/Studies/study_startup_checklist.php?id=" . $studyId . "&updated=1");
            exit;
        }
    }
}

if (isset($_GET["updated"])) {
    $success = "Checklist task updated successfully.";
}

$statusLabels = [
    "not_started" => "Not Started",
    "in_progress" => "In Progress",
    "complete" => "Complete",
    "blocked" => "Blocked",
    "not_applicable" => "Not Applicable"
];

$stmt = $pdo->prepare("
    SELECT
        study_startup_tasks.*,
        users.first_name,
        users.last_name,
        users.email
    FROM study_startup_tasks
    LEFT JOIN users
        ON study_startup_tasks.completed_by = users.id
    WHERE study_startup_tasks.study_id = ?
    ORDER BY
        FIELD(
            study_startup_tasks.section_name,
            'Feasibility & Site Selection',
            'Budget & Contract',
            'Regulatory Documents',
            'IRB / Ethics Committee',
            'Study Team Training',
            'Vendor Setup',
            'Pharmacy / Investigational Product',
            'Laboratory Readiness',
            'Recruitment Preparation',
            'Site Initiation Visit',
            'First Patient Ready'
        ),
        study_startup_tasks.id ASC
");
$stmt->execute([$studyId]);
$tasks = $stmt->fetchAll();

$tasksBySection = [];

$totalTasks = count($tasks);
$completeTasks = 0;
$inProgressTasks = 0;
$blockedTasks = 0;

foreach ($tasks as $task) {
    $tasksBySection[$task["section_name"]][] = $task;

    if ($task["status"] === "complete") {
        $completeTasks++;
    }

    if ($task["status"] === "in_progress") {
        $inProgressTasks++;
    }

    if ($task["status"] === "blocked") {
        $blockedTasks++;
    }
}

$completionPercent = $totalTasks > 0
    ? round(($completeTasks / $totalTasks) * 100)
    : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Startup Checklist | CTMS</title>
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
        <h1>Startup Checklist</h1>
        <p>
            <?php echo htmlspecialchars($study["study_code"] ?? ""); ?>
            |
            <?php echo htmlspecialchars($study["study_name"] ?? ""); ?>
        </p>
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

    <section class="card-grid">
        <div class="card">
            <h3>Total Tasks</h3>
            <div class="stat-number"><?php echo htmlspecialchars($totalTasks); ?></div>
            <p>Startup checklist items for this study.</p>
        </div>

        <div class="card">
            <h3>Completed</h3>
            <div class="stat-number"><?php echo htmlspecialchars($completeTasks); ?></div>
            <p><?php echo htmlspecialchars($completionPercent); ?>% complete.</p>
        </div>

        <div class="card">
            <h3>In Progress</h3>
            <div class="stat-number"><?php echo htmlspecialchars($inProgressTasks); ?></div>
            <p>Tasks currently being worked on.</p>
        </div>

        <div class="card">
            <h3>Blocked</h3>
            <div class="stat-number"><?php echo htmlspecialchars($blockedTasks); ?></div>
            <p>Tasks needing attention.</p>
        </div>
    </section>

    <?php foreach ($tasksBySection as $sectionName => $sectionTasks): ?>
        <section class="card" style="margin-bottom: 24px;">
            <h3><?php echo htmlspecialchars($sectionName); ?></h3>

            <table>
                <thead>
                    <tr>
                        <th>Task</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <th>Completed</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sectionTasks as $task): ?>
                        <?php
                            $formId = "task-form-" . (int) $task["id"];

                            $completedBy = trim(($task["first_name"] ?? "") . " " . ($task["last_name"] ?? ""));

                            if ($completedBy === "") {
                                $completedBy = $task["email"] ?? "";
                            }

                            $completedText = "N/A";

                            if ($task["status"] === "complete" && !empty($task["completed_at"])) {
                                $completedText = date("m/d/Y g:i A", strtotime($task["completed_at"]));

                                if ($completedBy !== "") {
                                    $completedText .= " by " . $completedBy;
                                }
                            }
                        ?>

                        <tr>
                            <td><?php echo htmlspecialchars($task["task_name"]); ?></td>

                            <td>
                                <select name="status" form="<?php echo htmlspecialchars($formId); ?>">
                                    <?php foreach ($statusLabels as $statusValue => $statusLabel): ?>
                                        <option 
                                            value="<?php echo htmlspecialchars($statusValue); ?>"
                                            <?php echo $task["status"] === $statusValue ? "selected" : ""; ?>
                                        >
                                            <?php echo htmlspecialchars($statusLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>

                            <td>
                                <textarea 
                                    name="notes" 
                                    rows="2"
                                    form="<?php echo htmlspecialchars($formId); ?>"
                                ><?php echo htmlspecialchars($task["notes"] ?? ""); ?></textarea>
                            </td>

                            <td><?php echo htmlspecialchars($completedText); ?></td>

                            <td>
                                <form 
                                    id="<?php echo htmlspecialchars($formId); ?>" 
                                    method="POST" 
                                    action="<?php echo BASE_URL; ?>/Studies/study_startup_checklist.php?id=<?php echo htmlspecialchars($studyId); ?>"
                                >
                                    <?php echo csrf_field(); ?>

                                    <input 
                                        type="hidden" 
                                        name="task_id" 
                                        value="<?php echo htmlspecialchars($task["id"]); ?>"
                                    >

                                    <button type="submit" class="btn btn-primary btn-small">
                                        Save
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endforeach; ?>

    <a 
        href="<?php echo BASE_URL; ?>/Studies/study_view.php?id=<?php echo htmlspecialchars($studyId); ?>" 
        class="btn btn-secondary"
    >
        Back to Study
    </a>
</main>

</body>
</html>