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
$totalStats = ['total' => 0, 'upcoming' => 0, 'past' => 0, 'ongoing' => 0];

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

// Enhanced validation function with multi-day support
function validateEventData($data, $allowedServices, $hasRestrictedAccess, $isCreate = false) {
    $errors = [];
    
    // Add start_time and end_time to required fields
    $required = ['title', 'event_date', 'start_time', 'end_time', 'location', 'major_service'];
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
        $startDate = $data['event_date'] ?? '';
        $durationDays = isset($data['duration_days']) ? max(1, (int)$data['duration_days']) : 1;
        $startTime = $data['start_time'] ?? '';
        $endTime = $data['end_time'] ?? '';
        
        if (!DateTime::createFromFormat('Y-m-d', $startDate)) {
            $errors[] = "Invalid start date format. Please use YYYY-MM-DD.";
        }
        
        $startDateTime = strtotime($startDate);
        $today = strtotime('today');
        if ($startDateTime < $today && $isCreate) {
            $errors[] = "Event start date cannot be in the past.";
        }
        
        // Calculate end date
        $endDateTime = $startDateTime + (($durationDays - 1) * 24 * 60 * 60);
        $endDate = date('Y-m-d', $endDateTime);
        
        // Validate duration
        if ($durationDays < 1 || $durationDays > 365) {
            $errors[] = "Duration must be between 1 and 365 days.";
        }
        
        // Validate time fields - NEW CODE
        if (!empty($startTime) && !empty($endTime) && empty($errors)) {
            $startTimestamp = strtotime($startDate . ' ' . $startTime);
            $endTimestamp = strtotime($startDate . ' ' . $endTime);
            
            if ($startTimestamp === false) {
                $errors[] = "Invalid start time format.";
            } elseif ($endTimestamp === false) {
                $errors[] = "Invalid end time format.";
            } elseif ($endTimestamp <= $startTimestamp) {
                $errors[] = "End time must be after start time.";
            }
            
            // Minimum 1 hour duration
            if ($endTimestamp - $startTimestamp < 3600) {
                $errors[] = "Event must be at least 1 hour long.";
            }
        }
        
    } catch (Exception $e) {
        $errors[] = "Invalid date/time values: " . $e->getMessage();
    }
    
    // Rest of validation remains the same...
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
            'event_date' => $startDate,
            'event_end_date' => $endDate ?? $startDate,
            'duration_days' => $durationDays,
            'start_time' => trim($data['start_time'] ?? '09:00'), // NEW
            'end_time' => trim($data['end_time'] ?? '17:00'),     // NEW
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

