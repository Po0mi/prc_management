<?php
/**
 * Dashboard Connection Configuration
 * This file manages the connection and data flow between user and admin dashboards
 */

// Dashboard routing configuration
function route_to_appropriate_dashboard() {
    $user_role = get_user_role();
    $current_page = basename($_SERVER['PHP_SELF']);
    
    // Admin users should go to admin dashboard
    if ($user_role && $current_page === 'dashboard.php') {
        header("Location: /admin/dashboard.php");
        exit;
    }
    
    // Regular users accessing admin area should be redirected
    if (!$user_role && strpos($_SERVER['REQUEST_URI'], '/admin/') !== false) {
        header("Location: /user/dashboard.php");
        exit;
    }
}

// Shared notification system
function get_shared_notifications($pdo, $user_id, $is_admin = false) {
    $notifications = [];
    
    try {
        // System-wide announcements
        $stmt = $pdo->query("
            SELECT title, content, created_at, priority, target_audience
            FROM announcements 
            WHERE status = 'published' 
            AND (target_audience = 'all' OR target_audience = '" . ($is_admin ? 'admin' : 'user') . "')
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER BY priority DESC, created_at DESC 
            LIMIT 5
        ");
        $announcements = $stmt ? $stmt->fetchAll() : [];
        
        foreach ($announcements as $announcement) {
            $notifications[] = [
                'type' => 'system',
                'priority' => $announcement['priority'] ?? 'medium',
                'title' => $announcement['title'],
                'message' => substr($announcement['content'], 0, 150) . '...',
                'timestamp' => strtotime($announcement['created_at']),
                'category' => 'announcement'
            ];
        }
        
        if (!$is_admin) {
            // User-specific notifications
            $user_notifications = get_user_specific_notifications($pdo, $user_id);
            $notifications = array_merge($notifications, $user_notifications);
        } else {
            // Admin-specific notifications
            $admin_notifications = get_admin_specific_notifications($pdo);
            $notifications = array_merge($notifications, $admin_notifications);
        }
        
    } catch (Exception $e) {
        error_log("Error fetching shared notifications: " . $e->getMessage());
    }
    
    return $notifications;
}

function get_user_specific_notifications($pdo, $user_id) {
    $notifications = [];
    
    try {
        // Check for pending registrations
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count, MIN(registration_date) as oldest_date
            FROM registrations 
            WHERE user_id = ? AND status = 'pending'
        ");
        $stmt->execute([$user_id]);
        $pending = $stmt->fetch();
        
        if ($pending['count'] > 0) {
            $notifications[] = [
                'type' => 'user',
                'priority' => 'medium',
                'title' => 'Pending Registrations',
                'message' => "You have {$pending['count']} registration(s) awaiting approval.",
                'timestamp' => strtotime($pending['oldest_date']),
                'category' => 'registration'
            ];
        }
        
        // Check for upcoming events user is registered for
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count, MIN(e.event_date) as next_date
            FROM registrations r
            JOIN events e ON r.event_id = e.event_id
            WHERE r.user_id = ? AND r.status = 'approved'
            AND e.event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ");
        $stmt->execute([$user_id]);
        $upcoming = $stmt->fetch();
        
        if ($upcoming['count'] > 0) {
            $notifications[] = [
                'type' => 'user',
                'priority' => 'high',
                'title' => 'Upcoming Events',
                'message' => "You have {$upcoming['count']} event(s) coming up this week.",
                'timestamp' => strtotime($upcoming['next_date']),
                'category' => 'event'
            ];
        }
        
    } catch (Exception $e) {
        error_log("Error fetching user notifications: " . $e->getMessage());
    }
    
    return $notifications;
}

function get_admin_specific_notifications($pdo) {
    $notifications = [];
    
    try {
        // Check for pending registrations needing approval
        $stmt = $pdo->query("
            SELECT COUNT(*) as count, MIN(registration_date) as oldest_date
            FROM registrations 
            WHERE status = 'pending'
            AND registration_date < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $pending = $stmt->fetch();
        
        if ($pending['count'] > 0) {
            $notifications[] = [
                'type' => 'admin',
                'priority' => 'high',
                'title' => 'Pending Approvals',
                'message' => "{$pending['count']} registration(s) need approval (oldest from " . date('M j', strtotime($pending['oldest_date'])) . ")",
                'timestamp' => strtotime($pending['oldest_date']),
                'category' => 'approval'
            ];
        }
        
        // Check for low inventory
        $stmt = $pdo->query("
            SELECT COUNT(*) as count
            FROM inventory_items 
            WHERE quantity <= 10
        ");
        $inventory = $stmt->fetch();
        
        if ($inventory['count'] > 0) {
            $notifications[] = [
                'type' => 'admin',
                'priority' => 'medium',
                'title' => 'Low Inventory',
                'message' => "{$inventory['count']} item(s) are running low on stock.",
                'timestamp' => time(),
                'category' => 'inventory'
            ];
        }
        
    } catch (Exception $e) {
        error_log("Error fetching admin notifications: " . $e->getMessage());
    }
    
    return $notifications;
}

// Cross-dashboard activity tracking
function log_dashboard_activity($pdo, $user_id, $action, $details = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, action, details, timestamp) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$user_id, $action, json_encode($details)]);
    } catch (Exception $e) {
        error_log("Error logging activity: " . $e->getMessage());
    }
}

