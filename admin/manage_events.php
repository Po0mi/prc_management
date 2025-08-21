<?php

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'],
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

require_once __DIR__ . '/../config.php';
ensure_logged_in();
ensure_admin();

$pdo = $GLOBALS['pdo'];

// Initialize all variables to prevent undefined variable errors
$errorMessage = '';
$successMessage = '';
$selectedEvent = null;
$registrations = [];
$events = [];
$totalEvents = 0;
$totalPages = 1;
$stats = [];
$totalStats = ['total' => 0, 'upcoming' => 0, 'past' => 0];

date_default_timezone_set('Asia/Kuala_Lumpur');

// Get user role and ID
$user_role = get_user_role();
$current_user_id = $_SESSION['user_id'] ?? 0;

if (!$user_role) {
    $user_role = 'super'; // Default fallback
}

// Define role-to-service mapping - ENSURE EXACT MATCH WITH DATABASE
$roleServiceMapping = [
    'health' => ['Health Service'],
    'safety' => ['Safety Service'],
    'welfare' => ['Welfare Service'],
    'disaster' => ['Disaster Management Service'],
    'youth' => ['Red Cross Youth'],
    'super' => ['Health Service', 'Safety Service', 'Welfare Service', 'Disaster Management Service', 'Red Cross Youth']
];

// Get allowed services for current user role
$allowedServices = $roleServiceMapping[$user_role] ?? [];
$hasRestrictedAccess = $user_role !== 'super';

$majorServices = [
    'Health Service',
    'Safety Service',
    'Welfare Service',
    'Disaster Management Service',
    'Red Cross Youth'
];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Add logging function for debugging
function logDebug($message) {
    error_log("[EVENT_DEBUG] " . $message);
}

// Log current user role and permissions
logDebug("User role: $user_role, User ID: $current_user_id, Allowed services: " . implode(', ', $allowedServices));

function validateEventData($data, $allowedServices, $hasRestrictedAccess, $isCreate = false) {
    $errors = [];
    
    $required = ['title', 'event_date', 'location', 'major_service'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            $errors[] = "Please fill all required fields";
            break;
        }
    }
    
    // Check service permission for CREATE operations
    if ($isCreate && $hasRestrictedAccess && !empty($allowedServices)) {
        if (!in_array($data['major_service'] ?? '', $allowedServices)) {
            $errors[] = "You don't have permission to create events for this service. You can only create events for: " . implode(', ', $allowedServices);
        }
    }
    
    try {
        $date = $data['event_date'] ?? '';
        
        if (!DateTime::createFromFormat('Y-m-d', $date)) {
            $errors[] = "Invalid date format. Please use YYYY-MM-DD.";
        }
        
        $eventDate = strtotime($date);
        $today = strtotime('today');
        if ($eventDate < $today && $isCreate) {
            $errors[] = "Event date cannot be in the past.";
        }
    } catch (Exception $e) {
        $errors[] = "Invalid date values: " . $e->getMessage();
    }
    
    global $majorServices;
    if (!in_array($data['major_service'], $majorServices)) {
        $errors[] = "Invalid major service selected.";
    }
    
    $capacity = isset($data['capacity']) && $data['capacity'] !== '' ? (int)$data['capacity'] : 0;
    $fee = isset($data['fee']) && $data['fee'] !== '' ? (float)$data['fee'] : 0.00;
    
    if ($capacity < 0) {
        $errors[] = "Capacity cannot be negative.";
    }
    
    if ($fee < 0) {
        $errors[] = "Fee cannot be negative.";
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'data' => [
            'title' => trim($data['title'] ?? ''),
            'description' => trim($data['description'] ?? ''),
            'event_date' => $data['event_date'] ?? '',
            'location' => trim($data['location'] ?? ''),
            'major_service' => trim($data['major_service'] ?? ''),
            'capacity' => $capacity,
            'fee' => $fee
        ]
    ];
}

function handleDatabaseError($e) {
    error_log("Database error: " . $e->getMessage());
    return "Database error occurred. Please try again later.";
}

function checkEventAccess($pdo, $eventId, $allowedServices, $hasRestrictedAccess, $userId) {
    if (!$hasRestrictedAccess) {
        logDebug("Super admin accessing event $eventId - access granted");
        return true; // Super admin has access to all events
    }
    
    try {
        $stmt = $pdo->prepare("SELECT major_service, created_by FROM events WHERE event_id = ?");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch();
        
        if (!$event) {
            logDebug("Event $eventId not found");
            return false; // Event doesn't exist
        }
        
        // Allow access if:
        // 1. They created it (regardless of service)
        // 2. It's in their allowed services
        $hasAccess = ($event['created_by'] == $userId) || in_array($event['major_service'], $allowedServices);
        
        logDebug("Event $eventId access check - Created by: {$event['created_by']}, User: $userId, Service: {$event['major_service']}, Has access: " . ($hasAccess ? 'yes' : 'no'));
        
        return $hasAccess;
    } catch (PDOException $e) {
        error_log("Error checking event access: " . $e->getMessage());
        return false;
    }
}

// Handle CREATE event
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_event'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security error: Invalid form submission. Please try again.";
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        logDebug("Creating event for service: " . ($_POST['major_service'] ?? 'none'));
        
        $validation = validateEventData($_POST, $allowedServices, $hasRestrictedAccess, true);
        
        if ($validation['valid']) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO events 
                    (title, description, event_date, location, major_service, capacity, fee, created_at, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                ");
                
                $result = $stmt->execute([
                    $validation['data']['title'],
                    $validation['data']['description'],
                    $validation['data']['event_date'],
                    $validation['data']['location'],
                    $validation['data']['major_service'],
                    $validation['data']['capacity'],
                    $validation['data']['fee'],
                    $current_user_id
                ]);
                
                if ($result) {
                    $newEventId = $pdo->lastInsertId();
                    logDebug("Successfully created event ID: $newEventId for service: " . $validation['data']['major_service'] . " by user: $current_user_id");
                    $successMessage = "Event created successfully! Event ID: " . $newEventId;
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                } else {
                    $errorMessage = "Failed to create event. Please try again.";
                    logDebug("Failed to insert event into database");
                }
            } catch (PDOException $e) {
                logDebug("Database error creating event: " . $e->getMessage());
                $errorMessage = handleDatabaseError($e);
            }
        } else {
            logDebug("Event validation failed: " . implode(', ', $validation['errors']));
            $errorMessage = implode("<br>", $validation['errors']);
        }
    }
}

