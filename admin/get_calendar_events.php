<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
ensure_logged_in();
ensure_admin();

header('Content-Type: application/json');

$pdo = $GLOBALS['pdo'];

try {
    // Get events for calendar
    $stmt = $pdo->prepare("
        SELECT 
            event_id,
            title,
            event_date,
            location,
            major_service,
            description
        FROM events 
        WHERE event_date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
        AND event_date <= DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
        ORDER BY event_date ASC
    ");
    
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Also get training sessions
    $stmt = $pdo->prepare("
        SELECT 
            session_id as event_id,
            title,
            session_date as event_date,
            venue as location,
            major_service,
            description
        FROM training_sessions 
        WHERE session_date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
        AND session_date <= DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
        ORDER BY session_date ASC
    ");
    
    $stmt->execute();
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Combine events and sessions
    $allEvents = array_merge($events, $sessions);
    
    echo json_encode($allEvents);
    
} catch (PDOException $e) {
    error_log("Error fetching calendar events: " . $e->getMessage());
    echo json_encode([]);
}
?>