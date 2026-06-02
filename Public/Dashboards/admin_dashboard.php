<?php
require_once __DIR__ . '/../../App/Config/bootstrap.php';

require_role("admin");

$firstName = $_SESSION["first_name"] ?? "Admin";

$stmt = $pdo->query("
    SELECT COUNT(*) AS total 
    FROM studies
    WHERE status != 'archived'
");
$activeStudyCountRow = $stmt->fetch();
$activeStudyCount = $activeStudyCountRow["total"] ?? 0;

$stmt = $pdo->query("
    SELECT COUNT(*) AS total 
    FROM studies
    WHERE status = 'archived'
");
$archivedStudyCountRow = $stmt->fetch();
$archivedStudyCount = $archivedStudyCountRow["total"] ?? 0;

$stmt = $pdo->query("
    SELECT COUNT(*) AS total 
    FROM audit_logs
");
$auditLogCountRow = $stmt->fetch();
$auditLogCount = $auditLogCountRow["total"] ?? 0;

$stmt = $pdo->query("
    SELECT COUNT(*) AS total 
    FROM users
    WHERE active = 1
");
$userCountRow = $stmt->fetch();
$userCount = $userCountRow["total"] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard | CTMS</title>
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
            <a href="<?php echo BASE_URL; ?>/Users/users.php">Users</a>
            <a href="<?php echo BASE_URL; ?>/Audit/audit_logs.php">Audit Log</a>
            <a href="<?php echo BASE_URL; ?>/Auth/logout.php">Logout</a>
        </div>
    </nav>
</header>

<main class="page-wrapper">
    <section class="page-title">
        <h1>Welcome, <?php echo htmlspecialchars($firstName); ?></h1>
        <p>Manage studies, users, subjects, reports, and research office workflows.</p>
    </section>

    <section class="card-grid">
        <a href="<?php echo BASE_URL; ?>/Studies/studies.php" class="card card-link">
            <h3>Studies</h3>
            <div class="stat-number"><?php echo htmlspecialchars($activeStudyCount); ?></div>
            <p>Create and manage active clinical research studies.</p>
        </a>

        <a href="<?php echo BASE_URL; ?>/Audit/audit_logs.php" class="card card-link">
            <h3>Audit Log</h3>
            <div class="stat-number"><?php echo htmlspecialchars($auditLogCount); ?></div>
            <p>View recent system activity and study change history.</p>
        </a>

        <div class="card">
            <h3>Subjects</h3>
            <div class="stat-number">0</div>
            <p>View all screened and enrolled subjects.</p>
        </div>

        <div class="card">
            <h3>Reports</h3>
            <div class="stat-number">0</div>
            <p>Generate operational reports.</p>
        </div>

        <a href="<?php echo BASE_URL; ?>/Users/users.php" class="card card-link">
            <h3>Users</h3>
            <div class="stat-number"><?php echo htmlspecialchars($userCount); ?></div>
            <p>View and add admins and research coordinators.</p>
        </a>
    </section>

    <div class="card">
        <h3>Admin Actions</h3>
        <p>Current modules: study setup, study editing, archiving, and restoration.</p>
    </div>
</main>

</body>
</html>