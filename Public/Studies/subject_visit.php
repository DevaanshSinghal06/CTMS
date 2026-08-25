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

$error = "";

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
        sv.target_date_snapshot,
        sv.window_start_date_snapshot,
        sv.window_end_date_snapshot,
        sv.scheduled_time,
        sv.actual_start_time,
        sv.actual_end_time,
        sv.visit_timezone,
        sv.status,
        sv.expected_total_snapshot,
        sv.submitted_total,
        sv.notes,
        sv.submitted_by,
        sv.submitted_at,
        sv.created_at,

        ss.study_id,
        ss.subject_id,
        ss.screening_status,
        ss.schedule_anchor_date,

        svt.is_schedule_anchor,

        s.study_code,
        s.study_name,

        sub.first_name,
        sub.last_name,
        sub.initials

    FROM subject_visits sv

    INNER JOIN study_subjects ss
        ON ss.id = sv.study_subject_id

    LEFT JOIN study_visit_templates svt
        ON svt.id = sv.visit_template_id
        AND svt.study_id = ss.study_id

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
// Subject display name
// ---------------------------------------------------------

$subjectName = trim(
    ($visit["first_name"] ?? "")
    . " "
    . ($visit["last_name"] ?? "")
);

if ($subjectName === "") {
    $subjectName = $visit["initials"] ?? "Unknown Subject";
}

// ---------------------------------------------------------
// Procedure statuses
// ---------------------------------------------------------

$allowedProcedureStatuses = [
    "pending",
    "done",
    "not_done",
    "not_applicable"
];

$procedureStatusLabels = [
    "pending" => "Pending",
    "done" => "Done",
    "not_done" => "Not Done",
    "not_applicable" => "Not Applicable"
];

function is_valid_visit_date(?string $value): bool
{
    if ($value === null) {
        return true;
    }

    $date = DateTime::createFromFormat("Y-m-d", $value);

    return $date !== false
        && $date->format("Y-m-d") === $value;
}

function is_valid_visit_time(?string $value): bool
{
    if ($value === null) {
        return true;
    }

    $time = DateTime::createFromFormat("H:i", $value);

    return $time !== false
        && $time->format("H:i") === $value;
}