// Handle UPDATE event
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_event'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security error: Invalid form submission. Please try again.";
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $event_id = (int)($_POST['event_id'] ?? 0);
        
        // Check access permission (creator or service permission)
        if (!checkEventAccess($pdo, $event_id, $allowedServices, $hasRestrictedAccess, $current_user_id)) {
            $errorMessage = "You don't have permission to edit this event.";
        } else {
            $validation = validateEventData($_POST, $allowedServices, $hasRestrictedAccess, false);
            
            if ($validation['valid'] && $event_id > 0) {
                try {
                    $checkStmt = $pdo->prepare("SELECT created_by, major_service FROM events WHERE event_id = ?");
                    $checkStmt->execute([$event_id]);
                    $eventData = $checkStmt->fetch();
                    
                    if (!$eventData) {
                        $errorMessage = "Event not found.";
                    } else {
                        // Allow update if:
                        // 1. They created the event (can change to any service they have permission for)
                        // 2. They have permission for BOTH the current service AND the new service
                        $canUpdate = false;
                        $newService = $_POST['major_service'];
                        
                        if ($eventData['created_by'] == $current_user_id) {
                            // Creator can change to any service they have permission for
                            $canUpdate = !$hasRestrictedAccess || in_array($newService, $allowedServices);
                            if (!$canUpdate) {
                                $errorMessage = "You can only change the service to ones you have permission for.";
                            }
                        } else if (!$hasRestrictedAccess) {
                            // Super admin can edit anything
                            $canUpdate = true;
                        } else {
                            // Non-creator with restricted access: must have permission for both old and new service
                            $canUpdate = in_array($eventData['major_service'], $allowedServices) && 
                                        in_array($newService, $allowedServices);
                            if (!$canUpdate) {
                                $errorMessage = "You don't have permission to edit this event or change it to the selected service.";
                            }
                        }
                        
                        if ($canUpdate) {
                            $stmt = $pdo->prepare("
                                UPDATE events
                                SET title = ?, description = ?, event_date = ?, 
                                    location = ?, major_service = ?, capacity = ?, fee = ?
                                WHERE event_id = ?
                            ");
                            
                            $result = $stmt->execute([
                                $validation['data']['title'],
                                $validation['data']['description'],
                                $validation['data']['event_date'],
                                $validation['data']['location'],
                                $validation['data']['major_service'],
                                $validation['data']['capacity'],
                                $validation['data']['fee'],
                                $event_id
                            ]);
                            
                            if ($result) {
                                logDebug("Event $event_id updated successfully by user $current_user_id");
                                $successMessage = "Event updated successfully!";
                                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                            } else {
                                $errorMessage = "Failed to update event. Please try again.";
                            }
                        }
                    }
                } catch (PDOException $e) {
                    $errorMessage = handleDatabaseError($e);
                }
            } else {
                $errorMessage = $validation['valid'] ? "Invalid event ID" : implode("<br>", $validation['errors']);
            }
        }
    }
}

// Handle DELETE event
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_event'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security error: Invalid form submission. Please try again.";
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $event_id = (int)($_POST['event_id'] ?? 0);
        
        // Check access permission (creator or service permission)
        if (!checkEventAccess($pdo, $event_id, $allowedServices, $hasRestrictedAccess, $current_user_id)) {
            $errorMessage = "You don't have permission to delete this event.";
        } else {
            if ($event_id > 0) {
                try {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE event_id = ?");
                    $stmt->execute([$event_id]);
                    $registrations = $stmt->fetchColumn();
                    
                    if ($registrations > 0) {
                        $errorMessage = "Cannot delete event with existing registrations. Please cancel all registrations first.";
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM events WHERE event_id = ?");
                        $result = $stmt->execute([$event_id]);
                        
                        if ($result && $stmt->rowCount() > 0) {
                            $successMessage = "Event deleted successfully.";
                            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        } else {
                            $errorMessage = "Event not found or already deleted.";
                        }
                    }
                } catch (PDOException $e) {
                    $errorMessage = handleDatabaseError($e);
                }
            } else {
                $errorMessage = "Invalid event ID";
            }
        }
    }
}

// Handle REGISTRATION ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_registration_status'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security error: Invalid form submission.";
    } else {
        $registration_id = (int)($_POST['registration_id'] ?? 0);
        $new_status = $_POST['new_status'] ?? '';
        
        if ($registration_id > 0 && in_array($new_status, ['pending', 'approved', 'rejected'])) {
            try {
                // Check if user has access to this registration's event
                $stmt = $pdo->prepare("
                    SELECT e.major_service, e.event_id, e.created_by
                    FROM registrations r 
                    JOIN events e ON r.event_id = e.event_id 
                    WHERE r.registration_id = ?
                ");
                $stmt->execute([$registration_id]);
                $regEvent = $stmt->fetch();
                
                if (!$regEvent) {
                    $errorMessage = "Registration not found.";
                } else if ($hasRestrictedAccess && !in_array($regEvent['major_service'], $allowedServices) && $regEvent['created_by'] != $current_user_id) {
                    $errorMessage = "You don't have permission to manage this registration.";
                } else {
                    $stmt = $pdo->prepare("UPDATE registrations SET status = ? WHERE registration_id = ?");
                    $result = $stmt->execute([$new_status, $registration_id]);
                    
                    if ($result) {
                        $successMessage = "Registration status updated successfully!";
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    } else {
                        $errorMessage = "Failed to update registration status.";
                    }
                }
            } catch (PDOException $e) {
                $errorMessage = handleDatabaseError($e);
            }
        } else {
            $errorMessage = "Invalid registration data.";
        }
    }
}

