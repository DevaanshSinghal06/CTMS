<?php
require_once __DIR__ . '/../App/Config/bootstrap.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>CTMS</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/Assets/CSS/style.css">
</head>
<body>

<header class="site-header">
    <div class="top-bar">
        <div>Clinical Trial Management System</div>
        <div>Internal Research Office Portal</div>
    </div>

    <nav class="main-nav">
        <a href="<?php echo BASE_URL; ?>/Auth/portal.php" class="brand">CTMS <span>Portal</span></a>

        <div class="nav-links">
            <a href="<?php echo BASE_URL; ?>/Auth/login.php">Login</a>
        </div>
    </nav>
</header>

<main class="page-wrapper">
    <section class="page-title">
        <h1>Clinical Research Dashboard</h1>
        <p>Manage studies, subjects, visits, and coordinator workflows from one secure internal system.</p>
    </section>

    <section class="card-grid">
        <div class="card">
            <h3>Active Studies</h3>
            <div class="stat-number">0</div>
            <p>Studies currently open or in setup.</p>
        </div>

        <div class="card">
            <h3>Screening</h3>
            <div class="stat-number">0</div>
            <p>Potential subjects being reviewed.</p>
        </div>

        <div class="card">
            <h3>Enrolled</h3>
            <div class="stat-number">0</div>
            <p>Subjects currently enrolled.</p>
        </div>

        <div class="card">
            <h3>Upcoming Visits</h3>
            <div class="stat-number">0</div>
            <p>Scheduled visits needing attention.</p>
        </div>
    </section>

    <div class="card">
        <h3>System Setup</h3>
        <p>The CTMS foundation is running. Current modules: login, dashboards, studies, archiving, and restoration.</p>
    </div>
</main>

</body>
</html>