<?php
require_once __DIR__ . '/../../App/Config/bootstrap.php';

require_role("admin");

$dateFrom = trim($_GET["date_from"] ?? "");
$dateTo = trim($_GET["date_to"] ?? "");
$userFilter = trim($_GET["user_id"] ?? "");
$studyFilter = trim($_GET["study_id"] ?? "");
$entityTypeFilter = trim($_GET["entity_type"] ?? "");
$actionFilter = trim($_GET["action"] ?? "");

$whereClauses = [];
$params = [];

if ($dateFrom !== "") {
    $whereClauses[] = "audit_logs.created_at >= ?";
    $params[] = $dateFrom . " 00:00:00";
}

if ($dateTo !== "") {
    $whereClauses[] = "audit_logs.created_at <= ?";
    $params[] = $dateTo . " 23:59:59";
}

if ($userFilter !== "" && is_numeric($userFilter)) {
    $whereClauses[] = "audit_logs.user_id = ?";
    $params[] = (int) $userFilter;
}

if ($studyFilter !== "" && is_numeric($studyFilter)) {
    $whereClauses[] = "audit_logs.entity_type = 'study' AND audit_logs.entity_id = ?";
    $params[] = (int) $studyFilter;
}

if ($entityTypeFilter !== "") {
    $whereClauses[] = "audit_logs.entity_type = ?";
    $params[] = $entityTypeFilter;
}

if ($actionFilter !== "") {
    $whereClauses[] = "audit_logs.action = ?";
    $params[] = $actionFilter;
}

$whereSql = "";

if (count($whereClauses) > 0) {
    $whereSql = "WHERE " . implode(" AND ", $whereClauses);
}

$stmt = $pdo->prepare("
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
    $whereSql
    ORDER BY audit_logs.created_at DESC
    LIMIT 250
");

$stmt->execute($params);
$auditLogs = $stmt->fetchAll();

$stmt = $pdo->query("
    SELECT id, first_name, last_name, email
    FROM users
    ORDER BY last_name ASC, first_name ASC
");
$users = $stmt->fetchAll();

$stmt = $pdo->query("
    SELECT id, study_code, study_name
    FROM studies
    ORDER BY study_code ASC
");
$studies = $stmt->fetchAll();

$stmt = $pdo->query("
    SELECT DISTINCT entity_type
    FROM audit_logs
    WHERE entity_type IS NOT NULL
        AND entity_type != ''
    ORDER BY entity_type ASC
");
$entityTypes = $stmt->fetchAll();

$stmt = $pdo->query("
    SELECT DISTINCT action
    FROM audit_logs
    WHERE action IS NOT NULL
        AND action != ''
    ORDER BY action ASC
");
$actions = $stmt->fetchAll();
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
        <p>Review and filter system activity by date, user, study, entity type, and action.</p>
    </section>

    <section class="card" style="margin-bottom: 24px;">
        <h3>Filter Audit Log</h3>

        <form method="GET" action="<?php echo BASE_URL; ?>/Audit/audit_logs.php">
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px;">
                <div class="form-group">
                    <label for="date_from">Date From</label>
                    <input 
                        type="date" 
                        id="date_from" 
                        name="date_from"
                        value="<?php echo htmlspecialchars($dateFrom); ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="date_to">Date To</label>
                    <input 
                        type="date" 
                        id="date_to" 
                        name="date_to"
                        value="<?php echo htmlspecialchars($dateTo); ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="user_id">User</label>
                    <select id="user_id" name="user_id">
                        <option value="">All Users</option>

                        <?php foreach ($users as $user): ?>
                            <?php
                                $userName = trim(($user["first_name"] ?? "") . " " . ($user["last_name"] ?? ""));

                                if ($userName === "") {
                                    $userName = $user["email"] ?? "Unknown User";
                                }
                            ?>

                            <option 
                                value="<?php echo htmlspecialchars($user["id"]); ?>"
                                <?php echo (string) $userFilter === (string) $user["id"] ? "selected" : ""; ?>
                            >
                                <?php echo htmlspecialchars($userName); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="study_id">Study</label>
                    <select id="study_id" name="study_id">
                        <option value="">All Studies</option>

                        <?php foreach ($studies as $study): ?>
                            <option 
                                value="<?php echo htmlspecialchars($study["id"]); ?>"
                                <?php echo (string) $studyFilter === (string) $study["id"] ? "selected" : ""; ?>
                            >
                                <?php
                                    echo htmlspecialchars(
                                        ($study["study_code"] ?? "Study #" . $study["id"]) .
                                        " - " .
                                        ($study["study_name"] ?? "")
                                    );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="entity_type">Entity Type</label>
                    <select id="entity_type" name="entity_type">
                        <option value="">All Entity Types</option>

                        <?php foreach ($entityTypes as $entityType): ?>
                            <option 
                                value="<?php echo htmlspecialchars($entityType["entity_type"]); ?>"
                                <?php echo $entityTypeFilter === $entityType["entity_type"] ? "selected" : ""; ?>
                            >
                                <?php echo htmlspecialchars(ucfirst($entityType["entity_type"])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="action">Action</label>
                    <select id="action" name="action">
                        <option value="">All Actions</option>

                        <?php foreach ($actions as $action): ?>
                            <option 
                                value="<?php echo htmlspecialchars($action["action"]); ?>"
                                <?php echo $actionFilter === $action["action"] ? "selected" : ""; ?>
                            >
                                <?php echo htmlspecialchars(ucfirst($action["action"])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                Apply Filters
            </button>

            <a href="<?php echo BASE_URL; ?>/Audit/audit_logs.php" class="btn btn-secondary">
                Clear Filters
            </a>
        </form>
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