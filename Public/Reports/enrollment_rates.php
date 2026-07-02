<?php
require_once __DIR__ . '/../../App/Config/bootstrap.php';

require_any_role(["admin", "coordinator"]);

$isAdmin = $_SESSION["role"] === "admin";
$currentUserId = (int) ($_SESSION["user_id"] ?? 0);

$dashboardLink = $isAdmin
    ? BASE_URL . "/Dashboards/admin_dashboard.php"
    : BASE_URL . "/Dashboards/coordinator_dashboard.php";

$portalLabel = $isAdmin ? "Admin Portal" : "Research Coordinator Portal";

if ($isAdmin) {
    $stmt = $pdo->query("
        SELECT
            studies.id,
            studies.study_code,
            studies.study_name,
            studies.protocol_number,
            studies.drug_name,
            studies.sponsor,
            studies.status,
            studies.site_enrollment_target,
            studies.budgeted_enrollment_number,

            SUM(CASE WHEN study_subjects.screening_status = 'screening' THEN 1 ELSE 0 END) AS screening_count,
            SUM(CASE WHEN study_subjects.screening_status = 'randomization' THEN 1 ELSE 0 END) AS randomization_count,
            SUM(CASE WHEN study_subjects.screening_status = 'enrolled' THEN 1 ELSE 0 END) AS enrolled_count,
            SUM(CASE WHEN study_subjects.screening_status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
            SUM(CASE WHEN study_subjects.screening_status = 'screen_failed' THEN 1 ELSE 0 END) AS screen_failed_count,
            SUM(CASE WHEN study_subjects.screening_status = 'withdrawn' THEN 1 ELSE 0 END) AS withdrawn_count
        FROM studies
        LEFT JOIN study_subjects
            ON studies.id = study_subjects.study_id
        WHERE studies.status != 'archived'
        GROUP BY
            studies.id,
            studies.study_code,
            studies.study_name,
            studies.protocol_number,
            studies.drug_name,
            studies.sponsor,
            studies.status,
            studies.site_enrollment_target,
            studies.budgeted_enrollment_number
        ORDER BY studies.study_code ASC
    ");
} else {
    $stmt = $pdo->prepare("
        SELECT
            studies.id,
            studies.study_code,
            studies.study_name,
            studies.protocol_number,
            studies.drug_name,
            studies.sponsor,
            studies.status,
            studies.site_enrollment_target,
            studies.budgeted_enrollment_number,

            SUM(CASE WHEN study_subjects.screening_status = 'screening' THEN 1 ELSE 0 END) AS screening_count,
            SUM(CASE WHEN study_subjects.screening_status = 'randomization' THEN 1 ELSE 0 END) AS randomization_count,
            SUM(CASE WHEN study_subjects.screening_status = 'enrolled' THEN 1 ELSE 0 END) AS enrolled_count,
            SUM(CASE WHEN study_subjects.screening_status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
            SUM(CASE WHEN study_subjects.screening_status = 'screen_failed' THEN 1 ELSE 0 END) AS screen_failed_count,
            SUM(CASE WHEN study_subjects.screening_status = 'withdrawn' THEN 1 ELSE 0 END) AS withdrawn_count
        FROM studies
        INNER JOIN study_assignments
            ON studies.id = study_assignments.study_id
        LEFT JOIN study_subjects
            ON studies.id = study_subjects.study_id
        WHERE studies.status != 'archived'
            AND study_assignments.user_id = ?
        GROUP BY
            studies.id,
            studies.study_code,
            studies.study_name,
            studies.protocol_number,
            studies.drug_name,
            studies.sponsor,
            studies.status,
            studies.site_enrollment_target,
            studies.budgeted_enrollment_number
        ORDER BY studies.study_code ASC
    ");

    $stmt->execute([$currentUserId]);
}

$reports = $stmt->fetchAll();

function format_rate(?int $numerator, $denominator): string
{
    $numerator = (int) ($numerator ?? 0);
    $denominator = (int) ($denominator ?? 0);

    if ($denominator <= 0) {
        return "N/A";
    }

    $percentage = ($numerator / $denominator) * 100;

    return number_format($percentage, 1) . "%";
}

function format_count($value): int
{
    return (int) ($value ?? 0);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Enrollment Rates Report | CTMS</title>
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
            <a href="<?php echo BASE_URL; ?>/Subjects/subjects.php">Subjects</a>
            <a href="<?php echo BASE_URL; ?>/Reports/enrollment_rates.php">Reports</a>
            <a href="<?php echo BASE_URL; ?>/Auth/logout.php">Logout</a>
        </div>
    </nav>
</header>

<main class="page-wrapper">
    <section class="page-title">
        <h1>Enrollment Rates Report</h1>
        <p>
            Compare enrolled subjects against internal site targets and budgeted enrollment numbers by study.
        </p>
    </section>

    <section class="table-card table-scroll">
        <table>
            <thead>
                <tr>
                    <th>Study</th>
                    <th>Protocol</th>
                    <th>Drug</th>
                    <th>Sponsor</th>
                    <th>Status</th>
                    <th>Screening</th>
                    <th>Randomization</th>
                    <th>Enrolled</th>
                    <th>Completed</th>
                    <th>Screen Failed</th>
                    <th>Withdrawn</th>
                    <th>Site Target</th>
                    <th>Budgeted</th>
                    <th>Enrolled / Site Target</th>
                    <th>Enrolled / Budgeted</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($reports) === 0): ?>
                    <tr>
                        <td colspan="15">No studies found for this report.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($reports as $report): ?>
                        <?php
                            $enrolledCount = format_count($report["enrolled_count"] ?? 0);
                            $siteTarget = (int) ($report["site_enrollment_target"] ?? 0);
                            $budgetedEnrollment = (int) ($report["budgeted_enrollment_number"] ?? 0);

                            $siteRate = format_rate($enrolledCount, $siteTarget);
                            $budgetedRate = format_rate($enrolledCount, $budgetedEnrollment);

                            $statusLabel = ucwords(str_replace("_", " ", $report["status"] ?? ""));
                        ?>

                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($report["study_code"] ?? ""); ?></strong><br>
                                <?php echo htmlspecialchars($report["study_name"] ?? ""); ?>
                            </td>
                            <td><?php echo htmlspecialchars($report["protocol_number"] ?? ""); ?></td>
                            <td><?php echo htmlspecialchars($report["drug_name"] ?? ""); ?></td>
                            <td><?php echo htmlspecialchars($report["sponsor"] ?? ""); ?></td>
                            <td><?php echo htmlspecialchars($statusLabel); ?></td>
                            <td><?php echo htmlspecialchars(format_count($report["screening_count"] ?? 0)); ?></td>
                            <td><?php echo htmlspecialchars(format_count($report["randomization_count"] ?? 0)); ?></td>
                            <td><?php echo htmlspecialchars($enrolledCount); ?></td>
                            <td><?php echo htmlspecialchars(format_count($report["completed_count"] ?? 0)); ?></td>
                            <td><?php echo htmlspecialchars(format_count($report["screen_failed_count"] ?? 0)); ?></td>
                            <td><?php echo htmlspecialchars(format_count($report["withdrawn_count"] ?? 0)); ?></td>
                            <td><?php echo $siteTarget > 0 ? htmlspecialchars($siteTarget) : "N/A"; ?></td>
                            <td><?php echo $budgetedEnrollment > 0 ? htmlspecialchars($budgetedEnrollment) : "N/A"; ?></td>
                            <td><?php echo htmlspecialchars($siteRate); ?></td>
                            <td><?php echo htmlspecialchars($budgetedRate); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>

</body>
</html>