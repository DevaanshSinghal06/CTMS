<?php
require_once __DIR__ . '/../../App/Config/bootstrap.php';

require_any_role(["admin", "coordinator"]);

$isAdmin = $_SESSION["role"] === "admin";
$currentUserId = (int) ($_SESSION["user_id"] ?? 0);

$dashboardLink = $isAdmin
    ? BASE_URL . "/Dashboards/admin_dashboard.php"
    : BASE_URL . "/Dashboards/coordinator_dashboard.php";

$portalLabel = $isAdmin
    ? "Admin Portal"
    : "Research Coordinator Portal";

$subjectVisitId = $_GET["id"] ?? null;

if (!$subjectVisitId || !is_numeric($subjectVisitId)) {
    header("Location: " . BASE_URL . "/Studies/studies.php");
    exit;
}

$subjectVisitId = (int) $subjectVisitId;

// ---------------------------------------------------------
// Load actual visit + study participation + subject + study
// ---------------------------------------------------------

$stmt = $pdo->prepare("
    SELECT
        sv.id AS subject_visit_id,
        sv.study_subject_id,
        sv.visit_template_id,
        sv.visit_name_snapshot,
        sv.target_day_snapshot,
        sv.occurrence_number,
        sv.scheduled_date,
        sv.actual_visit_date,
        sv.status,
        sv.expected_total_snapshot,
        sv.submitted_total,
        sv.notes,
        sv.submitted_at,
        sv.created_at,

        ss.study_id,
        ss.subject_id,
        ss.screening_status,

        s.study_code,
        s.study_name,

        sub.first_name,
        sub.last_name,
        sub.initials

    FROM subject_visits sv

    INNER JOIN study_subjects ss
        ON ss.id = sv.study_subject_id

    INNER JOIN studies s
        ON s.id = ss.study_id

    INNER JOIN subjects sub
        ON sub.id = ss.subject_id

    WHERE sv.id = ?
    LIMIT 1
");

$stmt->execute([$subjectVisitId]);
$visit = $stmt->fetch();

if (!$visit) {
    header("Location: " . BASE_URL . "/Studies/studies.php");
    exit;
}

$studyId = (int) $visit["study_id"];
$studySubjectId = (int) $visit["study_subject_id"];

// ---------------------------------------------------------
// Coordinator access control
// ---------------------------------------------------------

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
        $currentUserId
    ]);

    $assignment = $stmt->fetch();

    if (!$assignment) {
        header(
            "Location: "
            . BASE_URL
            . "/Studies/studies.php?access_denied=1"
        );
        exit;
    }
}

// ---------------------------------------------------------
// Load actual visit procedures
// ---------------------------------------------------------

