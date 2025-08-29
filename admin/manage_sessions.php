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
$selectedSession = null;
$registrations = [];
$sessions = [];
$totalSessions = 0;
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
    error_log("[SESSION_DEBUG] " . $message);
}

// Log current user role and permissions
logDebug("User role: $user_role, User ID: $current_user_id, Allowed services: " . implode(', ', $allowedServices));

function validateSessionData($data, $allowedServices, $hasRestrictedAccess, $isCreate = false) {
    $errors = [];
    
    $required = ['title', 'session_date', 'start_time', 'end_time', 'venue', 'major_service'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            $errors[] = "Please fill all required fields";
            break;
        }
    }
    
    // Check service permission for CREATE operations
    if ($isCreate && $hasRestrictedAccess && !empty($allowedServices)) {
        if (!in_array($data['major_service'] ?? '', $allowedServices)) {
            $errors[] = "You don't have permission to create sessions for this service. You can only create sessions for: " . implode(', ', $allowedServices);
        }
    }
    
    try {
        $date = $data['session_date'] ?? '';
        $startTime = $data['start_time'] ?? '';
        $endTime = $data['end_time'] ?? '';
        
        if (!DateTime::createFromFormat('Y-m-d', $date)) {
            $errors[] = "Invalid date format. Please use YYYY-MM-DD.";
        }
        
        $sessionDate = strtotime($date);
        $today = strtotime('today');
        if ($sessionDate < $today && $isCreate) {
            $errors[] = "Session date cannot be in the past.";
        }
        
        if (!empty($startTime) && !empty($endTime) && empty($errors)) {
            $startTimestamp = strtotime($date . ' ' . $startTime);
            $endTimestamp = strtotime($date . ' ' . $endTime);
            
            if ($startTimestamp === false) {
                $errors[] = "Invalid start time format.";
            } elseif ($endTimestamp === false) {
                $errors[] = "Invalid end time format.";
            } elseif ($endTimestamp <= $startTimestamp) {
                $errors[] = "End time must be after start time.";
            }
            
            if ($endTimestamp - $startTimestamp < 3600) {
                $errors[] = "Session must be at least 1 hour long.";
            }
        }
    } catch (Exception $e) {
        $errors[] = "Invalid date/time values: " . $e->getMessage();
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
            'major_service' => trim($data['major_service'] ?? ''),
            'session_date' => $data['session_date'] ?? '',
            'start_time' => trim($data['start_time'] ?? ''),
            'end_time' => trim($data['end_time'] ?? ''),
            'venue' => trim($data['venue'] ?? ''),
            'capacity' => $capacity,
            'fee' => $fee
        ]
    ];
}

function handleDatabaseError($e) {
    error_log("Database error: " . $e->getMessage());
    return "Database error occurred. Please try again later.";
}

