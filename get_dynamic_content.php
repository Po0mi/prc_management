<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'config.php';

$response = [
    'success' => false,
    'events_updated' => true,
    'sessions_updated' => true,
    'merchandise_updated' => true,
    'announcements_updated' => true,
    'events' => [],
    'sessions' => [],
    'merchandise' => [],
    'announcements' => [],
    'timestamp' => time()
];

try {
    $pdo = $GLOBALS['pdo'];
    
    // Get events - show recent and upcoming events
    $stmt = $pdo->prepare("
        SELECT 
            event_id,
            title,
            description,
            event_date,
            event_end_date,
            start_time,
            end_time,
            location,
            major_service,
            capacity,
            fee,
            duration_days,
            (SELECT COUNT(*) FROM registrations WHERE event_id = e.event_id) as registrations_count
        FROM events e
        WHERE event_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ORDER BY event_date ASC
        LIMIT 6
    ");
    $stmt->execute();
    $response['events'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get training sessions - include active sessions
    $stmt = $pdo->prepare("
        SELECT 
            session_id,
            title,
            description,
            major_service,
            session_date,
            session_end_date,
            start_time,
            end_time,
            venue,
            instructor,
            instructor_bio,
            instructor_credentials,
            capacity,
            fee,
            duration_days,
            status,
            (SELECT COUNT(*) FROM session_registrations WHERE session_id = ts.session_id) as registrations_count
        FROM training_sessions ts
        WHERE status = 'active' 
        AND session_end_date >= CURDATE()
        ORDER BY session_date ASC
        LIMIT 4
    ");
    $stmt->execute();
    $response['sessions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get merchandise (this is already working)
    $stmt = $pdo->prepare("
        SELECT 
            merch_id,
            name,
            description,
            category,
            price,
            stock_quantity,
            image_url,
            is_available
        FROM merchandise
        WHERE is_available = 1 AND stock_quantity > 0
        ORDER BY created_at DESC
        LIMIT 8
    ");
    $stmt->execute();
    $response['merchandise'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get announcements (this is already working)
    $stmt = $pdo->prepare("
        SELECT 
            announcement_id,
            title,
            content,
            image_url,
            posted_at
        FROM announcements
        ORDER BY posted_at DESC
        LIMIT 3
    ");
    $stmt->execute();
    $response['announcements'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $response['success'] = true;
    
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>