$stmt = $pdo->prepare("
    SELECT
        id,
        procedure_name_snapshot,
        budgeted_amount_snapshot,
        required_snapshot,
        status,
        notes,
        completed_by,
        completed_at
    FROM subject_visit_procedures
    WHERE subject_visit_id = ?
    ORDER BY id
");

$stmt->execute([$subjectVisitId]);
$procedures = $stmt->fetchAll();

// ---------------------------------------------------------
// Calculate procedure counts and completed total
// ---------------------------------------------------------

$pendingCount = 0;
$doneCount = 0;
$notDoneCount = 0;
$notApplicableCount = 0;

$completedTotal = 0.00;

foreach ($procedures as $procedure) {
    $procedureStatus = $procedure["status"];

    if ($procedureStatus === "done") {
        $doneCount++;

        if ($procedure["budgeted_amount_snapshot"] !== null) {
            $completedTotal +=
                (float) $procedure["budgeted_amount_snapshot"];
        }
    } elseif ($procedureStatus === "not_done") {
        $notDoneCount++;
    } elseif ($procedureStatus === "not_applicable") {
        $notApplicableCount++;
    } else {
        $pendingCount++;
    }
}

// ---------------------------------------------------------
// Display helpers
// ---------------------------------------------------------

$subjectName = trim(
    ($visit["first_name"] ?? "")
    . " "
    . ($visit["last_name"] ?? "")
);

if ($subjectName === "") {
    $subjectName = $visit["initials"] ?? "Unknown Subject";
}

$visitStatusLabels = [
    "open" => "Open",
    "submitted" => "Submitted"
];

$procedureStatusLabels = [
    "pending" => "Pending",
    "done" => "Done",
    "not_done" => "Not Done",
    "not_applicable" => "Not Applicable"
];

$visitStatusLabel =
    $visitStatusLabels[$visit["status"]]
    ?? ucwords(
        str_replace("_", " ", $visit["status"])
    );
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Subject Visit | CTMS</title>

    <link
        rel="stylesheet"
        href="<?php echo BASE_URL; ?>/Assets/CSS/style.css"
    >
</head>
<body>

<header class="site-header">
    <div class="top-bar">
        <div>Clinical Trial Management System</div>

        <div>
            <?php echo htmlspecialchars($portalLabel); ?>
        </div>
    </div>

    <nav class="main-nav">
        <a
            href="<?php echo BASE_URL; ?>/Auth/portal.php"
            class="brand"
        >
            CTMS <span>Portal</span>
        </a>

        <div class="nav-links">
            <a href="<?php echo htmlspecialchars($dashboardLink); ?>">
                Dashboard
            </a>

            <a href="<?php echo BASE_URL; ?>/Studies/studies.php">
                Studies
            </a>

            <a href="<?php echo BASE_URL; ?>/Subjects/subjects.php">
                Subjects
            </a>

            <a href="<?php echo BASE_URL; ?>/Reports/enrollment_rates.php">
                Reports
            </a>

            <a href="<?php echo BASE_URL; ?>/Auth/logout.php">
                Logout
            </a>
        </div>
    </nav>
</header>

<main class="page-wrapper">

    <section class="page-title">
        <h1>
            <?php
            echo htmlspecialchars(
                $visit["visit_name_snapshot"]
            );
            ?>
        </h1>

        <p>
            <?php echo htmlspecialchars($subjectName); ?>
            —
            <?php echo htmlspecialchars($visit["study_code"]); ?>
            —
            <?php echo htmlspecialchars($visit["study_name"]); ?>
        </p>
    </section>

    <section class="card" style="margin-bottom: 28px;">
        <a
            href="<?php echo BASE_URL; ?>/Studies/study_subject_visits.php?study_subject_id=<?php echo $studySubjectId; ?>"
            class="btn btn-secondary"
        >
            Back to Subject Visits
        </a>
    </section>

    <section class="card-grid">

        <div class="card">
            <h3>Visit Status</h3>

            <div class="stat-number" style="font-size: 24px;">
                <?php echo htmlspecialchars($visitStatusLabel); ?>
            </div>

            <p>Current actual visit status.</p>
        </div>

        <div class="card">
            <h3>Expected Total</h3>

            <div class="stat-number" style="font-size: 24px;">
                $<?php
                echo number_format(
                    (float) $visit["expected_total_snapshot"],
                    2
                );
                ?>
            </div>

            <p>Budgeted value of the complete visit.</p>
        </div>

        <div class="card">
            <h3>Completed Total</h3>

            <div class="stat-number" style="font-size: 24px;">
                $<?php echo number_format($completedTotal, 2); ?>
            </div>

            <p>Current value of procedures marked Done.</p>
        </div>

        <div class="card">
            <h3>Procedures</h3>

            <div class="stat-number">
                <?php echo count($procedures); ?>
            </div>

            <p>Procedures snapshotted into this visit.</p>
        </div>

    </section>

    <section class="card" style="margin-top: 28px; margin-bottom: 28px;">
        <h2>Visit Details</h2>

        <table>
            <tbody>
                <tr>
                    <th>Subject</th>
                    <td>
                        <?php echo htmlspecialchars($subjectName); ?>
                    </td>
                </tr>

                <tr>
                    <th>Visit</th>
                    <td>
                        <?php
                        echo htmlspecialchars(
                            $visit["visit_name_snapshot"]
                        );
                        ?>
                    </td>
                </tr>

                <tr>
                    <th>Occurrence</th>
                    <td>
                        <?php
                        echo (int) $visit["occurrence_number"];
                        ?>
                    </td>
                </tr>

                <tr>
                    <th>Target Day</th>
                    <td>
                        <?php if ($visit["target_day_snapshot"] === null): ?>
                            N/A
                        <?php else: ?>
                            Day
                            <?php
                            echo (int) $visit["target_day_snapshot"];
                            ?>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th>Scheduled Date</th>
                    <td>
                        <?php
                        echo htmlspecialchars(
                            $visit["scheduled_date"]
                            ?: "N/A"
                        );
                        ?>
                    </td>
                </tr>

                <tr>
                    <th>Actual Visit Date</th>
                    <td>
                        <?php
                        echo htmlspecialchars(
                            $visit["actual_visit_date"]
                            ?: "N/A"
                        );
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </section>

    <section class="card" style="margin-bottom: 28px;">
        <h2>Procedure Status Summary</h2>

        <div class="card-grid">
            <div class="card">
                <h3>Pending</h3>
                <div class="stat-number">
                    <?php echo $pendingCount; ?>
                </div>
            </div>

            <div class="card">
                <h3>Done</h3>
                <div class="stat-number">
                    <?php echo $doneCount; ?>
                </div>
            </div>

            <div class="card">
                <h3>Not Done</h3>
                <div class="stat-number">
                    <?php echo $notDoneCount; ?>
                </div>
            </div>

            <div class="card">
                <h3>Not Applicable</h3>
                <div class="stat-number">
                    <?php echo $notApplicableCount; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="card">
        <h2>Procedure Checklist</h2>

        <?php if (empty($procedures)): ?>

            <p>No procedures were copied into this visit.</p>

        <?php else: ?>

            <div class="table-card">
                <table>
                    <thead>
                        <tr>
                            <th>Procedure</th>
                            <th>Required</th>
                            <th>Budgeted Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($procedures as $procedure): ?>
                            <?php
                            $procedureStatus =
                                $procedureStatusLabels[
                                    $procedure["status"]
                                ]
                                ?? ucwords(
                                    str_replace(
                                        "_",
                                        " ",
                                        $procedure["status"]
                                    )
                                );
                            ?>

                            <tr>
                                <td>
                                    <?php
                                    echo htmlspecialchars(
                                        $procedure[
                                            "procedure_name_snapshot"
                                        ]
                                    );
                                    ?>
                                </td>

                                <td>
                                    <?php
                                    echo (int) $procedure["required_snapshot"] === 1
                                        ? "Yes"
                                        : "No";
                                    ?>
                                </td>

                                <td>
                                    <?php
                                    if (
                                        $procedure[
                                            "budgeted_amount_snapshot"
                                        ] === null
                                    ):
                                    ?>
                                        —
                                    <?php else: ?>
                                        $<?php
                                        echo number_format(
                                            (float) $procedure[
                                                "budgeted_amount_snapshot"
                                            ],
                                            2
                                        );
                                        ?>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php
                                    echo htmlspecialchars(
                                        $procedureStatus
                                    ); 
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </section>

</main>

</body>
</html>