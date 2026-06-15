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

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verify_csrf()) {
        http_response_code(400);
        exit("Invalid or expired request token.");
    }

    $initials = strtoupper(trim($_POST["initials"] ?? ""));
    $dateOfBirth = $_POST["date_of_birth"] ?: null;
    $phoneNumber = trim($_POST["phone_number"] ?? "");
    $notes = trim($_POST["notes"] ?? "");

    if ($initials === "") {
        $error = "Subject initials are required.";
    } elseif (!$dateOfBirth) {
        $error = "Date of birth is required.";
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO subjects
            (
                initials,
                date_of_birth,
                phone_number,
                notes,
                created_by
            )
            VALUES
            (?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $initials,
            $dateOfBirth,
            $phoneNumber,
            $notes,
            $currentUserId
        ]);

        $newSubjectId = (int) $pdo->lastInsertId();

        log_action(
            "created",
            "subject",
            $newSubjectId,
            "Created subject profile: " . $initials
        );

        header("Location: " . BASE_URL . "/Subjects/subjects.php?created=1");
        exit;
    }
}

if (isset($_GET["created"])) {
    $success = "Subject profile created successfully.";
}

$stmt = $pdo->query("
    SELECT
        subjects.id,
        subjects.initials,
        subjects.date_of_birth,
        subjects.phone_number,
        subjects.notes,
        subjects.created_at,
        COUNT(study_subjects.id) AS study_count
    FROM subjects
    LEFT JOIN study_subjects
        ON subjects.id = study_subjects.subject_id
    GROUP BY
        subjects.id,
        subjects.initials,
        subjects.date_of_birth,
        subjects.phone_number,
        subjects.notes,
        subjects.created_at
    ORDER BY subjects.created_at DESC
");

$subjects = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Subjects | CTMS</title>
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
            <a href="<?php echo BASE_URL; ?>/Subjects/subjects.php">Subjects</a>

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
        <h1>Subject Registry</h1>
        <p>Create and view reusable subject profiles. Subjects can later be linked to multiple studies.</p>
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
                    <th>Initials</th>
                    <th>DOB</th>
                    <th>Phone</th>
                    <th>Studies</th>
                    <th>Notes</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($subjects) === 0): ?>
                    <tr>
                        <td colspan="6">No subject profiles have been created yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($subjects as $subject): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($subject["initials"]); ?></td>
                            <td><?php echo htmlspecialchars($subject["date_of_birth"]); ?></td>
                            <td><?php echo htmlspecialchars($subject["phone_number"] ?? ""); ?></td>
                            <td><?php echo htmlspecialchars($subject["study_count"]); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($subject["notes"] ?? "")); ?></td>
                            <td>
                                <?php
                                    echo $subject["created_at"]
                                        ? htmlspecialchars(date("m/d/Y", strtotime($subject["created_at"])))
                                        : "";
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="card" style="margin-top: 28px;">
        <h3>Add New Subject Profile</h3>

        <form method="POST" action="<?php echo BASE_URL; ?>/Subjects/subjects.php">
            <?php echo csrf_field(); ?>

            <div class="form-group">
                <label for="initials">Initials</label>
                <input 
                    type="text" 
                    id="initials" 
                    name="initials" 
                    maxlength="20"
                    required
                >
            </div>

            <div class="form-group">
                <label for="date_of_birth">Date of Birth</label>
                <input 
                    type="date" 
                    id="date_of_birth" 
                    name="date_of_birth"
                    required
                >
            </div>

            <div class="form-group">
                <label for="phone_number">Phone Number</label>
                <input 
                    type="text" 
                    id="phone_number" 
                    name="phone_number"
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
                Create Subject
            </button>
        </form>
    </section>
</main>

</body>
</html>