<?php
// api/check_new_registrations.php - Simple and robust version
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

try {
    $pdo = $GLOBALS['pdo'];
    $since = $_GET['since'] ?? date('Y-m-d H:i:s', strtotime('-1 hour'));
    $type = $_GET['type'] ?? 'event'; // 'event' or 'training'
    
    // Convert ISO format to MySQL format if needed
    if (strpos($since, 'T') !== false) {
        $since = date('Y-m-d H:i:s', strtotime($since));
    }
    
    $newRegistrations = [];
    
    if ($type === 'event') {
        // Check events registrations
        $query = "
            SELECT 
                r.registration_id,
                r.event_id,
                r.full_name as participant_name,
                r.email as participant_email,
                r.status,
                r.registration_date,
                e.title as item_title
            FROM registrations r
            LEFT JOIN events e ON r.event_id = e.event_id
            WHERE r.registration_date > ?
            ORDER BY r.registration_date DESC
            LIMIT 50
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$since]);
        $newRegistrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } else if ($type === 'training') {
        // Check training sessions registrations
        $query = "
            SELECT 
                r.registration_id,
                r.session_id,
                COALESCE(r.full_name, r.name, 'New Participant') as participant_name,
                r.email as participant_email,
                r.status,
                r.registration_date,
                s.title as item_title
            FROM session_registrations r
            LEFT JOIN training_sessions s ON r.session_id = s.session_id
            WHERE r.registration_date > ?
            ORDER BY r.registration_date DESC
            LIMIT 50
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$since]);
        $newRegistrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode([
        'success' => true,
        'newRegistrations' => $newRegistrations,
        'count' => count($newRegistrations),
        'type' => $type,
        'since' => $since
    ]);
    
} catch (Exception $e) {
    error_log("Error in check_new_registrations.php: " . $e->getMessage());
    error_log("SQL Error: " . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred',
        'debug' => $e->getMessage() // Remove this in production
    ]);
}
?>