function checkSessionAccess($pdo, $sessionId, $allowedServices, $hasRestrictedAccess, $userId) {
    if (!$hasRestrictedAccess) {
        logDebug("Super admin accessing session $sessionId - access granted");
        return true; // Super admin has access to all sessions
    }
    
    try {
        $stmt = $pdo->prepare("SELECT major_service, created_by FROM training_sessions WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch();
        
        if (!$session) {
            logDebug("Session $sessionId not found");
            return false; // Session doesn't exist
        }
        
        // Allow access if:
        // 1. They created it (regardless of service)
        // 2. It's in their allowed services
        $hasAccess = ($session['created_by'] == $userId) || in_array($session['major_service'], $allowedServices);
        
        logDebug("Session $sessionId access check - Created by: {$session['created_by']}, User: $userId, Service: {$session['major_service']}, Has access: " . ($hasAccess ? 'yes' : 'no'));
        
        return $hasAccess;
    } catch (PDOException $e) {
        error_log("Error checking session access: " . $e->getMessage());
        return false;
    }
}

// Handle CREATE session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_session'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security error: Invalid form submission. Please try again.";
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        logDebug("Creating session for service: " . ($_POST['major_service'] ?? 'none'));
        
        $validation = validateSessionData($_POST, $allowedServices, $hasRestrictedAccess, true);
        
        if ($validation['valid']) {
            try {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM training_sessions 
                    WHERE venue = ? AND session_date = ? 
                    AND ((start_time <= ? AND end_time > ?) OR (start_time < ? AND end_time >= ?))
                ");
                $stmt->execute([
                    $validation['data']['venue'],
                    $validation['data']['session_date'],
                    $validation['data']['start_time'],
                    $validation['data']['start_time'],
                    $validation['data']['end_time'],
                    $validation['data']['end_time']
                ]);
                
                if ($stmt->fetchColumn() > 0) {
                    $errorMessage = "Another session is already scheduled at this venue during the selected time.";
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO training_sessions 
                        (title, major_service, session_date, start_time, end_time, venue, capacity, fee, created_at, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                    ");
                    
                    $result = $stmt->execute([
                        $validation['data']['title'],
                        $validation['data']['major_service'],
                        $validation['data']['session_date'],
                        $validation['data']['start_time'],
                        $validation['data']['end_time'],
                        $validation['data']['venue'],
                        $validation['data']['capacity'],
                        $validation['data']['fee'],
                        $current_user_id
                    ]);
                    
                    if ($result) {
                        $newSessionId = $pdo->lastInsertId();
                        logDebug("Successfully created session ID: $newSessionId for service: " . $validation['data']['major_service'] . " by user: $current_user_id");
                        $successMessage = "Training session created successfully! Session ID: " . $newSessionId;
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    } else {
                        $errorMessage = "Failed to create session. Please try again.";
                        logDebug("Failed to insert session into database");
                    }
                }
            } catch (PDOException $e) {
                logDebug("Database error creating session: " . $e->getMessage());
                $errorMessage = handleDatabaseError($e);
            }
        } else {
            logDebug("Session validation failed: " . implode(', ', $validation['errors']));
            $errorMessage = implode("<br>", $validation['errors']);
        }
    }
}

// Handle UPDATE session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_session'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security error: Invalid form submission. Please try again.";
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $session_id = (int)($_POST['session_id'] ?? 0);
        
        // Check access permission (creator or service permission)
        if (!checkSessionAccess($pdo, $session_id, $allowedServices, $hasRestrictedAccess, $current_user_id)) {
            $errorMessage = "You don't have permission to edit this session.";
        } else {
            $validation = validateSessionData($_POST, $allowedServices, $hasRestrictedAccess, false);
            
            if ($validation['valid'] && $session_id > 0) {
                try {
                    $checkStmt = $pdo->prepare("SELECT created_by, major_service FROM training_sessions WHERE session_id = ?");
                    $checkStmt->execute([$session_id]);
                    $sessionData = $checkStmt->fetch();
                    
                    if (!$sessionData) {
                        $errorMessage = "Session not found.";
                    } else {
                        // Allow update if:
                        // 1. They created the session (can change to any service they have permission for)
                        // 2. They have permission for BOTH the current service AND the new service
                        $canUpdate = false;
                        $newService = $_POST['major_service'];
                        
                        if ($sessionData['created_by'] == $current_user_id) {
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
                            $canUpdate = in_array($sessionData['major_service'], $allowedServices) && 
                                        in_array($newService, $allowedServices);
                            if (!$canUpdate) {
                                $errorMessage = "You don't have permission to edit this session or change it to the selected service.";
                            }
                        }
                        
                        if ($canUpdate) {
                            // Check for time conflicts
                            $stmt = $pdo->prepare("
                                SELECT COUNT(*) FROM training_sessions 
                                WHERE venue = ? AND session_date = ? AND session_id != ?
                                AND ((start_time <= ? AND end_time > ?) OR (start_time < ? AND end_time >= ?))
                            ");
                            $stmt->execute([
                                $validation['data']['venue'],
                                $validation['data']['session_date'],
                                $session_id,
                                $validation['data']['start_time'],
                                $validation['data']['start_time'],
                                $validation['data']['end_time'],
                                $validation['data']['end_time']
                            ]);
                            
                            if ($stmt->fetchColumn() > 0) {
                                $errorMessage = "Another session is already scheduled at this venue during the selected time.";
                            } else {
                                $stmt = $pdo->prepare("
                                    UPDATE training_sessions
                                    SET title = ?, major_service = ?, session_date = ?, 
                                        start_time = ?, end_time = ?, venue = ?, 
                                        capacity = ?, fee = ?
                                    WHERE session_id = ?
                                ");
                                
                                $result = $stmt->execute([
                                    $validation['data']['title'],
                                    $validation['data']['major_service'],
                                    $validation['data']['session_date'],
                                    $validation['data']['start_time'],
                                    $validation['data']['end_time'],
                                    $validation['data']['venue'],
                                    $validation['data']['capacity'],
                                    $validation['data']['fee'],
                                    $session_id
                                ]);
                                
                                if ($result) {
                                    logDebug("Session $session_id updated successfully by user $current_user_id");
                                    $successMessage = "Session updated successfully!";
                                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                                } else {
                                    $errorMessage = "Failed to update session. Please try again.";
                                }
                            }
                        }
                    }
                } catch (PDOException $e) {
                    $errorMessage = handleDatabaseError($e);
                }
            } else {
                $errorMessage = $validation['valid'] ? "Invalid session ID" : implode("<br>", $validation['errors']);
            }
        }
    }
}

