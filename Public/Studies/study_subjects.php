<?php
require_once __DIR__ . '/../../App/Config/bootstrap.php';

require_any_role(["admin", "coordinator"]);

$isAdmin = $_SESSION["role"] === "admin";
$currentUserId = (int) ($_SESSION["user_id"] ?? 0);

$dashboardLink = $isAdmin
    ? BASE_URL . "/Dashboards/admin_dashboard.php"
    : BASE_URL . "/Dashboards/coordinator_dashboard.php";

$portalLabel = $isAdmin ? "Admin Portal" : "Research Coordinator Portal";

$error = "";
$success = "";

$studyId = $_GET["id"] ?? null;

if (!$studyId || !is_numeric($studyId)) {
    header("Location: " . BASE_URL . "/Studies/studies.php");
    exit;
}

$studyId = (int) $studyId;

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

if (!$isAdmin) {
    $stmt = $pdo->prepare("
        SELECT id
        FROM study_assignments
        WHERE study_id = ?
            AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$studyId, $currentUserId]);
    $assignment = $stmt->fetch();

    if (!$assignment) {
        header("Location: " . BASE_URL . "/Studies/studies.php?access_denied=1");
        exit;
    }
}

$statusLabels = [
    "screening" => "Screening",
    "randomization" => "Randomization",
    "screen_failed" => "Screen Failed",
    "enrolled" => "Enrolled",
    "completed" => "Completed",
    "withdrawn" => "Withdrawn"
];