// UPDATED: CREATE event handler - Remove conflict checking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_event'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security error: Invalid form submission. Please try again.";
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        logDebug("Creating event for service: " . ($_POST['major_service'] ?? 'none'));
        
        $validation = validateEventData($_POST, $allowedServices, $hasRestrictedAccess, true);
        
        if ($validation['valid']) {
            try {
                // DIRECTLY INSERT WITHOUT CONFLICT CHECK - UPDATED WITH TIME FIELDS
                $stmt = $pdo->prepare("
                    INSERT INTO events 
                    (title, description, event_date, event_end_date, duration_days, start_time, end_time, location, major_service, capacity, fee, created_at, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                ");
                
                $result = $stmt->execute([
                    $validation['data']['title'],
                    $validation['data']['description'],
                    $validation['data']['event_date'],
                    $validation['data']['event_end_date'],
                    $validation['data']['duration_days'],
                    $validation['data']['start_time'],  // NEW
                    $validation['data']['end_time'],    // NEW
                    $validation['data']['location'],
                    $validation['data']['major_service'],
                    $validation['data']['capacity'],
                    $validation['data']['fee'],
                    $current_user_id
                ]);
                
                if ($result) {
                    $newEventId = $pdo->lastInsertId();
                    logDebug("Successfully created event ID: $newEventId for service: " . $validation['data']['major_service'] . " by user: $current_user_id");
                    
                    $durationText = $validation['data']['duration_days'] > 1 ? 
                        " ({$validation['data']['duration_days']} days)" : "";
                    $successMessage = "Event created successfully! Event ID: " . $newEventId . $durationText;
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

// UPDATED: UPDATE event handler - Remove conflict checking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_event'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security error: Invalid form submission. Please try again.";
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $event_id = (int)($_POST['event_id'] ?? 0);
        
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
                        // Allow update if user has proper permissions
                        $canUpdate = false;
                        $newService = $_POST['major_service'];
                        
                        if ($eventData['created_by'] == $current_user_id) {
                            $canUpdate = !$hasRestrictedAccess || in_array($newService, $allowedServices);
                            if (!$canUpdate) {
                                $errorMessage = "You can only change the service to ones you have permission for.";
                            }
                        } else if (!$hasRestrictedAccess) {
                            $canUpdate = true;
                        } else {
                            $canUpdate = in_array($eventData['major_service'], $allowedServices) && 
                                        in_array($newService, $allowedServices);
                            if (!$canUpdate) {
                                $errorMessage = "You don't have permission to edit this event or change it to the selected service.";
                            }
                        }
                        
                        if ($canUpdate) {
                            // DIRECTLY UPDATE WITHOUT CONFLICT CHECK - UPDATED WITH TIME FIELDS
                            $stmt = $pdo->prepare("
                                UPDATE events
                                SET title = ?, description = ?, event_date = ?, event_end_date = ?, duration_days = ?,
                                    start_time = ?, end_time = ?, location = ?, major_service = ?, capacity = ?, fee = ?
                                WHERE event_id = ?
                            ");
                            
                            $result = $stmt->execute([
                                $validation['data']['title'],
                                $validation['data']['description'],
                                $validation['data']['event_date'],
                                $validation['data']['event_end_date'],
                                $validation['data']['duration_days'],
                                $validation['data']['start_time'],  // NEW
                                $validation['data']['end_time'],    // NEW
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

// Handle DELETE event (unchanged)
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

// Handle REGISTRATION ACTIONS (unchanged from original)
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

// Handle DELETE registration (unchanged from original)
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
    
    // UPDATED: Status filter conditions to use time-aware logic
    if ($statusFilter === 'upcoming') {
        $whereConditions[] = "CONCAT(e.event_date, ' ', e.start_time) > NOW()";
    } elseif ($statusFilter === 'past') {
        $whereConditions[] = "CONCAT(COALESCE(e.event_end_date, e.event_date), ' ', e.end_time) < NOW()";
    } elseif ($statusFilter === 'ongoing') {
        $whereConditions[] = "NOW() BETWEEN CONCAT(e.event_date, ' ', e.start_time) AND CONCAT(COALESCE(e.event_end_date, e.event_date), ' ', e.end_time)";
    }
    
    $whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // UPDATED: Main SELECT query with time fields and time-aware status
    $query = "
        SELECT SQL_CALC_FOUND_ROWS 
               e.event_id,
               e.title,
               e.description,
               e.event_date,
               e.event_end_date,
               e.duration_days,
               e.start_time,
               e.end_time,
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
               END as is_my_event,
               CASE 
                   WHEN NOW() < CONCAT(e.event_date, ' ', e.start_time) THEN 'upcoming'
                   WHEN NOW() > CONCAT(COALESCE(e.event_end_date, e.event_date), ' ', e.end_time) THEN 'past'
                   WHEN NOW() BETWEEN CONCAT(e.event_date, ' ', e.start_time) AND CONCAT(COALESCE(e.event_end_date, e.event_date), ' ', e.end_time) THEN 'ongoing'
                   ELSE 'upcoming'
               END as event_status
        FROM events e
        LEFT JOIN registrations r ON e.event_id = r.event_id
        LEFT JOIN users u ON e.created_by = u.user_id
        $whereClause
        GROUP BY e.event_id, e.title, e.description, e.event_date, e.event_end_date, e.duration_days, 
                 e.start_time, e.end_time, e.location, e.major_service, e.capacity, e.fee, e.created_at, e.created_by, u.email
        ORDER BY e.event_date ASC, e.start_time ASC
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
                        SUM(CASE WHEN CONCAT(event_date, ' ', start_time) > NOW() THEN 1 ELSE 0 END) as upcoming,
                        SUM(CASE WHEN CONCAT(COALESCE(event_end_date, event_date), ' ', end_time) < NOW() THEN 1 ELSE 0 END) as past,
                        SUM(CASE WHEN NOW() BETWEEN CONCAT(event_date, ' ', start_time) AND CONCAT(COALESCE(event_end_date, event_date), ' ', end_time) THEN 1 ELSE 0 END) as ongoing
                    FROM events
                    WHERE major_service = ? AND (major_service IN (" . str_repeat('?,', count($allowedServices) - 1) . "?) OR created_by = ?)
                ");
                $statsParams = array_merge([$service], $allowedServices, [$current_user_id]);
                $stmt->execute($statsParams);
                $stats[$service] = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'upcoming' => 0, 'past' => 0, 'ongoing' => 0];
            } else {
                $stats[$service] = ['total' => 0, 'upcoming' => 0, 'past' => 0, 'ongoing' => 0];
            }
        } else {
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN CONCAT(event_date, ' ', start_time) > NOW() THEN 1 ELSE 0 END) as upcoming,
                    SUM(CASE WHEN CONCAT(COALESCE(event_end_date, event_date), ' ', end_time) < NOW() THEN 1 ELSE 0 END) as past,
                    SUM(CASE WHEN NOW() BETWEEN CONCAT(event_date, ' ', start_time) AND CONCAT(COALESCE(event_end_date, event_date), ' ', end_time) THEN 1 ELSE 0 END) as ongoing
                FROM events
                WHERE major_service = ?
            ");
            $stmt->execute([$service]);
            $stats[$service] = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'upcoming' => 0, 'past' => 0, 'ongoing' => 0];
        }
    }
    
    // Get total stats with service restrictions including created events
    if ($hasRestrictedAccess && !empty($allowedServices)) {
        $placeholders = str_repeat('?,', count($allowedServices) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN CONCAT(event_date, ' ', start_time) > NOW() THEN 1 ELSE 0 END) as upcoming,
                SUM(CASE WHEN CONCAT(COALESCE(event_end_date, event_date), ' ', end_time) < NOW() THEN 1 ELSE 0 END) as past,
                SUM(CASE WHEN NOW() BETWEEN CONCAT(event_date, ' ', start_time) AND CONCAT(COALESCE(event_end_date, event_date), ' ', end_time) THEN 1 ELSE 0 END) as ongoing
            FROM events
            WHERE major_service IN ($placeholders) OR created_by = ?
        ");
        $statsParams = array_merge($allowedServices, [$current_user_id]);
        $stmt->execute($statsParams);
        $totalStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'upcoming' => 0, 'past' => 0, 'ongoing' => 0];
    } else {
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN CONCAT(event_date, ' ', start_time) > NOW() THEN 1 ELSE 0 END) as upcoming,
                SUM(CASE WHEN CONCAT(COALESCE(event_end_date, event_date), ' ', end_time) < NOW() THEN 1 ELSE 0 END) as past,
                SUM(CASE WHEN NOW() BETWEEN CONCAT(event_date, ' ', start_time) AND CONCAT(COALESCE(event_end_date, event_date), ' ', end_time) THEN 1 ELSE 0 END) as ongoing
            FROM events
        ");
        $totalStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'upcoming' => 0, 'past' => 0, 'ongoing' => 0];
    }
    
    // Get event details and registrations if viewing a specific event
    if ($viewEvent > 0) {
        // Check access to this event
        if (checkEventAccess($pdo, $viewEvent, $allowedServices, $hasRestrictedAccess, $current_user_id)) {
            $stmt = $pdo->prepare("SELECT *, 
                CASE 
                    WHEN NOW() < CONCAT(event_date, ' ', start_time) THEN 'upcoming'
                    WHEN NOW() > CONCAT(COALESCE(event_end_date, event_date), ' ', end_time) THEN 'past'
                    WHEN NOW() BETWEEN CONCAT(event_date, ' ', start_time) AND CONCAT(COALESCE(event_end_date, event_date), ' ', end_time) THEN 'ongoing'
                    ELSE 'upcoming'
                END as event_status
                FROM events WHERE event_id = ?");
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
    $totalStats = ['total' => 0, 'upcoming' => 0, 'past' => 0, 'ongoing' => 0];
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
                        <?php if (!empty($reg['valid_id_path'])): ?>
                          <a href="../<?= htmlspecialchars($reg['valid_id_path']) ?>" target="_blank" class="doc-link" style="font-size: 0.8rem; padding: 0.2rem 0.5rem;">
                            <i class="fas fa-id-card"></i> ID
                          </a>
                        <?php endif; ?>
                        <?php if (!empty($reg['requirements_path'])): ?>
                          <a href="../<?= htmlspecialchars($reg['requirements_path']) ?>" target="_blank" class="doc-link" style="font-size: 0.8rem; padding: 0.2rem 0.5rem;">
                            <i class="fas fa-file-alt"></i> Requirements
                          </a>
                        <?php endif; ?>
                        <?php if (!empty($reg['documents_path'])): ?>
                          <a href="../<?= htmlspecialchars($reg['documents_path']) ?>" target="_blank" class="doc-link" style="font-size: 0.8rem; padding: 0.2rem 0.5rem;">
                            <i class="fas fa-file-alt"></i> Documents
                          </a>
                        <?php endif; ?>
                        <?php if (!empty($reg['receipt_path'])): ?>
                          <a href="../<?= htmlspecialchars($reg['receipt_path']) ?>" target="_blank" class="doc-link" style="font-size: 0.8rem; padding: 0.2rem 0.5rem;">
                            <i class="fas fa-receipt"></i> Receipt
                          </a>
                        <?php endif; ?>
                        <?php if (empty($reg['valid_id_path']) && empty($reg['requirements_path']) && empty($reg['documents_path']) && empty($reg['receipt_path'])): ?>
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
                    <th>Date Range</th>
                    <th>Location</th>
                    <th>Fee</th>
                    <th>Registrations</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events as $event): 
                    $eventStartDate = strtotime($event['event_date']);
                    $eventEndDate = strtotime($event['event_end_date'] ?? $event['event_date']);
                    $durationDays = $event['duration_days'] ?? 1;
                    
                    // NEW: Use time-aware status calculation
                    $now = time();
                    $eventStartDateTime = strtotime($event['event_date'] . ' ' . ($event['start_time'] ?? '09:00:00'));
                    $eventEndDateTime = strtotime(($event['event_end_date'] ?? $event['event_date']) . ' ' . ($event['end_time'] ?? '17:00:00'));
                    
                    // Determine event status with time precision
                    $eventStatus = 'upcoming';
                    if ($now > $eventEndDateTime) {
                        $eventStatus = 'past';
                    } elseif ($now >= $eventStartDateTime && $now <= $eventEndDateTime) {
                        $eventStatus = 'ongoing';
                    }
                    
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
                        <?php if ($eventStatus === 'ongoing'): ?>
                            <span class="ongoing-badge">Ongoing</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="event-service"><?= htmlspecialchars($event['major_service']) ?></span>
                    </td>
                    <td>
                        <div class="event-datetime">
                            <?php if ($durationDays == 1): ?>
                                <div class="event-date-single">
                                    <span class="event-date"><?= date('M d, Y', $eventStartDate) ?></span>
                                    <span class="event-time">
                                        <?= date('g:i A', strtotime($event['start_time'] ?? '09:00')) ?> - <?= date('g:i A', strtotime($event['end_time'] ?? '17:00')) ?>
                                    </span>
                                    <div class="event-duration">Single Day</div>
                                </div>
                            <?php else: ?>
                                <div class="event-date-start"><?= date('M d, Y', $eventStartDate) ?></div>
                                <div class="event-date-end">to <?= date('M d, Y', $eventEndDate) ?></div>
                                <div class="event-duration"><?= $durationDays ?> days</div>
                            <?php endif; ?>
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
                        <span class="status-badge <?= $eventStatus ?>">
                            <?php if ($eventStatus === 'upcoming'): ?>
                                <i class="fas fa-clock"></i> Upcoming
                            <?php elseif ($eventStatus === 'ongoing'): ?>
                                <i class="fas fa-play-circle"></i> Ongoing
                            <?php else: ?>
                                <i class="fas fa-check-circle"></i> Completed
                            <?php endif; ?>
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

  
<!-- Create/Edit Modal with Multi-Day Support -->
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
                    <label for="event_date">Start Date *</label>
                    <input type="date" id="event_date" name="event_date" required min="<?= date('Y-m-d') ?>">
                </div>
                
                <div class="form-group">
                    <label for="duration_days">Duration (Days) *</label>
                    <input type="number" id="duration_days" name="duration_days" min="1" max="365" value="1" required>
                    <small style="color: var(--gray);">How many days will this event run?</small>
                </div>
            </div>

            <!-- SIMPLIFIED Date Preview - No Conflict Warnings -->
            <div class="date-preview-container" id="datePreviewContainer" style="display: none;">
                <div class="date-preview">
                    <div class="date-preview-header">
                        <i class="fas fa-calendar-check"></i>
                        <span>Event Schedule Preview</span>
                    </div>
                    <div class="date-preview-content">
                        <div class="date-range">
                            <span class="start-date" id="previewStartDate">-</span>
                            <i class="fas fa-arrow-right"></i>
                            <span class="end-date" id="previewEndDate">-</span>
                        </div>
                        <div class="duration-display" id="previewDuration">1 day</div>
                    </div>
                </div>
            </div>

            <div class="conflict-warning" id="conflictWarning" style="display: none;">
                <div class="conflict-alert">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div class="conflict-content">
                        <div class="conflict-title">Date Conflict Detected!</div>
                        <div class="conflict-message" id="conflictMessage"></div>
                    </div>
                </div>
            </div>
            
             <div class="form-group">
                <label for="location">Location *</label>
                <input type="text" id="location" name="location" required placeholder="Event location" maxlength="255">
            </div>
            
            <!-- NEW: Time Fields Section -->
            <div class="form-row">
                <div class="form-group">
                    <label for="start_time">Start Time *</label>
                    <input type="time" id="start_time" name="start_time" required value="09:00">
                </div>
                
                <div class="form-group">
                    <label for="end_time">End Time *</label>
                    <input type="time" id="end_time" name="end_time" required value="17:00">
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
            
            <div class="form-notice">
                <i class="fas fa-info-circle"></i>
                <p>Events can be scheduled at any time without date restrictions. Multiple events can run simultaneously.</p>
            </div>
            
            <button type="submit" class="btn-submit" id="submitButton">
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

// UPDATED JavaScript for events.php - Remove all conflict checking

// Global variables
let currentEventId = null;

// 1. SIMPLIFIED date preview function WITHOUT conflict checking
function updateEventDatePreview() {
    const startDateInput = document.getElementById('event_date');
    const durationInput = document.getElementById('duration_days');
    const startTimeInput = document.getElementById('start_time');
    const endTimeInput = document.getElementById('end_time');
    const previewContainer = document.getElementById('datePreviewContainer');
    const previewStartDate = document.getElementById('previewStartDate');
    const previewEndDate = document.getElementById('previewEndDate');
    const previewDuration = document.getElementById('previewDuration');
    
    const startDate = startDateInput.value;
    const duration = parseInt(durationInput.value) || 1;
    const startTime = startTimeInput ? startTimeInput.value : '09:00';
    const endTime = endTimeInput ? endTimeInput.value : '17:00';
    
    if (startDate) {
        const start = new Date(startDate + 'T00:00:00');
        const end = new Date(start);
        end.setDate(end.getDate() + duration - 1);
        
        // Format times for display
        const formatTime = (timeStr) => {
            if (!timeStr) return '';
            const time = new Date(`2000-01-01T${timeStr}`);
            return time.toLocaleTimeString('en-US', {
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
        };
        
        // Update preview display with time information
        const startDateStr = start.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
        
        const endDateStr = end.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
        
        previewStartDate.textContent = `${startDateStr} at ${formatTime(startTime)}`;
        previewEndDate.textContent = `${endDateStr} at ${formatTime(endTime)}`;
        
        const durationText = duration === 1 ? '1 day' : `${duration} days`;
        previewDuration.textContent = durationText;
        
        previewContainer.style.display = 'block';
    } else {
        previewContainer.style.display = 'none';
    }
}

// 2. SIMPLIFIED openCreateModal function
function openCreateModal() {
    document.getElementById('modalTitle').textContent = 'Create New Event';
    document.getElementById('formAction').name = 'create_event';
    document.getElementById('eventForm').reset();
    document.getElementById('event_date').min = new Date().toISOString().split('T')[0];
    document.getElementById('duration_days').value = 1;
    document.getElementById('start_time').value = '09:00';
    document.getElementById('end_time').value = '17:00';
    
    currentEventId = null;
    
    // Hide preview initially (no conflict warnings needed)
    document.getElementById('datePreviewContainer').style.display = 'none';
    
    // Service selection logic
    const serviceSelect = document.getElementById('major_service');
    if (hasRestrictedAccess && allowedServices && allowedServices.length > 0) {
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
        Array.from(serviceSelect.options).forEach(option => {
            option.disabled = false;
            option.style.display = 'block';
        });
    }
    
    document.getElementById('eventModal').classList.add('active');
}

// 3. UPDATED openEditModal function with time fields
function openEditModal(event) {
    console.log('Opening edit modal for event:', event);
    
    document.getElementById('modalTitle').textContent = 'Edit Event';
    document.getElementById('formAction').name = 'update_event';
    document.getElementById('eventId').value = event.event_id;
    document.getElementById('title').value = event.title;
    document.getElementById('description').value = event.description || '';
    document.getElementById('major_service').value = event.major_service;
    document.getElementById('event_date').value = event.event_date;
    document.getElementById('duration_days').value = event.duration_days || 1;
    document.getElementById('start_time').value = event.start_time || '09:00';
    document.getElementById('end_time').value = event.end_time || '17:00';
    document.getElementById('location').value = event.location;
    document.getElementById('capacity').value = event.capacity || '';
    document.getElementById('fee').value = event.fee || '';
    
    currentEventId = event.event_id;
    
    // Update date preview immediately
    setTimeout(updateEventDatePreview, 100);
    
    // Service selection logic for editing
    const serviceSelect = document.getElementById('major_service');
    const serviceHint = document.getElementById('serviceHint');
    const isCreator = event.created_by == currentUserId;
    
    if (hasRestrictedAccess && allowedServices && allowedServices.length > 0) {
        Array.from(serviceSelect.options).forEach(option => {
            if (option.value === '') {
                option.disabled = false;
                option.style.display = 'block';
            } else if (option.value === event.major_service) {
                option.disabled = false;
                option.style.display = 'block';
            } else if (isCreator && allowedServices.includes(option.value)) {
                option.disabled = false;
                option.style.display = 'block';
            } else if (!isCreator && allowedServices.includes(option.value)) {
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
        Array.from(serviceSelect.options).forEach(option => {
            option.disabled = false;
            option.style.display = 'block';
        });
        if (serviceHint) {
            serviceHint.style.display = 'none';
        }
    }
    
    // Set proper date minimum for editing
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

    // SIMPLIFIED form validation - NO conflict checking
    const eventForm = document.getElementById('eventForm');
    if (eventForm) {
        eventForm.addEventListener('submit', function(e) {
            const eventDate = document.getElementById('event_date').value;
            const startTime = document.getElementById('start_time').value;
            const endTime = document.getElementById('end_time').value;
            const duration = parseInt(document.getElementById('duration_days').value) || 1;
            const title = document.getElementById('title').value.trim();
            const location = document.getElementById('location').value.trim();
            const majorService = document.getElementById('major_service').value;
            const isCreating = document.getElementById('formAction').name === 'create_event';
            
            console.log('Form submission - Service:', majorService, 'IsCreating:', isCreating, 'Duration:', duration, 'Times:', startTime, '-', endTime);
            
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
            
            // Time validation
            if (!startTime) {
                e.preventDefault();
                alert('Please select a start time.');
                return;
            }
            
            if (!endTime) {
                e.preventDefault();
                alert('Please select an end time.');
                return;
            }
            
            // Validate end time is after start time
            if (endTime <= startTime) {
                e.preventDefault();
                alert('End time must be after start time.');
                return;
            }
            
            // Validate minimum duration (1 hour)
            const start = new Date(`2000-01-01T${startTime}`);
            const end = new Date(`2000-01-01T${endTime}`);
            const timeDuration = (end - start) / (1000 * 60 * 60); // hours
            
            if (timeDuration < 1) {
                e.preventDefault();
                alert('Event must be at least 1 hour long.');
                return;
            }
            
            // Duration validation
            if (duration < 1 || duration > 365) {
                e.preventDefault();
                alert('Event duration must be between 1 and 365 days.');
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
            
            // REMOVED: Conflict checking - Events can overlap freely
            
            // Visual feedback
            const submitBtn = this.querySelector('.btn-submit');
            if (submitBtn) {
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
    
    // Add event listeners for preview updates
    const eventDateInput = document.getElementById('event_date');
    const durationInput = document.getElementById('duration_days');
    const startTimeInput = document.getElementById('start_time');
    const endTimeInput = document.getElementById('end_time');
    
    if (eventDateInput) {
        eventDateInput.addEventListener('change', updateEventDatePreview);
    }
    
    if (durationInput) {
        durationInput.addEventListener('input', updateEventDatePreview);
        durationInput.addEventListener('change', updateEventDatePreview);
    }
    
    if (startTimeInput) {
        startTimeInput.addEventListener('change', updateEventDatePreview);
    }
    
    if (endTimeInput) {
        endTimeInput.addEventListener('change', updateEventDatePreview);
    }
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

// Enhanced status filtering with ongoing events
function filterStatus(status) {
    const urlParams = new URLSearchParams(window.location.search);
    
    const currentService = urlParams.get('service');
    
    if (status === 'all') {
        urlParams.delete('status');
    } else {
        urlParams.set('status', status);
    }
    
    if (currentService) {
        urlParams.set('service', currentService);
    }
    
    urlParams.delete('page');
    
    window.location.search = urlParams.toString();
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

/* Enhanced styles for multi-day event display */
.event-date-range {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}

.event-date-single .event-date-start {
    font-weight: 600;
    color: var(--dark);
}

.event-date-start {
    font-weight: 600;
    color: var(--dark);
    font-size: 0.9rem;
}

.event-date-end {
    font-size: 0.85rem;
    color: var(--gray);
    font-style: italic;
}

.event-duration {
    font-size: 0.75rem;
    background: linear-gradient(135deg, var(--light) 0%, #e3f2fd 100%);
    color: var(--blue);
    padding: 0.2rem 0.4rem;
    border-radius: 4px;
    display: inline-block;
    margin-top: 0.2rem;
    font-weight: 500;
    border: 1px solid rgba(33, 150, 243, 0.2);
}

.status-badge.ongoing {
    background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
    color: #856404;
    border: 1px solid #f7dc6f;
}

.status-badge.ongoing i {
    color: #ff9800;
}

/* Date Preview Styles */
.date-preview-container {
    margin: 1rem 0;
    animation: slideDown 0.3s ease;
}

.date-preview {
    background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
    border: 2px solid #90caf9;
    border-radius: 12px;
    padding: 1rem;
}

.date-preview-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    color: var(--dark);
    margin-bottom: 0.5rem;
}

.date-preview-header i {
    color: var(--blue);
}

.date-preview-content {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.date-range {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1rem;
    font-size: 1.1rem;
    font-weight: 600;
}

.start-date, .end-date {
    background: white;
    padding: 0.5rem 1rem;
    border-radius: 8px;
    color: var(--dark);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.date-range i {
    color: var(--blue);
}

.duration-display {
    text-align: center;
    font-size: 0.9rem;
    color: var(--gray);
    font-weight: 500;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .date-range {
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .date-range i {
        transform: rotate(90deg);
    }
    
    .event-date-range {
        font-size: 0.8rem;
    }
    
    .event-duration {
        font-size: 0.7rem;
        padding: 0.1rem 0.3rem;
    }
}

@media (max-width: 576px) {
    .event-date-start,
    .event-date-end {
        font-size: 0.75rem;
    }
    
    .event-duration {
        font-size: 0.65rem;
    }
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
        max-height: 0;
    }
    to {
        opacity: 1;
        transform: translateY(0);
        max-height: 200px;
    }
}
</style>
  
  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
</body>
</html>