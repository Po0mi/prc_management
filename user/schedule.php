<?php
// /user/schedule.php - Updated with Multi-Day Training Sessions

require_once __DIR__ . '/../config.php';
ensure_logged_in();
$user_role = get_user_role();
if ($user_role) {
    // If user has an admin role, redirect to admin dashboard
    header("Location: /admin/dashboard.php");
    exit;
}
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// Add archive filter logic
if (isset($_GET['show_archived']) && $_GET['show_archived'] === '1') {
    // Show archived sessions
    $whereConditions[] = "ts.archived = 1";
} else {
    // Default: show only active sessions
    $whereConditions[] = "ts.archived = 0";
}
$userEmail = $_SESSION['email'] ?? '';
$username = current_username();
$userId = current_user_id();
$pdo = $GLOBALS['pdo'];
$regMessage = '';

// Handle training registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_training'])) {
    $sessionId = (int)$_POST['session_id'];
    $registrationType = trim($_POST['registration_type']);
    
    // Get session details to check if it's a paid session
    $sessionQuery = $pdo->prepare("SELECT fee FROM training_sessions WHERE session_id = ?");
    $sessionQuery->execute([$sessionId]);
    $sessionData = $sessionQuery->fetch();
    $sessionFee = $sessionData ? floatval($sessionData['fee']) : 0;
    
    // Handle payment mode with default for free sessions
    $paymentMethod = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : 'free';
    
    // For free sessions, set payment method to 'free' if not provided
    if ($sessionFee <= 0) {
        $paymentMethod = 'free';
    }
    
    // Check if session exists and is still available
    $sessionCheck = $pdo->prepare("
        SELECT ts.*, 
               COALESCE(COUNT(sr.registration_id), 0) as current_registrations
        FROM training_sessions ts 
        LEFT JOIN session_registrations sr ON ts.session_id = sr.session_id
        WHERE ts.session_id = ? AND ts.session_end_date >= CURDATE()
        GROUP BY ts.session_id
    ");
    $sessionCheck->execute([$sessionId]);
    $session = $sessionCheck->fetch();
    
    if (!$session) {
        $regMessage = "This training session is no longer available.";
    } else {
        // Check if session is full
        $isFull = $session['capacity'] > 0 && $session['current_registrations'] >= $session['capacity'];
        
        if ($isFull) {
            $regMessage = "This training session is already full.";
        } else {
            // Check if already registered
            $check = $pdo->prepare("SELECT * FROM session_registrations WHERE session_id = ? AND user_id = ?");
            $check->execute([$sessionId, $userId]);
            
            if ($check->rowCount() === 0) {
                // Create user folder if it doesn't exist
                $userFolder = __DIR__ . "/../uploads/training_user_" . $userId;
                if (!file_exists($userFolder)) {
                    mkdir($userFolder, 0755, true);
                }

                $validIdPath = '';
                $documentsPath = '';
                $paymentReceiptPath = '';

                // Handle valid ID upload
                if (isset($_FILES['valid_id']) && $_FILES['valid_id']['error'] === UPLOAD_ERR_OK) {
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
                    $fileType = $_FILES['valid_id']['type'];
                    
                    if (in_array($fileType, $allowedTypes)) {
                        $fileExtension = pathinfo($_FILES['valid_id']['name'], PATHINFO_EXTENSION);
                        $fileName = 'valid_id_' . time() . '.' . $fileExtension;
                        $validIdPath = $userFolder . '/' . $fileName;
                        
                        if (move_uploaded_file($_FILES['valid_id']['tmp_name'], $validIdPath)) {
                            $validIdPath = 'uploads/training_user_' . $userId . '/' . $fileName;
                        } else {
                            $validIdPath = '';
                        }
                    } else {
                        $regMessage = "Invalid file type for Valid ID. Please upload JPG, PNG, or PDF files only.";
                    }
                }

                // Handle payment receipt upload
                if (isset($_FILES['payment_receipt']) && $_FILES['payment_receipt']['error'] === UPLOAD_ERR_OK) {
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
                    $fileType = $_FILES['payment_receipt']['type'];
                    
                    if (in_array($fileType, $allowedTypes)) {
                        $fileExtension = pathinfo($_FILES['payment_receipt']['name'], PATHINFO_EXTENSION);
                        $fileName = 'payment_receipt_' . time() . '.' . $fileExtension;
                        $paymentReceiptPath = $userFolder . '/' . $fileName;
                        
                        if (move_uploaded_file($_FILES['payment_receipt']['tmp_name'], $paymentReceiptPath)) {
                            $paymentReceiptPath = 'uploads/training_user_' . $userId . '/' . $fileName;
                        } else {
                            $paymentReceiptPath = '';
                        }
                    } else {
                        $regMessage = "Invalid file type for payment receipt.";
                    }
                }

                // Handle requirements upload
                if (isset($_FILES['requirements']) && $_FILES['requirements']['error'] === UPLOAD_ERR_OK) {
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                    $fileType = $_FILES['requirements']['type'];
                    
                    if (in_array($fileType, $allowedTypes)) {
                        $fileExtension = pathinfo($_FILES['requirements']['name'], PATHINFO_EXTENSION);
                        $fileName = 'requirements_' . time() . '.' . $fileExtension;
                        $documentsPath = $userFolder . '/' . $fileName;
                        
                        if (move_uploaded_file($_FILES['requirements']['tmp_name'], $documentsPath)) {
                            $documentsPath = 'uploads/training_user_' . $userId . '/' . $fileName;
                        } else {
                            $documentsPath = '';
                        }
                    } else {
                        $regMessage = "Invalid file type for requirements.";
                    }
                }

                // Only proceed if no file upload errors
                if (empty($regMessage)) {
                    // Validate required valid ID upload
                    if (empty($validIdPath)) {
                        $regMessage = "Valid ID upload is required.";
                    } else {
                        try {
                            // Get payment information
                            $paymentAmount = floatval($_POST['payment_amount'] ?? 0);
                            $paymentReference = trim($_POST['payment_reference'] ?? '');
                            $paymentAccountNumber = trim($_POST['payment_account_number'] ?? '');
                            $paymentAccountName = trim($_POST['payment_account_name'] ?? '');

                            // Insert registration based on type
                            if ($registrationType === 'individual') {
                                $fullName = trim($_POST['full_name']);
                                $location = trim($_POST['location']);
                                $age = (int)$_POST['age'];
                                $rcyStatus = trim($_POST['rcy_status']);
                                $trainingType = trim($_POST['training_type']);
                                $trainingDate = $_POST['training_date'];

                                // Validate required fields
                                if (empty($fullName) || empty($location) || empty($age) || empty($rcyStatus)) {
                                    $regMessage = "Please fill in all required fields.";
                                } else {
                                    $stmt = $pdo->prepare("
                                        INSERT INTO session_registrations (
                                            session_id, user_id, email, registration_type, full_name, location, age, 
                                            rcy_status, training_type, training_date, valid_id_path, requirements_path, 
                                            payment_method, payment_amount, payment_reference, payment_account_number, 
                                            payment_account_name, payment_receipt_path, registration_date, status, payment_status
                                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending', ?)
                                    ");
                                    $paymentStatus = ($paymentAmount > 0) ? 'pending' : 'not_required';
                                    $stmt->execute([
                                        $sessionId, $userId, $userEmail, $registrationType, $fullName, $location, $age,
                                        $rcyStatus, $trainingType, $trainingDate, $validIdPath, $documentsPath,
                                        $paymentMethod, $paymentAmount, $paymentReference, $paymentAccountNumber,
                                        $paymentAccountName, $paymentReceiptPath, $paymentStatus
                                    ]);
                                    
                                    if ($sessionFee <= 0) {
                                        $regMessage = "You have successfully registered for this free training session. Your documents have been uploaded. Awaiting confirmation.";
                                    } else {
                                        $regMessage = "You have successfully registered for the training session. Your documents and payment information have been uploaded. Awaiting confirmation.";
                                    }
                                }
                            } else { // organization
                                $organizationName = trim($_POST['organization_name']);
                                $trainingType = trim($_POST['training_type']);
                                $trainingDate = $_POST['training_date'];
                                $paxCount = (int)$_POST['pax_count'];

                                // Validate required fields
                                if (empty($organizationName) || empty($paxCount) || $paxCount < 1) {
                                    $regMessage = "Please fill in all required fields.";
                                } else {
                                    $stmt = $pdo->prepare("
                                        INSERT INTO session_registrations (
                                            session_id, user_id, email, registration_type, organization_name, 
                                            training_type, training_date, pax_count, valid_id_path, requirements_path, 
                                            payment_method, payment_amount, payment_reference, payment_account_number, 
                                            payment_account_name, payment_receipt_path, registration_date, status, payment_status
                                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending', ?)
                                    ");
                                    $paymentStatus = ($paymentAmount > 0) ? 'pending' : 'not_required';
                                    $stmt->execute([
                                        $sessionId, $userId, $userEmail, $registrationType, $organizationName,
                                        $trainingType, $trainingDate, $paxCount, $validIdPath, $documentsPath,
                                        $paymentMethod, $paymentAmount, $paymentReference, $paymentAccountNumber,
                                        $paymentAccountName, $paymentReceiptPath, $paymentStatus
                                    ]);
                                    
                                    if ($sessionFee <= 0) {
                                        $regMessage = "You have successfully registered for this free training session. Your documents have been uploaded. Awaiting confirmation.";
                                    } else {
                                        $regMessage = "You have successfully registered for the training session. Your documents and payment information have been uploaded. Awaiting confirmation.";
                                    }
                                }
                            }
                        } catch (PDOException $e) {
                            error_log("Registration error: " . $e->getMessage());
                            $regMessage = "An error occurred during registration. Please try again.";
                        }
                    }
                }
            } else {
                $regMessage = "You are already registered for this training session.";
            }
        }
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$serviceFilter = isset($_GET['service']) ? trim($_GET['service']) : '';
$showArchived = isset($_GET['show_archived']) && $_GET['show_archived'] === '1';

// Define the major services in the same order as admin panel
$majorServices = [
    'Health Service',
    'Safety Service',
    'Welfare Service',
    'Disaster Management Service',
    'Red Cross Youth'
];

// Define color mappings for training types
$trainingColors = [
    'Health Service' => 'health',
    'Safety Service' => 'safety',
    'Welfare Service' => 'welfare',
    'Disaster Management Service' => 'disaster',
    'Red Cross Youth' => 'rcy',
    'General' => 'general'
];

// Build query with filters - UPDATED FOR MULTI-DAY SESSIONS
$whereConditions = [];
$params = [];

// FIXED: Only show upcoming sessions - check session_end_date instead of session_date
$whereConditions[] = "ts.session_end_date >= CURDATE()";

if ($showArchived) {
    $whereConditions[] = "ts.archived = 1";  // Show only archived
} else {
    $whereConditions[] = "ts.archived = 0";  // Show only non-archived (default)
    // FIXED: Only show upcoming sessions when not viewing archived
    $whereConditions[] = "ts.session_end_date >= CURDATE()";
}

if ($search) {
    $whereConditions[] = "(ts.title LIKE :search OR ts.venue LIKE :search OR ts.major_service LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($serviceFilter && $serviceFilter !== 'all') {
    $whereConditions[] = "ts.major_service = :service";
    $params[':service'] = $serviceFilter;
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

try {
    // Main query to get all training sessions - UPDATED WITH MULTI-DAY FIELDS AND ARCHIVE SUPPORT
    $stmt = $pdo->prepare("
    SELECT ts.*,
           COALESCE(ts.session_end_date, ts.session_date) as session_end_date,
           COALESCE(ts.duration_days, 1) as duration_days,
           COALESCE((
               SELECT 1 FROM session_registrations 
               WHERE session_id = ts.session_id 
               AND user_id = ?
           ), 0) AS is_registered,
           COALESCE((
               SELECT COUNT(*) FROM session_registrations 
               WHERE session_id = ts.session_id
           ), 0) AS registered_count
    FROM training_sessions ts
    $whereClause
    ORDER BY ts.major_service, ts.session_date, ts.start_time
");

    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->execute();
    $allSessions = $stmt->fetchAll();

    // Group sessions by service
    $sessionsByService = [];
    foreach ($allSessions as $session) {
        $service = $session['major_service'];
        if (!isset($sessionsByService[$service])) {
            $sessionsByService[$service] = [];
        }
        $sessionsByService[$service][] = $session;
    }

    // Get statistics - UPDATED TO HANDLE ARCHIVE STATE
    if ($showArchived) {
        // When viewing archived sessions
        $upcoming = $pdo->query("SELECT COUNT(*) FROM training_sessions WHERE archived = 1")->fetchColumn();
        $past = 0; // Not relevant for archived view
        $total_sessions = $upcoming;
    } else {
        // When viewing active sessions - exclude archived
        $upcoming = $pdo->query("SELECT COUNT(*) FROM training_sessions WHERE session_end_date >= CURDATE() AND archived = 0")->fetchColumn();
        $past = $pdo->query("SELECT COUNT(*) FROM training_sessions WHERE session_end_date < CURDATE() AND archived = 0")->fetchColumn();
        $total_sessions = $upcoming + $past;
    }

    // Get user's registration count - only from non-archived sessions
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM session_registrations sr 
        JOIN training_sessions ts ON sr.session_id = ts.session_id 
        WHERE sr.user_id = ? AND ts.archived = 0
    ");
    $stmt->execute([$userId]);
    $registered = $stmt->fetchColumn();

    // Get user's registrations with session details - UPDATED WITH MULTI-DAY FIELDS
    $userRegistrations = $pdo->prepare("
        SELECT sr.*, ts.title, ts.session_date, ts.session_end_date, ts.duration_days,
               ts.start_time, ts.end_time, ts.venue, ts.major_service, ts.archived
        FROM session_registrations sr
        JOIN training_sessions ts ON sr.session_id = ts.session_id
        WHERE sr.user_id = ?
        ORDER BY ts.session_date DESC
    ");
    $userRegistrations->execute([$userId]);
    $myRegistrations = $userRegistrations->fetchAll();

    // Filter out registrations for archived sessions (unless viewing archived)
    if (!$showArchived) {
        $myRegistrations = array_filter($myRegistrations, function($reg) {
            return $reg['archived'] == 0;
        });
    }

    // Get statistics for my registrations
    $pending_registrations = count(array_filter($myRegistrations, function($reg) { return $reg['status'] === 'pending'; }));
    $approved_registrations = count(array_filter($myRegistrations, function($reg) { return $reg['status'] === 'approved'; }));

    // Get ALL training sessions for calendar (next 6 months) - EXCLUDE ARCHIVED
    $calendarTrainings = $pdo->query("
        SELECT session_id, title, session_date, 
               COALESCE(session_end_date, session_date) as session_end_date,
               COALESCE(duration_days, 1) as duration_days,
               venue, major_service, start_time, end_time, fee,
               (SELECT COUNT(*) FROM session_registrations WHERE session_id = training_sessions.session_id) as registrations_count
        FROM training_sessions 
        WHERE session_end_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 1 MONTH) AND DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
        AND archived = 0
        ORDER BY session_date ASC, start_time ASC
    ")->fetchAll();

    // Get extended training sessions for large calendar modal - EXCLUDE ARCHIVED
    $extendedCalendarTrainings = $pdo->query("
        SELECT session_id, title, session_date,
               COALESCE(session_end_date, session_date) as session_end_date,
               COALESCE(duration_days, 1) as duration_days,
               venue, major_service, start_time, end_time, fee, capacity,
               (SELECT COUNT(*) FROM session_registrations WHERE session_id = training_sessions.session_id) as registrations_count
        FROM training_sessions
        WHERE session_end_date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
        AND session_date <= DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
        AND archived = 0
        ORDER BY session_date ASC
    ")->fetchAll();

    // Get user registrations for JavaScript - FOR CALENDAR INTEGRATION
    $userRegistrationsStmt = $pdo->prepare("
        SELECT sr.session_id, sr.registration_date 
        FROM session_registrations sr
        JOIN training_sessions ts ON sr.session_id = ts.session_id
        WHERE sr.user_id = ? AND ts.archived = 0
    ");
    $userRegistrationsStmt->execute([$userId]);
    $userRegistrationsJS = $userRegistrationsStmt->fetchAll();

    // Get training summary statistics for calendar - UPDATED FOR MULTI-DAY AND ARCHIVE
    $trainingStats = [
        'total_upcoming' => $upcoming,
        'this_week' => $pdo->query("
            SELECT COUNT(*) FROM training_sessions 
            WHERE session_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            AND archived = 0
        ")->fetchColumn(),
        'this_month' => $pdo->query("
            SELECT COUNT(*) FROM training_sessions 
            WHERE session_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            AND archived = 0
        ")->fetchColumn(),
        'my_upcoming' => 0 // Will calculate below
    ];
    
    // Calculate my upcoming registrations - UPDATED FOR MULTI-DAY AND ARCHIVE
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM session_registrations sr
        JOIN training_sessions ts ON sr.session_id = ts.session_id
        WHERE sr.user_id = ? AND ts.session_end_date >= CURDATE() AND ts.archived = 0
    ");
    $stmt->execute([$userId]);
    $trainingStats['my_upcoming'] = $stmt->fetchColumn();

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    // Initialize all variables with empty/zero values on error
    $allSessions = [];
    $sessionsByService = [];
    $upcoming = 0;
    $past = 0;
    $total_sessions = 0;
    $registered = 0;
    $myRegistrations = [];
    $pending_registrations = 0;
    $approved_registrations = 0;
    $calendarTrainings = [];
    $extendedCalendarTrainings = [];
    $userRegistrationsJS = [];
    $trainingStats = [
        'total_upcoming' => 0,
        'this_week' => 0,
        'this_month' => 0,
        'my_upcoming' => 0
    ];
}
// Enhanced form validation in the POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_training'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security error: Invalid form submission. Please try again.";
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $serviceType = trim($_POST['service_type'] ?? '');
        $trainingProgram = trim($_POST['training_program'] ?? '');
        $preferredStartDate = !empty($_POST['preferred_start_date']) ? $_POST['preferred_start_date'] : null;
        $preferredEndDate = !empty($_POST['preferred_end_date']) ? $_POST['preferred_end_date'] : null;
        $preferredTime = $_POST['preferred_time'] ?? 'morning';
        $participantCount = (int)($_POST['participant_count'] ?? 1);
        $organizationName = trim($_POST['organization_name'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $contactNumber = trim($_POST['contact_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $purpose = trim($_POST['purpose'] ?? '');
        $additionalRequirements = trim($_POST['additional_requirements'] ?? '');
        $locationPreference = trim($_POST['location_preference'] ?? '');
        
        // Calculate duration if both dates provided
        $durationDays = 1;
        if ($preferredStartDate && $preferredEndDate) {
            $start = new DateTime($preferredStartDate);
            $end = new DateTime($preferredEndDate);
            $durationDays = $end->diff($start)->days + 1;
        }
        
        // Validation
        $errors = [];
        
        if (empty($serviceType) || !in_array($serviceType, ['Safety Service', 'Red Cross Youth'])) {
            $errors[] = "Please select a valid service type.";
        }
        
        if (empty($trainingProgram)) {
            $errors[] = "Please select a training program.";
        }
        
        if (empty($contactPerson)) {
            $errors[] = "Contact person is required.";
        }
        
        if (empty($contactNumber)) {
            $errors[] = "Contact number is required.";
        } else if (!validatePhilippineNumber($contactNumber)) {
            $errors[] = "Please enter a valid Philippine phone number (e.g., 09XX XXX XXXX, 032 XXX XXXX, or +63 9XX XXX XXXX).";
        }
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Valid email address is required.";
        }
        
        if ($participantCount < 1 || $participantCount > 100) {
            $errors[] = "Participant count must be between 1 and 100.";
        }
        
        // Enhanced date validation
        if ($preferredStartDate) {
            $startDate = new DateTime($preferredStartDate);
            $nextWeek = new DateTime('+1 week');
            
            if ($startDate < $nextWeek) {
                $errors[] = "Preferred start date must be at least 1 week from now.";
            }
            
            if ($preferredEndDate) {
                $endDate = new DateTime($preferredEndDate);
                if ($endDate < $startDate) {
                    $errors[] = "End date cannot be before start date.";
                }
                
                if ($durationDays > 365) {
                    $errors[] = "Training duration cannot exceed 365 days.";
                }
            }
        }
        
        // Validate training program against service type
        $validPrograms = [
            'Safety Service' => ['EFAT', 'OFAT', 'OTC'],
            'Red Cross Youth' => ['YVFC', 'LDP', 'AD']
        ];
        
        if (!in_array($trainingProgram, $validPrograms[$serviceType] ?? [])) {
            $errors[] = "Invalid training program for selected service type.";
        }
        
        if (empty($errors)) {
            try {
                // Create user folder for document uploads
                $userFolder = __DIR__ . "/../uploads/training_request_user_" . $userId;
                if (!file_exists($userFolder)) {
                    mkdir($userFolder, 0755, true);
                }

                $validIdPath = '';
                $participantListPath = '';
                $additionalDocsPaths = [];
                $additionalDocsFilenames = [];

                // Handle Valid ID upload (required)
                if (isset($_FILES['valid_id_request']) && $_FILES['valid_id_request']['error'] === UPLOAD_ERR_OK) {
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
                    $fileType = $_FILES['valid_id_request']['type'];
                    
                    if (in_array($fileType, $allowedTypes)) {
                        $fileExtension = pathinfo($_FILES['valid_id_request']['name'], PATHINFO_EXTENSION);
                        $originalName = pathinfo($_FILES['valid_id_request']['name'], PATHINFO_FILENAME);
                        $fileName = 'valid_id_' . time() . '.' . $fileExtension;
                        $fullPath = $userFolder . '/' . $fileName;
                        
                        if (move_uploaded_file($_FILES['valid_id_request']['tmp_name'], $fullPath)) {
                            $validIdPath = 'uploads/training_request_user_' . $userId . '/' . $fileName;
                        } else {
                            $errors[] = "Failed to upload valid ID. Please try again.";
                        }
                    } else {
                        $errors[] = "Invalid file type for Valid ID. Please upload JPG, PNG, or PDF files only.";
                    }
                } else {
                    $errors[] = "Valid ID upload is required.";
                }

                // Handle Participant List upload (optional for groups > 5)
                if (isset($_FILES['participant_list']) && $_FILES['participant_list']['error'] === UPLOAD_ERR_OK) {
                    $allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
                    $fileType = $_FILES['participant_list']['type'];
                    
                    if (in_array($fileType, $allowedTypes)) {
                        $fileExtension = pathinfo($_FILES['participant_list']['name'], PATHINFO_EXTENSION);
                        $fileName = 'participants_' . time() . '.' . $fileExtension;
                        $fullPath = $userFolder . '/' . $fileName;
                        
                        if (move_uploaded_file($_FILES['participant_list']['tmp_name'], $fullPath)) {
                            $participantListPath = 'uploads/training_request_user_' . $userId . '/' . $fileName;
                        } else {
                            $errors[] = "Failed to upload participant list. Please try again.";
                        }
                    } else {
                        $errors[] = "Invalid file type for participant list. Please upload PDF, DOC, DOCX, CSV, or Excel files.";
                    }
                }

                // Handle Additional Documents upload (optional)
                if (isset($_FILES['additional_docs']) && is_array($_FILES['additional_docs']['name'])) {
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                    
                    for ($i = 0; $i < count($_FILES['additional_docs']['name']); $i++) {
                        if ($_FILES['additional_docs']['error'][$i] === UPLOAD_ERR_OK) {
                            $fileType = $_FILES['additional_docs']['type'][$i];
                            
                            if (in_array($fileType, $allowedTypes)) {
                                $originalName = $_FILES['additional_docs']['name'][$i];
                                $fileExtension = pathinfo($originalName, PATHINFO_EXTENSION);
                                $fileName = 'additional_' . time() . '_' . $i . '.' . $fileExtension;
                                $fullPath = $userFolder . '/' . $fileName;
                                
                                if (move_uploaded_file($_FILES['additional_docs']['tmp_name'][$i], $fullPath)) {
                                    $additionalDocsPaths[] = 'uploads/training_request_user_' . $userId . '/' . $fileName;
                                    $additionalDocsFilenames[] = $originalName;
                                }
                            }
                        }
                    }
                }

                // Only proceed if no file upload errors
                if (empty($errors)) {
    $stmt = $pdo->prepare("
    INSERT INTO training_requests (
        user_id, service_type, training_program, preferred_start_date, preferred_end_date, 
        duration_days, preferred_start_time, preferred_end_time, 
        participant_count, organization_name, contact_person, 
        contact_number, email, purpose, additional_requirements, location_preference, status,
        valid_id_request_path, participant_list_path, additional_docs_paths, 
        additional_docs_filenames, documents_uploaded_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, NOW())
");
                    
                    $preferredStartTime = !empty($_POST['preferred_start_time']) ? $_POST['preferred_start_time'] : null;
$preferredEndTime = !empty($_POST['preferred_end_time']) ? $_POST['preferred_end_time'] : null;

$result = $stmt->execute([
    $userId, $serviceType, $trainingProgram, $preferredStartDate, $preferredEndDate,
    $durationDays, $preferredStartTime, $preferredEndTime,
    $participantCount, $organizationName, $contactPerson, 
    $contactNumber, $email, $purpose, $additionalRequirements, $locationPreference,
    $validIdPath,
    $participantListPath ?: null,
    !empty($additionalDocsPaths) ? json_encode($additionalDocsPaths) : null,
    !empty($additionalDocsFilenames) ? json_encode($additionalDocsFilenames) : null
]);
                    
                    if ($result) {
                        $requestId = $pdo->lastInsertId();
                        $successMessage = "Training request submitted successfully! Request ID: #" . $requestId . ". We will contact you within 3-5 business days.";
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    } else {
                        $errorMessage = "Failed to submit training request. Please try again.";
                    }
                }
                
            } catch (PDOException $e) {
                error_log("Training request error: " . $e->getMessage());
                $errorMessage = "Database error occurred. Please try again later.";
            }
        } else {
            $errorMessage = implode("<br>", $errors);
        }
    }
}
// First, add the phone validation function at the top of the file after the existing functions
function validatePhilippineNumber($phoneNumber) {
    // Remove all non-digit characters
    $cleanNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
    
    // Check for various Philippine number formats
    $patterns = [
        '/^(09\d{9})$/',           // 09XXXXXXXXX (mobile)
        '/^(\+639\d{9})$/',        // +639XXXXXXXXX (mobile with country code)
        '/^(639\d{9})$/',          // 639XXXXXXXXX (mobile without +)
        '/^(02\d{7,8})$/',         // 02XXXXXXX or 02XXXXXXXX (Manila landline)
        '/^(032\d{7})$/',          // 032XXXXXXX (Cebu landline)
        '/^(033\d{7})$/',          // 033XXXXXXX (Iloilo landline)
        '/^(034\d{7})$/',          // 034XXXXXXX (Bacolod landline)
        '/^(035\d{7})$/',          // 035XXXXXXX (Dumaguete landline)
        '/^(036\d{7})$/',          // 036XXXXXXX (Kalibo landline)
        '/^(038\d{7})$/',          // 038XXXXXXX (Tagbilaran landline)
        '/^(045\d{7})$/',          // 045XXXXXXX (Cabanatuan landline)
        '/^(046\d{7})$/',          // 046XXXXXXX (Batangas landline)
        '/^(047\d{7})$/',          // 047XXXXXXX (Lipa landline)
        '/^(048\d{7})$/',          // 048XXXXXXX (San Pablo landline)
        '/^(049\d{7})$/',          // 049XXXXXXX (Los Baños landline)
        '/^(063\d{7})$/',          // 063XXXXXXX (Davao landline)
        '/^(078\d{7})$/',          // 078XXXXXXX (Cagayan de Oro landline)
        '/^(082\d{7})$/',          // 082XXXXXXX (Davao landline alt)
        '/^(083\d{7})$/',          // 083XXXXXXX (Butuan landline)
        '/^(085\d{7})$/',          // 085XXXXXXX (Zamboanga landline)
        '/^(088\d{7})$/',          // 088XXXXXXX (Cagayan de Oro landline alt)
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $cleanNumber)) {
            return true;
        }
    }
    
    return false;
}
// Get user's training requests for display
try {
   $userRequestsStmt = $pdo->prepare("
    SELECT tr.*, tp.program_name, tp.program_description, tp.typical_duration_hours,
           tr.preferred_start_time, tr.preferred_end_time
    FROM training_requests tr
    LEFT JOIN training_programs tp ON tr.training_program = tp.program_code 
        AND tr.service_type = tp.service_type
    WHERE tr.user_id = ?
    ORDER BY tr.created_at DESC
");
    $userRequestsStmt->execute([$userId]);
    $userTrainingRequests = $userRequestsStmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching training requests: " . $e->getMessage());
    $userTrainingRequests = [];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Training Schedule - PRC Portal</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/sidebar.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/dashboard.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/registration.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/header.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/schedule.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  <div class="header-content">

    
    <div class="admin-content">
      <div class="events-layout">
        <!-- Main Content Area -->
        <div class="main-content">
          <div class="page-header">
            <h1><i class="fas fa-calendar-alt"></i> Training Schedule</h1>
            <p>Welcome, <?= htmlspecialchars($username) ?> - View and register for upcoming training sessions</p>
          </div>

          <?php if ($regMessage): ?>
            <div class="alert <?= strpos($regMessage, 'successfully') !== false ? 'success' : 'error' ?>">
              <i class="fas <?= strpos($regMessage, 'successfully') !== false ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
              <?= htmlspecialchars($regMessage) ?>
            </div>
          <?php endif; ?>

          <!-- Action Bar -->
          <div class="action-bar">
            <div class="action-bar-left">
              <form method="GET" class="search-box">
                <input type="hidden" name="service" value="<?= htmlspecialchars($serviceFilter) ?>">
                <i class="fas fa-search"></i>
                <input type="text" name="search" placeholder="Search training sessions..." value="<?= htmlspecialchars($search) ?>">
              </form>
              <button class="btn-request-training" onclick="openTrainingRequestModal()" style="margin-left: 1rem;">
    <i class="fas fa-chalkboard-teacher"></i> Request Training
</button>

              <div class="status-filter">
                <button onclick="filterService('all')" class="<?= !$serviceFilter || $serviceFilter === 'all' ? 'active' : '' ?>">All Services</button>
                <?php foreach ($majorServices as $service): ?>
                  <button onclick="filterService('<?= urlencode($service) ?>')" class="<?= $serviceFilter === $service ? 'active' : '' ?>">
                    <?= htmlspecialchars($service) ?>
                  </button>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- Statistics Overview -->
          <div class="stats-overview">
            <div class="stat-card">
              <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                <i class="fas fa-calendar"></i>
              </div>
              <div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?= $total_sessions ?></div>
                <div style="color: var(--gray); font-size: 0.9rem;">Total Sessions</div>
              </div>
            </div>
            
            <div class="stat-card">
              <div class="stat-icon" style="background: linear-gradient(135deg, #00c853 0%, #64dd17 100%);">
                <i class="fas fa-calendar-check"></i>
              </div>
              <div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?= $upcoming ?></div>
                <div style="color: var(--gray); font-size: 0.9rem;">Upcoming</div>
              </div>
            </div>
            
            <div class="stat-card">
              <div class="stat-icon" style="background: linear-gradient(135deg, #ffd93d 0%, #ff9800 100%);">
                <i class="fas fa-user-check"></i>
              </div>
              <div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?= $registered ?></div>
                <div style="color: var(--gray); font-size: 0.9rem;">My Registrations</div>
              </div>
            </div>
            
            <div class="stat-card">
              <div class="stat-icon" style="background: linear-gradient(135deg, #4caf50 0%, #8bc34a 100%);">
                <i class="fas fa-check-circle"></i>
              </div>
              <div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?= $approved_registrations ?></div>
                <div style="color: var(--gray); font-size: 0.9rem;">Approved</div>
              </div>
            </div>
          </div>
<div class="modal" id="trainingRequestModal">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-headers">
            <h2 class="modal-title">Request Training Program</h2>
            <button class="close-modal" onclick="closeTrainingRequestModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <?php if (isset($errorMessage)): ?>
            <div class="alert error">
                <i class="fas fa-exclamation-circle"></i>
                <?= $errorMessage ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($successMessage)): ?>
            <div class="alert success">
                <i class="fas fa-check-circle"></i>
                <?= $successMessage ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" id="trainingRequestForm" enctype="multipart/form-data">
            <input type="hidden" name="request_training" value="1">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            
            <!-- Step 1: Program Selection -->
            <div class="form-section">
                <h3 class="section-title">
                    <i class="fas fa-graduation-cap"></i>
                    Training Program Details
                </h3>
                
                <div class="form-group">
                    <label for="service_type">Service Type <span class="required">*</span></label>
                    <select id="service_type" name="service_type" required onchange="updateTrainingPrograms()">
                        <option value="">Select Service Type</option>
                        <option value="Safety Service" <?= isset($_POST['service_type']) && $_POST['service_type'] === 'Safety Service' ? 'selected' : '' ?>>Safety Service</option>
                        <option value="Red Cross Youth" <?= isset($_POST['service_type']) && $_POST['service_type'] === 'Red Cross Youth' ? 'selected' : '' ?>>Red Cross Youth</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="training_program">Training Program <span class="required">*</span></label>
                    <select id="training_program" name="training_program" required disabled>
                        <option value="">Select service type first</option>
                    </select>
                    <div class="program-description" id="program_description" style="display: none;">
                        <small style="color: var(--gray); margin-top: 0.5rem; display: block;"></small>
                    </div>
                </div>
                
                <!-- Enhanced Date Range Selection -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="preferred_start_date">Preferred Start Date</label>
                        <input type="date" 
                               id="preferred_start_date" 
                               name="preferred_start_date" 
                               min="<?= date('Y-m-d', strtotime('+1 week')) ?>"
                               value="<?= isset($_POST['preferred_start_date']) ? htmlspecialchars($_POST['preferred_start_date']) : '' ?>"
                               onchange="updateRequestDatePreview()">
                        <small style="color: var(--gray);">Leave blank if flexible</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="preferred_end_date">Preferred End Date</label>
                        <input type="date" 
                               id="preferred_end_date" 
                               name="preferred_end_date" 
                               min="<?= date('Y-m-d', strtotime('+1 week')) ?>"
                               value="<?= isset($_POST['preferred_end_date']) ? htmlspecialchars($_POST['preferred_end_date']) : '' ?>"
                               onchange="updateRequestDatePreview()">
                        <small style="color: var(--gray);">Same as start date for single-day training</small>
                    </div>
                </div>

                <!-- Date Preview (similar to sessions) -->
                <div class="date-preview-container" id="requestDatePreviewContainer" style="display: none;">
                    <div class="date-preview">
                        <div class="date-preview-header">
                            <i class="fas fa-calendar-check"></i>
                            <span>Requested Training Period</span>
                        </div>
                        <div class="date-preview-content">
                            <div class="date-range">
                                <span class="start-date" id="requestPreviewStartDate">-</span>
                                <i class="fas fa-arrow-right"></i>
                                <span class="end-date" id="requestPreviewEndDate">-</span>
                            </div>
                            <div class="duration-display" id="requestPreviewDuration">1 day</div>
                        </div>
                    </div>
                </div>
                <div class="form-row">
    <div class="form-group">
        <label for="preferred_start_time">Preferred Start Time <span class="required">*</span></label>
        <input type="time" 
               id="preferred_start_time" 
               name="preferred_start_time" 
               required
               value="09:00"
               title="Preferred training start time">
    </div>
    
    <div class="form-group">
        <label for="preferred_end_time">Preferred End Time <span class="required">*</span></label>
        <input type="time" 
               id="preferred_end_time" 
               name="preferred_end_time" 
               required
               value="17:00"
               title="Preferred training end time">
    </div>
</div>
            </div>
            

            <!-- Step 2: Participant Information -->
<div class="form-section">
    <h3 class="section-title">
        <i class="fas fa-users"></i>
        Participant Information
    </h3>
    
    <div class="form-group">
        <label for="participant_count">Number of Participants <span class="required">*</span></label>
        <input type="number" 
               id="participant_count" 
               name="participant_count" 
               min="1" 
               max="100" 
               required 
               placeholder="How many participants?"
               value="<?= isset($_POST['participant_count']) ? htmlspecialchars($_POST['participant_count']) : '1' ?>"
               onchange="toggleOrganizationSection()">
        <small style="color: var(--gray);">Minimum 1, maximum 100 participants</small>
    </div>
    
    
    <div class="form-group">
        <label for="location_preference">Location Preference</label>
        <input type="text" 
               id="location_preference" 
               name="location_preference" 
               placeholder="Preferred training location"
               value="<?= isset($_POST['location_preference']) ? htmlspecialchars($_POST['location_preference']) : '' ?>">
        <small style="color: var(--gray);">City or specific venue preference</small>
    </div>
    
    <div class="form-group" id="organization_section" style="display: none;">
        <label for="organization_name">Organization/Company Name</label>
        <input type="text" 
               id="organization_name" 
               name="organization_name" 
               placeholder="Organization name if applicable"
               value="<?= isset($_POST['organization_name']) ? htmlspecialchars($_POST['organization_name']) : '' ?>">
        <small style="color: var(--gray);">Required for groups of 5 or more participants</small>
    </div>
</div>

            <!-- Step 3: Contact Information -->
            <div class="form-section">
                <h3 class="section-title">
                    <i class="fas fa-address-book"></i>
                    Contact Information
                </h3>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="contact_person">Contact Person <span class="required">*</span></label>
                        <input type="text" 
                               id="contact_person" 
                               name="contact_person" 
                               required 
                               placeholder="Primary contact person"
                               value="<?= isset($_POST['contact_person']) ? htmlspecialchars($_POST['contact_person']) : '' ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="contact_number">Philippine Contact Number <span class="required">*</span></label>
                        <input type="tel" 
                               id="contact_number" 
                               name="contact_number" 
                               required 
                               placeholder="09XX XXX XXXX or 032 XXX XXXX"
                               pattern="^(\+?63|0)?[0-9\s\-\(\)]{10,14}$"
                               value="<?= isset($_POST['contact_number']) ? htmlspecialchars($_POST['contact_number']) : '' ?>"
                               onblur="validatePhoneNumber(this)">
                        <div class="phone-validation-feedback" id="phoneValidationFeedback" style="display: none;">
                            <!-- Validation feedback will be shown here -->
                        </div>
                        <small style="color: var(--gray);">
                            Accepted formats: 09XX XXX XXXX (mobile), 032 XXX XXXX (landline), +63 9XX XXX XXXX
                        </small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="email">Email Address <span class="required">*</span></label>
                    <input type="email" 
                           id="email" 
                           name="email" 
                           required 
                           placeholder="contact@example.com" 
                           value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : htmlspecialchars($userEmail ?? '') ?>">
                </div>
                
                <div class="form-group">
                    <label for="purpose">Purpose/Objective</label>
                    <textarea id="purpose" 
                             name="purpose" 
                             rows="3" 
                             placeholder="Brief description of why you need this training..."><?= isset($_POST['purpose']) ? htmlspecialchars($_POST['purpose']) : '' ?></textarea>
                </div>
                
                <div class="form-group">
                    <label for="additional_requirements">Additional Requirements</label>
                    <textarea id="additional_requirements" 
                             name="additional_requirements" 
                             rows="2" 
                             placeholder="Any special requirements, equipment needs, or accessibility considerations..."><?= isset($_POST['additional_requirements']) ? htmlspecialchars($_POST['additional_requirements']) : '' ?></textarea>
                </div>
            </div>

            <!-- Step 4: Document Upload -->
            <div class="form-section">
                <h3 class="section-title">
                    <i class="fas fa-file-upload"></i>
                    Required Documents
                </h3>
                
                <div class="upload-notice">
                    <i class="fas fa-info-circle"></i>
                    <p>Please upload the following documents to process your training request:</p>
                </div>
                
                <div class="form-group">
                    <label for="valid_id_request">Valid ID <span class="required">*</span></label>
                    <div class="file-upload-container">
                        <input type="file" 
                               id="valid_id_request" 
                               name="valid_id_request" 
                               required 
                               accept=".jpg,.jpeg,.png,.pdf"
                               onchange="handleFileUpload(this)">
                        <div class="file-upload-info">
                            <i class="fas fa-id-card"></i>
                            <span>Upload a clear photo of your valid ID</span>
                            <small>Accepted formats: JPG, PNG, PDF (Max 5MB)</small>
                        </div>
                    </div>
                </div>
                
                <div class="form-group" id="participant_list_section" style="display: none;">
                    <label for="participant_list">Participant List</label>
                    <div class="file-upload-container">
                        <input type="file" 
                               id="participant_list" 
                               name="participant_list" 
                               accept=".pdf,.doc,.docx,.csv,.xls,.xlsx"
                               onchange="handleFileUpload(this)">
                        <div class="file-upload-info">
                            <i class="fas fa-users"></i>
                            <span>Upload list of participants (for groups of 5 or more)</span>
                            <small>Accepted formats: PDF, DOC, DOCX, CSV, Excel (Max 10MB)</small>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="additional_docs">Additional Documents (Optional)</label>
                    <div class="file-upload-container">
                        <input type="file" 
                               id="additional_docs" 
                               name="additional_docs[]" 
                               multiple 
                               accept=".jpg,.jpeg,.png,.pdf,.doc,.docx"
                               onchange="handleFileUpload(this)">
                        <div class="file-upload-info">
                            <i class="fas fa-file-alt"></i>
                            <span>Upload supporting documents (certificates, authorization letters, etc.)</span>
                            <small>Accepted formats: JPG, PNG, PDF, DOC, DOCX (Max 5MB each)</small>
                        </div>
                    </div>
                    <div id="additional_docs_list" class="uploaded-files-list"></div>
                </div>
            </div>
            
            <div class="form-notice">
                <i class="fas fa-info-circle"></i>
                <p>Your training request will be reviewed by our training coordinators. We will contact you within 3-5 business days to discuss scheduling and requirements. All uploaded documents are securely stored and used only for training request processing.</p>
            </div>
            
            <button type="submit" class="btn-submit">
                <i class="fas fa-paper-plane"></i> Submit Training Request
            </button>
        </form>
    </div>
</div>

          <!-- Available Training Sessions -->
          <div class="events-table-wrapper">
            <div class="table-header">
              <h2 class="table-title">Available Training Sessions</h2>
            </div>

            <?php if (empty($allSessions)): ?>
              <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <h3>No training sessions found</h3>
                <p><?= $search ? 'Try adjusting your search criteria' : 'There are currently no training sessions available.' ?></p>
              </div>
            <?php else: ?>
              <table class="data-table">
                <thead>
  <tr>
    <th>Training Details</th>
    <th>Date Range & Time</th>
    <th>Instructor</th>
    <th>Venue</th>
    <th>Service</th>
    <th>Capacity</th>
    <th>Fee</th>
    <th>Status</th>
    <th>Action</th>
  </tr>
</thead>
                <tbody>
                  <?php foreach ($allSessions as $s): 
                    $sessionStartDate = strtotime($s['session_date']);
                    $sessionEndDate = strtotime($s['session_end_date'] ?? $s['session_date']);
                    $durationDays = $s['duration_days'] ?? 1;
                    $isFull = $s['capacity'] > 0 && $s['registered_count'] >= $s['capacity'];
                    $isRegistered = $s['is_registered'];
                    
                    // Determine session status
                    $today = strtotime('today');
                    $sessionStatus = 'upcoming';
                    if ($sessionEndDate < $today) {
                        $sessionStatus = 'past';
                    } elseif ($sessionStartDate <= $today && $sessionEndDate >= $today) {
                        $sessionStatus = 'ongoing';
                    }
                  ?>
                    <tr>
                      <td>
                        <div class="event-title"><?= htmlspecialchars($s['title']) ?></div>
                        <div style="font-size: 0.85rem; color: var(--gray); margin-top: 0.2rem;">
                          Session ID: #<?= $s['session_id'] ?>
                        </div>
                        <?php if ($sessionStatus === 'ongoing'): ?>
                          <span class="ongoing-badge">Ongoing</span>
                        <?php endif; ?>
                      </td>
                      <td>

<div class="session-datetime">
  <?php if ($durationDays == 1): ?>
    <div class="session-date-single">
      <span class="session-date"><?= date('M d, Y', $sessionStartDate) ?></span>
      <div class="session-time-duration">
        <span class="session-time"><?= date('g:i A', strtotime($s['start_time'])) ?> - <?= date('g:i A', strtotime($s['end_time'])) ?></span>
        <div class="session-duration">Single Day</div>
      </div>
    </div>
  <?php else: ?>
    <div class="session-date-range">
      <div class="session-date-start"><?= date('M d, Y', $sessionStartDate) ?></div>
      <div class="session-date-end">to <?= date('M d, Y', $sessionEndDate) ?></div>
      <div class="session-time-duration">
        <span class="session-time"><?= date('g:i A', strtotime($s['start_time'])) ?> - <?= date('g:i A', strtotime($s['end_time'])) ?></span>
        <div class="session-duration"><?= $durationDays ?> days</div>
      </div>
    </div>
  <?php endif; ?>
</div>
                        <td>
  <?php if (!empty($s['instructor'])): ?>
    <div class="instructor-info">
      <div class="instructor-name"><?= htmlspecialchars($s['instructor']) ?></div>
      <?php if (!empty($s['instructor_credentials'])): ?>
        <div class="instructor-credentials"><?= htmlspecialchars($s['instructor_credentials']) ?></div>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <span class="no-instructor">Not assigned</span>
  <?php endif; ?>
</td>
                      </td>
                      <td><?= htmlspecialchars($s['venue']) ?></td>
                      <td>
                        <span class="service-badge <?= strtolower(str_replace(' ', '-', $s['major_service'])) ?>">
                          <?= htmlspecialchars($s['major_service']) ?>
                        </span>
                      </td>
                      <td>
                        <div class="registrations-badge <?= $isFull ? 'full' : '' ?>">
                          <i class="fas fa-users"></i>
                          <?= $s['registered_count'] ?> / <?= $s['capacity'] ?: '∞' ?>
                          <?php if ($isFull): ?>
                            <span style="font-size: 0.7rem; background: var(--prc-red); color: white; padding: 0.2rem 0.4rem; border-radius: 4px;">FULL</span>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td>
                        <div class="fee">
                          <?= $s['fee'] > 0 ? '₱' . number_format($s['fee'], 2) : 'Free' ?>
                        </div>
                      </td>
                      <td>
                        <?php if ($isRegistered): ?>
                          <span class="status-badge registered">
                            <i class="fas fa-check-circle"></i> Registered
                          </span>
                        <?php else: ?>
                          <span class="status-badge <?= $sessionStatus ?>">
                            <?php if ($sessionStatus === 'upcoming'): ?>
                              <i class="fas fa-clock"></i> Available
                            <?php elseif ($sessionStatus === 'ongoing'): ?>
                              <i class="fas fa-play-circle"></i> Ongoing
                            <?php else: ?>
                              <i class="fas fa-history"></i> Past
                            <?php endif; ?>
                          </span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if (!$isRegistered && !$isFull && $sessionStatus === 'upcoming'): ?>
                          <button class="btn-action btn-register" onclick="openRegisterModal(<?= htmlspecialchars(json_encode($s)) ?>)">
                            <i class="fas fa-user-plus"></i> Register
                          </button>
                        <?php elseif ($isRegistered): ?>
                          <button class="btn-action btn-registered" disabled>
                            <i class="fas fa-check"></i> Registered
                          </button>
                        <?php elseif ($isFull): ?>
                          <button class="btn-action btn-full" disabled>
                            <i class="fas fa-times"></i> Full
                          </button>
                        <?php else: ?>
                          <button class="btn-action btn-past" disabled>
                            <i class="fas fa-history"></i> Past
                          </button>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>

          <!-- My Registrations Section -->
          <div class="registrations-section">
            <div class="section-header">
              <h2><i class="fas fa-list-check"></i> My Training Registrations</h2>
            </div>
            
            <?php if (empty($myRegistrations)): ?>
              <div class="empty-state">
                <i class="fas fa-user-slash"></i>
                <h3>No registrations found</h3>
                <p>You haven't registered for any training sessions yet.</p>
              </div>
            <?php else: ?>
              <div class="table-container">
                <table class="data-table">
                  <thead>
                    <tr>
                      <th>Training</th>
                      <th>Service</th>
                      <th>Date Range & Time</th>
                      <th>Venue</th>
                      <th>Type</th>
                      <th>Payment</th>
                      <th>Documents</th>
                      <th>Registered On</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($myRegistrations as $r): 
                      $regStartDate = strtotime($r['session_date']);
                      $regEndDate = strtotime($r['session_end_date'] ?? $r['session_date']);
                      $regDurationDays = $r['duration_days'] ?? 1;
                    ?>
                    <tr>
                      <td><?= htmlspecialchars($r['title']) ?></td>
                      <td><?= htmlspecialchars($r['major_service']) ?></td>
                      <td>
                        <div class="session-datetime">
                          <?php if ($regDurationDays == 1): ?>
                            <div class="session-date-single">
                              <span class="session-date"><?= date('M d, Y', $regStartDate) ?></span>
                              <span class="session-time"><?= date('g:i A', strtotime($r['start_time'])) ?> - <?= date('g:i A', strtotime($r['end_time'])) ?></span>
                              <div class="session-duration">Single Day</div>
                            </div>
                          <?php else: ?>
                            <div class="session-date-range">
                              <div class="session-date-start"><?= date('M d, Y', $regStartDate) ?></div>
                              <div class="session-date-end">to <?= date('M d, Y', $regEndDate) ?></div>
                              <span class="session-time"><?= date('g:i A', strtotime($r['start_time'])) ?> - <?= date('g:i A', strtotime($r['end_time'])) ?></span>
                              <div class="session-duration"><?= $regDurationDays ?> days</div>
                            </div>
                          <?php endif; ?>
                        </div>
                      </td>
  <td>
    <div class="venue-display">
        <div class="venue-preview">
            <?= htmlspecialchars(strlen($r['venue']) > 60 ? 
                substr($r['venue'], 0, 60) . '...' : 
                $r['venue']) ?>
        </div>
        <?php if (strlen($r['venue']) > 60): ?>
            <button type="button" class="btn-view-venue" onclick="showVenueModal('<?= htmlspecialchars($r['title'], ENT_QUOTES) ?>', <?= htmlspecialchars(json_encode($r['venue']), ENT_QUOTES) ?>)">
                <i class="fas fa-map-marker-alt"></i> View Full
            </button>
        <?php endif; ?>
    </div>
</td>
                      <td>
                        <span class="type-badge <?= $r['registration_type'] ?>">
                          <?= ucfirst($r['registration_type']) ?>
                        </span>
                      </td>
                      <td>
                        <?php if ($r['payment_amount'] > 0): ?>
                          <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                            <span class="fee-amount" style="font-size: 0.8rem; padding: 0.2rem 0.5rem;">₱<?= number_format($r['payment_amount'], 2) ?></span>
                            <span class="payment-status-indicator <?= $r['payment_status'] ?? 'pending' ?>">
                              <?= ucfirst($r['payment_status'] ?? 'pending') ?>
                            </span>
                          </div>
                        <?php else: ?>
                          <span class="fee-free" style="font-size: 0.8rem; padding: 0.2rem 0.5rem;">FREE</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <div class="document-links">
                          <?php if ($r['valid_id_path']): ?>
                            <a href="../<?= htmlspecialchars($r['valid_id_path']) ?>" target="_blank" class="doc-link">
                              <i class="fas fa-id-card"></i> Valid ID
                            </a>
                          <?php endif; ?>
                          <?php if ($r['requirements_path']): ?>
                            <a href="../<?= htmlspecialchars($r['requirements_path']) ?>" target="_blank" class="doc-link">
                              <i class="fas fa-file-alt"></i> Requirements
                            </a>
                          <?php endif; ?>
                          <?php if ($r['payment_receipt_path']): ?>
                            <a href="../<?= htmlspecialchars($r['payment_receipt_path']) ?>" target="_blank" class="doc-link">
                              <i class="fas fa-receipt"></i> Receipt
                            </a>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td><?= date('M d, Y', strtotime($r['registration_date'])) ?></td>
                      <td>
                        <span class="status-badge <?= $r['status'] ?>">
                          <i class="fas <?= 
                            $r['status'] === 'pending' ? 'fa-clock' : 
                            ($r['status'] === 'approved' ? 'fa-check-circle' : 
                            ($r['status'] === 'rejected' ? 'fa-times-circle' : 'fa-info-circle')) ?>"></i>
                          <?= ucfirst($r['status']) ?>
                        </span>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
<div class="training-requests-section">
    <div class="section-header">
        <h2><i class="fas fa-chalkboard-teacher"></i> My Training Requests</h2>
    </div>
    
    <?php if (empty($userTrainingRequests)): ?>
        <div class="empty-state">
            <i class="fas fa-clipboard-list"></i>
            <h3>No training requests found</h3>
            <p>You haven't submitted any training requests yet. Click "Request Training" to get started.</p>
        </div>
    <?php else: ?>
        <div class="table-container">
            <table class="data-table training-requests-table">
                <thead>
                    <tr>
                        <th>Request Details</th>
                        <th>Service & Program</th>
                        <th>Participants</th>
                        <th>Schedule</th>
                        <th>Contact</th>
                        <th>Status</th>
                        <th>Submitted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($userTrainingRequests as $request): ?>
                    <tr>
                        <td>
                            <div class="request-title">Request #<?= $request['request_id'] ?></div>
                            <?php if ($request['purpose']): ?>
                                <div class="contact-detail">
                                    <?= htmlspecialchars(substr($request['purpose'], 0, 80)) ?><?= strlen($request['purpose']) > 80 ? '...' : '' ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        
                        <td>
                            <div class="service-program">
                                <span class="service-badge <?= strtolower(str_replace(' ', '-', $request['service_type'])) ?>">
                                    <?= htmlspecialchars($request['service_type']) ?>
                                </span>
                                <div style="font-weight: 600; margin-top: 0.2rem; font-size: 0.8rem;">
                                    <?= htmlspecialchars($request['program_name'] ?: $request['training_program']) ?>
                                </div>
                                <?php if ($request['typical_duration_hours']): ?>
                                    <div class="contact-detail"><?= $request['typical_duration_hours'] ?> hours</div>
                                <?php endif; ?>
                            </div>
                        </td>
                        
                        <td>
                            <div class="participant-count">
                                <span class="count"><?= $request['participant_count'] ?></span>
                                <span class="label">participants</span>
                            </div>
                            <?php if ($request['organization_name']): ?>
                                <div class="contact-detail" style="margin-top: 0.3rem;">
                                    <?= htmlspecialchars(substr($request['organization_name'], 0, 20)) ?><?= strlen($request['organization_name']) > 20 ? '...' : '' ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        
                        <td>
                            <div class="schedule-info">
                                <?php if ($request['preferred_start_date']): ?>
                                    <div class="date-main">
                                        <?= date('M d, Y', strtotime($request['preferred_start_date'])) ?>
                                    </div>
                                    <?php if ($request['preferred_end_date'] && $request['preferred_end_date'] !== $request['preferred_start_date']): ?>
                                        <div class="date-range">
                                            to <?= date('M d, Y', strtotime($request['preferred_end_date'])) ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($request['preferred_start_time']) || !empty($request['preferred_end_time'])): ?>
                                        <div class="time-info">
                                            <i class="fas fa-clock"></i>
                                            <?php if (!empty($request['preferred_start_time'])): ?>
                                                <?= date('g:i A', strtotime($request['preferred_start_time'])) ?>
                                            <?php endif; ?>
                                            <?php if (!empty($request['preferred_end_time'])): ?>
                                                <?= !empty($request['preferred_start_time']) ? ' - ' : 'End: ' ?>
                                                <?= date('g:i A', strtotime($request['preferred_end_time'])) ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="contact-detail" style="font-style: italic;">Flexible</div>
                                <?php endif; ?>
                                
                                <?php if ($request['location_preference']): ?>
                                    <div class="location-info">
                                        <i class="fas fa-map-marker-alt"></i> 
                                        <?= htmlspecialchars(substr($request['location_preference'], 0, 15)) ?><?= strlen($request['location_preference']) > 15 ? '...' : '' ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        
                        <td>
                            <div class="contact-info">
                                <div class="contact-name"><?= htmlspecialchars($request['contact_person']) ?></div>
                                <div class="contact-detail"><?= htmlspecialchars($request['contact_number']) ?></div>
                                <div class="contact-detail"><?= htmlspecialchars($request['email']) ?></div>
                            </div>
                        </td>
                        
                        <td>
                            <span class="status-badge <?= $request['status'] ?>">
                                <i class="fas <?= 
                                    $request['status'] === 'pending' ? 'fa-clock' : 
                                    ($request['status'] === 'under_review' ? 'fa-search' :
                                    ($request['status'] === 'approved' ? 'fa-check-circle' :
                                    ($request['status'] === 'scheduled' ? 'fa-calendar-check' :
                                    ($request['status'] === 'completed' ? 'fa-graduation-cap' : 'fa-times-circle')))) ?>"></i>
                                <?= ucwords(str_replace('_', ' ', $request['status'])) ?>
                            </span>
                        </td>
                        
                        <td>
                            <div class="submitted-date">
                                <?= date('M d, Y', strtotime($request['created_at'])) ?>
                            </div>
                            <div class="submitted-time">
                                <?= date('g:i A', strtotime($request['created_at'])) ?>
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

        <!-- Training Calendar Sidebar -->
        <div class="calendar-sidebar">
          <div class="calendar-header">
            <h2><i class="fas fa-calendar-alt"></i> Training Calendar</h2>
            <button class="btn-view-calendar" onclick="openCalendarModal()">
              <i class="fas fa-expand"></i> View Full Calendar
            </button>
          </div>

          <!-- Training Type Legend -->
          <div class="training-type-legend">
            <div class="legend-title">
              <i class="fas fa-palette"></i> Training Services
            </div>
            <div class="legend-items">
              <div class="legend-item">
                <div class="legend-color health"></div>
                <div class="legend-text">Health Service</div>
              </div>
              <div class="legend-item">
                <div class="legend-color safety"></div>
                <div class="legend-text">Safety Service</div>
              </div>
              <div class="legend-item">
                <div class="legend-color welfare"></div>
                <div class="legend-text">Welfare Service</div>
              </div>
              <div class="legend-item">
                <div class="legend-color disaster"></div>
                <div class="legend-text">Disaster Management</div>
              </div>
              <div class="legend-item">
                <div class="legend-color rcy"></div>
                <div class="legend-text">Red Cross Youth</div>
              </div>
              <div class="legend-item">
                <div class="legend-color general"></div>
                <div class="legend-text">General Training</div>
              </div>
            </div>
          </div>

          <!-- Training Summary -->
          <div class="training-summary">
            <div class="summary-title">
              <i class="fas fa-chart-bar"></i> Training Overview
            </div>
            <div class="summary-stats">
              <div class="summary-stat">
                <div class="summary-stat-number"><?= $trainingStats['total_upcoming'] ?></div>
                <div class="summary-stat-label">Total Upcoming</div>
              </div>
              <div class="summary-stat">
                <div class="summary-stat-number"><?= $trainingStats['this_week'] ?></div>
                <div class="summary-stat-label">This Week</div>
              </div>
              <div class="summary-stat">
                <div class="summary-stat-number"><?= $trainingStats['this_month'] ?></div>
                <div class="summary-stat-label">This Month</div>
              </div>
              <div class="summary-stat">
                <div class="summary-stat-number"><?= $registered ?></div>
                <div class="summary-stat-label">My Registrations</div>
              </div>
            </div>
          </div>

          <!-- Calendar Container -->
          <div class="calendar-container" id="trainingCalendarContainer">
            <!-- Calendar will be generated by JavaScript -->
          </div>
        </div>
      </div>
    </div>

    <!-- Large Calendar Modal -->
    <div class="modal calendar-modal" id="calendarModal">
      <div class="modal-content calendar-modal-content">
        <div class="modal-header">
          <h2 class="modal-title">
            <i class="fas fa-calendar-alt"></i> Training Calendar
          </h2>
          <button class="close-modal" onclick="closeCalendarModal()">
            <i class="fas fa-times"></i>
          </button>
        </div>
        
        <div class="calendar-nav">
          <button class="nav-btn" onclick="changeMonth(-1)">
            <i class="fas fa-chevron-left"></i>
          </button>
          <div class="current-month-year" id="currentMonthYear">
            <!-- Will be populated by JavaScript -->
          </div>
          <button class="nav-btn" onclick="changeMonth(1)">
            <i class="fas fa-chevron-right"></i>
          </button>
        </div>
        
        <div class="large-calendar-container" id="largeCalendarContainer">
          <!-- Calendar will be generated by JavaScript -->
        </div>
        
        <div class="calendar-legend">
          <div class="legend-item">
            <div class="legend-dot has-events"></div>
            <span>Has Training</span>
          </div>
          <div class="legend-item">
            <div class="legend-dot today"></div>
            <span>Today</span>
          </div>
          <div class="legend-item">
            <div class="legend-dot registered"></div>
            <span>Registered</span>
          </div>
          <div class="legend-item">
            <div class="legend-dot past"></div>
            <span>Past Date</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Training Details Tooltip -->
    <div class="event-tooltip" id="trainingTooltip">
      <div class="tooltip-content">
        <!-- Training details will be populated here -->
      </div>
    </div>

    <!-- Registration Modal -->
    <div class="modal" id="registerModal">
      <div class="modal-content">
        <div class="modal-headers">
          <h2 class="modal-titles" id="modalTitle">Register for Training</h2>
          <button class="close-modal" onclick="closeRegisterModal()">
            <i class="fas fa-times"></i>
          </button>
        </div>
        
        <form method="POST" id="registerForm" enctype="multipart/form-data">
          <input type="hidden" name="register_training" value="1">
          <input type="hidden" name="session_id" id="sessionId">
          <input type="hidden" name="payment_amount" id="hiddenPaymentAmount" value="0">
          
          <div class="event-info" id="trainingInfo">
            <!-- Training details will be populated by JavaScript -->
          </div>
          
          <!-- Registration Type Tabs -->
          <div class="tab-container">
            <div class="tab-buttons">
              <button type="button" class="tab-btn active" onclick="switchTab('individual')">
                <i class="fas fa-user"></i> Individual
              </button>
              <button type="button" class="tab-btn" onclick="switchTab('organization')">
                <i class="fas fa-building"></i> Organization/Company
              </button>
            </div>
            
            <!-- Individual Tab -->
            <div class="tab-content active" id="individual-tab">
              <input type="hidden" name="registration_type" value="individual" id="registration_type_individual">
              
              <div class="form-row">
                <div class="form-group">
                  <label for="full_name">Full Name *</label>
                  <input type="text" id="full_name" name="full_name" required placeholder="Enter your full name">
                </div>
                
                <div class="form-group">
                  <label for="location">Location *</label>
                  <input type="text" id="location" name="location" required placeholder="Enter your location">
                </div>
              </div>
              
              <div class="form-row">
                <div class="form-group">
                  <label for="age">Age *</label>
                  <input type="number" id="age" name="age" required min="1" max="120" placeholder="Enter your age">
                </div>
                
                <div class="form-group">
                  <label for="rcy_status">RCY Status *</label>
                  <select id="rcy_status" name="rcy_status" required>
                    <option value="">Select status</option>
                    <option value="Non-RCY">Non-RCY</option>
                    <option value="RCY Member">RCY Member</option>
                    <option value="RCY Volunteer">RCY Volunteer</option>
                    <option value="RCY Officer">RCY Officer</option>
                  </select>
                </div>
              </div>
              
              <div class="form-row">
                <div class="form-group">
                  <label for="training_type_individual">Training Type *</label>
                  <input type="text" id="training_type_individual" name="training_type" required readonly>
                </div>
                
                <div class="form-group">
                  <label for="training_date_individual">Training Date *</label>
                  <input type="date" id="training_date_individual" name="training_date" required readonly>
                </div>
              </div>
            </div>
            
            <!-- Organization Tab -->
            <div class="tab-content" id="organization-tab">
              <input type="hidden" name="registration_type" value="organization" id="registration_type_organization">
              
              <div class="form-group">
                <label for="organization_name">Organization/Company Name *</label>
                <input type="text" id="organization_name" name="organization_name" placeholder="Enter organization/company name">
              </div>
              
              <div class="form-row">
                <div class="form-group">
                  <label for="training_type_org">Training Type *</label>
                  <input type="text" id="training_type_org" name="training_type" readonly>
                </div>
                
                <div class="form-group">
                  <label for="training_date_org">Training Date *</label>
                  <input type="date" id="training_date_org" name="training_date" readonly>
                </div>
              </div>
              
              <div class="form-group">
                <label for="pax_count">Number of Participants *</label>
                <input type="number" id="pax_count" name="pax_count" min="1" placeholder="Enter number of participants">
              </div>
            </div>
          </div>

          <!-- Payment Section -->
          <div class="payment-section" id="paymentSection" style="display: none;">
            <div class="payment-header">
              <i class="fas fa-credit-card"></i>
              <h3>Payment Information</h3>
            </div>

            <!-- Fee Summary -->
            <div class="fee-summary">
              <h4><i class="fas fa-calculator"></i> Fee Summary</h4>
              <div class="fee-breakdown">
                <div class="fee-item">
                  <span class="fee-label">Training Fee:</span>
                  <span class="fee-amount" id="trainingFeeAmount">₱0.00</span>
                </div>
                <div class="fee-item">
                  <span class="fee-label">Total Amount:</span>
                  <span class="fee-amount total" id="totalAmountDisplay">₱0.00</span>
                </div>
              </div>
            </div>

            <!-- Payment Methods -->
            <div class="payment-methods">
              <h4><i class="fas fa-money-check-alt"></i> Payment Method</h4>
              <div class="payment-options">
                <div class="payment-option">
                  <input type="radio" name="payment_method" value="bank_transfer" id="bank_transfer">
                  <div class="payment-card">
                    <div class="payment-icon bank">
                      <i class="fas fa-university"></i>
                    </div>
                    <div class="payment-details">
                      <div class="payment-name">Bank Transfer</div>
                      <div class="payment-description">Transfer to PRC official bank account</div>
                    </div>
                  </div>
                </div>

                <div class="payment-option">
                  <input type="radio" name="payment_method" value="gcash" id="gcash">
                  <div class="payment-card">
                    <div class="payment-icon gcash">
                      <i class="fas fa-mobile-alt"></i>
                    </div>
                    <div class="payment-details">
                      <div class="payment-name">GCash</div>
                      <div class="payment-description">Send money via GCash</div>
                    </div>
                  </div>
                </div>

                <div class="payment-option">
                  <input type="radio" name="payment_method" value="paymaya" id="paymaya">
                  <div class="payment-card">
                    <div class="payment-icon paymaya">
                      <i class="fas fa-mobile-alt"></i>
                    </div>
                    <div class="payment-details">
                      <div class="payment-name">PayMaya</div>
                      <div class="payment-description">Send money via PayMaya</div>
                    </div>
                  </div>
                </div>

                <div class="payment-option">
                  <input type="radio" name="payment_method" value="cash" id="cash">
                  <div class="payment-card">
                    <div class="payment-icon cash">
                      <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="payment-details">
                      <div class="payment-name">Cash Payment</div>
                      <div class="payment-description">Pay at PRC office</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Payment Details Forms -->
            <div class="payment-details-container">
              <!-- Bank Transfer Form -->
              <div class="payment-form" id="bank_transfer_form">
                <h5><i class="fas fa-university"></i> Bank Transfer Details</h5>
                <div class="bank-details">
                  <div class="bank-info">
                    <div class="bank-field">
                      <label>Bank Name</label>
                      <span>BDO Unibank</span>
                    </div>
                    <div class="bank-field">
                      <label>Account Number</label>
                      <span>1234-5678-9012</span>
                    </div>
                    <div class="bank-field">
                      <label>Account Name</label>
                      <span>Philippine Red Cross - Tacloban Chapter</span>
                    </div>
                  </div>
                </div>
                <div class="form-group">
                  <label for="bank_reference">Reference Number *</label>
                  <input type="text" id="bank_reference" name="payment_reference" placeholder="Enter transfer reference number">
                </div>
              </div>

              <!-- GCash Form -->
              <div class="payment-form" id="gcash_form">
                <h5><i class="fas fa-mobile-alt"></i> GCash Payment Details</h5>
                <div class="bank-details">
                  <div class="bank-info">
                    <div class="bank-field">
                      <label>GCash Number</label>
                      <span>+63 917 123 4567</span>
                    </div>
                    <div class="bank-field">
                      <label>Account Name</label>
                      <span>Philippine Red Cross Tacloban</span>
                    </div>
                  </div>
                </div>
                <div class="form-row">
                  <div class="form-group">
                    <label for="gcash_reference">Reference Number *</label>
                    <input type="text" id="gcash_reference" name="payment_reference" placeholder="Enter GCash reference number">
                  </div>
                  <div class="form-group">
                    <label for="gcash_sender">Your GCash Number *</label>
                    <input type="tel" id="gcash_sender" name="payment_account_number" placeholder="+63 9XX XXX XXXX">
                  </div>
                </div>
              </div>

              <!-- PayMaya Form -->
              <div class="payment-form" id="paymaya_form">
                <h5><i class="fas fa-mobile-alt"></i> PayMaya Payment Details</h5>
                <div class="bank-details">
                  <div class="bank-info">
                    <div class="bank-field">
                      <label>PayMaya Number</label>
                      <span>+63 917 123 4567</span>
                    </div>
                    <div class="bank-field">
                      <label>Account Name</label>
                      <span>Philippine Red Cross Tacloban</span>
                    </div>
                  </div>
                </div>
                <div class="form-row">
                  <div class="form-group">
                    <label for="paymaya_reference">Reference Number *</label>
                    <input type="text" id="paymaya_reference" name="payment_reference" placeholder="Enter PayMaya reference number">
                  </div>
                  <div class="form-group">
                    <label for="paymaya_sender">Your PayMaya Number *</label>
                    <input type="tel" id="paymaya_sender" name="payment_account_number" placeholder="+63 9XX XXX XXXX">
                  </div>
                </div>
              </div>

              <!-- Cash Payment Form -->
              <div class="payment-form" id="cash_form">
                <h5><i class="fas fa-money-bill-wave"></i> Cash Payment Details</h5>
                <div class="payment-note">
                  <i class="fas fa-info-circle"></i>
                  <div class="payment-note-content">
                    <strong>Important Note:</strong>
                    <p>You have selected cash payment. Please visit our office during business hours to complete your payment.</p>
                  </div>
                </div>
                <div class="bank-details">
                  <div class="bank-info">
                    <div class="bank-field">
                      <label>Office Address</label>
                      <span>Philippine Red Cross Tacloban Chapter<br>123 Remedios Street, Tacloban City</span>
                    </div>
                    <div class="bank-field">
                      <label>Business Hours</label>
                      <span>Monday - Friday: 8:00 AM - 5:00 PM<br>Saturday: 8:00 AM - 12:00 PM</span>
                    </div>
                  </div>
                </div>
                <div class="form-group">
                  <label for="cash_name">Contact Person Name *</label>
                  <input type="text" id="cash_name" name="payment_account_name" placeholder="Who should we expect for payment?">
                </div>
              </div>

              <!-- Payment Receipt Upload -->
              <div class="receipt-upload" id="receiptUpload" style="display: none;">
                <input type="file" name="payment_receipt" id="payment_receipt" accept=".jpg,.jpeg,.png,.pdf">
                <div class="receipt-upload-content">
                  <i class="fas fa-receipt"></i>
                  <div class="upload-text">Upload Payment Receipt</div>
                  <div class="upload-note">Upload a clear photo or scan of your payment receipt/screenshot</div>
                  <div class="upload-note">Accepted formats: JPG, PNG, PDF (Max 5MB)</div>
                </div>
              </div>
            </div>

            <!-- Payment Summary -->
            <div class="payment-summary" id="paymentSummary" style="display: none;">
              <h4><i class="fas fa-file-invoice-dollar"></i> Payment Summary</h4>
              <div class="summary-item">
                <span class="summary-label">Payment Method:</span>
                <span class="summary-value" id="selectedPaymentMethod">-</span>
              </div>
              <div class="summary-item">
                <span class="summary-label">Training Fee:</span>
                <span class="summary-value" id="summaryTrainingFee">₱0.00</span>
              </div>
              <div class="summary-item">
                <span class="summary-label">Total Amount:</span>
                <span class="summary-value total" id="summaryTotalAmount">₱0.00</span>
              </div>
            </div>
          </div>
          
          <!-- Common Fields -->
          <div class="form-row">
            <div class="form-group">
              <label for="valid_id">Valid ID *</label>
              <div class="file-upload-container">
                <input type="file" id="valid_id" name="valid_id" required accept=".jpg,.jpeg,.png,.pdf">
                <div class="file-upload-info">
                  <i class="fas fa-id-card"></i>
                  <span>Upload a clear photo of your valid ID</span>
                  <small>Accepted formats: JPG, PNG, PDF (Max 5MB)</small>
                </div>
              </div>
            </div>
            
            <div class="form-group">
              <label for="requirements">Requirements & Documents</label>
              <div class="file-upload-container">
                <input type="file" id="requirements" name="requirements" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                <div class="file-upload-info">
                  <i class="fas fa-file-upload"></i>
                  <span>Upload requirements/supporting documents</span>
                  <small>Accepted formats: JPG, PNG, PDF, DOC, DOCX (Max 10MB)</small>
                </div>
              </div>
            </div>
          </div>
          
          <div class="form-notice">
            <i class="fas fa-info-circle"></i>
            <p>By registering, you agree to provide accurate information. Your documents will be securely stored and used only for training registration purposes.</p>
          </div>
          
          <button type="submit" class="btn-submit">
            <i class="fas fa-user-plus"></i> Register for Training
          </button>
        </form>
      </div>
    </div>
  </div>
  

  <script src="js/general-ui.js?v=<?php echo time(); ?>"></script>
  <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>

  <?php include 'chat_widget.php'; ?>
  <?php include 'floating_notification_widget.php'; ?>
  <script> 
// Store training sessions and user registrations in global scope
window.calendarTrainingsData = <?php echo json_encode($calendarTrainings); ?>;
window.userTrainingRegistrations = <?php echo json_encode($userRegistrationsJS); ?>;
function showVenueModal(sessionTitle, venueText) {
    // Remove any existing venue modal
    const existingModal = document.querySelector('.venue-modal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Create modal
    const modal = document.createElement('div');
    modal.className = 'venue-modal';
    modal.innerHTML = `
        <div class="venue-modal-content">
            <div class="venue-modal-header">
                <h3>
                    <i class="fas fa-map-marker-alt"></i>
                    ${escapeHtml(sessionTitle)} - Training Venue
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
    
    // Add to body
    document.body.appendChild(modal);
    
    // Show modal
    setTimeout(() => {
        modal.classList.add('active');
    }, 10);
    
    // Add click outside to close
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeVenueModal();
        }
    });
    
    // Store reference
    window.currentVenueModal = modal;
    
    // Prevent body scroll
    document.body.style.overflow = 'hidden';
}

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
// Enhanced date formatting function that handles timezone properly
function formatDateToString(date) {
    if (typeof date === 'string') {
        if (date.match(/^\d{4}-\d{2}-\d{2}$/)) {
            return date;
        }
        date = new Date(date + 'T12:00:00');
    }
    
    if (!(date instanceof Date) || isNaN(date)) {
        console.error('Invalid date passed to formatDateToString:', date);
        return '';
    }
    
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

// Create dates in local timezone consistently
function createLocalDate(dateString) {
    if (typeof dateString === 'string' && dateString.match(/^\d{4}-\d{2}-\d{2}$/)) {
        const [year, month, day] = dateString.split('-').map(Number);
        return new Date(year, month - 1, day);
    }
    return new Date(dateString);
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Function to check if user is registered for any training on a specific date
function checkUserTrainingRegistrationForDate(dateStr) {
    if (typeof window.userTrainingRegistrations === 'undefined') return false;
    
    const targetDate = createLocalDate(dateStr);
    
    return window.userTrainingRegistrations.some(reg => {
        const training = window.calendarTrainingsData.find(t => t.session_id === reg.session_id);
        if (training) {
            const trainingStart = createLocalDate(training.session_date);
            const trainingEnd = createLocalDate(training.session_end_date || training.session_date);
            
            const targetTime = targetDate.getTime();
            const startTime = trainingStart.getTime();
            const endTime = trainingEnd.getTime();
            
            return targetTime >= startTime && targetTime <= endTime;
        }
        return false;
    });
}

// Function to check if user is registered for a specific training
function checkUserTrainingRegistrationForSession(sessionId) {
    if (typeof window.userTrainingRegistrations === 'undefined') return false;
    return window.userTrainingRegistrations.some(reg => reg.session_id === parseInt(sessionId));
}

// Get trainings for a specific date
function getTrainingsForDate(dateStr) {
    if (typeof window.calendarTrainingsData === 'undefined') return [];
    
    const targetDate = createLocalDate(dateStr);
    
    return window.calendarTrainingsData.filter(training => {
        const trainingStart = createLocalDate(training.session_date);
        const trainingEnd = createLocalDate(training.session_end_date || training.session_date);
        
        const targetTime = targetDate.getTime();
        const startTime = trainingStart.getTime();
        const endTime = trainingEnd.getTime();
        
        return targetTime >= startTime && targetTime <= endTime;
    });
}

// Get training service color
function getTrainingServiceColor(service) {
    const serviceColors = {
        'Health Service': '#4CAF50',
        'Safety Service': '#FF5722',
        'Welfare Service': '#2196F3',
        'Disaster Management Service': '#FF9800',
        'Red Cross Youth': '#9C27B0'
    };
    return serviceColors[service] || '#607D8B';
}

// Enhanced training calendar generation with multi-day support
function generateTrainingCalendar() {
    const container = document.getElementById('trainingCalendarContainer');
    if (!container) return;
    
    const today = new Date();
    const currentMonth = today.getMonth();
    const currentYear = today.getFullYear();
    
    let calendarHTML = '';
    
    for (let monthOffset = 0; monthOffset < 3; monthOffset++) {
        const month = (currentMonth + monthOffset) % 12;
        const year = currentYear + Math.floor((currentMonth + monthOffset) / 12);
        
        calendarHTML += generateMonthCalendar(year, month, today);
    }
    
    container.innerHTML = calendarHTML;
}

// Enhanced month calendar generation with multi-day training spans
function generateMonthCalendar(year, month, today) {
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];
    
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const todayStr = formatDateToString(today);
    
    let html = `
        <div class="month-calendar">
            <div class="month-header">
                <h3>${monthNames[month]} ${year}</h3>
            </div>
            <div class="calendar-grid">
                <div class="day-header">Sun</div>
                <div class="day-header">Mon</div>
                <div class="day-header">Tue</div>
                <div class="day-header">Wed</div>
                <div class="day-header">Thu</div>
                <div class="day-header">Fri</div>
                <div class="day-header">Sat</div>
    `;
    
    // Fill in the days
    for (let i = 0; i < firstDay; i++) {
        html += '<div class="day-cell empty"></div>';
    }
    
    for (let day = 1; day <= daysInMonth; day++) {
        const dateStr = formatDateToString(new Date(year, month, day));
        const dateTrainings = getTrainingsForDate(dateStr);
        const isRegistered = checkUserTrainingRegistrationForDate(dateStr);
        const isToday = dateStr === todayStr;
        
        let dayClass = 'day-cell';
        let dayContent = `<span class="day-number">${day}</span>`;
        
        if (isToday) dayClass += ' today';
        if (dateTrainings.length > 0) {
            dayClass += ' has-events';
            if (isRegistered) dayClass += ' has-registered-event';
            
            dayContent += '<div class="event-indicators">';
            dateTrainings.slice(0, 3).forEach(training => {
                const isUserRegistered = window.userTrainingRegistrations.some(reg => reg.session_id === training.session_id);
                dayContent += `<div class="event-indicator ${isUserRegistered ? 'registered' : ''}" 
                                  style="background-color: ${getTrainingServiceColor(training.major_service)}"
                                  title="${training.title}"></div>`;
            });
            if (dateTrainings.length > 3) {
                dayContent += `<div class="event-count">+${dateTrainings.length - 3}</div>`;
            }
            dayContent += '</div>';
        }
        
        html += `<div class="${dayClass}" data-date="${dateStr}" 
                     onmouseover="showTrainingTooltip(event, '${dateStr}')"
                     onmouseout="hideTrainingTooltip()">
                     ${dayContent}
                 </div>`;
    }
    
    // Fill remaining cells
    const totalCells = Math.ceil((daysInMonth + firstDay) / 7) * 7;
    const remainingCells = totalCells - (daysInMonth + firstDay);
    for (let i = 0; i < remainingCells; i++) {
        html += '<div class="day-cell empty"></div>';
    }
    
    html += '</div></div>';
    return html;
}

// Large calendar modal functions
function openCalendarModal() {
    const modal = document.getElementById('calendarModal');
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        currentCalendarMonth = new Date().getMonth();
        currentCalendarYear = new Date().getFullYear();
        updateLargeCalendar();
    }
}

function closeCalendarModal() {
    const modal = document.getElementById('calendarModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

function changeMonth(direction) {
    currentCalendarMonth += direction;
    
    if (currentCalendarMonth > 11) {
        currentCalendarMonth = 0;
        currentCalendarYear++;
    } else if (currentCalendarMonth < 0) {
        currentCalendarMonth = 11;
        currentCalendarYear--;
    }
    
    updateLargeCalendar();
}

function updateLargeCalendar() {
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];
    
    const currentMonthYear = document.getElementById('currentMonthYear');
    if (currentMonthYear) {
        currentMonthYear.textContent = `${monthNames[currentCalendarMonth]} ${currentCalendarYear}`;
    }
    
    const calendarContainer = document.getElementById('largeCalendarContainer');
    if (calendarContainer) {
        calendarContainer.innerHTML = generateLargeCalendarGrid(currentCalendarYear, currentCalendarMonth);
    }
}

// Enhanced large calendar generation for modal with multi-day support
function generateLargeCalendarGrid(year, month) {
    const today = new Date();
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const todayStr = formatDateToString(today);
    
    let html = `
        <div class="large-calendar-grid">
            <div class="calendar-weekdays">
                <div class="weekday">Sun</div>
                <div class="weekday">Mon</div>
                <div class="weekday">Tue</div>
                <div class="weekday">Wed</div>
                <div class="weekday">Thu</div>
                <div class="weekday">Fri</div>
                <div class="weekday">Sat</div>
            </div>
            <div class="calendar-days">
    `;
    
    // Fill in the days
    for (let i = 0; i < firstDay; i++) {
        html += '<div class="calendar-day empty"></div>';
    }
    
    for (let day = 1; day <= daysInMonth; day++) {
        const dateStr = formatDateToString(new Date(year, month, day));
        const dateTrainings = getTrainingsForDate(dateStr);
        const isRegistered = checkUserTrainingRegistrationForDate(dateStr);
        const isToday = dateStr === todayStr;
        const cellDate = new Date(dateStr + 'T00:00:00');
        const isPast = cellDate < today && !isToday;
        
        let dayClass = 'calendar-day';
        
        if (dateTrainings.length > 0) {
            dayClass += ' has-events';
            if (isRegistered) dayClass += ' has-registered-event';
        }
        
        if (isToday) dayClass += ' today';
        if (isPast) dayClass += ' past';
        
        html += `<div class="${dayClass}" data-date="${dateStr}" 
                    onmouseover="showTrainingTooltip(event, '${dateStr}')"
                    onmouseout="hideTrainingTooltip()">
            <div class="day-number">${day}</div>`;
        
        if (dateTrainings.length > 0) {
            html += '<div class="event-display">';
            
            dateTrainings.slice(0, 2).forEach(training => {
                const isUserRegistered = window.userTrainingRegistrations.some(reg => reg.session_id === training.session_id);
                const trainingClass = `event-bar ${isUserRegistered ? 'registered' : ''}`;
                
                html += `<div class="${trainingClass}" 
                           style="--event-color: ${getTrainingServiceColor(training.major_service)};"
                           title="${escapeHtml(training.title)}${isUserRegistered ? ' (Registered)' : ''}">
                           ${truncateText(training.title, 12)}
                         </div>`;
            });
            
            if (dateTrainings.length > 2) {
                html += '<div class="event-dots">';
                dateTrainings.slice(2, 5).forEach(training => {
                    const isUserRegistered = window.userTrainingRegistrations.some(reg => reg.session_id === training.session_id);
                    const dotClass = `event-dot ${isUserRegistered ? 'registered' : ''}`;
                    html += `<div class="${dotClass}" 
                               style="background-color: ${getTrainingServiceColor(training.major_service)};"
                               title="${escapeHtml(training.title)}${isUserRegistered ? ' (Registered)' : ''}"></div>`;
                });
                
                if (dateTrainings.length > 5) {
                    html += `<div class="event-count">+${dateTrainings.length - 5}</div>`;
                }
                html += '</div>';
            }
            
            html += '</div>';
            
            if (isRegistered) {
                html += '<div class="registration-indicator">✓</div>';
            }
        }
        
        html += '</div>';
    }

    // Fill remaining cells
    const totalCells = Math.ceil((daysInMonth + firstDay) / 7) * 7;
    const remainingCells = totalCells - (daysInMonth + firstDay);
    for (let i = 0; i < remainingCells; i++) {
        html += '<div class="calendar-day empty"></div>';
    }
    
    html += '</div></div>';
    return html;
}

function truncateText(text, maxLength) {
    if (!text) return '';
    return text.length > maxLength ? text.substring(0, maxLength) + '...' : text;
}

// Enhanced training tooltip with multi-day training info
function showTrainingTooltip(event, dateStr) {
    const tooltip = document.getElementById('trainingTooltip');
    if (!tooltip || typeof window.calendarTrainingsData === 'undefined') return;
    
    const dayTrainings = getTrainingsForDate(dateStr);
    if (dayTrainings.length === 0) return;
    
    let tooltipContent = '';
    dayTrainings.forEach(trainingData => {
        const isRegistered = checkUserTrainingRegistrationForSession(trainingData.session_id);
        const registrationStatus = isRegistered ? 
            '<div class="tooltip-event-status registered">✓ Registered</div>' : 
            '<div class="tooltip-event-status available">Available</div>';
        
        const startDate = new Date(trainingData.session_date);
        const endDate = new Date(trainingData.session_end_date || trainingData.session_date);
        const durationDays = trainingData.duration_days || 1;
        
        const durationText = durationDays > 1 ? 
            `📅 ${durationDays} days (${formatDateForTooltip(startDate)} - ${formatDateForTooltip(endDate)})` :
            `📅 ${formatDateForTooltip(startDate)}`;
        
        const timeText = `🕐 ${formatTime(trainingData.start_time)} - ${formatTime(trainingData.end_time)}`;
        
        tooltipContent += `
            <div class="tooltip-event ${isRegistered ? 'registered' : ''}">
                <div class="tooltip-event-title">${escapeHtml(trainingData.title)}</div>
                <div class="tooltip-event-duration">${durationText}</div>
                <div class="tooltip-event-time">${timeText}</div>
                <div class="tooltip-event-location">📍 ${escapeHtml(trainingData.venue)}</div>
                <div class="tooltip-event-capacity">👥 ${trainingData.registrations_count || 0}/${trainingData.capacity || '∞'}</div>
                ${trainingData.fee > 0 ? `<div class="tooltip-event-fee">💰 ₱${parseFloat(trainingData.fee).toFixed(2)}</div>` : '<div class="tooltip-event-fee">🆓 Free</div>'}
                <div class="tooltip-event-service">🏥 ${escapeHtml(trainingData.major_service)}</div>
                ${registrationStatus}
            </div>
        `;
    });
    
    tooltip.querySelector('.tooltip-content').innerHTML = tooltipContent;
    tooltip.style.display = 'block';
    
    const rect = event.target.getBoundingClientRect();
    tooltip.style.position = 'fixed';
    tooltip.style.left = (rect.left + window.scrollX) + 'px';
    tooltip.style.top = (rect.bottom + window.scrollY + 5) + 'px';
}

function hideTrainingTooltip() {
    const tooltip = document.getElementById('trainingTooltip');
    if (tooltip) {
        tooltip.style.display = 'none';
    }
}

function formatDateForTooltip(date) {
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function formatTime(timeString) {
    if (!timeString) return '';
    const time = new Date('1970-01-01T' + timeString + 'Z');
    return time.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    });
}

// Enhanced openRegisterModal function with multi-day support
function openRegisterModal(session) {
    currentSession = session;
    document.getElementById('sessionId').value = session.session_id;
    document.getElementById('modalTitle').textContent = 'Register for ' + session.title;
    
    const trainingInfo = document.getElementById('trainingInfo');
    const sessionStartDate = createLocalDate(session.session_date);
    const sessionEndDate = createLocalDate(session.session_end_date || session.session_date);
    const durationDays = session.duration_days || 1;
    
    // Format date display based on duration
    let dateDisplay;
    if (durationDays === 1) {
        dateDisplay = sessionStartDate.toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'});
    } else {
        const startStr = sessionStartDate.toLocaleDateString('en-US', {month: 'short', day: 'numeric'});
        const endStr = sessionEndDate.toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'});
        dateDisplay = `${startStr} - ${endStr} (${durationDays} days)`;
    }
    
    trainingInfo.innerHTML = `
        <div class="event-details">
            <h3>${escapeHtml(session.title)}</h3>
            <p><i class="fas fa-calendar"></i> ${dateDisplay}</p>
            <p><i class="fas fa-clock"></i> ${formatTime(session.start_time)} - ${formatTime(session.end_time)}</p>
            <p><i class="fas fa-map-marker-alt"></i> ${escapeHtml(session.venue)}</p>
            <p><i class="fas fa-tag"></i> ${escapeHtml(session.major_service)}</p>
            ${session.fee > 0 ? `<p><i class="fas fa-money-bill"></i> Fee: ₱${parseFloat(session.fee).toFixed(2)}</p>` : '<p><i class="fas fa-gift"></i> Free Training</p>'}
            <p><i class="fas fa-users"></i> Available Slots: ${session.capacity > 0 ? (session.capacity - session.registered_count) : 'Unlimited'}</p>
        </div>
    `;
    
    // Auto-fill training type and date for both tabs
    document.getElementById('training_type_individual').value = session.title;
    document.getElementById('training_date_individual').value = session.session_date;
    document.getElementById('training_type_org').value = session.title;
    document.getElementById('training_date_org').value = session.session_date;
    
    // Handle payment section
    updatePaymentSection(session.fee);
    
    // Reset form to individual tab
    switchTab('individual');
    
    document.getElementById('registerModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function updatePaymentSection(fee) {
    const paymentSection = document.getElementById('paymentSection');
    const trainingFeeAmount = document.getElementById('trainingFeeAmount');
    const totalAmountDisplay = document.getElementById('totalAmountDisplay');
    const hiddenPaymentAmount = document.getElementById('hiddenPaymentAmount');

    if (fee > 0) {
        paymentSection.style.display = 'block';
        const formattedFee = '₱' + parseFloat(fee).toFixed(2);
        trainingFeeAmount.textContent = formattedFee;
        totalAmountDisplay.textContent = formattedFee;
        hiddenPaymentAmount.value = fee;
        
        // Update summary
        document.getElementById('summaryTrainingFee').textContent = formattedFee;
        document.getElementById('summaryTotalAmount').textContent = formattedFee;
        
        // Make payment method required
        const paymentModeInputs = document.querySelectorAll('input[name="payment_method"]');
        paymentModeInputs.forEach(input => input.required = true);
    } else {
        paymentSection.style.display = 'none';
        hiddenPaymentAmount.value = '0';
        
        // Make payment method not required for free training
        const paymentModeInputs = document.querySelectorAll('input[name="payment_method"]');
        paymentModeInputs.forEach(input => input.required = false);
    }
}

function closeRegisterModal() {
    document.getElementById('registerModal').classList.remove('active');
    document.body.style.overflow = '';
    
    currentSession = null;
    
    const form = document.getElementById('registerForm');
    if (form) {
        form.reset();
        switchTab('individual');
        resetFileUploads();
        resetPaymentForms();
        
        const submitBtn = form.querySelector('.btn-submit');
        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fas fa-user-plus"></i> Register for Training';
            submitBtn.disabled = false;
        }
    }
}

function switchTab(tabName) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelector(`[onclick="switchTab('${tabName}')"]`).classList.add('active');
    
    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
    document.getElementById(`${tabName}-tab`).classList.add('active');
    
    if (tabName === 'individual') {
        document.getElementById('registration_type_individual').disabled = false;
        document.getElementById('registration_type_organization').disabled = true;
        setRequiredFields(true, false);
        
        document.getElementById('organization_name').value = '';
        document.getElementById('pax_count').value = '';
        
        if (currentSession) {
            document.getElementById('training_type_individual').value = currentSession.title;
            document.getElementById('training_date_individual').value = currentSession.session_date;
        }
    } else {
        document.getElementById('registration_type_individual').disabled = true;
        document.getElementById('registration_type_organization').disabled = false;
        setRequiredFields(false, true);
        
        document.getElementById('full_name').value = '';
        document.getElementById('location').value = '';
        document.getElementById('age').value = '';
        document.getElementById('rcy_status').value = '';
        
        if (currentSession) {
            document.getElementById('training_type_org').value = currentSession.title;
            document.getElementById('training_date_org').value = currentSession.session_date;
        }
    }
}

function setRequiredFields(individual, organization) {
    document.getElementById('full_name').required = individual;
    document.getElementById('location').required = individual;
    document.getElementById('age').required = individual;
    document.getElementById('rcy_status').required = individual;
    
    document.getElementById('organization_name').required = organization;
    document.getElementById('pax_count').required = organization;
}

function filterService(service) {
    const urlParams = new URLSearchParams(window.location.search);
    if (service === 'all') {
        urlParams.delete('service');
    } else {
        urlParams.set('service', service);
    }
    
    const currentSearch = urlParams.get('search');
    if (currentSearch) {
        urlParams.set('search', currentSearch);
    }
    
    window.location.search = urlParams.toString();
}

// Payment handling functions
function handlePaymentMethodChange(selectedMethod) {
    document.querySelectorAll('.payment-form').forEach(form => {
        form.classList.remove('active');
        form.style.display = 'none';
    });
    
    const selectedForm = document.getElementById(selectedMethod + '_form');
    if (selectedForm) {
        selectedForm.style.display = 'block';
        selectedForm.classList.add('active');
    }
    
    const receiptUpload = document.getElementById('receiptUpload');
    const paymentSummary = document.getElementById('paymentSummary');
    
    if (selectedMethod === 'cash') {
        if (receiptUpload) receiptUpload.style.display = 'none';
        const receiptInput = document.getElementById('payment_receipt');
        if (receiptInput) receiptInput.required = false;
    } else {
        if (receiptUpload) receiptUpload.style.display = 'block';
        const receiptInput = document.getElementById('payment_receipt');
        if (receiptInput) receiptInput.required = true;
    }
    
    if (paymentSummary) paymentSummary.style.display = 'block';
    const selectedMethodSpan = document.getElementById('selectedPaymentMethod');
    if (selectedMethodSpan) selectedMethodSpan.textContent = getPaymentMethodName(selectedMethod);
}

function getPaymentMethodName(method) {
    const names = {
        'bank_transfer': 'Bank Transfer',
        'gcash': 'GCash',
        'paymaya': 'PayMaya',
        'cash': 'Cash Payment'
    };
    return names[method] || method;
}

function resetPaymentForms() {
    document.querySelectorAll('.payment-form').forEach(form => {
        form.style.display = 'none';
        form.classList.remove('active');
    });
    
    const receiptUpload = document.getElementById('receiptUpload');
    const paymentSummary = document.getElementById('paymentSummary');
    if (receiptUpload) receiptUpload.style.display = 'none';
    if (paymentSummary) paymentSummary.style.display = 'none';
    
    document.querySelectorAll('input[name="payment_method"]').forEach(input => {
        input.checked = false;
    });
    
    document.querySelectorAll('.payment-form input').forEach(input => {
        input.value = '';
        input.removeAttribute('required');
    });
}

function resetFileUploads() {
    document.querySelectorAll('.file-upload-container, .receipt-upload').forEach(container => {
        container.classList.remove('has-file');
    });
}

function handleFileUpload(inputElement) {
    const container = inputElement.closest('.file-upload-container') || inputElement.closest('.receipt-upload');
    const info = container.querySelector('.file-upload-info span') || container.querySelector('.upload-text');
    
    if (inputElement.files && inputElement.files[0]) {
        const file = inputElement.files[0];
        let maxSize = inputElement.name === 'valid_id' || inputElement.name === 'payment_receipt' ? 5 * 1024 * 1024 : 10 * 1024 * 1024;
        
        if (file.size > maxSize) {
            alert(`File size too large. Maximum allowed: ${maxSize / (1024 * 1024)}MB`);
            inputElement.value = '';
            return;
        }
        
        const allowedTypes = inputElement.name === 'valid_id' || inputElement.name === 'payment_receipt' ? 
            ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'] :
            ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        
        if (!allowedTypes.includes(file.type)) {
            alert('Invalid file type. Please upload a supported file format.');
            inputElement.value = '';
            return;
        }
        
        container.classList.add('has-file');
        info.textContent = `Selected: ${file.name}`;
    } else {
        container.classList.remove('has-file');
        if (inputElement.name === 'valid_id') {
            info.textContent = 'Upload a clear photo of your valid ID';
        } else if (inputElement.name === 'payment_receipt') {
            info.textContent = 'Upload Payment Receipt';
        } else {
            info.textContent = 'Upload requirements/supporting documents';
        }
    }
}

function validateForm(form) {
    const activeTab = document.querySelector('.tab-content.active');
    const isIndividual = activeTab && activeTab.id === 'individual-tab';
    
    if (isIndividual) {
        const fullName = form.querySelector('#full_name');
        const location = form.querySelector('#location');
        const age = form.querySelector('#age');
        const rcyStatus = form.querySelector('#rcy_status');
        
        if (!fullName || !fullName.value.trim()) {
            alert('Please enter your full name.');
            if (fullName) fullName.focus();
            return false;
        }
        
        if (!location || !location.value.trim()) {
            alert('Please enter your location.');
            if (location) location.focus();
            return false;
        }
        
        if (!age || !age.value || age.value < 1 || age.value > 120) {
            alert('Please enter a valid age (1-120).');
            if (age) age.focus();
            return false;
        }
        
        if (!rcyStatus || !rcyStatus.value) {
            alert('Please select your RCY status.');
            if (rcyStatus) rcyStatus.focus();
            return false;
        }
    } else {
        const orgName = form.querySelector('#organization_name');
        const paxCount = form.querySelector('#pax_count');
        
        if (!orgName || !orgName.value.trim()) {
            alert('Please enter the organization/company name.');
            if (orgName) orgName.focus();
            return false;
        }
        
        if (!paxCount || !paxCount.value || paxCount.value < 1) {
            alert('Please enter the number of participants.');
            if (paxCount) paxCount.focus();
            return false;
        }
    }
    
    // Check payment method for paid sessions
    if (currentSession && parseFloat(currentSession.fee) > 0) {
        const paymentMode = form.querySelector('input[name="payment_method"]:checked');
        if (!paymentMode) {
            alert('Please select a payment method.');
            return false;
        }
        
        // Check receipt upload for non-cash payments
        if (paymentMode.value !== 'cash') {
            const receiptInput = form.querySelector('#payment_receipt');
            if (!receiptInput || !receiptInput.files || !receiptInput.files[0]) {
                alert('Please upload your payment receipt.');
                if (receiptInput) receiptInput.focus();
                return false;
            }
        }
    }
    
    // Check valid ID upload
    const validId = form.querySelector('#valid_id');
    if (!validId || !validId.files || !validId.files[0]) {
        alert('Please upload a valid ID.');
        if (validId) validId.focus();
        return false;
    }
    
    return true;
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    generateTrainingCalendar();
    const startDateInput = document.getElementById('preferred_start_date');
    const endDateInput = document.getElementById('preferred_end_date');
    
    // Initialize payment method listeners
    const paymentMethods = document.querySelectorAll('input[name="payment_method"]');
    paymentMethods.forEach(method => {
        method.addEventListener('change', function() {
            handlePaymentMethodChange(this.value);
        });
    });
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && window.currentVenueModal) {
            closeVenueModal();
        }
    });

    // Enhanced date change handlers for Training Request Modal
    if (startDateInput) {
        startDateInput.addEventListener('change', function() {
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
            updateRequestDatePreview();
        });
    }
    
    if (endDateInput) {
        endDateInput.addEventListener('change', function() {
            updateRequestDatePreview();
        });
    }
    
    // Phone number formatting and validation for Training Request Modal
    const phoneInput = document.getElementById('contact_number');
    if (phoneInput) {
        phoneInput.addEventListener('input', function() {
            let value = this.value.replace(/[^\d\+\-\s\(\)]/g, '');
            
            // Auto-format common patterns
            if (value.match(/^09\d{9}$/)) {
                // Format 09XXXXXXXXX to 09XX XXX XXXX
                value = value.replace(/(\d{4})(\d{3})(\d{4})/, '$1 $2 $3');
            } else if (value.match(/^0\d{2}\d{7}$/)) {
                // Format 0XXXXXXXXXX to 0XX XXX XXXX
                value = value.replace(/(\d{3})(\d{3})(\d{4})/, '$1 $2 $3');
            }
            
            this.value = value;
        });
        
        // Clear validation on input change
        phoneInput.addEventListener('input', function() {
            const feedbackElement = document.getElementById('phoneValidationFeedback');
            if (feedbackElement) {
                feedbackElement.style.display = 'none';
            }
            this.classList.remove('valid', 'invalid');
        });
    }

    // Initialize file upload handlers
    const validIdInput = document.getElementById('valid_id');
    const requirementsInput = document.getElementById('requirements');
    const receiptInput = document.getElementById('payment_receipt');
    
    if (validIdInput) {
        validIdInput.addEventListener('change', function() {
            handleFileUpload(this);
        });
    }

    if (requirementsInput) {
        requirementsInput.addEventListener('change', function() {
            handleFileUpload(this);
        });
    }

    if (receiptInput) {
        receiptInput.addEventListener('change', function() {
            handleFileUpload(this);
        });
    }

    // Form submission handler for registration
    const registerForm = document.getElementById('registerForm');
    if (registerForm) {
        registerForm.addEventListener('submit', function(e) {
            if (!validateForm(this)) {
                e.preventDefault();
                return;
            }

            const submitBtn = this.querySelector('.btn-submit');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing Registration...';
                submitBtn.disabled = true;
            }
        });
    }

    // Form submission handler for training request
    const trainingRequestForm = document.getElementById('trainingRequestForm');
    if (trainingRequestForm) {
        trainingRequestForm.addEventListener('submit', function(e) {
            const errors = validateTrainingRequestForm();
            
            if (errors.length > 0) {
                e.preventDefault();
                alert('Please fix the following errors:\n\n' + errors.join('\n'));
                return false;
            }
            
            // Check phone validation status
            const phoneInput = document.getElementById('contact_number');
            if (phoneInput && phoneInput.classList.contains('invalid')) {
                e.preventDefault();
                alert('Please enter a valid Philippine phone number before submitting.');
                phoneInput.focus();
                return false;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('.btn-submit');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting Request...';
                submitBtn.disabled = true;
            }
            
            return true;
        });
    }

    // Initialize form state for training request
    toggleOrganizationSection();
    
    // Event listeners for dynamic sections
    const participantCountInput = document.getElementById('participant_count');
    if (participantCountInput) {
        participantCountInput.addEventListener('input', toggleOrganizationSection);
    }
    
    const trainingProgramSelect = document.getElementById('training_program');
    if (trainingProgramSelect) {
        trainingProgramSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const programDescription = document.getElementById('program_description');
            const descriptionText = programDescription.querySelector('small');
            
            if (selectedOption.dataset.description) {
                descriptionText.textContent = selectedOption.dataset.description;
                programDescription.style.display = 'block';
            } else {
                programDescription.style.display = 'none';
            }
        });
    }

    // Close modal when clicking outside
    const modal = document.getElementById('registerModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeRegisterModal();
            }
        });
    }

    // Close calendar modal when clicking outside
    const calendarModal = document.getElementById('calendarModal');
    if (calendarModal) {
        calendarModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeCalendarModal();
            }
        });
    }

    // Close training request modal when clicking outside
    const trainingRequestModal = document.getElementById('trainingRequestModal');
    if (trainingRequestModal) {
        trainingRequestModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeTrainingRequestModal();
            }
        });
    }

    // Keyboard navigation
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (calendarModal && calendarModal.classList.contains('active')) {
                closeCalendarModal();
            } else if (modal && modal.classList.contains('active')) {
                closeRegisterModal();
            } else if (trainingRequestModal && trainingRequestModal.classList.contains('active')) {
                closeTrainingRequestModal();
            }
        }
        
        if (calendarModal && calendarModal.classList.contains('active')) {
            if (e.key === 'ArrowLeft') {
                e.preventDefault();
                changeMonth(-1);
            } else if (e.key === 'ArrowRight') {
                e.preventDefault();
                changeMonth(1);
            }
        }
    });

    // Auto-dismiss alerts
    setTimeout(() => {
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
    }, 100);

    // Calendar day click handling
    document.addEventListener('click', function(e) {
        const dayCell = e.target.closest('.day-cell.has-events');
        if (dayCell) {
            const date = dayCell.getAttribute('data-date');
            if (date) {
                let dayTrainings = getTrainingsForDate(date);
                if (dayTrainings.length > 0) {
                    showDayTrainings(date, dayTrainings);
                }
            }
        }
    });

    // Initialize calendar styles
    injectMultiDayTrainingStyles();

    // Debug logs
    console.log('Training Calendar Data:', typeof window.calendarTrainingsData !== 'undefined' ? window.calendarTrainingsData : 'Not available');
    console.log('User Training Registrations:', typeof window.userTrainingRegistrations !== 'undefined' ? window.userTrainingRegistrations : 'Not available');
});

function showDayTrainings(date, trainings) {
    const trainingDate = new Date(date + 'T00:00:00');
    const formattedDate = trainingDate.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });

    alert(`Training Sessions on ${formattedDate}:\n${trainings.map(t => {
        const startDate = new Date(t.session_date);
        const endDate = new Date(t.session_end_date || t.session_date);
        const durationDays = t.duration_days || 1;
        const durationText = durationDays > 1 ? ` (${durationDays} days)` : '';
        return `• ${t.title}${durationText} - ${t.venue}`;
    }).join('\n')}`);
}
function handleFileUpload(inputElement) {
    const container = inputElement.closest('.file-upload-container');
    const info = container.querySelector('.file-upload-info span');
    
    if (inputElement.files && inputElement.files.length > 0) {
        if (inputElement.multiple) {
            // Handle multiple files
            handleMultipleFileUpload(inputElement);
        } else {
            // Handle single file
            const file = inputElement.files[0];
            const maxSize = getMaxFileSize(inputElement.name);
            
            if (file.size > maxSize) {
                alert(`File size too large. Maximum allowed: ${maxSize / (1024 * 1024)}MB`);
                inputElement.value = '';
                return;
            }
            
            if (!validateFileType(file, inputElement.accept)) {
                alert('Invalid file type. Please upload a supported file format.');
                inputElement.value = '';
                return;
            }
            
            container.classList.add('has-file');
            info.textContent = `Selected: ${file.name}`;
        }
        
        // Show participant list section for groups
        if (inputElement.name === 'participant_count') {
            toggleParticipantListSection();
        }
    } else {
        container.classList.remove('has-file');
        resetFileUploadInfo(inputElement);
    }
}

function handleMultipleFileUpload(inputElement) {
    const files = Array.from(inputElement.files);
    const listContainer = document.getElementById('additional_docs_list');
    
    // Clear existing list
    listContainer.innerHTML = '';
    
    if (files.length > 0) {
        listContainer.classList.add('has-files');
        
        files.forEach((file, index) => {
            const maxSize = 5 * 1024 * 1024; // 5MB
            
            if (file.size > maxSize) {
                alert(`File "${file.name}" is too large. Maximum allowed: 5MB`);
                return;
            }
            
            if (!validateFileType(file, inputElement.accept)) {
                alert(`File "${file.name}" has an invalid type.`);
                return;
            }
            
            const fileElement = document.createElement('div');
            fileElement.className = 'uploaded-file';
            fileElement.innerHTML = `
                <i class="fas ${getFileIconClass(file.name)}"></i>
                <span title="${file.name}">${file.name}</span>
                <button type="button" onclick="removeFile(this, ${index})" title="Remove file">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            listContainer.appendChild(fileElement);
        });
        
        const container = inputElement.closest('.file-upload-container');
        const info = container.querySelector('.file-upload-info span');
        container.classList.add('has-file');
        info.textContent = `Selected: ${files.length} file(s)`;
    } else {
        listContainer.classList.remove('has-files');
    }
}

function removeFile(button, index) {
    const fileInput = document.getElementById('additional_docs');
    const dt = new DataTransfer();
    
    // Rebuild file list without the removed file
    Array.from(fileInput.files).forEach((file, i) => {
        if (i !== index) {
            dt.items.add(file);
        }
    });
    
    fileInput.files = dt.files;
    
    // Update the display
    handleMultipleFileUpload(fileInput);
}

function getMaxFileSize(fileName) {
    if (fileName === 'valid_id_request' || fileName === 'additional_docs[]') {
        return 5 * 1024 * 1024; // 5MB
    }
    return 10 * 1024 * 1024; // 10MB
}

function validateFileType(file, acceptedTypes) {
    if (!acceptedTypes) return true;
    
    const accepted = acceptedTypes.split(',').map(type => type.trim());
    const fileExtension = '.' + file.name.split('.').pop().toLowerCase();
    
    return accepted.some(type => {
        if (type.startsWith('.')) {
            return type === fileExtension;
        } else {
            return file.type === type || file.type.startsWith(type.split('/')[0] + '/');
        }
    });
}

function getFileIconClass(filename) {
    const extension = filename.split('.').pop().toLowerCase();
    const iconMap = {
        'pdf': 'fa-file-pdf',
        'jpg': 'fa-file-image',
        'jpeg': 'fa-file-image',
        'png': 'fa-file-image',
        'gif': 'fa-file-image',
        'doc': 'fa-file-word',
        'docx': 'fa-file-word',
        'xls': 'fa-file-excel',
        'xlsx': 'fa-file-excel',
        'csv': 'fa-file-csv'
    };
    return iconMap[extension] || 'fa-file';
}

function resetFileUploadInfo(inputElement) {
    const container = inputElement.closest('.file-upload-container');
    const info = container.querySelector('.file-upload-info span');
    
    container.classList.remove('has-file');
    
    switch (inputElement.name) {
        case 'valid_id_request':
            info.textContent = 'Upload a clear photo of your valid ID';
            break;
        case 'participant_list':
            info.textContent = 'Upload list of participants (for groups of 5 or more)';
            break;
        case 'additional_docs[]':
            info.textContent = 'Upload supporting documents (certificates, authorization letters, etc.)';
            break;
    }
}

function toggleOrganizationSection() {
    const participantCount = parseInt(document.getElementById('participant_count').value) || 0;
    const orgSection = document.getElementById('organization_section');
    const participantListSection = document.getElementById('participant_list_section');
    
    if (participantCount >= 5) {
        orgSection.style.display = 'block';
        participantListSection.style.display = 'block';
        document.getElementById('participant_list').required = true;
    } else {
        orgSection.style.display = 'none';
        participantListSection.style.display = 'none';
        document.getElementById('participant_list').required = false;
        document.getElementById('organization_name').value = '';
    }
}

function toggleParticipantListSection() {
    const participantCount = parseInt(document.getElementById('participant_count').value) || 0;
    const participantListSection = document.getElementById('participant_list_section');
    
    if (participantCount >= 5) {
        participantListSection.style.display = 'block';
        document.getElementById('participant_list').required = true;
    } else {
        participantListSection.style.display = 'none';
        document.getElementById('participant_list').required = false;
    }
}

// Update training programs dropdown (enhanced version)
function updateTrainingPrograms() {
    const serviceType = document.getElementById('service_type').value;
    const programSelect = document.getElementById('training_program');
    const programDescription = document.getElementById('program_description');
    const participantCount = document.getElementById('participant_count');
    
    // Clear program selection
    programSelect.innerHTML = '<option value="">Select training program</option>';
    programDescription.style.display = 'none';
    
    if (serviceType && trainingPrograms[serviceType]) {
        // Enable program selection
        programSelect.disabled = false;
        
        // Populate programs for selected service
        trainingPrograms[serviceType].forEach(program => {
            const option = document.createElement('option');
            option.value = program.code;
            option.textContent = program.name;
            option.dataset.description = program.description;
            option.dataset.duration = program.duration;
            programSelect.appendChild(option);
        });
    } else {
        // Disable program selection
        programSelect.disabled = true;
    }
    
    // Update organization section visibility
    toggleOrganizationSection();
}

// Enhanced form validation
function validateTrainingRequestForm() {
    const form = document.getElementById('trainingRequestForm');
    const formData = new FormData(form);
    const errors = [];
    
    // Basic field validation
    const requiredFields = {
        'service_type': 'Service Type',
        'training_program': 'Training Program',
        'contact_person': 'Contact Person',
        'contact_number': 'Contact Number',
        'email': 'Email Address'
    };
    
    Object.entries(requiredFields).forEach(([field, label]) => {
        if (!formData.get(field) || formData.get(field).trim() === '') {
            errors.push(`${label} is required.`);
        }
    });
    
    // Enhanced phone validation
    const phone = formData.get('contact_number');
    if (phone) {
        const cleanNumber = phone.replace(/[^0-9]/g, '');
        const validPatterns = [
            /^(09\d{9})$/,
            /^(\+639\d{9})$/,
            /^(639\d{9})$/,
            /^(02\d{7,8})$/,
            /^(032\d{7})$/,
            /^(033\d{7})$/,
            /^(034\d{7})$/,
            /^(035\d{7})$/,
            /^(036\d{7})$/,
            /^(038\d{7})$/,
            /^(045\d{7})$/,
            /^(046\d{7})$/,
            /^(047\d{7})$/,
            /^(048\d{7})$/,
            /^(049\d{7})$/,
            /^(063\d{7})$/,
            /^(078\d{7})$/,
            /^(082\d{7})$/,
            /^(083\d{7})$/,
            /^(085\d{7})$/,
            /^(088\d{7})$/
        ];
        
        let phoneValid = false;
        for (const pattern of validPatterns) {
            if (pattern.test(cleanNumber)) {
                phoneValid = true;
                break;
            }
        }
        
        if (!phoneValid) {
            errors.push('Please enter a valid Philippine phone number.');
        }
    }
    
    // Email validation
    const email = formData.get('email');
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        errors.push('Please enter a valid email address.');
    }
    
    // Participant count validation
    const participantCount = parseInt(formData.get('participant_count')) || 0;
    if (participantCount < 1 || participantCount > 100) {
        errors.push('Participant count must be between 1 and 100.');
    }
    
    // Organization name required for large groups
    if (participantCount >= 5 && !formData.get('organization_name')) {
        errors.push('Organization name is required for groups of 5 or more participants.');
    }
    
    // Date validation
    const startDate = formData.get('preferred_start_date');
    const endDate = formData.get('preferred_end_date');
    
    if (startDate) {
        const start = new Date(startDate);
        const nextWeek = new Date();
        nextWeek.setDate(nextWeek.getDate() + 7);
        
        if (start < nextWeek) {
            errors.push('Preferred start date must be at least 1 week from now.');
        }
        
        if (endDate) {
            const end = new Date(endDate);
            if (end < start) {
                errors.push('End date cannot be before start date.');
            }
            
            const duration = Math.ceil((end - start) / (1000 * 60 * 60 * 24)) + 1;
            if (duration > 365) {
                errors.push('Training duration cannot exceed 365 days.');
            }
        }
    }
    
    // File validation
    const validIdFile = document.getElementById('valid_id_request').files[0];
    if (!validIdFile) {
        errors.push('Valid ID upload is required.');
    }
    
    const participantListFile = document.getElementById('participant_list').files[0];
    if (participantCount >= 5 && !participantListFile) {
        errors.push('Participant list is required for groups of 5 or more participants.');
    }
    
    return errors;
}

// Form submission handler
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('trainingRequestForm');
    
    
   if (form) {
        form.addEventListener('submit', function(e) {
            const errors = validateTrainingRequestForm();
            
            if (errors.length > 0) {
                e.preventDefault();
                alert('Please fix the following errors:\n\n' + errors.join('\n'));
                return false;
            }
            
            // Check phone validation status
            const phoneInput = document.getElementById('contact_number');
            if (phoneInput && phoneInput.classList.contains('invalid')) {
                e.preventDefault();
                alert('Please enter a valid Philippine phone number before submitting.');
                phoneInput.focus();
                return false;
            }
            
            // Show loading state
            const submitBtn = form.querySelector('.btn-submit');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting Request...';
                submitBtn.disabled = true;
            }
            
            return true;
        });
    }
    
    // Initialize form state
    toggleOrganizationSection();
    
    // Event listeners for dynamic sections
    const participantCountInput = document.getElementById('participant_count');
    if (participantCountInput) {
        participantCountInput.addEventListener('input', toggleOrganizationSection);
    }
    
    const trainingProgramSelect = document.getElementById('training_program');
    if (trainingProgramSelect) {
        trainingProgramSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const programDescription = document.getElementById('program_description');
            const descriptionText = programDescription.querySelector('small');
            
            if (selectedOption.dataset.description) {
                descriptionText.textContent = selectedOption.dataset.description;
                programDescription.style.display = 'block';
            } else {
                programDescription.style.display = 'none';
            }
        });
    }
});
// Update request date preview
function updateRequestDatePreview() {
    const startDateInput = document.getElementById('preferred_start_date');
    const endDateInput = document.getElementById('preferred_end_date');
    const previewContainer = document.getElementById('requestDatePreviewContainer');
    
    if (!startDateInput || !endDateInput || !previewContainer) return;
    
    const previewStartDate = document.getElementById('requestPreviewStartDate');
    const previewEndDate = document.getElementById('requestPreviewEndDate');
    const previewDuration = document.getElementById('requestPreviewDuration');
    
    const startDate = startDateInput.value;
    const endDate = endDateInput.value;
    
    if (startDate) {
        const start = new Date(startDate + 'T00:00:00');
        let end = start;
        let duration = 1;
        
        if (endDate) {
            end = new Date(endDate + 'T00:00:00');
            const timeDiff = end.getTime() - start.getTime();
            duration = Math.ceil(timeDiff / (1000 * 3600 * 24)) + 1;
            
            if (duration < 1) {
                duration = 1;
                end = start;
            }
        }
        
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

// Philippine phone number validation with API simulation
async function validatePhoneNumber(input) {
    const phoneNumber = input.value.trim();
    const feedbackElement = document.getElementById('phoneValidationFeedback');
    
    if (!phoneNumber) {
        feedbackElement.style.display = 'none';
        input.classList.remove('valid', 'invalid');
        return;
    }
    
    // Show loading state
    feedbackElement.style.display = 'block';
    feedbackElement.className = 'phone-validation-feedback loading';
    feedbackElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Validating phone number...';
    
    // Simulate API delay
    await new Promise(resolve => setTimeout(resolve, 1000));
    
    // Client-side validation patterns for Philippine numbers
    const cleanNumber = phoneNumber.replace(/[^0-9]/g, '');
    
    const validPatterns = [
        /^(09\d{9})$/,           // 09XXXXXXXXX (mobile)
        /^(\+639\d{9})$/,        // +639XXXXXXXXX (mobile with country code) 
        /^(639\d{9})$/,          // 639XXXXXXXXX (mobile without +)
        /^(02\d{7,8})$/,         // 02XXXXXXX or 02XXXXXXXX (Manila landline)
        /^(032\d{7})$/,          // 032XXXXXXX (Cebu landline)
        /^(033\d{7})$/,          // 033XXXXXXX (Iloilo landline)
        /^(034\d{7})$/,          // 034XXXXXXX (Bacolod landline)
        /^(035\d{7})$/,          // 035XXXXXXX (Dumaguete landline)
        /^(036\d{7})$/,          // 036XXXXXXX (Kalibo landline)
        /^(038\d{7})$/,          // 038XXXXXXX (Tagbilaran landline)
        /^(045\d{7})$/,          // 045XXXXXXX (Cabanatuan landline)
        /^(046\d{7})$/,          // 046XXXXXXX (Batangas landline)
        /^(047\d{7})$/,          // 047XXXXXXX (Lipa landline)
        /^(048\d{7})$/,          // 048XXXXXXX (San Pablo landline)
        /^(049\d{7})$/,          // 049XXXXXXX (Los Baños landline)
        /^(063\d{7})$/,          // 063XXXXXXX (Davao landline)
        /^(078\d{7})$/,          // 078XXXXXXX (Cagayan de Oro landline)
        /^(082\d{7})$/,          // 082XXXXXXX (Davao landline alt)
        /^(083\d{7})$/,          // 083XXXXXXX (Butuan landline)
        /^(085\d{7})$/,          // 085XXXXXXX (Zamboanga landline)
        /^(088\d{7})$/,          // 088XXXXXXX (Cagayan de Oro landline alt)
    ];
    
    let isValid = false;
    let numberType = '';
    let formattedNumber = '';
    
    // Check against patterns
    for (const pattern of validPatterns) {
        if (pattern.test(cleanNumber)) {
            isValid = true;
            
            if (cleanNumber.startsWith('09') || cleanNumber.startsWith('639')) {
                numberType = 'Mobile';
                // Format mobile number
                if (cleanNumber.startsWith('09')) {
                    formattedNumber = cleanNumber.replace(/(\d{4})(\d{3})(\d{4})/, '$1 $2 $3');
                } else {
                    formattedNumber = '+63 ' + cleanNumber.substring(2).replace(/(\d{3})(\d{3})(\d{4})/, '$1 $2 $3');
                }
            } else {
                numberType = 'Landline';
                // Format landline number
                formattedNumber = cleanNumber.replace(/(\d{2,3})(\d{3})(\d{4})/, '$1 $2 $3');
            }
            break;
        }
    }
    
    // Simulate additional validation checks (carrier verification, etc.)
    if (isValid) {
        // Simulate carrier check
        await new Promise(resolve => setTimeout(resolve, 500));
        
        feedbackElement.className = 'phone-validation-feedback valid';
        feedbackElement.innerHTML = `
            <i class="fas fa-check-circle"></i>
            <div class="validation-details">
                <div class="validation-status">Valid Philippine ${numberType} Number</div>
                <div class="formatted-number">${formattedNumber}</div>
                <div class="validation-note">Number format verified</div>
            </div>
        `;
        input.classList.remove('invalid');
        input.classList.add('valid');
    } else {
        feedbackElement.className = 'phone-validation-feedback invalid';
        feedbackElement.innerHTML = `
            <i class="fas fa-times-circle"></i>
            <div class="validation-details">
                <div class="validation-status">Invalid Philippine Number</div>
                <div class="validation-suggestions">
                    <strong>Valid formats:</strong><br>
                    • Mobile: 09XX XXX XXXX or +63 9XX XXX XXXX<br>
                    • Landline: 032 XXX XXXX (Cebu), 02 XXXX XXXX (Manila)
                </div>
            </div>
        `;
        input.classList.remove('valid');
        input.classList.add('invalid');
    }
}

// Function to inject multi-day training styles
function injectMultiDayTrainingStyles() {
    const multiDayTrainingStyles = `
/* Multi-day training span styles */
.session-datetime {
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

.session-time {
     font-size: 0.8rem;
    color: #2196F3;
    font-weight: 500;
    background: rgba(33, 150, 243, 0.1);
    padding: 0.1rem 0.3rem;
    border-radius: 4px;
    display: inline-block;
    margin: 0.1rem 0;
    border: 1px solid rgba(33, 150, 243, 0.2);
}

.session-duration {
    font-size: 0.75rem;
    background: rgba(33, 150, 243, 0.1);
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

.ongoing-badge {
    background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);
    color: white;
    padding: 0.2rem 0.5rem;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
    margin-top: 0.3rem;
    display: inline-block;
    animation: pulse 2s infinite;
}

/* Enhanced calendar styles for multi-day training */
.event-indicators {
    display: flex;
    flex-wrap: wrap;
    gap: 1px;
    margin-top: 2px;
    position: relative;
    z-index: 1;
}

.event-indicator {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    border: 1px solid white;
    box-shadow: 0 1px 2px rgba(0,0,0,0.2);
    transition: transform 0.2s ease;
}

.event-indicator.registered {
    border: 2px solid #4CAF50;
    box-shadow: 0 0 4px rgba(76, 175, 80, 0.4);
}

.event-count {
    font-size: 7px;
    background: rgba(0,0,0,0.6);
    color: white;
    padding: 1px 3px;
    border-radius: 3px;
    margin-top: 1px;
}

/* Large calendar event bars */
.event-display {
    display: flex;
    flex-direction: column;
    gap: 2px;
    margin-top: 4px;
}

.event-bar {
    height: 16px;
    border-radius: 3px;
    font-size: 10px;
    font-weight: 600;
    color: white;
    text-shadow: 0 1px 2px rgba(0,0,0,0.3);
    padding: 2px 4px;
    position: relative;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    background: var(--event-color, #607D8B);
    border: 1px solid rgba(255,255,255,0.2);
    line-height: 12px;
    transition: transform 0.2s ease;
}

.event-bar.registered {
    background: linear-gradient(45deg, var(--event-color, #607D8B) 0%, #4CAF50 100%);
    box-shadow: 0 0 6px rgba(76, 175, 80, 0.4);
}

/* Enhanced event dots */
.event-dots {
    display: flex;
    flex-wrap: wrap;
    gap: 2px;
    margin-top: 2px;
}

.event-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    border: 1px solid white;
    box-shadow: 0 1px 2px rgba(0,0,0,0.2);
    transition: transform 0.2s ease;
}

.event-dot.registered {
    border: 2px solid #4CAF50;
    box-shadow: 0 0 4px rgba(76, 175, 80, 0.4);
}

/* Registration indicator */
.registration-indicator {
    position: absolute;
    top: 2px;
    right: 2px;
    width: 12px;
    height: 12px;
    background: #4CAF50;
    color: white;
    border-radius: 50%;
    font-size: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    box-shadow: 0 1px 3px rgba(0,0,0,0.3);
    z-index: 2;
}

/* Enhanced calendar day cells */
.day-cell.has-events {
    border: 2px solid rgba(33, 150, 243, 0.3);
}

.day-cell.has-registered-event {
    border: 2px solid rgba(76, 175, 80, 0.5);
    background: linear-gradient(135deg, rgba(76, 175, 80, 0.05) 0%, rgba(76, 175, 80, 0.1) 100%);
}

.calendar-day.has-registered-event {
    border: 2px solid rgba(76, 175, 80, 0.5);
    background: linear-gradient(135deg, rgba(76, 175, 80, 0.05) 0%, rgba(76, 175, 80, 0.1) 100%);
}

/* Tooltip styles for multi-day training */
.tooltip-event-duration {
    color: #666;
    font-size: 0.85rem;
    margin: 2px 0;
    display: flex;
    align-items: center;
    gap: 4px;
}
/* Tooltip styles for multi-day training */
.tooltip-event-duration {
    color: #666;
    font-size: 0.85rem;
    margin: 2px 0;
    display: flex;
    align-items: center;
    gap: 4px;
}

.tooltip-event-time {
    color: #666;
    font-size: 0.8rem;
    margin: 2px 0;
    display: flex;
    align-items: center;
    gap: 4px;
}

.tooltip-event-service {
    color: #666;
    font-size: 0.8rem;
    margin: 2px 0;
    display: flex;
    align-items: center;
    gap: 4px;
}

/* Animation for spans */
.event-indicator, .event-bar {
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-3px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.7; }
    100% { opacity: 1; }
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .event-indicator {
        width: 5px;
        height: 5px;
    }
    
    .event-bar {
        height: 14px;
        font-size: 9px;
    }
    
    .event-dot {
        width: 6px;
        height: 6px;
    }
    
    .session-datetime {
        font-size: 0.8rem;
    }
    
    .session-duration {
        font-size: 0.7rem;
        padding: 0.1rem 0.3rem;
    }
}

@media (max-width: 576px) {
    .session-date-start,
    .session-date-end {
        font-size: 0.75rem;
    }
    
    .session-duration {
        font-size: 0.65rem;
    }
    
    .session-time {
        font-size: 0.75rem;
    }
}
    `;
    
    // Create and append style element
    const styleElement = document.createElement('style');
    styleElement.innerHTML = multiDayTrainingStyles;
    document.head.appendChild(styleElement);
}
// Training Programs Data
const trainingPrograms = {
    'Safety Service': [
        {
            code: 'EFAT',
            name: 'Emergency First Aid Training (EFAT)',
            description: 'Basic emergency first aid skills and techniques - 8 hours duration',
            duration: 8
        },
        {
            code: 'OFAT', 
            name: 'Occupational First Aid Training (OFAT)',
            description: 'Workplace-specific first aid training for occupational safety - 16 hours duration',
            duration: 16
        },
        {
            code: 'OTC',
            name: 'Occupational Training Course (OTC)', 
            description: 'Comprehensive occupational safety and health training - 24 hours duration',
            duration: 24
        }
    ],
    'Red Cross Youth': [
        {
            code: 'YVFC',
            name: 'Youth Volunteer Formation Course (YVFC)',
            description: 'Foundational training for youth volunteers in Red Cross principles - 12 hours duration',
            duration: 12
        },
        {
            code: 'LDP',
            name: 'Leadership Development Program (LDP)', 
            description: 'Advanced leadership skills for youth officers and coordinators - 20 hours duration',
            duration: 20
        },
        {
            code: 'AD',
            name: 'Advocacy Dissemination (AD)',
            description: 'Training on advocacy techniques and community outreach methods - 8 hours duration', 
            duration: 8
        }
    ]
};

// Open Training Request Modal
function openTrainingRequestModal() {
    const modal = document.getElementById('trainingRequestModal');
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        // Reset form
        const form = document.getElementById('trainingRequestForm');
        if (form) {
            form.reset();
        }
        
        // Reset form state
        const programSelect = document.getElementById('training_program');
        const programDescription = document.getElementById('program_description');
        const orgSection = document.getElementById('organization_section');
        const participantListSection = document.getElementById('participant_list_section');
        
        if (programSelect) {
            programSelect.disabled = true;
            programSelect.innerHTML = '<option value="">Select service type first</option>';
        }
        if (programDescription) {
            programDescription.style.display = 'none';
        }
        if (orgSection) {
            orgSection.style.display = 'none';
        }
        if (participantListSection) {
            participantListSection.style.display = 'none';
        }
        
        // Set default times
        setDefaultTimes();
        
        // Set minimum date to 1 week from now
        const nextWeek = new Date();
        nextWeek.setDate(nextWeek.getDate() + 7);
        const minDate = nextWeek.toISOString().split('T')[0];
        
        const startDateInput = document.getElementById('preferred_start_date');
        const endDateInput = document.getElementById('preferred_end_date');
        
        if (startDateInput) {
            startDateInput.min = minDate;
            startDateInput.value = '';
        }
        if (endDateInput) {
            endDateInput.min = minDate;
            endDateInput.value = '';
        }
        
        // Hide date preview
        const previewContainer = document.getElementById('requestDatePreviewContainer');
        if (previewContainer) {
            previewContainer.style.display = 'none';
        }
        
        // Reset file uploads
        const fileContainers = document.querySelectorAll('#trainingRequestModal .file-upload-container');
        fileContainers.forEach(container => {
            container.classList.remove('has-file');
            const input = container.querySelector('input[type="file"]');
            if (input) {
                input.value = '';
                const info = container.querySelector('.file-upload-info span');
                if (info) {
                    resetFileUploadInfo(input);
                }
            }
        });
        
        // Clear uploaded files list
        const filesList = document.getElementById('additional_docs_list');
        if (filesList) {
            filesList.innerHTML = '';
            filesList.classList.remove('has-files');
        }
        
        // Clear phone validation
        const phoneInput = document.getElementById('contact_number');
        const phoneValidation = document.getElementById('phoneValidationFeedback');
        if (phoneInput) {
            phoneInput.classList.remove('valid', 'invalid');
        }
        if (phoneValidation) {
            phoneValidation.style.display = 'none';
        }
        
        // Clear any previous errors
        document.querySelectorAll('#trainingRequestModal .form-group.error').forEach(group => {
            group.classList.remove('error');
            const errorMsg = group.querySelector('.error-message');
            if (errorMsg) errorMsg.remove();
        });
    }
}

// Set default times based on preference or specific times
function setDefaultTimes() {
    const startTimeInput = document.getElementById('preferred_start_time');
    const endTimeInput = document.getElementById('preferred_end_time');
    
    // Default times
    let startTime = '09:00';
    let endTime = '17:00';
    
    // Check if specific times were provided (when editing existing request)
    if (startTimeInput && startTimeInput.value) {
        startTime = startTimeInput.value;
    }
    if (endTimeInput && endTimeInput.value) {
        endTime = endTimeInput.value;
    }
    
    // Fallback to preference-based times if specific times not provided
    if ((!startTimeInput || !startTimeInput.value) && preferredTimeSelect && preferredTimeSelect.value) {
        const preferredTime = preferredTimeSelect.value;
        
        if (preferredTime === 'morning') {
            startTime = '09:00';
            endTime = '17:00';
        } else if (preferredTime === 'afternoon') {
            startTime = '13:00';
            endTime = '17:00';
        } else if (preferredTime === 'evening') {
            startTime = '18:00';
            endTime = '20:00';
        } else if (preferredTime === 'flexible') {
            startTime = '09:00';
            endTime = '17:00';
        }
    }
    
    // Set the time inputs
    if (startTimeInput) {
        startTimeInput.value = startTime;
    }
    if (endTimeInput) {
        endTimeInput.value = endTime;
    }
}

// Update times based on preferred time selection
function updateTimesBasedOnPreference() {
    const preferredTimeSelect = document.getElementById('preferred_time');
    const startTimeInput = document.getElementById('preferred_start_time');
    const endTimeInput = document.getElementById('preferred_end_time');
    
    if (!preferredTimeSelect || !startTimeInput || !endTimeInput) return;
    
    const preferredTime = preferredTimeSelect.value;
    
    // Only update if user hasn't manually set specific times
    const hasCustomStartTime = startTimeInput.value && startTimeInput.value !== '09:00';
    const hasCustomEndTime = endTimeInput.value && endTimeInput.value !== '17:00';
    
    if (!hasCustomStartTime || !hasCustomEndTime) {
        switch (preferredTime) {
            case 'morning':
                startTimeInput.value = '09:00';
                endTimeInput.value = '17:00';
                break;
            case 'afternoon':
                startTimeInput.value = '13:00';
                endTimeInput.value = '17:00';
                break;
            case 'evening':
                startTimeInput.value = '18:00';
                endTimeInput.value = '20:00';
                break;
            case 'flexible':
                startTimeInput.value = '09:00';
                endTimeInput.value = '17:00';
                break;
            default:
                startTimeInput.value = '09:00';
                endTimeInput.value = '17:00';
        }
    }
}

// Close Training Request Modal
function closeTrainingRequestModal() {
    const modal = document.getElementById('trainingRequestModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// Update Training Programs based on Service Type
function updateTrainingPrograms() {
    const serviceType = document.getElementById('service_type').value;
    const programSelect = document.getElementById('training_program');
    const programDescription = document.getElementById('program_description');
    
    // Clear program selection
    programSelect.innerHTML = '<option value="">Select training program</option>';
    programDescription.style.display = 'none';
    
    if (serviceType && trainingPrograms[serviceType]) {
        // Enable program selection
        programSelect.disabled = false;
        
        // Populate programs for selected service
        trainingPrograms[serviceType].forEach(program => {
            const option = document.createElement('option');
            option.value = program.code;
            option.textContent = program.name;
            option.dataset.description = program.description;
            option.dataset.duration = program.duration;
            programSelect.appendChild(option);
        });
    } else {
        // Disable program selection
        programSelect.disabled = true;
    }
    
    // Update organization section visibility
    toggleOrganizationSection();
}

// Enhanced form validation
function validateTrainingRequestForm() {
    const form = document.getElementById('trainingRequestForm');
    const formData = new FormData(form);
    const errors = [];
    
    // Basic field validation
    const requiredFields = {
        'service_type': 'Service Type',
        'training_program': 'Training Program',
        'contact_person': 'Contact Person',
        'contact_number': 'Contact Number',
        'email': 'Email Address'
    };
    
    Object.entries(requiredFields).forEach(([field, label]) => {
        if (!formData.get(field) || formData.get(field).trim() === '') {
            errors.push(`${label} is required.`);
        }
    });
    
    // Enhanced phone validation
    const phone = formData.get('contact_number');
    if (phone) {
        const cleanNumber = phone.replace(/[^0-9]/g, '');
        const validPatterns = [
            /^(09\d{9})$/,
            /^(\+639\d{9})$/,
            /^(639\d{9})$/,
            /^(02\d{7,8})$/,
            /^(032\d{7})$/,
            /^(033\d{7})$/,
            /^(034\d{7})$/,
            /^(035\d{7})$/,
            /^(036\d{7})$/,
            /^(038\d{7})$/,
            /^(045\d{7})$/,
            /^(046\d{7})$/,
            /^(047\d{7})$/,
            /^(048\d{7})$/,
            /^(049\d{7})$/,
            /^(063\d{7})$/,
            /^(078\d{7})$/,
            /^(082\d{7})$/,
            /^(083\d{7})$/,
            /^(085\d{7})$/,
            /^(088\d{7})$/
        ];
        
        let phoneValid = false;
        for (const pattern of validPatterns) {
            if (pattern.test(cleanNumber)) {
                phoneValid = true;
                break;
            }
        }
        
        if (!phoneValid) {
            errors.push('Please enter a valid Philippine phone number.');
        }
    }
    
    // Email validation
    const email = formData.get('email');
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        errors.push('Please enter a valid email address.');
    }
    
    // Participant count validation
    const participantCount = parseInt(formData.get('participant_count')) || 0;
    if (participantCount < 1 || participantCount > 100) {
        errors.push('Participant count must be between 1 and 100.');
    }
    
    // Organization name required for large groups
    if (participantCount >= 5 && !formData.get('organization_name')) {
        errors.push('Organization name is required for groups of 5 or more participants.');
    }
    
    // Date validation
    const startDate = formData.get('preferred_start_date');
    const endDate = formData.get('preferred_end_date');
    
    if (startDate) {
        const start = new Date(startDate);
        const nextWeek = new Date();
        nextWeek.setDate(nextWeek.getDate() + 7);
        
        if (start < nextWeek) {
            errors.push('Preferred start date must be at least 1 week from now.');
        }
        
        if (endDate) {
            const end = new Date(endDate);
            if (end < start) {
                errors.push('End date cannot be before start date.');
            }
            
            const duration = Math.ceil((end - start) / (1000 * 60 * 60 * 24)) + 1;
            if (duration > 365) {
                errors.push('Training duration cannot exceed 365 days.');
            }
        }
    }
    
    // Time validation
    const startTime = formData.get('preferred_start_time');
    const endTime = formData.get('preferred_end_time');
    
    if (startTime && endTime) {
        const start = new Date('1970-01-01T' + startTime);
        const end = new Date('1970-01-01T' + endTime);
        
        if (end <= start) {
            errors.push('End time must be after start time.');
        }
    }
    
    // File validation
    const validIdFile = document.getElementById('valid_id_request').files[0];
    if (!validIdFile) {
        errors.push('Valid ID upload is required.');
    }
    
    const participantListFile = document.getElementById('participant_list').files[0];
    if (participantCount >= 5 && !participantListFile) {
        errors.push('Participant list is required for groups of 5 or more participants.');
    }
    
    return errors;
}

// Helper function to show field errors
function showFieldError(fieldId, message) {
    const field = document.getElementById(fieldId);
    if (field) {
        const formGroup = field.closest('.form-group');
        if (formGroup) {
            formGroup.classList.add('error');
            
            const errorElement = document.createElement('div');
            errorElement.className = 'error-message';
            errorElement.textContent = message;
            formGroup.appendChild(errorElement);
        }
    }
}

// Initialize event listeners when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Training program change listener
    const trainingProgramSelect = document.getElementById('training_program');
    if (trainingProgramSelect) {
        trainingProgramSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const programDescription = document.getElementById('program_description');
            const descriptionText = programDescription.querySelector('small');
            
            if (selectedOption.dataset.description) {
                descriptionText.textContent = selectedOption.dataset.description;
                programDescription.style.display = 'block';
            } else {
                programDescription.style.display = 'none';
            }
        });
    }

    // Participant count change listener
    const participantCountInput = document.getElementById('participant_count');
    if (participantCountInput) {
        participantCountInput.addEventListener('input', function() {
            toggleOrganizationSection();
        });
    }

    // Preferred time change listener
    const preferredTimeSelect = document.getElementById('preferred_time');
    if (preferredTimeSelect) {
        preferredTimeSelect.addEventListener('change', function() {
            updateTimesBasedOnPreference();
        });
    }

    // Form submission handler
    const trainingRequestForm = document.getElementById('trainingRequestForm');
    if (trainingRequestForm) {
        trainingRequestForm.addEventListener('submit', function(e) {
            const errors = validateTrainingRequestForm();
            
            if (errors.length > 0) {
                e.preventDefault();
                alert('Please fix the following errors:\n\n' + errors.join('\n'));
                return false;
            }
            
            // Check phone validation status
            const phoneInput = document.getElementById('contact_number');
            if (phoneInput && phoneInput.classList.contains('invalid')) {
                e.preventDefault();
                alert('Please enter a valid Philippine phone number before submitting.');
                phoneInput.focus();
                return false;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('.btn-submit');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting Request...';
                submitBtn.disabled = true;
            }
            
            return true;
        });
    }

    // Close modal when clicking outside
    const trainingRequestModal = document.getElementById('trainingRequestModal');
    if (trainingRequestModal) {
        trainingRequestModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeTrainingRequestModal();
            }
        });
    }

    // Keyboard navigation
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('trainingRequestModal');
            if (modal && modal.classList.contains('active')) {
                closeTrainingRequestModal();
            }
        }
    });
});
</script>

</body>
</html>