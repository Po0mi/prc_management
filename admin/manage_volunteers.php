<?php
require_once __DIR__ . '/../config.php';
ensure_logged_in();

$pdo = $GLOBALS['pdo'];
$errorMessage = '';
$successMessage = '';

// Get current user's service access
$current_user_id = $_SESSION['user_id'];
$current_user_role = current_user_role();
$is_super_admin = ($current_user_role === 'admin');

// Handle API requests
if (isset($_GET['api']) && $_GET['api'] === 'geographic') {
    header('Content-Type: application/json');
    
    try {
        // Get geographic distribution
        $geographic_sql = "
            SELECT 
                COALESCE(barangay, 'Not Specified') as barangay,
                COALESCE(municipality, 'Not Specified') as municipality,
                COALESCE(city, 'Not Specified') as city,
                service,
                status,
                COUNT(*) as count
            FROM volunteers 
        ";
        
        $where_conditions = [];
        $params = [];
        
        // Add service access restriction for non-super-admin users
        if (!$is_super_admin) {
            $stmt = $pdo->prepare("
                SELECT service, access_level 
                FROM user_service_access 
                WHERE user_id = ? AND access_level IN ('admin', 'write')
            ");
            $stmt->execute([$current_user_id]);
            $access_results = $stmt->fetchAll();
            
            $user_service_access = [];
            foreach ($access_results as $access) {
                $user_service_access[] = $access['service'];
            }
            
            if (!empty($user_service_access)) {
                $placeholders = str_repeat('?,', count($user_service_access) - 1) . '?';
                $where_conditions[] = "service IN ($placeholders)";
                $params = array_merge($params, $user_service_access);
            }
        }
        
        if (!empty($where_conditions)) {
            $geographic_sql .= " WHERE " . implode(" AND ", $where_conditions);
        }
        
        $geographic_sql .= " GROUP BY barangay, municipality, city, service, status ORDER BY city, municipality, barangay";
        
        $stmt = $pdo->prepare($geographic_sql);
        $stmt->execute($params);
        $geographic_data = $stmt->fetchAll();
        
        // Process data for better structure
        $locations = [];
        foreach ($geographic_data as $row) {
            $location_key = $row['barangay'] . '|' . $row['municipality'] . '|' . $row['city'];
            
            if (!isset($locations[$location_key])) {
                $locations[$location_key] = [
                    'barangay' => $row['barangay'],
                    'municipality' => $row['municipality'],
                    'city' => $row['city'],
                    'total_volunteers' => 0,
                    'current_volunteers' => 0,
                    'graduated_volunteers' => 0,
                    'services' => []
                ];
            }
            
            $locations[$location_key]['total_volunteers'] += $row['count'];
            
            if ($row['status'] === 'current') {
                $locations[$location_key]['current_volunteers'] += $row['count'];
            } else {
                $locations[$location_key]['graduated_volunteers'] += $row['count'];
            }
            
            if (!isset($locations[$location_key]['services'][$row['service']])) {
                $locations[$location_key]['services'][$row['service']] = [
                    'current' => 0,
                    'graduated' => 0,
                    'total' => 0
                ];
            }
            
            $locations[$location_key]['services'][$row['service']][$row['status']] += $row['count'];
            $locations[$location_key]['services'][$row['service']]['total'] += $row['count'];
        }
        
        echo json_encode(['success' => true, 'data' => array_values($locations)]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Get user's service access permissions
$user_service_access = [];
if (!$is_super_admin) {
    $stmt = $pdo->prepare("
        SELECT service, access_level 
        FROM user_service_access 
        WHERE user_id = ? AND access_level IN ('admin', 'write')
    ");
    $stmt->execute([$current_user_id]);
    $access_results = $stmt->fetchAll();
    
    foreach ($access_results as $access) {
        $user_service_access[] = $access['service'];
    }
    
    // If user has no service access, deny access
    if (empty($user_service_access)) {
        header('Location: dashboard.php?error=access_denied');
        exit;
    }
}

// Define services
$services = [
    'first_aid' => 'First Aid',
    'disaster_response' => 'Disaster Response', 
    'blood_services' => 'Blood Services',
    'safety_services' => 'Safety Services',
    'youth_services' => 'Youth Services',
    'welfare_services' => 'Welfare Services'
];

// Filter services based on user access
$accessible_services = $services;
if (!$is_super_admin) {
    $accessible_services = array_intersect_key($services, array_flip($user_service_access));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_volunteer'])) {
        $full_name = trim($_POST['full_name']);
        $age = (int)$_POST['age'];
        $location = trim($_POST['location']);
        $barangay = trim($_POST['barangay']);
        $municipality = trim($_POST['municipality']);
        $city = trim($_POST['city']);
        $contact_number = trim($_POST['contact_number']);
        $service = $_POST['service'];
        $status = $_POST['status'];
        $email = trim($_POST['email']);
        $notes = trim($_POST['notes']);

        // Check if user has access to this service
        if (!$is_super_admin && !in_array($service, $user_service_access)) {
            $errorMessage = "You don't have permission to add volunteers to this service.";
        } elseif ($full_name && $age && $contact_number && array_key_exists($service, $accessible_services) && in_array($status, ['current', 'graduated'])) {
            $stmt = $pdo->prepare("
                INSERT INTO volunteers (full_name, age, location, barangay, municipality, city, contact_number, service, status, email, notes, created_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ");
            try {
                $stmt->execute([$full_name, $age, $location, $barangay, $municipality, $city, $contact_number, $service, $status, $email, $notes, $current_user_id]);
                $successMessage = "Volunteer '$full_name' added successfully!";
            } catch (Exception $e) {
                $errorMessage = "Error adding volunteer: " . $e->getMessage();
            }
        } else {
            $errorMessage = "All required fields must be filled with valid data.";
        }
    }
    elseif (isset($_POST['update_volunteer'])) {
        $volunteer_id = (int)$_POST['volunteer_id'];
        $full_name = trim($_POST['full_name']);
        $age = (int)$_POST['age'];
        $location = trim($_POST['location']);
        $barangay = trim($_POST['barangay']);
        $municipality = trim($_POST['municipality']);
        $city = trim($_POST['city']);
        $contact_number = trim($_POST['contact_number']);
        $service = $_POST['service'];
        $status = $_POST['status'];
        $email = trim($_POST['email']);
        $notes = trim($_POST['notes']);

        // Check if user has access to this service
        if (!$is_super_admin && !in_array($service, $user_service_access)) {
            $errorMessage = "You don't have permission to edit volunteers in this service.";
        } elseif ($volunteer_id && $full_name && $age && $contact_number && array_key_exists($service, $accessible_services) && in_array($status, ['current', 'graduated'])) {
            // Verify user has access to the volunteer's current service
            $stmt = $pdo->prepare("SELECT service FROM volunteers WHERE volunteer_id = ?");
            $stmt->execute([$volunteer_id]);
            $current_service = $stmt->fetchColumn();
            
            if (!$is_super_admin && !in_array($current_service, $user_service_access)) {
                $errorMessage = "You don't have permission to edit this volunteer.";
            } else {
                $stmt = $pdo->prepare("
                    UPDATE volunteers
                    SET full_name = ?, age = ?, location = ?, barangay = ?, municipality = ?, city = ?, contact_number = ?, service = ?, status = ?, email = ?, notes = ?, updated_at = NOW(), updated_by = ?
                    WHERE volunteer_id = ?
                ");
                $stmt->execute([$full_name, $age, $location, $barangay, $municipality, $city, $contact_number, $service, $status, $email, $notes, $current_user_id, $volunteer_id]);
                $successMessage = "Volunteer updated successfully!";
            }
        } else {
            $errorMessage = "Invalid data for update.";
        }
    }
    elseif (isset($_POST['delete_volunteer'])) {
        $volunteer_id = (int)$_POST['volunteer_id'];
        if ($volunteer_id) {
            // Verify user has access to delete this volunteer
            $stmt = $pdo->prepare("SELECT service FROM volunteers WHERE volunteer_id = ?");
            $stmt->execute([$volunteer_id]);
            $volunteer_service = $stmt->fetchColumn();
            
            if (!$is_super_admin && !in_array($volunteer_service, $user_service_access)) {
                $errorMessage = "You don't have permission to delete this volunteer.";
            } else {
                $stmt = $pdo->prepare("DELETE FROM volunteers WHERE volunteer_id = ?");
                $stmt->execute([$volunteer_id]);
                $successMessage = "Volunteer deleted successfully.";
            }
        }
    }
}

// Get volunteers with filters
$service_filter = isset($_GET['service']) ? $_GET['service'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$location_filter = isset($_GET['location']) ? $_GET['location'] : '';

// Validate filters against user access
if ($service_filter && !$is_super_admin && !in_array($service_filter, $user_service_access)) {
    $service_filter = '';
}

$where_conditions = [];
$params = [];

// Add service access restriction for non-super-admin users
if (!$is_super_admin) {
    $placeholders = str_repeat('?,', count($user_service_access) - 1) . '?';
    $where_conditions[] = "service IN ($placeholders)";
    $params = array_merge($params, $user_service_access);
}

if ($service_filter && array_key_exists($service_filter, $accessible_services)) {
    $where_conditions[] = "service = ?";
    $params[] = $service_filter;
}

if ($status_filter && in_array($status_filter, ['current', 'graduated'])) {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
}

if ($location_filter) {
    if ($location_filter === 'no_location') {
        $where_conditions[] = "(barangay IS NULL OR barangay = '' OR municipality IS NULL OR municipality = '' OR city IS NULL OR city = '')";
    } else {
        // Format: "barangay|municipality|city"
        $location_parts = explode('|', $location_filter);
        if (count($location_parts) >= 3) {
            $where_conditions[] = "barangay = ? AND municipality = ? AND city = ?";
            $params[] = $location_parts[0];
            $params[] = $location_parts[1];
            $params[] = $location_parts[2];
        }
    }
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

$stmt = $pdo->prepare("
    SELECT volunteer_id, full_name, age, location, barangay, municipality, city, contact_number, service, status, email, notes, created_at
    FROM volunteers 
    $where_clause
    ORDER BY full_name
");
$stmt->execute($params);
$volunteers = $stmt->fetchAll();

// Get statistics (only for accessible services)
$stats = [];
foreach ($accessible_services as $service_key => $service_name) {
    $current_count = $pdo->prepare("SELECT COUNT(*) FROM volunteers WHERE service = ? AND status = 'current'");
    $current_count->execute([$service_key]);
    $graduated_count = $pdo->prepare("SELECT COUNT(*) FROM volunteers WHERE service = ? AND status = 'graduated'");
    $graduated_count->execute([$service_key]);
    
    $stats[$service_key] = [
        'current' => $current_count->fetchColumn(),
        'graduated' => $graduated_count->fetchColumn(),
        'total' => $current_count->fetchColumn() + $graduated_count->fetchColumn()
    ];
}

// Calculate totals for accessible services only
if ($is_super_admin) {
    $total_current = $pdo->query("SELECT COUNT(*) FROM volunteers WHERE status = 'current'")->fetchColumn();
    $total_graduated = $pdo->query("SELECT COUNT(*) FROM volunteers WHERE status = 'graduated'")->fetchColumn();
    $total_with_location = $pdo->query("SELECT COUNT(*) FROM volunteers WHERE barangay IS NOT NULL AND barangay != '' AND municipality IS NOT NULL AND municipality != '' AND city IS NOT NULL AND city != ''")->fetchColumn();
} else {
    $placeholders = str_repeat('?,', count($user_service_access) - 1) . '?';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM volunteers WHERE service IN ($placeholders) AND status = 'current'");
    $stmt->execute($user_service_access);
    $total_current = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM volunteers WHERE service IN ($placeholders) AND status = 'graduated'");
    $stmt->execute($user_service_access);
    $total_graduated = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM volunteers WHERE service IN ($placeholders) AND barangay IS NOT NULL AND barangay != '' AND municipality IS NOT NULL AND municipality != '' AND city IS NOT NULL AND city != ''");
    $stmt->execute($user_service_access);
    $total_with_location = $stmt->fetchColumn();
}

$total_volunteers = $total_current + $total_graduated;

// Get unique locations for filter
$location_stmt = $pdo->prepare("
    SELECT DISTINCT 
        COALESCE(barangay, 'Not Specified') as barangay,
        COALESCE(municipality, 'Not Specified') as municipality,
        COALESCE(city, 'Not Specified') as city,
        COUNT(*) as volunteer_count
    FROM volunteers 
    " . (!$is_super_admin ? "WHERE service IN (" . str_repeat('?,', count($user_service_access) - 1) . "?)" : "") . "
    GROUP BY barangay, municipality, city
    HAVING volunteer_count > 0
    ORDER BY city, municipality, barangay
");

if (!$is_super_admin) {
    $location_stmt->execute($user_service_access);
} else {
    $location_stmt->execute();
}
$unique_locations = $location_stmt->fetchAll();

// Determine page title based on access level
$page_title = $is_super_admin ? 'All Services' : implode(', ', array_map(function($s) use ($services) { return $services[$s]; }, $user_service_access));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Volunteers - PRC Admin</title>
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
  <link rel="stylesheet" href="../assets/manage_volunteers.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  <div class="users-container">
    <div class="page-header">
      <div class="header-content">
        <h1><i class="fas fa-hands-helping"></i> Volunteer Management</h1>
        <p>Manage volunteers across PRC services with advanced filtering and geographic tracking</p>
      </div>
      <div class="branch-indicator">
        <i class="fas fa-layer-group"></i>
        <span><?= $page_title ?></span>
      </div>
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

<!-- Enhanced Action Bar with Dropdown Filters -->
<div class="action-bar">
  <div class="action-bar-left">
    <!-- Search and Create Button Row -->
    <div class="search-filter-row">
      <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" id="volunteerSearch" placeholder="Search volunteers by name, location, or contact...">
      </div>
      
      <button class="btn-create" onclick="openCreateModal()">
        <i class="fas fa-user-plus"></i> Add New Volunteer
      </button>
    </div>
        
       <!-- Filter Controls Row -->
    <div class="filter-row">
      <!-- Service Filter -->
      <?php if (count($accessible_services) > 1): ?>
      <div class="filter-group">
        <label class="filter-label">Service:</label>
        <select onchange="updateServiceFilter(this.value)">
          <option value="">All Services</option>
          <?php foreach ($accessible_services as $key => $name): ?>
            <option value="<?= $key ?>" <?= $service_filter === $key ? 'selected' : '' ?>>
              <?= $name ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
        
       <!-- Status Filter -->
      <div class="filter-group">
        <label class="filter-label">Status:</label>
        <select onchange="updateStatusFilter(this.value)">
          <option value="">All Status</option>
          <option value="current" <?= $status_filter === 'current' ? 'selected' : '' ?>>Current</option>
          <option value="graduated" <?= $status_filter === 'graduated' ? 'selected' : '' ?>>Graduated</option>
        </select>
      </div>
        
  <!-- Location Filter - Now as Dropdown -->
      <div class="filter-group">
        <label class="filter-label">Location:</label>
        <div class="location-dropdown">
          <select onchange="updateLocationFilter(this.value)">
            <option value="">All Locations</option>
            <option value="no_location" <?= $location_filter === 'no_location' ? 'selected' : '' ?>>
              No Location Specified
            </option>
            <?php foreach ($unique_locations as $loc): ?>
              <?php 
              $location_key = $loc['barangay'] . '|' . $loc['municipality'] . '|' . $loc['city'];
              $location_display = ($loc['barangay'] !== 'Not Specified' ? $loc['barangay'] . ', ' : '') . 
                                 ($loc['municipality'] !== 'Not Specified' ? $loc['municipality'] . ', ' : '') . 
                                 ($loc['city'] !== 'Not Specified' ? $loc['city'] : '');
              $location_display = trim($location_display, ', ');
              if (empty($location_display)) $location_display = 'Not Specified';
              ?>
              <option value="<?= $location_key ?>" <?= $location_filter === $location_key ? 'selected' : '' ?>>
                <?= htmlspecialchars($location_display) ?> (<?= $loc['volunteer_count'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      
          <!-- Clear Filters Button -->
      <?php if ($service_filter || $status_filter || $location_filter): ?>
      <div class="filter-group">
        <a href="?" class="btn-clear-filters">
          <i class="fas fa-times-circle"></i> Clear Filters
        </a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

    <!-- Enhanced Statistics Overview -->
    <div class="stats-overview">
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
          <i class="fas fa-users"></i>
        </div>
        <div>
          <div style="font-size: 1.8rem; font-weight: 700; color: var(--dark);"><?= $total_volunteers ?></div>
          <div style="color: var(--gray); font-size: 0.9rem; font-weight: 500;">Total Volunteers</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #00c853 0%, #64dd17 100%);">
          <i class="fas fa-user-check"></i>
        </div>
        <div>
          <div style="font-size: 1.8rem; font-weight: 700; color: var(--dark);"><?= $total_current ?></div>
          <div style="color: var(--gray); font-size: 0.9rem; font-weight: 500;">Active Volunteers</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #ff6b6b 0%, #ff8e53 100%);">
          <i class="fas fa-graduation-cap"></i>
        </div>
        <div>
          <div style="font-size: 1.8rem; font-weight: 700; color: var(--dark);"><?= $total_graduated ?></div>
          <div style="color: var(--gray); font-size: 0.9rem; font-weight: 500;">Graduated</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #2196f3 0%, #21cbf3 100%);">
          <i class="fas fa-map-marker-alt"></i>
        </div>
        <div>
          <div style="font-size: 1.8rem; font-weight: 700; color: var(--dark);"><?= $total_with_location ?></div>
          <div style="color: var(--gray); font-size: 0.9rem; font-weight: 500;">With Location</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #9c27b0 0%, #673ab7 100%);">
          <i class="fas fa-layer-group"></i>
        </div>
        <div>
          <div style="font-size: 1.8rem; font-weight: 700; color: var(--dark);"><?= count($accessible_services) ?></div>
          <div style="color: var(--gray); font-size: 0.9rem; font-weight: 500;"><?= $is_super_admin ? 'Services' : 'My Services' ?></div>
        </div>
      </div>
    </div>

    <!-- Geographic Distribution Section -->
    <div class="users-table-wrapper" id="geographicSection" style="display: none;">
      <div class="table-header">
        <h2 class="table-title">
          <i class="fas fa-map-marked-alt"></i>
          Geographic Distribution
        </h2>
        <button class="btn-toggle" onclick="toggleGeographicView()">
          <i class="fas fa-eye-slash"></i> Hide Geographic View
        </button>
      </div>
      
      <div id="geographicData" class="geographic-grid">
        <div class="loading-state">
          <i class="fas fa-spinner fa-spin"></i>
          <p>Loading geographic data...</p>
        </div>
      </div>
    </div>

    <!-- Toggle Button for Geographic View -->
    <div class="geographic-toggle">
      <button class="btn-toggle" onclick="toggleGeographicView()" id="geographicToggle">
        <i class="fas fa-map-marked-alt"></i> Show Geographic Distribution
      </button>
    </div>

<!-- Enhanced Service Statistics Section -->
<?php if (empty($service_filter) && count($accessible_services) > 1): ?>
<div class="service-stats-section">
  <div class="service-stats-title">
    <h2>
      <i class="fas fa-chart-pie"></i>
      Service Distribution Overview
    </h2>
    <p>Volunteer breakdown across all PRC services</p>
  </div>
  
  <div class="service-stats-compact">
    <?php foreach ($accessible_services as $service_key => $service_name): ?>
      <div class="service-stat-card">
        <div class="service-stat-icon">
          <i class="fas fa-<?= $service_key === 'first_aid' ? 'first-aid' : ($service_key === 'disaster_response' ? 'exclamation-triangle' : ($service_key === 'blood_services' ? 'tint' : ($service_key === 'safety_services' ? 'shield-alt' : ($service_key === 'youth_services' ? 'users' : 'heart')))) ?>"></i>
        </div>
        <div class="service-stat-name"><?= $service_name ?></div>
        <div class="service-stat-numbers">
          <div class="service-stat-item">
            <span class="service-stat-value"><?= $stats[$service_key]['current'] ?></span>
            <span class="service-stat-label">Current</span>
          </div>
          <div class="service-stat-item">
            <span class="service-stat-value"><?= $stats[$service_key]['graduated'] ?></span>
            <span class="service-stat-label">Graduated</span>
          </div>
        </div>
        <a href="?service=<?= $service_key ?>" class="service-stat-view">
          <i class="fas fa-eye"></i> View Details
        </a>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

    <!-- Volunteers Table -->
    <div class="users-table-wrapper">
      <div class="table-header">
        <h2 class="table-title">
          <i class="fas fa-users"></i>
          <?php 
          if ($service_filter && isset($accessible_services[$service_filter])) {
              echo $accessible_services[$service_filter] . ' Volunteers';
          } else {
              echo count($accessible_services) === 1 ? reset($accessible_services) . ' Volunteers' : 'All Volunteers';
          }
          ?>
          <?= $status_filter ? ' - ' . ucfirst($status_filter) : '' ?>
          <?php if ($location_filter && $location_filter !== 'no_location'): ?>
            <?php 
            $location_parts = explode('|', $location_filter);
            $location_display = implode(', ', array_filter($location_parts, function($part) { return $part !== 'Not Specified'; }));
            ?>
            - <?= htmlspecialchars($location_display) ?>
          <?php elseif ($location_filter === 'no_location'): ?>
            - No Location Specified
          <?php endif; ?>
        </h2>
        <div class="table-controls">
          <span class="volunteer-count"><?= count($volunteers) ?> volunteers found</span>
        </div>
      </div>
      
      <?php if (empty($volunteers)): ?>
        <div class="empty-state">
          <i class="fas fa-user-slash"></i>
          <h3>No volunteers found</h3>
          <p>Click "Add New Volunteer" to get started or adjust your filters</p>
        </div>
      <?php else: ?>
        <table class="data-table" id="volunteersTable">
          <thead>
            <tr>
              <th>ID</th>
              <th>Full Name</th>
              <th>Age</th>
              <th>Location Details</th>
              <th>Contact Number</th>
              <?php if (count($accessible_services) > 1): ?>
              <th>Service</th>
              <?php endif; ?>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($volunteers as $v): ?>
              <tr>
                <td style="font-weight: 600; color: var(--gray);"><?= htmlspecialchars($v['volunteer_id']) ?></td>
                <td>
                  <div class="user-info">
                    <div class="user-avatar">
                      <?= strtoupper(substr($v['full_name'], 0, 2)) ?>
                    </div>
                    <div class="user-details">
                      <div class="user-name"><?= htmlspecialchars($v['full_name']) ?></div>
                      <div class="user-email"><?= htmlspecialchars($v['email']) ?></div>
                    </div>
                  </div>
                </td>
                <td style="font-weight: 500;"><?= htmlspecialchars($v['age']) ?></td>
                <td>
                  <div class="location-details">
                    <?php if (!empty($v['barangay']) || !empty($v['municipality']) || !empty($v['city'])): ?>
                      <div class="primary-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php 
                        $location_parts = array_filter([
                          $v['barangay'], 
                          $v['municipality'], 
                          $v['city']
                        ]);
                        echo htmlspecialchars(implode(', ', $location_parts));
                        ?>
                      </div>
                    <?php endif; ?>
                    <?php if (!empty($v['location'])): ?>
                      <div class="secondary-location" style="font-size: 0.85em; color: var(--gray); margin-top: 2px;">
                        <?= htmlspecialchars($v['location']) ?>
                      </div>
                    <?php endif; ?>
                    <?php if (empty($v['barangay']) && empty($v['municipality']) && empty($v['city']) && empty($v['location'])): ?>
                      <span style="color: var(--gray); font-style: italic;">No location specified</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td style="font-family: monospace; font-weight: 500;"><?= htmlspecialchars($v['contact_number']) ?></td>
                <?php if (count($accessible_services) > 1): ?>
                <td>
                  <span class="role-badge moderator">
                    <?= $services[$v['service']] ?>
                  </span>
                </td>
                <?php endif; ?>
                <td>
                  <span class="status-badge <?= $v['status'] === 'current' ? 'active' : 'inactive' ?>">
                    <i class="fas fa-<?= $v['status'] === 'current' ? 'check-circle' : 'graduation-cap' ?>"></i>
                    <?= ucfirst($v['status']) ?>
                  </span>
                </td>
                <td class="actions">
                  <button class="btn-action btn-edit" onclick="openEditModal(<?= htmlspecialchars(json_encode($v)) ?>)">
                    <i class="fas fa-edit"></i> Edit
                  </button>
                  <form method="POST" style="display: inline;" onsubmit="return confirmDelete('<?= htmlspecialchars($v['full_name']) ?>')">
                    <input type="hidden" name="delete_volunteer" value="1">
                    <input type="hidden" name="volunteer_id" value="<?= $v['volunteer_id'] ?>">
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

  <!-- Enhanced Create/Edit Modal -->
  <div class="modal" id="volunteerModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitle">
          <i class="fas fa-user-plus"></i>
          Add New Volunteer
        </h2>
        <button class="close-modal" onclick="closeModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <form method="POST" id="volunteerForm">
        <input type="hidden" name="create_volunteer" value="1" id="formAction">
        <input type="hidden" name="volunteer_id" id="volunteerId">
        
        <div class="form-row">
          <div class="form-group">
            <label for="full_name">
              <i class="fas fa-user"></i>
              Full Name *
            </label>
            <input type="text" id="full_name" name="full_name" required placeholder="Enter full name">
          </div>
          
          <div class="form-group">
            <label for="age">
              <i class="fas fa-birthday-cake"></i>
              Age *
            </label>
            <input type="number" id="age" name="age" required min="16" max="100" placeholder="Enter age">
          </div>
        </div>
        
        <!-- Enhanced Location Fields -->
        <div class="form-section">
          <h3 class="section-title">
            <i class="fas fa-map-marked-alt"></i>
            Location Information
          </h3>
          
          <div class="form-row">
            <div class="form-group">
              <label for="barangay">
                <i class="fas fa-map-pin"></i>
                Barangay
              </label>
              <input type="text" id="barangay" name="barangay" placeholder="Enter barangay">
            </div>
            
            <div class="form-group">
              <label for="municipality">
                <i class="fas fa-building"></i>
                Municipality
              </label>
              <input type="text" id="municipality" name="municipality" placeholder="Enter municipality">
            </div>
            
            <div class="form-group">
              <label for="city">
                <i class="fas fa-city"></i>
                City/Province
              </label>
              <input type="text" id="city" name="city" placeholder="Enter city or province">
            </div>
          </div>
          
          <div class="form-group">
            <label for="location">
              <i class="fas fa-map-marker-alt"></i>
              Complete Address (Optional)
            </label>
            <textarea id="location" name="location" placeholder="Enter complete address or additional location details" rows="2"></textarea>
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label for="contact_number">
              <i class="fas fa-phone"></i>
              Contact Number *
            </label>
            <input type="tel" id="contact_number" name="contact_number" required placeholder="+63-XXX-XXX-XXXX">
          </div>
          
          <div class="form-group">
            <label for="email">
              <i class="fas fa-envelope"></i>
              Email
            </label>
            <input type="email" id="email" name="email" placeholder="Enter email address">
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label for="service">
              <i class="fas fa-hands-helping"></i>
              Service *
            </label>
            <select id="service" name="service" required>
              <option value="">Select Service</option>
              <?php foreach ($accessible_services as $key => $name): ?>
                <option value="<?= $key ?>"><?= $name ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <div class="form-group">
            <label for="status">
              <i class="fas fa-user-check"></i>
              Status *
            </label>
            <select id="status" name="status" required>
              <option value="current">Current</option>
              <option value="graduated">Graduated</option>
            </select>
          </div>
        </div>
        
        <div class="form-group">
          <label for="notes">
            <i class="fas fa-sticky-note"></i>
            Notes
          </label>
          <textarea id="notes" name="notes" placeholder="Additional notes, certifications, special skills, etc." rows="4"></textarea>
        </div>
        
        <button type="submit" class="btn-submit">
          <i class="fas fa-save"></i> Save Volunteer
        </button>
      </form>
    </div>
  </div>

<script src="../admin/js/notification_frontend.js?v=<?php echo time(); ?>"></script>
  <script src="../admin/js/sidebar-notifications.js?v=<?php echo time(); ?>"></script>
  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
  <script>
    let geographicDataLoaded = false;
    let geographicVisible = false;
    
    function openCreateModal() {
      document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus"></i> Add New Volunteer';
      document.getElementById('formAction').name = 'create_volunteer';
      document.getElementById('volunteerForm').reset();
      document.getElementById('volunteerModal').classList.add('active');
      
      // Pre-select service if filtering by specific service
      const urlParams = new URLSearchParams(window.location.search);
      const serviceFilter = urlParams.get('service');
      if (serviceFilter) {
        document.getElementById('service').value = serviceFilter;
      }
    }
    
    function openEditModal(volunteer) {
      document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-edit"></i> Edit Volunteer';
      document.getElementById('formAction').name = 'update_volunteer';
      document.getElementById('volunteerId').value = volunteer.volunteer_id;
      document.getElementById('full_name').value = volunteer.full_name;
      document.getElementById('age').value = volunteer.age;
      document.getElementById('location').value = volunteer.location || '';
      document.getElementById('barangay').value = volunteer.barangay || '';
      document.getElementById('municipality').value = volunteer.municipality || '';
      document.getElementById('city').value = volunteer.city || '';
      document.getElementById('contact_number').value = volunteer.contact_number;
      document.getElementById('service').value = volunteer.service;
      document.getElementById('status').value = volunteer.status;
      document.getElementById('email').value = volunteer.email || '';
      document.getElementById('notes').value = volunteer.notes || '';
      document.getElementById('volunteerModal').classList.add('active');
    }
    
    function closeModal() {
      document.getElementById('volunteerModal').classList.remove('active');
    }
    
    function confirmDelete(name) {
      return confirm(`Are you sure you want to delete the volunteer "${name}"?\n\nThis action cannot be undone and will remove all associated training records and activities.`);
    }
    
    // Geographic data functions
    function toggleGeographicView() {
      const section = document.getElementById('geographicSection');
      const toggle = document.getElementById('geographicToggle');
      
      geographicVisible = !geographicVisible;
      
      if (geographicVisible) {
        section.style.display = 'block';
        toggle.innerHTML = '<i class="fas fa-eye-slash"></i> Hide Geographic Distribution';
        
        if (!geographicDataLoaded) {
          loadGeographicData();
        }
      } else {
        section.style.display = 'none';
        toggle.innerHTML = '<i class="fas fa-map-marked-alt"></i> Show Geographic Distribution';
      }
    }
    
    function loadGeographicData() {
      fetch('?api=geographic')
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            renderGeographicData(data.data);
            geographicDataLoaded = true;
          } else {
            document.getElementById('geographicData').innerHTML = `
              <div class="error-state">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Error loading geographic data: ${data.error}</p>
              </div>
            `;
          }
        })
        .catch(error => {
          console.error('Error loading geographic data:', error);
          document.getElementById('geographicData').innerHTML = `
            <div class="error-state">
              <i class="fas fa-exclamation-triangle"></i>
              <p>Failed to load geographic data. Please try again.</p>
            </div>
          `;
        });
    }
    
    function renderGeographicData(locations) {
      const container = document.getElementById('geographicData');
      
      if (locations.length === 0) {
        container.innerHTML = `
          <div class="empty-state">
            <i class="fas fa-map-marked"></i>
            <h3>No geographic data available</h3>
            <p>Start adding location information to volunteers to see geographic distribution</p>
          </div>
        `;
        return;
      }
      
      const services = <?= json_encode($services) ?>;
      
      let html = '<div class="location-cards">';
      
      locations.forEach(location => {
        const locationDisplay = [
          location.barangay !== 'Not Specified' ? location.barangay : null,
          location.municipality !== 'Not Specified' ? location.municipality : null,
          location.city !== 'Not Specified' ? location.city : null
        ].filter(Boolean).join(', ') || 'Location Not Specified';
        
        const locationKey = `${location.barangay}|${location.municipality}|${location.city}`;
        
        html += `
          <div class="location-card">
            <div class="location-header">
              <div class="location-icon">
                <i class="fas fa-map-marker-alt"></i>
              </div>
              <div class="location-info">
                <h3>${locationDisplay}</h3>
                <p>${location.total_volunteers} volunteers</p>
              </div>
            </div>
            
            <div class="location-stats">
              <div class="stat-item">
                <span class="stat-value">${location.current_volunteers}</span>
                <span class="stat-label">Current</span>
              </div>
              <div class="stat-item">
                <span class="stat-value">${location.graduated_volunteers}</span>
                <span class="stat-label">Graduated</span>
              </div>
            </div>
            
            <div class="location-services">
              <h4>Services Available:</h4>
              <div class="service-list">
        `;
        
        Object.keys(location.services).forEach(serviceKey => {
          const service = location.services[serviceKey];
          const serviceName = services[serviceKey] || serviceKey;
          
          html += `
            <div class="service-item">
              <span class="service-name">${serviceName}</span>
              <span class="service-count">${service.total}</span>
            </div>
          `;
        });
        
        html += `
              </div>
            </div>
            
            <div class="location-actions">
              <a href="?location=${encodeURIComponent(locationKey)}" class="btn-location-view">
                <i class="fas fa-eye"></i> View Volunteers
              </a>
            </div>
          </div>
        `;
      });
      
      html += '</div>';
      container.innerHTML = html;
    }
    
    // Close modal when clicking outside
    document.getElementById('volunteerModal').addEventListener('click', function(e) {
      if (e.target === this) {
        closeModal();
      }
    });
    
    // Enhanced volunteer search functionality
    document.getElementById('volunteerSearch').addEventListener('input', function() {
      const searchTerm = this.value.toLowerCase();
      const rows = document.querySelectorAll('#volunteersTable tbody tr');
      let visibleCount = 0;
      
      rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const isVisible = text.includes(searchTerm);
        row.style.display = isVisible ? '' : 'none';
        if (isVisible) visibleCount++;
      });
      
      // Update volunteer count
      const countElement = document.querySelector('.volunteer-count');
      if (countElement) {
        countElement.textContent = `${visibleCount} volunteers found`;
      }
    });
    
    // Phone number formatting with enhanced validation
    document.getElementById('contact_number').addEventListener('input', function() {
      let value = this.value.replace(/\D/g, '');
      if (value.startsWith('63')) {
        value = value.substring(2);
      }
      if (value.length > 0) {
        if (value.length <= 3) {
          this.value = '+63-' + value;
        } else if (value.length <= 6) {
          this.value = '+63-' + value.substring(0, 3) + '-' + value.substring(3);
        } else {
          this.value = '+63-' + value.substring(0, 3) + '-' + value.substring(3, 6) + '-' + value.substring(6, 10);
        }
      }
    });
    
    // Enhanced form validation
    document.getElementById('volunteerForm').addEventListener('submit', function(e) {
      const age = parseInt(document.getElementById('age').value);
      if (age < 16 || age > 100) {
        e.preventDefault();
        alert('Age must be between 16 and 100 years.');
        return false;
      }
      
      const contactNumber = document.getElementById('contact_number').value;
      const phoneRegex = /^\+63-\d{3}-\d{3}-\d{4}$/;
      if (!phoneRegex.test(contactNumber)) {
        e.preventDefault();
        alert('Please enter a valid Philippine phone number in format: +63-XXX-XXX-XXXX');
        return false;
      }
      
      // Add loading state to submit button
      const submitBtn = document.querySelector('.btn-submit');
      submitBtn.classList.add('loading');
      submitBtn.disabled = true;
      
      return true;
    });
    
    // Auto-capitalize location fields
    ['barangay', 'municipality', 'city'].forEach(fieldId => {
      document.getElementById(fieldId).addEventListener('blur', function() {
        this.value = this.value.replace(/\w\S*/g, (txt) => 
          txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase()
        );
      });
    });
    
    // Auto-capitalize names
    document.getElementById('full_name').addEventListener('blur', function() {
      this.value = this.value.replace(/\w\S*/g, (txt) => 
        txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase()
      );
    });
    
    // Enhanced keyboard navigation
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && document.getElementById('volunteerModal').classList.contains('active')) {
        closeModal();
      }
      
      if (e.ctrlKey && e.key === 'n' && !document.getElementById('volunteerModal').classList.contains('active')) {
        e.preventDefault();
        openCreateModal();
      }
      
      if (e.ctrlKey && e.key === 'g') {
        e.preventDefault();
        toggleGeographicView();
      }
    });
    
    // Auto-hide alerts after 5 seconds
    document.addEventListener('DOMContentLoaded', function() {
      const alerts = document.querySelectorAll('.alert');
      alerts.forEach(alert => {
        setTimeout(() => {
          alert.style.opacity = '0';
          setTimeout(() => {
            alert.remove();
          }, 300);
        }, 5000);
      });
    });
    // Filter update functions
function updateServiceFilter(value) {
  const url = new URL(window.location);
  if (value) {
    url.searchParams.set('service', value);
  } else {
    url.searchParams.delete('service');
  }
  window.location = url;
}

function updateStatusFilter(value) {
  const url = new URL(window.location);
  if (value) {
    url.searchParams.set('status', value);
  } else {
    url.searchParams.delete('status');
  }
  window.location = url;
}

function updateLocationFilter(value) {
  const url = new URL(window.location);
  if (value) {
    url.searchParams.set('location', value);
  } else {
    url.searchParams.delete('location');
  }
  window.location = url;
}
  </script>
</body>
</html>