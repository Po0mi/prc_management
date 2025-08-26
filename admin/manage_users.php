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
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $role = $_POST['role'];
        $user_type = $_POST['user_type'] ?? 'non_rcy_member';
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $gender = $_POST['gender'] ?? null;
        $services = $_POST['services'] ?? [];

        if ($username && $password && $full_name && in_array($role, ['admin','user'])) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $servicesJson = $user_type === 'rcy_member' ? json_encode($services) : null;
            
            $stmt = $pdo->prepare("
                INSERT INTO users (username, password_hash, full_name, first_name, last_name, role, user_type, email, phone, gender, services)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            try {
                $stmt->execute([$username, $hash, $full_name, $first_name, $last_name, $role, $user_type, $email, $phone, $gender, $servicesJson]);
                $userId = $pdo->lastInsertId();
                
                // Insert services into user_services table if RCY member
                if ($user_type === 'rcy_member' && !empty($services)) {
                    $stmt = $pdo->prepare("INSERT INTO user_services (user_id, service_type) VALUES (?, ?)");
                    foreach ($services as $service) {
                        $stmt->execute([$userId, $service]);
                    }
                }
                
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
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $role = $_POST['role'];
        $user_type = $_POST['user_type'] ?? 'non_rcy_member';
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $gender = $_POST['gender'] ?? null;
        $password = $_POST['password'];
        $services = $_POST['services'] ?? [];

        if ($user_id && $full_name && in_array($role, ['admin','user'])) {
            $servicesJson = $user_type === 'rcy_member' ? json_encode($services) : null;
            
            if (!empty($password)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET full_name = ?, first_name = ?, last_name = ?, role = ?, user_type = ?, email = ?, phone = ?, gender = ?, services = ?, password_hash = ?
                    WHERE user_id = ?
                ");
                $stmt->execute([$full_name, $first_name, $last_name, $role, $user_type, $email, $phone, $gender, $servicesJson, $hash, $user_id]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET full_name = ?, first_name = ?, last_name = ?, role = ?, user_type = ?, email = ?, phone = ?, gender = ?, services = ?
                    WHERE user_id = ?
                ");
                $stmt->execute([$full_name, $first_name, $last_name, $role, $user_type, $email, $phone, $gender, $servicesJson, $user_id]);
            }
            
            // Update services in user_services table
            $pdo->prepare("DELETE FROM user_services WHERE user_id = ?")->execute([$user_id]);
            if ($user_type === 'rcy_member' && !empty($services)) {
                $stmt = $pdo->prepare("INSERT INTO user_services (user_id, service_type) VALUES (?, ?)");
                foreach ($services as $service) {
                    $stmt->execute([$user_id, $service]);
                }
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

// Get all users with their services
$stmt = $pdo->query("
    SELECT u.user_id, u.username, u.full_name, u.first_name, u.last_name, u.role, u.user_type, u.email, u.phone, u.gender, u.services,
           GROUP_CONCAT(us.service_type) as user_services
    FROM users u
    LEFT JOIN user_services us ON u.user_id = us.user_id
    GROUP BY u.user_id
    ORDER BY u.username
");
$users = $stmt->fetchAll();

// Get statistics
$admin_count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$user_count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
$rcy_member_count = $pdo->query("SELECT COUNT(*) FROM users WHERE user_type = 'rcy_member'")->fetchColumn();
$non_rcy_member_count = $pdo->query("SELECT COUNT(*) FROM users WHERE user_type = 'non_rcy_member'")->fetchColumn();
$total_users = $admin_count + $user_count;

// Service names mapping
$serviceNames = [
    'health' => 'Health Services',
    'safety' => 'Safety Services',
    'welfare' => 'Welfare Services',
    'disaster_management' => 'Disaster Management',
    'red_cross_youth' => 'Red Cross Youth'
];
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
  <style>
    .user-type-badge {
      display: inline-block;
      padding: 0.25rem 0.5rem;
      border-radius: 12px;
      font-size: 0.75rem;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    
    .user-type-badge.non_rcy_member {
      background: #e3f2fd;
      color: #1565c0;
    }
    
    .user-type-badge.rcy_member {
      background: #f3e5f5;
      color: #7b1fa2;
    }
    
    .user-type-badge.guest {
      background: #f1f8e9;
      color: #558b2f;
    }
    
    .user-type-badge.member {
      background: #fff3e0;
      color: #f57c00;
    }
    
    .services-tags {
      display: flex;
      flex-wrap: wrap;
      gap: 0.25rem;
      margin-top: 0.25rem;
    }
    
    .service-tag {
      background: #ffebee;
      color: #c62828;
      padding: 0.125rem 0.375rem;
      border-radius: 8px;
      font-size: 0.6rem;
      font-weight: 500;
    }
    
    .services-section {
      display: none;
      margin-top: 1rem;
      padding: 1rem;
      background: #f8f9fa;
      border-radius: 8px;
      border: 1px solid #dee2e6;
    }
    
    .services-section.show {
      display: block;
    }
    
    .services-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 0.5rem;
      margin-top: 0.5rem;
    }
    
    .service-checkbox {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.5rem;
      background: white;
      border: 1px solid #dee2e6;
      border-radius: 4px;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    
    .service-checkbox:hover {
      background: #f8f9fa;
      border-color: #a00000;
    }
    
    .service-checkbox input[type="checkbox"] {
      margin: 0;
      cursor: pointer;
    }
    
    .service-checkbox span {
      cursor: pointer;
      font-size: 0.85rem;
      user-select: none;
    }
  </style>
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="users-container">
    <div class="page-header">
      <h1><i class="fas fa-users-cog"></i> User Management</h1>
      <p>Create, update, and manage system users including RCY members</p>
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
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #9c27b0 0%, #e91e63 100%);">
          <i class="fas fa-hands-helping"></i>
        </div>
        <div>
          <div style="font-size: 1.5rem; font-weight: 700;"><?= $rcy_member_count ?></div>
          <div style="color: var(--gray); font-size: 0.9rem;">RCY Members</div>
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
              <th>User Type</th>
              <th>Services</th>
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
                <td>
                  <span class="user-type-badge <?= $u['user_type'] ?>">
                    <?php 
                    switch($u['user_type']) {
                      case 'rcy_member': echo 'RCY Member'; break;
                      case 'non_rcy_member': echo 'Non-RCY'; break;
                      case 'guest': echo 'Guest'; break;
                      case 'member': echo 'Member'; break;
                      default: echo ucfirst($u['user_type']); break;
                    }
                    ?>
                  </span>
                </td>
                <td>
                  <?php if ($u['user_type'] === 'rcy_member' && $u['user_services']): ?>
                    <div class="services-tags">
                      <?php 
                      $services = explode(',', $u['user_services']);
                      foreach ($services as $service): 
                        $serviceName = $serviceNames[trim($service)] ?? ucfirst(str_replace('_', ' ', trim($service)));
                      ?>
                        <span class="service-tag"><?= htmlspecialchars($serviceName) ?></span>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <span style="color: #6c757d; font-style: italic;">N/A</span>
                  <?php endif; ?>
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
    <div class="modal-content" style="max-width: 600px;">
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
            <label for="first_name">First Name</label>
            <input type="text" id="first_name" name="first_name" placeholder="Enter first name">
          </div>
          
          <div class="form-group">
            <label for="last_name">Last Name</label>
            <input type="text" id="last_name" name="last_name" placeholder="Enter last name">
          </div>
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
            <label for="user_type">User Type *</label>
            <select id="user_type" name="user_type" required onchange="toggleServicesSection()">
              <option value="non_rcy_member">Non-RCY Member</option>
              <option value="rcy_member">RCY Member</option>
              <option value="guest">Guest (Legacy)</option>
              <option value="member">Member (Legacy)</option>
            </select>
          </div>
          
          <div class="form-group">
            <label for="gender">Gender</label>
            <select id="gender" name="gender">
              <option value="">Select Gender</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
              <option value="other">Other</option>
              <option value="prefer_not_to_say">Prefer not to say</option>
            </select>
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" placeholder="Enter email">
          </div>
          
          <div class="form-group">
            <label for="phone">Phone</label>
            <input type="text" id="phone" name="phone" placeholder="Enter phone number">
          </div>
        </div>
        
        <!-- RCY Services Section -->
        <div class="services-section" id="servicesSection">
          <h4><i class="fas fa-hands-helping"></i> RCY Member Services</h4>
          <p style="margin: 0.5rem 0; color: #666; font-size: 0.9rem;">Select the services this RCY member will participate in:</p>
          
          <div class="services-grid">
            <label class="service-checkbox" for="service_health">
              <input type="checkbox" name="services[]" value="health" id="service_health">
              <span>Health Services</span>
            </label>
            
            <label class="service-checkbox" for="service_safety">
              <input type="checkbox" name="services[]" value="safety" id="service_safety">
              <span>Safety Services</span>
            </label>
            
            <label class="service-checkbox" for="service_welfare">
              <input type="checkbox" name="services[]" value="welfare" id="service_welfare">
              <span>Welfare Services</span>
            </label>
            
            <label class="service-checkbox" for="service_disaster">
              <input type="checkbox" name="services[]" value="disaster_management" id="service_disaster">
              <span>Disaster Management</span>
            </label>
            
            <label class="service-checkbox" for="service_rcy">
              <input type="checkbox" name="services[]" value="red_cross_youth" id="service_rcy">
              <span>Red Cross Youth</span>
            </label>
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
    function toggleServicesSection() {
      const userType = document.getElementById('user_type').value;
      const servicesSection = document.getElementById('servicesSection');
      
      if (userType === 'rcy_member') {
        servicesSection.classList.add('show');
      } else {
        servicesSection.classList.remove('show');
        // Clear all checkboxes when not RCY member
        const checkboxes = servicesSection.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(cb => cb.checked = false);
      }
    }
    
    function openCreateModal() {
      document.getElementById('modalTitle').textContent = 'Create New User';
      document.getElementById('formAction').name = 'create_user';
      document.getElementById('userForm').reset();
      document.getElementById('username').readOnly = false;
      document.getElementById('passwordField').required = true;
      document.getElementById('passwordField').placeholder = "Enter password";
      document.getElementById('user_type').value = 'non_rcy_member';
      toggleServicesSection();
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
      document.getElementById('first_name').value = user.first_name || '';
      document.getElementById('last_name').value = user.last_name || '';
      document.getElementById('role').value = user.role;
      document.getElementById('user_type').value = user.user_type || 'non_rcy_member';
      document.getElementById('gender').value = user.gender || '';
      document.getElementById('email').value = user.email || '';
      document.getElementById('phone').value = user.phone || '';
      
      // Clear all service checkboxes first
      const serviceCheckboxes = document.querySelectorAll('input[name="services[]"]');
      serviceCheckboxes.forEach(cb => cb.checked = false);
      
      // Set services for RCY members
      if (user.user_type === 'rcy_member' && user.user_services) {
        const userServices = user.user_services.split(',');
        userServices.forEach(service => {
          const checkbox = document.getElementById('service_' + service.trim());
          if (checkbox) {
            checkbox.checked = true;
          }
        });
      }
      
      toggleServicesSection();
      document.getElementById('userModal').classList.add('active');
    }
    
    function closeModal() {
      document.getElementById('userModal').classList.remove('active');
    }
    
    function confirmDelete(username) {
      return confirm(`Are you sure you want to delete the user "${username}"?\n\nThis action cannot be undone and will also delete all associated services and documents.`);
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
    
    // Initialize services section visibility on load
    document.addEventListener('DOMContentLoaded', function() {
      toggleServicesSection();
    });
  </script>
</body>
</html>