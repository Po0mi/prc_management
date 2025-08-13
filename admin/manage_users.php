<?php


require_once __DIR__ . '/../config.php';
ensure_logged_in();
ensure_admin();

$pdo = $GLOBALS['pdo'];
$errorMessage = '';
$successMessage = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_user'])) {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $full_name = trim($_POST['full_name']);
        $role = $_POST['role'];
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);

        if ($username && $password && $full_name && in_array($role, ['admin','user'])) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO users (username, password_hash, full_name, role, email, phone)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            try {
                $stmt->execute([$username, $hash, $full_name, $role, $email, $phone]);
                $successMessage = "User '$username' created successfully!";
            } catch (Exception $e) {
                $errorMessage = "Error creating user: " . $e->getMessage();
            }
        } else {
            $errorMessage = "Username, password, full name, and valid role are required.";
        }
    }
    elseif (isset($_POST['update_user'])) {
        $user_id = (int)$_POST['user_id'];
        $full_name = trim($_POST['full_name']);
        $role = $_POST['role'];
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);

        if ($user_id && $full_name && in_array($role, ['admin','user'])) {
            $stmt = $pdo->prepare("
                UPDATE users
                SET full_name = ?, role = ?, email = ?, phone = ?
                WHERE user_id = ?
            ");
            $stmt->execute([$full_name, $role, $email, $phone, $user_id]);
            $successMessage = "User updated successfully!";
        } else {
            $errorMessage = "Invalid data for update.";
        }
    }
    elseif (isset($_POST['delete_user'])) {
        $user_id = (int)$_POST['user_id'];
        if ($user_id) {
            $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $successMessage = "User deleted successfully.";
        }
    }
}


$stmt = $pdo->query("SELECT user_id, username, full_name, role, email, phone FROM users ORDER BY username");
$users = $stmt->fetchAll();


$admin_count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$user_count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
$total_users = $admin_count + $user_count;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Users - PRC Admin</title>
    <!-- Apply saved sidebar state BEFORE CSS -->
  <?php $collapsed = isset($_COOKIE['sidebarCollapsed']) && $_COOKIE['sidebarCollapsed'] === 'true'; ?>
  <script>
    // Option 1: Set sidebar width early to prevent flicker
    (function() {
      var collapsed = document.cookie.split('; ').find(row => row.startsWith('sidebarCollapsed='));
      var root = document.documentElement;
      if (collapsed && collapsed.split('=')[1] === 'true') {
        root.style.setProperty('--sidebar-width', '70px');
      } else {
        root.style.setProperty('--sidebar-width', '250px');
      }
    })();
  </script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/sidebar_admin.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/admin_users.css?v=<?php echo time(); ?>">
</head>

<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="admin-content">
    <div class="users-container">
      <div class="page-header">
        <h1>User Management</h1>
        <p>Create, update, and manage system users</p>
      </div>

      <?php if ($errorMessage): ?>
        <div class="alert error">
          <i class="fas fa-exclamation-circle"></i>
          <?= htmlspecialchars($errorMessage) ?>
        </div>
      <?php endif; ?>
      
      <?php if ($successMessage): ?>
        <div class="alert success">
          <i class="fas fa-check-circle"></i>
          <?= htmlspecialchars($successMessage) ?>
        </div>
      <?php endif; ?>

      <div class="user-sections">
        <!-- Create User Section -->
        <section class="create-user card">
          <h2><i class="fas fa-user-plus"></i> Create New User</h2>
          <form method="POST" class="user-form">
            <input type="hidden" name="create_user" value="1">
            
            <div class="form-row">
              <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required>
              </div>
              
              <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
              </div>
            </div>
            
            <div class="form-group">
              <label for="full_name">Full Name</label>
              <input type="text" id="full_name" name="full_name" required>
            </div>
            
            <div class="form-row">
              <div class="form-group">
                <label for="role">Role</label>
                <select id="role" name="role" required>
                  <option value="user">User</option>
                  <option value="admin">Admin</option>
                </select>
              </div>
              
              <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email">
              </div>
              
              <div class="form-group">
                <label for="phone">Phone</label>
                <input type="text" id="phone" name="phone">
              </div>
            </div>
            
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-save"></i> Create User
            </button>
          </form>
        </section>

        
        <section class="existing-users">
          <div class="section-header">
            <h2><i class="fas fa-users-cog"></i> All Users</h2>
            <div class="search-box">
              <input type="text" placeholder="Search users...">
              <button type="submit"><i class="fas fa-search"></i></button>
            </div>
          </div>
          
          <?php if (empty($users)): ?>
            <div class="empty-state">
              <i class="fas fa-user-slash"></i>
              <h3>No Users Found</h3>
              <p>There are no users to display.</p>
            </div>
          <?php else: ?>
            <div class="stats-cards">
              <div class="stat-card">
                <div class="stat-icon blue">
                  <i class="fas fa-users"></i>
                </div>
                <div class="stat-content">
                  <h3>Total Users</h3>
                  <p><?= $total_users ?></p>
                </div>
              </div>
              
              <div class="stat-card">
                <div class="stat-icon green">
                  <i class="fas fa-user-shield"></i>
                </div>
                <div class="stat-content">
                  <h3>Admins</h3>
                  <p><?= $admin_count ?></p>
                </div>
              </div>
              
              <div class="stat-card">
                <div class="stat-icon purple">
                  <i class="fas fa-user"></i>
                </div>
                <div class="stat-content">
                  <h3>Regular Users</h3>
                  <p><?= $user_count ?></p>
                </div>
              </div>
            </div>
            
            <div class="table-container">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Full Name</th>
                    <th>Role</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($users as $u): ?>
                  <tr>
                    <td><?= htmlspecialchars($u['user_id']) ?></td>
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td>
                      <input type="text" name="full_name" value="<?= htmlspecialchars($u['full_name']) ?>" 
                             form="update-form-<?= $u['user_id'] ?>" required>
                    </td>
                    <td>
                      <select name="role" form="update-form-<?= $u['user_id'] ?>" class="role-select">
                        <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>User</option>
                        <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                      </select>
                    </td>
                    <td>
                      <input type="email" name="email" value="<?= htmlspecialchars($u['email']) ?>" 
                             form="update-form-<?= $u['user_id'] ?>">
                    </td>
                    <td>
                      <input type="text" name="phone" value="<?= htmlspecialchars($u['phone']) ?>" 
                             form="update-form-<?= $u['user_id'] ?>">
                    </td>
                    <td class="actions">
                      <form method="POST" id="update-form-<?= $u['user_id'] ?>" class="inline-form">
                        <input type="hidden" name="update_user" value="1">
                        <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-update">
                          <i class="fas fa-save"></i> Update
                        </button>
                      </form>
                      
                      <form method="POST" class="inline-form" 
                            onsubmit="return confirm('Are you sure you want to delete <?= htmlspecialchars($u['username']) ?>?')">
                        <input type="hidden" name="delete_user" value="1">
                        <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-delete">
                          <i class="fas fa-trash-alt"></i> Delete
                        </button>
                      </form>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      </div>
    </div>
  </div>
  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
</body>
</html>