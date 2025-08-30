<?php
// check_conflicts.php - AJAX endpoint for checking event date conflicts

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';

// Set JSON response header
header('Content-Type: application/json');

// Check if user is logged in and has admin access
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Check if user has admin role
$user_role = get_user_role();
if (!$user_role) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin access required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$pdo = $GLOBALS['pdo'];

try {
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $eventId = isset($_POST['event_id']) ? (int)$_POST['event_id'] : null;
    
    // Validate dates
    if (empty($startDate) || empty($endDate)) {
        echo json_encode(['error' => 'Start date and end date are required']);
        exit;
    }
    
    if (!DateTime::createFromFormat('Y-m-d', $startDate) || !DateTime::createFromFormat('Y-m-d', $endDate)) {
        echo json_encode(['error' => 'Invalid date format']);
        exit;
    }
    
    // Check for conflicts
    $sql = "SELECT e.event_id, e.title, e.event_date as start_date, e.event_end_date as end_date
            FROM events e
            WHERE e.event_date <= ? AND e.event_end_date >= ?";
    
    $params = [$endDate, $startDate];
    
    // Exclude current event if editing
    if ($eventId) {
        $sql .= " AND e.event_id != ?";
        $params[] = $eventId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Return conflicts as JSON
    echo json_encode([
        'conflicts' => $conflicts,
        'has_conflicts' => !empty($conflicts),
        'conflict_count' => count($conflicts)
    ]);
    
} catch (PDOException $e) {
    error_log("Database error in conflict checker: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("General error in conflict checker: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An error occurred while checking conflicts']);
}
?>