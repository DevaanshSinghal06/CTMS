<?php
require_once __DIR__ . '/../../App/Config/bootstrap.php';

require_any_role(["admin", "coordinator"]);

$isAdmin = $_SESSION["role"] === "admin";
$dashboardLink = $isAdmin
    ? BASE_URL . "/Dashboards/admin_dashboard.php"
    : BASE_URL . "/Dashboards/coordinator_dashboard.php";

$portalLabel = $isAdmin ? "Admin Portal" : "Research Coordinator Portal";

$studyId = $_GET["id"] ?? null;

if (!$studyId || !is_numeric($studyId)) {
    header("Location: " . BASE_URL . "/Studies/studies.php");
    exit;
}

$studyId = (int) $studyId;

// Load study
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

// Coordinator access control
if (!$isAdmin) {
    $stmt = $pdo->prepare("
        SELECT id
        FROM study_assignments
        WHERE study_id = ?
            AND user_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $studyId,
        $_SESSION["user_id"]
    ]);

    $assignment = $stmt->fetch();

    if (!$assignment) {
        header("Location: " . BASE_URL . "/Studies/studies.php?access_denied=1");
        exit;
    }
}

// Load arms
$stmt = $pdo->prepare("
    SELECT
        id,
        arm_name,
        arm_order,
        notes
    FROM study_arms
    WHERE study_id = ?
    ORDER BY arm_order, id
");
$stmt->execute([$studyId]);
$arms = $stmt->fetchAll();

// Load visits
$stmt = $pdo->prepare("
    SELECT
        svt.id,
        svt.study_id,
        svt.arm_id,
        svt.visit_name,
        svt.visit_order,
        svt.target_day,
        svt.window_before_days,
        svt.window_after_days,
        svt.notes,
        COALESCE(SUM(svp.budgeted_amount), 0) AS visit_total
    FROM study_visit_templates svt
    LEFT JOIN study_visit_procedures svp
        ON svp.visit_template_id = svt.id
    WHERE svt.study_id = ?
    GROUP BY
        svt.id,
        svt.study_id,
        svt.arm_id,
        svt.visit_name,
        svt.visit_order,
        svt.target_day,
        svt.window_before_days,
        svt.window_after_days,
        svt.notes
    ORDER BY
        svt.arm_id,
        svt.visit_order,
        svt.id
");
$stmt->execute([$studyId]);
$visits = $stmt->fetchAll();

// Load procedures assigned to visits
$stmt = $pdo->prepare("
    SELECT
        svp.id AS visit_procedure_id,
        svp.visit_template_id,
        svp.procedure_id,
        svp.budgeted_amount,
        svp.required,
        svp.notes,
        sp.procedure_name,
        sp.procedure_code
    FROM study_visit_procedures svp
    INNER JOIN study_procedures sp
        ON sp.id = svp.procedure_id
    INNER JOIN study_visit_templates svt
        ON svt.id = svp.visit_template_id
    WHERE svt.study_id = ?
    ORDER BY
        svt.visit_order,
        sp.id
");
$stmt->execute([$studyId]);
$procedureRows = $stmt->fetchAll();

// Group visits by arm
$visitsByArm = [];

foreach ($visits as $visit) {
    $armKey = $visit["arm_id"] === null
        ? "unassigned"
        : (string) $visit["arm_id"];

    if (!isset($visitsByArm[$armKey])) {
        $visitsByArm[$armKey] = [];
    }

    $visitsByArm[$armKey][] = $visit;
}

// Group procedures by visit
$proceduresByVisit = [];

foreach ($procedureRows as $procedure) {
    $visitTemplateId = (int) $procedure["visit_template_id"];

    if (!isset($proceduresByVisit[$visitTemplateId])) {
        $proceduresByVisit[$visitTemplateId] = [];
    }

    $proceduresByVisit[$visitTemplateId][] = $procedure;
}

function format_visit_timing(array $visit): string
{
    if ($visit["target_day"] === null) {
        return "No target day set";
    }

    $targetDay = (int) $visit["target_day"];
    $before = $visit["window_before_days"];
    $after = $visit["window_after_days"];

    $text = "Target Day " . $targetDay;

    if ($before !== null || $after !== null) {
        $beforeValue = $before === null ? 0 : (int) $before;
        $afterValue = $after === null ? 0 : (int) $after;

        $text .= " (-" . $beforeValue . " / +" . $afterValue . " days)";
    }

    return $text;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Visit Template | CTMS</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/Assets/CSS/style.css">
</head>
<body>

<header class="site-header">
    <div class="top-bar">
        <div>Clinical Trial Management System</div>
        <div><?php echo htmlspecialchars($portalLabel); ?></div>
    </div>

    <nav class="main-nav">
        <a href="<?php echo BASE_URL; ?>/Auth/portal.php" class="brand">
            CTMS <span>Portal</span>
        </a>

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
        <h1>Study Visit Template</h1>

        <p>
            <?php echo htmlspecialchars($study["study_code"] ?? ""); ?>
            —
            <?php echo htmlspecialchars($study["study_name"] ?? ""); ?>
        </p>
    </section>

    <section class="card" style="margin-bottom: 24px;">
        <div style="display: flex; gap: 12px; flex-wrap: wrap;">
            <a
                href="<?php echo BASE_URL; ?>/Studies/study_view.php?id=<?php echo $studyId; ?>"
                class="btn btn-secondary"
            >
                Back to Study
            </a>

            <a
                href="<?php echo BASE_URL; ?>/Studies/study_budget_import.php?id=<?php echo $studyId; ?>"
                class="btn btn-primary"
            >
                Import Budget
            </a>
        </div>
    </section>

    <?php if (empty($arms) && empty($visits)): ?>

        <section class="card">
            <h2>No Visit Template Yet</h2>

            <p>
                This study does not have any study arms or visit templates configured yet.
            </p>
        </section>

    <?php else: ?>

        <?php foreach ($arms as $arm): ?>
            <?php
            $armId = (int) $arm["id"];
            $armVisits = $visitsByArm[(string) $armId] ?? [];
            ?>

            <section class="card" style="margin-bottom: 28px;">
                <h2>
                    <?php echo htmlspecialchars($arm["arm_name"]); ?>
                </h2>

                <?php if (!empty($arm["notes"])): ?>
                    <p>
                        <?php echo nl2br(htmlspecialchars($arm["notes"])); ?>
                    </p>
                <?php endif; ?>

                <?php if (empty($armVisits)): ?>

                    <p>No visits have been assigned to this arm yet.</p>

                <?php else: ?>

                    <?php foreach ($armVisits as $visit): ?>
                        <?php
                        $visitId = (int) $visit["id"];
                        $visitProcedures = $proceduresByVisit[$visitId] ?? [];
                        ?>

                        <div
                            style="
                                margin-top: 24px;
                                padding-top: 20px;
                                border-top: 1px solid var(--border-color, #ddd);
                            "
                        >
                            <div
                                style="
                                    display: flex;
                                    justify-content: space-between;
                                    gap: 20px;
                                    align-items: flex-start;
                                    flex-wrap: wrap;
                                "
                            >
                                <div>
                                    <h3 style="margin-bottom: 6px;">
                                        <?php echo htmlspecialchars($visit["visit_name"]); ?>
                                    </h3>

                                    <p style="margin-top: 0; color: var(--text-muted);">
                                        <?php echo htmlspecialchars(format_visit_timing($visit)); ?>
                                    </p>
                                </div>

                                <div>
                                    <strong>
                                        Visit Total:
                                        $<?php echo number_format((float) $visit["visit_total"], 2); ?>
                                    </strong>
                                </div>
                            </div>

                            <?php if (empty($visitProcedures)): ?>

                                <p>No procedures assigned to this visit.</p>

                            <?php else: ?>

                                <div class="table-card">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Procedure</th>
                                                <th>Code</th>
                                                <th>Required</th>
                                                <th>Budgeted Amount</th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            <?php foreach ($visitProcedures as $procedure): ?>
                                                <tr>
                                                    <td>
                                                        <?php echo htmlspecialchars($procedure["procedure_name"]); ?>
                                                    </td>

                                                    <td>
                                                        <?php echo htmlspecialchars($procedure["procedure_code"] ?? "—"); ?>
                                                    </td>

                                                    <td>
                                                        <?php echo (int) $procedure["required"] === 1 ? "Yes" : "No"; ?>
                                                    </td>

                                                    <td>
                                                        <?php if ($procedure["budgeted_amount"] !== null): ?>
                                                            $<?php echo number_format((float) $procedure["budgeted_amount"], 2); ?>
                                                        <?php else: ?>
                                                            —
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                            <?php endif; ?>

                            <?php if (!empty($visit["notes"])): ?>
                                <p>
                                    <strong>Visit Notes:</strong><br>
                                    <?php echo nl2br(htmlspecialchars($visit["notes"])); ?>
                                </p>
                            <?php endif; ?>
                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>
            </section>

        <?php endforeach; ?>


        <?php
        $unassignedVisits = $visitsByArm["unassigned"] ?? [];
        ?>

        <?php if (!empty($unassignedVisits)): ?>
            <section class="card" style="margin-bottom: 28px;">
                <h2>Unassigned Visits</h2>

                <?php foreach ($unassignedVisits as $visit): ?>
                    <?php
                    $visitId = (int) $visit["id"];
                    $visitProcedures = $proceduresByVisit[$visitId] ?? [];
                    ?>

                    <div
                        style="
                            margin-top: 24px;
                            padding-top: 20px;
                            border-top: 1px solid var(--border-color, #ddd);
                        "
                    >
                        <h3>
                            <?php echo htmlspecialchars($visit["visit_name"]); ?>
                        </h3>

                        <p>
                            <?php echo htmlspecialchars(format_visit_timing($visit)); ?>
                        </p>

                        <p>
                            <strong>
                                Visit Total:
                                $<?php echo number_format((float) $visit["visit_total"], 2); ?>
                            </strong>
                        </p>

                        <?php if (!empty($visitProcedures)): ?>
                            <div class="table-card">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Procedure</th>
                                            <th>Required</th>
                                            <th>Budgeted Amount</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <?php foreach ($visitProcedures as $procedure): ?>
                                            <tr>
                                                <td>
                                                    <?php echo htmlspecialchars($procedure["procedure_name"]); ?>
                                                </td>

                                                <td>
                                                    <?php echo (int) $procedure["required"] === 1 ? "Yes" : "No"; ?>
                                                </td>

                                                <td>
                                                    <?php if ($procedure["budgeted_amount"] !== null): ?>
                                                        $<?php echo number_format((float) $procedure["budgeted_amount"], 2); ?>
                                                    <?php else: ?>
                                                        —
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php endforeach; ?>
            </section>
        <?php endif; ?>

    <?php endif; ?>

</main>

</body>
</html>