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
require_once __DIR__ . '/../user/notifications_api.php';
ensure_logged_in();
ensure_admin();

// Handle document viewing and downloading
if (isset($_GET['path']) || isset($_GET['download'])) {
    $filePath = $_GET['path'] ?? '';
    $isDownload = isset($_GET['download']) && $_GET['download'] === 'true';
    $filename = $_GET['filename'] ?? '';
    
    // Security: Validate file path
    if (empty($filePath)) {
        http_response_code(400);
        die('Invalid file path');
    }
    
    // Security: Ensure file path starts with uploads/ to prevent directory traversal
    if (!str_starts_with($filePath, 'uploads/') && !str_starts_with($filePath, '../uploads/')) {
        http_response_code(403);
        die('Access denied');
    }
    
    // Build full file path
    $fullPath = __DIR__ . '/../' . $filePath;
    
    // Check if file exists
    if (!file_exists($fullPath) || !is_file($fullPath)) {
        http_response_code(404);
        die('File not found');
    }
    
    // Security: Verify this file belongs to a training request the user can access
try {
    $pdo = $GLOBALS['pdo'];
    
    // Enhanced query to check all possible document columns
    $stmt = $pdo->prepare("
        SELECT tr.request_id, tr.service_type, tr.user_id,
               tr.valid_id_path, tr.valid_id_request_path,
               tr.participant_list_path,
               tr.additional_docs_paths, tr.additional_docs_path
        FROM training_requests tr 
        WHERE tr.valid_id_path = ? 
           OR tr.valid_id_request_path = ?
           OR tr.participant_list_path = ? 
           OR tr.additional_docs_paths LIKE ? 
           OR tr.additional_docs_path LIKE ?
           OR JSON_CONTAINS(tr.additional_docs_paths, JSON_QUOTE(?))
           OR JSON_CONTAINS(tr.additional_docs_path, JSON_QUOTE(?))
    ");
    
    $pathPattern = '%' . $filePath . '%';
    $stmt->execute([
        $filePath,      // exact match for valid_id_path
        $filePath,      // exact match for valid_id_request_path  
        $filePath,      // exact match for participant_list_path
        $pathPattern,   // pattern match for additional_docs_paths
        $pathPattern,   // pattern match for additional_docs_path
        $filePath,      // JSON contains for additional_docs_paths
        $filePath       // JSON contains for additional_docs_path
    ]);
    
    $requestData = $stmt->fetch();
    
    // If no match found, try a more comprehensive search
    if (!$requestData) {
        // Fallback: Check if the file path contains any training request ID pattern
        $stmt2 = $pdo->prepare("
            SELECT tr.request_id, tr.service_type, tr.user_id
            FROM training_requests tr 
            WHERE ? LIKE CONCAT('%user_', tr.user_id, '%')
               OR ? LIKE CONCAT('%', tr.request_id, '%')
        ");
        $stmt2->execute([$filePath, $filePath]);
        $requestData = $stmt2->fetch();
    }
    
    if (!$requestData) {
        // Log the failed access attempt for debugging
        error_log("Document access denied for file: $filePath");
        error_log("User ID: " . $_SESSION['user_id'] . ", Role: " . get_user_role());
        
        http_response_code(403);
        die('File access denied - not associated with any training request');
    }
    
    // Check if user has permission to view this service type's documents
    $user_role = get_user_role();
    $roleServiceMapping = [
        'health' => ['Health Service'],
        'safety' => ['Safety Service'],
        'welfare' => ['Welfare Service'],
        'disaster' => ['Disaster Management Service'],
        'youth' => ['Red Cross Youth'],
        'super' => ['Health Service', 'Safety Service', 'Welfare Service', 'Disaster Management Service', 'Red Cross Youth']
    ];
    $allowedServices = $roleServiceMapping[$user_role] ?? [];
    $hasRestrictedAccess = $user_role !== 'super';
    
    if ($hasRestrictedAccess && !in_array($requestData['service_type'], $allowedServices)) {
        error_log("Access denied - insufficient permissions for service type: " . $requestData['service_type']);
        http_response_code(403);
        die('Access denied - insufficient permissions for this service type');
    }
    
} catch (PDOException $e) {
    error_log("Document access check error: " . $e->getMessage());
    error_log("File path attempted: " . $filePath);
    http_response_code(500);
    die('Database error');
}

    
    // Get file info
    $fileSize = filesize($fullPath);
    $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';
    
    // Set filename for download
    if (empty($filename)) {
        $filename = basename($filePath);
    }
    
    // Security headers
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    
    if ($isDownload) {
        // Force download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . $fileSize);
        
        // Prevent caching for downloads
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        
    } else {
        // View in browser (for images/PDFs)
        if (str_starts_with($mimeType, 'image/')) {
            header('Content-Type: ' . $mimeType);
        } elseif ($mimeType === 'application/pdf') {
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
        } else {
            // For other file types, force download for security
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        }
        
        header('Content-Length: ' . $fileSize);
    }
    
    // Output file
    readfile($fullPath);
    exit;
}

$pdo = $GLOBALS['pdo'];

// Initialize all variables to prevent undefined variable errors
$errorMessage = '';
$successMessage = '';
$trainingRequests = [];
$requestStats = ['total' => 0, 'pending' => 0, 'under_review' => 0, 'approved' => 0, 'scheduled' => 0, 'completed' => 0, 'rejected' => 0];

date_default_timezone_set('Asia/Kuala_Lumpur');

// Get user role and ID
$user_role = get_user_role();
$current_user_id = $_SESSION['user_id'] ?? 0;

if (!$user_role) {
    $user_role = 'super'; // Default fallback
}

// Define role-to-service mapping
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
    error_log("[REQUEST_DEBUG] " . $message);
}

// Log current user role and permissions
logDebug("User role: $user_role, User ID: $current_user_id, Allowed services: " . implode(', ', $allowedServices));

function handleDatabaseError($e) {
    error_log("Database error: " . $e->getMessage());
    return "Database error occurred. Please try again later.";
}

// Handle training request actions with automatic session creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_request_status'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security error: Invalid form submission.";
    } else {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        $adminNotes = trim($_POST['admin_notes'] ?? '');
        
        if ($requestId > 0 && in_array($newStatus, ['pending', 'under_review', 'approved', 'scheduled', 'completed', 'rejected'])) {
            try {
                // Get the current request details
                $checkStmt = $pdo->prepare("SELECT * FROM training_requests WHERE request_id = ?");
                $checkStmt->execute([$requestId]);
                $request = $checkStmt->fetch();
                
                if (!$request) {
                    $errorMessage = "Training request not found.";
                } else if ($hasRestrictedAccess && !in_array($request['service_type'], $allowedServices)) {
                    $errorMessage = "You don't have permission to manage this request.";
                } else {
                    // Check if status is changing to "approved" and no session exists yet
                    $shouldCreateSession = ($newStatus === 'approved' && 
                                          $request['status'] !== 'approved' && 
                                          empty($request['created_session_id']));
                    
                    // Update request status first
                    $stmt = $pdo->prepare("
                        UPDATE training_requests 
                        SET status = ?, admin_notes = ?, reviewed_by = ?, reviewed_date = NOW() 
                        WHERE request_id = ?
                    ");
                    $result = $stmt->execute([$newStatus, $adminNotes, $current_user_id, $requestId]);
                    
                    if ($result) {
    try {
        notifyTrainingRequest($pdo, $requestId, $newStatus);
    } catch (Exception $e) {
        error_log("Notification error: " . $e->getMessage());
    }
                        // If status changed to approved, automatically create training session
                        if ($shouldCreateSession) {
                            try {
                                // Get program details for better session title
                                $programStmt = $pdo->prepare("
                                    SELECT program_name, typical_duration_hours 
                                    FROM training_programs 
                                    WHERE program_code = ? AND service_type = ?
                                ");
                                $programStmt->execute([$request['training_program'], $request['service_type']]);
                                $program = $programStmt->fetch();
                                
                                // Generate session title
                                $programName = $program['program_name'] ?? $request['training_program'];
                                $sessionTitle = $programName . " Training";
                                if ($request['organization_name']) {
                                    $sessionTitle .= " - " . $request['organization_name'];
                                }
                                
                                // FIXED: Calculate proper dates and duration from the request
                                $sessionStartDate = $request['preferred_start_date'] ?: date('Y-m-d', strtotime('+14 days'));
                                $sessionEndDate = $request['preferred_end_date'] ?: $sessionStartDate;
                                
                                // Calculate duration in days
                                $startDateTime = new DateTime($sessionStartDate);
                                $endDateTime = new DateTime($sessionEndDate);
                                $durationDays = $startDateTime->diff($endDateTime)->days + 1;
                                
                                // Ensure minimum 1 day duration
                                $durationDays = max(1, $durationDays);
                                
                                // If request has duration_days field, use it as override
                                if (!empty($request['duration_days']) && $request['duration_days'] > 0) {
                                    $durationDays = $request['duration_days'];
                                    // Recalculate end date based on duration
                                    $endDateTime = clone $startDateTime;
                                    $endDateTime->add(new DateInterval('P' . ($durationDays - 1) . 'D'));
                                    $sessionEndDate = $endDateTime->format('Y-m-d');
                                }
                                
     // Set default times based on request preferences
$startTime = '09:00';
$endTime = '17:00';

// FIXED: Use specific times from the request if provided
if (!empty($request['preferred_start_time'])) {
    $startTime = $request['preferred_start_time'];
}

if (!empty($request['preferred_end_time'])) {
    $endTime = $request['preferred_end_time'];
}

// ONLY use typical duration if NO specific times were provided
if (empty($request['preferred_start_time']) && empty($request['preferred_end_time'])) {
    // Use typical duration to extend session time
    if ($program['typical_duration_hours'] && $program['typical_duration_hours'] > 8) {
        $endTime = date('H:i', strtotime($startTime . ' + ' . $program['typical_duration_hours'] . ' hours'));
    }
}
                                
                                // Set default venue based on location preference or generic
                                $venue = $request['location_preference'] ?: 
                                    "Philippine Red Cross Training Center\n" .
                                    "To be confirmed - detailed location will be provided\n\n" .
                                    "Contact: " . $request['contact_person'] . "\n" .
                                    "Phone: " . $request['contact_number'];
                                
                                // Set capacity based on participant count (add 20% buffer)
                                $capacity = max($request['participant_count'], ceil($request['participant_count'] * 1.2));
                                
                                // Default fee is free unless specified
                                $fee = 0.00;
                                
                                // Create training session with FIXED multi-day support
                                $sessionStmt = $pdo->prepare("
                                    INSERT INTO training_sessions (
                                        title, major_service, session_date, session_end_date, duration_days,
                                        start_time, end_time, venue, capacity, fee, created_by, created_at
                                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                                ");
                                
                                $sessionResult = $sessionStmt->execute([
                                    $sessionTitle,
                                    $request['service_type'],
                                    $sessionStartDate,        // FIXED: Use calculated start date
                                    $sessionEndDate,          // FIXED: Use calculated end date
                                    $durationDays,            // FIXED: Use calculated duration
                                    $startTime,
                                    $endTime,
                                    $venue,
                                    $capacity,
                                    $fee,
                                    $current_user_id
                                ]);
                                
                                if ($sessionResult) {
                                    $sessionId = $pdo->lastInsertId();
                                    
                                    // Link the session to the request and mark as scheduled
                                    $linkStmt = $pdo->prepare("
                                        UPDATE training_requests 
                                        SET created_session_id = ?, status = 'scheduled'
                                        WHERE request_id = ?
                                    ");
                                    $linkStmt->execute([$sessionId, $requestId]);
                                    
                                    $durationText = $durationDays > 1 ? 
                                        " ({$durationDays} days from {$sessionStartDate} to {$sessionEndDate})" : 
                                        " (single day on {$sessionStartDate})";
                                        
                                    $successMessage = "Training request approved and session created automatically! " .
                                                    "Session ID: #" . $sessionId . $durationText . " " .
                                                    "Please review and edit the session details as needed in the Training Sessions page.";
                                    
                                    logDebug("Auto-created session $sessionId from approved request $requestId with duration: $durationDays days");
                                } else {
                                    // If session creation fails, keep the request as approved
                                    $successMessage = "Training request approved successfully! " .
                                                    "However, automatic session creation failed. " .
                                                    "Please create the session manually.";
                                    logDebug("Failed to auto-create session from request $requestId");
                                }
                                
                            } catch (Exception $e) {
                                // If session creation fails, keep the request as approved
                                $successMessage = "Training request approved successfully! " .
                                                "However, automatic session creation failed: " . $e->getMessage() . " " .
                                                "Please create the session manually.";
                                error_log("Auto session creation error: " . $e->getMessage());
                                logDebug("Exception auto-creating session from request $requestId: " . $e->getMessage());
                            }
                        } else {
                            $successMessage = "Training request status updated successfully!";
                        }
                         notifyTrainingRequest($pdo, $requestId, $newStatus);
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    } else {
                        $errorMessage = "Failed to update request status.";
                    }
                }
            } catch (PDOException $e) {
                $errorMessage = "Database error occurred.";
                logDebug("Error updating request status: " . $e->getMessage());
            }
        } else {
            $errorMessage = "Invalid request data.";
        }
    }
}

// Handle create training session from request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_from_request'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security error: Invalid form submission.";
    } else {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $sessionTitle = trim($_POST['session_title'] ?? '');
        $sessionDate = $_POST['session_date'] ?? '';
        $sessionEndDate = $_POST['session_end_date'] ?? '';
        $startTime = $_POST['start_time'] ?? '09:00';
        $endTime = $_POST['end_time'] ?? '17:00';
        $venue = trim($_POST['venue'] ?? '');
        $capacity = (int)($_POST['capacity'] ?? 0);
        $fee = (float)($_POST['fee'] ?? 0);
        
        if ($requestId > 0 && !empty($sessionTitle) && !empty($sessionDate) && !empty($venue)) {
            try {
                // Get request details
                $requestStmt = $pdo->prepare("SELECT * FROM training_requests WHERE request_id = ?");
                $requestStmt->execute([$requestId]);
                $request = $requestStmt->fetch();
                
                if (!$request) {
                    $errorMessage = "Training request not found.";
                } else if ($hasRestrictedAccess && !in_array($request['service_type'], $allowedServices)) {
                    $errorMessage = "You don't have permission to create sessions for this service.";
                } else {
                    // Calculate duration days
                    $startDate = new DateTime($sessionDate);
                    $endDate = new DateTime($sessionEndDate ?: $sessionDate);
                    $durationDays = $startDate->diff($endDate)->days + 1;
                    
                    // Create training session
                    $sessionStmt = $pdo->prepare("
                        INSERT INTO training_sessions (
                            title, major_service, session_date, session_end_date, duration_days,
                            start_time, end_time, venue, capacity, fee, created_by, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $result = $sessionStmt->execute([
                        $sessionTitle, $request['service_type'], $sessionDate, 
                        $sessionEndDate ?: $sessionDate, $durationDays,
                        $startTime, $endTime, $venue, $capacity, $fee, $current_user_id
                    ]);
                    
                    if ($result) {
                        $sessionId = $pdo->lastInsertId();
                        
                        // Update request with created session ID
                        $updateStmt = $pdo->prepare("
                            UPDATE training_requests 
                            SET created_session_id = ?, status = 'scheduled', reviewed_by = ?, reviewed_date = NOW()
                            WHERE request_id = ?
                        ");
                        $updateStmt->execute([$sessionId, $current_user_id, $requestId]);
                        
                        $successMessage = "Training session created successfully from request! Session ID: #" . $sessionId;
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    } else {
                        $errorMessage = "Failed to create training session.";
                    }
                }
            } catch (PDOException $e) {
                error_log("Create session from request error: " . $e->getMessage());
                $errorMessage = "Database error occurred.";
            }
        } else {
            $errorMessage = "Please fill in all required fields.";
        }
    }
}

// Handle document verification update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_document_verification'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security error: Invalid form submission.";
    } else {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $verificationStatus = $_POST['verification_status'] ?? '';
        $verificationNotes = trim($_POST['verification_notes'] ?? '');
        
        if ($requestId > 0 && in_array($verificationStatus, ['pending', 'verified', 'rejected'])) {
            try {
                // Check if user has permission to verify this request
                $checkStmt = $pdo->prepare("SELECT service_type FROM training_requests WHERE request_id = ?");
                $checkStmt->execute([$requestId]);
                $requestService = $checkStmt->fetchColumn();
                
                if ($hasRestrictedAccess && !in_array($requestService, $allowedServices)) {
                    $errorMessage = "You don't have permission to verify documents for this service.";
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE training_requests 
                        SET documents_verified = ?, document_verification_notes = ?, reviewed_by = ?, reviewed_date = NOW() 
                        WHERE request_id = ?
                    ");
                    $result = $stmt->execute([$verificationStatus, $verificationNotes, $current_user_id, $requestId]);
                    
                    if ($result) {
                        $successMessage = "Document verification status updated successfully!";
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    } else {
                        $errorMessage = "Failed to update document verification status.";
                    }
                }
            } catch (PDOException $e) {
                $errorMessage = "Database error occurred.";
                logDebug("Error updating document verification: " . $e->getMessage());
            }
        } else {
            $errorMessage = "Invalid verification data.";
        }
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$serviceFilter = isset($_GET['service']) ? trim($_GET['service']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$limit = 20;
$offset = ($page - 1) * $limit;

// Get training requests based on admin permissions - FIXED VERSION
$requestWhereConditions = [];
$requestParams = [];
$requestWhereClause = '';
$totalRequests = 0;
$totalPages = 1;

try {
    // Debug current state
    logDebug("Starting query build - User role: $user_role, Has restricted access: " . ($hasRestrictedAccess ? 'true' : 'false'));
    
    // Build WHERE conditions
    $requestWhereConditions = [];
    $requestParams = [];
    
    // Apply service restrictions for non-super admins
    if ($hasRestrictedAccess && !empty($allowedServices)) {
        $placeholders = str_repeat('?,', count($allowedServices) - 1) . '?';
        $requestWhereConditions[] = "tr.service_type IN ($placeholders)";
        $requestParams = array_merge($requestParams, $allowedServices);
        logDebug("Added service restriction for: " . implode(', ', $allowedServices));
    }
    
    // Add search filter
    if ($search) {
        $requestWhereConditions[] = "(tr.training_program LIKE ? OR tr.organization_name LIKE ? OR tr.contact_person LIKE ? OR tr.purpose LIKE ?)";
        $requestParams[] = '%' . $search . '%';
        $requestParams[] = '%' . $search . '%';
        $requestParams[] = '%' . $search . '%';
        $requestParams[] = '%' . $search . '%';
    }
    
    // Add service filter
    if ($serviceFilter && $serviceFilter !== '') {
        if (!$hasRestrictedAccess || in_array($serviceFilter, $allowedServices)) {
            $requestWhereConditions[] = "tr.service_type = ?";
            $requestParams[] = $serviceFilter;
        }
    }
    
    // Add status filter
    if ($statusFilter && $statusFilter !== '') {
        $requestWhereConditions[] = "tr.status = ?";
        $requestParams[] = $statusFilter;
    }
    
    $requestWhereClause = $requestWhereConditions ? 'WHERE ' . implode(' AND ', $requestWhereConditions) : '';
    
    // Count query
    $countQuery = "SELECT COUNT(*) FROM training_requests tr $requestWhereClause";
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($requestParams);
    $totalRequests = $countStmt->fetchColumn();
    $totalPages = ceil($totalRequests / $limit);
    
    // Updated main query with proper filename fields
    $requestQuery = "
        SELECT tr.*,
               u.full_name as user_full_name, 
               u.email as user_email,
               admin_u.full_name as reviewed_by_name,
               COALESCE(tp.program_name, tr.training_program) as program_name,
               tp.program_description, 
               tp.typical_duration_hours
        FROM training_requests tr
        LEFT JOIN users u ON tr.user_id = u.user_id
        LEFT JOIN users admin_u ON tr.reviewed_by = admin_u.user_id
        LEFT JOIN training_programs tp ON (tr.training_program = tp.program_code AND tr.service_type = tp.service_type)
        $requestWhereClause
        ORDER BY tr.created_at DESC
    ";
    
    $requestStmt = $pdo->prepare($requestQuery);
    $requestStmt->execute($requestParams);
    $allResults = $requestStmt->fetchAll();
    
    // Manual pagination
    $trainingRequests = array_slice($allResults, $offset, $limit);
    
    logDebug("Query returned " . count($allResults) . " total results, showing " . count($trainingRequests) . " on this page");
    
    // Statistics query
    $statsQuery = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN tr.status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN tr.status = 'under_review' THEN 1 ELSE 0 END) as under_review,
            SUM(CASE WHEN tr.status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN tr.status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
            SUM(CASE WHEN tr.status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN tr.status = 'rejected' THEN 1 ELSE 0 END) as rejected
        FROM training_requests tr
        $requestWhereClause
    ";
    
    $statsStmt = $pdo->prepare($statsQuery);
    $statsStmt->execute($requestParams);
    $requestStats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'total' => 0, 'pending' => 0, 'under_review' => 0, 
        'approved' => 0, 'scheduled' => 0, 'completed' => 0, 'rejected' => 0
    ];
    
} catch (PDOException $e) {
    error_log("Error fetching training requests: " . $e->getMessage());
    logDebug("EXCEPTION in query: " . $e->getMessage());
    
    $trainingRequests = [];
    $requestStats = [
        'total' => 0, 'pending' => 0, 'under_review' => 0, 
        'approved' => 0, 'scheduled' => 0, 'completed' => 0, 'rejected' => 0
    ];
    $totalRequests = 0;
    $totalPages = 1;
}

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

function getFileIcon($filename) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    switch ($extension) {
        case 'pdf':
            return 'fa-file-pdf';
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'gif':
            return 'fa-file-image';
        case 'doc':
        case 'docx':
            return 'fa-file-word';
        case 'xls':
        case 'xlsx':
            return 'fa-file-excel';
        case 'csv':
            return 'fa-file-csv';
        default:
            return 'fa-file';
    }
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
  <title>Training Requests Management - PRC Admin</title>
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
  <link rel="stylesheet" href="../assets/training_request.css?v=<?= time() ?>">
</head>
<body class="admin-<?= htmlspecialchars($user_role) ?>">
  <?php include 'sidebar.php'; ?>
  <div class="sessions-container">

    <div class="page-header">
      <div class="header-content">
        <h1><i class="fas fa-chalkboard-teacher"></i> Training Requests Management</h1>
        <p>
          <?php if ($hasRestrictedAccess): ?>
            Review and manage <?= htmlspecialchars(implode(' and ', $allowedServices)) ?> training requests
          <?php else: ?>
            Review and manage incoming training requests from users
          <?php endif; ?>
        </p>
      </div>
      <?php if ($hasRestrictedAccess): ?>
        <div class="branch-indicator">
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

    <!-- Service Filter Tabs -->
    <div class="service-tabs">
      <a href="?" class="service-tab all-services <?= !$serviceFilter ? 'active' : '' ?>">
        <div class="service-name">
          <?= $hasRestrictedAccess ? 'My Requests' : 'All Requests' ?>
        </div>
        <div class="service-count"><?= $requestStats['total'] ?> requests</div>
      </a>
      
      <?php 
      $servicesToShow = $hasRestrictedAccess ? $allowedServices : $majorServices;
      foreach ($servicesToShow as $service): 
          $serviceCount = count(array_filter($trainingRequests, function($r) use ($service) { 
              return $r['service_type'] === $service; 
          }));
      ?>
        <a href="?service=<?= urlencode($service) ?>&status=<?= htmlspecialchars($statusFilter) ?>&search=<?= urlencode($search) ?>" 
           class="service-tab <?= $serviceFilter === $service ? 'active' : '' ?>">
          <div class="service-name"><?= htmlspecialchars($service) ?></div>
          <div class="service-count"><?= $serviceCount ?> requests</div>
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
          <input type="text" name="search" placeholder="Search requests..." value="<?= htmlspecialchars($search) ?>">
        </form>
        
        <div class="status-filter">
          <button onclick="filterStatus('all')" class="<?= !$statusFilter ? 'active' : '' ?>">All</button>
          <button onclick="filterStatus('pending')" class="<?= $statusFilter === 'pending' ? 'active' : '' ?>">Pending</button>
          <button onclick="filterStatus('under_review')" class="<?= $statusFilter === 'under_review' ? 'active' : '' ?>">Review</button>
          <button onclick="filterStatus('approved')" class="<?= $statusFilter === 'approved' ? 'active' : '' ?>">Approved</button>
          <button onclick="filterStatus('scheduled')" class="<?= $statusFilter === 'scheduled' ? 'active' : '' ?>">Scheduled</button>
        </div>
      </div>
    </div>

    <!-- Request Statistics -->
    <div class="stats-overview">
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
          <i class="fas fa-clipboard-list"></i>
        </div>
        <div>
          <div style="font-size: 1.5rem; font-weight: 700;"><?= $requestStats['total'] ?></div>
          <div style="color: var(--gray); font-size: 0.9rem;">Need Review</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #00c853 0%, #64dd17 100%);">
          <i class="fas fa-check-circle"></i>
        </div>
        <div>
          <div style="font-size: 1.5rem; font-weight: 700;"><?= $requestStats['approved'] + $requestStats['scheduled'] ?></div>
          <div style="color: var(--gray); font-size: 0.9rem;">Approved</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #4caf50 0%, #8bc34a 100%);">
          <i class="fas fa-graduation-cap"></i>
        </div>
        <div>
          <div style="font-size: 1.5rem; font-weight: 700;"><?= $requestStats['completed'] ?></div>
          <div style="color: var(--gray); font-size: 0.9rem;">Completed</div>
        </div>
      </div>
    </div>

    <!-- Training Requests Table -->
    <div class="sessions-table-wrapper">
      <div class="table-header">
        <h2 class="table-title">
          <?php if ($serviceFilter): ?>
            <?= htmlspecialchars($serviceFilter) ?> Requests
          <?php elseif ($hasRestrictedAccess): ?>
            <?= htmlspecialchars(implode(' & ', $allowedServices)) ?> Requests
          <?php else: ?>
            All Training Requests
          <?php endif; ?>
        </h2>
      </div>
      
      <?php if (empty($trainingRequests)): ?>
        <div class="empty-state">
          <i class="fas fa-inbox"></i>
          <h3>No training requests found</h3>
          <p><?= $search ? 'Try adjusting your search criteria' : 'No users have submitted training requests yet.' ?></p>
          <?php if ($hasRestrictedAccess): ?>
            <small style="color: var(--gray); margin-top: 0.5rem;">
              You can only view and manage <?= htmlspecialchars(implode(', ', $allowedServices)) ?> requests.
            </small>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>Request Details</th>
              <th>Requester</th>
              <th>Service & Program</th>
              <th>Schedule Preference</th>
              <th>Participants</th>
              <th>Status</th>
              <th>Actions</th>
              <th>Documents</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($trainingRequests as $req): ?>
            <tr class="request-row" data-status="<?= $req['status'] ?>">
              <td>
                <div class="request-header">
                  <strong style="color: var(--prc-red);">Request #<?= $req['request_id'] ?></strong>
                  <small style="color: var(--gray); margin-left: 0.5rem;">
                    <?= date('M d, Y g:i A', strtotime($req['created_at'])) ?>
                  </small>
                </div>
                <?php if ($req['purpose']): ?>
                  <div style="font-size: 0.85rem; color: var(--gray); margin-top: 0.3rem;">
                    <strong>Purpose:</strong> <?= htmlspecialchars(substr($req['purpose'], 0, 80)) ?><?= strlen($req['purpose']) > 80 ? '...' : '' ?>
                  </div>
                <?php endif; ?>
                <?php if ($req['location_preference']): ?>
                  <div style="font-size: 0.8rem; color: var(--gray); margin-top: 0.2rem;">
                    <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($req['location_preference']) ?>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <div class="requester-info">
                  <div style="font-weight: 600;"><?= htmlspecialchars($req['user_full_name'] ?? 'Unknown User') ?></div>
                  <div style="font-size: 0.85rem; color: var(--gray);"><?= htmlspecialchars($req['user_email']) ?></div>
                  <div style="font-size: 0.8rem; margin-top: 0.2rem;">
                    <strong>Contact:</strong> <?= htmlspecialchars($req['contact_person']) ?><br>
                    <?= htmlspecialchars($req['contact_number']) ?><br>
                    <?= htmlspecialchars($req['email']) ?>
                  </div>
                </div>
              </td>
              <td>
                <span class="service-badge <?= strtolower(str_replace(' ', '-', $req['service_type'])) ?>">
                  <?= htmlspecialchars($req['service_type']) ?>
                </span>
                <div style="font-weight: 600; margin-top: 0.3rem;">
                  <?= htmlspecialchars($req['program_name'] ?: $req['training_program']) ?>
                </div>
                <?php if ($req['typical_duration_hours']): ?>
                  <small style="color: var(--gray);"><?= $req['typical_duration_hours'] ?> hours</small>
                <?php endif; ?>
              </td>
             <td>
  <?php if ($req['preferred_start_date']): ?>
    <div style="font-weight: 600;">
      <?= date('M d, Y', strtotime($req['preferred_start_date'])) ?>
      <?php if ($req['preferred_end_date'] && $req['preferred_end_date'] !== $req['preferred_start_date']): ?>
        <div style="font-size: 0.8rem; color: var(--gray);">
          to <?= date('M d, Y', strtotime($req['preferred_end_date'])) ?>
        </div>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div style="color: var(--gray); font-style: italic;">Flexible</div>
  <?php endif; ?>
  
  <!-- Show specific times if available -->
  <?php if (!empty($req['preferred_start_time']) || !empty($req['preferred_end_time'])): ?>
    <div style="font-size: 0.8rem; color: #2196F3; margin-top: 0.3rem; font-weight: 500;">
      <i class="fas fa-clock"></i>
      <?php if (!empty($req['preferred_start_time'])): ?>
        <?= date('g:i A', strtotime($req['preferred_start_time'])) ?>
      <?php endif; ?>
      <?php if (!empty($req['preferred_end_time'])): ?>
        <?php if (!empty($req['preferred_start_time'])): ?>
          - <?= date('g:i A', strtotime($req['preferred_end_time'])) ?>
        <?php else: ?>
          End: <?= date('g:i A', strtotime($req['preferred_end_time'])) ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  
  <?php if ($req['location_preference']): ?>
    <div style="font-size: 0.8rem; color: var(--gray); margin-top: 0.2rem;">
      <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($req['location_preference']) ?>
    </div>
  <?php endif; ?>
</td>
              <td>
                <div style="text-align: center;">
                  <div style="font-size: 1.2rem; font-weight: 600;"><?= $req['participant_count'] ?></div>
                  <small style="color: var(--gray);">participants</small>
                </div>
                <?php if ($req['organization_name']): ?>
                  <div style="font-size: 0.8rem; color: var(--gray); margin-top: 0.2rem;">
                    <?= htmlspecialchars($req['organization_name']) ?>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <span class="status-badge <?= $req['status'] ?>">
                  <i class="fas <?= 
                      $req['status'] === 'pending' ? 'fa-clock' : 
                      ($req['status'] === 'under_review' ? 'fa-search' :
                      ($req['status'] === 'approved' ? 'fa-check-circle' :
                      ($req['status'] === 'scheduled' ? 'fa-calendar-check' :
                      ($req['status'] === 'completed' ? 'fa-graduation-cap' : 'fa-times-circle')))) ?>"></i>
                  <?= ucwords(str_replace('_', ' ', $req['status'])) ?>
                </span>
                <?php if ($req['reviewed_by_name']): ?>
                  <div style="font-size: 0.7rem; color: var(--gray); margin-top: 0.2rem;">
                    by <?= htmlspecialchars($req['reviewed_by_name']) ?>
                  </div>
                <?php endif; ?>
              </td>
              <td class="actions">
                <button class="btn-action btn-view" onclick='openRequestModal(<?= json_encode($req, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                  <i class="fas fa-eye"></i> View Details
                </button>
                
                <!-- Status Update Form -->
                <form method="POST" style="display: inline-block; margin-bottom: 0.5rem;">
                  <input type="hidden" name="update_request_status" value="1">
                  <input type="hidden" name="request_id" value="<?= $req['request_id'] ?>">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                  
                  <select name="new_status" onchange="this.form.submit()" class="status-select">
                    <option value="pending" <?= $req['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="under_review" <?= $req['status'] === 'under_review' ? 'selected' : '' ?>>Under Review</option>
                    <option value="approved" <?= $req['status'] === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="scheduled" <?= $req['status'] === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                    <option value="completed" <?= $req['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="rejected" <?= $req['status'] === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                  </select>
                </form>
                
                <?php if (in_array($req['status'], ['approved', 'under_review'])): ?>
                  <button class="btn-action btn-edit" onclick='openCreateSessionModal(<?= json_encode($req, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                    <i class="fas fa-calendar-plus"></i> Create Session
                  </button>
                <?php endif; ?>
              </td>
              <td class="documents-column">
    <?php
    // FIXED: Comprehensive document counting logic that matches the modal
    $hasDocuments = false;
    $docCount = 0;
    
    // Count Valid ID (prioritize valid_id_request_path, then valid_id_path)
    $validIdPath = $req['valid_id_request_path'] ?: $req['valid_id_path'];
    if (!empty($validIdPath) && trim($validIdPath) !== '' && $validIdPath !== 'null') {
        $hasDocuments = true;
        $docCount++;
    }
    
    // Count participant list
    if (!empty($req['participant_list_path']) && trim($req['participant_list_path']) !== '' && $req['participant_list_path'] !== 'null') {
        $hasDocuments = true;
        $docCount++;
    }
    
    // Count additional documents - handle both fields
    $additionalFields = [
        $req['additional_docs_paths'],
        $req['additional_docs_path']
    ];
    
    foreach ($additionalFields as $additionalField) {
        if (!empty($additionalField) && trim($additionalField) !== '' && $additionalField !== 'null' && $additionalField !== '[]') {
            $hasDocuments = true;
            
            try {
                // Handle JSON array format
                if (strpos($additionalField, '[') === 0 && strpos($additionalField, ']') === strlen($additionalField) - 1) {
                    $additionalDocs = json_decode($additionalField, true);
                    if (is_array($additionalDocs)) {
                        // Count only non-empty, non-null entries
                        foreach ($additionalDocs as $doc) {
                            if (!empty($doc) && trim($doc) !== '' && $doc !== 'null') {
                                $docCount++;
                            }
                        }
                    } else {
                        // JSON decode failed, assume 1 document
                        $docCount++;
                    }
                } else {
                    // Single document path
                    $docCount++;
                }
            } catch (Exception $e) {
                // If parsing fails, assume 1 document
                $docCount++;
            }
        }
    }
                ?>
                
                <?php if ($hasDocuments): ?>
                    <div class="document-indicator">
                        <div class="doc-count">
                            <i class="fas fa-paperclip"></i>
                            <span><?= $docCount ?></span>
                        </div>
                        <div class="doc-status-mini <?= $req['documents_verified'] ?? 'pending' ?>">
                            <i class="fas <?= 
                                ($req['documents_verified'] ?? 'pending') === 'verified' ? 'fa-check-circle' : 
                                (($req['documents_verified'] ?? 'pending') === 'rejected' ? 'fa-times-circle' : 'fa-clock') 
                            ?>"></i>
                        </div>
                        <button onclick='openDocumentModal(<?= json_encode($req, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' 
                                class="btn-view-docs-mini">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                <?php else: ?>
                    <div class="no-documents-indicator">
                        <i class="fas fa-file-slash" style="color: var(--gray); opacity: 0.5;"></i>
                    </div>
                <?php endif; ?>
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
            
            <span class="page-info">Page <?= $page ?> of <?= $totalPages ?> (<?= $totalRequests ?> total requests)</span>
            
            <?php if ($page < $totalPages): ?>
              <a href="?page=<?= $page+1 ?>&service=<?= urlencode($serviceFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&search=<?= urlencode($search) ?>" class="page-link">
                Next <i class="fas fa-chevron-right"></i>
              </a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Request Details Modal -->
  <div class="modal" id="requestModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title">Request Details</h2>
        <button class="close-modal" onclick="closeRequestModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <div id="requestDetails" class="request-details">
        <!-- Request details will be populated by JavaScript -->
      </div>
      
      <form method="POST" style="margin-top: 1.5rem;">
        <input type="hidden" name="update_request_status" value="1">
        <input type="hidden" name="request_id" id="updateRequestId">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        
        <div class="form-group">
          <label for="new_status">Update Status</label>
          <select id="new_status" name="new_status" class="status-select" style="width: 100%;">
            <option value="pending">Pending</option>
            <option value="under_review">Under Review</option>
            <option value="approved">Approved</option>
            <option value="scheduled">Scheduled</option>
            <option value="completed">Completed</option>
            <option value="rejected">Rejected</option>
          </select>
        </div>
        

        <div class="modal-footer">
          <button type="button" class="btn-action btn-edit" onclick="closeRequestModal()">Cancel</button>
          <button type="submit" class="btn-submit">
            <i class="fas fa-save"></i> Update Request
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Create Session from Request Modal -->
  <div class="modal" id="createSessionModal">
    <div class="modal-content" style="max-width: 700px;">
      <div class="modal-header">
        <h2 class="modal-title">Create Training Session</h2>
        <button class="close-modal" onclick="closeCreateSessionModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <div id="requestSummary" class="request-summary">
        <!-- Request summary will be populated by JavaScript -->
      </div>
      
      <form method="POST" id="createSessionForm">
        <input type="hidden" name="create_from_request" value="1">
        <input type="hidden" name="request_id" id="createRequestId">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        
        <div class="form-group">
          <label for="session_title">Session Title *</label>
          <input type="text" id="session_title" name="session_title" required 
                 placeholder="Enter session title" maxlength="255">
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label for="session_date">Start Date *</label>
            <input type="date" id="session_date" name="session_date" required min="<?= date('Y-m-d') ?>">
          </div>
          
          <div class="form-group">
            <label for="session_end_date">End Date *</label>
            <input type="date" id="session_end_date" name="session_end_date" required min="<?= date('Y-m-d') ?>">
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
        
        <div class="form-group venue-group">
          <label for="venue">
            Training Venue & Directions *
            <span class="field-hint">Include full address and travel instructions</span>
          </label>
          <textarea id="venue" name="venue" required rows="4" maxlength="2000"
                    placeholder="Example:&#10;Philippine Red Cross Training Center&#10;Real Street, Guadalupe, Cebu City, 6000 Cebu&#10;&#10;Travel Instructions:&#10;- From Ayala Center: Take jeepney to Guadalupe, alight at Real Street&#10;- Parking available at the rear entrance&#10;- Training Room 2A (Second Floor)&#10;- Contact: 032-123-4567 for assistance"
                    style="resize: vertical; min-height: 100px;"></textarea>
          <div class="field-info">
            <span class="char-counter">
              <span id="venue-char-count">0</span>/2000 characters
            </span>
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
        
        <div class="modal-footer">
          <button type="button" class="btn-action btn-edit" onclick="closeCreateSessionModal()">Cancel</button>
          <button type="submit" class="btn-submit">
            <i class="fas fa-calendar-plus"></i> Create Session
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Document Viewer Modal -->
  <div class="modal" id="documentModal">
    <div class="modal-content document-modal">
        <div class="modal-header">
            <h2 class="modal-title">Training Request Documents</h2>
            <button class="close-modal" onclick="closeDocumentModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div id="documentDetails" class="document-details">
            <!-- Document details will be populated by JavaScript -->
        </div>
        
        <!-- Document Verification Form -->
        <form method="POST" style="margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1.5rem;">
            <input type="hidden" name="update_document_verification" value="1">
            <input type="hidden" name="request_id" id="docRequestId">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            
            <h3 style="margin-bottom: 1rem; color: var(--dark);">
                <i class="fas fa-shield-check"></i> Document Verification
            </h3>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="verification_status">Verification Status</label>
                    <select id="verification_status" name="verification_status" class="status-select" style="width: 100%;">
                        <option value="pending">Pending Review</option>
                        <option value="verified">Verified ✓</option>
                        <option value="rejected">Rejected ✗</option>
                    </select>
                </div>
            </div>
        
            
            <div class="modal-footer">
                <button type="button" class="btn-action btn-edit" onclick="closeDocumentModal()">Cancel</button>
                <button type="submit" class="btn-submit">
                    <i class="fas fa-shield-check"></i> Update Verification
                </button>
            </div>
        </form>
    </div>
  </div>

<script>
// JavaScript variables from PHP
const userRole = '<?= $user_role ?>';
const hasRestrictedAccess = <?= $hasRestrictedAccess ? 'true' : 'false' ?>;
const allowedServices = <?= json_encode($allowedServices) ?>;
const currentUserId = <?= $current_user_id ?>;

let currentRequest = null;

// Status filtering function
function filterStatus(status) {
    const urlParams = new URLSearchParams(window.location.search);
    
    const currentService = urlParams.get('service');
    const currentSearch = urlParams.get('search');
    
    if (status === 'all') {
        urlParams.delete('status');
    } else {
        urlParams.set('status', status);
    }
    
    if (currentService) {
        urlParams.set('service', currentService);
    }
    
    if (currentSearch) {
        urlParams.set('search', currentSearch);
    }
    
    urlParams.delete('page');
    
    window.location.search = urlParams.toString();
}

// Enhanced openRequestModal function with document info
function openRequestModal(request) {
    currentRequest = request;
    document.getElementById('updateRequestId').value = request.request_id;
    document.getElementById('new_status').value = request.status;

    
    // Check if documents are uploaded - updated to handle your table structure
    const hasDocuments = request.valid_id_path || 
                         request.valid_id_request_path || 
                         request.participant_list_path || 
                         request.additional_docs_paths || 
                         request.additional_docs_path;
    
    // Populate request details
    const requestDetails = document.getElementById('requestDetails');
    const preferredDate = request.preferred_start_date ? 
        new Date(request.preferred_start_date).toLocaleDateString('en-US', {
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
                <div class="info-label">Preferred Dates</div>
                <div class="info-value">
                    ${request.preferred_start_date ? 
                        (() => {
                            const startDate = new Date(request.preferred_start_date).toLocaleDateString('en-US', {
                                year: 'numeric', month: 'long', day: 'numeric'
                            });
                            if (request.preferred_end_date && request.preferred_end_date !== request.preferred_start_date) {
                                const endDate = new Date(request.preferred_end_date).toLocaleDateString('en-US', {
                                    year: 'numeric', month: 'long', day: 'numeric'
                                });
                                return `${startDate} to ${endDate} (${request.duration_days || 1} days)`;
                            }
                            return startDate;
                        })() : 'Flexible'
                    }
                </div>
            </div>
            <div class="info-item">
                <div class="info-label">Preferred Time</div>
                <div class="info-value">${request.preferred_time ? request.preferred_time.charAt(0).toUpperCase() + request.preferred_time.slice(1) : 'Not specified'}</div>
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
            <div class="info-item documents-info">
                <div class="info-label">Documents Status</div>
                <div class="info-value">
                    ${hasDocuments ? 
                        `<span class="doc-status ${request.documents_verified || 'pending'}">
                            <i class="fas ${request.documents_verified === 'verified' ? 'fa-check-circle' : 
                                         request.documents_verified === 'rejected' ? 'fa-times-circle' : 'fa-clock'}"></i>
                            ${(request.documents_verified || 'pending').charAt(0).toUpperCase() + (request.documents_verified || 'pending').slice(1)}
                        </span>
                        <button onclick="openDocumentModal(currentRequest)" class="btn-view-docs">
                            <i class="fas fa-folder-open"></i> View Documents
                        </button>` : 
                        '<span class="no-docs"><i class="fas fa-file-slash"></i> No documents uploaded</span>'
                    }
                </div>
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
        
        ${request.documents_uploaded_at ? `
            <div class="info-item" style="margin-top: 1rem;">
                <div class="info-label">Documents Uploaded</div>
                <div class="info-value">${new Date(request.documents_uploaded_at).toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'long', 
                    day: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit'
                })}</div>
            </div>
        ` : ''}
        
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

// Open Create Session Modal
function openCreateSessionModal(request) {
    currentRequest = request;
    document.getElementById('createRequestId').value = request.request_id;
    
    // Auto-fill form with request data
    const programName = request.program_name || request.training_program;
    document.getElementById('session_title').value = `${programName} Training`;
    
    // Set default dates (preferred date or 2 weeks from now)
    const defaultDate = request.preferred_start_date || 
        new Date(Date.now() + 14 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
    const defaultEndDate = request.preferred_end_date || defaultDate;
    document.getElementById('session_date').value = defaultDate;
    document.getElementById('session_end_date').value = defaultEndDate;
    
    // Set capacity based on participant count
    document.getElementById('capacity').value = Math.max(request.participant_count, 10);
    
// FIXED: Set times using JavaScript only
if (request.preferred_start_time) {
    document.getElementById('start_time').value = request.preferred_start_time;
} else {
    document.getElementById('start_time').value = '09:00';
}

if (request.preferred_end_time) {
    document.getElementById('end_time').value = request.preferred_end_time;
} else {
    document.getElementById('end_time').value = '17:00';
}
    
    // Set default venue with proper formatting
    let venueText = "Philippine Red Cross Training Center\n";
    if (request.location_preference) {
        venueText += `${request.location_preference}\n\n`;
    } else {
        venueText += "To be confirmed - detailed location will be provided\n\n";
    }
    venueText += `Contact: ${request.contact_person}\nPhone: ${request.contact_number}\nEmail: ${request.email}`;
    
    document.getElementById('venue').value = venueText;
    
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

// FIXED: Enhanced openDocumentModal function
function openDocumentModal(request) {
    console.log('Opening document modal for request:', request.request_id);
    currentRequest = request;
    
    // Get the modal element first
    const modal = document.getElementById('documentModal');
    if (!modal) {
        console.error('Document modal element not found');
        return;
    }
    
    // Debug the request object
    console.log('Opening document modal for request:', request.request_id);
    console.log('Document fields in request:', {
        valid_id_path: request.valid_id_path,
        valid_id_filename: request.valid_id_filename,
        valid_id_request_path: request.valid_id_request_path,
        participant_list_path: request.participant_list_path,
        participant_list_filename: request.participant_list_filename,
        additional_docs_paths: request.additional_docs_paths,
        additional_docs_filenames: request.additional_docs_filenames,
        additional_docs_path: request.additional_docs_path,
        documents_verified: request.documents_verified,
        documents_uploaded_at: request.documents_uploaded_at
    });
    
    // Populate document details
    const documentDetails = document.getElementById('documentDetails');
    let documentsHtml = '';
    let docCount = 0;
    let hasAnyDocuments = false;
    
    // Check for Valid ID Document (prioritize valid_id_request_path, then valid_id_path)
    const validIdPath = request.valid_id_request_path || request.valid_id_path;
    const validIdFilename = request.valid_id_filename || extractFilename(validIdPath);
    
    if (validIdPath && validIdPath.trim() && validIdPath !== 'null') {
        hasAnyDocuments = true;
        const fileIcon = getFileIconClass(validIdFilename);
        docCount++;
        
        documentsHtml += `
            <div class="document-item">
                <div class="document-header">
                    <i class="fas ${fileIcon}"></i>
                    <span class="document-title">Valid ID Document</span>
                    <span class="document-status ${request.documents_verified || 'pending'}">${capitalizeFirst(request.documents_verified || 'pending')}</span>
                </div>
                <div class="document-info">
                    <span class="document-filename" title="${escapeHtml(validIdFilename)}">${escapeHtml(validIdFilename)}</span>
                    <div class="document-actions">
                        <button onclick="viewDocument('${validIdPath}')" class="btn-view-doc">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <button onclick="downloadDocument('${validIdPath}', '${escapeHtml(validIdFilename)}')" class="btn-download-doc">
                            <i class="fas fa-download"></i> Download
                        </button>
                    </div>
                </div>
            </div>
        `;
    }
    
    // Check for Participant List Document
   if (request.participant_list_path && request.participant_list_path.trim() && request.participant_list_path !== 'null') {
        hasAnyDocuments = true;
        const fileName = request.participant_list_filename || extractFilename(request.participant_list_path);
        const fileIcon = getFileIconClass(fileName);
        docCount++;
        
        documentsHtml += `
            <div class="document-item">
                <div class="document-header">
                    <i class="fas ${fileIcon}"></i>
                    <span class="document-title">Participant List</span>
                </div>
                <div class="document-info">
                    <span class="document-filename" title="${escapeHtml(fileName)}">${escapeHtml(fileName)}</span>
                    <div class="document-actions">
                        <button onclick="viewDocument('${request.participant_list_path}')" class="btn-view-doc">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <button onclick="downloadDocument('${request.participant_list_path}', '${escapeHtml(fileName)}')" class="btn-download-doc">
                            <i class="fas fa-download"></i> Download
                        </button>
                    </div>
                </div>
            </div>
        `;
    }
    
    // Check for Additional Documents - handle both additional_docs_paths and additional_docs_path
   const additionalDocsFields = [
        { 
            paths: request.additional_docs_paths, 
            filenames: request.additional_docs_filenames,
            label: 'Additional Document'
        },
        { 
            paths: request.additional_docs_path, 
            filenames: null,
            label: 'Supporting Document'
        }
    ];
    
   let additionalDocIndex = 1;
    
    additionalDocsFields.forEach(({ paths, filenames, label }) => {
        if (paths && paths.trim() && paths !== 'null' && paths !== '[]') {
            hasAnyDocuments = true;
            
            try {
                let docPaths = [];
                let docFilenames = [];
                
                // Handle JSON array format
                if (paths.startsWith('[') && paths.endsWith(']')) {
                    docPaths = JSON.parse(paths);
                } else {
                    // Handle single path
                    docPaths = [paths];
                }
                
                // Ensure docPaths is an array
                if (!Array.isArray(docPaths)) {
                    docPaths = [docPaths];
                }
                
                // Parse filenames if available
                if (filenames && filenames.trim() && filenames !== 'null') {
                    try {
                        if (filenames.startsWith('[') && filenames.endsWith(']')) {
                            docFilenames = JSON.parse(filenames);
                        } else {
                            docFilenames = [filenames];
                        }
                    } catch (e) {
                        console.warn('Error parsing additional docs filenames:', e);
                        docFilenames = docPaths.map(path => extractFilename(path));
                    }
                } else {
                    docFilenames = docPaths.map(path => extractFilename(path));
                }
                
                // Ensure docFilenames is an array
                if (!Array.isArray(docFilenames)) {
                    docFilenames = [docFilenames];
                }
                
                // Process each document path
                docPaths.forEach((path, index) => {
                    if (path && path.trim() && path !== 'null') {
                        const fileName = docFilenames[index] || extractFilename(path);
                        const fileIcon = getFileIconClass(fileName);
                        docCount++;
                        
                        documentsHtml += `
                            <div class="document-item">
                                <div class="document-header">
                                    <i class="fas ${fileIcon}"></i>
                                    <span class="document-title">${label} ${additionalDocIndex}</span>
                                </div>
                                <div class="document-info">
                                    <span class="document-filename" title="${escapeHtml(fileName)}">${escapeHtml(fileName)}</span>
                                    <div class="document-actions">
                                        <button onclick="viewDocument('${path}')" class="btn-view-doc">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <button onclick="downloadDocument('${path}', '${escapeHtml(fileName)}')" class="btn-download-doc">
                                            <i class="fas fa-download"></i> Download
                                        </button>
                                    </div>
                                </div>
                            </div>
                        `;
                        additionalDocIndex++;
                    }
                });
                
            } catch (e) {
                console.error('Error parsing additional documents:', e, 'Raw data:', paths);
                hasAnyDocuments = true;
                
                documentsHtml += `
                    <div class="document-item error">
                        <div class="document-header">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span class="document-title">Document Parse Error</span>
                        </div>
                        <div class="document-info">
                            <span class="document-filename">Unable to parse document list</span>
                            <small style="color: var(--red); display: block; margin-top: 0.5rem;">
                                Raw data: ${escapeHtml(String(paths).substring(0, 100))}...
                            </small>
                        </div>
                    </div>
                `;
            }
        }
    });
    
    
    // If no documents found, show appropriate message
    if (!hasAnyDocuments) {
        documentsHtml = `
            <div class="no-documents">
                <i class="fas fa-file-slash"></i>
                <h3>No documents uploaded</h3>
                <p>This training request was submitted without document uploads.</p>
            </div>
        `;
    } else {
        // Add document summary header if there are documents
        const documentSummary = `
            <div class="document-summary">
                <div class="summary-header">
                    <i class="fas fa-file-alt"></i>
                    <span>Document Summary</span>
                </div>
                <div class="summary-stats">
                    <div class="stat-item">
                        <span class="stat-number">${docCount}</span>
                        <span class="stat-label">Total Files</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-status ${request.documents_verified || 'pending'}">${capitalizeFirst(request.documents_verified || 'pending')}</span>
                        <span class="stat-label">Verification Status</span>
                    </div>
                    ${request.documents_uploaded_at ? `
                        <div class="stat-item">
                            <span class="stat-date">${formatDate(request.documents_uploaded_at)}</span>
                            <span class="stat-label">Upload Date</span>
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
        
        documentsHtml = documentSummary + documentsHtml;
    }
    
    // Populate the modal with document details
    documentDetails.innerHTML = documentsHtml;
    
    // Set verification form values
    const docRequestIdField = document.getElementById('docRequestId');
    const verificationStatusField = document.getElementById('verification_status');
    const verificationNotesField = document.getElementById('verification_notes');
    
    if (docRequestIdField) docRequestIdField.value = request.request_id;
    if (verificationStatusField) verificationStatusField.value = request.documents_verified || 'pending';
    if (verificationNotesField) verificationNotesField.value = request.document_verification_notes || '';
    
    // Show the modal with proper sequence
    setTimeout(() => {
        modal.style.display = 'flex';
        // Force reflow
        modal.offsetHeight;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        console.log('Document modal opened successfully with', docCount, 'documents');
    }, 10);
}

// Helper function to capitalize first letter
function capitalizeFirst(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1);
}

// Helper function to format dates
function formatDate(dateString) {
    if (!dateString) return 'Unknown';
    
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    } catch (e) {
        return 'Invalid date';
    }
}

// FIXED: Enhanced closeDocumentModal function
function closeDocumentModal() {
    const modal = document.getElementById('documentModal');
    if (modal) {
        modal.classList.remove('active');
        
        // Wait for transition to complete before hiding
        setTimeout(() => {
            modal.style.display = 'none';
            document.body.style.overflow = '';
            
            // Reset verification form
            const verificationStatusField = document.getElementById('verification_status');
            const verificationNotesField = document.getElementById('verification_notes');
            
            if (verificationStatusField) verificationStatusField.value = 'pending';
            if (verificationNotesField) verificationNotesField.value = '';
            
            console.log('Document modal closed');
        }, 300);
    }
    currentRequest = null;
}

// Enhanced document viewing with your table structure
function viewDocument(filePath) {
    if (!filePath || filePath === 'null' || filePath.trim() === '') {
        alert('Invalid file path');
        return;
    }
    
    // Simple URL construction - let the server handle the rest
    const viewUrl = window.location.pathname + '?path=' + encodeURIComponent(filePath);
    
    // Open in new tab - simple and reliable
    window.open(viewUrl, '_blank');
}

// Enhanced document download with your table structure
function downloadDocument(filePath, filename) {
    if (!filePath || filePath === 'null') {
        alert('Invalid file path');
        return;
    }
    
    console.log('Attempting to download document:', filePath, 'as', filename);
    
    const downloadFilename = filename || extractFilename(filePath);
    const downloadUrl = `${window.location.pathname}?download=true&path=${encodeURIComponent(filePath)}&filename=${encodeURIComponent(downloadFilename)}`;
    
    // Create and trigger download
    const link = document.createElement('a');
    link.href = downloadUrl;
    link.download = downloadFilename;
    link.style.display = 'none';
    
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Updated extractFilename function
function downloadDocument(filePath, filename) {
    if (!filePath || filePath === 'null' || filePath.trim() === '') {
        alert('Invalid file path');
        return;
    }
    
    const downloadFilename = filename || extractFilename(filePath);
    const downloadUrl = window.location.pathname + '?download=true&path=' + encodeURIComponent(filePath) + '&filename=' + encodeURIComponent(downloadFilename);
    
    // Create temporary link and click it
    const link = document.createElement('a');
    link.href = downloadUrl;
    link.download = downloadFilename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Keep the existing helper functions but simplify them
function extractFilename(path) {
    if (!path || path === 'null') return 'Unknown File';
    return path.split('/').pop().split('?')[0] || 'Unknown File';
}

function getFileIconClass(filename) {
    if (!filename || filename === 'null') return 'fa-file';
    
    const extension = filename.split('.').pop().toLowerCase();
    const icons = {
        'pdf': 'fa-file-pdf',
        'jpg': 'fa-file-image', 'jpeg': 'fa-file-image', 'png': 'fa-file-image', 'gif': 'fa-file-image',
        'doc': 'fa-file-word', 'docx': 'fa-file-word',
        'xls': 'fa-file-excel', 'xlsx': 'fa-file-excel',
        'csv': 'fa-file-csv',
        'txt': 'fa-file-alt'
    };
    
    return icons[extension] || 'fa-file';
}

// Enhanced error handling for document operations
function handleDocumentError(error, operation = 'access') {
    console.error(`Document ${operation} error:`, error);
    
    let errorMessage = `Unable to ${operation} document. `;
    
    if (error.status === 403) {
        errorMessage += 'Access denied - you may not have permission to view this file.';
    } else if (error.status === 404) {
        errorMessage += 'File not found - it may have been moved or deleted.';
    } else if (error.status === 500) {
        errorMessage += 'Server error - please try again later.';
    } else {
        errorMessage += 'Please check your internet connection and try again.';
    }
    
    alert(errorMessage);
}

// Helper function for HTML escaping
function escapeHtml(text) {
    if (!text || text === 'null') return '';
    
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

// Form validation and submission
document.addEventListener('DOMContentLoaded', function() {
    console.log('Initializing training requests page...');
    
    // Create session form validation
    const createSessionForm = document.getElementById('createSessionForm');
    if (createSessionForm) {
        createSessionForm.addEventListener('submit', function(e) {
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
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Session...';
                submitBtn.disabled = true;
            }
        });
    }

    // Update end date minimum when start date changes
    const sessionDateInput = document.getElementById('session_date');
    const sessionEndDateInput = document.getElementById('session_end_date');
    
    if (sessionDateInput && sessionEndDateInput) {
        sessionDateInput.addEventListener('change', function() {
            sessionEndDateInput.min = this.value;
            if (sessionEndDateInput.value && sessionEndDateInput.value < this.value) {
                sessionEndDateInput.value = this.value;
            }
        });
    }

    // Character counter for venue field
    const venueField = document.getElementById('venue');
    const charCounter = document.getElementById('venue-char-count');
    
    if (venueField && charCounter) {
        function updateVenueCharCount() {
            const currentLength = venueField.value.length;
            charCounter.textContent = currentLength;
            
            const counterElement = charCounter.parentElement;
            counterElement.classList.remove('warning', 'danger');
            
            if (currentLength > 1800) {
                counterElement.classList.add('danger');
            } else if (currentLength > 1500) {
                counterElement.classList.add('warning');
            }
        }
        
        venueField.addEventListener('input', updateVenueCharCount);
        updateVenueCharCount();
    }

    // Enhanced modal event handling
    const requestModal = document.getElementById('requestModal');
    const createSessionModal = document.getElementById('createSessionModal');
    const documentModal = document.getElementById('documentModal');

    // Request modal events
    if (requestModal) {
        requestModal.addEventListener('click', function(e) {
            if (e.target === this) closeRequestModal();
        });
        
        const requestCloseBtn = requestModal.querySelector('.close-modal');
        if (requestCloseBtn) {
            requestCloseBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                closeRequestModal();
            });
        }
    }

    // Create session modal events
    if (createSessionModal) {
        createSessionModal.addEventListener('click', function(e) {
            if (e.target === this) closeCreateSessionModal();
        });
        
        const sessionCloseBtn = createSessionModal.querySelector('.close-modal');
        if (sessionCloseBtn) {
            sessionCloseBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                closeCreateSessionModal();
            });
        }
    }

    // Document modal events - ENHANCED
    if (documentModal) {
        // Close modal when clicking the backdrop
        documentModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeDocumentModal();
            }
        });
        
        // Prevent modal content clicks from closing modal
        const modalContent = documentModal.querySelector('.modal-content');
        if (modalContent) {
            modalContent.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        }
        
        // Close button event
        const closeButton = documentModal.querySelector('.close-modal');
        if (closeButton) {
            closeButton.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                closeDocumentModal();
            });
        }
    }

    // Enhanced keyboard navigation
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (requestModal && requestModal.classList.contains('active')) {
                closeRequestModal();
            }
            if (createSessionModal && createSessionModal.classList.contains('active')) {
                closeCreateSessionModal();
            }
            if (documentModal && documentModal.classList.contains('active')) {
                closeDocumentModal();
            }
        }
    });

    // Auto-dismiss alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            if (alert.parentNode) {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-20px)';
                setTimeout(() => {
                    if (alert.parentNode) {
                        alert.remove();
                    }
                }, 300);
            }
        }, 5000);
    });

    // Enhanced table interactions with auto-creation feedback
    const tableRows = document.querySelectorAll('.data-table tbody tr');
    tableRows.forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.backgroundColor = '#f8f9fa';
        });
        
        row.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '';
        });
    });

    // Handle status change with confirmation for approval
    const statusSelects = document.querySelectorAll('.status-select');
    statusSelects.forEach(select => {
        select.addEventListener('change', function(e) {
            const newStatus = this.value;
            
            if (newStatus === 'approved') {
                const confirmed = confirm(
                    'Approving this request will automatically create a training session.\n\n' +
                    'The session will be:\n' +
                    '• Created with smart defaults based on the request\n' +
                    '• Available for user registration immediately\n' +
                    '• Editable in the Training Sessions page\n\n' +
                    'Continue with approval?'
                );
                
                if (!confirmed) {
                    // Reset to previous value
                    this.value = this.dataset.previousValue || 'pending';
                    e.preventDefault();
                    return;
                }
            }
            
            // Store current value for reset if needed
            this.dataset.previousValue = this.value;
        });
        
        // Store initial value
        select.dataset.previousValue = select.value;
    });

    // Form submission handler for document verification
    const verificationForm = documentModal ? documentModal.querySelector('form') : null;
    if (verificationForm) {
        verificationForm.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('.btn-submit');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating Verification...';
                submitBtn.disabled = true;
            }
        });
    }

    // Initialize tooltips for status badges
    const statusBadges = document.querySelectorAll('.status-badge');
    statusBadges.forEach(badge => {
        badge.addEventListener('mouseenter', function() {
            const status = this.textContent.trim().toLowerCase();
            let tooltip = '';
            
            switch(status) {
                case 'pending':
                    tooltip = 'Request submitted and awaiting admin review';
                    break;
                case 'under review':
                    tooltip = 'Admin is currently reviewing this request';
                    break;
                case 'approved':
                    tooltip = 'Request approved, session will be created';
                    break;
                case 'scheduled':
                    tooltip = 'Training session has been scheduled';
                    break;
                case 'completed':
                    tooltip = 'Training session completed successfully';
                    break;
                case 'rejected':
                    tooltip = 'Request rejected - see admin notes';
                    break;
            }
            
            if (tooltip) {
                this.title = tooltip;
            }
        });
    });

    // Add loading states for document actions
    document.addEventListener('click', function(e) {
        if (e.target.matches('.btn-view-doc, .btn-download-doc')) {
            const btn = e.target;
            const originalText = btn.innerHTML;
            
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
            btn.disabled = true;
            
            // Reset button after 3 seconds (in case of slow response)
            setTimeout(() => {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }, 3000);
        }
    });

    // Search form auto-submit
    const searchInput = document.querySelector('.search-box input[name="search"]');
    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                if (this.value.length >= 3 || this.value.length === 0) {
                    this.form.submit();
                }
            }, 500);
        });
    }

    // Table sorting (if needed in future)
    const tableHeaders = document.querySelectorAll('.data-table th');
    tableHeaders.forEach(header => {
        header.style.cursor = 'pointer';
        header.addEventListener('click', function() {
            // Future implementation for table sorting
            console.log('Header clicked:', this.textContent);
        });
    });

    console.log('Training requests page initialized successfully');
    console.log('Document modal handlers ready');
});
// Add this helper function
function formatTime(timeString) {
    if (!timeString) return '';
    try {
        const time = new Date('1970-01-01T' + timeString + 'Z');
        return time.toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });
    } catch (e) {
        return timeString;
    }
}
</script>

<!-- Enhanced CSS for admin training requests -->
<style>

</style>
<script src="../admin/js/notification_frontend.js?v=<?php echo time(); ?>"></script>
  <script src="../admin/js/sidebar-notifications.js?v=<?php echo time(); ?>"></script>
<script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
  <?php include 'chat_widget.php'; ?>
    <?php include 'floating_notification_widget.php'; ?>
</body>
</html>