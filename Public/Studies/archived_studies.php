<?php
require_once __DIR__ . '/../../App/Config/bootstrap.php';

require_any_role(["admin", "coordinator"]);

$isAdmin = $_SESSION["role"] === "admin";
$dashboardLink = $isAdmin
    ? BASE_URL . "/Dashboards/admin_dashboard.php"
    : BASE_URL . "/Dashboards/coordinator_dashboard.php";

$portalLabel = $isAdmin ? "Admin Portal" : "Research Coordinator Portal";

$success = "";

if (isset($_GET["created"])) {
    $success = "Archived study created successfully.";
}

if (isset($_GET["restored"])) {
    $success = "Study restored successfully.";
}

if (isset($_GET["updated"])) {
    $success = "Study updated successfully.";
}

$stmt = $pdo->query("
    SELECT 
        id,
        study_code,
        study_name,
        protocol_number,
        drug_name,
        sponsor,
        cro_name,
        principal_investigator,
        status,
        start_date,
        end_date,
        created_at
    FROM studies
    WHERE status = 'archived'
    ORDER BY created_at DESC
");

$studies = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Archived Studies | CTMS</title>
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
            <a href="<?php echo BASE_URL; ?>/Auth/logout.php">Logout</a>
        </div>
    </nav>
</header>

<main class="page-wrapper">
    <section class="page-title">
        <h1>Archived Studies</h1>
        <p>View studies that have been removed from the active studies table.</p>
    </section>

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
                    <th>Drug</th>
                    <th>Sponsor</th>
                    <th>CRO</th>
                    <th>PI</th>
                    <th>Dates</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($studies) === 0): ?>
                    <tr>
                        <td colspan="9">No archived studies found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($studies as $study): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($study["study_code"] ?? ""); ?></td>
                            <td><?php echo htmlspecialchars($study["study_name"]); ?></td>
                            <td><?php echo htmlspecialchars($study["protocol_number"] ?? ""); ?></td>
                            <td><?php echo htmlspecialchars($study["drug_name"] ?? ""); ?></td>
                            <td><?php echo htmlspecialchars($study["sponsor"] ?? ""); ?></td>
                            <td><?php echo htmlspecialchars($study["cro_name"] ?? ""); ?></td>
                            <td><?php echo htmlspecialchars($study["principal_investigator"] ?? ""); ?></td>
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
                                        class="btn btn-secondary btn-small" 
                                        href="<?php echo BASE_URL; ?>/Studies/study_edit.php?id=<?php echo htmlspecialchars($study["id"]); ?>"
                                    >
                                        Edit
                                    </a>

                                    <?php if ($isAdmin): ?>
                                        <form 
                                            method="POST" 
                                            action="<?php echo BASE_URL; ?>/Studies/study_restore.php"
                                            onsubmit="return confirm('Restore this study to the active studies table?');"
                                        >
                                            <?php echo csrf_field(); ?>
                                            <input 
                                                type="hidden" 
                                                name="id" 
                                                value="<?php echo htmlspecialchars($study["id"]); ?>"
                                            >

                                            <button type="submit" class="btn btn-primary btn-small">
                                                Restore
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
</main>

</body>
</html>