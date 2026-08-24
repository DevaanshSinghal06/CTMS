<?php
require_once __DIR__ . '/../../App/Config/bootstrap.php';

require_any_role(["admin", "coordinator"]);

$isAdmin = $_SESSION["role"] === "admin";
$currentUserId = (int) ($_SESSION["user_id"] ?? 0);

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

// ---------------------------------------------------------
// Load study
// ---------------------------------------------------------

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
        header("Location: " . BASE_URL . "/Studies/studies.php?access_denied=1");
        exit;
    }
}

// ---------------------------------------------------------
// Helpers
// ---------------------------------------------------------

function normalize_budget_text($value): string
{
    $value = (string) $value;

    // Remove UTF-8 BOM if present.
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);

    return trim($value);
}

function normalize_budget_amount($value): ?float
{
    $value = trim((string) $value);

    if (
        $value === "" ||
        $value === "-" ||
        $value === "—" ||
        strtoupper($value) === "N/A"
    ) {
        return null;
    }

    $isNegative = false;

    if (
        strlen($value) >= 2 &&
        $value[0] === "(" &&
        substr($value, -1) === ")"
    ) {
        $isNegative = true;
        $value = substr($value, 1, -1);
    }

    $value = str_replace(
        ["$", ",", " "],
        "",
        $value
    );

    if (!is_numeric($value)) {
        return null;
    }

    $amount = (float) $value;

    return $isNegative ? -$amount : $amount;
}

function detect_target_day(string $visitName): ?int
{
    $normalized = strtolower(trim($visitName));

    if ($normalized === "baseline visit" || $normalized === "baseline") {
        return 0;
    }

    if (preg_match('/^week\s+(\d+)/i', $visitName, $matches)) {
        return ((int) $matches[1]) * 7;
    }

    return null;
}

// ---------------------------------------------------------
// Check whether the study already has a visit template
// ---------------------------------------------------------

