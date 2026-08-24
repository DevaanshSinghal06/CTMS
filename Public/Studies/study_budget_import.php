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
        $currentUserId
    ]);

    $assignment = $stmt->fetch();

    if (!$assignment) {
        header("Location: " . BASE_URL . "/Studies/studies.php?access_denied=1");
        exit;
    }
}

$error = "";
$parsedRows = [];
$uploadedFileName = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verify_csrf()) {
        http_response_code(400);
        exit("Invalid or expired request token.");
    }

    if (!isset($_FILES["budget_file"])) {
        $error = "Please choose a CSV file.";
    } elseif ($_FILES["budget_file"]["error"] !== UPLOAD_ERR_OK) {
        $error = "The file could not be uploaded.";
    } else {
        $uploadedFileName = $_FILES["budget_file"]["name"] ?? "";
        $temporaryPath = $_FILES["budget_file"]["tmp_name"] ?? "";

        $extension = strtolower(pathinfo($uploadedFileName, PATHINFO_EXTENSION));

        if ($extension !== "csv") {
            $error = "Only CSV files are supported in this first importer version.";
        } elseif (!is_uploaded_file($temporaryPath)) {
            $error = "Invalid uploaded file.";
        } else {
            $handle = fopen($temporaryPath, "r");

            if ($handle === false) {
                $error = "The CSV file could not be opened.";
            } else {
                while (($row = fgetcsv($handle)) !== false) {
                    $parsedRows[] = $row;
                }

                fclose($handle);

                if (empty($parsedRows)) {
                    $error = "The CSV file did not contain any readable rows.";
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

    <section class="card" style="margin-bottom: 28px;">
        <h2>Upload CSV Budget</h2>

        <p>
            This first importer version only previews the CSV.
            It will not create or modify visit-template records.
        </p>

        <form
            method="POST"
            enctype="multipart/form-data"
            action="<?php echo BASE_URL; ?>/Studies/study_budget_import.php?id=<?php echo $studyId; ?>"
        >
            <?php echo csrf_field(); ?>

            <div class="form-group">
                <label for="budget_file">Budget CSV File</label>

                <input
                    type="file"
                    id="budget_file"
                    name="budget_file"
                    accept=".csv,text/csv"
                    required
                >
            </div>

            <button type="submit" class="btn btn-primary">
                Upload & Preview
            </button>

            <a
                href="<?php echo BASE_URL; ?>/Studies/study_visit_template.php?id=<?php echo $studyId; ?>"
                class="btn btn-secondary"
            >
                Back to Visit Template
            </a>
        </form>
    </section>

    <?php if (!empty($parsedRows)): ?>

        <section class="card">
            <h2>Raw CSV Preview</h2>

            <p>
                File:
                <strong><?php echo htmlspecialchars($uploadedFileName); ?></strong>
            </p>

            <p>
                Rows detected:
                <strong><?php echo count($parsedRows); ?></strong>
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
                                        $displayValue = trim((string) $cell);

                                        echo $displayValue === ""
                                            ? "—"
                                            : htmlspecialchars($displayValue);
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