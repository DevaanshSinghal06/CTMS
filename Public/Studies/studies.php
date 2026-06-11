<?php
require_once __DIR__ . '/../../App/Config/bootstrap.php';

require_any_role(["admin", "coordinator"]);

$isAdmin = $_SESSION["role"] === "admin";
$dashboardLink = $isAdmin
    ? BASE_URL . "/Dashboards/admin_dashboard.php"
    : BASE_URL . "/Dashboards/coordinator_dashboard.php";

$portalLabel = $isAdmin ? "Admin Portal" : "Research Coordinator Portal";

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verify_csrf()) {
        http_response_code(400);
        exit("Invalid or expired request token.");
    }

    if (!$isAdmin) {
        $error = "You do not have permission to create studies.";
    } else {
        $studyCode = generate_study_code($pdo);
        $studyName = trim($_POST["study_name"] ?? "");
        $protocolNumber = trim($_POST["protocol_number"] ?? "");
        $sponsor = trim($_POST["sponsor"] ?? "");
        $croName = trim($_POST["cro_name"] ?? "");
        $principalInvestigator = trim($_POST["principal_investigator"] ?? "");
        $status = $_POST["status"] ?? "enrolling";
        $startDate = $_POST["start_date"] ?: null;
        $endDate = $_POST["end_date"] ?: null;
        $notes = trim($_POST["notes"] ?? "");
        $createdBy = $_SESSION["user_id"] ?? null;

        $allowedStatuses = ["enrolling", "closed_to_enrollment", "terminated"];

        if ($studyName === "") {
            $error = "Study name is required.";
        } elseif (!in_array($status, $allowedStatuses, true)) {
            $error = "Invalid study status.";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO studies 
                (
                    study_code,
                    study_name,
                    protocol_number,
                    sponsor,
                    cro_name,
                    principal_investigator,
                    status,
                    start_date,
                    end_date,
                    notes,
                    created_by
                )
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $studyCode,
                $studyName,
                $protocolNumber,
                $sponsor,
                $croName,
                $principalInvestigator,
                $status,
                $startDate,
                $endDate,
                $notes,
                $createdBy
            ]);

            $newStudyId = (int) $pdo->lastInsertId();

            log_action(
                "created",
                "study",
                $newStudyId,
                "Created study " . $studyCode . ": " . $studyName
            );

            header("Location: " . BASE_URL . "/Studies/studies.php?created=1");
            exit;
        }
    }
}

if (isset($_GET["created"])) {
    $success = "Study created successfully.";
}

if (isset($_GET["updated"])) {
    $success = "Study updated successfully.";
}

if (isset($_GET["archived"])) {
    $success = "Study archived successfully.";
}

if (isset($_GET["access_denied"])) {
    $error = "You do not have access to that study.";
}