// Handle DELETE registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_registration'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security error: Invalid form submission.";
    } else {
        $registration_id = (int)($_POST['registration_id'] ?? 0);
        
        if ($registration_id > 0) {
            try {
                // Check if user has access to this registration's event
                $stmt = $pdo->prepare("
                    SELECT e.major_service, e.created_by
                    FROM registrations r 
                    JOIN events e ON r.event_id = e.event_id 
                    WHERE r.registration_id = ?
                ");
                $stmt->execute([$registration_id]);
                $regEvent = $stmt->fetch();
                
                if (!$regEvent) {
                    $errorMessage = "Registration not found.";
                } else if ($hasRestrictedAccess && !in_array($regEvent['major_service'], $allowedServices) && $regEvent['created_by'] != $current_user_id) {
                    $errorMessage = "You don't have permission to delete this registration.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM registrations WHERE registration_id = ?");
                    $result = $stmt->execute([$registration_id]);
                    
                    if ($result && $stmt->rowCount() > 0) {
                        $successMessage = "Registration deleted successfully!";
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    } else {
                        $errorMessage = "Registration not found or already deleted.";
                    }
                }
            } catch (PDOException $e) {
                $errorMessage = handleDatabaseError($e);
            }
        } else {
            $errorMessage = "Invalid registration ID.";
        }
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$serviceFilter = isset($_GET['service']) ? trim($_GET['service']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$viewEvent = isset($_GET['view_event']) ? (int)$_GET['view_event'] : 0;
$page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$limit = 20;
$offset = ($page - 1) * $limit;

try {
    $whereConditions = [];
    $params = [];
    
    // Add service restriction OR created_by restriction for non-super admins
    if ($hasRestrictedAccess && !empty($allowedServices)) {
        // Allow seeing events they created OR events in their allowed services
        $placeholders = str_repeat('?,', count($allowedServices) - 1) . '?';
        $whereConditions[] = "(e.major_service IN ($placeholders) OR e.created_by = ?)";
        $params = array_merge($params, $allowedServices);
        $params[] = $current_user_id;
        
        logDebug("Service filter applied with creator exception. Allowed services: " . implode(', ', $allowedServices) . " OR created_by: " . $current_user_id);
    }
    
    if ($search) {
        $whereConditions[] = "(e.title LIKE ? OR e.location LIKE ? OR e.description LIKE ?)";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    
    // Fixed service filter logic
    if ($serviceFilter && $serviceFilter !== '') {
        if (!$hasRestrictedAccess) {
            // Super admin can filter by any service
            $whereConditions[] = "e.major_service = ?";
            $params[] = $serviceFilter;
        } else if (in_array($serviceFilter, $allowedServices)) {
            // Restricted user filtering by allowed service
            $whereConditions[] = "e.major_service = ?";
            $params[] = $serviceFilter;
        } else {
            // Restricted user filtering by non-allowed service - show only their created events
            $whereConditions[] = "(e.major_service = ? AND e.created_by = ?)";
            $params[] = $serviceFilter;
            $params[] = $current_user_id;
        }
    }
    
    if ($statusFilter === 'upcoming') {
        $whereConditions[] = "e.event_date >= CURDATE()";
    } elseif ($statusFilter === 'past') {
        $whereConditions[] = "e.event_date < CURDATE()";
    }
    
    $whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Modified query to include creator information and properly select all fields
    $query = "
        SELECT SQL_CALC_FOUND_ROWS 
               e.event_id,
               e.title,
               e.description,
               e.event_date,
               e.location,
               e.major_service,
               e.capacity,
               e.fee,
               e.created_at,
               e.created_by,
               COUNT(r.registration_id) AS registrations_count,
               u.email as creator_email,
               CASE 
                   WHEN e.created_by = ? THEN 1 
                   ELSE 0 
               END as is_my_event
        FROM events e
        LEFT JOIN registrations r ON e.event_id = r.event_id
        LEFT JOIN users u ON e.created_by = u.user_id
        $whereClause
        GROUP BY e.event_id, e.title, e.description, e.event_date, e.location, 
                 e.major_service, e.capacity, e.fee, e.created_at, e.created_by, u.email
        ORDER BY e.event_date ASC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $pdo->prepare($query);
    $paramIndex = 1;
    
    // Bind the user_id for the CASE statement
    $stmt->bindValue($paramIndex++, $current_user_id, PDO::PARAM_INT);
    
    // Bind other parameters
    foreach ($params as $param) {
        if (is_int($param)) {
            $stmt->bindValue($paramIndex++, $param, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($paramIndex++, $param, PDO::PARAM_STR);
        }
    }
    $stmt->bindValue($paramIndex++, $limit, PDO::PARAM_INT);
    $stmt->bindValue($paramIndex, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $events = $stmt->fetchAll();
    
    // Get total count
    $stmt = $pdo->query("SELECT FOUND_ROWS()");
    $totalEvents = $stmt->fetchColumn();
    $totalPages = ceil($totalEvents / $limit);
    
    logDebug("Found " . count($events) . " events for user role: $user_role, user_id: $current_user_id");
    
    // Get service stats
    foreach ($majorServices as $service) {
        if ($hasRestrictedAccess && !empty($allowedServices)) {
            if (in_array($service, $allowedServices)) {
                $stmt = $pdo->prepare("
                    SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN event_date >= CURDATE() THEN 1 ELSE 0 END) as upcoming,
                        SUM(CASE WHEN event_date < CURDATE() THEN 1 ELSE 0 END) as past
                    FROM events
                    WHERE major_service = ? AND (major_service IN (" . str_repeat('?,', count($allowedServices) - 1) . "?) OR created_by = ?)
                ");
                $statsParams = array_merge([$service], $allowedServices, [$current_user_id]);
                $stmt->execute($statsParams);
                $stats[$service] = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'upcoming' => 0, 'past' => 0];
            } else {
                $stats[$service] = ['total' => 0, 'upcoming' => 0, 'past' => 0];
            }
        } else {
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN event_date >= CURDATE() THEN 1 ELSE 0 END) as upcoming,
                    SUM(CASE WHEN event_date < CURDATE() THEN 1 ELSE 0 END) as past
                FROM events
                WHERE major_service = ?
            ");
            $stmt->execute([$service]);
            $stats[$service] = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'upcoming' => 0, 'past' => 0];
        }
    }
    
    // Get total stats with service restrictions including created events
    if ($hasRestrictedAccess && !empty($allowedServices)) {
        $placeholders = str_repeat('?,', count($allowedServices) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN event_date >= CURDATE() THEN 1 ELSE 0 END) as upcoming,
                SUM(CASE WHEN event_date < CURDATE() THEN 1 ELSE 0 END) as past
            FROM events
            WHERE major_service IN ($placeholders) OR created_by = ?
        ");
        $statsParams = array_merge($allowedServices, [$current_user_id]);
        $stmt->execute($statsParams);
        $totalStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'upcoming' => 0, 'past' => 0];
    } else {
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN event_date >= CURDATE() THEN 1 ELSE 0 END) as upcoming,
                SUM(CASE WHEN event_date < CURDATE() THEN 1 ELSE 0 END) as past
            FROM events
        ");
        $totalStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'upcoming' => 0, 'past' => 0];
    }
    
    // Get event details and registrations if viewing a specific event
    if ($viewEvent > 0) {
        // Check access to this event
        if (checkEventAccess($pdo, $viewEvent, $allowedServices, $hasRestrictedAccess, $current_user_id)) {
            $stmt = $pdo->prepare("SELECT * FROM events WHERE event_id = ?");
            $stmt->execute([$viewEvent]);
            $selectedEvent = $stmt->fetch();
            
            if ($selectedEvent) {
                $stmt = $pdo->prepare("
                    SELECT r.*, u.email as user_email
                    FROM registrations r
                    LEFT JOIN users u ON r.user_id = u.user_id
                    WHERE r.event_id = ?
                    ORDER BY r.registration_date DESC
                ");
                $stmt->execute([$viewEvent]);
                $registrations = $stmt->fetchAll() ?: [];
            }
        } else {
            $errorMessage = "You don't have permission to view this event.";
        }
    }
    
} catch (PDOException $e) {
    logDebug("Error fetching events: " . $e->getMessage());
    $errorMessage = handleDatabaseError($e);
    
    // Ensure all variables are initialized even on error
    $events = [];
    $totalEvents = 0;
    $totalPages = 1;
    $stats = [];
    $totalStats = ['total' => 0, 'upcoming' => 0, 'past' => 0];
    $selectedEvent = null;
    $registrations = [];
}