$stmt = $pdo->prepare("
    SELECT COUNT(*) AS total
    FROM study_visit_templates
    WHERE study_id = ?
");
$stmt->execute([$studyId]);

$templateCountRow = $stmt->fetch();
$templateAlreadyExists = (int) ($templateCountRow["total"] ?? 0) > 0;

// ---------------------------------------------------------
// Page state
// ---------------------------------------------------------

$error = "";
$parseError = "";

$parsedRows = [];
$uploadedFileName = "";

$headerRowIndex = null;
$subtotalColumnIndex = null;

$detectedVisits = [];
$detectedProcedures = [];

// Restore an existing normalized draft from the session,
// if one exists for this study.
$draft = $_SESSION["budget_import_drafts"][$studyId] ?? null;

if (is_array($draft)) {
    $uploadedFileName = $draft["file_name"] ?? "";
    $detectedVisits = $draft["visits"] ?? [];
    $detectedProcedures = $draft["procedures"] ?? [];
}

// ---------------------------------------------------------
// POST handling
// ---------------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verify_csrf()) {
        http_response_code(400);
        exit("Invalid or expired request token.");
    }

    $action = $_POST["action"] ?? "parse";

    // =====================================================
    // ACTION: APPROVE AND CREATE TEMPLATE
    // =====================================================

    if ($action === "create_template") {

        $draft = $_SESSION["budget_import_drafts"][$studyId] ?? null;

        if (!is_array($draft)) {
            $error = "No parsed budget draft is available. Upload and parse the budget again.";
        } elseif ($templateAlreadyExists) {
            $error = "This study already has a visit template. Import was not performed.";
        } else {
            $draftVisits = $draft["visits"] ?? [];
            $draftProcedures = $draft["procedures"] ?? [];

            if (empty($draftVisits) || empty($draftProcedures)) {
                $error = "The parsed budget draft is incomplete.";
            } else {
                try {
                    $pdo->beginTransaction();

                    // -----------------------------------------
                    // Create default arm
                    // -----------------------------------------

                    $stmt = $pdo->prepare("
                        INSERT INTO study_arms
                        (study_id, arm_name, arm_order)
                        VALUES (?, ?, ?)
                    ");

                    $stmt->execute([
                        $studyId,
                        "Main Treatment Arm",
                        1
                    ]);

                    $armId = (int) $pdo->lastInsertId();

                    // -----------------------------------------
                    // Create visit templates
                    // -----------------------------------------

                    $visitIdsByColumn = [];

                    $visitInsert = $pdo->prepare("
                        INSERT INTO study_visit_templates
                        (
                            study_id,
                            arm_id,
                            visit_name,
                            visit_order,
                            target_day,
                            window_before_days,
                            window_after_days
                        )
                        VALUES (?, ?, ?, ?, ?, NULL, NULL)
                    ");

                    foreach ($draftVisits as $visitOrderIndex => $visit) {
                        $visitName = $visit["visit_name"] ?? "";
                        $columnIndex = (int) ($visit["column_index"] ?? 0);

                        $targetDay = detect_target_day($visitName);

                        $visitInsert->execute([
                            $studyId,
                            $armId,
                            $visitName,
                            $visitOrderIndex + 1,
                            $targetDay
                        ]);

                        $visitIdsByColumn[$columnIndex] =
                            (int) $pdo->lastInsertId();
                    }

                    // -----------------------------------------
                    // Create reusable procedures
                    // -----------------------------------------

                    $procedureInsert = $pdo->prepare("
                        INSERT INTO study_procedures
                        (
                            study_id,
                            procedure_name
                        )
                        VALUES (?, ?)
                    ");

                    $visitProcedureInsert = $pdo->prepare("
                        INSERT INTO study_visit_procedures
                        (
                            visit_template_id,
                            procedure_id,
                            budgeted_amount,
                            required
                        )
                        VALUES (?, ?, ?, ?)
                    ");

                    $relationshipCount = 0;

                    foreach ($draftProcedures as $procedure) {
                        $procedureName =
                            $procedure["procedure_name"] ?? "";

                        if ($procedureName === "") {
                            continue;
                        }

                        $procedureInsert->execute([
                            $studyId,
                            $procedureName
                        ]);

                        $procedureId =
                            (int) $pdo->lastInsertId();

                        $amounts = $procedure["amounts"] ?? [];

                        foreach ($amounts as $columnIndex => $amount) {
                            if ($amount === null) {
                                continue;
                            }

                            $visitTemplateId =
                                $visitIdsByColumn[(int) $columnIndex]
                                ?? null;

                            if (!$visitTemplateId) {
                                continue;
                            }

                            $visitProcedureInsert->execute([
                                $visitTemplateId,
                                $procedureId,
                                $amount,
                                1
                            ]);

                            $relationshipCount++;
                        }
                    }

                    $pdo->commit();

                    $studyCodeForLog =
                        $study["study_code"] ?? "No Code";

                    log_action(
                        "imported",
                        "study",
                        $studyId,
                        "Imported visit template for "
                        . $studyCodeForLog
                        . ": "
                        . count($draftVisits)
                        . " visits, "
                        . count($draftProcedures)
                        . " procedures, "
                        . $relationshipCount
                        . " visit/procedure assignments"
                    );

                    unset(
                        $_SESSION["budget_import_drafts"][$studyId]
                    );

                    header(
                        "Location: "
                        . BASE_URL
                        . "/Studies/study_visit_template.php?id="
                        . $studyId
                        . "&imported=1"
                    );
                    exit;

                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $error =
                        "The visit template could not be created. "
                        . "No template records were saved.";
                }
            }
        }

    // =====================================================
    // ACTION: UPLOAD AND PARSE
    // =====================================================

    } else {

        // Starting a fresh parse replaces any previous draft.
        unset($_SESSION["budget_import_drafts"][$studyId]);

        $detectedVisits = [];
        $detectedProcedures = [];
        $parsedRows = [];
        $uploadedFileName = "";

        if (!isset($_FILES["budget_file"])) {
            $error = "Please choose a CSV file.";
        } elseif ($_FILES["budget_file"]["error"] !== UPLOAD_ERR_OK) {
            $error = "The file could not be uploaded.";
        } else {
            $uploadedFileName =
                $_FILES["budget_file"]["name"] ?? "";

            $temporaryPath =
                $_FILES["budget_file"]["tmp_name"] ?? "";

            $fileSize =
                (int) ($_FILES["budget_file"]["size"] ?? 0);

            $extension = strtolower(
                pathinfo(
                    $uploadedFileName,
                    PATHINFO_EXTENSION
                )
            );

            if ($extension !== "csv") {
                $error =
                    "Only CSV files are supported in this importer version.";
            } elseif ($fileSize > 5 * 1024 * 1024) {
                $error =
                    "The CSV file must be 5 MB or smaller.";
            } elseif (!is_uploaded_file($temporaryPath)) {
                $error = "Invalid uploaded file.";
            } else {
                $handle = fopen($temporaryPath, "r");

                if ($handle === false) {
                    $error =
                        "The CSV file could not be opened.";
                } else {
                    while (($row = fgetcsv($handle)) !== false) {
                        $parsedRows[] = $row;
                    }

                    fclose($handle);

                    if (empty($parsedRows)) {
                        $error =
                            "The CSV file did not contain any readable rows.";
                    }
                }
            }
        }

        // -------------------------------------------------
        // Detect budget structure
        // -------------------------------------------------

        if ($error === "" && !empty($parsedRows)) {

            foreach ($parsedRows as $rowIndex => $row) {
                $firstCell = normalize_budget_text(
                    $row[0] ?? ""
                );

                if (
                    strcasecmp(
                        $firstCell,
                        "Site Procedures/Fees"
                    ) === 0
                ) {
                    $headerRowIndex = $rowIndex;
                    break;
                }
            }

            if ($headerRowIndex === null) {
                $parseError =
                    'Could not find the "Site Procedures/Fees" header row.';
            } else {
                $headerRow =
                    $parsedRows[$headerRowIndex];

                foreach (
                    $headerRow as $columnIndex => $cell
                ) {
                    $headerValue =
                        normalize_budget_text($cell);

                    if (
                        strcasecmp(
                            $headerValue,
                            "SUBTOTAL"
                        ) === 0
                    ) {
                        $subtotalColumnIndex =
                            $columnIndex;
                        break;
                    }
                }

                if ($subtotalColumnIndex === null) {
                    $parseError =
                        'Could not find the "SUBTOTAL" column.';
                } else {

                    // -------------------------------------
                    // Detect visits
                    // -------------------------------------

                    for (
                        $columnIndex = 1;
                        $columnIndex < $subtotalColumnIndex;
                        $columnIndex++
                    ) {
                        $visitName =
                            normalize_budget_text(
                                $headerRow[$columnIndex]
                                ?? ""
                            );

                        if ($visitName === "") {
                            continue;
                        }

                        $detectedVisits[] = [
                            "column_index" =>
                                $columnIndex,
                            "visit_name" =>
                                $visitName
                        ];
                    }

                    // -------------------------------------
                    // Detect procedures
                    // -------------------------------------

                    for (
                        $rowIndex =
                            $headerRowIndex + 1;
                        $rowIndex <
                            count($parsedRows);
                        $rowIndex++
                    ) {
                        $row =
                            $parsedRows[$rowIndex];

                        $procedureName =
                            normalize_budget_text(
                                $row[0] ?? ""
                            );

                        if ($procedureName === "") {
                            continue;
                        }

                        if (
                            strcasecmp(
                                $procedureName,
                                "SUBTOTAL"
                            ) === 0
                        ) {
                            break;
                        }

                        $amounts = [];
                        $calculatedTotal = 0.00;
                        $hasVisitAmount = false;

                        foreach (
                            $detectedVisits as $visit
                        ) {
                            $columnIndex =
                                $visit["column_index"];

                            $amount =
                                normalize_budget_amount(
                                    $row[$columnIndex]
                                    ?? ""
                                );

                            $amounts[$columnIndex] =
                                $amount;

                            if ($amount !== null) {
                                $hasVisitAmount = true;
                                $calculatedTotal +=
                                    $amount;
                            }
                        }

                        if ($hasVisitAmount) {
                            $detectedProcedures[] = [
                                "row_number" =>
                                    $rowIndex + 1,
                                "procedure_name" =>
                                    $procedureName,
                                "amounts" =>
                                    $amounts,
                                "calculated_total" =>
                                    $calculatedTotal
                            ];
                        }
                    }

                    // -------------------------------------
                    // Store normalized draft in session
                    // -------------------------------------

                    if ($parseError === "") {
                        $_SESSION[
                            "budget_import_drafts"
                        ][$studyId] = [
                            "file_name" =>
                                $uploadedFileName,
                            "visits" =>
                                $detectedVisits,
                            "procedures" =>
                                $detectedProcedures
                        ];
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Import Study Budget | CTMS</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/Assets/CSS/style.css">
</head>
<body>

<header class="site-header">
    <div class="top-bar">
        <div>Clinical Trial Management System</div>
        <div><?php echo htmlspecialchars($portalLabel); ?></div>
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
        <h1>Import Study Budget</h1>

        <p>
            <?php echo htmlspecialchars($study["study_code"] ?? ""); ?>
            —
            <?php echo htmlspecialchars($study["study_name"] ?? ""); ?>
        </p>
    </section>

    <?php if ($error !== ""): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($parseError !== ""): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($parseError); ?>
        </div>
    <?php endif; ?>

    <?php if ($templateAlreadyExists): ?>
        <div class="alert alert-danger">
            This study already has a visit template.
            A new budget cannot be imported over it in this version.
        </div>
    <?php endif; ?>

    <section class="card" style="margin-bottom: 28px;">
        <h2>Upload CSV Budget</h2>

        <p>
            Upload a CSV budget to detect visits, procedures,
            and procedure amounts.
        </p>

        <form
            method="POST"
            enctype="multipart/form-data"
            action="<?php echo BASE_URL; ?>/Studies/study_budget_import.php?id=<?php echo $studyId; ?>"
        >
            <?php echo csrf_field(); ?>

            <input
                type="hidden"
                name="action"
                value="parse"
            >

            <div class="form-group">
                <label for="budget_file">
                    Budget CSV File
                </label>

                <input
                    type="file"
                    id="budget_file"
                    name="budget_file"
                    accept=".csv,text/csv"
                    required
                    <?php echo $templateAlreadyExists ? "disabled" : ""; ?>
                >
            </div>

            <button
                type="submit"
                class="btn btn-primary"
                <?php echo $templateAlreadyExists ? "disabled" : ""; ?>
            >
                Upload & Parse
            </button>

            <a
                href="<?php echo BASE_URL; ?>/Studies/study_visit_template.php?id=<?php echo $studyId; ?>"
                class="btn btn-secondary"
            >
                Back to Visit Template
            </a>
        </form>
    </section>

    <?php if (
        !$templateAlreadyExists &&
        !empty($detectedVisits) &&
        !empty($detectedProcedures)
    ): ?>

        <section class="card" style="margin-bottom: 28px;">
            <h2>Detected Template Draft</h2>

            <p>
                File:
                <strong>
                    <?php echo htmlspecialchars($uploadedFileName); ?>
                </strong>
            </p>

            <div class="card-grid" style="margin-top: 20px;">
                <div class="card">
                    <h3>Visits Detected</h3>

                    <div class="stat-number">
                        <?php echo count($detectedVisits); ?>
                    </div>
                </div>

                <div class="card">
                    <h3>Procedures Detected</h3>

                    <div class="stat-number">
                        <?php echo count($detectedProcedures); ?>
                    </div>
                </div>
            </div>

            <h3 style="margin-top: 28px;">
                Detected Visits
            </h3>

            <ul>
                <?php foreach ($detectedVisits as $visit): ?>
                    <li>
                        <?php
                        echo htmlspecialchars(
                            $visit["visit_name"]
                        );
                        ?>

                        <?php
                        $targetDay =
                            detect_target_day(
                                $visit["visit_name"]
                            );
                        ?>

                        <?php if ($targetDay !== null): ?>
                            — Target Day
                            <?php echo $targetDay; ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <h3 style="margin-top: 28px;">
                Procedure / Visit Matrix
            </h3>

            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Procedure</th>

                            <?php foreach ($detectedVisits as $visit): ?>
                                <th>
                                    <?php
                                    echo htmlspecialchars(
                                        $visit["visit_name"]
                                    );
                                    ?>
                                </th>
                            <?php endforeach; ?>

                            <th>Calculated Total</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($detectedProcedures as $procedure): ?>
                            <tr>
                                <td>
                                    <strong>
                                        <?php
                                        echo htmlspecialchars(
                                            $procedure[
                                                "procedure_name"
                                            ]
                                        );
                                        ?>
                                    </strong>
                                </td>

                                <?php foreach ($detectedVisits as $visit): ?>
                                    <?php
                                    $columnIndex =
                                        $visit["column_index"];

                                    $amount =
                                        $procedure["amounts"][
                                            $columnIndex
                                        ] ?? null;
                                    ?>

                                    <td>
                                        <?php if ($amount === null): ?>
                                            —
                                        <?php else: ?>
                                            $<?php
                                            echo number_format(
                                                (float) $amount,
                                                2
                                            );
                                            ?>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>

                                <td>
                                    <strong>
                                        $<?php
                                        echo number_format(
                                            (float) $procedure[
                                                "calculated_total"
                                            ],
                                            2
                                        );
                                        ?>
                                    </strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 28px;">
                <form
                    method="POST"
                    action="<?php echo BASE_URL; ?>/Studies/study_budget_import.php?id=<?php echo $studyId; ?>"
                >
                    <?php echo csrf_field(); ?>

                    <input
                        type="hidden"
                        name="action"
                        value="create_template"
                    >

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        Approve & Create Template
                    </button>
                </form>

                <p style="margin-top: 12px; color: var(--text-muted);">
                    This will create one Main Treatment Arm,
                    <?php echo count($detectedVisits); ?> visits,
                    <?php echo count($detectedProcedures); ?> procedures,
                    and their budgeted visit assignments.
                </p>
            </div>
        </section>

    <?php endif; ?>

    <?php if (!empty($parsedRows)): ?>

        <section class="card">
            <h2>Raw CSV Preview</h2>

            <p>
                Rows detected:
                <strong>
                    <?php echo count($parsedRows); ?>
                </strong>
            </p>

            <div style="overflow-x: auto;">
                <table>
                    <tbody>
                        <?php foreach ($parsedRows as $rowIndex => $row): ?>
                            <tr>
                                <th>
                                    Row <?php echo $rowIndex + 1; ?>
                                </th>

                                <?php foreach ($row as $cell): ?>
                                    <td>
                                        <?php
                                        $displayValue =
                                            normalize_budget_text(
                                                $cell
                                            );

                                        echo $displayValue === ""
                                            ? "—"
                                            : htmlspecialchars(
                                                $displayValue
                                            );
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

    <?php endif; ?>

</main>

</body>
</html>