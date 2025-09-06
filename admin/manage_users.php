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
                 require_once 'notifications_api_admin.php';
            notifyNewUserCreated($pdo, $userId, $username, $role, $user_type, $_SESSION['user_id']);
            
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
        $successMessage = "User updated successfully!";

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
// Get filtered users with their services
$stmt = $pdo->prepare("
    SELECT u.user_id, u.username, u.full_name, u.first_name, u.last_name, u.role, u.admin_role, u.user_type, u.email, u.phone, u.gender, u.services,
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/sidebar_admin.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/header.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/admin_users.css?v=<?php echo time(); ?>">  
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="users-container">
        <!-- Enhanced Page Header -->
        <div class="page-header">
            <h1><i class="fas fa-users-cog"></i> User Management</h1>
            <p>Create, update, and manage system users including RCY members with streamlined controls</p>
        </div>

        <!-- Alert Messages -->
        <?php if ($errorMessage): ?>
            <div class="alert error">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Error:</strong> <?= htmlspecialchars($errorMessage) ?>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($successMessage): ?>
            <div class="alert success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Success:</strong> <?= htmlspecialchars($successMessage) ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Enhanced Action Bar -->
        <div class="action-bar">
            <div class="search-and-filters">
                <!-- Advanced Search Container -->
                <div class="search-container">
                    <input 
                        type="text" 
                        class="search-input" 
                        id="userSearch" 
                        placeholder="Search users by name, username, or email..."
                        autocomplete="off"
                    >
                    <i class="fas fa-search search-icon"></i>
                    <button class="search-clear" id="searchClear" type="button" aria-label="Clear search">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <!-- Improved Filter Pills -->
                <div class="filter-pills">
                    <a href="?role=all" class="filter-pill <?= $roleFilter === 'all' ? 'active' : '' ?>">
                        <i class="fas fa-users"></i>
                        <span>All Users</span>
                        <span class="filter-count"><?= $total_users ?></span>
                    </a>
                    <a href="?role=admin" class="filter-pill <?= $roleFilter === 'admin' ? 'active' : '' ?>">
                        <i class="fas fa-user-shield"></i>
                        <span>Administrators</span>
                        <span class="filter-count"><?= $admin_count ?></span>
                    </a>
                    <a href="?role=user" class="filter-pill <?= $roleFilter === 'user' ? 'active' : '' ?>">
                        <i class="fas fa-user"></i>
                        <span>Regular Users</span>
                        <span class="filter-count"><?= $user_count ?></span>
                    </a>
                </div>
            </div>
            
            <!-- Enhanced Create Button -->
            <button class="btn-create" onclick="openCreateModal()" type="button">
                <i class="fas fa-user-plus"></i>
                <span>Create New User</span>
            </button>
        </div>

        <!-- Compact Statistics Overview -->
        <div class="stats-overview">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-details">
                    <div class="stat-number"><?= $total_users ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="stat-details">
                    <div class="stat-number"><?= $admin_count ?></div>
                    <div class="stat-label">Administrators</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user"></i>
                </div>
                <div class="stat-details">
                    <div class="stat-number"><?= $user_count ?></div>
                    <div class="stat-label">Regular Users</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-hands-helping"></i>
                </div>
                <div class="stat-details">
                    <div class="stat-number"><?= $rcy_member_count ?></div>
                    <div class="stat-label">RCY Members</div>
                </div>
            </div>
        </div>

        <!-- Clean Users Table -->
        <div class="users-table-container">
            <div class="table-header">
                <h2 class="table-title">
                    <i class="fas fa-table"></i>
                    <?php if ($roleFilter === 'all'): ?>
                        All System Users
                    <?php elseif ($roleFilter === 'admin'): ?>
                        System Administrators
                    <?php elseif ($roleFilter === 'user'): ?>
                        Regular Users
                    <?php endif; ?>
                </h2>
                <div class="results-info">
                    <i class="fas fa-info-circle"></i>
                    <span>Showing <span class="results-count"><?= count($users) ?></span> results</span>
                </div>
            </div>
            
            <?php if (empty($users)): ?>
                <div class="empty-state">
                    <?php if ($roleFilter === 'all'): ?>
                        <i class="fas fa-user-slash"></i>
                        <h3>No Users Found</h3>
                        <p>Click "Create New User" to add your first user to the system</p>
                    <?php elseif ($roleFilter === 'admin'): ?>
                        <i class="fas fa-user-shield"></i>
                        <h3>No Administrators Found</h3>
                        <p>Create users with administrator privileges to see them here</p>
                    <?php elseif ($roleFilter === 'user'): ?>
                        <i class="fas fa-user"></i>
                        <h3>No Regular Users Found</h3>
                        <p>Create standard user accounts to see them listed here</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="users-table" id="usersTable">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>User Type</th>
                                <th>Services</th>
                                <th>Contact</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                                <tr data-user-id="<?= $u['user_id'] ?>">
                                    <td>
                                        <div class="user-info">
                                            <div class="user-avatar">
                                                <?= strtoupper(substr($u['full_name'] ?: $u['username'], 0, 1)) ?>
                                            </div>
                                            <div class="user-details">
                                                <div class="user-name"><?= htmlspecialchars($u['full_name']) ?></div>
                                                <div class="user-username">@<?= htmlspecialchars($u['username']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
    <span class="role-badge <?= $u['role'] === 'admin' ? $u['admin_role'] ?? 'admin' : $u['role'] ?>">
        <?php 
        if ($u['role'] === 'admin' && $u['admin_role']): 
            switch($u['admin_role']) {
                case 'safety': 
                    echo '<i class="fas fa-shield-alt"></i> Safety Admin'; 
                    break;
                case 'disaster': 
                    echo '<i class="fas fa-exclamation-triangle"></i> Disaster Admin'; 
                    break;
                case 'health': 
                    echo '<i class="fas fa-heartbeat"></i> Health Admin'; 
                    break;
                case 'welfare': 
                    echo '<i class="fas fa-hand-holding-heart"></i> Welfare Admin'; 
                    break;
                case 'youth': 
                    echo '<i class="fas fa-users"></i> Red Cross Youth Administrator'; 
                    break;
                case 'super': 
                    echo '<i class="fas fa-crown"></i> Super Administrator'; 
                    break;
                default: 
                    echo '<i class="fas fa-user-shield"></i> Administrator'; 
                    break;
            }
        elseif ($u['role'] === 'admin'): 
            echo '<i class="fas fa-user-shield"></i> Administrator';
        else: 
            echo '<i class="fas fa-user"></i> User';
        endif; 
        ?>
    </span>
</td>
                                    <td>
                                        <?php if ($u['user_type'] === 'rcy_member' && $u['user_services']): ?>
                                            <div class="services-container">
                                                <?php 
                                                $services = explode(',', $u['user_services']);
                                                foreach ($services as $service): 
                                                    $serviceName = $serviceNames[trim($service)] ?? ucfirst(str_replace('_', ' ', trim($service)));
                                                ?>
                                                    <span class="service-tag"><?= htmlspecialchars($serviceName) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="contact-info">
                                            <?php if ($u['email']): ?>
                                                <div class="contact-item">
                                                    <i class="fas fa-envelope"></i>
                                                    <?= htmlspecialchars($u['email']) ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($u['phone']): ?>
                                                <div class="contact-item">
                                                    <i class="fas fa-phone"></i>
                                                    <?= htmlspecialchars($u['phone']) ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!$u['email'] && !$u['phone']): ?>
                                                <span class="text-muted">No contact info</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button 
                                                class="btn-action btn-edit" 
                                                onclick="openEditModal(<?= htmlspecialchars(json_encode($u)) ?>)"
                                                type="button"
                                                title="Edit user details"
                                            >
                                                <i class="fas fa-edit"></i>
                                                <span>Edit</span>
                                            </button>
                                            
                                            <button 
                                                class="btn-action btn-view-docs" 
                                                onclick="viewDocuments(<?= $u['user_id'] ?>, '<?= htmlspecialchars($u['username']) ?>')"
                                                type="button"
                                                title="View user documents"
                                            >
                                                <i class="fas fa-file-alt"></i>
                                                <span>Docs</span>
                                            </button>
                                            
                                            <form method="POST" class="inline-form" onsubmit="return confirmDelete('<?= htmlspecialchars($u['username']) ?>')">
                                                <input type="hidden" name="delete_user" value="1">
                                                <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                                <button 
                                                    type="submit" 
                                                    class="btn-action btn-delete"
                                                    title="Delete user permanently"
                                                >
                                                    <i class="fas fa-trash-alt"></i>
                                                    <span>Delete</span>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Enhanced User Modal -->
    <div class="modal" id="userModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Create New User</h2>
                <button class="close-modal" onclick="closeModal()" type="button" aria-label="Close modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="modal-body">
                <form method="POST" id="userForm" class="form-grid">
                    <input type="hidden" name="create_user" value="1" id="formAction">
                    <input type="hidden" name="user_id" id="userId">
                    
                    <!-- Basic Information -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="username" class="form-label">
                                Username <span class="required">*</span>
                            </label>
                            <input 
                                type="text" 
                                id="username" 
                                name="username" 
                                class="form-input"
                                required 
                                placeholder="Enter unique username"
                                autocomplete="username"
                            >
                        </div>
                        
                        <div class="form-group">
                            <label for="passwordField" class="form-label">
                                Password <span class="required">*</span>
                            </label>
                            <input 
                                type="password" 
                                id="passwordField" 
                                name="password" 
                                class="form-input"
                                required 
                                placeholder="Enter secure password"
                                autocomplete="new-password"
                            >
                        </div>
                    </div>
                    
                    <!-- Full Name -->
                    <div class="form-group full-width">
                        <label for="full_name" class="form-label">
                            Full Name <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="full_name" 
                            name="full_name" 
                            class="form-input"
                            required 
                            placeholder="Enter complete full name"
                            autocomplete="name"
                        >
                    </div>
                    
                    <!-- Name Details -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name" class="form-label">First Name</label>
                            <input 
                                type="text" 
                                id="first_name" 
                                name="first_name" 
                                class="form-input"
                                placeholder="Enter first name"
                                autocomplete="given-name"
                            >
                        </div>
                        
                        <div class="form-group">
                            <label for="last_name" class="form-label">Last Name</label>
                            <input 
                                type="text" 
                                id="last_name" 
                                name="last_name" 
                                class="form-input"
                                placeholder="Enter last name"
                                autocomplete="family-name"
                            >
                        </div>
                    </div>
                    
                    <!-- Role and User Type -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="role" class="form-label">
                                Role <span class="required">*</span>
                            </label>
                            <select id="role" name="role" class="form-select" required>
                                <option value="user">User</option>
                                <option value="admin">Administrator</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="user_type" class="form-label">
                                User Type <span class="required">*</span>
                            </label>
                            <select id="user_type" name="user_type" class="form-select" required onchange="toggleServicesSection()">
                                <option value="non_rcy_member">Non-RCY Member</option>
                                <option value="rcy_member">RCY Member</option>
                                <option value="guest">Guest (Legacy)</option>
                                <option value="member">Member (Legacy)</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Contact and Gender -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="email" class="form-label">
                                <i class="fas fa-envelope"></i> Email Address
                            </label>
                            <input 
                                type="email" 
                                id="email" 
                                name="email" 
                                class="form-input"
                                placeholder="user@example.com"
                                autocomplete="email"
                            >
                        </div>
                        
                        <div class="form-group">
                            <label for="phone" class="form-label">
                                <i class="fas fa-phone"></i> Phone Number
                            </label>
                            <input 
                                type="tel" 
                                id="phone" 
                                name="phone" 
                                class="form-input"
                                placeholder="+63 XXX XXX XXXX"
                                autocomplete="tel"
                            >
                        </div>
                    </div>
                    
                    <!-- Gender Selection -->
                    <div class="form-group">
                        <label for="gender" class="form-label">
                            <i class="fas fa-user"></i> Gender
                        </label>
                        <select id="gender" name="gender" class="form-select">
                            <option value="">Select Gender (Optional)</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                            <option value="prefer_not_to_say">Prefer not to say</option>
                        </select>
                    </div>
                    
                    <!-- Enhanced RCY Services Section -->
                    <div class="services-section" id="servicesSection">
                        <div class="services-header">
                            <i class="fas fa-hands-helping"></i>
                            <span>RCY Member Services</span>
                        </div>
                        <p class="services-description">Select the services this RCY member will participate in:</p>
                        
                        <div class="services-grid">
                            <label class="service-checkbox" for="service_health">
                                <input type="checkbox" name="services[]" value="health" id="service_health">
                                <span>
                                    <i class="fas fa-heartbeat"></i>
                                    Health Services
                                </span>
                            </label>
                            
                            <label class="service-checkbox" for="service_safety">
                                <input type="checkbox" name="services[]" value="safety" id="service_safety">
                                <span>
                                    <i class="fas fa-shield-alt"></i>
                                    Safety Services
                                </span>
                            </label>
                            
                            <label class="service-checkbox" for="service_welfare">
                                <input type="checkbox" name="services[]" value="welfare" id="service_welfare">
                                <span>
                                    <i class="fas fa-hand-holding-heart"></i>
                                    Welfare Services
                                </span>
                            </label>
                            
                            <label class="service-checkbox" for="service_disaster">
                                <input type="checkbox" name="services[]" value="disaster_management" id="service_disaster">
                                <span>
                                    <i class="fas fa-exclamation-triangle"></i>
                                    Disaster Management
                                </span>
                            </label>
                            
                            <label class="service-checkbox" for="service_rcy">
                                <input type="checkbox" name="services[]" value="red_cross_youth" id="service_rcy">
                                <span>
                                    <i class="fas fa-users"></i>
                                    Red Cross Youth
                                </span>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Enhanced Submit Button -->
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i>
                        <span>Save User</span>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Enhanced Documents Modal -->
    <div class="documents-modal" id="documentsModal">
        <div class="documents-content">
            <div class="documents-header">
                <h2 id="documentsTitle">User Documents</h2>
                <button class="close-documents" onclick="closeDocumentsModal()" type="button" aria-label="Close documents">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="documents-body">
                <div class="loading-documents" id="documentsLoading">
                    <div class="loading-spinner"></div>
                    <p>Loading documents...</p>
                </div>
                <div class="documents-list" id="documentsList" style="display: none;">
                    <!-- Documents will be populated here via JavaScript -->
                </div>
                <div class="no-documents" id="noDocuments" style="display: none;">
                    <i class="fas fa-folder-open"></i>
                    <h3>No Documents Found</h3>
                    <p>This user hasn't uploaded any documents yet.</p>
                </div>
            </div>
        </div>
    </div>

    <script src="../admin/js/notification_frontend.js?v=<?php echo time(); ?>"></script>
  <script src="../admin/js/sidebar-notifications.js?v=<?php echo time(); ?>"></script>
    <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
    <script>
        // Enhanced Document viewing functionality
function viewDocuments(userId, username) {
    const modal = document.getElementById('documentsModal');
    const title = document.getElementById('documentsTitle');
    const loading = document.getElementById('documentsLoading');
    const list = document.getElementById('documentsList');
    const noDocs = document.getElementById('noDocuments');
    
    title.textContent = `Documents - ${username}`;
    loading.style.display = 'block';
    list.style.display = 'none';
    noDocs.style.display = 'none';
    modal.classList.add('active');
    
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
        
        const fileSize = formatFileSize(doc.file_size);
        const uploadDate = new Date(doc.uploaded_at).toLocaleDateString();
        
        const docElement = document.createElement('div');
        docElement.className = 'document-item';
        docElement.innerHTML = `
            <div class="document-info">
                <div class="document-icon">
                    <i class="fas ${icon}"></i>
                </div>
                <div class="document-details">
                    <h4>${escapeHtml(doc.original_name)}</h4>
                    <div class="document-meta">
                        ${fileSize} • ${doc.file_type.toUpperCase()} • Uploaded: ${uploadDate}
                    </div>
                </div>
            </div>
            <div class="document-actions">
                <a href="../${doc.file_path}" target="_blank" class="btn-view" title="View document">
                    <i class="fas fa-eye"></i> View
                </a>
                <a href="../${doc.file_path}" download="${escapeHtml(doc.original_name)}" class="btn-download" title="Download document">
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

function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}

function closeDocumentsModal() {
    document.getElementById('documentsModal').classList.remove('active');
}

// Enhanced Services Section Toggle
function toggleServicesSection() {
    const userType = document.getElementById('user_type').value;
    const servicesSection = document.getElementById('servicesSection');
    
    if (userType === 'rcy_member') {
        servicesSection.classList.add('show');
    } else {
        servicesSection.classList.remove('show');
        // Clear all checkboxes when not RCY member
        const checkboxes = servicesSection.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(cb => {
            cb.checked = false;
            cb.closest('.service-checkbox').classList.remove('checked');
        });
    }
}

// Enhanced Modal Functions
function openCreateModal() {
    const modal = document.getElementById('userModal');
    const form = document.getElementById('userForm');
    
    document.getElementById('modalTitle').textContent = 'Create New User';
    document.getElementById('formAction').name = 'create_user';
    form.reset();
    document.getElementById('username').readOnly = false;
    document.getElementById('passwordField').required = true;
    document.getElementById('passwordField').placeholder = "Enter secure password";
    document.getElementById('user_type').value = 'non_rcy_member';
    toggleServicesSection();
    modal.classList.add('active');
    
    // Focus first input
    setTimeout(() => {
        document.getElementById('username').focus();
    }, 100);
}

function openEditModal(user) {
    const modal = document.getElementById('userModal');
    
    document.getElementById('modalTitle').textContent = 'Edit User';
    document.getElementById('formAction').name = 'update_user';
    document.getElementById('userId').value = user.user_id;
    document.getElementById('username').value = user.username;
    document.getElementById('username').readOnly = true;
    document.getElementById('passwordField').required = false;
    document.getElementById('passwordField').value = '';
    document.getElementById('passwordField').placeholder = "Leave blank to keep current password";
    document.getElementById('full_name').value = user.full_name;
    document.getElementById('first_name').value = user.first_name || '';
    document.getElementById('last_name').value = user.last_name || '';
    document.getElementById('role').value = user.role;
    document.getElementById('user_type').value = user.user_type || 'non_rcy_member';
    document.getElementById('gender').value = user.gender || '';
    document.getElementById('email').value = user.email || '';
    document.getElementById('phone').value = user.phone || '';
    
    // Clear and set services
    const serviceCheckboxes = document.querySelectorAll('input[name="services[]"]');
    serviceCheckboxes.forEach(cb => {
        cb.checked = false;
        cb.closest('.service-checkbox').classList.remove('checked');
    });
    
    if (user.user_type === 'rcy_member' && user.user_services) {
        const userServices = user.user_services.split(',');
        userServices.forEach(service => {
            const checkbox = document.getElementById('service_' + service.trim());
            if (checkbox) {
                checkbox.checked = true;
                checkbox.closest('.service-checkbox').classList.add('checked');
            }
        });
    }
    
    toggleServicesSection();
    modal.classList.add('active');
    
    // Focus full name input
    setTimeout(() => {
        document.getElementById('full_name').focus();
    }, 100);
}

function closeModal() {
    document.getElementById('userModal').classList.remove('active');
}

function confirmDelete(username) {
    return confirm(`Are you sure you want to delete the user "${username}"?\n\nThis action cannot be undone and will also delete all associated services and documents.`);
}

// Enhanced Search Functionality
function setupSearch() {
    const searchInput = document.getElementById('userSearch');
    const searchClear = document.getElementById('searchClear');
    const tableRows = document.querySelectorAll('#usersTable tbody tr');
    
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        let visibleCount = 0;
        
        if (searchTerm) {
            searchClear.classList.add('show');
        } else {
            searchClear.classList.remove('show');
        }
        
        tableRows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const isVisible = text.includes(searchTerm);
            
            if (isVisible) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        // Update results count
        const resultsCount = document.querySelector('.results-count');
        if (resultsCount) {
            resultsCount.textContent = visibleCount;
        }
        
        // Show/hide empty state
        const emptyState = document.querySelector('.empty-state');
        if (visibleCount === 0 && searchTerm && emptyState) {
            emptyState.style.display = 'block';
        } else if (emptyState) {
            emptyState.style.display = 'none';
        }
    });
    
    searchClear.addEventListener('click', function() {
        searchInput.value = '';
        searchInput.dispatchEvent(new Event('input'));
        searchInput.focus();
    });
}

// Enhanced Service Checkbox Handling
function setupServiceCheckboxes() {
    const serviceCheckboxes = document.querySelectorAll('.service-checkbox');
    
    serviceCheckboxes.forEach(checkbox => {
        const input = checkbox.querySelector('input[type="checkbox"]');
        
        checkbox.addEventListener('click', function(e) {
            if (e.target !== input) {
                input.checked = !input.checked;
                input.dispatchEvent(new Event('change'));
            }
        });
        
        input.addEventListener('change', function() {
            if (this.checked) {
                checkbox.classList.add('checked');
            } else {
                checkbox.classList.remove('checked');
            }
        });
    });
}

// Modal Event Handlers
function setupModalEvents() {
    const userModal = document.getElementById('userModal');
    const documentsModal = document.getElementById('documentsModal');
    
    // Close modals when clicking outside
    [userModal, documentsModal].forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.classList.remove('active');
            }
        });
    });
    
    // Close modals with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (userModal.classList.contains('active')) {
                closeModal();
            }
            if (documentsModal.classList.contains('active')) {
                closeDocumentsModal();
            }
        }
    });
}

// Enhanced Form Validation
function setupFormValidation() {
    const form = document.getElementById('userForm');
    const submitButton = form.querySelector('.btn-submit');
    
    form.addEventListener('submit', function(e) {
        // Basic validation
        const username = document.getElementById('username').value.trim();
        const password = document.getElementById('passwordField').value;
        const fullName = document.getElementById('full_name').value.trim();
        const isCreating = document.getElementById('formAction').name === 'create_user';
        
        if (!username) {
            alert('Please enter a username.');
            e.preventDefault();
            return false;
        }
        
        if (isCreating && !password) {
            alert('Please enter a password.');
            e.preventDefault();
            return false;
        }
        
        if (!fullName) {
            alert('Please enter the full name.');
            e.preventDefault();
            return false;
        }
        
        // Show loading state
        submitButton.disabled = true;
        const originalText = submitButton.innerHTML;
        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Saving...</span>';
        
        // Reset button after some time (in case of errors)
        setTimeout(() => {
            submitButton.disabled = false;
            submitButton.innerHTML = originalText;
        }, 10000);
    });
    
    // Real-time validation feedback
    const requiredFields = form.querySelectorAll('[required]');
    requiredFields.forEach(field => {
        field.addEventListener('blur', function() {
            if (this.value.trim() === '') {
                this.style.borderColor = '#d32f2f';
            } else {
                this.style.borderColor = '';
            }
        });
        
        field.addEventListener('input', function() {
            if (this.style.borderColor === 'rgb(211, 47, 47)') {
                this.style.borderColor = '';
            }
        });
    });
}

// Enhanced User Creation Success Popup
function showUserCreationPopup(username, role, userType, isRCY) {
    // Create popup element
    const popup = document.createElement('div');
    popup.className = 'user-creation-popup';
    
    // Determine icon and styling based on user type
    let icon = 'fa-user-plus';
    let popupClass = 'success';
    let roleText = role === 'admin' ? 'Administrator' : 'User';
    
    if (role === 'admin') {
        icon = 'fa-user-shield';
        popupClass = 'admin-created';
        roleText = 'Administrator';
    } else if (isRCY) {
        icon = 'fa-users';
        popupClass = 'rcy-created';
        roleText = 'RCY Member';
    }
    
    popup.innerHTML = `
        <div class="popup-content ${popupClass}">
            <div class="popup-header">
                <div class="popup-icon">
                    <i class="fas ${icon}"></i>
                </div>
                <h3>User Created Successfully!</h3>
                <button class="popup-close" onclick="closeUserPopup(this)" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="popup-body">
                <div class="user-created-info">
                    <div class="created-user-details">
                        <strong>${username}</strong> has been created as a ${roleText}
                        ${isRCY ? ' (Red Cross Youth Member)' : ''}
                    </div>
                    <div class="popup-actions">
                        <button class="btn-view-user" onclick="highlightNewUser('${username}')">
                            <i class="fas fa-search"></i> View User
                        </button>
                        <button class="btn-create-another" onclick="closeUserPopup(this); openCreateModal();">
                            <i class="fas fa-plus"></i> Create Another
                        </button>
                    </div>
                </div>
            </div>
            <div class="popup-notification-info">
                <i class="fas fa-bell"></i>
                <small>Other administrators have been notified about this new user</small>
            </div>
        </div>
    `;
    
    // Add to page
    document.body.appendChild(popup);
    
    // Animate in
    setTimeout(() => {
        popup.classList.add('show');
    }, 100);
    
    // Auto-hide after 8 seconds
    setTimeout(() => {
        if (popup.parentNode) {
            closeUserPopup(popup.querySelector('.popup-close'));
        }
    }, 8000);
    
    // Play success sound
    playUserCreationSound(role === 'admin');
}

// Close popup function
function closeUserPopup(button) {
    const popup = button.closest('.user-creation-popup');
    popup.classList.add('hide');
    setTimeout(() => {
        if (popup.parentNode) {
            popup.parentNode.removeChild(popup);
        }
    }, 300);
}

// Highlight the newly created user in the table
function highlightNewUser(username) {
    // Close the popup first
    const popup = document.querySelector('.user-creation-popup');
    if (popup) {
        closeUserPopup(popup.querySelector('.popup-close'));
    }
    
    // Find and highlight the user row
    const rows = document.querySelectorAll('#usersTable tbody tr');
    rows.forEach(row => {
        const usernameCell = row.querySelector('.user-username');
        if (usernameCell && usernameCell.textContent.includes(username)) {
            // Scroll to the row
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            // Highlight effect
            row.style.backgroundColor = '#e8f5e8';
            row.style.boxShadow = '0 0 0 2px #4caf50';
            row.style.transform = 'scale(1.02)';
            row.style.transition = 'all 0.3s ease';
            
            // Remove highlight after 3 seconds
            setTimeout(() => {
                row.style.backgroundColor = '';
                row.style.boxShadow = '';
                row.style.transform = '';
            }, 3000);
        }
    });
}

// Play sound notification
function playUserCreationSound(isAdmin = false) {
    try {
        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
        
        // Different sound pattern for admin vs regular user
        const frequencies = isAdmin ? [800, 1000, 1200] : [600, 800];
        const duration = 200;
        
        frequencies.forEach((freq, index) => {
            setTimeout(() => {
                const oscillator = audioContext.createOscillator();
                const gainNode = audioContext.createGain();
                
                oscillator.connect(gainNode);
                gainNode.connect(audioContext.destination);
                
                oscillator.frequency.setValueAtTime(freq, audioContext.currentTime);
                oscillator.type = 'sine';
                
                gainNode.gain.setValueAtTime(0.1, audioContext.currentTime);
                gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + duration / 1000);
                
                oscillator.start(audioContext.currentTime);
                oscillator.stop(audioContext.currentTime + duration / 1000);
            }, index * 150);
        });
    } catch (error) {
        console.log('Audio notification not available');
    }
}

// Enhanced form submission to show popup
function enhanceFormSubmission() {
    const userForm = document.getElementById('userForm');
    
    // Remove any existing listeners by cloning the form
    const newForm = userForm.cloneNode(true);
    userForm.parentNode.replaceChild(newForm, userForm);
    
    newForm.addEventListener('submit', function(e) {
        const formAction = document.getElementById('formAction').name;
        
        if (formAction === 'create_user') {
            const username = document.getElementById('username').value.trim();
            const role = document.getElementById('role').value;
            const userType = document.getElementById('user_type').value;
            const isRCY = userType === 'rcy_member';
            
            const userData = {
                username: username,
                role: role,
                userType: userType,
                isRCY: isRCY,
                timestamp: Date.now()
            };
            
            sessionStorage.setItem('newUserData', JSON.stringify(userData));
            sessionStorage.setItem('shouldCheckPopup', 'true');
        }
    });
}
// Add a more aggressive popup check function
function forceCheckPopup() {
    const shouldCheck = sessionStorage.getItem('shouldCheckPopup');
    const storedUserData = sessionStorage.getItem('newUserData');
    
    console.log('Force checking popup. Should check:', shouldCheck, 'Has data:', !!storedUserData);
    
    if (shouldCheck === 'true' && storedUserData) {
        try {
            const userData = JSON.parse(storedUserData);
            
            // Check if the data is recent (within last 30 seconds)
            if (userData.timestamp && (Date.now() - userData.timestamp) < 30000) {
                console.log('Force showing popup for recent user creation:', userData);
                
                showUserCreationPopup(
                    userData.username,
                    userData.role,
                    userData.userType,
                    userData.isRCY
                );
                
                // Clear flags
                sessionStorage.removeItem('newUserData');
                sessionStorage.removeItem('shouldCheckPopup');
            } else {
                console.log('Stored data is too old, clearing...');
                sessionStorage.removeItem('newUserData');
                sessionStorage.removeItem('shouldCheckPopup');
            }
        } catch (error) {
            console.error('Error in force check:', error);
            sessionStorage.removeItem('newUserData');
            sessionStorage.removeItem('shouldCheckPopup');
        }
    }
}

// Check for successful user creation on page load
function checkForNewUserCreation() {
    console.log('Checking for new user creation...');
    
    // Check if there's a success message and stored user data
    const successAlert = document.querySelector('.alert.success');
    const storedUserData = sessionStorage.getItem('newUserData');
    
    console.log('Success alert found:', !!successAlert);
    console.log('Stored user data:', storedUserData);
    
    if (successAlert) {
        console.log('Success alert text:', successAlert.textContent);
    }
    
    if (successAlert && storedUserData && successAlert.textContent.includes('created successfully')) {
        try {
            const userData = JSON.parse(storedUserData);
            console.log('Showing popup for user:', userData);
            
            // Show the popup
            setTimeout(() => {
                showUserCreationPopup(
                    userData.username,
                    userData.role,
                    userData.userType,
                    userData.isRCY
                );
            }, 500); // Small delay to let the page settle
            
            // Clear the stored data
            sessionStorage.removeItem('newUserData');
            
        } catch (error) {
            console.error('Error parsing stored user data:', error);
            sessionStorage.removeItem('newUserData');
        }
    } else {
        // Alternative check - if there's stored data but no alert yet, 
        // it might be a page refresh after creation
        if (storedUserData) {
            console.log('Found stored user data without alert, checking URL...');
            
            // Check if we're on the same page (no redirect happened)
            if (window.location.href.includes('manage_users.php')) {
                try {
                    const userData = JSON.parse(storedUserData);
                    console.log('Showing popup from stored data:', userData);
                    
                    // Show popup anyway - the success might not be visible yet
                    setTimeout(() => {
                        showUserCreationPopup(
                            userData.username,
                            userData.role,
                            userData.userType,
                            userData.isRCY
                        );
                    }, 1000); // Longer delay
                    
                    // Clear the stored data
                    sessionStorage.removeItem('newUserData');
                    
                } catch (error) {
                    console.error('Error parsing stored user data:', error);
                    sessionStorage.removeItem('newUserData');
                }
            }
        }
    }
}

// Utility function for contact info display
function formatContactInfo(email, phone) {
    let html = '';
    if (email) {
        html += `<div class="contact-item"><i class="fas fa-envelope"></i> ${email}</div>`;
    }
    if (phone) {
        html += `<div class="contact-item"><i class="fas fa-phone"></i> ${phone}</div>`;
    }
    return html || '<span class="text-muted">No contact info</span>';
}

// Enhanced keyboard navigation
document.addEventListener('keydown', function(e) {
    // Alt + N to create new user
    if (e.altKey && e.key === 'n') {
        e.preventDefault();
        openCreateModal();
    }
    
    // Alt + S to focus search
    if (e.altKey && e.key === 's') {
        e.preventDefault();
        document.getElementById('userSearch').focus();
    }
});

// CSS styles for the popup
const userPopupStyles = `
<style>
.user-creation-popup {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
}

.user-creation-popup.show {
    opacity: 1;
    visibility: visible;
}

.user-creation-popup.hide {
    opacity: 0;
    visibility: hidden;
}

.popup-content {
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
    max-width: 500px;
    width: 90%;
    margin: 20px;
    transform: scale(0.9) translateY(20px);
    transition: transform 0.3s ease;
    overflow: hidden;
}

.user-creation-popup.show .popup-content {
    transform: scale(1) translateY(0);
}

.popup-header {
    background: linear-gradient(135deg, #4caf50, #45a049);
    color: white;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 15px;
    position: relative;
}

.popup-content.admin-created .popup-header {
    background: linear-gradient(135deg, #ff9800, #f57c00);
}

.popup-content.rcy-created .popup-header {
    background: linear-gradient(135deg, #2196f3, #1976d2);
}

.popup-icon {
    width: 50px;
    height: 50px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    flex-shrink: 0;
}

.popup-header h3 {
    margin: 0;
    font-size: 1.3rem;
    font-weight: 600;
    flex: 1;
}

.popup-close {
    background: none;
    border: none;
    color: white;
    font-size: 1.2rem;
    cursor: pointer;
    padding: 8px;
    border-radius: 50%;
    transition: background-color 0.2s ease;
    position: absolute;
    top: 15px;
    right: 15px;
}

.popup-close:hover {
    background: rgba(255, 255, 255, 0.2);
}

.popup-body {
    padding: 25px;
}

.created-user-details {
    font-size: 1.1rem;
    color: #333;
    margin-bottom: 20px;
    text-align: center;
    line-height: 1.5;
}

.created-user-details strong {
    color: #2e7d32;
    font-weight: 600;
}

.popup-actions {
    display: flex;
    gap: 12px;
    justify-content: center;
    margin-top: 20px;
}

.btn-view-user,
.btn-create-another {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.9rem;
    font-weight: 500;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.btn-view-user {
    background: #2196f3;
    color: white;
}

.btn-view-user:hover {
    background: #1976d2;
    transform: translateY(-1px);
}

.btn-create-another {
    background: #f5f5f5;
    color: #666;
    border: 1px solid #ddd;
}

.btn-create-another:hover {
    background: #e8e8e8;
    color: #333;
    transform: translateY(-1px);
}

.popup-notification-info {
    background: #f8f9fa;
    border-top: 1px solid #e9ecef;
    padding: 12px 20px;
    text-align: center;
    color: #666;
    font-size: 0.85rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.popup-notification-info i {
    color: #4caf50;
}

/* Animation for highlighted user row */
@keyframes highlightUser {
    0% { 
        background-color: #e8f5e8;
        transform: scale(1);
    }
    50% { 
        background-color: #c8e6c9;
        transform: scale(1.02);
    }
    100% { 
        background-color: #e8f5e8;
        transform: scale(1);
    }
}

/* Responsive design */
@media (max-width: 600px) {
    .popup-content {
        margin: 10px;
        width: calc(100% - 20px);
    }
    
    .popup-header {
        padding: 15px;
    }
    
    .popup-body {
        padding: 20px;
    }
    
    .popup-actions {
        flex-direction: column;
    }
    
    .btn-view-user,
    .btn-create-another {
        width: 100%;
        justify-content: center;
    }
}
</style>
`;

// Inject the styles
if (typeof document !== 'undefined') {
    document.head.insertAdjacentHTML('beforeend', userPopupStyles);
}

// Initialize Enhanced Features
document.addEventListener('DOMContentLoaded', function() {
    setupSearch();
    setupServiceCheckboxes();
    setupModalEvents();
    setupFormValidation();
    toggleServicesSection(); // Initialize services section visibility
    
     // Auto-dismiss alerts
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(() => {
                alert.remove();
            }, 300);
        }, 5000);
    });
    
    // Initialize popup functionality
    enhanceFormSubmission();
    
    // Multiple checks for popup
    setTimeout(() => {
        checkForNewUserCreation();
    }, 100);
    
    setTimeout(() => {
        forceCheckPopup();
    }, 1500);
    
    // Emergency check after 3 seconds
    setTimeout(() => {
        const storedData = sessionStorage.getItem('newUserData');
        if (storedData) {
            console.log('Emergency popup check triggered');
            forceCheckPopup();
        }
    }, 3000);
});

// Test function - you can call this in browser console to test the popup
function testPopup() {
    console.log('Testing popup...');
    showUserCreationPopup('test_user', 'user', 'non_rcy_member', false);
}

// Debug function to clear stored data
function clearStoredPopupData() {
    sessionStorage.removeItem('newUserData');
    sessionStorage.removeItem('shouldCheckPopup');
    console.log('Cleared stored popup data');
}
    </script>