// Add function to get role color (if not already defined)
if (!function_exists('get_role_color')) {
    function get_role_color($role) {
        $colors = [
            'health' => '#4CAF50',
            'safety' => '#FF5722', 
            'welfare' => '#2196F3',
            'disaster' => '#FF9800',
            'youth' => '#9C27B0',
            'super' => '#607D8B'
        ];
        return $colors[$role] ?? '#607D8B';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Event Management - PRC Admin</title>
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
  <link rel="stylesheet" href="../assets/styles.css?v=<?= time() ?>">
  <link rel="stylesheet" href="../assets/events.css?v=<?= time() ?>">
</head>
<body class="admin-<?= htmlspecialchars($user_role) ?>">
  <?php include 'sidebar.php'; ?>
  
  <div class="events-container">
    <div class="page-header">
      <h1><i class="fas fa-calendar-alt"></i> Event Management</h1>
      <p>
        <?php if ($hasRestrictedAccess): ?>
          Manage <?= htmlspecialchars(implode(' and ', $allowedServices)) ?> events and participant registrations
        <?php else: ?>
          Create, update, and manage events and participant registrations
        <?php endif; ?>
      </p>
      <?php if ($hasRestrictedAccess): ?>
        <div class="role-indicator">
          <i class="fas fa-user-shield"></i>
          <span><?= htmlspecialchars(strtoupper($user_role)) ?> ADMIN</span>
          <small>Access limited to <?= htmlspecialchars(implode(', ', $allowedServices)) ?></small>
        </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($errorMessage)): ?>
      <div class="alert error">
        <i class="fas fa-exclamation-circle"></i>
        <?= $errorMessage ?>
      </div>
    <?php endif; ?>
    
    <?php if (!empty($successMessage)): ?>
      <div class="alert success">
        <i class="fas fa-check-circle"></i>
        <?= $successMessage ?>
      </div>
    <?php endif; ?>

    <?php if ($selectedEvent): ?>
      <!-- Registration View -->
      <div class="registrations-view">
        <a href="?<?= http_build_query(array_filter(['search' => $search, 'service' => $serviceFilter, 'status' => $statusFilter])) ?>" class="back-to-events">
          <i class="fas fa-arrow-left"></i> Back to Events
        </a>
        
        <div class="event-info-header">
          <div class="event-info-details">
            <div class="event-info-title"><?= htmlspecialchars($selectedEvent['title']) ?></div>
            <div class="event-info-meta">
              <span><i class="fas fa-tag"></i> <?= htmlspecialchars($selectedEvent['major_service']) ?></span>
              <span><i class="fas fa-calendar"></i> <?= date('M j, Y', strtotime($selectedEvent['event_date'])) ?></span>
              <span><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($selectedEvent['location']) ?></span>
              <?php if ($selectedEvent['fee'] > 0): ?>
                <span><i class="fas fa-money-bill"></i> ₱<?= number_format($selectedEvent['fee'], 2) ?></span>
              <?php endif; ?>
              <?php if ($selectedEvent['capacity'] > 0): ?>
                <span><i class="fas fa-users"></i> Capacity: <?= $selectedEvent['capacity'] ?></span>
              <?php endif; ?>
            </div>
            <?php if ($selectedEvent['description']): ?>
              <div class="event-description">
                <i class="fas fa-info-circle"></i>
                <?= htmlspecialchars($selectedEvent['description']) ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="registrations-stats">
          <div class="reg-stat-card">
            <div class="reg-stat-number"><?= count($registrations) ?></div>
            <div class="reg-stat-label">Total Registrations</div>
          </div>
          <div class="reg-stat-card">
            <div class="reg-stat-number"><?= count(array_filter($registrations, function($r) { return $r['status'] === 'approved'; })) ?></div>
            <div class="reg-stat-label">Approved</div>
          </div>
          <div class="reg-stat-card">
            <div class="reg-stat-number"><?= count(array_filter($registrations, function($r) { return $r['status'] === 'pending'; })) ?></div>
            <div class="reg-stat-label">Pending</div>
          </div>
          <div class="reg-stat-card">
            <div class="reg-stat-number"><?= count(array_filter($registrations, function($r) { return $r['status'] === 'rejected'; })) ?></div>
            <div class="reg-stat-label">Rejected</div>
          </div>
        </div>

        <?php if (empty($registrations)): ?>
          <div class="empty-state">
            <i class="fas fa-user-slash"></i>
            <h3>No Registrations Found</h3>
            <p>No participants have registered for this event yet.</p>
          </div>
        <?php else: ?>
          <div class="table-container">
            <table class="registrations-table">
              <thead>
                <tr>
                  <th>Participant</th>
                  <th>Registration ID</th>
                  <th>Registration Date</th>
                  <th>Status</th>
                  <th>Documents</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($registrations as $reg): ?>
                  <tr>
                    <td>
                      <div class="participant-info">
                        <div class="participant-avatar">
                          <?= strtoupper(substr($reg['full_name'] ?? 'U', 0, 1)) ?>
                        </div>
                        <div class="participant-details">
                          <div class="participant-name">
                            <?= htmlspecialchars($reg['full_name'] ?? 'Unknown') ?>
                          </div>
                          <div class="participant-email">
                            <?= htmlspecialchars($reg['user_email'] ?? $reg['email'] ?? 'No email') ?>
                          </div>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div style="font-size: 0.9rem; font-weight: 600; color: var(--prc-red);">
                        #<?= $reg['registration_id'] ?>
                      </div>
                    </td>
                    <td>
                      <div style="font-size: 0.9rem;">
                        <?= date('M j, Y', strtotime($reg['registration_date'])) ?>
                      </div>
                      <div style="font-size: 0.8rem; color: var(--gray);">
                        <?= date('g:i A', strtotime($reg['registration_date'])) ?>
                      </div>
                    </td>
                    <td>
                      <span class="reg-status-badge <?= $reg['status'] ?>">
                        <?= ucfirst($reg['status']) ?>
                      </span>
                    </td>
                    <td>
                      <div class="document-links">
                        <?php if (!empty($reg['documents_path'])): ?>
                          <a href="../<?= htmlspecialchars($reg['documents_path']) ?>" target="_blank" class="doc-link" style="font-size: 0.8rem; padding: 0.2rem 0.5rem;">
                            <i class="fas fa-file-alt"></i> Documents
                          </a>
                        <?php else: ?>
                          <span style="color: var(--gray); font-size: 0.8rem;">No documents</span>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td>
                      <div class="reg-actions">
                        <?php if ($reg['status'] !== 'approved'): ?>
                          <form method="POST" style="display: inline;">
                            <input type="hidden" name="update_registration_status" value="1">
                            <input type="hidden" name="registration_id" value="<?= $reg['registration_id'] ?>">
                            <input type="hidden" name="new_status" value="approved">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <button type="submit" class="btn-reg-action btn-approve">
                              <i class="fas fa-check"></i> Approve
                            </button>
                          </form>
                        <?php endif; ?>
                        
                        <?php if ($reg['status'] !== 'rejected'): ?>
                          <form method="POST" style="display: inline;">
                            <input type="hidden" name="update_registration_status" value="1">
                            <input type="hidden" name="registration_id" value="<?= $reg['registration_id'] ?>">
                            <input type="hidden" name="new_status" value="rejected">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <button type="submit" class="btn-reg-action btn-reject">
                              <i class="fas fa-times"></i> Reject
                            </button>
                          </form>
                        <?php endif; ?>
                        
                        <?php if ($reg['status'] !== 'pending'): ?>
                          <form method="POST" style="display: inline;">
                            <input type="hidden" name="update_registration_status" value="1">
                            <input type="hidden" name="registration_id" value="<?= $reg['registration_id'] ?>">
                            <input type="hidden" name="new_status" value="pending">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <button type="submit" class="btn-reg-action btn-pending">
                              <i class="fas fa-clock"></i> Pending
                            </button>
                          </form>
                        <?php endif; ?>
                        
                        <form method="POST" style="display: inline;" onsubmit="return confirmDeleteRegistration();">
                          <input type="hidden" name="delete_registration" value="1">
                          <input type="hidden" name="registration_id" value="<?= $reg['registration_id'] ?>">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                          <button type="submit" class="btn-reg-action btn-delete-reg">
                            <i class="fas fa-trash"></i> Delete
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
      
    <?php else: ?>
      <!-- Events List View -->

      <!-- Service Filter Tabs (only show allowed services) -->
      <div class="service-tabs">
        <a href="?status=<?= htmlspecialchars($statusFilter) ?>" class="service-tab all-services <?= !$serviceFilter ? 'active' : '' ?>">
          <div class="service-name">
            <?= $hasRestrictedAccess ? 'My Services' : 'All Services' ?>
          </div>
          <div class="service-count"><?= $totalStats['total'] ?> events</div>
        </a>
        <?php 
        // Show all services for super admin, or only allowed services for restricted users
        $servicesToShow = $hasRestrictedAccess ? $allowedServices : $majorServices;
        foreach ($servicesToShow as $service): 
            // Make sure we have stats for this service
            $serviceStats = $stats[$service] ?? ['total' => 0];
        ?>
          <a href="?service=<?= urlencode($service) ?>&status=<?= htmlspecialchars($statusFilter) ?>" 
             class="service-tab <?= $serviceFilter === $service ? 'active' : '' ?>">
            <div class="service-name"><?= htmlspecialchars($service) ?></div>
            <div class="service-count"><?= $serviceStats['total'] ?> events</div>
          </a>
        <?php endforeach; ?>
      </div>

      <!-- Action Bar -->
      <div class="action-bar">
        <div class="action-bar-left">
          <form method="GET" class="search-box">
            <input type="hidden" name="service" value="<?= htmlspecialchars($serviceFilter) ?>">
            <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
            <i class="fas fa-search"></i>
            <input type="text" name="search" placeholder="Search events..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit"><i class="fas fa-arrow-right"></i></button>
          </form>
          
          <div class="status-filter">
            <button onclick="filterStatus('all')" class="<?= !$statusFilter ? 'active' : '' ?>">All</button>
            <button onclick="filterStatus('upcoming')" class="<?= $statusFilter === 'upcoming' ? 'active' : '' ?>">Upcoming</button>
            <button onclick="filterStatus('past')" class="<?= $statusFilter === 'past' ? 'active' : '' ?>">Past</button>
          </div>
        </div>
        
        <div class="view-toggle">
          <button class="btn-create" onclick="openCreateModal()">
            <i class="fas fa-plus-circle"></i> Create New Event
          </button>
        </div>
      </div>

      <!-- Statistics Overview -->
      <div class="stats-overview">
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <i class="fas fa-calendar-alt"></i>
          </div>
          <div>
            <div style="font-size: 1.5rem; font-weight: 700;"><?= $totalStats['total'] ?></div>
            <div style="color: var(--gray); font-size: 0.9rem;">Total Events</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #00c853 0%, #64dd17 100%);">
            <i class="fas fa-clock"></i>
          </div>
          <div>
            <div style="font-size: 1.5rem; font-weight: 700;"><?= $totalStats['upcoming'] ?></div>
            <div style="color: var(--gray); font-size: 0.9rem;">Upcoming</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #ff6b6b 0%, #ff8e53 100%);">
            <i class="fas fa-history"></i>
          </div>
          <div>
            <div style="font-size: 1.5rem; font-weight: 700;"><?= $totalStats['past'] ?></div>
            <div style="color: var(--gray); font-size: 0.9rem;">Completed</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #ffd93d 0%, #ff9800 100%);">
            <i class="fas fa-users"></i>
          </div>
          <div>
            <div style="font-size: 1.5rem; font-weight: 700;"><?= !empty($events) ? array_sum(array_column($events, 'registrations_count')) : 0 ?></div>
            <div style="color: var(--gray); font-size: 0.9rem;">Total Registrations</div>
          </div>
        </div>
      </div>

      <!-- Events Table -->
      <div class="events-table-wrapper">
        <div class="table-header">
          <h2 class="table-title">
            <?php if ($serviceFilter): ?>
              <?= htmlspecialchars($serviceFilter) ?> Events
            <?php elseif ($hasRestrictedAccess): ?>
              <?= htmlspecialchars(implode(' & ', $allowedServices)) ?> Events
            <?php else: ?>
              All Events
            <?php endif; ?>
          </h2>
        </div>
        
        <?php if (empty($events)): ?>
          <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <h3>No events found</h3>
            <p><?= $search ? 'Try adjusting your search criteria' : 'Click "Create New Event" to get started' ?></p>
            <?php if ($hasRestrictedAccess): ?>
              <small style="color: var(--gray); margin-top: 0.5rem;">
                You can only view and manage <?= htmlspecialchars(implode(', ', $allowedServices)) ?> events.
              </small>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Event Details</th>
                <th>Service</th>
                <th>Date</th>
                <th>Location</th>
                <th>Fee</th>
                <th>Registrations</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($events as $event): 
                $eventDate = strtotime($event['event_date']);
                $today = strtotime('today');
                $isUpcoming = $eventDate >= $today;
                $isFull = $event['capacity'] > 0 && $event['registrations_count'] >= $event['capacity'];
              ?>
                <tr>
                  <td>
                    <div class="event-title"><?= htmlspecialchars($event['title']) ?></div>
                    <div style="font-size: 0.75rem; color: var(--gray); margin-top: 0.2rem;">ID: #<?= $event['event_id'] ?></div>
                    <?php if ($event['description']): ?>
                      <div style="font-size: 0.85rem; color: var(--gray); margin-top: 0.2rem;">
                        <?= htmlspecialchars(substr($event['description'], 0, 80)) ?><?= strlen($event['description']) > 80 ? '...' : '' ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="event-service"><?= htmlspecialchars($event['major_service']) ?></span>
                  </td>
                  <td>
                    <div class="event-datetime">
                      <span class="event-date"><?= date('M d, Y', $eventDate) ?></span>
                    </div>
                  </td>
                  <td><?= htmlspecialchars($event['location']) ?></td>
                  <td>
                    <div class="fee-display">
                      <?php if ($event['fee'] > 0): ?>
                        <span class="fee-amount">₱<?= number_format($event['fee'], 2) ?></span>
                      <?php else: ?>
                        <span class="fee-free">FREE</span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td>
                    <a href="?view_event=<?= $event['event_id'] ?>&<?= http_build_query(array_filter(['search' => $search, 'service' => $serviceFilter, 'status' => $statusFilter])) ?>" 
                       class="registrations-badge <?= $isFull ? 'full' : '' ?>">
                      <i class="fas fa-users"></i>
                      <?= $event['registrations_count'] ?> / <?= $event['capacity'] ?: '∞' ?>
                      <?php if ($isFull): ?>
                        <span style="font-size: 0.7rem; background: var(--prc-red); color: white; padding: 0.2rem 0.4rem; border-radius: 4px;">FULL</span>
                      <?php endif; ?>
                    </a>
                  </td>
                  <td>
                    <span class="status-badge <?= $isUpcoming ? 'upcoming' : 'past' ?>">
                      <?= $isUpcoming ? 'Upcoming' : 'Completed' ?>
                    </span>
                  </td>
                  <td class="actions">
                    <a href="?view_event=<?= $event['event_id'] ?>&<?= http_build_query(array_filter(['search' => $search, 'service' => $serviceFilter, 'status' => $statusFilter])) ?>" 
                       class="btn-action btn-view">
                      <i class="fas fa-users"></i> View Registrations
                    </a>
                    <button class="btn-action btn-edit" onclick='openEditModal(<?= json_encode($event, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                      <i class="fas fa-edit"></i> Edit
                    </button>
                    <form method="POST" style="display: inline;" onsubmit="return confirmDelete('<?= htmlspecialchars($event['title'], ENT_QUOTES) ?>', <?= $event['registrations_count'] ?>);">
                      <input type="hidden" name="delete_event" value="1">
                      <input type="hidden" name="event_id" value="<?= $event['event_id'] ?>">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                      <button type="submit" class="btn-action btn-delete">
                        <i class="fas fa-trash"></i> Delete
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          
          <?php if ($totalPages > 1): ?>
            <div class="pagination">
              <?php if ($page > 1): ?>
                <a href="?page=<?= $page-1 ?>&service=<?= urlencode($serviceFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&search=<?= urlencode($search) ?>" class="page-link">
                  <i class="fas fa-chevron-left"></i> Previous
                </a>
              <?php endif; ?>
              
              <span class="page-info">Page <?= $page ?> of <?= $totalPages ?> (<?= $totalEvents ?> total events)</span>
              
              <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page+1 ?>&service=<?= urlencode($serviceFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&search=<?= urlencode($search) ?>" class="page-link">
                  Next <i class="fas fa-chevron-right"></i>
                </a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Create/Edit Modal -->
  <div class="modal" id="eventModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitle">Create New Event</h2>
        <button class="close-modal" onclick="closeModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <form method="POST" id="eventForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="create_event" value="1" id="formAction">
        <input type="hidden" name="event_id" id="eventId">
        
        <div class="form-group">
          <label for="title">Event Title *</label>
          <input type="text" id="title" name="title" required placeholder="Enter event title" maxlength="255">
        </div>
        
        <div class="form-group">
          <label for="description">Description</label>
          <textarea id="description" name="description" rows="3" placeholder="Enter event description and details" maxlength="1000"></textarea>
        </div>
        
        <div class="form-group">
          <label for="major_service">Major Service *</label>
          <select id="major_service" name="major_service" required>
              <option value="">Select a service</option>
              <?php foreach ($majorServices as $service): ?>
                  <option value="<?= htmlspecialchars($service) ?>" 
                          class="service-option" 
                          data-allowed="<?= (!$hasRestrictedAccess || in_array($service, $allowedServices)) ? 'true' : 'false' ?>">
                      <?= htmlspecialchars($service) ?>
                  </option>
              <?php endforeach; ?>
          </select>
          <?php if ($hasRestrictedAccess): ?>
              <small id="serviceHint" style="color: var(--gray); margin-top: 0.25rem;">
                  You can only create events for: <?= htmlspecialchars(implode(', ', $allowedServices)) ?>
              </small>
          <?php endif; ?>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label for="event_date">Date *</label>
            <input type="date" id="event_date" name="event_date" required min="<?= date('Y-m-d') ?>">
          </div>
          
          <div class="form-group">
            <label for="location">Location *</label>
            <input type="text" id="location" name="location" required placeholder="Event location" maxlength="255">
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label for="capacity">Capacity</label>
            <input type="number" id="capacity" name="capacity" min="0" max="10000" placeholder="0 for unlimited">
            <small style="color: var(--gray);">Leave empty or set to 0 for unlimited capacity</small>
          </div>
          
          <div class="form-group">
            <label for="fee">Fee (₱)</label>
            <input type="number" id="fee" name="fee" min="0" step="0.01" placeholder="0.00">
            <small style="color: var(--gray);">Leave empty or set to 0 for free event</small>
          </div>
        </div>
        
        <button type="submit" class="btn-submit">
          <i class="fas fa-save"></i> Save Event
        </button>
      </form>
    </div>
  </div>

  <!-- CRITICAL: JavaScript Variables from PHP -->
  <script>
  // Define JavaScript variables from PHP
  const userRole = '<?= $user_role ?>';
  const hasRestrictedAccess = <?= $hasRestrictedAccess ? 'true' : 'false' ?>;
  const allowedServices = <?= json_encode($allowedServices) ?>;
  const currentUserId = <?= $current_user_id ?>;

  console.log('User Role:', userRole);
  console.log('Has Restricted Access:', hasRestrictedAccess);
  console.log('Allowed Services:', allowedServices);
  console.log('Current User ID:', currentUserId);

  // Main JavaScript Functions
  function openCreateModal() {
      document.getElementById('modalTitle').textContent = 'Create New Event';
      document.getElementById('formAction').name = 'create_event';
      document.getElementById('eventForm').reset();
      document.getElementById('event_date').min = new Date().toISOString().split('T')[0];
      
      // For create modal, filter services if restricted access
      const serviceSelect = document.getElementById('major_service');
      const serviceHint = document.getElementById('serviceHint');
      
      if (hasRestrictedAccess && allowedServices && allowedServices.length > 0) {
          // Disable options not in allowedServices for creation
          Array.from(serviceSelect.options).forEach(option => {
              if (option.value === '') {
                  option.disabled = false;
                  option.style.display = 'block';
              } else if (!allowedServices.includes(option.value)) {
                  option.disabled = true;
                  option.style.display = 'none';
              } else {
                  option.disabled = false;
                  option.style.display = 'block';
              }
          });
      } else {
          // Show all options for super admin
          Array.from(serviceSelect.options).forEach(option => {
              option.disabled = false;
              option.style.display = 'block';
          });
      }
      
      document.getElementById('eventModal').classList.add('active');
  }

  function openEditModal(event) {
      console.log('Opening edit modal for event:', event);
      
      document.getElementById('modalTitle').textContent = 'Edit Event';
      document.getElementById('formAction').name = 'update_event';
      document.getElementById('eventId').value = event.event_id;
      document.getElementById('title').value = event.title;
      document.getElementById('description').value = event.description || '';
      document.getElementById('major_service').value = event.major_service;
      document.getElementById('event_date').value = event.event_date;
      document.getElementById('location').value = event.location;
      document.getElementById('capacity').value = event.capacity || '';
      document.getElementById('fee').value = event.fee || '';
      
      // For edit modal, show appropriate services based on permissions
      const serviceSelect = document.getElementById('major_service');
      const serviceHint = document.getElementById('serviceHint');
      
      if (hasRestrictedAccess && allowedServices && allowedServices.length > 0) {
          const isCreator = event.created_by == currentUserId;
          
          Array.from(serviceSelect.options).forEach(option => {
              if (option.value === '') {
                  option.disabled = false;
                  option.style.display = 'block';
              } else if (option.value === event.major_service) {
                  // Always show current service
                  option.disabled = false;
                  option.style.display = 'block';
              } else if (isCreator && allowedServices.includes(option.value)) {
                  // Creator can change to services they have permission for
                  option.disabled = false;
                  option.style.display = 'block';
              } else if (!isCreator && allowedServices.includes(option.value)) {
                  // Non-creator can only change between allowed services
                  option.disabled = false;
                  option.style.display = 'block';
              } else {
                  option.disabled = true;
                  option.style.display = 'none';
              }
          });
          
          if (serviceHint) {
              if (isCreator) {
                  serviceHint.textContent = 'You can change to services you have permission for';
              } else {
                  serviceHint.textContent = 'You can only change between services you have permission for';
              }
              serviceHint.style.display = 'block';
          }
      } else {
          // Show all options for super admin
          Array.from(serviceSelect.options).forEach(option => {
              option.disabled = false;
              option.style.display = 'block';
          });
          if (serviceHint) {
              serviceHint.style.display = 'none';
          }
      }
      
      // Set proper date minimum
      const eventDate = new Date(event.event_date);
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      
      if (eventDate < today) {
          document.getElementById('event_date').min = event.event_date;
      } else {
          document.getElementById('event_date').min = new Date().toISOString().split('T')[0];
      }
      
      document.getElementById('eventModal').classList.add('active');
  }

  function closeModal() {
      document.getElementById('eventModal').classList.remove('active');
  }

  function filterStatus(status) {
      const urlParams = new URLSearchParams(window.location.search);
      
      // Preserve service filter when changing status
      const currentService = urlParams.get('service');
      
      if (status === 'all') {
          urlParams.delete('status');
      } else {
          urlParams.set('status', status);
      }
      
      // Keep the service filter if it exists
      if (currentService) {
          urlParams.set('service', currentService);
      }
      
      // Reset to page 1 when filtering
      urlParams.delete('page');
      
      window.location.search = urlParams.toString();
  }

  function confirmDelete(title, registrationCount) {
      if (registrationCount > 0) {
          return confirm(`Are you sure you want to delete "${title}"?\n\nThis event has ${registrationCount} registration(s).\nYou should cancel all registrations first.`);
      }
      return confirm(`Are you sure you want to delete "${title}"?\n\nThis action cannot be undone.`);
  }

  function confirmDeleteRegistration() {
      return confirm('Are you sure you want to delete this registration?\n\nThis action cannot be undone.');
  }

  // Close modal when clicking outside
  document.addEventListener('DOMContentLoaded', function() {
      const eventModal = document.getElementById('eventModal');
      if (eventModal) {
          eventModal.addEventListener('click', function(e) {
              if (e.target === this) {
                  closeModal();
              }
          });
      }

      // Form validation with role-based restrictions
      const eventForm = document.getElementById('eventForm');
      if (eventForm) {
          eventForm.addEventListener('submit', function(e) {
              const eventDate = document.getElementById('event_date').value;
              const title = document.getElementById('title').value.trim();
              const location = document.getElementById('location').value.trim();
              const majorService = document.getElementById('major_service').value;
              const isCreating = document.getElementById('formAction').name === 'create_event';
              
              console.log('Form submission - Service:', majorService, 'IsCreating:', isCreating, 'Allowed:', allowedServices);
              
              // Basic validation
              if (!title) {
                  e.preventDefault();
                  alert('Please enter an event title.');
                  return;
              }
              
              if (!location) {
                  e.preventDefault();
                  alert('Please enter a location.');
                  return;
              }
              
              if (!majorService) {
                  e.preventDefault();
                  alert('Please select a major service.');
                  return;
              }
              
              // Only check service permission for CREATE operations
              if (isCreating && hasRestrictedAccess && allowedServices && allowedServices.length > 0) {
                  if (!allowedServices.includes(majorService)) {
                      e.preventDefault();
                      alert("You don't have permission to create events for " + majorService + 
                            "\n\nYou can only create events for: " + allowedServices.join(', '));
                      return;
                  }
              }
              
              const selectedDate = new Date(eventDate);
              const today = new Date();
              today.setHours(0, 0, 0, 0);
              
              if (selectedDate < today && isCreating) {
                  e.preventDefault();
                  alert('Event date cannot be in the past for new events');
                  return;
              }
              
              // Visual feedback
              const submitBtn = this.querySelector('.btn-submit');
              if (submitBtn) {
                  const originalText = submitBtn.innerHTML;
                  submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                  submitBtn.disabled = true;
              }
          });
      }

      // Auto-dismiss alerts after 5 seconds
      const alerts = document.querySelectorAll('.alert');
      alerts.forEach(alert => {
          setTimeout(() => {
              alert.style.opacity = '0';
              alert.style.transform = 'translateY(-20px)';
              setTimeout(() => {
                  alert.remove();
              }, 300);
          }, 5000);
      });

      // Clear form submission state from browser history
      if (window.history.replaceState) {
          window.history.replaceState(null, null, window.location.href);
      }

      // Debug: Log current permissions
      console.log('=== Event Management Permissions ===');
      console.log('User Role:', userRole);
      console.log('Has Restricted Access:', hasRestrictedAccess);
      console.log('Allowed Services:', allowedServices);
      console.log('Current User ID:', currentUserId);
      console.log('===================================');
  });

  // Keyboard shortcuts
  document.addEventListener('keydown', function(e) {
      // Escape key to close modal
      if (e.key === 'Escape') {
          const modal = document.getElementById('eventModal');
          if (modal && modal.classList.contains('active')) {
              closeModal();
          }
      }
      
      // Ctrl/Cmd + N to create new event
      if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
          e.preventDefault();
          openCreateModal();
      }
  });

  // Search form enhancement
  document.addEventListener('DOMContentLoaded', function() {
      const searchForm = document.querySelector('.search-box');
      if (searchForm) {
          const searchInput = searchForm.querySelector('input[name="search"]');
          if (searchInput) {
              // Clear search functionality
              searchInput.addEventListener('keyup', function(e) {
                  if (e.key === 'Escape') {
                      this.value = '';
                      searchForm.submit();
                  }
              });
          }
      }
  });

  // Enhanced table interactions
  document.addEventListener('DOMContentLoaded', function() {
      // Add hover effects for table rows
      const tableRows = document.querySelectorAll('.data-table tbody tr');
      tableRows.forEach(row => {
          row.addEventListener('mouseenter', function() {
              this.style.backgroundColor = '#f8f9fa';
          });
          
          row.addEventListener('mouseleave', function() {
              this.style.backgroundColor = '';
          });
      });

      // Enhanced registration table interactions
      const regRows = document.querySelectorAll('.registrations-table tbody tr');
      regRows.forEach(row => {
          row.addEventListener('mouseenter', function() {
              this.style.backgroundColor = '#f8f9fa';
          });
          
          row.addEventListener('mouseleave', function() {
              this.style.backgroundColor = '';
          });
      });
  });

  // Touch/mobile enhancements
  if ('ontouchstart' in window) {
      document.addEventListener('DOMContentLoaded', function() {
          // Add touch-friendly classes
          document.body.classList.add('touch-device');
          
          // Enhance button interactions for touch
          const buttons = document.querySelectorAll('button, .btn-action');
          buttons.forEach(button => {
              button.addEventListener('touchstart', function() {
                  this.classList.add('touch-active');
              });
              
              button.addEventListener('touchend', function() {
                  setTimeout(() => {
                      this.classList.remove('touch-active');
                  }, 150);
              });
          });
      });
  }
  </script>
  
  <!-- Role-based styling -->
  <style>
    :root {
      --current-role-color: <?= get_role_color($user_role) ?>;
    }
    
    .role-indicator {
      background: linear-gradient(135deg, var(--current-role-color) 0%, <?= get_role_color($user_role) ?>dd 100%);
      color: white;
      padding: 0.5rem 1rem;
      border-radius: 8px;
      margin-top: 0.5rem;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.85rem;
    }
    
    .role-indicator small {
      opacity: 0.9;
      font-size: 0.75rem;
    }
    
    .service-tab.active,
    .btn-create {
      color: var(--current-role-color) !important;
    }
    
    .btn-create {
      background: linear-gradient(135deg, var(--current-role-color) 0%, <?= get_role_color($user_role) ?>dd 100%) !important;
      color: white !important;
    }
    
    .service-tab.active {
      border-bottom-color: var(--current-role-color) !important;
      background: linear-gradient(135deg, var(--current-role-color)15 0%, <?= get_role_color($user_role) ?>10 100%) !important;
    }
    
    .stat-card .stat-icon {
      background: linear-gradient(135deg, var(--current-role-color) 0%, <?= get_role_color($user_role) ?>dd 100%) !important;
    }
    
    .events-container {
      --role-accent: var(--current-role-color);
    }
    
    .event-service {
      background: linear-gradient(135deg, var(--current-role-color)20 0%, <?= get_role_color($user_role) ?>15 100%);
      color: var(--current-role-color);
      padding: 0.2rem 0.6rem;
      border-radius: 12px;
      font-size: 0.8rem;
      font-weight: 600;
    }
  </style>
  
  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
</body>
</html>