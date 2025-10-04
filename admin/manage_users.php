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
        $rcy_role = trim($_POST['rcy_role'] ?? '');
        $services = $_POST['services'] ?? [];

       if ($username && $password && $full_name && in_array($role, ['admin','user'])) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $servicesJson = $user_type === 'rcy_member' ? json_encode($services) : null;
            
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, password_hash, full_name, first_name, last_name, role, user_type, email, phone, gender, services, rcy_role)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$username, $hash, $full_name, $first_name, $last_name, $role, $user_type, $email, $phone, $gender, $servicesJson, $rcy_role]);
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
        $rcy_role = trim($_POST['rcy_role'] ?? '');
        $gender = $_POST['gender'] ?? null;
        $password = $_POST['password'];
        $services = $_POST['services'] ?? [];

        if ($user_id && $full_name && in_array($role, ['admin','user'])) {
            $servicesJson = $user_type === 'rcy_member' ? json_encode($services) : null;
            
            try {
                if (!empty($password)) {
                    // Update with new password
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET full_name = ?, first_name = ?, last_name = ?, role = ?, 
                            user_type = ?, email = ?, phone = ?, gender = ?, 
                            services = ?, rcy_role = ?, password_hash = ?
                        WHERE user_id = ?
                    ");
                    $stmt->execute([
                        $full_name, $first_name, $last_name, $role, 
                        $user_type, $email, $phone, $gender, 
                        $servicesJson, $rcy_role, $hash, $user_id
                    ]);
                } else {
                    // Update without changing password
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET full_name = ?, first_name = ?, last_name = ?, role = ?, 
                            user_type = ?, email = ?, phone = ?, gender = ?, 
                            services = ?, rcy_role = ?
                        WHERE user_id = ?
                    ");
                    $stmt->execute([
                        $full_name, $first_name, $last_name, $role, 
                        $user_type, $email, $phone, $gender, 
                        $servicesJson, $rcy_role, $user_id
                    ]);
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
            } catch (Exception $e) {
                $errorMessage = "Error updating user: " . $e->getMessage();
            }
        } else {
            $errorMessage = "Invalid data for update.";
        }
    }
    elseif (isset($_POST['delete_user'])) {
        $user_id = (int)$_POST['user_id'];
        if ($user_id) {
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $successMessage = "User deleted successfully.";
            } catch (Exception $e) {
                $errorMessage = "Error deleting user: " . $e->getMessage();
            }
        }
    }
}

// Build query with role filter - FIXED VERSION
$whereClause = '';
$orderClause = 'ORDER BY u.username';
$params = [];

if ($roleFilter === 'new') {
    $orderClause = 'ORDER BY u.created_at DESC';
    // No WHERE clause needed - show all users but sorted by newest first
} elseif ($roleFilter !== 'all') {
    $whereClause = 'WHERE u.role = ?';
    $params[] = $roleFilter;
}

// Get filtered users with their services
try {
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.full_name, u.first_name, u.last_name, 
               u.role, u.admin_role, u.user_type, u.email, u.phone, u.gender, 
               u.services, u.rcy_role, u.created_at,
               GROUP_CONCAT(us.service_type) as user_services
        FROM users u
        LEFT JOIN user_services us ON u.user_id = us.user_id
        $whereClause
        GROUP BY u.user_id
        $orderClause
    ");
    $stmt->execute($params);
    $users = $stmt->fetchAll();
} catch (Exception $e) {
    $errorMessage = "Error loading users: " . $e->getMessage();
    $users = [];
}

