<?php
require_once __DIR__ . '/../config.php';
ensure_logged_in();
ensure_admin();

$pdo = $GLOBALS['pdo'];
$errorMessage = '';
$successMessage = '';

// Get filter parameter
$roleFilter = $_GET['role'] ?? 'all';
if (isset($_GET['view_documents'])) {
    $userId = (int)$_GET['user_id'];
    if ($userId) {
        $stmt = $pdo->prepare("
            SELECT * FROM user_documents 
            WHERE user_id = ? 
            ORDER BY uploaded_at DESC
        ");
        $stmt->execute([$userId]);
        $documents = $stmt->fetchAll();
        
        // Return JSON response for AJAX request
        header('Content-Type: application/json');
        echo json_encode($documents);
        exit;
    }
}

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

// Build query with role filter
$whereClause = '';
$params = [];

if ($roleFilter !== 'all') {
    $whereClause = 'WHERE u.role = ?';
    $params[] = $roleFilter;
}
// Get filtered users with their services
$stmt = $pdo->prepare("
    SELECT u.user_id, u.username, u.full_name, u.first_name, u.last_name, u.role, u.user_type, u.email, u.phone, u.gender, u.services,
           GROUP_CONCAT(us.service_type) as user_services
    FROM users u
    LEFT JOIN user_services us ON u.user_id = us.user_id
    $whereClause
    GROUP BY u.user_id
    ORDER BY u.username
");
$stmt->execute($params);
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

    /* Enhanced role filter styles */
    .role-filter {
      display: flex;
      gap: 0.5rem;
      background: white;
      padding: 0.4rem;
      border-radius: 50px;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
      border: 1px solid #e0e0e0;
    }
    
    .role-filter button {
      padding: 0.6rem 1.2rem;
      border: none;
      background: transparent;
      border-radius: 50px;
      cursor: pointer;
      transition: all 0.3s ease;
      font-size: 0.9rem;
      font-weight: 600;
      color: var(--gray);
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 0.4rem;
      position: relative;
    }
    
    .role-filter button:hover {
      background: rgba(160, 0, 0, 0.08);
      color: var(--prc-red);
      transform: translateY(-1px);
    }
    
    .role-filter button.active {
      background: linear-gradient(135deg, var(--prc-red) 0%, var(--prc-red-dark) 100%);
      color: white;
      box-shadow: 0 3px 10px rgba(160, 0, 0, 0.3);
      transform: translateY(-2px);
    }
    
    .role-filter button.active:hover {
      transform: translateY(-3px);
      box-shadow: 0 5px 15px rgba(160, 0, 0, 0.4);
    }

    /* Count badges in filter buttons */
    .filter-count {
      background: rgba(255, 255, 255, 0.3);
      color: inherit;
      padding: 0.15rem 0.4rem;
      border-radius: 12px;
      font-size: 0.7rem;
      font-weight: 700;
      margin-left: 0.3rem;
    }
    
    .role-filter button.active .filter-count {
      background: rgba(255, 255, 255, 0.25);
      color: white;
    }
    
    .role-filter button:not(.active) .filter-count {
      background: rgba(160, 0, 0, 0.1);
      color: var(--prc-red);
    }

    /* Section headers for filtered views */
    .section-header {
      display: flex;
      align-items: center;
      gap: 1rem;
      margin-bottom: 1rem;
      padding: 1rem 0;
      border-bottom: 2px solid #f0f0f0;
    }
    
    .section-title {
      font-size: 1.2rem;
      font-weight: 600;
      color: var(--dark);
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    
    .section-count {
      background: var(--prc-red);
      color: white;
      padding: 0.3rem 0.8rem;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 600;
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

    <!-- Action Bar with Enhanced Role Filter -->
    <div class="action-bar">
      <div class="action-bar-left">
        <div class="search-box">
          <i class="fas fa-search"></i>
          <input type="text" id="userSearch" placeholder="Search users...">
        </div>
        
        <!-- Enhanced Role Filter -->
        <div class="role-filter">
          <a href="?role=all" class="<?= $roleFilter === 'all' ? 'active' : '' ?>">
            <i class="fas fa-users"></i>
            All Users
            <span class="filter-count"><?= $total_users ?></span>
          </a>
          <a href="?role=admin" class="<?= $roleFilter === 'admin' ? 'active' : '' ?>">
            <i class="fas fa-user-shield"></i>
            Administrators
            <span class="filter-count"><?= $admin_count ?></span>
          </a>
          <a href="?role=user" class="<?= $roleFilter === 'user' ? 'active' : '' ?>">
            <i class="fas fa-user"></i>
            Regular Users
            <span class="filter-count"><?= $user_count ?></span>
          </a>
        </div>
      </div>
      
      <button class="btn-create" onclick="openCreateModal()">
        <i class="fas fa-user-plus"></i> Create New User
      </button>
    </div>

    <!-- Statistics Overview -->
    <div class="stats-overview">
      <div class="stat-card">
        <div class="stat-icon">
          <i class="fas fa-users"></i>
        </div>
        <div>
          <div><?= $total_users ?></div>
          <div>Total Users</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon">
          <i class="fas fa-user-shield"></i>
        </div>
        <div>
          <div><?= $admin_count ?></div>
          <div>Administrators</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon">
          <i class="fas fa-user"></i>
        </div>
        <div>
          <div><?= $user_count ?></div>
          <div>Regular Users</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon">
          <i class="fas fa-hands-helping"></i>
        </div>
        <div>
          <div><?= $rcy_member_count ?></div>
          <div>RCY Members</div>
        </div>
      </div>
    </div>

    <!-- Users Table with Section Header -->
    <div class="users-table-wrapper">
      <div class="section-header">
        <?php if ($roleFilter === 'all'): ?>
          <h2 class="section-title">
            <i class="fas fa-users"></i>
            All Users
          </h2>
          <span class="section-count"><?= count($users) ?> users</span>
        <?php elseif ($roleFilter === 'admin'): ?>
          <h2 class="section-title">
            <i class="fas fa-user-shield"></i>
            Administrators
          </h2>
          <span class="section-count"><?= count($users) ?> admins</span>
        <?php elseif ($roleFilter === 'user'): ?>
          <h2 class="section-title">
            <i class="fas fa-user"></i>
            Regular Users
          </h2>
          <span class="section-count"><?= count($users) ?> users</span>
        <?php endif; ?>
      </div>
      
      <?php if (empty($users)): ?>
        <div class="empty-state">
          <?php if ($roleFilter === 'all'): ?>
            <i class="fas fa-user-slash"></i>
            <h3>No users found</h3>
            <p>Click "Create New User" to get started</p>
          <?php elseif ($roleFilter === 'admin'): ?>
            <i class="fas fa-user-shield"></i>
            <h3>No administrators found</h3>
            <p>Create users with admin role to see them here</p>
          <?php elseif ($roleFilter === 'user'): ?>
            <i class="fas fa-user"></i>
            <h3>No regular users found</h3>
            <p>Create users with user role to see them here</p>
          <?php endif; ?>
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
                    <span>N/A</span>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= htmlspecialchars($u['phone']) ?></td>
                <td class="actions">
                  <button class="btn-action btn-edit" onclick="openEditModal(<?= htmlspecialchars(json_encode($u)) ?>)">
                    <i class="fas fa-edit"></i> Edit
                  </button>
                  <button class="btn-action btn-view-docs" onclick="viewDocuments(<?= $u['user_id'] ?>, '<?= htmlspecialchars($u['username']) ?>')">
                    <i class="fas fa-file"></i> Docs
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

  <!-- Documents Modal -->
  <div class="documents-modal" id="documentsModal">
    <div class="documents-content">
      <div class="documents-header">
        <h2 id="documentsTitle">User Documents</h2>
        <button class="close-documents" onclick="closeDocumentsModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <div class="documents-body">
        <div class="loading-documents" id="documentsLoading">
          <div class="loading-spinner"></div>
          <p>Loading documents...</p>
        </div>
        <div class="documents-list" id="documentsList" style="display: none;">
          <!-- Documents will be inserted here -->
        </div>
        <div class="no-documents" id="noDocuments" style="display: none;">
          <i class="fas fa-folder-open"></i>
          <h3>No Documents Found</h3>
          <p>This user hasn't uploaded any documents yet.</p>
        </div>
      </div>
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
          <p>Select the services this RCY member will participate in:</p>
          
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
  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
<script>
  // Document viewing functionality
    function viewDocuments(userId, username) {
      // Show modal with loading state
      const modal = document.getElementById('documentsModal');
      const title = document.getElementById('documentsTitle');
      const loading = document.getElementById('documentsLoading');
      const list = document.getElementById('documentsList');
      const noDocs = document.getElementById('noDocuments');
      
      title.textContent = `Documents - ${username}`;
      loading.style.display = 'block';
      list.style.display = 'none';
      noDocs.style.display = 'none';
      modal.style.display = 'flex';
      
      // Fetch documents via AJAX
      fetch(`?view_documents=1&user_id=${userId}`)
        .then(response => response.json())
        .then(documents => {
          loading.style.display = 'none';
          
          if (documents.length > 0) {
            list.style.display = 'grid';
            renderDocumentsList(documents);
          } else {
            noDocs.style.display = 'block';
          }
        })
        .catch(error => {
          console.error('Error fetching documents:', error);
          loading.style.display = 'none';
          noDocs.innerHTML = `
            <i class="fas fa-exclamation-triangle"></i>
            <h3>Error Loading Documents</h3>
            <p>There was a problem loading the documents. Please try again.</p>
          `;
          noDocs.style.display = 'block';
        });
    }
    
    function renderDocumentsList(documents) {
      const list = document.getElementById('documentsList');
      list.innerHTML = '';
      
      documents.forEach(doc => {
        // Determine icon based on file type
        let icon = 'fa-file';
        if (doc.file_type === 'pdf') icon = 'fa-file-pdf';
        else if (['doc', 'docx'].includes(doc.file_type)) icon = 'fa-file-word';
        else if (['jpg', 'jpeg', 'png', 'gif'].includes(doc.file_type)) icon = 'fa-file-image';
        else if (doc.file_type === 'txt') icon = 'fa-file-text';
        
        // Format file size
        const fileSize = formatFileSize(doc.file_size);
        
        // Format upload date
        const uploadDate = new Date(doc.uploaded_at).toLocaleDateString();
        
        const docElement = document.createElement('div');
        docElement.className = 'document-item';
        docElement.innerHTML = `
          <div class="document-info">
            <div class="document-icon">
              <i class="fas ${icon}"></i>
            </div>
            <div class="document-details">
              <h4>${doc.original_name}</h4>
              <div class="document-meta">
                ${fileSize} • ${doc.file_type.toUpperCase()} • Uploaded: ${uploadDate}
              </div>
            </div>
          </div>
          <div class="document-actions">
            <a href="../${doc.file_path}" target="_blank" class="btn-view">
              <i class="fas fa-eye"></i> View
            </a>
            <a href="../${doc.file_path}" download="${doc.original_name}" class="btn-download">
              <i class="fas fa-download"></i> Download
            </a>
          </div>
        `;
        
        list.appendChild(docElement);
      });
    }
    
    function formatFileSize(bytes) {
      if (bytes === 0) return '0 Bytes';
      const k = 1024;
      const sizes = ['Bytes', 'KB', 'MB', 'GB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    function closeDocumentsModal() {
      document.getElementById('documentsModal').style.display = 'none';
    }
    
    // Close modal when clicking outside
    document.getElementById('documentsModal').addEventListener('click', function(e) {
      if (e.target === this) {
        closeDocumentsModal();
      }
    });
    
    // Existing functions
    function toggleServicesSection() {
      const userType = document.getElementById('user_type').value;
      const servicesSection = document.getElementById('servicesSection');
      
      if (userType === 'rcy_member') {
        servicesSection.style.display = 'block';
      } else {
        servicesSection.style.display = 'none';
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
      document.getElementById('userModal').style.display = 'flex';
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
      document.getElementById('userModal').style.display = 'flex';
    }
    
    function closeModal() {
      document.getElementById('userModal').style.display = 'none';
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