// Handle DELETE session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_session'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security error: Invalid form submission. Please try again.";
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $session_id = (int)($_POST['session_id'] ?? 0);
        
        // Check access permission (creator or service permission)
        if (!checkSessionAccess($pdo, $session_id, $allowedServices, $hasRestrictedAccess, $current_user_id)) {
            $errorMessage = "You don't have permission to delete this session.";
        } else {
            if ($session_id > 0) {
                try {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM session_registrations WHERE session_id = ?");
                    $stmt->execute([$session_id]);
                    $registrations = $stmt->fetchColumn();
                    
                    if ($registrations > 0) {
                        $errorMessage = "Cannot delete session with existing registrations. Please cancel all registrations first.";
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM training_sessions WHERE session_id = ?");
                        $result = $stmt->execute([$session_id]);
                        
                        if ($result && $stmt->rowCount() > 0) {
                            $successMessage = "Session deleted successfully.";
                            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        } else {
                            $errorMessage = "Session not found or already deleted.";
                        }
                    }
                } catch (PDOException $e) {
                    $errorMessage = handleDatabaseError($e);
                }
            } else {
                $errorMessage = "Invalid session ID";
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
                // Check if user has access to this registration's session
                $stmt = $pdo->prepare("
                    SELECT ts.major_service, ts.session_id, ts.created_by
                    FROM session_registrations sr 
                    JOIN training_sessions ts ON sr.session_id = ts.session_id 
                    WHERE sr.registration_id = ?
                ");
                $stmt->execute([$registration_id]);
                $regSession = $stmt->fetch();
                
                if (!$regSession) {
                    $errorMessage = "Registration not found.";
                } else if ($hasRestrictedAccess && !in_array($regSession['major_service'], $allowedServices) && $regSession['created_by'] != $current_user_id) {
                    $errorMessage = "You don't have permission to manage this registration.";
                } else {
                    $stmt = $pdo->prepare("UPDATE session_registrations SET status = ? WHERE registration_id = ?");
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
                // Check if user has access to this registration's session
                $stmt = $pdo->prepare("
                    SELECT ts.major_service, ts.created_by
                    FROM session_registrations sr 
                    JOIN training_sessions ts ON sr.session_id = ts.session_id 
                    WHERE sr.registration_id = ?
                ");
                $stmt->execute([$registration_id]);
                $regSession = $stmt->fetch();
                
                if (!$regSession) {
                    $errorMessage = "Registration not found.";
                } else if ($hasRestrictedAccess && !in_array($regSession['major_service'], $allowedServices) && $regSession['created_by'] != $current_user_id) {
                    $errorMessage = "You don't have permission to delete this registration.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM session_registrations WHERE registration_id = ?");
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
$viewSession = isset($_GET['view_session']) ? (int)$_GET['view_session'] : 0;
$page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$limit = 20;
$offset = ($page - 1) * $limit;

try {
    $whereConditions = [];
    $params = [];
    
    // Add service restriction OR created_by restriction for non-super admins
    if ($hasRestrictedAccess && !empty($allowedServices)) {
        // Allow seeing sessions they created OR sessions in their allowed services
        $placeholders = str_repeat('?,', count($allowedServices) - 1) . '?';
        $whereConditions[] = "(ts.major_service IN ($placeholders) OR ts.created_by = ?)";
        $params = array_merge($params, $allowedServices);
        $params[] = $current_user_id;
        
        logDebug("Service filter applied with creator exception. Allowed services: " . implode(', ', $allowedServices) . " OR created_by: " . $current_user_id);
    }
    
    if ($search) {
        $whereConditions[] = "(ts.title LIKE ? OR ts.venue LIKE ?)";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    
    // Fixed service filter logic
    if ($serviceFilter && $serviceFilter !== '') {
        if (!$hasRestrictedAccess) {
            // Super admin can filter by any service
            $whereConditions[] = "ts.major_service = ?";
            $params[] = $serviceFilter;
        } else if (in_array($serviceFilter, $allowedServices)) {
            // Restricted user filtering by allowed service
            $whereConditions[] = "ts.major_service = ?";
            $params[] = $serviceFilter;
        } else {
            // Restricted user filtering by non-allowed service - show only their created sessions
            $whereConditions[] = "(ts.major_service = ? AND ts.created_by = ?)";
            $params[] = $serviceFilter;
            $params[] = $current_user_id;
        }
    }
    
    if ($statusFilter === 'upcoming') {
        $whereConditions[] = "ts.session_date >= CURDATE()";
    } elseif ($statusFilter === 'past') {
        $whereConditions[] = "ts.session_date < CURDATE()";
    }
    
    $whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Modified query to include creator information and properly select all fields
    $query = "
        SELECT SQL_CALC_FOUND_ROWS 
               ts.session_id,
               ts.title,
               ts.major_service,
               ts.session_date,
               ts.start_time,
               ts.end_time,
               ts.venue,
               ts.capacity,
               ts.fee,
               ts.created_at,
               ts.created_by,
               COUNT(sr.registration_id) AS registrations_count,
               u.email as creator_email,
               CASE 
                   WHEN ts.created_by = ? THEN 1 
                   ELSE 0 
               END as is_my_session
        FROM training_sessions ts
        LEFT JOIN session_registrations sr ON ts.session_id = sr.session_id
        LEFT JOIN users u ON ts.created_by = u.user_id
        $whereClause
        GROUP BY ts.session_id, ts.title, ts.major_service, ts.session_date, 
                 ts.start_time, ts.end_time, ts.venue, ts.capacity, ts.fee, 
                 ts.created_at, ts.created_by, u.email
        ORDER BY ts.session_date ASC, ts.start_time ASC
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
    $sessions = $stmt->fetchAll();
    
    // Get total count
    $stmt = $pdo->query("SELECT FOUND_ROWS()");
    $totalSessions = $stmt->fetchColumn();
    $totalPages = ceil($totalSessions / $limit);
    
    logDebug("Found " . count($sessions) . " sessions for user role: $user_role, user_id: $current_user_id");
    
    // Get service stats
    foreach ($majorServices as $service) {
        if ($hasRestrictedAccess && !empty($allowedServices)) {
            if (in_array($service, $allowedServices)) {
                $stmt = $pdo->prepare("
                    SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN session_date >= CURDATE() THEN 1 ELSE 0 END) as upcoming,
                        SUM(CASE WHEN session_date < CURDATE() THEN 1 ELSE 0 END) as past
                    FROM training_sessions
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
                    SUM(CASE WHEN session_date >= CURDATE() THEN 1 ELSE 0 END) as upcoming,
                    SUM(CASE WHEN session_date < CURDATE() THEN 1 ELSE 0 END) as past
                FROM training_sessions
                WHERE major_service = ?
            ");
            $stmt->execute([$service]);
            $stats[$service] = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'upcoming' => 0, 'past' => 0];
        }
    }
    
    // Get total stats with service restrictions including created sessions
    if ($hasRestrictedAccess && !empty($allowedServices)) {
        $placeholders = str_repeat('?,', count($allowedServices) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN session_date >= CURDATE() THEN 1 ELSE 0 END) as upcoming,
                SUM(CASE WHEN session_date < CURDATE() THEN 1 ELSE 0 END) as past
            FROM training_sessions
            WHERE major_service IN ($placeholders) OR created_by = ?
        ");
        $statsParams = array_merge($allowedServices, [$current_user_id]);
        $stmt->execute($statsParams);
        $totalStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'upcoming' => 0, 'past' => 0];
    } else {
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN session_date >= CURDATE() THEN 1 ELSE 0 END) as upcoming,
                SUM(CASE WHEN session_date < CURDATE() THEN 1 ELSE 0 END) as past
            FROM training_sessions
        ");
        $totalStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'upcoming' => 0, 'past' => 0];
    }
    
    // Get session details and registrations if viewing a specific session
    if ($viewSession > 0) {
        // Check access to this session
        if (checkSessionAccess($pdo, $viewSession, $allowedServices, $hasRestrictedAccess, $current_user_id)) {
            $stmt = $pdo->prepare("SELECT * FROM training_sessions WHERE session_id = ?");
            $stmt->execute([$viewSession]);
            $selectedSession = $stmt->fetch();
            
            if ($selectedSession) {
                $stmt = $pdo->prepare("
                    SELECT sr.*, u.email as user_email
                    FROM session_registrations sr
                    LEFT JOIN users u ON sr.user_id = u.user_id
                    WHERE sr.session_id = ?
                    ORDER BY sr.registration_date DESC
                ");
                $stmt->execute([$viewSession]);
                $registrations = $stmt->fetchAll() ?: [];
            }
        } else {
            $errorMessage = "You don't have permission to view this session.";
        }
    }
    
} catch (PDOException $e) {
    logDebug("Error fetching sessions: " . $e->getMessage());
    $errorMessage = handleDatabaseError($e);
    
    // Ensure all variables are initialized even on error
    $sessions = [];
    $totalSessions = 0;
    $totalPages = 1;
    $stats = [];
    $totalStats = ['total' => 0, 'upcoming' => 0, 'past' => 0];
    $selectedSession = null;
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
  <title>Training Sessions Management - PRC Admin</title>
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
  <link rel="stylesheet" href="../assets/sessions.css?v=<?= time() ?>">
</head>
<body class="admin-<?= htmlspecialchars($user_role) ?>">
  <?php include 'sidebar.php'; ?>
  
  <div class="sessions-container">
    <div class="page-header">
      <h1><i class="fas fa-graduation-cap"></i> Training Sessions Management</h1>
      <p>
        <?php if ($hasRestrictedAccess): ?>
          Manage <?= htmlspecialchars(implode(' and ', $allowedServices)) ?> training sessions and participant registrations
        <?php else: ?>
          Schedule and manage training sessions and participant registrations
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

    <?php if ($selectedSession): ?>
      <!-- Registration View -->
      <div class="registrations-view">
        <a href="?<?= http_build_query(array_filter(['search' => $search, 'service' => $serviceFilter, 'status' => $statusFilter])) ?>" class="back-to-sessions">
          <i class="fas fa-arrow-left"></i> Back to Sessions
        </a>
        
        <div class="session-info-header">
          <div class="session-info-details">
            <div class="session-info-title"><?= htmlspecialchars($selectedSession['title']) ?></div>
            <div class="session-info-meta">
              <span><i class="fas fa-tag"></i> <?= htmlspecialchars($selectedSession['major_service']) ?></span>
              <span><i class="fas fa-calendar"></i> <?= date('M j, Y', strtotime($selectedSession['session_date'])) ?></span>
              <span><i class="fas fa-clock"></i> <?= date('g:i A', strtotime($selectedSession['start_time'])) ?> - <?= date('g:i A', strtotime($selectedSession['end_time'])) ?></span>
              <span><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($selectedSession['venue']) ?></span>
              <?php if ($selectedSession['fee'] > 0): ?>
                <span><i class="fas fa-money-bill"></i> ₱<?= number_format($selectedSession['fee'], 2) ?></span>
              <?php endif; ?>
              <?php if ($selectedSession['capacity'] > 0): ?>
                <span><i class="fas fa-users"></i> Capacity: <?= $selectedSession['capacity'] ?></span>
              <?php endif; ?>
            </div>
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
            <p>No participants have registered for this session yet.</p>
          </div>
        <?php else: ?>
          <div class="table-container">
            <table class="registrations-table">
              <thead>
                <tr>
                  <th>Participant</th>
                  <th>Registration ID</th>
                  <th>Registration Type</th>
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
                          <?= strtoupper(substr($reg['full_name'] ?? $reg['organization_name'] ?? 'U', 0, 1)) ?>
                        </div>
                        <div class="participant-details">
                          <div class="participant-name">
                            <?= htmlspecialchars($reg['full_name'] ?? $reg['organization_name'] ?? 'Unknown') ?>
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
                      <span class="type-badge <?= $reg['registration_type'] ?>">
                        <?= ucfirst($reg['registration_type']) ?>
                      </span>
                      <?php if ($reg['registration_type'] === 'organization' && $reg['pax_count']): ?>
                        <small style="display: block; color: var(--gray); margin-top: 0.2rem;">
                          <?= $reg['pax_count'] ?> participants
                        </small>
                      <?php endif; ?>
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
                            <i class="fas fa-file-alt"></i> Docs
                          </a>
                        <?php endif; ?>
                        <?php if (empty($reg['valid_id_path']) && empty($reg['requirements_path'])): ?>
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
      <!-- Sessions List View -->
      
      <!-- Service Filter Tabs (only show allowed services) -->
      <div class="service-tabs">
        <a href="?status=<?= htmlspecialchars($statusFilter) ?>" class="service-tab all-services <?= !$serviceFilter ? 'active' : '' ?>">
          <div class="service-name">
            <?= $hasRestrictedAccess ? 'My Services' : 'All Services' ?>
          </div>
          <div class="service-count"><?= $totalStats['total'] ?> sessions</div>
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
            <div class="service-count"><?= $serviceStats['total'] ?> sessions</div>
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
            <input type="text" name="search" placeholder="Search sessions..." value="<?= htmlspecialchars($search) ?>">
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
            <i class="fas fa-plus-circle"></i> Create New Session
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
            <div style="color: var(--gray); font-size: 0.9rem;">Total Sessions</div>
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
            <div style="font-size: 1.5rem; font-weight: 700;"><?= !empty($sessions) ? array_sum(array_column($sessions, 'registrations_count')) : 0 ?></div>
            <div style="color: var(--gray); font-size: 0.9rem;">Total Registrations</div>
          </div>
        </div>
      </div>

      <!-- Sessions Table -->
      <div class="sessions-table-wrapper">
        <div class="table-header">
          <h2 class="table-title">
            <?php if ($serviceFilter): ?>
              <?= htmlspecialchars($serviceFilter) ?> Sessions
            <?php elseif ($hasRestrictedAccess): ?>
              <?= htmlspecialchars(implode(' & ', $allowedServices)) ?> Sessions
            <?php else: ?>
              All Training Sessions
            <?php endif; ?>
          </h2>
        </div>
        
        <?php if (empty($sessions)): ?>
          <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <h3>No sessions found</h3>
            <p><?= $search ? 'Try adjusting your search criteria' : 'Click "Create New Session" to get started' ?></p>
            <?php if ($hasRestrictedAccess): ?>
              <small style="color: var(--gray); margin-top: 0.5rem;">
                You can only view and manage <?= htmlspecialchars(implode(', ', $allowedServices)) ?> sessions.
              </small>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Session Details</th>
                <th>Service</th>
                <th>Date & Time</th>
                <th>Venue</th>
                <th>Fee</th>
                <th>Registrations</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sessions as $session): 
                $sessionDate = strtotime($session['session_date']);
                $today = strtotime('today');
                $isUpcoming = $sessionDate >= $today;
                $isFull = $session['capacity'] > 0 && $session['registrations_count'] >= $session['capacity'];
              ?>
                <tr>
                  <td>
                    <div class="session-title"><?= htmlspecialchars($session['title']) ?></div>
                    <div style="font-size: 0.75rem; color: var(--gray); margin-top: 0.2rem;">ID: #<?= $session['session_id'] ?></div>
                  </td>
                  <td>
                    <span class="session-service"><?= htmlspecialchars($session['major_service']) ?></span>
                  </td>
                  <td>
                    <div class="session-datetime">
                      <span class="session-date"><?= date('M d, Y', $sessionDate) ?></span>
                      <span class="session-time"><?= date('g:i A', strtotime($session['start_time'])) ?> - <?= date('g:i A', strtotime($session['end_time'])) ?></span>
                    </div>
                  </td>
                  <td><?= htmlspecialchars($session['venue']) ?></td>
                  <td>
                    <div class="fee-display">
                      <?php if ($session['fee'] > 0): ?>
                        <span class="fee-amount">₱<?= number_format($session['fee'], 2) ?></span>
                      <?php else: ?>
                        <span class="fee-free">FREE</span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td>
                    <a href="?view_session=<?= $session['session_id'] ?>&<?= http_build_query(array_filter(['search' => $search, 'service' => $serviceFilter, 'status' => $statusFilter])) ?>" 
                       class="registrations-badge <?= $isFull ? 'full' : '' ?>">
                      <i class="fas fa-users"></i>
                      <?= $session['registrations_count'] ?> / <?= $session['capacity'] ?: '∞' ?>
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
                    <a href="?view_session=<?= $session['session_id'] ?>&<?= http_build_query(array_filter(['search' => $search, 'service' => $serviceFilter, 'status' => $statusFilter])) ?>" 
                       class="btn-action btn-view">
                      <i class="fas fa-users"></i> View Registrations
                    </a>
                    <button class="btn-action btn-edit" onclick='openEditModal(<?= json_encode($session, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                      <i class="fas fa-edit"></i> Edit
                    </button>
                    <form method="POST" style="display: inline;" onsubmit="return confirmDelete('<?= htmlspecialchars($session['title'], ENT_QUOTES) ?>', <?= $session['registrations_count'] ?>);">
                      <input type="hidden" name="delete_session" value="1">
                      <input type="hidden" name="session_id" value="<?= $session['session_id'] ?>">
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
              
              <span class="page-info">Page <?= $page ?> of <?= $totalPages ?> (<?= $totalSessions ?> total sessions)</span>
              
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
  <div class="modal" id="sessionModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitle">Create New Session</h2>
        <button class="close-modal" onclick="closeModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <form method="POST" id="sessionForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="create_session" value="1" id="formAction">
        <input type="hidden" name="session_id" id="sessionId">
        
        <div class="form-group">
          <label for="title">Session Title *</label>
          <input type="text" id="title" name="title" required placeholder="Enter session title" maxlength="255">
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
                  You can only create sessions for: <?= htmlspecialchars(implode(', ', $allowedServices)) ?>
              </small>
          <?php endif; ?>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label for="session_date">Date *</label>
            <input type="date" id="session_date" name="session_date" required min="<?= date('Y-m-d') ?>">
          </div>
          
          <div class="form-group">
            <label for="venue">Venue *</label>
            <input type="text" id="venue" name="venue" required placeholder="Location" maxlength="255">
          </div>
        </div>
        
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
            <input type="number" id="capacity" name="capacity" min="0" max="1000" placeholder="0 for unlimited">
            <small style="color: var(--gray);">Leave empty or set to 0 for unlimited capacity</small>
          </div>
          
          <div class="form-group">
            <label for="fee">Fee (₱)</label>
            <input type="number" id="fee" name="fee" min="0" step="0.01" placeholder="0.00">
            <small style="color: var(--gray);">Leave empty or set to 0 for free session</small>
          </div>
        </div>
        
        <button type="submit" class="btn-submit">
          <i class="fas fa-save"></i> Save Session
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
      document.getElementById('modalTitle').textContent = 'Create New Session';
      document.getElementById('formAction').name = 'create_session';
      document.getElementById('sessionForm').reset();
      document.getElementById('session_date').min = new Date().toISOString().split('T')[0];
      
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
      
      document.getElementById('sessionModal').classList.add('active');
  }

  function openEditModal(session) {
      console.log('Opening edit modal for session:', session);
      
      document.getElementById('modalTitle').textContent = 'Edit Session';
      document.getElementById('formAction').name = 'update_session';
      document.getElementById('sessionId').value = session.session_id;
      document.getElementById('title').value = session.title;
      document.getElementById('major_service').value = session.major_service;
      document.getElementById('session_date').value = session.session_date;
      document.getElementById('start_time').value = session.start_time;
      document.getElementById('end_time').value = session.end_time;
      document.getElementById('venue').value = session.venue;
      document.getElementById('capacity').value = session.capacity || '';
      document.getElementById('fee').value = session.fee || '';
      
      // For edit modal, show appropriate services based on permissions
      const serviceSelect = document.getElementById('major_service');
      const serviceHint = document.getElementById('serviceHint');
      
      if (hasRestrictedAccess && allowedServices && allowedServices.length > 0) {
          const isCreator = session.created_by == currentUserId;
          
          Array.from(serviceSelect.options).forEach(option => {
              if (option.value === '') {
                  option.disabled = false;
                  option.style.display = 'block';
              } else if (option.value === session.major_service) {
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
      const sessionDate = new Date(session.session_date);
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      
      if (sessionDate < today) {
          document.getElementById('session_date').min = session.session_date;
      } else {
          document.getElementById('session_date').min = new Date().toISOString().split('T')[0];
      }
      
      document.getElementById('sessionModal').classList.add('active');
  }

  function closeModal() {
      document.getElementById('sessionModal').classList.remove('active');
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
          return confirm(`Are you sure you want to delete "${title}"?\n\nThis session has ${registrationCount} registration(s).\nYou should cancel all registrations first.`);
      }
      return confirm(`Are you sure you want to delete "${title}"?\n\nThis action cannot be undone.`);
  }

  function confirmDeleteRegistration() {
      return confirm('Are you sure you want to delete this registration?\n\nThis action cannot be undone.');
  }

  // Close modal when clicking outside
  document.addEventListener('DOMContentLoaded', function() {
      const sessionModal = document.getElementById('sessionModal');
      if (sessionModal) {
          sessionModal.addEventListener('click', function(e) {
              if (e.target === this) {
                  closeModal();
              }
          });
      }

      // Form validation with role-based restrictions
      const sessionForm = document.getElementById('sessionForm');
      if (sessionForm) {
          sessionForm.addEventListener('submit', function(e) {
              const startTime = document.getElementById('start_time').value;
              const endTime = document.getElementById('end_time').value;
              const sessionDate = document.getElementById('session_date').value;
              const title = document.getElementById('title').value.trim();
              const venue = document.getElementById('venue').value.trim();
              const majorService = document.getElementById('major_service').value;
              const isCreating = document.getElementById('formAction').name === 'create_session';
              
              console.log('Form submission - Service:', majorService, 'IsCreating:', isCreating, 'Allowed:', allowedServices);
              
              // Basic validation
              if (!title) {
                  e.preventDefault();
                  alert('Please enter a session title.');
                  return;
              }
              
              if (!venue) {
                  e.preventDefault();
                  alert('Please enter a venue.');
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
                      alert("You don't have permission to create sessions for " + majorService + 
                            "\n\nYou can only create sessions for: " + allowedServices.join(', '));
                      return;
                  }
              }
              
              if (endTime <= startTime) {
                  e.preventDefault();
                  alert('End time must be after start time');
                  return;
              }
              
              const start = new Date(`2000-01-01T${startTime}`);
              const end = new Date(`2000-01-01T${endTime}`);
              const duration = (end - start) / (1000 * 60 * 60);
              
              if (duration < 1) {
                  e.preventDefault();
                  alert('Session must be at least 1 hour long');
                  return;
              }
              
              const selectedDate = new Date(sessionDate);
              const today = new Date();
              today.setHours(0, 0, 0, 0);
              
              if (selectedDate < today && isCreating) {
                  e.preventDefault();
                  alert('Session date cannot be in the past for new sessions');
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
      console.log('=== Session Management Permissions ===');
      console.log('User Role:', userRole);
      console.log('Has Restricted Access:', hasRestrictedAccess);
      console.log('Allowed Services:', allowedServices);
      console.log('Current User ID:', currentUserId);
      console.log('======================================');
  });

  // Keyboard shortcuts
  document.addEventListener('keydown', function(e) {
      // Escape key to close modal
      if (e.key === 'Escape') {
          const modal = document.getElementById('sessionModal');
          if (modal && modal.classList.contains('active')) {
              closeModal();
          }
      }
      
      // Ctrl/Cmd + N to create new session
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
    
    .sessions-container {
      --role-accent: var(--current-role-color);
    }
    
    .session-service {
      background: linear-gradient(135deg, var(--current-role-color)20 0%, <?= get_role_color($user_role) ?>15 100%);
      color: var(--current-role-color);
      padding: 0.2rem 0.6rem;
      border-radius: 12px;
      font-size: 0.8rem;
      font-weight: 600;
    }
  </style>
  
  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
</body>a
</html>