// Shared statistics function
function get_dashboard_stats($pdo, $user_id = null, $is_admin = false) {
    $stats = [];
    
    try {
        if ($is_admin) {
            // Admin dashboard stats
            $stats['total_users'] = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $stats['total_events'] = $pdo->query("SELECT COUNT(*) FROM events WHERE event_date >= CURDATE()")->fetchColumn();
            $stats['pending_registrations'] = $pdo->query("SELECT COUNT(*) FROM registrations WHERE status = 'pending'")->fetchColumn();
            $stats['total_donations'] = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM donations WHERE status = 'approved'")->fetchColumn();
            
            // Recent activity counts
            $stats['recent_registrations'] = $pdo->query("
                SELECT COUNT(*) FROM registrations 
                WHERE registration_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ")->fetchColumn();
            
            $stats['recent_users'] = $pdo->query("
                SELECT COUNT(*) FROM users 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ")->fetchColumn();
            
        } else {
            // User dashboard stats
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $stats['events_registered'] = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE user_id = ? AND status = 'approved'");
            $stmt->execute([$user_id]);
            $stats['events_attended'] = $stmt->fetchColumn();
            
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM session_registrations WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $stats['training_sessions'] = $stmt->fetchColumn();
            } catch (Exception $e) {
                $stats['training_sessions'] = 0;
            }
            
            try {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM donations 
                    WHERE donor_id IN (SELECT donor_id FROM donors WHERE user_id = ?)
                ");
                $stmt->execute([$user_id]);
                $stats['donations_made'] = $stmt->fetchColumn();
            } catch (Exception $e) {
                $stats['donations_made'] = 0;
            }
        }
        
    } catch (Exception $e) {
        error_log("Error fetching dashboard stats: " . $e->getMessage());
        // Return default values
        $stats = array_fill_keys([
            'total_users', 'total_events', 'pending_registrations', 'total_donations',
            'recent_registrations', 'recent_users', 'events_registered', 
            'events_attended', 'training_sessions', 'donations_made'
        ], 0);
    }
    
    return $stats;
}

// Navigation menu builder
function build_navigation_menu($user_role, $current_page) {
    $menu_items = [];
    
    if ($user_role) {
        // Admin navigation
        $menu_items = [
            'dashboard' => ['icon' => 'tachometer-alt', 'label' => 'Dashboard', 'url' => '/admin/dashboard.php'],
            'events' => ['icon' => 'calendar', 'label' => 'Events', 'url' => '/admin/manage_events.php'],
            'users' => ['icon' => 'users', 'label' => 'Users', 'url' => '/admin/manage_users.php'],
            'training' => ['icon' => 'graduation-cap', 'label' => 'Training', 'url' => '/admin/manage_sessions.php'],
            'donations' => ['icon' => 'heart', 'label' => 'Donations', 'url' => '/admin/manage_donations.php'],
            'inventory' => ['icon' => 'boxes', 'label' => 'Inventory', 'url' => '/admin/manage_inventory.php'],
            'announcements' => ['icon' => 'bullhorn', 'label' => 'Announcements', 'url' => '/admin/manage_announcements.php'],
        ];
    } else {
        // User navigation
        $menu_items = [
            'dashboard' => ['icon' => 'tachometer-alt', 'label' => 'Dashboard', 'url' => '/user/dashboard.php'],
            'events' => ['icon' => 'calendar', 'label' => 'Events', 'url' => '/user/registration.php'],
            'training' => ['icon' => 'graduation-cap', 'label' => 'Training', 'url' => '/user/schedule.php'],
            'donations' => ['icon' => 'heart', 'label' => 'Donate', 'url' => '/user/donate.php'],
            'merchandise' => ['icon' => 'store', 'label' => 'Merchandise', 'url' => '/user/merch.php'],
            'announcements' => ['icon' => 'bullhorn', 'label' => 'Announcements', 'url' => '/user/announcements.php'],
        ];
    }
    
    // Mark current page as active
    foreach ($menu_items as $key => &$item) {
        $item['active'] = (strpos($current_page, $key) !== false || 
                         strpos($current_page, basename($item['url'], '.php')) !== false);
    }
    
    return $menu_items;
}

// Theme and styling consistency
function get_dashboard_theme_config() {
    return [
        'primary_color' => '#a00000',
        'secondary_color' => '#800000',
        'success_color' => '#28a745',
        'warning_color' => '#ffc107',
        'danger_color' => '#dc3545',
        'info_color' => '#17a2b8',
        'light_color' => '#f8f9fa',
        'dark_color' => '#343a40',
        
        // Layout settings
        'sidebar_width' => '250px',
        'sidebar_collapsed_width' => '70px',
        'header_height' => '60px',
        'border_radius' => '12px',
        
        // Animation settings
        'transition_speed' => '0.3s',
        'hover_transform' => 'translateY(-2px)',
        'shadow_base' => '0 2px 4px rgba(0, 0, 0, 0.1)',
        'shadow_hover' => '0 4px 12px rgba(0, 0, 0, 0.15)',
    ];
}

// Real-time update checker
function check_for_updates($pdo, $last_check_time, $user_id, $is_admin = false) {
    $updates = [];
    
    try {
        if ($is_admin) {
            // Check for new registrations
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM registrations 
                WHERE registration_date > FROM_UNIXTIME(?)
            ");
            $stmt->execute([$last_check_time]);
            $new_registrations = $stmt->fetchColumn();
            
            if ($new_registrations > 0) {
                $updates['new_registrations'] = $new_registrations;
            }
            
            // Check for new users
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM users 
                WHERE created_at > FROM_UNIXTIME(?)
            ");
            $stmt->execute([$last_check_time]);
            $new_users = $stmt->fetchColumn();
            
            if ($new_users > 0) {
                $updates['new_users'] = $new_users;
            }
            
        } else {
            // Check for registration status updates
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM registrations 
                WHERE user_id = ? AND updated_at > FROM_UNIXTIME(?)
            ");
            $stmt->execute([$user_id, $last_check_time]);
            $status_updates = $stmt->fetchColumn();
            
            if ($status_updates > 0) {
                $updates['registration_updates'] = $status_updates;
            }
        }
        
        // Check for new announcements
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM announcements 
            WHERE created_at > FROM_UNIXTIME(?) 
            AND status = 'published'
            AND (target_audience = 'all' OR target_audience = ?)
        ");
        $stmt->execute([$last_check_time, $is_admin ? 'admin' : 'user']);
        $new_announcements = $stmt->fetchColumn();
        
        if ($new_announcements > 0) {
            $updates['new_announcements'] = $new_announcements;
        }
        
    } catch (Exception $e) {
        error_log("Error checking for updates: " . $e->getMessage());
    }
    
    return $updates;
}

// Data export functions for cross-dashboard sharing
function export_dashboard_data($pdo, $data_type, $filters = []) {
    $data = [];
    
    try {
        switch ($data_type) {
            case 'user_activity':
                $sql = "
                    SELECT u.username, u.created_at, 
                           COUNT(DISTINCT r.registration_id) as total_registrations,
                           COUNT(DISTINCT CASE WHEN r.status = 'approved' THEN r.registration_id END) as approved_registrations
                    FROM users u
                    LEFT JOIN registrations r ON u.user_id = r.user_id
                    WHERE 1=1
                ";
                
                if (!empty($filters['date_from'])) {
                    $sql .= " AND u.created_at >= '{$filters['date_from']}'";
                }
                if (!empty($filters['date_to'])) {
                    $sql .= " AND u.created_at <= '{$filters['date_to']}'";
                }
                
                $sql .= " GROUP BY u.user_id ORDER BY u.created_at DESC";
                $data = $pdo->query($sql)->fetchAll();
                break;
                
            case 'event_statistics':
                $sql = "
                    SELECT e.title, e.event_date, e.major_service,
                           COUNT(r.registration_id) as total_registrations,
                           COUNT(CASE WHEN r.status = 'approved' THEN 1 END) as approved_count,
                           COUNT(CASE WHEN r.status = 'pending' THEN 1 END) as pending_count
                    FROM events e
                    LEFT JOIN registrations r ON e.event_id = r.event_id
                    WHERE e.event_date >= CURDATE()
                ";
                
                if (!empty($filters['service'])) {
                    $sql .= " AND e.major_service = '{$filters['service']}'";
                }
                
                $sql .= " GROUP BY e.event_id ORDER BY e.event_date ASC";
                $data = $pdo->query($sql)->fetchAll();
                break;
        }
        
    } catch (Exception $e) {
        error_log("Error exporting dashboard data: " . $e->getMessage());
    }
    
    return $data;
}
?>