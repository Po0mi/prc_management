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
        $password = $_POST['password'];

        if ($user_id && $full_name && in_array($role, ['admin','user'])) {
            if (!empty($password)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET full_name = ?, role = ?, email = ?, phone = ?, password_hash = ?
                    WHERE user_id = ?
                ");
                $stmt->execute([$full_name, $role, $email, $phone, $hash, $user_id]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET full_name = ?, role = ?, email = ?, phone = ?
                    WHERE user_id = ?
                ");
                $stmt->execute([$full_name, $role, $email, $phone, $user_id]);
            }
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

// Get all users
$stmt = $pdo->query("SELECT user_id, username, full_name, role, email, phone FROM users ORDER BY username");
$users = $stmt->fetchAll();

// Get statistics
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
  <?php $collapsed = isset($_COOKIE['sidebarCollapsed']) && $_COOKIE['sidebarCollapsed'] === 'true'; ?>
  <script>
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
  
  <div class="users-container">
    <div class="page-header">
      <h1><i class="fas fa-users-cog"></i> User Management</h1>
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

    <!-- Action Bar -->
    <div class="action-bar">
      <div class="action-bar-left">
        <div class="search-box">
          <i class="fas fa-search"></i>
          <input type="text" id="userSearch" placeholder="Search users...">
        </div>
      </div>
      
      <button class="btn-create" onclick="openCreateModal()">
        <i class="fas fa-user-plus"></i> Create New User
      </button>
    </div>

    <!-- Statistics Overview -->
    <div class="stats-overview">
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
          <i class="fas fa-users"></i>
        </div>
        <div>
          <div style="font-size: 1.5rem; font-weight: 700;"><?= $total_users ?></div>
          <div style="color: var(--gray); font-size: 0.9rem;">Total Users</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #00c853 0%, #64dd17 100%);">
          <i class="fas fa-user-shield"></i>
        </div>
        <div>
          <div style="font-size: 1.5rem; font-weight: 700;"><?= $admin_count ?></div>
          <div style="color: var(--gray); font-size: 0.9rem;">Administrators</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #ff6b6b 0%, #ff8e53 100%);">
          <i class="fas fa-user"></i>
        </div>
        <div>
          <div style="font-size: 1.5rem; font-weight: 700;"><?= $user_count ?></div>
          <div style="color: var(--gray); font-size: 0.9rem;">Regular Users</div>
        </div>
      </div>
    </div>

    <!-- Users Table -->
    <div class="users-table-wrapper">
      <div class="table-header">
        <h2 class="table-title">All Users</h2>
      </div>
      
      <?php if (empty($users)): ?>
        <div class="empty-state">
          <i class="fas fa-user-slash"></i>
          <h3>No users found</h3>
          <p>Click "Create New User" to get started</p>
        </div>
      <?php else: ?>
        <table class="data-table" id="usersTable">
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
                <td><?= htmlspecialchars($u['full_name']) ?></td>
                <td>
                  <span class="role-badge <?= $u['role'] === 'admin' ? 'admin' : 'user' ?>">
                    <?= ucfirst($u['role']) ?>
                  </span>
                </td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= htmlspecialchars($u['phone']) ?></td>
                <td class="actions">
                  <button class="btn-action btn-edit" onclick="openEditModal(<?= htmlspecialchars(json_encode($u)) ?>)">
                    <i class="fas fa-edit"></i> Edit
                  </button>
                  <form method="POST" style="display: inline;" onsubmit="return confirmDelete('<?= htmlspecialchars($u['username']) ?>')">
                    <input type="hidden" name="delete_user" value="1">
                    <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                    <button type="submit" class="btn-action btn-delete">
                      <i class="fas fa-trash"></i> Delete
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- Create/Edit Modal -->
  <div class="modal" id="userModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitle">Create New User</h2>
        <button class="close-modal" onclick="closeModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <form method="POST" id="userForm">
        <input type="hidden" name="create_user" value="1" id="formAction">
        <input type="hidden" name="user_id" id="userId">
        
        <div class="form-row">
          <div class="form-group">
            <label for="username">Username *</label>
            <input type="text" id="username" name="username" required placeholder="Enter username">
          </div>
          
          <div class="form-group">
            <label for="passwordField">Password *</label>
            <input type="password" id="passwordField" name="password" required placeholder="Enter password">
          </div>
        </div>
        
        <div class="form-group">
          <label for="full_name">Full Name *</label>
          <input type="text" id="full_name" name="full_name" required placeholder="Enter full name">
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label for="role">Role *</label>
            <select id="role" name="role" required>
              <option value="user">User</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          
          <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" placeholder="Enter email">
          </div>
          
          <div class="form-group">
            <label for="phone">Phone</label>
            <input type="text" id="phone" name="phone" placeholder="Enter phone number">
          </div>
        </div>
        
        <button type="submit" class="btn-submit">
          <i class="fas fa-save"></i> Save User
        </button>
      </form>
    </div>
  </div>

  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
  <script>
    function openCreateModal() {
      document.getElementById('modalTitle').textContent = 'Create New User';
      document.getElementById('formAction').name = 'create_user';
      document.getElementById('userForm').reset();
      document.getElementById('username').readOnly = false;
      document.getElementById('passwordField').required = true;
      document.getElementById('passwordField').placeholder = "Enter password";
      document.getElementById('userModal').classList.add('active');
    }
    
    function openEditModal(user) {
      document.getElementById('modalTitle').textContent = 'Edit User';
      document.getElementById('formAction').name = 'update_user';
      document.getElementById('userId').value = user.user_id;
      document.getElementById('username').value = user.username;
      document.getElementById('username').readOnly = true;
      document.getElementById('passwordField').required = false;
      document.getElementById('passwordField').value = '';
      document.getElementById('passwordField').placeholder = "Leave blank to keep current";
      document.getElementById('full_name').value = user.full_name;
      document.getElementById('role').value = user.role;
      document.getElementById('email').value = user.email || '';
      document.getElementById('phone').value = user.phone || '';
      document.getElementById('userModal').classList.add('active');
    }
    
    function closeModal() {
      document.getElementById('userModal').classList.remove('active');
    }
    
    function confirmDelete(username) {
      return confirm(`Are you sure you want to delete the user "${username}"?\n\nThis action cannot be undone.`);
    }
    
    // Close modal when clicking outside
    document.getElementById('userModal').addEventListener('click', function(e) {
      if (e.target === this) {
        closeModal();
      }
    });
    
    // User search functionality
    document.getElementById('userSearch').addEventListener('input', function() {
      const searchTerm = this.value.toLowerCase();
      const rows = document.querySelectorAll('#usersTable tbody tr');
      
      rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
      });
    });
  </script>
</body>
</html>
