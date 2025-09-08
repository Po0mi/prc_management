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
    $venue = trim($data['venue'] ?? '');
    if (strlen($venue) > 2000) {
        $errors[] = "Venue description is too long. Please keep it under 2000 characters.";
    }
    if (strlen($venue) < 5) {
        $errors[] = "Please provide a more detailed venue location.";
    }
    
    // Add instructor validation
    $instructor = trim($data['instructor'] ?? '');
    $instructorBio = trim($data['instructor_bio'] ?? '');
    $instructorCredentials = trim($data['instructor_credentials'] ?? '');

    // Validate instructor if provided
    if (!empty($instructor) && strlen($instructor) > 100) {
        $errors[] = "Instructor name is too long. Please keep it under 100 characters.";
    }

    if (!empty($instructorBio) && strlen($instructorBio) > 1000) {
        $errors[] = "Instructor bio is too long. Please keep it under 1000 characters.";
    }

    if (!empty($instructorCredentials) && strlen($instructorCredentials) > 500) {
        $errors[] = "Instructor credentials are too long. Please keep it under 500 characters.";
    }
    
    // Check service permission for CREATE operations
    if ($isCreate && $hasRestrictedAccess && !empty($allowedServices)) {
        if (!in_array($data['major_service'] ?? '', $allowedServices)) {
            $errors[] = "You don't have permission to create sessions for this service. You can only create sessions for: " . implode(', ', $allowedServices);
        }
    }
    
    try {
        $startDate = $data['session_date'] ?? '';
        $durationDays = isset($data['duration_days']) ? max(1, (int)$data['duration_days']) : 1;
        $startTime = $data['start_time'] ?? '';
        $endTime = $data['end_time'] ?? '';
        
        if (!DateTime::createFromFormat('Y-m-d', $startDate)) {
            $errors[] = "Invalid start date format. Please use YYYY-MM-DD.";
        }
        
        $startDateTime = strtotime($startDate);
        $today = strtotime('today');
        if ($startDateTime < $today && $isCreate) {
            $errors[] = "Session start date cannot be in the past.";
        }
        // Calculate end date
        $endDateTime = $startDateTime + (($durationDays - 1) * 24 * 60 * 60);
        $endDate = date('Y-m-d', $endDateTime);
        
        // Validate duration
        if ($durationDays < 1 || $durationDays > 365) {
            $errors[] = "Duration must be between 1 and 365 days.";
        }
        
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
            'session_date' => $startDate,
            'session_end_date' => $endDate ?? $startDate,
            'duration_days' => $durationDays,
            'start_time' => trim($data['start_time'] ?? ''),
            'end_time' => trim($data['end_time'] ?? ''),
            'venue' => $venue,
            'instructor' => $instructor,
            'instructor_bio' => $instructorBio,
            'instructor_credentials' => $instructorCredentials,
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
                // DIRECTLY INSERT WITHOUT CONFLICT CHECK:
                $stmt = $pdo->prepare("
                    INSERT INTO training_sessions 
                    (title, major_service, session_date, session_end_date, duration_days, start_time, end_time, 
                     venue, instructor, instructor_bio, instructor_credentials, capacity, fee, created_at, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                ");
                
                $result = $stmt->execute([
                    $validation['data']['title'],
                    $validation['data']['major_service'],
                    $validation['data']['session_date'],
                    $validation['data']['session_end_date'],
                    $validation['data']['duration_days'],
                    $validation['data']['start_time'],
                    $validation['data']['end_time'],
                    $validation['data']['venue'],
                    $validation['data']['instructor'],
                    $validation['data']['instructor_bio'],
                    $validation['data']['instructor_credentials'],
                    $validation['data']['capacity'],
                    $validation['data']['fee'],
                    $current_user_id
                ]);
                
                if ($result) {
                    $newSessionId = $pdo->lastInsertId();
                    logDebug("Successfully created session ID: $newSessionId for service: " . $validation['data']['major_service'] . " by user: $current_user_id");
                    
                    $durationText = $validation['data']['duration_days'] > 1 ? 
                        " ({$validation['data']['duration_days']} days)" : "";
                    $successMessage = "Training session created successfully! Session ID: " . $newSessionId . $durationText;
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                } else {
                    $errorMessage = "Failed to create session. Please try again.";
                    logDebug("Failed to insert session into database");
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
                        $canUpdate = false;
                        $newService = $_POST['major_service'];
                        
                        if ($sessionData['created_by'] == $current_user_id) {
                            $canUpdate = !$hasRestrictedAccess || in_array($newService, $allowedServices);
                            if (!$canUpdate) {
                                $errorMessage = "You can only change the service to ones you have permission for.";
                            }
                        } else if (!$hasRestrictedAccess) {
                            $canUpdate = true;
                        } else {
                            $canUpdate = in_array($sessionData['major_service'], $allowedServices) && 
                                        in_array($newService, $allowedServices);
                            if (!$canUpdate) {
                                $errorMessage = "You don't have permission to edit this session or change it to the selected service.";
                            }
                        }
                        
                        if ($canUpdate) {
                            // DIRECTLY UPDATE WITHOUT CONFLICT CHECK:
                            $stmt = $pdo->prepare("
                                UPDATE training_sessions
                                SET title = ?, major_service = ?, session_date = ?, session_end_date = ?, duration_days = ?,
                                    start_time = ?, end_time = ?, venue = ?, instructor = ?, instructor_bio = ?, 
                                    instructor_credentials = ?, capacity = ?, fee = ?
                                WHERE session_id = ?
                            ");
                            
                            $result = $stmt->execute([
                                $validation['data']['title'],
                                $validation['data']['major_service'],
                                $validation['data']['session_date'],
                                $validation['data']['session_end_date'],
                                $validation['data']['duration_days'],
                                $validation['data']['start_time'],
                                $validation['data']['end_time'],
                                $validation['data']['venue'],
                                $validation['data']['instructor'],
                                $validation['data']['instructor_bio'],
                                $validation['data']['instructor_credentials'],
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
// Handle REGISTRATION ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_registration_status'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security error: Invalid form submission.";
    } else {
        $registration_id = isset($_POST['registration_id']) ? (int)$_POST['registration_id'] : 0;
        $new_status = isset($_POST['new_status']) ? trim($_POST['new_status']) : '';
        
        // Debug logging for local environment
        error_log("DEBUG: Registration ID: $registration_id, New Status: $new_status");
        
        if ($registration_id <= 0) {
            $errorMessage = "Invalid registration ID: $registration_id";
        } elseif (!in_array($new_status, ['pending', 'approved', 'rejected'])) {
            $errorMessage = "Invalid status: $new_status";
        } else {
            try {
                // Check if user has access to this registration's session
                $stmt = $pdo->prepare("
                    SELECT ts.major_service, ts.session_id, ts.created_by, sr.registration_id
                    FROM session_registrations sr 
                    JOIN training_sessions ts ON sr.session_id = ts.session_id 
                    WHERE sr.registration_id = ?
                ");
                $stmt->execute([$registration_id]);
                $regSession = $stmt->fetch();
                
                if (!$regSession) {
                    $errorMessage = "Registration not found for ID: $registration_id";
                } else if ($hasRestrictedAccess && !in_array($regSession['major_service'], $allowedServices) && $regSession['created_by'] != $current_user_id) {
                    $errorMessage = "You don't have permission to manage this registration.";
                } else {
                    $stmt = $pdo->prepare("UPDATE session_registrations SET status = ? WHERE registration_id = ?");
                    $result = $stmt->execute([$new_status, $registration_id]);
                    
                    if ($result && $stmt->rowCount() > 0) {
                        $successMessage = "Registration status updated to '$new_status' successfully!";
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    } else {
                        $errorMessage = "Failed to update registration status. No rows affected.";
                    }
                }
            } catch (PDOException $e) {
                error_log("Registration update error: " . $e->getMessage());
                $errorMessage = handleDatabaseError($e);
            }
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
        $whereConditions[] = "ts.session_end_date < CURDATE()";
    } elseif ($statusFilter === 'ongoing') {
        $whereConditions[] = "ts.session_date <= CURDATE() AND ts.session_end_date >= CURDATE()";
    }
    
    $whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Modified query to include instructor fields
    $query = "
    SELECT SQL_CALC_FOUND_ROWS 
           ts.session_id,
           ts.title,
           ts.major_service,
           ts.session_date,
           ts.session_end_date,
           ts.duration_days,
           ts.start_time,
           ts.end_time,
           ts.venue,
           ts.instructor,
           ts.instructor_bio,
           ts.instructor_credentials,
           ts.capacity,
           ts.fee,
           ts.created_at,
           ts.created_by,
           COUNT(sr.registration_id) AS registrations_count,
           u.email as creator_email,
           CASE 
               WHEN ts.created_by = ? THEN 1 
               ELSE 0 
           END as is_my_session,
           CASE 
               WHEN ts.session_date > CURDATE() THEN 'upcoming'
               WHEN ts.session_end_date < CURDATE() THEN 'past'
               WHEN ts.session_date <= CURDATE() AND ts.session_end_date >= CURDATE() THEN 'ongoing'
               ELSE 'upcoming'
           END as session_status
    FROM training_sessions ts
    LEFT JOIN session_registrations sr ON ts.session_id = sr.session_id
    LEFT JOIN users u ON ts.created_by = u.user_id
    $whereClause
    GROUP BY ts.session_id, ts.title, ts.major_service, ts.session_date, ts.session_end_date, ts.duration_days,
             ts.start_time, ts.end_time, ts.venue, ts.instructor, ts.instructor_bio, ts.instructor_credentials,
             ts.capacity, ts.fee, ts.created_at, ts.created_by, u.email
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
                        SUM(CASE WHEN session_end_date < CURDATE() THEN 1 ELSE 0 END) as past,
                        SUM(CASE WHEN session_date <= CURDATE() AND session_end_date >= CURDATE() THEN 1 ELSE 0 END) as ongoing
                    FROM training_sessions
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
                    SUM(CASE WHEN session_date >= CURDATE() THEN 1 ELSE 0 END) as upcoming,
                    SUM(CASE WHEN session_end_date < CURDATE() THEN 1 ELSE 0 END) as past,
                    SUM(CASE WHEN session_date <= CURDATE() AND session_end_date >= CURDATE() THEN 1 ELSE 0 END) as ongoing
                FROM training_sessions
                WHERE major_service = ?
            ");
            $stmt->execute([$service]);
            $stats[$service] = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'upcoming' => 0, 'past' => 0, 'ongoing' => 0];
        }
    }
    
    // Get total stats with service restrictions including created sessions
    if ($hasRestrictedAccess && !empty($allowedServices)) {
        $placeholders = str_repeat('?,', count($allowedServices) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN session_date >= CURDATE() THEN 1 ELSE 0 END) as upcoming,
                SUM(CASE WHEN session_end_date < CURDATE() THEN 1 ELSE 0 END) as past,
                SUM(CASE WHEN session_date <= CURDATE() AND session_end_date >= CURDATE() THEN 1 ELSE 0 END) as ongoing
            FROM training_sessions
            WHERE major_service IN ($placeholders) OR created_by = ?
        ");
        $statsParams = array_merge($allowedServices, [$current_user_id]);
        $stmt->execute($statsParams);
        $totalStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'upcoming' => 0, 'past' => 0, 'ongoing' => 0];
    } else {
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN session_date >= CURDATE() THEN 1 ELSE 0 END) as upcoming,
                SUM(CASE WHEN session_end_date < CURDATE() THEN 1 ELSE 0 END) as past,
                SUM(CASE WHEN session_date <= CURDATE() AND session_end_date >= CURDATE() THEN 1 ELSE 0 END) as ongoing
            FROM training_sessions
        ");
        $totalStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'upcoming' => 0, 'past' => 0, 'ongoing' => 0];
    }
    
    // Get session details and registrations if viewing a specific session
    if ($viewSession > 0) {
        if (checkSessionAccess($pdo, $viewSession, $allowedServices, $hasRestrictedAccess, $current_user_id)) {
            // Get session details with instructor fields
            $stmt = $pdo->prepare("SELECT *, 
                CASE 
                    WHEN session_date > CURDATE() THEN 'upcoming'
                    WHEN session_end_date < CURDATE() THEN 'past'
                    WHEN session_date <= CURDATE() AND session_end_date >= CURDATE() THEN 'ongoing'
                    ELSE 'upcoming'
                END as session_status
                FROM training_sessions WHERE session_id = ?");
            $stmt->execute([$viewSession]);
            $selectedSession = $stmt->fetch();
            
            if ($selectedSession) {
                // Fixed registration query based on your actual table structure
                $stmt = $pdo->prepare("
                    SELECT 
                        sr.*,
                        u.email as user_email,
                        u.full_name as user_full_name,
                        -- Build display name from available fields in your table
                        COALESCE(
                            NULLIF(sr.full_name, ''), 
                            NULLIF(sr.name, ''), 
                            u.full_name, 
                            sr.organization_name,
                            'Unknown Participant'
                        ) as display_name,
                        -- Build display email from available fields
                        COALESCE(
                            NULLIF(sr.email, ''), 
                            u.email,
                            'No email provided'
                        ) as display_email,
                        -- Determine registration type display
                        CASE 
                            WHEN sr.registration_type = 'organization' THEN 'Organization'
                            WHEN sr.registration_type = 'individual' THEN 'Individual'
                            ELSE 'Individual'
                        END as type_display,
                        -- Contact information (using emergency_contact as contact info)
                        sr.emergency_contact as contact_info,
                        -- Age and location info
                        CASE 
                            WHEN sr.age IS NOT NULL THEN CONCAT(sr.age, ' years old')
                            ELSE 'Age not specified'
                        END as age_display,
                        sr.location as participant_location,
                        -- RCY Status
                        COALESCE(sr.rcy_status, 'Not specified') as rcy_status_display,
                        -- Payment information
                        CASE 
                            WHEN sr.payment_method = 'free' OR sr.payment_amount = 0 THEN 'Free'
                            ELSE CONCAT('₱', sr.payment_amount, ' via ', UPPER(sr.payment_method))
                        END as payment_display,
                        -- Document counts
                        (CASE WHEN sr.valid_id_path IS NOT NULL AND sr.valid_id_path != '' THEN 1 ELSE 0 END +
                         CASE WHEN sr.requirements_path IS NOT NULL AND sr.requirements_path != '' THEN 1 ELSE 0 END +
                         CASE WHEN sr.payment_receipt_path IS NOT NULL AND sr.payment_receipt_path != '' THEN 1 ELSE 0 END
                        ) as document_count
                    FROM session_registrations sr
                    LEFT JOIN users u ON sr.user_id = u.user_id
                    WHERE sr.session_id = ?
                    ORDER BY sr.registration_date DESC
                ");
                $stmt->execute([$viewSession]);
                $registrations = $stmt->fetchAll() ?: [];
                
                logDebug("Found " . count($registrations) . " registrations for session $viewSession");
                if (!empty($registrations)) {
                    logDebug("First registration sample: Registration ID {$registrations[0]['registration_id']}, Name: {$registrations[0]['display_name']}, Email: {$registrations[0]['display_email']}");
                }
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
              <?php if (($selectedSession['duration_days'] ?? 1) == 1): ?>
                <span><i class="fas fa-calendar"></i> <?= date('M j, Y', strtotime($selectedSession['session_date'])) ?></span>
              <?php else: ?>
                <span><i class="fas fa-calendar"></i> <?= date('M j, Y', strtotime($selectedSession['session_date'])) ?> - <?= date('M j, Y', strtotime($selectedSession['session_end_date'])) ?></span>
                <span><i class="fas fa-calendar-week"></i> <?= $selectedSession['duration_days'] ?> days</span>
              <?php endif; ?>
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

        <!-- Replace the registrations table section in your sessions.php (around line 350) -->

<!-- Replace the registrations table section in sessions.php (around line 350-450) -->
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
          <th>Type & Status</th>
          <th>Registration Date</th>
          <th>Status</th>
          <th>Contact & Info</th>
          <th>Payment</th>
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
                  <?= strtoupper(substr($reg['display_name'], 0, 1)) ?>
                </div>
                <div class="participant-details">
                  <div class="participant-name">
                    <?= htmlspecialchars($reg['display_name']) ?>
                  </div>
                  <div class="participant-email">
                    <?= htmlspecialchars($reg['display_email']) ?>
                  </div>
                  <?php if (!empty($reg['location'])): ?>
                    <div class="participant-location">
                      <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($reg['location']) ?>
                    </div>
                  <?php endif; ?>
                  <?php if (!empty($reg['organization_name'])): ?>
                    <div class="participant-org">
                      <i class="fas fa-building"></i> <?= htmlspecialchars($reg['organization_name']) ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td>
              <div style="font-size: 0.9rem; font-weight: 600; color: var(--prc-red);">
                #<?= $reg['registration_id'] ?>
              </div>
              <?php if (!empty($reg['user_id'])): ?>
                <div style="font-size: 0.7rem; color: var(--gray);">
                  User ID: <?= $reg['user_id'] ?>
                </div>
              <?php else: ?>
                <div style="font-size: 0.7rem; color: var(--orange);">
                  Guest Registration
                </div>
              <?php endif; ?>
            </td>
            <td>
              <span class="type-badge <?= $reg['registration_type'] ?>">
                <?= ucfirst($reg['registration_type']) ?>
              </span>
              <?php if (!empty($reg['pax_count']) && $reg['pax_count'] > 1): ?>
                <small style="display: block; color: var(--gray); margin-top: 0.2rem;">
                  <?= $reg['pax_count'] ?> participants
                </small>
              <?php endif; ?>
              <?php if (!empty($reg['rcy_status']) && $reg['rcy_status'] !== 'Not specified'): ?>
                <div class="rcy-badge <?= strtolower(str_replace(' ', '-', $reg['rcy_status'])) ?>">
                  <?= htmlspecialchars($reg['rcy_status']) ?>
                </div>
              <?php endif; ?>
              <?php if (!empty($reg['age'])): ?>
                <small style="display: block; color: var(--gray); margin-top: 0.2rem;">
                  <?= $reg['age'] ?> years old
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
              <?php if (!empty($reg['training_date'])): ?>
                <div style="font-size: 0.7rem; color: var(--blue); margin-top: 0.2rem;">
                  Training: <?= date('M j, Y', strtotime($reg['training_date'])) ?>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <span class="reg-status-badge <?= $reg['status'] ?>">
                <i class="fas <?= 
                  $reg['status'] === 'approved' ? 'fa-check-circle' : 
                  ($reg['status'] === 'rejected' ? 'fa-times-circle' : 'fa-clock') 
                ?>"></i>
                <?= ucfirst($reg['status']) ?>
              </span>
              <?php if ($reg['payment_status'] !== 'not_required'): ?>
                <div class="payment-status <?= $reg['payment_status'] ?>" style="margin-top: 0.3rem;">
                  <i class="fas <?= 
                    $reg['payment_status'] === 'approved' ? 'fa-check-circle' : 
                    ($reg['payment_status'] === 'rejected' ? 'fa-times-circle' : 'fa-clock') 
                  ?>"></i>
                  Payment: <?= ucfirst($reg['payment_status']) ?>
                </div>
              <?php endif; ?>
              <?php if (!empty($reg['attendance_status']) && $reg['attendance_status'] !== 'registered'): ?>
                <div class="attendance-status" style="margin-top: 0.2rem; font-size: 0.8rem;">
                  <i class="fas <?= 
                    $reg['attendance_status'] === 'attended' ? 'fa-check' : 'fa-user-times'
                  ?>"></i>
                  <?= ucfirst(str_replace('_', ' ', $reg['attendance_status'])) ?>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <div class="contact-info">
                <?php if (!empty($reg['emergency_contact'])): ?>
                  <div style="font-size: 0.8rem;">
                    <i class="fas fa-phone"></i> 
                    <?= htmlspecialchars($reg['emergency_contact']) ?>
                  </div>
                <?php endif; ?>
                <?php if (!empty($reg['display_email']) && $reg['display_email'] !== 'No email provided'): ?>
                  <div style="font-size: 0.8rem; margin-top: 0.2rem;">
                    <i class="fas fa-envelope"></i> 
                    <?= htmlspecialchars($reg['display_email']) ?>
                  </div>
                <?php endif; ?>
                <?php if (!empty($reg['medical_info'])): ?>
                  <div style="font-size: 0.7rem; margin-top: 0.3rem; padding: 0.2rem; background: #fff3cd; border-radius: 4px;">
                    <i class="fas fa-medkit"></i> 
                    <?= htmlspecialchars(substr($reg['medical_info'], 0, 30)) ?><?= strlen($reg['medical_info']) > 30 ? '...' : '' ?>
                  </div>
                <?php endif; ?>
                <?php if (!empty($reg['purpose'])): ?>
                  <div style="font-size: 0.7rem; margin-top: 0.2rem; color: var(--gray);">
                    <i class="fas fa-info-circle"></i> 
                    <?= htmlspecialchars(substr($reg['purpose'], 0, 25)) ?><?= strlen($reg['purpose']) > 25 ? '...' : '' ?>
                  </div>
                <?php endif; ?>
              </div>
            </td>
            <td>
              <div class="payment-info">
                <?php if ($reg['payment_method'] === 'free' || $reg['payment_amount'] == 0): ?>
                  <span class="payment-free">FREE</span>
                <?php else: ?>
                  <div class="payment-amount">₱<?= number_format($reg['payment_amount'], 2) ?></div>
                  <div class="payment-method"><?= strtoupper($reg['payment_method']) ?></div>
                  <?php if (!empty($reg['payment_reference'])): ?>
                    <div class="payment-ref">Ref: <?= htmlspecialchars($reg['payment_reference']) ?></div>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </td>
            <td>
              <div class="document-links" style="display: flex; flex-direction: column; gap: 0.2rem;">
                <?php if (!empty($reg['valid_id_path'])): ?>
                  <button onclick="viewDocument('<?= htmlspecialchars($reg['valid_id_path']) ?>')" 
                          class="doc-link" style="font-size: 0.7rem; padding: 0.2rem 0.4rem;">
                    <i class="fas fa-id-card"></i> Valid ID
                  </button>
                <?php endif; ?>
                
                <?php if (!empty($reg['requirements_path'])): ?>
                  <button onclick="viewDocument('<?= htmlspecialchars($reg['requirements_path']) ?>')" 
                          class="doc-link" style="font-size: 0.7rem; padding: 0.2rem 0.4rem;">
                    <i class="fas fa-file-alt"></i> Requirements
                  </button>
                <?php endif; ?>
                
                <?php if (!empty($reg['payment_receipt_path'])): ?>
                  <button onclick="viewDocument('<?= htmlspecialchars($reg['payment_receipt_path']) ?>')" 
                          class="doc-link" style="font-size: 0.7rem; padding: 0.2rem 0.4rem;">
                    <i class="fas fa-receipt"></i> Receipt
                  </button>
                <?php endif; ?>
                
                <?php if (empty($reg['valid_id_path']) && empty($reg['requirements_path']) && empty($reg['payment_receipt_path'])): ?>
                  <span style="color: var(--gray); font-size: 0.7rem;">No documents</span>
                <?php else: ?>
                  <div style="font-size: 0.6rem; color: var(--gray); margin-top: 0.2rem;">
                    <?= $reg['document_count'] ?> file(s)
                  </div>
                <?php endif; ?>
              </div>
            </td>
            <td>
    <div class="reg-actions">
        <?php if ($reg['status'] !== 'approved'): ?>
            <form method="POST" style="display: inline; margin-bottom: 0.3rem;">
                <input type="hidden" name="update_registration_status" value="1">
                <input type="hidden" name="registration_id" value="<?= (int)$reg['registration_id'] ?>">
                <input type="hidden" name="new_status" value="approved">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <button type="submit" class="btn-reg-action btn-approve" 
                        onclick="return confirm('Approve this registration?');">
                    <i class="fas fa-check"></i> Approve
                </button>
            </form>
        <?php endif; ?>
        
        <?php if ($reg['status'] !== 'rejected'): ?>
            <form method="POST" style="display: inline; margin-bottom: 0.3rem;">
                <input type="hidden" name="update_registration_status" value="1">
                <input type="hidden" name="registration_id" value="<?= (int)$reg['registration_id'] ?>">
                <input type="hidden" name="new_status" value="rejected">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <button type="submit" class="btn-reg-action btn-reject" 
                        onclick="return confirm('Reject this registration?');">
                    <i class="fas fa-times"></i> Reject
                </button>
            </form>
        <?php endif; ?>
        
        <?php if ($reg['status'] !== 'pending'): ?>
            <form method="POST" style="display: inline; margin-bottom: 0.3rem;">
                <input type="hidden" name="update_registration_status" value="1">
                <input type="hidden" name="registration_id" value="<?= (int)$reg['registration_id'] ?>">
                <input type="hidden" name="new_status" value="pending">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <button type="submit" class="btn-reg-action btn-pending" 
                        onclick="return confirm('Set registration to pending?');">
                    <i class="fas fa-clock"></i> Pending
                </button>
            </form>
        <?php endif; ?>
        
        <form method="POST" style="display: inline;" onsubmit="return confirmDeleteRegistration();">
            <input type="hidden" name="delete_registration" value="1">
            <input type="hidden" name="registration_id" value="<?= (int)$reg['registration_id'] ?>">
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
            <th>Date Range & Time</th>
            <th>Instructor</th>
            <th>Location</th>
            <th>Fee</th>
            <th>Registrations</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
               <?php foreach ($sessions as $session): 
            $sessionStartDate = strtotime($session['session_date']);
            $sessionEndDate = strtotime($session['session_end_date'] ?? $session['session_date']);
            $today = strtotime('today');
            $durationDays = $session['duration_days'] ?? 1;
            
            // Determine session status
            $sessionStatus = 'upcoming';
            if ($sessionEndDate < $today) {
                $sessionStatus = 'past';
            } elseif ($sessionStartDate <= $today && $sessionEndDate >= $today) {
                $sessionStatus = 'ongoing';
            }
            
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
                        <?php if ($durationDays == 1): ?>
                            <div class="session-date-single">
                                <span class="session-date"><?= date('M d, Y', $sessionStartDate) ?></span>
                                <span class="session-time"><?= date('g:i A', strtotime($session['start_time'])) ?> - <?= date('g:i A', strtotime($session['end_time'])) ?></span>
                                <div class="session-duration">Single Day</div>
                            </div>
                        <?php else: ?>
                            <div class="session-date-range">
                                <div class="session-date-start"><?= date('M d, Y', $sessionStartDate) ?></div>
                                <div class="session-date-end">to <?= date('M d, Y', $sessionEndDate) ?></div>
                                <span class="session-time"><?= date('g:i A', strtotime($session['start_time'])) ?> - <?= date('g:i A', strtotime($session['end_time'])) ?></span>
                                <div class="session-duration"><?= $durationDays ?> days</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
    <?php if (!empty($session['instructor'])): ?>
        <div class="instructor-info">
            <div class="instructor-name"><?= htmlspecialchars($session['instructor']) ?></div>
            <?php if (!empty($session['instructor_credentials'])): ?>
                <div class="instructor-credentials"><?= htmlspecialchars($session['instructor_credentials']) ?></div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <span class="no-instructor">Not assigned</span>
    <?php endif; ?>
</td>
                  <td>
                    
    <div class="venue-display">
        <div class="venue-preview">
            <?= htmlspecialchars(strlen($session['venue']) > 60 ? 
                substr($session['venue'], 0, 60) . '...' : 
                $session['venue']) ?>
        </div>
        <?php if (strlen($session['venue']) > 60): ?>
            <button type="button" class="btn-expand-venue" onclick="showVenueModal('<?= htmlspecialchars($session['title'], ENT_QUOTES) ?>', <?= htmlspecialchars(json_encode($session['venue']), ENT_QUOTES) ?>)">
                <i class="fas fa-expand-alt"></i> View Full
            </button>
        <?php endif; ?>
    </div>
</td>

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
                     <span class="status-badge <?= $sessionStatus ?>">
                        <?php if ($sessionStatus === 'upcoming'): ?>
                            <i class="fas fa-clock"></i> Upcoming
                        <?php elseif ($sessionStatus === 'ongoing'): ?>
                            <i class="fas fa-play-circle"></i> Ongoing
                        <?php else: ?>
                            <i class="fas fa-check-circle"></i> Completed
                        <?php endif; ?>
                    </span>
                </td>
        <td class="actions">
    <a href="?view_session=<?= $session['session_id'] ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $serviceFilter ? '&service=' . urlencode($serviceFilter) : '' ?><?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?>" 
       class="btn-action btn-view">
        <i class="fas fa-users"></i> View Registrations
    </a>
    
    <button type="button" class="btn-action btn-edit" onclick='openEditModal(<?= json_encode($session, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
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
        <label for="session_date">Start Date *</label>
        <input type="date" id="session_date" name="session_date" required min="<?= date('Y-m-d') ?>">
    </div>
    
    <div class="form-group">
        <label for="session_end_date_input">End Date *</label>
        <input type="date" id="session_end_date_input" name="session_end_date_input" required min="<?= date('Y-m-d') ?>">
        <small style="color: var(--gray);">End date must be same or after start date</small>
    </div>
</div>

<!-- Keep the existing duration field but make it hidden since it will be calculated -->
<input type="hidden" id="duration_days" name="duration_days" value="1">

            <div class="date-preview-container" id="datePreviewContainer" style="display: none;">
    <div class="date-preview">
        <div class="date-preview-header">
            <i class="fas fa-calendar-check"></i>
            <span>Session Date Range</span>
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
    <div class="form-group venue-group">
    <label for="venue">
        Training Venue & Directions *
        <span class="field-hint">Include full address and travel instructions</span>
    </label>
    <textarea 
        id="venue" 
        name="venue" 
        required 
        rows="4"
        maxlength="2000"
        placeholder="Example:&#10;Philippine Red Cross Training Center&#10;Real Street, Guadalupe, Cebu City, 6000 Cebu&#10;&#10;Travel Instructions:&#10;- From Ayala Center: Take jeepney to Guadalupe, alight at Real Street&#10;- Parking available at the rear entrance&#10;- Training Room 2A (Second Floor)&#10;- Contact: 032-123-4567 for assistance"
        style="resize: vertical; min-height: 100px;"
    ></textarea>
    <div class="field-info">
        <span class="char-counter">
            <span id="venue-char-count">0</span>/2000 characters
        </span>
        <div class="venue-tips">
            <small>
                <i class="fas fa-info-circle"></i>
                Tips: Include room numbers, floor levels, parking info, contact person, and accessibility notes
            </small>
        </div>
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
            
<div class="form-group instructor-group">
    <label for="instructor">
        Instructor/Facilitator
        <span class="field-hint">Training facilitator or instructor name</span>
    </label>
    <input type="text" 
           id="instructor" 
           name="instructor" 
           maxlength="100"
           placeholder="e.g. Dr. Maria Santos, RN" />
    <small style="color: var(--gray);">Optional - Leave blank if not assigned yet</small>
</div>

<div class="form-group">
    <label for="instructor_credentials">
        Instructor Credentials
        <span class="field-hint">Professional qualifications and certifications</span>
    </label>
    <input type="text" 
           id="instructor_credentials" 
           name="instructor_credentials" 
           maxlength="500"
           placeholder="e.g. MD, BLS Instructor, ACLS Provider, 10+ years emergency medicine" />
    <div class="char-counter">
        <span id="credentials-char-count">0</span>/500 characters
    </div>
</div>

<div class="form-group">
    <label for="instructor_bio">
        Instructor Bio/Background
        <span class="field-hint">Brief background and expertise (optional)</span>
    </label>
    <textarea id="instructor_bio" 
              name="instructor_bio" 
              rows="3" 
              maxlength="1000"
              placeholder="Brief professional background, specializations, and relevant experience..."
              style="resize: vertical; min-height: 80px;"></textarea>
    <div class="char-counter">
        <span id="bio-char-count">0</span>/1000 characters
    </div>
</div>
            <button type="submit" class="btn-submit" id="submitButton">
                <i class="fas fa-save"></i> Save Session
            </button>
        </form>
    </div>
</div>

<script>
// COMPLETE JAVASCRIPT FIX FOR manage_sessions.php
// Replace all your existing JavaScript with this cleaned-up version

let currentSessionId = null;

// Calculate duration days from start and end date
function calculateSessionDurationDays() {
    const startDateInput = document.getElementById('session_date');
    const endDateInput = document.getElementById('session_end_date_input');
    const hiddenDurationInput = document.getElementById('duration_days');
    
    if (!startDateInput || !endDateInput || !hiddenDurationInput) return 1;
    
    const startDate = startDateInput.value;
    const endDate = endDateInput.value;
    
    if (startDate && endDate) {
        const start = new Date(startDate);
        const end = new Date(endDate);
        
        const timeDiff = end.getTime() - start.getTime();
        const dayDiff = Math.ceil(timeDiff / (1000 * 3600 * 24)) + 1;
        
        if (dayDiff >= 1) {
            hiddenDurationInput.value = dayDiff;
            return dayDiff;
        } else {
            hiddenDurationInput.value = 1;
            return 1;
        }
    }
    return 1;
}

// Update session date preview
function updateSessionDatePreview() {
    const startDateInput = document.getElementById('session_date');
    const endDateInput = document.getElementById('session_end_date_input');
    const previewContainer = document.getElementById('datePreviewContainer');
    
    if (!startDateInput || !endDateInput || !previewContainer) return;
    
    const previewStartDate = document.getElementById('previewStartDate');
    const previewEndDate = document.getElementById('previewEndDate');
    const previewDuration = document.getElementById('previewDuration');
    
    const startDate = startDateInput.value;
    const endDate = endDateInput.value;
    
    if (startDate && endDate) {
        const start = new Date(startDate + 'T00:00:00');
        const end = new Date(endDate + 'T00:00:00');
        const duration = calculateSessionDurationDays();
        
        if (previewStartDate) {
            previewStartDate.textContent = start.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }
        
        if (previewEndDate) {
            previewEndDate.textContent = end.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }
        
        if (previewDuration) {
            const durationText = duration === 1 ? '1 day' : `${duration} days`;
            previewDuration.textContent = durationText;
        }
        
        previewContainer.style.display = 'block';
    } else {
        previewContainer.style.display = 'none';
    }
}

// Hide conflict warning (not used but kept for compatibility)
function hideSessionConflictWarning() {
    const conflictWarning = document.getElementById('conflictWarning');
    const submitButton = document.getElementById('submitButton');
    
    if (conflictWarning) conflictWarning.style.display = 'none';
    if (submitButton) {
        submitButton.classList.remove('has-conflict');
        submitButton.innerHTML = '<i class="fas fa-save"></i> Save Session';
    }
}

// Open create modal
function openCreateModal() {
    const modalTitle = document.getElementById('modalTitle');
    const formAction = document.getElementById('formAction');
    const sessionForm = document.getElementById('sessionForm');
    const sessionDate = document.getElementById('session_date');
    const endDateInput = document.getElementById('session_end_date_input');
    const durationDays = document.getElementById('duration_days');
    const datePreviewContainer = document.getElementById('datePreviewContainer');
    
    if (modalTitle) modalTitle.textContent = 'Create New Session';
    if (formAction) formAction.name = 'create_session';
    if (sessionForm) sessionForm.reset();
    
    const today = new Date().toISOString().split('T')[0];
    if (sessionDate) {
        sessionDate.min = today;
        sessionDate.value = today;
    }
    if (endDateInput) {
        endDateInput.min = today;
        endDateInput.value = today;
    }
    if (durationDays) durationDays.value = 1;
    
    currentSessionId = null;
    
    if (datePreviewContainer) datePreviewContainer.style.display = 'none';
    hideSessionConflictWarning();
    
    const serviceSelect = document.getElementById('major_service');
    if (serviceSelect && typeof hasRestrictedAccess !== 'undefined' && hasRestrictedAccess && typeof allowedServices !== 'undefined' && allowedServices && allowedServices.length > 0) {
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
    } else if (serviceSelect) {
        Array.from(serviceSelect.options).forEach(option => {
            option.disabled = false;
            option.style.display = 'block';
        });
    }
    
    const sessionModal = document.getElementById('sessionModal');
    if (sessionModal) sessionModal.classList.add('active');
}

// Open edit modal
function openEditModal(session) {
    console.log('Opening edit modal for session:', session);
    
    const modalTitle = document.getElementById('modalTitle');
    const formAction = document.getElementById('formAction');
    const sessionIdInput = document.getElementById('sessionId');
    const titleInput = document.getElementById('title');
    const majorServiceSelect = document.getElementById('major_service');
    const sessionDateInput = document.getElementById('session_date');
    const endDateInput = document.getElementById('session_end_date_input');
    const durationDaysInput = document.getElementById('duration_days');
    const startTimeInput = document.getElementById('start_time');
    const endTimeInput = document.getElementById('end_time');
    const venueInput = document.getElementById('venue');
    const capacityInput = document.getElementById('capacity');
    const feeInput = document.getElementById('fee');
    const instructorInput = document.getElementById('instructor');
    const instructorCredentialsInput = document.getElementById('instructor_credentials');
    const instructorBioInput = document.getElementById('instructor_bio');

    if (instructorInput) instructorInput.value = session.instructor || '';
    if (instructorCredentialsInput) instructorCredentialsInput.value = session.instructor_credentials || '';
    if (instructorBioInput) instructorBioInput.value = session.instructor_bio || '';
    if (modalTitle) modalTitle.textContent = 'Edit Session';
    if (formAction) formAction.name = 'update_session';
    if (sessionIdInput) sessionIdInput.value = session.session_id;
    if (titleInput) titleInput.value = session.title;
    if (majorServiceSelect) majorServiceSelect.value = session.major_service;
    if (sessionDateInput) sessionDateInput.value = session.session_date;
    
    const startDate = new Date(session.session_date);
    const duration = session.duration_days || 1;
    const endDate = new Date(startDate);
    endDate.setDate(endDate.getDate() + duration - 1);
    if (endDateInput) endDateInput.value = endDate.toISOString().split('T')[0];
    
    if (durationDaysInput) durationDaysInput.value = duration;
    if (startTimeInput) startTimeInput.value = session.start_time;
    if (endTimeInput) endTimeInput.value = session.end_time;
    if (venueInput) venueInput.value = session.venue;
    if (capacityInput) capacityInput.value = session.capacity || '';
    if (feeInput) feeInput.value = session.fee || '';
    
    currentSessionId = session.session_id;
    
    setTimeout(updateSessionDatePreview, 100);
    
    const serviceHint = document.getElementById('serviceHint');
    const isCreator = session.created_by == (typeof currentUserId !== 'undefined' ? currentUserId : 0);
    
    if (majorServiceSelect && typeof hasRestrictedAccess !== 'undefined' && hasRestrictedAccess && typeof allowedServices !== 'undefined' && allowedServices && allowedServices.length > 0) {
        Array.from(majorServiceSelect.options).forEach(option => {
            if (option.value === '') {
                option.disabled = false;
                option.style.display = 'block';
            } else if (option.value === session.major_service) {
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
    } else if (majorServiceSelect) {
        Array.from(majorServiceSelect.options).forEach(option => {
            option.disabled = false;
            option.style.display = 'block';
        });
        if (serviceHint) {
            serviceHint.style.display = 'none';
        }
    }
    
    const sessionDate = new Date(session.session_date);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    if (sessionDateInput && endDateInput) {
        if (sessionDate < today) {
            sessionDateInput.min = session.session_date;
            endDateInput.min = session.session_date;
        } else {
            const todayStr = new Date().toISOString().split('T')[0];
            sessionDateInput.min = todayStr;
            endDateInput.min = todayStr;
        }
    }
    
    const sessionModal = document.getElementById('sessionModal');
    if (sessionModal) sessionModal.classList.add('active');
}

// Close modal
function closeModal() {
    const sessionModal = document.getElementById('sessionModal');
    if (sessionModal) sessionModal.classList.remove('active');
}

// Filter status
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

// Confirm delete
function confirmDelete(title, registrationCount) {
    if (registrationCount > 0) {
        return confirm(`Are you sure you want to delete "${title}"?\n\nThis session has ${registrationCount} registration(s).\nYou should cancel all registrations first.`);
    }
    return confirm(`Are you sure you want to delete "${title}"?\n\nThis action cannot be undone.`);
}

// Confirm delete registration
function confirmDeleteRegistration() {
    return confirm('Are you sure you want to delete this registration?\n\nThis action cannot be undone.');
}

// Show venue modal
function showVenueModal(sessionTitle, venueText) {
    const existingModal = document.querySelector('.venue-modal');
    if (existingModal) {
        existingModal.remove();
    }
    
    const modal = document.createElement('div');
    modal.className = 'venue-modal';
    modal.innerHTML = `
        <div class="venue-modal-content">
            <div class="venue-modal-header">
                <h3>
                    <i class="fas fa-map-marker-alt"></i>
                    ${escapeHtml(sessionTitle)} - Venue
                </h3>
                <button class="venue-modal-close" onclick="closeVenueModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="venue-modal-body">
                ${escapeHtml(venueText).replace(/\n/g, '<br>')}
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    setTimeout(() => {
        modal.classList.add('active');
    }, 10);
    
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeVenueModal();
        }
    });
    
    window.currentVenueModal = modal;
    document.body.style.overflow = 'hidden';
}

// Close venue modal
function closeVenueModal() {
    const modal = window.currentVenueModal || document.querySelector('.venue-modal');
    if (modal) {
        modal.classList.remove('active');
        setTimeout(() => {
            modal.remove();
            window.currentVenueModal = null;
            document.body.style.overflow = '';
        }, 300);
    }
}

// Escape HTML helper
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// DOM Ready Event Listener
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM Content Loaded - Initializing Session Management');
    
    // Date input handlers
    const sessionDateInput = document.getElementById('session_date');
    const endDateInput = document.getElementById('session_end_date_input');
    const startTimeInput = document.getElementById('start_time');
    const endTimeInput = document.getElementById('end_time');
    
    if (sessionDateInput) {
        sessionDateInput.addEventListener('change', function() {
            const startDate = this.value;
            if (endDateInput) {
                endDateInput.min = startDate;
                if (endDateInput.value && endDateInput.value < startDate) {
                    endDateInput.value = startDate;
                }
                if (!endDateInput.value) {
                    endDateInput.value = startDate;
                }
            }
            calculateSessionDurationDays();
            updateSessionDatePreview();
        });
    }
    
    if (endDateInput) {
        endDateInput.addEventListener('change', function() {
            calculateSessionDurationDays();
            updateSessionDatePreview();
        });
    }
    
    if (startTimeInput) {
        startTimeInput.addEventListener('change', updateSessionDatePreview);
    }
    
    if (endTimeInput) {
        endTimeInput.addEventListener('change', updateSessionDatePreview);
    }
    // Add to the JavaScript section - character counters for instructor fields
document.addEventListener('DOMContentLoaded', function() {
    // Instructor credentials character counter
    const credentialsField = document.getElementById('instructor_credentials');
    const credentialsCounter = document.getElementById('credentials-char-count');
    
    if (credentialsField && credentialsCounter) {
        function updateCredentialsCharCount() {
            const currentLength = credentialsField.value.length;
            const maxLength = 500;
            
            credentialsCounter.textContent = currentLength;
            
            const counterElement = credentialsCounter.parentElement;
            counterElement.classList.remove('warning', 'danger');
            
            if (currentLength > maxLength * 0.9) {
                counterElement.classList.add('danger');
            } else if (currentLength > maxLength * 0.75) {
                counterElement.classList.add('warning');
            }
        }
        
        credentialsField.addEventListener('input', updateCredentialsCharCount);
        updateCredentialsCharCount();
    }
    
    // Instructor bio character counter
    const bioField = document.getElementById('instructor_bio');
    const bioCounter = document.getElementById('bio-char-count');
    
    if (bioField && bioCounter) {
        function updateBioCharCount() {
            const currentLength = bioField.value.length;
            const maxLength = 1000;
            
            bioCounter.textContent = currentLength;
            
            const counterElement = bioCounter.parentElement;
            counterElement.classList.remove('warning', 'danger');
            
            if (currentLength > maxLength * 0.9) {
                counterElement.classList.add('danger');
            } else if (currentLength > maxLength * 0.75) {
                counterElement.classList.add('warning');
            }
        }
        
        bioField.addEventListener('input', updateBioCharCount);
        bioField.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.max(80, this.scrollHeight) + 'px';
        });
        updateBioCharCount();
    }
});
    // Form validation
   if (sessionForm) {
    sessionForm.addEventListener('submit', function(e) {
        const startDate = document.getElementById('session_date')?.value;
        const endDate = document.getElementById('session_end_date_input')?.value;
        const startTime = document.getElementById('start_time')?.value;
        const endTime = document.getElementById('end_time')?.value;
        const title = document.getElementById('title')?.value.trim();
        const venue = document.getElementById('venue')?.value.trim();
        const majorService = document.getElementById('major_service')?.value;
        const formAction = document.getElementById('formAction');
        const isCreating = formAction ? formAction.name === 'create_session' : false;
        
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
        
        if (!startDate) {
            e.preventDefault();
            alert('Please select a start date.');
            return;
        }
        
        if (!endDate) {
            e.preventDefault();
            alert('Please select an end date.');
            return;
        }
        
        const start = new Date(startDate);
        const end = new Date(endDate);
        
        if (end < start) {
            e.preventDefault();
            alert('End date cannot be before start date.');
            return;
        }
        
        const finalDuration = calculateSessionDurationDays();
        if (finalDuration > 365) {
            e.preventDefault();
            alert('Session duration cannot exceed 365 days.');
            return;
        }
        
        if (endTime <= startTime) {
            e.preventDefault();
            alert('End time must be after start time');
            return;
        }
        
        const startDateTime = new Date(`2000-01-01T${startTime}`);
        const endDateTime = new Date(`2000-01-01T${endTime}`);
        const timeDuration = (endDateTime - startDateTime) / (1000 * 60 * 60);
        
        if (timeDuration < 1) {
            e.preventDefault();
            alert('Session must be at least 1 hour long');
            return;
        }
        
        if (isCreating && typeof hasRestrictedAccess !== 'undefined' && hasRestrictedAccess && typeof allowedServices !== 'undefined' && allowedServices && allowedServices.length > 0) {
            if (!allowedServices.includes(majorService)) {
                e.preventDefault();
                alert("You don't have permission to create sessions for " + majorService + 
                      "\n\nYou can only create sessions for: " + allowedServices.join(', '));
                return;
            }
        }
        
        const selectedDate = new Date(startDate);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        if (selectedDate < today && isCreating) {
            e.preventDefault();
            alert('Session date cannot be in the past for new sessions');
            return;
        }
        
        const submitBtn = this.querySelector('.btn-submit');
        if (submitBtn) {
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;
        }
    });
}
    
    // Modal close on outside click
    const sessionModal = document.getElementById('sessionModal');
    if (sessionModal) {
        sessionModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    }
    
    // Auto-dismiss alerts
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
    
    // Table row hover effects (without interfering with clicks)
    const tableRows = document.querySelectorAll('.data-table tbody tr');
    tableRows.forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.backgroundColor = '#f8f9fa';
        });
        
        row.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '';
        });
    });
    
    // Registration table hover effects
    const regRows = document.querySelectorAll('.registrations-table tbody tr');
    regRows.forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.backgroundColor = '#f8f9fa';
        });
        
        row.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '';
        });
    });
    
    // Search form enhancement
    const searchForm = document.querySelector('.search-box');
    if (searchForm) {
        const searchInput = searchForm.querySelector('input[name="search"]');
        if (searchInput) {
            searchInput.addEventListener('keyup', function(e) {
                if (e.key === 'Escape') {
                    this.value = '';
                    searchForm.submit();
                }
            });
        }
    }
    
    // Character counter for venue field
    const venueField = document.getElementById('venue');
    const charCounter = document.getElementById('venue-char-count');
    
    if (venueField && charCounter) {
        function updateVenueCharCount() {
            const currentLength = venueField.value.length;
            const maxLength = 2000;
            
            charCounter.textContent = currentLength;
            
            const counterElement = charCounter.parentElement;
            counterElement.classList.remove('warning', 'danger');
            
            if (currentLength > maxLength * 0.9) {
                counterElement.classList.add('danger');
            } else if (currentLength > maxLength * 0.75) {
                counterElement.classList.add('warning');
            }
        }
        
        venueField.addEventListener('input', updateVenueCharCount);
        updateVenueCharCount();
        
        venueField.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.max(100, this.scrollHeight) + 'px';
        });
    }
    
    // Debug logging
    console.log('Session Management Initialized Successfully');
    if (typeof userRole !== 'undefined') {
        console.log('User Role:', userRole);
        console.log('Has Restricted Access:', typeof hasRestrictedAccess !== 'undefined' ? hasRestrictedAccess : 'undefined');
        console.log('Allowed Services:', typeof allowedServices !== 'undefined' ? allowedServices : 'undefined');
        console.log('Current User ID:', typeof currentUserId !== 'undefined' ? currentUserId : 'undefined');
    }
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('sessionModal');
        if (modal && modal.classList.contains('active')) {
            closeModal();
        }
        if (window.currentVenueModal) {
            closeVenueModal();
        }
    }
    
    if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
        e.preventDefault();
        openCreateModal();
    }
});

// Touch/mobile enhancements
if ('ontouchstart' in window) {
    document.addEventListener('DOMContentLoaded', function() {
        document.body.classList.add('touch-device');
        
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
let currentRequest = null;

// Open Request View Modal
function openRequestModal(request) {
    currentRequest = request;
    document.getElementById('updateRequestId').value = request.request_id;
    document.getElementById('new_status').value = request.status;
    document.getElementById('admin_notes').value = request.admin_notes || '';
    
    // Populate request details
    const requestDetails = document.getElementById('requestDetails');
    const preferredDate = request.preferred_date ? 
        new Date(request.preferred_date).toLocaleDateString('en-US', {
            year: 'numeric', 
            month: 'long', 
            day: 'numeric'
        }) : 'Flexible';
    
    requestDetails.innerHTML = `
        <div class="request-info-grid">
            <div class="info-item">
                <div class="info-label">Request ID</div>
                <div class="info-value">#${request.request_id}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Requested By</div>
                <div class="info-value">${escapeHtml(request.user_full_name || 'Unknown User')}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Email</div>
                <div class="info-value">${escapeHtml(request.user_email)}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Service Type</div>
                <div class="info-value">${escapeHtml(request.service_type)}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Training Program</div>
                <div class="info-value">${escapeHtml(request.program_name || request.training_program)}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Duration</div>
                <div class="info-value">${request.typical_duration_hours || 8} hours</div>
            </div>
            <div class="info-item">
                <div class="info-label">Preferred Date</div>
                <div class="info-value">${preferredDate}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Preferred Time</div>
                <div class="info-value">${request.preferred_time.charAt(0).toUpperCase() + request.preferred_time.slice(1)}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Participants</div>
                <div class="info-value">${request.participant_count}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Contact Person</div>
                <div class="info-value">${escapeHtml(request.contact_person)}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Contact Number</div>
                <div class="info-value">${escapeHtml(request.contact_number)}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Contact Email</div>
                <div class="info-value">${escapeHtml(request.email)}</div>
            </div>
        </div>
        
        ${request.organization_name ? `
            <div class="info-item" style="margin-top: 1rem;">
                <div class="info-label">Organization</div>
                <div class="info-value">${escapeHtml(request.organization_name)}</div>
            </div>
        ` : ''}
        
        ${request.location_preference ? `
            <div class="info-item" style="margin-top: 1rem;">
                <div class="info-label">Location Preference</div>
                <div class="info-value">${escapeHtml(request.location_preference)}</div>
            </div>
        ` : ''}
        
        ${request.purpose ? `
            <div class="info-item" style="margin-top: 1rem;">
                <div class="info-label">Purpose/Objective</div>
                <div class="info-value">${escapeHtml(request.purpose)}</div>
            </div>
        ` : ''}
        
        ${request.additional_requirements ? `
            <div class="info-item" style="margin-top: 1rem;">
                <div class="info-label">Additional Requirements</div>
                <div class="info-value">${escapeHtml(request.additional_requirements)}</div>
            </div>
        ` : ''}
        
        <div class="info-item" style="margin-top: 1rem;">
            <div class="info-label">Submitted</div>
            <div class="info-value">${new Date(request.created_at).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long', 
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit'
            })}</div>
        </div>
        
        ${request.reviewed_by_name ? `
            <div class="info-item" style="margin-top: 1rem;">
                <div class="info-label">Last Reviewed By</div>
                <div class="info-value">${escapeHtml(request.reviewed_by_name)} on ${new Date(request.reviewed_date).toLocaleDateString('en-US')}</div>
            </div>
        ` : ''}
    `;
    
    document.getElementById('requestModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
// Fix for View Registrations button click handling
const viewButtons = document.querySelectorAll('.btn-view');
viewButtons.forEach(button => {
    button.addEventListener('click', function(e) {
        e.stopPropagation();
        // Allow default navigation behavior
        return true;
    });
});

// Open Create Session Modal
function openCreateSessionModal(request) {
    currentRequest = request;
    document.getElementById('createRequestId').value = request.request_id;
    
    // Auto-fill form with request data
    const programName = request.program_name || request.training_program;
    document.getElementById('session_title').value = `${programName} Training`;
    
    // Set default dates (preferred date or 2 weeks from now)
    const defaultDate = request.preferred_date || 
        new Date(Date.now() + 14 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
    document.getElementById('session_date').value = defaultDate;
    document.getElementById('session_end_date').value = defaultDate;
    
    // Set capacity based on participant count
    document.getElementById('capacity').value = Math.max(request.participant_count, 10);
    
    // Set time based on preference
    if (request.preferred_time === 'morning') {
        document.getElementById('start_time').value = '08:00';
        document.getElementById('end_time').value = '17:00';
    } else if (request.preferred_time === 'afternoon') {
        document.getElementById('start_time').value = '13:00';
        document.getElementById('end_time').value = '17:00';
    } else {
        document.getElementById('start_time').value = '18:00';
        document.getElementById('end_time').value = '20:00';
    }
    
    // Set default venue if location preference provided
    if (request.location_preference) {
        document.getElementById('venue').value = `Training Venue\n${request.location_preference}\n\nDetailed directions will be provided upon confirmation.`;
    }
    
    // Populate request summary
    const requestSummary = document.getElementById('requestSummary');
    requestSummary.innerHTML = `
        <div class="summary-header">
            <i class="fas fa-info-circle"></i>
            Request Summary - #${request.request_id}
        </div>
        <div class="summary-grid">
            <div class="summary-item">
                <div class="summary-label">Requested By</div>
                <div class="summary-value">${escapeHtml(request.user_full_name || 'Unknown User')}</div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Training Program</div>
                <div class="summary-value">${escapeHtml(programName)}</div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Service</div>
                <div class="summary-value">${escapeHtml(request.service_type)}</div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Participants</div>
                <div class="summary-value">${request.participant_count}</div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Contact</div>
                <div class="summary-value">${escapeHtml(request.contact_person)}<br>${escapeHtml(request.contact_number)}</div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Email</div>
                <div class="summary-value">${escapeHtml(request.email)}</div>
            </div>
        </div>
    `;
    
    document.getElementById('createSessionModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

// Close modals
function closeRequestModal() {
    document.getElementById('requestModal').classList.remove('active');
    document.body.style.overflow = '';
    currentRequest = null;
}

function closeCreateSessionModal() {
    document.getElementById('createSessionModal').classList.remove('active');
    document.body.style.overflow = '';
    currentRequest = null;
}

// Form validation and submission
document.getElementById('createSessionForm').addEventListener('submit', function(e) {
    const sessionTitle = document.getElementById('session_title').value.trim();
    const sessionDate = document.getElementById('session_date').value;
    const sessionEndDate = document.getElementById('session_end_date').value;
    const startTime = document.getElementById('start_time').value;
    const endTime = document.getElementById('end_time').value;
    const venue = document.getElementById('venue').value.trim();
    
    if (!sessionTitle || !sessionDate || !sessionEndDate || !venue) {
        e.preventDefault();
        alert('Please fill in all required fields.');
        return;
    }
    
    if (new Date(sessionEndDate) < new Date(sessionDate)) {
        e.preventDefault();
        alert('End date cannot be before start date.');
        return;
    }
    
    if (endTime <= startTime) {
        e.preventDefault();
        alert('End time must be after start time.');
        return;
    }
    
    // Show loading state
    const submitBtn = this.querySelector('.btn-submit');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Session...';
    submitBtn.disabled = true;
});

// Update end date minimum when start date changes
document.getElementById('session_date').addEventListener('change', function() {
    const endDateInput = document.getElementById('session_end_date');
    endDateInput.min = this.value;
    if (endDateInput.value && endDateInput.value < this.value) {
        endDateInput.value = this.value;
    }
});

// Close modals when clicking outside
document.getElementById('requestModal').addEventListener('click', function(e) {
    if (e.target === this) closeRequestModal();
});

document.getElementById('createSessionModal').addEventListener('click', function(e) {
    if (e.target === this) closeCreateSessionModal();
});

// Keyboard navigation
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        if (document.getElementById('requestModal').classList.contains('active')) {
            closeRequestModal();
        }
        if (document.getElementById('createSessionModal').classList.contains('active')) {
            closeCreateSessionModal();
        }
    }
});

// Helper function for HTML escaping
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
// Document viewer function
function viewDocument(filePath) {
    if (!filePath) {
        alert('No document path provided');
        return;
    }
    
    // Ensure the file path is properly formatted
    let documentPath = filePath;
    if (!documentPath.startsWith('http') && !documentPath.startsWith('/')) {
        // If it's a relative path, make sure it's relative to the root
        documentPath = '../' + filePath;
    }
    
    // Create modal for document viewing
    const existingModal = document.querySelector('.document-modal');
    if (existingModal) {
        existingModal.remove();
    }
    
    const modal = document.createElement('div');
    modal.className = 'document-modal';
    modal.innerHTML = `
        <div class="document-modal-content">
            <div class="document-modal-header">
                <h3>
                    <i class="fas fa-file-alt"></i>
                    Document Viewer
                </h3>
                <button class="document-modal-close" onclick="closeDocumentModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="document-modal-body">
                <div class="document-loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    Loading document...
                </div>
                <div class="document-content" style="display: none;">
                    <!-- Document will be loaded here -->
                </div>
                <div class="document-actions">
                    <a href="${documentPath}" target="_blank" class="btn-download">
                        <i class="fas fa-external-link-alt"></i> Open in New Tab
                    </a>
                    <a href="${documentPath}" download class="btn-download">
                        <i class="fas fa-download"></i> Download
                    </a>
                </div>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    // Show modal
    setTimeout(() => {
        modal.classList.add('active');
    }, 10);
    
    // Load document content
    loadDocumentContent(documentPath, modal);
    
    // Close on outside click
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeDocumentModal();
        }
    });
    
    window.currentDocumentModal = modal;
    document.body.style.overflow = 'hidden';
}

// Load document content based on file type
function loadDocumentContent(filePath, modal) {
    const loadingDiv = modal.querySelector('.document-loading');
    const contentDiv = modal.querySelector('.document-content');
    
    // Get file extension
    const extension = filePath.split('.').pop().toLowerCase();
    
    setTimeout(() => {
        loadingDiv.style.display = 'none';
        contentDiv.style.display = 'block';
        
        if (['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'].includes(extension)) {
            // Image file
            contentDiv.innerHTML = `
                <div class="image-viewer">
                    <img src="${filePath}" alt="Document" style="max-width: 100%; max-height: 70vh; object-fit: contain;" 
                         onload="this.style.opacity='1'" 
                         onerror="showImageError(this)"
                         style="opacity: 0; transition: opacity 0.3s;">
                </div>
            `;
        } else if (extension === 'pdf') {
            // PDF file
            contentDiv.innerHTML = `
                <div class="pdf-viewer">
                    <iframe src="${filePath}" width="100%" height="500px" style="border: none; border-radius: 8px;">
                        <p>Your browser does not support PDFs. 
                           <a href="${filePath}" target="_blank">Click here to view the PDF</a>
                        </p>
                    </iframe>
                </div>
            `;
        } else {
            // Other document files
            contentDiv.innerHTML = `
                <div class="document-preview">
                    <div class="file-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <h4>${filePath.split('/').pop()}</h4>
                    <p>This document can be downloaded or opened in a new tab for viewing.</p>
                    <div class="file-info">
                        <span class="file-type">${extension.toUpperCase()} File</span>
                    </div>
                </div>
            `;
        }
    }, 500);
}

// Handle image load error
function showImageError(img) {
    img.parentElement.innerHTML = `
        <div class="error-message">
            <i class="fas fa-exclamation-triangle"></i>
            <h4>Unable to load image</h4>
            <p>The image file may be missing or corrupted. Try using the "Open in New Tab" or "Download" buttons below.</p>
        </div>
    `;
}

// Close document modal
function closeDocumentModal() {
    const modal = window.currentDocumentModal || document.querySelector('.document-modal');
    if (modal) {
        modal.classList.remove('active');
        setTimeout(() => {
            modal.remove();
            window.currentDocumentModal = null;
            document.body.style.overflow = '';
        }, 300);
    }
}
// CSS Styles for Multi-Day Sessions
const sessionStyles = `
/* Enhanced styles for multi-day session display */
.session-date-range {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}

.session-date-single .session-date {
    font-weight: 600;
    color: var(--dark);
}

.session-date-start {
    font-weight: 600;
    color: var(--dark);
    font-size: 0.9rem;
}

.session-date-end {
    font-size: 0.85rem;
    color: var(--gray);
    font-style: italic;
}

.session-duration {
    font-size: 0.75rem;
    background: linear-gradient(135deg, var(--light) 0%, #e3f2fd 100%);
    color: var(--blue);
    padding: 0.2rem 0.4rem;
    border-radius: 4px;
    display: inline-block;
    margin-top: 0.2rem;
    font-weight: 500;
    border: 1px solid rgba(33, 150, 243, 0.2);
    width: 100px;
}

.status-badge.ongoing {
    background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
    color: #856404;
    border: 1px solid #f7dc6f;
}

.status-badge.ongoing i {
    color: #ff9800;
}

/* Date Preview Styles (matching events system) */
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

/* Conflict Warning Styles */
.conflict-warning {
    margin: 1rem 0;
    animation: slideDown 0.3s ease, shake 0.5s ease;
}

.conflict-alert {
    background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
    border: 2px solid #ef5350;
    border-radius: 12px;
    padding: 1rem;
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
}

.conflict-alert i {
    color: #d32f2f;
    font-size: 1.2rem;
    margin-top: 0.1rem;
    flex-shrink: 0;
}

.conflict-content {
    flex: 1;
}

.conflict-title {
    font-weight: 600;
    color: #d32f2f;
    margin-bottom: 0.3rem;
}

.conflict-message {
    color: #c62828;
    font-size: 0.9rem;
    line-height: 1.4;
}

/* Button states for conflict */
.btn-submit.has-conflict {
    background: linear-gradient(135deg, #ff5722 0%, #d32f2f 100%);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { box-shadow: 0 3px 10px rgba(211, 47, 47, 0.3); }
    50% { box-shadow: 0 3px 20px rgba(211, 47, 47, 0.5); }
    100% { box-shadow: 0 3px 10px rgba(211, 47, 47, 0.3); }
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

@keyframes shake {
    0%, 20%, 40%, 60%, 80% { transform: translateX(0); }
    10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
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
    
    .conflict-alert {
        flex-direction: column;
        text-align: center;
    }
    
    .session-date-range {
        font-size: 0.8rem;
    }
    
    .session-duration {
        font-size: 0.7rem;
        padding: 0.1rem 0.3rem;
        width: 100px;
    }
}

@media (max-width: 576px) {
    .session-date-start,
    .session-date-end {
        font-size: 0.75rem;
    }
    
    .session-duration {
        font-size: 0.65rem;
        width: 100px;
    }
}
`;

// Inject styles
const styleSheet = document.createElement('style');
styleSheet.textContent = sessionStyles;
document.head.appendChild(styleSheet);
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
</style>
<script src="../admin/js/notification_frontend.js?v=<?php echo time(); ?>"></script>
  <script src="../admin/js/sidebar-notifications.js?v=<?php echo time(); ?>"></script>
<script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
</body>
</html>