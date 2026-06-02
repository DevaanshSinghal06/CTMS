<?php
require_once __DIR__ . '/../../App/Config/bootstrap.php';

require_role("admin");

$stmt = $pdo->query("
    SELECT
        audit_logs.id,
        audit_logs.user_id,
        audit_logs.action,
        audit_logs.entity_type,
        audit_logs.entity_id,
        audit_logs.description,
        audit_logs.created_at,

        users.first_name,
        users.last_name,
        users.email,

        studies.study_code,
        studies.study_name
    FROM audit_logs
    LEFT JOIN users
        ON audit_logs.user_id = users.id
    LEFT JOIN studies
        ON audit_logs.entity_type = 'study'
        AND audit_logs.entity_id = studies.id
    ORDER BY audit_logs.created_at DESC
    LIMIT 100
");

$auditLogs = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Audit Log | CTMS</title>
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
            <a href="<?php echo BASE_URL; ?>/Audit/audit_logs.php">Audit Log</a>
            <a href="<?php echo BASE_URL; ?>/Auth/logout.php">Logout</a>
        </div>
    </nav>
</header>

<main class="page-wrapper">
    <section class="page-title">
        <h1>Audit Log</h1>
        <p>Review recent system activity, including study creation, updates, archiving, and restoration.</p>
    </section>

    <section class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Record</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($auditLogs) === 0): ?>
                    <tr>
                        <td colspan="5">No audit log entries found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($auditLogs as $log): ?>
                        <?php
                            $userName = trim(($log["first_name"] ?? "") . " " . ($log["last_name"] ?? ""));

                            if ($userName === "") {
                                $userName = $log["email"] ?? "Unknown User";
                            }

                            $recordLabel = ucfirst($log["entity_type"]);

                            if ($log["entity_type"] === "study") {
                                $studyCode = $log["study_code"] ?? "Study #" . $log["entity_id"];
                                $studyName = $log["study_name"] ?? "";

                                $recordLabel = trim($studyCode . " - " . $studyName);
                            }

                            $createdAt = $log["created_at"]
                                ? date("m/d/Y g:i A", strtotime($log["created_at"]))
                                : "";
                        ?>

                        <tr>
                            <td><?php echo htmlspecialchars($createdAt); ?></td>
                            <td><?php echo htmlspecialchars($userName); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst($log["action"])); ?></td>
                            <td><?php echo htmlspecialchars($recordLabel); ?></td>
                            <td><?php echo htmlspecialchars($log["description"] ?? ""); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>

</body>
</html>