$allowedStatuses = array_keys($statusLabels);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verify_csrf()) {
        http_response_code(400);
        exit("Invalid or expired request token.");
    }

    $action = $_POST["action"] ?? "";

    if ($action === "link_existing") {
        $subjectId = $_POST["subject_id"] ?? null;
        $referralSource = trim($_POST["referral_source"] ?? "");
        $screeningStatus = $_POST["screening_status"] ?? "screening";
        $notes = trim($_POST["notes"] ?? "");

        if (!$subjectId || !is_numeric($subjectId)) {
            $error = "Please select a subject.";
        } elseif (!in_array($screeningStatus, $allowedStatuses, true)) {
            $error = "Invalid subject status.";
        } else {
            $subjectId = (int) $subjectId;

            $stmt = $pdo->prepare("
                SELECT id, initials
                FROM subjects
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$subjectId]);
            $subject = $stmt->fetch();

            if (!$subject) {
                $error = "Subject not found.";
            } else {
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM study_subjects
                    WHERE study_id = ?
                        AND subject_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$studyId, $subjectId]);
                $existingLink = $stmt->fetch();

                if ($existingLink) {
                    $error = "This subject is already linked to this study.";
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO study_subjects
                        (
                            study_id,
                            subject_id,
                            referral_source,
                            screening_status,
                            notes,
                            created_by
                        )
                        VALUES
                        (?, ?, ?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $studyId,
                        $subjectId,
                        $referralSource,
                        $screeningStatus,
                        $notes,
                        $currentUserId
                    ]);

                    log_action(
                        "linked",
                        "subject",
                        $subjectId,
                        "Linked subject " . ($subject["initials"] ?? "") . " to study " . ($study["study_code"] ?? "")
                    );

                    header("Location: " . BASE_URL . "/Studies/study_subjects.php?id=" . $studyId . "&linked=1");
                    exit;
                }
            }
        }
    }

    if ($action === "create_and_link") {
        $initials = strtoupper(trim($_POST["initials"] ?? ""));
        $dateOfBirth = $_POST["date_of_birth"] ?: null;
        $phoneNumber = trim($_POST["phone_number"] ?? "");
        $subjectNotes = trim($_POST["subject_notes"] ?? "");

        $referralSource = trim($_POST["referral_source"] ?? "");
        $screeningStatus = $_POST["screening_status"] ?? "screening";
        $studyNotes = trim($_POST["study_notes"] ?? "");

        if ($initials === "") {
            $error = "Subject initials are required.";
        } elseif (!$dateOfBirth) {
            $error = "Date of birth is required.";
        } elseif (!in_array($screeningStatus, $allowedStatuses, true)) {
            $error = "Invalid subject status.";
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO subjects
                    (
                        initials,
                        date_of_birth,
                        phone_number,
                        notes,
                        created_by
                    )
                    VALUES
                    (?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $initials,
                    $dateOfBirth,
                    $phoneNumber,
                    $subjectNotes,
                    $currentUserId
                ]);

                $newSubjectId = (int) $pdo->lastInsertId();

                $stmt = $pdo->prepare("
                    INSERT INTO study_subjects
                    (
                        study_id,
                        subject_id,
                        referral_source,
                        screening_status,
                        notes,
                        created_by
                    )
                    VALUES
                    (?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $studyId,
                    $newSubjectId,
                    $referralSource,
                    $screeningStatus,
                    $studyNotes,
                    $currentUserId
                ]);

                $pdo->commit();

                log_action(
                    "created",
                    "subject",
                    $newSubjectId,
                    "Created and linked subject " . $initials . " to study " . ($study["study_code"] ?? "")
                );

                header("Location: " . BASE_URL . "/Studies/study_subjects.php?id=" . $studyId . "&created=1");
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "Unable to create and link subject.";
            }
        }
    }

    if ($action === "update_study_subject") {
        $studySubjectId = $_POST["study_subject_id"] ?? null;
        $referralSource = trim($_POST["referral_source"] ?? "");
        $screeningStatus = $_POST["screening_status"] ?? "";
        $notes = trim($_POST["notes"] ?? "");

        if (!$studySubjectId || !is_numeric($studySubjectId)) {
            $error = "Invalid study subject record.";
        } elseif (!in_array($screeningStatus, $allowedStatuses, true)) {
            $error = "Invalid subject status.";
        } else {
            $studySubjectId = (int) $studySubjectId;

            $stmt = $pdo->prepare("
                SELECT
                    study_subjects.id,
                    study_subjects.subject_id,
                    study_subjects.screening_status,
                    subjects.initials
                FROM study_subjects
                INNER JOIN subjects
                    ON study_subjects.subject_id = subjects.id
                WHERE study_subjects.id = ?
                    AND study_subjects.study_id = ?
                LIMIT 1
            ");
            $stmt->execute([$studySubjectId, $studyId]);
            $studySubject = $stmt->fetch();

            if (!$studySubject) {
                $error = "Study subject record not found.";
            } else {
                $oldStatus = $studySubject["screening_status"];

                $stmt = $pdo->prepare("
                    UPDATE study_subjects
                    SET
                        referral_source = ?,
                        screening_status = ?,
                        notes = ?
                    WHERE id = ?
                        AND study_id = ?
                ");

                $stmt->execute([
                    $referralSource,
                    $screeningStatus,
                    $notes,
                    $studySubjectId,
                    $studyId
                ]);

                log_action(
                    "updated",
                    "subject",
                    (int) $studySubject["subject_id"],
                    "Updated subject " . ($studySubject["initials"] ?? "") . " status for study " . ($study["study_code"] ?? "") . " from " . ($statusLabels[$oldStatus] ?? $oldStatus) . " to " . ($statusLabels[$screeningStatus] ?? $screeningStatus)
                );

                header("Location: " . BASE_URL . "/Studies/study_subjects.php?id=" . $studyId . "&updated=1");
                exit;
            }
        }
    }
}

if (isset($_GET["linked"])) {
    $success = "Subject linked to study successfully.";
}

if (isset($_GET["created"])) {
    $success = "Subject created and linked to study successfully.";
}

if (isset($_GET["updated"])) {
    $success = "Study subject status updated successfully.";
}

$stmt = $pdo->prepare("
    SELECT
        study_subjects.id AS study_subject_id,
        study_subjects.referral_source,
        study_subjects.screening_status,
        study_subjects.notes AS study_notes,
        study_subjects.created_at AS linked_at,

        subjects.id AS subject_id,
        subjects.initials,
        subjects.date_of_birth,
        subjects.phone_number,
        subjects.notes AS subject_notes
    FROM study_subjects
    INNER JOIN subjects
        ON study_subjects.subject_id = subjects.id
    WHERE study_subjects.study_id = ?
    ORDER BY study_subjects.created_at DESC
");
$stmt->execute([$studyId]);
$studySubjects = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT
        subjects.id,
        subjects.initials,
        subjects.date_of_birth,
        subjects.phone_number
    FROM subjects
    WHERE subjects.id NOT IN (
        SELECT subject_id
        FROM study_subjects
        WHERE study_id = ?
    )
    ORDER BY subjects.initials ASC, subjects.date_of_birth ASC
");
$stmt->execute([$studyId]);
$availableSubjects = $stmt->fetchAll();

$statusCounts = [
    "screening" => 0,
    "randomization" => 0,
    "screen_failed" => 0,
    "enrolled" => 0,
    "completed" => 0,
    "withdrawn" => 0
];

foreach ($studySubjects as $studySubject) {
    $status = $studySubject["screening_status"];

    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
}

$totalStudySubjects = count($studySubjects);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Study Subjects | CTMS</title>
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
            <a href="<?php echo BASE_URL; ?>/Subjects/subjects.php">Subjects</a>

            <?php if ($isAdmin): ?>
                <a href="<?php echo BASE_URL; ?>/Studies/study_assignments.php">Assignments</a>
                <a href="<?php echo BASE_URL; ?>/Users/users.php">Users</a>
                <a href="<?php echo BASE_URL; ?>/Audit/audit_logs.php">Audit Log</a>
            <?php endif; ?>

            <a href="<?php echo BASE_URL; ?>/Auth/logout.php">Logout</a>
        </div>
    </nav>
</header>

<main class="page-wrapper">
    <section class="page-title">
        <h1>Subjects / Screening</h1>
        <p>
            <?php echo htmlspecialchars($study["study_code"] ?? ""); ?>
            |
            <?php echo htmlspecialchars($study["study_name"] ?? ""); ?>
        </p>
    </section>

    <?php if ($error !== ""): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <section class="card-grid">
        <div class="card">
            <h3>Total Subjects</h3>
            <div class="stat-number"><?php echo htmlspecialchars($totalStudySubjects); ?></div>
            <p>Subjects linked to this study.</p>
        </div>

        <div class="card">
            <h3>Screening</h3>
            <div class="stat-number"><?php echo htmlspecialchars($statusCounts["screening"]); ?></div>
            <p>Currently in screening.</p>
        </div>

        <div class="card">
            <h3>Enrolled</h3>
            <div class="stat-number"><?php echo htmlspecialchars($statusCounts["enrolled"]); ?></div>
            <p>Currently enrolled.</p>
        </div>

        <div class="card">
            <h3>Screen Failed</h3>
            <div class="stat-number"><?php echo htmlspecialchars($statusCounts["screen_failed"]); ?></div>
            <p>Subjects who did not pass screening.</p>
        </div>
    </section>

    <section class="card" style="margin-bottom: 28px;">
        <h3>Subjects Linked to This Study</h3>

        <table>
            <thead>
                <tr>
                    <th>Initials</th>
                    <th>DOB</th>
                    <th>Phone</th>
                    <th>Referral Source</th>
                    <th>Status</th>
                    <th>Study Notes</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($studySubjects) === 0): ?>
                    <tr>
                        <td colspan="7">No subjects have been linked to this study yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($studySubjects as $studySubject): ?>
                        <?php $formId = "study-subject-form-" . (int) $studySubject["study_subject_id"]; ?>

                        <tr>
                            <td><?php echo htmlspecialchars($studySubject["initials"]); ?></td>
                            <td><?php echo htmlspecialchars($studySubject["date_of_birth"]); ?></td>
                            <td><?php echo htmlspecialchars($studySubject["phone_number"] ?? ""); ?></td>

                            <td>
                                <input 
                                    type="text" 
                                    name="referral_source"
                                    form="<?php echo htmlspecialchars($formId); ?>"
                                    value="<?php echo htmlspecialchars($studySubject["referral_source"] ?? ""); ?>"
                                >
                            </td>

                            <td>
                                <select 
                                    name="screening_status"
                                    form="<?php echo htmlspecialchars($formId); ?>"
                                >
                                    <?php foreach ($statusLabels as $statusValue => $statusLabel): ?>
                                        <option 
                                            value="<?php echo htmlspecialchars($statusValue); ?>"
                                            <?php echo $studySubject["screening_status"] === $statusValue ? "selected" : ""; ?>
                                        >
                                            <?php echo htmlspecialchars($statusLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>

                            <td>
                                <textarea 
                                    name="notes"
                                    rows="2"
                                    form="<?php echo htmlspecialchars($formId); ?>"
                                ><?php echo htmlspecialchars($studySubject["study_notes"] ?? ""); ?></textarea>
                            </td>

                            <td>
                                <form 
                                    id="<?php echo htmlspecialchars($formId); ?>" 
                                    method="POST" 
                                    action="<?php echo BASE_URL; ?>/Studies/study_subjects.php?id=<?php echo htmlspecialchars($studyId); ?>"
                                >
                                    <?php echo csrf_field(); ?>

                                    <input type="hidden" name="action" value="update_study_subject">

                                    <input 
                                        type="hidden" 
                                        name="study_subject_id" 
                                        value="<?php echo htmlspecialchars($studySubject["study_subject_id"]); ?>"
                                    >

                                    <button type="submit" class="btn btn-primary btn-small">
                                        Save
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="card-grid">
        <section class="card">
            <h3>Link Existing Subject</h3>

            <form method="POST" action="<?php echo BASE_URL; ?>/Studies/study_subjects.php?id=<?php echo htmlspecialchars($studyId); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="link_existing">

                <div class="form-group">
                    <label for="subject_id">Subject</label>
                    <select id="subject_id" name="subject_id" required>
                        <option value="">Select Subject</option>

                        <?php foreach ($availableSubjects as $subject): ?>
                            <option value="<?php echo htmlspecialchars($subject["id"]); ?>">
                                <?php
                                    echo htmlspecialchars(
                                        $subject["initials"] .
                                        " | DOB: " .
                                        $subject["date_of_birth"] .
                                        " | Phone: " .
                                        ($subject["phone_number"] ?? "")
                                    );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="existing_referral_source">Referral Source</label>
                    <input 
                        type="text" 
                        id="existing_referral_source" 
                        name="referral_source"
                    >
                </div>

                <div class="form-group">
                    <label for="existing_screening_status">Status</label>
                    <select id="existing_screening_status" name="screening_status">
                        <?php foreach ($statusLabels as $statusValue => $statusLabel): ?>
                            <option value="<?php echo htmlspecialchars($statusValue); ?>">
                                <?php echo htmlspecialchars($statusLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="existing_notes">Study Notes</label>
                    <textarea 
                        id="existing_notes" 
                        name="notes" 
                        rows="3"
                    ></textarea>
                </div>

                <button type="submit" class="btn btn-primary">
                    Link Subject
                </button>
            </form>
        </section>

        <section class="card">
            <h3>Create New Subject and Link</h3>

            <form method="POST" action="<?php echo BASE_URL; ?>/Studies/study_subjects.php?id=<?php echo htmlspecialchars($studyId); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="create_and_link">

                <div class="form-group">
                    <label for="initials">Initials</label>
                    <input 
                        type="text" 
                        id="initials" 
                        name="initials" 
                        maxlength="20"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="date_of_birth">Date of Birth</label>
                    <input 
                        type="date" 
                        id="date_of_birth" 
                        name="date_of_birth"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="phone_number">Phone Number</label>
                    <input 
                        type="text" 
                        id="phone_number" 
                        name="phone_number"
                    >
                </div>

                <div class="form-group">
                    <label for="subject_notes">Subject Profile Notes</label>
                    <textarea 
                        id="subject_notes" 
                        name="subject_notes" 
                        rows="2"
                    ></textarea>
                </div>

                <div class="form-group">
                    <label for="new_referral_source">Referral Source</label>
                    <input 
                        type="text" 
                        id="new_referral_source" 
                        name="referral_source"
                    >
                </div>

                <div class="form-group">
                    <label for="new_screening_status">Status</label>
                    <select id="new_screening_status" name="screening_status">
                        <?php foreach ($statusLabels as $statusValue => $statusLabel): ?>
                            <option value="<?php echo htmlspecialchars($statusValue); ?>">
                                <?php echo htmlspecialchars($statusLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="study_notes">Study Notes</label>
                    <textarea 
                        id="study_notes" 
                        name="study_notes" 
                        rows="3"
                    ></textarea>
                </div>

                <button type="submit" class="btn btn-primary">
                    Create and Link Subject
                </button>
            </form>
        </section>
    </section>

    <a 
        href="<?php echo BASE_URL; ?>/Studies/study_view.php?id=<?php echo htmlspecialchars($studyId); ?>" 
        class="btn btn-secondary"
        style="margin-top: 24px;"
    >
        Back to Study
    </a>
</main>

</body>
</html>