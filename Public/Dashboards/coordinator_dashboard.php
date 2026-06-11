<?php
require_once __DIR__ . '/../../App/Config/bootstrap.php';

require_role("coordinator");

$firstName = $_SESSION["first_name"] ?? "Coordinator";

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT studies.id) AS total
    FROM studies
    INNER JOIN study_assignments
        ON studies.id = study_assignments.study_id
    WHERE studies.status != 'archived'
        AND study_assignments.user_id = ?
");

$stmt->execute([$_SESSION["user_id"]]);
$activeStudyCountRow = $stmt->fetch();
$activeStudyCount = $activeStudyCountRow["total"] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Coordinator Dashboard | CTMS</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/Assets/CSS/style.css">
</head>
<body>

<header class="site-header">
    <div class="top-bar">
        <div>Clinical Trial Management System</div>
        <div>Research Coordinator Portal</div>
    </div>

    <nav class="main-nav">
        <a href="<?php echo BASE_URL; ?>/Auth/portal.php" class="brand">CTMS <span>Portal</span></a>

        <div class="nav-links">
            <a href="<?php echo BASE_URL; ?>/Dashboards/coordinator_dashboard.php">Dashboard</a>
            <a href="<?php echo BASE_URL; ?>/Studies/studies.php">Studies</a>
            <a href="<?php echo BASE_URL; ?>/Studies/archived_studies.php">Archived</a>
            <a href="<?php echo BASE_URL; ?>/Auth/logout.php">Logout</a>
        </div>
    </nav>
</header>

<main class="page-wrapper">
    <section class="page-title">
        <h1>Welcome, <?php echo htmlspecialchars($firstName); ?></h1>
        <p>Track assigned studies, subject screening, visits, follow-ups, and daily research tasks.</p>
    </section>

    <section class="card-grid">
        <a href="<?php echo BASE_URL; ?>/Studies/studies.php" class="card card-link">
            <h3>My Studies</h3>
            <div class="stat-number"><?php echo htmlspecialchars($activeStudyCount); ?></div>
            <p>View and update active clinical research studies.</p>
        </a>

        <div class="card">
            <h3>Screening</h3>
            <div class="stat-number">0</div>
            <p>Subjects currently being screened for eligibility.</p>
        </div>

        <div class="card">
            <h3>Upcoming Visits</h3>
            <div class="stat-number">0</div>
            <p>Scheduled visits requiring preparation or follow-up.</p>
        </div>

        <div class="card">
            <h3>Open Tasks</h3>
            <div class="stat-number">0</div>
            <p>Coordinator tasks that still need to be completed.</p>
        </div>
    </section>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Priority</th>
                    <th>Workflow Area</th>
                    <th>Next Action</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>High</td>
                    <td>Screening</td>
                    <td>Add screening tracker once patient module is created.</td>
                    <td>Pending Build</td>
                </tr>
                <tr>
                    <td>Medium</td>
                    <td>Visits</td>
                    <td>Create visit scheduling workflow.</td>
                    <td>Pending Build</td>
                </tr>
                <tr>
                    <td>Medium</td>
                    <td>Tasks</td>
                    <td>Add coordinator task list and due dates.</td>
                    <td>Pending Build</td>
                </tr>
            </tbody>
        </table>
    </div>
</main>

</body>
</html>