if ($isAdmin) {
    $stmt = $pdo->query("
        SELECT 
            id,
            study_code,
            study_name,
            protocol_number,
            sponsor,
            cro_name,
            principal_investigator,
            status,
            start_date,
            end_date,
            created_at
        FROM studies
        WHERE status != 'archived'
        ORDER BY created_at DESC
    ");

    $studies = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            studies.id,
            studies.study_code,
            studies.study_name,
            studies.protocol_number,
            studies.sponsor,
            studies.cro_name,
            studies.principal_investigator,
            studies.status,
            studies.start_date,
            studies.end_date,
            studies.created_at
        FROM studies
        INNER JOIN study_assignments
            ON studies.id = study_assignments.study_id
        WHERE studies.status != 'archived'
            AND study_assignments.user_id = ?
        ORDER BY studies.created_at DESC
    ");

    $stmt->execute([$_SESSION["user_id"]]);
    $studies = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Studies | CTMS</title>
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
            <?php endif; ?>
            <a href="<?php echo BASE_URL; ?>/Auth/logout.php">Logout</a>
        </div>
    </nav>
</header>

<main class="page-wrapper">
    <section class="page-title">
        <h1>Studies</h1>
        <p>
            <?php if ($isAdmin): ?>
                Create, view, and update clinical research studies.
            <?php else: ?>
                View and update clinical research studies.
            <?php endif; ?>
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

    <section class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Study Name</th>
                    <th>Protocol</th>
                    <th>Sponsor</th>
                    <th>CRO</th>
                    <th>PI</th>
                    <th>Status</th>
                    <th>Dates</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($studies) === 0): ?>
                    <tr>
                        <td colspan="9">No active studies have been created yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($studies as $study): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($study["study_code"] ?? ""); ?></td>
                            <td><?php echo htmlspecialchars($study["study_name"]); ?></td>
                            <td><?php echo htmlspecialchars($study["protocol_number"] ?? ""); ?></td>
                            <td><?php echo htmlspecialchars($study["sponsor"] ?? ""); ?></td>
                            <td><?php echo htmlspecialchars($study["cro_name"] ?? ""); ?></td>
                            <td><?php echo htmlspecialchars($study["principal_investigator"] ?? ""); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst($study["status"])); ?></td>
                            <td>
                                <?php
                                    $start = $study["start_date"] ?: "N/A";
                                    $end = $study["end_date"] ?: "N/A";
                                    echo htmlspecialchars($start . " - " . $end);
                                ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a 
                                        class="btn btn-primary btn-small" 
                                        href="<?php echo BASE_URL; ?>/Studies/study_view.php?id=<?php echo htmlspecialchars($study["id"]); ?>"
                                    >
                                        View
                                    </a>
                                    <a 
                                        class="btn btn-secondary btn-small" 
                                        href="<?php echo BASE_URL; ?>/Studies/study_edit.php?id=<?php echo htmlspecialchars($study["id"]); ?>"
                                    >
                                        Edit
                                    </a>

                                    <?php if ($isAdmin && $study["status"] !== "archived"): ?>
                                        <form 
                                            method="POST" 
                                            action="<?php echo BASE_URL; ?>/Studies/study_archive.php" 
                                            onsubmit="return confirm('Archive this study? It will remain in the system but will no longer be active.');"
                                        >
                                            <?php echo csrf_field(); ?>
                                            <input 
                                                type="hidden" 
                                                name="id" 
                                                value="<?php echo htmlspecialchars($study["id"]); ?>"
                                            >

                                            <button type="submit" class="btn btn-danger btn-small">
                                                Archive
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <?php if ($isAdmin): ?>
        <section class="card" style="margin-top: 28px;">
            <h3>Add New Study</h3>

            <form method="POST" action="<?php echo BASE_URL; ?>/Studies/studies.php">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label for="study_name">Study Name</label>
                    <input 
                        type="text" 
                        id="study_name" 
                        name="study_name" 
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="protocol_number">Protocol Number</label>
                    <input 
                        type="text" 
                        id="protocol_number" 
                        name="protocol_number"
                    >
                </div>

                <div class="form-group">
                    <label for="sponsor">Sponsor</label>
                    <input 
                        type="text" 
                        id="sponsor" 
                        name="sponsor"
                    >
                </div>

                <div class="form-group">
                    <label for="cro_name">CRO Name</label>
                    <input 
                        type="text" 
                        id="cro_name" 
                        name="cro_name"
                    >
                </div>

                <div class="form-group">
                    <label for="principal_investigator">Principal Investigator</label>
                    <input 
                        type="text" 
                        id="principal_investigator" 
                        name="principal_investigator"
                    >
                </div>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="enrolling">Enrolling</option>
                        <option value="closed_to_enrollment">Closed to Enrollment</option>
                        <option value="terminated">Terminated</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="start_date">Start Date</label>
                    <input 
                        type="date" 
                        id="start_date" 
                        name="start_date"
                    >
                </div>

                <div class="form-group">
                    <label for="end_date">End Date</label>
                    <input 
                        type="date" 
                        id="end_date" 
                        name="end_date"
                    >
                </div>

                <div class="form-group">
                    <label for="notes">Notes</label>
                    <textarea 
                        id="notes" 
                        name="notes" 
                        rows="4"
                    ></textarea>
                </div>

                <button type="submit" class="btn btn-primary">
                    Create Study
                </button>
            </form>
        </section>
    <?php endif; ?>
</main>

</body>
</html>