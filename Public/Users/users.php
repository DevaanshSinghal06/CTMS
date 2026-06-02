<?php
require_once __DIR__ . '/../../App/Config/bootstrap.php';

require_role("admin");

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verify_csrf()) {
        http_response_code(400);
        exit("Invalid or expired request token.");
    }

    $firstName = trim($_POST["first_name"] ?? "");
    $lastName = trim($_POST["last_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $role = $_POST["role"] ?? "";
    $password = $_POST["password"] ?? "";
    $active = isset($_POST["active"]) ? 1 : 0;

    $allowedRoles = ["admin", "coordinator"];

    if ($firstName === "" || $lastName === "" || $email === "" || $password === "" || $role === "") {
        $error = "Please fill out all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (!in_array($role, $allowedRoles, true)) {
        $error = "Invalid user role.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $existingUser = $stmt->fetch();

        if ($existingUser) {
            $error = "A user with this email already exists.";
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                INSERT INTO users
                (
                    first_name,
                    last_name,
                    email,
                    password_hash,
                    role,
                    active
                )
                VALUES
                (?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $firstName,
                $lastName,
                $email,
                $passwordHash,
                $role,
                $active
            ]);

            $newUserId = (int) $pdo->lastInsertId();

            log_action(
                "created",
                "user",
                $newUserId,
                "Created user: " . $firstName . " " . $lastName . " (" . $role . ")"
            );

            header("Location: " . BASE_URL . "/Users/users.php?created=1");
            exit;
        }
    }
}

if (isset($_GET["created"])) {
    $success = "User created successfully.";
}

if (isset($_GET["activated"])) {
    $success = "User activated successfully.";
}

if (isset($_GET["deactivated"])) {
    $success = "User deactivated successfully.";
}

if (isset($_GET["self_deactivate_denied"])) {
    $error = "You cannot deactivate your own account while logged in.";
}

$stmt = $pdo->query("
    SELECT
        id,
        first_name,
        last_name,
        email,
        role,
        active,
        created_at
    FROM users
    ORDER BY role ASC, last_name ASC, first_name ASC
");

$users = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Management | CTMS</title>
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
            <a href="<?php echo BASE_URL; ?>/Users/users.php">Users</a>
            <a href="<?php echo BASE_URL; ?>/Audit/audit_logs.php">Audit Log</a>
            <a href="<?php echo BASE_URL; ?>/Auth/logout.php">Logout</a>
        </div>
    </nav>
</header>

<main class="page-wrapper">
    <section class="page-title">
        <h1>User Management</h1>
        <p>View and add CTMS admins and research coordinators.</p>
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

    <section class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($users) === 0): ?>
                    <tr>
                        <td colspan="6">No users found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td>
                                <?php 
                                    echo htmlspecialchars(
                                        ($user["first_name"] ?? "") . " " . ($user["last_name"] ?? "")
                                    ); 
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars($user["email"]); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst($user["role"])); ?></td>
                            <td><?php echo (int)$user["active"] === 1 ? "Active" : "Inactive"; ?></td>
                            <td>
                                <?php
                                    echo $user["created_at"]
                                        ? htmlspecialchars(date("m/d/Y", strtotime($user["created_at"])))
                                        : "";
                                ?>
                            </td>

                            <td>
                                <div class="action-buttons">
                                    <?php if ((int)$user["id"] !== (int)($_SESSION["user_id"] ?? 0)): ?>
                                        <?php if ((int)$user["active"] === 1): ?>
                                            <form 
                                                method="POST" 
                                                action="<?php echo BASE_URL; ?>/Users/user_toggle_active.php"
                                                onsubmit="return confirm('Deactivate this user? They will no longer be able to log in.');"
                                            >
                                                <?php echo csrf_field(); ?>
                                                <input 
                                                    type="hidden" 
                                                    name="id" 
                                                    value="<?php echo htmlspecialchars($user["id"]); ?>"
                                                >

                                                <input 
                                                    type="hidden" 
                                                    name="action" 
                                                    value="deactivate"
                                                >

                                                <button type="submit" class="btn btn-danger btn-small">
                                                    Deactivate
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form 
                                                method="POST" 
                                                action="<?php echo BASE_URL; ?>/Users/user_toggle_active.php"
                                                onsubmit="return confirm('Reactivate this user? They will be able to log in again.');"
                                            >
                                                <?php echo csrf_field(); ?>
                                                <input 
                                                    type="hidden" 
                                                    name="id" 
                                                    value="<?php echo htmlspecialchars($user["id"]); ?>"
                                                >

                                                <input 
                                                    type="hidden" 
                                                    name="action" 
                                                    value="activate"
                                                >

                                                <button type="submit" class="btn btn-primary btn-small">
                                                    Reactivate
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">Current User</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="card" style="margin-top: 28px;">
        <h3>Add New User</h3>

        <form method="POST" action="<?php echo BASE_URL; ?>/Users/users.php">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label for="first_name">First Name</label>
                <input 
                    type="text" 
                    id="first_name" 
                    name="first_name" 
                    required
                >
            </div>

            <div class="form-group">
                <label for="last_name">Last Name</label>
                <input 
                    type="text" 
                    id="last_name" 
                    name="last_name" 
                    required
                >
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input 
                    type="email" 
                    id="email" 
                    name="email" 
                    required
                >
            </div>

            <div class="form-group">
                <label for="role">Role</label>
                <select id="role" name="role" required>
                    <option value="">Select Role</option>
                    <option value="admin">Admin</option>
                    <option value="coordinator">Coordinator</option>
                </select>
            </div>

            <div class="form-group">
                <label for="password">Temporary Password</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    required
                >
            </div>

            <div class="form-group">
                <label>
                    <input 
                        type="checkbox" 
                        name="active" 
                        checked
                        style="width: auto;"
                    >
                    Active user
                </label>
            </div>

            <button type="submit" class="btn btn-primary">
                Create User
            </button>
        </form>
    </section>
</main>

</body>
</html>