// Get statistics
try {
    $admin_count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    $user_count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
    $rcy_member_count = $pdo->query("SELECT COUNT(*) FROM users WHERE user_type = 'rcy_member'")->fetchColumn();
    $non_rcy_member_count = $pdo->query("SELECT COUNT(*) FROM users WHERE user_type = 'non_rcy_member'")->fetchColumn();
    $total_users = $admin_count + $user_count;
    
    // Get new users count (created within 7 days)
    $new_users_count = $pdo->query("
        SELECT COUNT(*) FROM users 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ")->fetchColumn();
} catch (Exception $e) {
    $admin_count = $user_count = $rcy_member_count = $non_rcy_member_count = $total_users = $new_users_count = 0;
}

// Service names mapping
$serviceNames = [
    'health' => 'Health Services',
    'safety' => 'Safety Services',
    'welfare' => 'Welfare Services',
    'disaster_management' => 'Disaster Management',
    'red_cross_youth' => 'Red Cross Youth'
];

function isNewUser($createdAt) {
    if (!$createdAt) return false;
    try {
        $createdDate = new DateTime($createdAt);
        $now = new DateTime();
        $daysDiff = $now->diff($createdDate)->days;
        return $daysDiff <= 7; // Consider users created within 7 days as "new"
    } catch (Exception $e) {
        return false;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - PRC Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/sidebar_admin.css?v=<?= time() ?>">
    <link rel="stylesheet" href="../assets/styles.css?v=<?= time() ?>">
    <link rel="stylesheet" href="../assets/header.css?v=<?= time() ?>">
    <link rel="stylesheet" href="../assets/admin_users.css?v=<?= time() ?>">
</head>

<body>
    <?php include 'sidebar.php'; ?>
   
    <div class="users-container">

        <!-- Compact Hero Header -->
        <div class="page-hero-compact">
            <div class="hero-gradient"></div>
            <div class="hero-content">
                <div class="hero-left">
                    <div class="hero-badge">
                        <i class="fas fa-users-cog"></i>
                        <span>USER MANAGEMENT</span>
                    </div>
                    <h1>User <span class="title-highlight">Administration</span></h1>
                    <p class="hero-subtitle">Create, manage, and organize system users</p>
                </div>
                <div class="hero-right">
                    <div class="hero-stats-mini">
                        <div class="stat-mini">
                            <div class="stat-number"><?= $total_users ?></div>
                            <div class="stat-label">Total Users</div>
                        </div>
                        <div class="stat-mini">
                            <div class="stat-number"><?= $admin_count ?></div>
                            <div class="stat-label">Admins</div>
                        </div>
                        <div class="stat-mini">
                            <div class="stat-number"><?= $rcy_member_count ?></div>
                            <div class="stat-label">RCY Members</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if ($errorMessage): ?>
            <div class="alert-compact error">
                <i class="fas fa-exclamation-triangle"></i>
                <div class="alert-content">
                    <strong>Error:</strong> <?= htmlspecialchars($errorMessage) ?>
                </div>
                <button class="alert-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>
        
        <?php if ($successMessage): ?>
            <div class="alert-compact success">
                <i class="fas fa-check-circle"></i>
                <div class="alert-content">
                    <strong>Success:</strong> <?= htmlspecialchars($successMessage) ?>
                </div>
                <button class="alert-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>

        <!-- Compact Action Bar -->
        <div class="action-bar-compact">
            <div class="search-filter-row">
                <!-- Search -->
                <div class="search-box-compact">
                    <i class="fas fa-search search-icon"></i>
                    <input 
                        type="text" 
                        class="search-input-compact" 
                        id="userSearch" 
                        placeholder="Search users..."
                        autocomplete="off"
                    >
                    <button class="search-clear-compact" id="searchClear" type="button">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <!-- Filter Chips -->
                <div class="filter-chips-compact">
                    <a href="?role=all" class="chip-filter <?= $roleFilter === 'all' ? 'active' : '' ?>">
                        <i class="fas fa-users"></i>
                        <span>All</span>
                        <span class="chip-count"><?= $total_users ?></span>
                    </a>
                    <a href="?role=new" class="chip-filter <?= $roleFilter === 'new' ? 'active' : '' ?>">
                        <i class="fas fa-user-plus"></i>
                        <span>New</span>
                        <span class="chip-count"><?= $new_users_count ?></span>
                    </a>
                    <a href="?role=admin" class="chip-filter <?= $roleFilter === 'admin' ? 'active' : '' ?>">
                        <i class="fas fa-user-shield"></i>
                        <span>Admins</span>
                        <span class="chip-count"><?= $admin_count ?></span>
                    </a>
                    <a href="?role=user" class="chip-filter <?= $roleFilter === 'user' ? 'active' : '' ?>">
                        <i class="fas fa-user"></i>
                        <span>Users</span>
                        <span class="chip-count"><?= $user_count ?></span>
                    </a>
                </div>
            </div>
            
            <!-- Create Button -->
            <button class="btn-create-compact" onclick="openCreateModal()" type="button">
                <i class="fas fa-plus"></i>
                <span>Create User</span>
            </button>
        </div>

        <!-- Quick Stats Row -->
        <div class="quick-stats-row">
            <div class="stat-box">
                <div class="stat-icon-mini events"><i class="fas fa-users"></i></div>
                <div class="stat-info-mini">
                    <div class="stat-value-mini"><?= $total_users ?></div>
                    <div class="stat-label-mini">Total Users</div>
                </div>
            </div>
            
            <div class="stat-box">
                <div class="stat-icon-mini users"><i class="fas fa-user-shield"></i></div>
                <div class="stat-info-mini">
                    <div class="stat-value-mini"><?= $admin_count ?></div>
                    <div class="stat-label-mini">Administrators</div>
                </div>
            </div>
            
            <div class="stat-box">
                <div class="stat-icon-mini training"><i class="fas fa-user"></i></div>
                <div class="stat-info-mini">
                    <div class="stat-value-mini"><?= $user_count ?></div>
                    <div class="stat-label-mini">Regular Users</div>
                </div>
            </div>
            
            <div class="stat-box">
                <div class="stat-icon-mini volunteers"><i class="fas fa-hands-helping"></i></div>
                <div class="stat-info-mini">
                    <div class="stat-value-mini"><?= $rcy_member_count ?></div>
                    <div class="stat-label-mini">RCY Members</div>
                </div>
            </div>
        </div>

        <!-- Users Table Card -->
        <div class="table-card-compact">
            <div class="table-card-header">
                <div class="header-left">
                    <h2>
                        <i class="fas fa-table"></i>
                        <?php if ($roleFilter === 'all'): ?>
                            All System Users
                        <?php elseif ($roleFilter === 'new'): ?>
                            New Users
                        <?php elseif ($roleFilter === 'admin'): ?>
                            Administrators
                        <?php else: ?>
                            Regular Users
                        <?php endif; ?>
                    </h2>
                </div>
                <div class="header-right">
                    <span class="results-badge"><?= count($users) ?> results</span>
                </div>
            </div>
            
            <?php if (empty($users)): ?>
                <div class="empty-state-compact">
                    <i class="fas fa-user-slash"></i>
                    <h3>No Users Found</h3>
                    <p>
                        <?php if ($roleFilter === 'new'): ?>
                            All users have been in the system for more than 7 days
                        <?php else: ?>
                            Click "Create User" to add users to the system
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="table-scroll-compact">
                    <table class="data-table-compact" id="usersTable">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Type</th>
                                <th>Gender</th>
                                <th>RCY Role</th>
                                <th>Services</th>
                                <th>Contact</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                                <tr data-user-id="<?= $u['user_id'] ?>">
                                    <td>
                                        <div class="user-cell">
                                            <div class="user-avatar-mini">
                                                <?= strtoupper(substr($u['full_name'] ?: $u['username'], 0, 1)) ?>
                                            </div>
                                            <div class="user-info-mini">
                                                <div class="user-name-mini">
                                                    <?= htmlspecialchars($u['full_name']) ?>
                                                    <?php if (isNewUser($u['created_at'])): ?>
                                                        <span class="badge-new">NEW</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="user-username-mini">@<?= htmlspecialchars($u['username']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge-role <?= $u['role'] === 'admin' ? $u['admin_role'] ?? 'admin' : $u['role'] ?>">
                                            <?php 
                                            if ($u['role'] === 'admin' && $u['admin_role']): 
                                                switch($u['admin_role']) {
                                                    case 'safety': echo '<i class="fas fa-shield-alt"></i> Safety'; break;
                                                    case 'disaster': echo '<i class="fas fa-exclamation-triangle"></i> Disaster'; break;
                                                    case 'health': echo '<i class="fas fa-heartbeat"></i> Health'; break;
                                                    case 'welfare': echo '<i class="fas fa-hand-holding-heart"></i> Welfare'; break;
                                                    case 'youth': echo '<i class="fas fa-users"></i> Youth'; break;
                                                    case 'super': echo '<i class="fas fa-crown"></i> Super'; break;
                                                    default: echo '<i class="fas fa-user-shield"></i> Admin'; break;
                                                }
                                            elseif ($u['role'] === 'admin'): 
                                                echo '<i class="fas fa-user-shield"></i> Admin';
                                            else: 
                                                echo '<i class="fas fa-user"></i> User';
                                            endif; 
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($u['user_type'] === 'rcy_member'): ?>
                                            <span class="badge-type rcy">
                                                <i class="fas fa-users"></i> RCY
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-type non-rcy">
                                                <i class="fas fa-user"></i> Non-RCY
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($u['gender']): ?>
                                            <span class="badge-gender <?= $u['gender'] ?>">
                                                <?= ucfirst($u['gender']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted-mini">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($u['user_type'] === 'rcy_member' && $u['rcy_role']): ?>
                                            <span class="badge-rcy-role <?= $u['rcy_role'] ?>">
                                                <?= ucfirst($u['rcy_role']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted-mini">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($u['user_type'] === 'rcy_member' && $u['user_services']): ?>
                                            <div class="services-tags">
                                                <?php 
                                                $services = explode(',', $u['user_services']);
                                                foreach (array_slice($services, 0, 2) as $service): 
                                                    $serviceName = $serviceNames[trim($service)] ?? ucfirst(str_replace('_', ' ', trim($service)));
                                                ?>
                                                    <span class="tag-service"><?= htmlspecialchars($serviceName) ?></span>
                                                <?php endforeach; ?>
                                                <?php if (count($services) > 2): ?>
                                                    <span class="tag-more">+<?= count($services) - 2 ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted-mini">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="contact-cell">
                                            <?php if ($u['email']): ?>
                                                <div class="contact-item-mini">
                                                    <i class="fas fa-envelope"></i>
                                                    <?= htmlspecialchars($u['email']) ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($u['phone']): ?>
                                                <div class="contact-item-mini">
                                                    <i class="fas fa-phone"></i>
                                                    <?= htmlspecialchars($u['phone']) ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!$u['email'] && !$u['phone']): ?>
                                                <span class="text-muted-mini">No contact</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-buttons-compact">
                                            <button 
                                                class="btn-action-mini edit" 
                                                onclick="openEditModal(<?= htmlspecialchars(json_encode($u)) ?>)"
                                                type="button"
                                                title="Edit"
                                            >
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            
                                            <button 
                                                class="btn-action-mini docs" 
                                                onclick="viewDocuments(<?= $u['user_id'] ?>, '<?= htmlspecialchars($u['username']) ?>')"
                                                type="button"
                                                title="Documents"
                                            >
                                                <i class="fas fa-file-alt"></i>
                                            </button>
                                            
                                            <form method="POST" class="inline-form" onsubmit="return confirmDelete('<?= htmlspecialchars($u['username']) ?>')">
                                                <input type="hidden" name="delete_user" value="1">
                                                <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                                <button 
                                                    type="submit" 
                                                    class="btn-action-mini delete"
                                                    title="Delete"
                                                >
                                                    <i class="fas fa-trash-alt"></i>
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

                    <!-- Gender and RCY Role Row -->
                    <div class="form-row">
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
                        
                        <div class="form-group" id="rcyRoleSection">
                            <label for="rcy_role" class="form-label">RCY Role</label>
                            <select id="rcy_role" name="rcy_role" class="form-select">
                                <option value="">Select RCY Role (Optional)</option>
                                <option value="adviser">Adviser</option>
                                <option value="member">Member</option>
                            </select>
                        </div>
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
      <?php include 'chat_widget.php'; ?>
        <?php include 'floating_notification_widget.php'; ?>
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
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
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
            const rcyRoleSection = document.getElementById('rcyRoleSection');
            
            if (userType === 'rcy_member') {
                servicesSection.classList.add('show');
                rcyRoleSection.classList.add('show');
                rcyRoleSection.style.display = 'block';
            } else {
                servicesSection.classList.remove('show');
                rcyRoleSection.classList.remove('show');
                rcyRoleSection.style.display = 'none';
                
                // Clear all checkboxes when not RCY member
                const checkboxes = servicesSection.querySelectorAll('input[type="checkbox"]');
                checkboxes.forEach(cb => {
                    cb.checked = false;
                    cb.closest('.service-checkbox').classList.remove('checked');
                });
                
                // Clear RCY role
                document.getElementById('rcy_role').value = '';
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
            const rcyRole = user.rcy_role || '';
            
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
            document.getElementById('rcy_role').value = rcyRole;
            
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
        });
    </script>
</body>
</html>