// ---------------------------------------------------------
// POST handling
// ---------------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verify_csrf()) {
        http_response_code(400);
        exit("Invalid or expired request token.");
    }

    $action = $_POST["action"] ?? "";

    // =====================================================
    // SAVE VISIT TIMING
    // =====================================================

    if ($action === "save_timing") {
        if ($visit["status"] !== "open") {
            $error = "Submitted visits cannot be edited.";
        } else {
            $scheduledDate =
                trim($_POST["scheduled_date"] ?? "");

            $scheduledTime =
                trim($_POST["scheduled_time"] ?? "");

            $actualVisitDate =
                trim($_POST["actual_visit_date"] ?? "");

            $actualStartTime =
                trim($_POST["actual_start_time"] ?? "");

            $actualEndTime =
                trim($_POST["actual_end_time"] ?? "");

            $scheduledDate =
                $scheduledDate === "" ? null : $scheduledDate;

            $scheduledTime =
                $scheduledTime === "" ? null : $scheduledTime;

            $actualVisitDate =
                $actualVisitDate === "" ? null : $actualVisitDate;

            $actualStartTime =
                $actualStartTime === "" ? null : $actualStartTime;

            $actualEndTime =
                $actualEndTime === "" ? null : $actualEndTime;

            if (!is_valid_visit_date($scheduledDate)) {
                $error = "Invalid scheduled date.";
            } elseif (!is_valid_visit_time($scheduledTime)) {
                $error = "Invalid scheduled time.";
            } elseif (!is_valid_visit_date($actualVisitDate)) {
                $error = "Invalid actual visit date.";
            } elseif (!is_valid_visit_time($actualStartTime)) {
                $error = "Invalid actual start time.";
            } elseif (!is_valid_visit_time($actualEndTime)) {
                $error = "Invalid actual end time.";
            } elseif (
                $scheduledTime !== null
                && $scheduledDate === null
            ) {
                $error =
                    "A scheduled time requires a scheduled date.";
            } elseif (
                ($actualStartTime !== null || $actualEndTime !== null)
                && $actualVisitDate === null
            ) {
                $error =
                    "Actual visit times require an actual visit date.";
            } elseif (
                $actualEndTime !== null
                && $actualStartTime === null
            ) {
                $error =
                    "An actual end time requires an actual start time.";
            } elseif (
                $actualStartTime !== null
                && $actualEndTime !== null
                && $actualEndTime < $actualStartTime
            ) {
                $error =
                    "Actual end time cannot be earlier than actual start time.";
            } else {
                $oldScheduledTime =
                    $visit["scheduled_time"]
                        ? substr($visit["scheduled_time"], 0, 5)
                        : null;

                $oldActualStartTime =
                    $visit["actual_start_time"]
                        ? substr($visit["actual_start_time"], 0, 5)
                        : null;

                $oldActualEndTime =
                    $visit["actual_end_time"]
                        ? substr($visit["actual_end_time"], 0, 5)
                        : null;

                $isScheduleAnchor =
                    (int) ($visit["is_schedule_anchor"] ?? 0) === 1;

                $oldScheduleAnchorDate =
                    $visit["schedule_anchor_date"] ?: null;

                $anchorDateChanged =
                    $isScheduleAnchor
                    && $oldScheduleAnchorDate !== $actualVisitDate;

                $changes = [];

                $timingComparisons = [
                    "Scheduled Date" => [
                        $visit["scheduled_date"],
                        $scheduledDate
                    ],
                    "Scheduled Time" => [
                        $oldScheduledTime,
                        $scheduledTime
                    ],
                    "Actual Visit Date" => [
                        $visit["actual_visit_date"],
                        $actualVisitDate
                    ],
                    "Actual Start Time" => [
                        $oldActualStartTime,
                        $actualStartTime
                    ],
                    "Actual End Time" => [
                        $oldActualEndTime,
                        $actualEndTime
                    ]
                ];

                foreach (
                    $timingComparisons as $label => [$oldValue, $newValue]
                ) {
                    if ($oldValue !== $newValue) {
                        $changes[] =
                            $label
                            . ' changed from "'
                            . ($oldValue ?? "N/A")
                            . '" to "'
                            . ($newValue ?? "N/A")
                            . '"';
                    }
                }

                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("
                        UPDATE subject_visits
                        SET
                            scheduled_date = ?,
                            scheduled_time = ?,
                            actual_visit_date = ?,
                            actual_start_time = ?,
                            actual_end_time = ?
                        WHERE id = ?
                            AND status = 'open'
                    ");

                    $stmt->execute([
                        $scheduledDate,
                        $scheduledTime,
                        $actualVisitDate,
                        $actualStartTime,
                        $actualEndTime,
                        $subjectVisitId
                    ]);

                    if ($anchorDateChanged) {
                        $anchorStmt = $pdo->prepare("
                            UPDATE study_subjects
                            SET schedule_anchor_date = ?
                            WHERE id = ?
                        ");

                        $anchorStmt->execute([
                            $actualVisitDate,
                            $studySubjectId
                        ]);
                    }

                    $pdo->commit();

                    if (!empty($changes)) {
                        log_action(
                            "updated",
                            "subject_visit",
                            $subjectVisitId,
                            "Updated "
                            . $visit["visit_name_snapshot"]
                            . " timing for "
                            . $subjectName
                            . " in "
                            . $visit["study_code"]
                            . ": "
                            . implode("; ", $changes)
                        );
                    }

                    if ($anchorDateChanged) {
                        log_action(
                            "updated",
                            "study_subject",
                            $studySubjectId,
                            "Updated schedule anchor date for "
                            . $subjectName
                            . " in "
                            . $visit["study_code"]
                            . " from "
                            . ($oldScheduleAnchorDate ?? "N/A")
                            . " to "
                            . ($actualVisitDate ?? "N/A")
                            . " based on "
                            . $visit["visit_name_snapshot"]
                        );
                    }

                    header(
                        "Location: "
                        . BASE_URL
                        . "/Studies/subject_visit.php?id="
                        . $subjectVisitId
                        . "&timing_saved=1"
                    );
                    exit;

                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $error =
                        "Visit timing could not be saved. "
                        . "No timing changes were saved.";
                }
            }
        }

    // =====================================================
    // SAVE PROGRESS
    // =====================================================

    } elseif ($action === "save_progress") {
        if ($visit["status"] !== "open") {
            $error = "Submitted visits cannot be edited.";
        } else {
            $postedStatuses =
                $_POST["procedure_status"] ?? [];

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    status,
                    completed_by,
                    completed_at
                FROM subject_visit_procedures
                WHERE subject_visit_id = ?
                ORDER BY id
            ");

            $stmt->execute([$subjectVisitId]);
            $currentProcedures = $stmt->fetchAll();

            $updates = [];

            foreach ($currentProcedures as $currentProcedure) {
                $procedureId =
                    (int) $currentProcedure["id"];

                $newStatus =
                    $postedStatuses[$procedureId]
                    ?? $currentProcedure["status"];

                if (
                    !in_array(
                        $newStatus,
                        $allowedProcedureStatuses,
                        true
                    )
                ) {
                    $error = "Invalid procedure status.";
                    break;
                }

                $updates[] = [
                    "id" => $procedureId,
                    "old_status" =>
                        $currentProcedure["status"],
                    "new_status" =>
                        $newStatus,
                    "completed_by" =>
                        $currentProcedure["completed_by"],
                    "completed_at" =>
                        $currentProcedure["completed_at"]
                ];
            }

            if ($error === "") {
                try {
                    $pdo->beginTransaction();

                    $updateStmt = $pdo->prepare("
                        UPDATE subject_visit_procedures
                        SET
                            status = ?,
                            completed_by = ?,
                            completed_at = ?
                        WHERE id = ?
                            AND subject_visit_id = ?
                    ");

                    $changedCount = 0;

                    $summaryCounts = [
                        "pending" => 0,
                        "done" => 0,
                        "not_done" => 0,
                        "not_applicable" => 0
                    ];

                    $completedTimestamp =
                        date("Y-m-d H:i:s");

                    foreach ($updates as $update) {
                        $newStatus =
                            $update["new_status"];

                        $summaryCounts[$newStatus]++;

                        if (
                            $update["old_status"]
                            !== $newStatus
                        ) {
                            $changedCount++;
                        }

                        if ($newStatus === "done") {
                            if (
                                $update["old_status"] === "done"
                                && $update["completed_at"] !== null
                            ) {
                                $completedBy =
                                    $update["completed_by"];

                                $completedAt =
                                    $update["completed_at"];
                            } else {
                                $completedBy =
                                    $currentUserId;

                                $completedAt =
                                    $completedTimestamp;
                            }
                        } else {
                            $completedBy = null;
                            $completedAt = null;
                        }

                        $updateStmt->execute([
                            $newStatus,
                            $completedBy,
                            $completedAt,
                            $update["id"],
                            $subjectVisitId
                        ]);
                    }

                    $pdo->commit();

                    if ($changedCount > 0) {
                        log_action(
                            "updated",
                            "subject_visit",
                            $subjectVisitId,
                            "Updated "
                            . $visit["visit_name_snapshot"]
                            . " progress for "
                            . $subjectName
                            . " in "
                            . $visit["study_code"]
                            . ": "
                            . $changedCount
                            . " procedure status changes; "
                            . $summaryCounts["done"]
                            . " Done, "
                            . $summaryCounts["not_done"]
                            . " Not Done, "
                            . $summaryCounts["not_applicable"]
                            . " Not Applicable, "
                            . $summaryCounts["pending"]
                            . " Pending"
                        );
                    }

                    header(
                        "Location: "
                        . BASE_URL
                        . "/Studies/subject_visit.php?id="
                        . $subjectVisitId
                        . "&saved=1"
                    );
                    exit;

                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $error =
                        "Visit progress could not be saved. "
                        . "No procedure changes were saved.";
                }
            }
        }

    // =====================================================
    // SUBMIT VISIT
    // =====================================================

    } elseif ($action === "submit_visit") {
        if ($visit["status"] !== "open") {
            $error = "This visit has already been submitted.";
        } else {
            $stmt = $pdo->prepare("
                SELECT
                    id,
                    status,
                    budgeted_amount_snapshot
                FROM subject_visit_procedures
                WHERE subject_visit_id = ?
                ORDER BY id
            ");

            $stmt->execute([$subjectVisitId]);
            $submissionProcedures = $stmt->fetchAll();

            if (empty($submissionProcedures)) {
                $error =
                    "A visit with no procedures cannot be submitted.";
            } else {
                $pendingCountForSubmission = 0;
                $doneCountForSubmission = 0;
                $notDoneCountForSubmission = 0;
                $notApplicableCountForSubmission = 0;

                $finalSubmittedTotal = 0.00;

                foreach ($submissionProcedures as $procedure) {
                    $status = $procedure["status"];

                    if ($status === "pending") {
                        $pendingCountForSubmission++;
                    } elseif ($status === "done") {
                        $doneCountForSubmission++;

                        if (
                            $procedure[
                                "budgeted_amount_snapshot"
                            ] !== null
                        ) {
                            $finalSubmittedTotal +=
                                (float) $procedure[
                                    "budgeted_amount_snapshot"
                                ];
                        }
                    } elseif ($status === "not_done") {
                        $notDoneCountForSubmission++;
                    } elseif ($status === "not_applicable") {
                        $notApplicableCountForSubmission++;
                    }
                }

                if ($pendingCountForSubmission > 0) {
                    $error =
                        "All procedures must be resolved before submitting the visit. "
                        . $pendingCountForSubmission
                        . " procedure(s) are still Pending.";
                } else {
                    try {
                        $pdo->beginTransaction();

                        $submittedAt =
                            date("Y-m-d H:i:s");

                        $stmt = $pdo->prepare("
                            UPDATE subject_visits
                            SET
                                status = 'submitted',
                                submitted_total = ?,
                                submitted_by = ?,
                                submitted_at = ?
                            WHERE id = ?
                                AND status = 'open'
                        ");

                        $stmt->execute([
                            $finalSubmittedTotal,
                            $currentUserId,
                            $submittedAt,
                            $subjectVisitId
                        ]);

                        if ($stmt->rowCount() !== 1) {
                            throw new RuntimeException(
                                "Visit was not available for submission."
                            );
                        }

                        $pdo->commit();

                        log_action(
                            "submitted",
                            "subject_visit",
                            $subjectVisitId,
                            "Submitted "
                            . $visit["visit_name_snapshot"]
                            . " for "
                            . $subjectName
                            . " in "
                            . $visit["study_code"]
                            . ": "
                            . $doneCountForSubmission
                            . " Done, "
                            . $notDoneCountForSubmission
                            . " Not Done, "
                            . $notApplicableCountForSubmission
                            . " Not Applicable; submitted total $"
                            . number_format(
                                $finalSubmittedTotal,
                                2
                            )
                        );

                        header(
                            "Location: "
                            . BASE_URL
                            . "/Studies/subject_visit.php?id="
                            . $subjectVisitId
                            . "&submitted=1"
                        );
                        exit;

                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        $error =
                            "The visit could not be submitted. "
                            . "The visit remains open.";
                    }
                }
            }
        }
    }
}

// ---------------------------------------------------------
// Reload visit after any non-redirecting POST attempt
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
        sv.target_date_snapshot,
        sv.window_start_date_snapshot,
        sv.window_end_date_snapshot,
        sv.scheduled_time,
        sv.actual_start_time,
        sv.actual_end_time,
        sv.visit_timezone,
        sv.status,
        sv.expected_total_snapshot,
        sv.submitted_total,
        sv.notes,
        sv.submitted_by,
        sv.submitted_at,
        sv.created_at,

        ss.study_id,
        ss.subject_id,
        ss.screening_status,
        ss.schedule_anchor_date,

        svt.is_schedule_anchor,

        s.study_code,
        s.study_name,

        sub.first_name,
        sub.last_name,
        sub.initials

    FROM subject_visits sv
    INNER JOIN study_subjects ss
        ON ss.id = sv.study_subject_id

    LEFT JOIN study_visit_templates svt
        ON svt.id = sv.visit_template_id
        AND svt.study_id = ss.study_id

    INNER JOIN studies s
        ON s.id = ss.study_id
    INNER JOIN subjects sub
        ON sub.id = ss.subject_id
    WHERE sv.id = ?
    LIMIT 1
");

$stmt->execute([$subjectVisitId]);
$visit = $stmt->fetch();

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
// Calculate counts + completed total
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

        if (
            $procedure["budgeted_amount_snapshot"]
            !== null
        ) {
            $completedTotal +=
                (float) $procedure[
                    "budgeted_amount_snapshot"
                ];
        }
    } elseif ($procedureStatus === "not_done") {
        $notDoneCount++;
    } elseif (
        $procedureStatus === "not_applicable"
    ) {
        $notApplicableCount++;
    } else {
        $pendingCount++;
    }
}

// ---------------------------------------------------------
// Display helpers
// ---------------------------------------------------------

$visitStatusLabels = [
    "open" => "Open",
    "submitted" => "Submitted"
];

$visitStatusLabel =
    $visitStatusLabels[$visit["status"]]
    ?? ucwords(
        str_replace("_", " ", $visit["status"])
    );

$isSubmitted =
    $visit["status"] === "submitted";
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
            <?php echo htmlspecialchars(
                $visit["visit_name_snapshot"]
            ); ?>
        </h1>

        <p>
            <?php echo htmlspecialchars($subjectName); ?>
            —
            <?php echo htmlspecialchars($visit["study_code"]); ?>
            —
            <?php echo htmlspecialchars($visit["study_name"]); ?>
        </p>
    </section>

    <?php if (isset($_GET["timing_saved"])): ?>
        <div class="alert alert-success">
            Visit timing saved successfully.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET["saved"])): ?>
        <div class="alert alert-success">
            Visit progress saved successfully.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET["submitted"])): ?>
        <div class="alert alert-success">
            Visit submitted successfully. This visit is now locked.
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

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

            <p>Value of procedures marked Done.</p>
        </div>

        <div class="card">
            <h3>Procedures</h3>

            <div class="stat-number">
                <?php echo count($procedures); ?>
            </div>

            <p>Procedures snapshotted into this visit.</p>
        </div>

        <?php if ($isSubmitted): ?>
            <div class="card">
                <h3>Submitted Total</h3>

                <div class="stat-number" style="font-size: 24px;">
                    $<?php
                    echo number_format(
                        (float) $visit["submitted_total"],
                        2
                    );
                    ?>
                </div>

                <p>Final submitted value of this visit.</p>
            </div>
        <?php endif; ?>

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
                        <?php echo htmlspecialchars(
                            $visit["visit_name_snapshot"]
                        ); ?>
                    </td>
                </tr>

                <tr>
                    <th>Occurrence</th>
                    <td>
                        <?php echo (int) $visit["occurrence_number"]; ?>
                    </td>
                </tr>

                <tr>
                    <th>Target Day</th>
                    <td>
                        <?php if ($visit["target_day_snapshot"] === null): ?>
                            N/A
                        <?php else: ?>
                            Day <?php echo (int) $visit["target_day_snapshot"]; ?>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th>Scheduled Date</th>
                    <td>
                        <?php echo htmlspecialchars(
                            $visit["scheduled_date"] ?: "N/A"
                        ); ?>
                    </td>
                </tr>

                <tr>
                    <th>Actual Visit Date</th>
                    <td>
                        <?php echo htmlspecialchars(
                            $visit["actual_visit_date"] ?: "N/A"
                        ); ?>
                    </td>
                </tr>

                <tr>
                    <th>Submitted At</th>
                    <td>
                        <?php echo htmlspecialchars(
                            $visit["submitted_at"] ?: "N/A"
                        ); ?>
                    </td>
                </tr>

                <tr>
                    <th>Submitted Total</th>
                    <td>
                        <?php if ($visit["submitted_total"] === null): ?>
                            N/A
                        <?php else: ?>
                            $<?php
                            echo number_format(
                                (float) $visit["submitted_total"],
                                2
                            );
                            ?>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </section>

    <?php if (!$isSubmitted): ?>

        <section
            class="card"
            style="margin-bottom: 28px;"
        >
            <h2>Visit Scheduling & Timing</h2>

            <p>
                Record when the visit was scheduled and when it
                actually occurred.
            </p>

            <form
                method="POST"
                action="<?php echo BASE_URL; ?>/Studies/subject_visit.php?id=<?php echo $subjectVisitId; ?>"
            >
                <?php echo csrf_field(); ?>

                <input
                    type="hidden"
                    name="action"
                    value="save_timing"
                >

                <div class="form-group">
                    <label for="scheduled_date">
                        Scheduled Date
                    </label>

                    <input
                        type="date"
                        id="scheduled_date"
                        name="scheduled_date"
                        value="<?php echo htmlspecialchars(
                            $visit["scheduled_date"] ?? ""
                        ); ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="scheduled_time">
                        Scheduled Time
                    </label>

                    <input
                        type="time"
                        id="scheduled_time"
                        name="scheduled_time"
                        value="<?php echo htmlspecialchars(
                            $visit["scheduled_time"]
                                ? substr($visit["scheduled_time"], 0, 5)
                                : ""
                        ); ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="actual_visit_date">
                        Actual Visit Date
                    </label>

                    <input
                        type="date"
                        id="actual_visit_date"
                        name="actual_visit_date"
                        value="<?php echo htmlspecialchars(
                            $visit["actual_visit_date"] ?? ""
                        ); ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="actual_start_time">
                        Actual Start Time
                    </label>

                    <input
                        type="time"
                        id="actual_start_time"
                        name="actual_start_time"
                        value="<?php echo htmlspecialchars(
                            $visit["actual_start_time"]
                                ? substr($visit["actual_start_time"], 0, 5)
                                : ""
                        ); ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="actual_end_time">
                        Actual End Time
                    </label>

                    <input
                        type="time"
                        id="actual_end_time"
                        name="actual_end_time"
                        value="<?php echo htmlspecialchars(
                            $visit["actual_end_time"]
                                ? substr($visit["actual_end_time"], 0, 5)
                                : ""
                        ); ?>"
                    >
                </div>

                <div class="form-group">
                    <label>Visit Time Zone</label>

                    <div>
                        <?php echo htmlspecialchars(
                            $visit["visit_timezone"]
                                ?? "America/Chicago"
                        ); ?>
                    </div>
                </div>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Save Visit Timing
                </button>
            </form>
        </section>

    <?php endif; ?>

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

        <?php elseif ($isSubmitted): ?>

            <div class="alert alert-success">
                This visit has been submitted and is locked.
            </div>

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
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars(
                                        $procedure[
                                            "procedure_name_snapshot"
                                        ]
                                    ); ?>
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
                                        $procedureStatusLabels[
                                            $procedure["status"]
                                        ]
                                        ?? $procedure["status"]
                                    );
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>

            <form
                method="POST"
                action="<?php echo BASE_URL; ?>/Studies/subject_visit.php?id=<?php echo $subjectVisitId; ?>"
            >
                <?php echo csrf_field(); ?>

                <input
                    type="hidden"
                    name="action"
                    value="save_progress"
                >

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
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars(
                                            $procedure[
                                                "procedure_name_snapshot"
                                            ]
                                        ); ?>
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
                                        <select
                                            name="procedure_status[<?php echo (int) $procedure["id"]; ?>]"
                                        >
                                            <option
                                                value="pending"
                                                <?php echo $procedure["status"] === "pending" ? "selected" : ""; ?>
                                            >
                                                Pending
                                            </option>

                                            <option
                                                value="done"
                                                <?php echo $procedure["status"] === "done" ? "selected" : ""; ?>
                                            >
                                                Done
                                            </option>

                                            <option
                                                value="not_done"
                                                <?php echo $procedure["status"] === "not_done" ? "selected" : ""; ?>
                                            >
                                                Not Done
                                            </option>

                                            <option
                                                value="not_applicable"
                                                <?php echo $procedure["status"] === "not_applicable" ? "selected" : ""; ?>
                                            >
                                                Not Applicable
                                            </option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top: 24px;">
                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        Save Progress
                    </button>
                </div>
            </form>

            <div style="margin-top: 28px;">
                <?php if ($pendingCount > 0): ?>

                    <p>
                        <strong>
                            Visit cannot be submitted yet.
                        </strong>
                        Resolve all
                        <?php echo $pendingCount; ?>
                        remaining Pending procedure(s).
                    </p>

                <?php else: ?>

                    <form
                        method="POST"
                        action="<?php echo BASE_URL; ?>/Studies/subject_visit.php?id=<?php echo $subjectVisitId; ?>"
                        onsubmit="return confirm('Submit this visit? After submission, procedure statuses will be locked.');"
                    >
                        <?php echo csrf_field(); ?>

                        <input
                            type="hidden"
                            name="action"
                            value="submit_visit"
                        >

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            Submit Visit
                        </button>
                    </form>

                <?php endif; ?>
            </div>

        <?php endif; ?>
    </section>

</main>

</body>
</html>