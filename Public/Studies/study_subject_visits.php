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

$studySubjectId = $_GET["study_subject_id"] ?? null;

if (!$studySubjectId || !is_numeric($studySubjectId)) {
    header("Location: " . BASE_URL . "/Studies/studies.php");
    exit;
}

$studySubjectId = (int) $studySubjectId;

// ---------------------------------------------------------
// Load study participation + subject + study
// ---------------------------------------------------------

$stmt = $pdo->prepare("
    SELECT
        ss.id AS study_subject_id,
        ss.study_id,
        ss.subject_id,
        ss.screening_status,
        s.study_code,
        s.study_name,
        s.status AS study_status,
        sub.first_name,
        sub.last_name,
        sub.initials
    FROM study_subjects ss
    INNER JOIN studies s
        ON s.id = ss.study_id
    INNER JOIN subjects sub
        ON sub.id = ss.subject_id
    WHERE ss.id = ?
    LIMIT 1
");

$stmt->execute([$studySubjectId]);
$participation = $stmt->fetch();

if (!$participation) {
    header("Location: " . BASE_URL . "/Studies/studies.php");
    exit;
}

$studyId = (int) $participation["study_id"];

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
// Create actual visit from template
// ---------------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verify_csrf()) {
        http_response_code(400);
        exit("Invalid or expired request token.");
    }

    $action = $_POST["action"] ?? "";

    if ($action === "create_visit") {
        $visitTemplateId =
            $_POST["visit_template_id"] ?? null;

        if (
            !$visitTemplateId ||
            !is_numeric($visitTemplateId)
        ) {
            $error = "Invalid visit template.";
        } else {
            $visitTemplateId =
                (int) $visitTemplateId;

            // Make sure this template belongs to the same study.
            $stmt = $pdo->prepare("
                SELECT
                    svt.id,
                    svt.visit_name,
                    svt.visit_order,
                    svt.target_day,
                    COALESCE(
                        (
                            SELECT SUM(svp.budgeted_amount)
                            FROM study_visit_procedures svp
                            WHERE svp.visit_template_id = svt.id
                        ),
                        0
                    ) AS expected_total
                FROM study_visit_templates svt
                WHERE svt.id = ?
                    AND svt.study_id = ?
                LIMIT 1
            ");

            $stmt->execute([
                $visitTemplateId,
                $studyId
            ]);

            $visitTemplate = $stmt->fetch();

            if (!$visitTemplate) {
                $error =
                    "The selected visit does not belong to this study.";
            } else {

                // Check how many actual occurrences already exist.
                $stmt = $pdo->prepare("
                    SELECT
                        COALESCE(MAX(occurrence_number), 0)
                            AS max_occurrence,
                        COUNT(*) AS total
                    FROM subject_visits
                    WHERE study_subject_id = ?
                        AND visit_template_id = ?
                ");

                $stmt->execute([
                    $studySubjectId,
                    $visitTemplateId
                ]);

                $occurrenceRow = $stmt->fetch();

                $existingCount =
                    (int) ($occurrenceRow["total"] ?? 0);

                $maxOccurrence =
                    (int) (
                        $occurrenceRow["max_occurrence"]
                        ?? 0
                    );

                $isUnscheduled =
                    stripos(
                        $visitTemplate["visit_name"],
                        "unscheduled"
                    ) !== false;

                if (
                    !$isUnscheduled &&
                    $existingCount > 0
                ) {
                    $error =
                        "This visit has already been created for this subject.";
                } else {
                    $occurrenceNumber =
                        $maxOccurrence + 1;

                    try {
                        $pdo->beginTransaction();

                        // ---------------------------------
                        // Create actual subject visit
                        // ---------------------------------

                        $stmt = $pdo->prepare("
                            INSERT INTO subject_visits
                            (
                                study_subject_id,
                                visit_template_id,
                                visit_name_snapshot,
                                target_day_snapshot,
                                occurrence_number,
                                status,
                                expected_total_snapshot,
                                created_by
                            )
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");

                        $stmt->execute([
                            $studySubjectId,
                            $visitTemplateId,
                            $visitTemplate["visit_name"],
                            $visitTemplate["target_day"],
                            $occurrenceNumber,
                            "open",
                            $visitTemplate["expected_total"],
                            $currentUserId
                        ]);

                        $subjectVisitId =
                            (int) $pdo->lastInsertId();

                        // ---------------------------------
                        // Load template procedures
                        // ---------------------------------

                        $stmt = $pdo->prepare("
                            SELECT
                                svp.id AS visit_procedure_id,
                                svp.budgeted_amount,
                                svp.required,
                                sp.procedure_name
                            FROM study_visit_procedures svp
                            INNER JOIN study_procedures sp
                                ON sp.id = svp.procedure_id
                            WHERE svp.visit_template_id = ?
                            ORDER BY svp.id
                        ");

                        $stmt->execute([
                            $visitTemplateId
                        ]);

                        $templateProcedures =
                            $stmt->fetchAll();

                        // ---------------------------------
                        // Snapshot procedures into actual visit
                        // ---------------------------------

                        $procedureInsert =
                            $pdo->prepare("
                                INSERT INTO subject_visit_procedures
                                (
                                    subject_visit_id,
                                    visit_procedure_id,
                                    procedure_name_snapshot,
                                    budgeted_amount_snapshot,
                                    required_snapshot,
                                    status
                                )
                                VALUES (?, ?, ?, ?, ?, ?)
                            ");

                        foreach (
                            $templateProcedures
                            as $procedure
                        ) {
                            $procedureInsert->execute([
                                $subjectVisitId,
                                $procedure["visit_procedure_id"],
                                $procedure["procedure_name"],
                                $procedure["budgeted_amount"],
                                $procedure["required"],
                                "pending"
                            ]);
                        }

                        $pdo->commit();

                        $subjectName = trim(
                            ($participation["first_name"] ?? "")
                            . " "
                            . ($participation["last_name"] ?? "")
                        );

                        if ($subjectName === "") {
                            $subjectName =
                                $participation["initials"]
                                ?? "Unknown Subject";
                        }

                        log_action(
                            "created",
                            "subject_visit",
                            $subjectVisitId,
                            "Created "
                            . $visitTemplate["visit_name"]
                            . " for "
                            . $subjectName
                            . " in "
                            . $participation["study_code"]
                            . " with "
                            . count($templateProcedures)
                            . " procedures"
                        );

                        header(
                            "Location: "
                            . BASE_URL
                            . "/Studies/study_subject_visits.php"
                            . "?study_subject_id="
                            . $studySubjectId
                            . "&created=1"
                        );
                        exit;

                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        $error =
                            "The subject visit could not be created. "
                            . "No visit records were saved.";
                    }
                }
            }
        }
    }
}

// ---------------------------------------------------------
// Load available visit templates
// ---------------------------------------------------------

$stmt = $pdo->prepare("
    SELECT
        svt.id,
        svt.visit_name,
        svt.visit_order,
        svt.target_day,

        COALESCE(
            (
                SELECT SUM(svp.budgeted_amount)
                FROM study_visit_procedures svp
                WHERE svp.visit_template_id = svt.id
            ),
            0
        ) AS expected_total,

        (
            SELECT COUNT(*)
            FROM subject_visits sv
            WHERE sv.study_subject_id = ?
                AND sv.visit_template_id = svt.id
        ) AS actual_visit_count

    FROM study_visit_templates svt
    WHERE svt.study_id = ?
    ORDER BY
        svt.visit_order,
        svt.id
");

$stmt->execute([
    $studySubjectId,
    $studyId
]);

$visitTemplates = $stmt->fetchAll();

// ---------------------------------------------------------
// Load actual visits already created
// ---------------------------------------------------------

$stmt = $pdo->prepare("
    SELECT
        sv.id,
        sv.visit_name_snapshot,
        sv.target_day_snapshot,
        sv.occurrence_number,
        sv.status,
        sv.scheduled_date,
        sv.actual_visit_date,
        sv.expected_total_snapshot,
        sv.submitted_total,
        sv.created_at,
        COUNT(svp.id) AS procedure_count
    FROM subject_visits sv
    LEFT JOIN subject_visit_procedures svp
        ON svp.subject_visit_id = sv.id
    WHERE sv.study_subject_id = ?
    GROUP BY
        sv.id,
        sv.visit_name_snapshot,
        sv.target_day_snapshot,
        sv.occurrence_number,
        sv.status,
        sv.scheduled_date,
        sv.actual_visit_date,
        sv.expected_total_snapshot,
        sv.submitted_total,
        sv.created_at
    ORDER BY
        sv.created_at,
        sv.id
");

$stmt->execute([$studySubjectId]);
$actualVisits = $stmt->fetchAll();

$subjectName = trim(
    ($participation["first_name"] ?? "")
    . " "
    . ($participation["last_name"] ?? "")
);

if ($subjectName === "") {
    $subjectName =
        $participation["initials"]
        ?? "Unknown Subject";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Subject Visits | CTMS</title>
    <link
        rel="stylesheet"
        href="<?php echo BASE_URL; ?>/Assets/CSS/style.css"
    >
</head>
<body>

<header class="site-header">
    <div class="top-bar">
        <div>
            Clinical Trial Management System
        </div>

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
        <h1>Subject Visits</h1>

        <p>
            <?php echo htmlspecialchars($subjectName); ?>
            —
            <?php
            echo htmlspecialchars(
                $participation["study_code"]
            );
            ?>
            —
            <?php
            echo htmlspecialchars(
                $participation["study_name"]
            );
            ?>
        </p>
    </section>

    <?php if (isset($_GET["created"])): ?>
        <div class="alert alert-success">
            Subject visit created successfully.
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <section class="card" style="margin-bottom: 28px;">
        <h2>Study Participation</h2>

        <p>
            <strong>Subject:</strong>
            <?php echo htmlspecialchars($subjectName); ?>
        </p>

        <p>
            <strong>Status:</strong>
            <?php
            echo htmlspecialchars(
                ucwords(
                    str_replace(
                        "_",
                        " ",
                        $participation["screening_status"]
                    )
                )
            );
            ?>
        </p>

        <a
            href="<?php echo BASE_URL; ?>/Studies/study_subjects.php?id=<?php echo $studyId; ?>"
            class="btn btn-secondary"
        >
            Back to Study Subjects
        </a>
    </section>

    <section class="card" style="margin-bottom: 28px;">
        <h2>Available Visit Templates</h2>

        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Visit</th>
                        <th>Target Day</th>
                        <th>Expected Total</th>
                        <th>Created</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($visitTemplates as $visit): ?>
                        <?php
                        $actualVisitCount =
                            (int) $visit["actual_visit_count"];

                        $isUnscheduled =
                            stripos(
                                $visit["visit_name"],
                                "unscheduled"
                            ) !== false;

                        $canCreate =
                            $isUnscheduled
                            || $actualVisitCount === 0;
                        ?>

                        <tr>
                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $visit["visit_name"]
                                );
                                ?>
                            </td>

                            <td>
                                <?php if ($visit["target_day"] === null): ?>
                                    —
                                <?php else: ?>
                                    Day
                                    <?php
                                    echo (int) $visit["target_day"];
                                    ?>
                                <?php endif; ?>
                            </td>

                            <td>
                                $<?php
                                echo number_format(
                                    (float) $visit["expected_total"],
                                    2
                                );
                                ?>
                            </td>

                            <td>
                                <?php echo $actualVisitCount; ?>
                            </td>

                            <td>
                                <?php if ($canCreate): ?>
                                    <form method="POST">
                                        <?php echo csrf_field(); ?>

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="create_visit"
                                        >

                                        <input
                                            type="hidden"
                                            name="visit_template_id"
                                            value="<?php echo (int) $visit["id"]; ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-primary"
                                        >
                                            Create Visit
                                        </button>
                                    </form>
                                <?php else: ?>
                                    Already Created
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <h2>Actual Visits</h2>

        <?php if (empty($actualVisits)): ?>
            <p>
                No actual visits have been created for this
                study participation yet.
            </p>
        <?php else: ?>

            <div class="table-card">
                <table>
                    <thead>
                        <tr>
                            <th>Visit</th>
                            <th>Occurrence</th>
                            <th>Status</th>
                            <th>Procedures</th>
                            <th>Expected Total</th>
                            <th>Submitted Total</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($actualVisits as $visit): ?>
                            <tr>
                                <td>
                                    <?php
                                    echo htmlspecialchars(
                                        $visit["visit_name_snapshot"]
                                    );
                                    ?>
                                </td>

                                <td>
                                    <?php
                                    echo (int) $visit["occurrence_number"];
                                    ?>
                                </td>

                                <td>
                                    <?php
                                    echo htmlspecialchars(
                                        ucwords(
                                            str_replace(
                                                "_",
                                                " ",
                                                $visit["status"]
                                            )
                                        )
                                    );
                                    ?>
                                </td>

                                <td>
                                    <?php
                                    echo (int) $visit["procedure_count"];
                                    ?>
                                </td>

                                <td>
                                    $<?php
                                    echo number_format(
                                        (float) $visit["expected_total_snapshot"],
                                        2
                                    );
                                    ?>
                                </td>

                                <td>
                                    <?php if ($visit["submitted_total"] === null): ?>
                                        —
                                    <?php else: ?>
                                        $<?php
                                        echo number_format(
                                            (float) $visit["submitted_total"],
                                            2
                                        );
                                        ?>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <a
                                        href="<?php echo BASE_URL; ?>/Studies/subject_visit.php?id=<?php echo (int) $visit["id"]; ?>"
                                        class="btn btn-primary"
                                    >
                                        Open Visit
                                    </a>
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