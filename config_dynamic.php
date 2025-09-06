<?php
/**
 * Dynamic Content Configuration
 * File: api/config_dynamic.php
 * 
 * This file contains configuration settings for the dynamic content system.
 * Modify these values to customize the behavior of your landing page.
 */

// Content Display Limits
define('EVENTS_LIMIT', 6);              // Number of events to show on landing page
define('SESSIONS_LIMIT', 4);            // Number of training sessions to show
define('MERCHANDISE_LIMIT', 8);         // Number of merchandise items to show
define('ANNOUNCEMENTS_LIMIT', 3);       // Number of announcements to show

// Auto-refresh Settings
define('REFRESH_INTERVAL', 30);         // Seconds between content updates (30 = 30 seconds)
define('REFRESH_ON_FOCUS', true);       // Refresh when user returns to tab
define('SHOW_REFRESH_INDICATOR', true); // Show visual indicator when content updates

// Content Truncation Limits
define('EVENT_DESCRIPTION_LENGTH', 120);        // Characters for event descriptions
define('EVENT_LOCATION_LENGTH', 30);            // Characters for event locations
define('SESSION_VENUE_LENGTH', 40);             // Characters for session venues
define('MERCH_DESCRIPTION_LENGTH', 80);         // Characters for merchandise descriptions
define('ANNOUNCEMENT_CONTENT_LENGTH', 150);     // Characters for announcement content

// Display Settings
define('SHOW_EVENTS_SECTION', true);            // Show/hide events section
define('SHOW_SESSIONS_SECTION', true);          // Show/hide training sessions section
define('SHOW_MERCHANDISE_SECTION', true);       // Show/hide merchandise section
define('SHOW_ANNOUNCEMENTS_SECTION', true);     // Show/hide announcements section

// Registration Links
define('EVENT_REGISTRATION_URL', 'user/event_registration.php');           // Event registration page
define('SESSION_REGISTRATION_URL', 'user/training_registration.php');      // Training registration page
define('MERCHANDISE_SHOP_URL', '#');                                       // Merchandise shop (placeholder)

// Image Settings
define('DEFAULT_EVENT_IMAGE', 'assets/images/default-event.jpg');          // Default event image
define('DEFAULT_SESSION_IMAGE', 'assets/images/default-training.jpg');     // Default training image
define('DEFAULT_MERCH_IMAGE', 'assets/images/default-merch.jpg');          // Default merchandise image
define('DEFAULT_ANNOUNCEMENT_IMAGE', 'assets/images/default-news.jpg');    // Default announcement image

// Animation Settings
define('ENABLE_ANIMATIONS', true);              // Enable/disable animations
define('ANIMATION_DURATION', 300);              // Animation duration in milliseconds
define('ENABLE_PARALLAX', true);                // Enable/disable parallax effects

// Mobile Settings
define('MOBILE_BREAKPOINT', 768);               // Mobile breakpoint in pixels
define('TOUCH_FRIENDLY', true);                 // Enable touch-friendly interactions

// Cache Settings (for future implementation)
define('ENABLE_CACHING', false);                // Enable content caching
define('CACHE_DURATION', 300);                  // Cache duration in seconds (5 minutes)

// Debug Settings
define('DEBUG_MODE', false);                    // Enable debug mode
define('LOG_API_CALLS', false);                 // Log API calls for debugging

// Color Theme Settings (CSS custom properties will override these)
$theme_colors = [
    'primary' => '#a00000',                     // PRC Red
    'primary_dark' => '#800000',                // Darker PRC Red
    'secondary' => '#2196F3',                   // Blue
    'success' => '#4CAF50',                     // Green
    'warning' => '#FF9800',                     // Orange
    'danger' => '#f44336',                      // Red
    'info' => '#17a2b8',                        // Cyan
    'light' => '#f8f9fa',                       // Light Gray
    'dark' => '#343a40'                         // Dark Gray
];

// Service Categories and Colors
$service_colors = [
    'Health Service' => '#4CAF50',
    'Safety Service' => '#FF5722',
    'Welfare Service' => '#2196F3',
    'Disaster Management Service' => '#FF9800',
    'Red Cross Youth' => '#9C27B0'
];

// Merchandise Category Icons
$merchandise_icons = [
    'clothing' => 'tshirt',
    'accessories' => 'hat-cowboy',
    'supplies' => 'first-aid',
    'books' => 'book',
    'collectibles' => 'medal',
    'other' => 'box'
];

// Status Display Settings
$status_settings = [
    'show_capacity' => true,                    // Show capacity information
    'show_registration_count' => true,         // Show registration counts
    'show_instructor_info' => true,            // Show instructor information
    'show_event_duration' => true,             // Show event duration
    'show_fees' => true,                       // Show event/session fees
    'show_stock_levels' => true                // Show merchandise stock levels
];

// Date and Time Format Settings
define('DATE_FORMAT', 'M j, Y');               // Date format for display
define('TIME_FORMAT', 'g:i A');                // Time format for display
define('DATETIME_FORMAT', 'M j, Y \a\t g:i A'); // Combined date/time format

// SEO and Meta Settings
$seo_settings = [
    'auto_generate_meta' => true,               // Auto-generate meta descriptions
    'meta_description_length' => 160,          // Meta description length limit
    'enable_schema_markup' => true,            // Enable JSON-LD schema markup
    'default_meta_description' => 'Join Philippine Red Cross events, training sessions, and support our humanitarian mission.',
    'default_meta_keywords' => 'Philippine Red Cross, humanitarian, events, training, first aid, blood donation, disaster response'
];

// Performance Settings
$performance_settings = [
    'lazy_load_images' => true,                // Enable lazy loading for images
    'compress_responses' => true,              // Compress API responses
    'minimize_dom_updates' => true,            // Minimize DOM updates for better performance
    'debounce_scroll' => true,                 // Debounce scroll events
    'prefetch_next_content' => false           // Prefetch next content (experimental)
];

// Accessibility Settings
$accessibility_settings = [
    'high_contrast_mode' => false,             // Enable high contrast mode
    'reduce_motion' => false,                  // Respect prefers-reduced-motion
    'screen_reader_optimized' => true,        // Optimize for screen readers
    'keyboard_navigation' => true,            // Enable keyboard navigation
    'focus_indicators' => true                // Show focus indicators
];

// Social Media Integration
$social_settings = [
    'enable_sharing' => false,                 // Enable social media sharing
    'facebook_app_id' => '',                  // Facebook App ID
    'twitter_handle' => '@PhilippineRC',      // Twitter handle
    'og_image_default' => 'assets/images/og-default.jpg' // Default Open Graph image
];

// Email Notification Settings (for future implementation)
$notification_settings = [
    'notify_on_new_events' => false,          // Send email notifications for new events
    'notify_on_new_sessions' => false,        // Send email notifications for new training
    'notify_on_announcements' => false,       // Send email notifications for announcements
    'admin_email' => 'admin@redcross.org.ph', // Admin email for notifications
    'notification_from_email' => 'noreply@redcross.org.ph' // From email address
];

// Error Handling Settings
$error_settings = [
    'show_errors_to_public' => false,         // Show detailed errors to public users
    'log_errors' => true,                     // Log errors to file
    'error_log_file' => 'logs/dynamic_content_errors.log', // Error log file path
    'fallback_content' => true,              // Show fallback content on errors
    'graceful_degradation' => true           // Gracefully handle missing content
];

// Content Filtering
$content_filters = [
    'filter_past_events' => true,            // Hide past events
    'filter_out_of_stock' => true,           // Hide out-of-stock merchandise
    'filter_inactive_sessions' => true,      // Hide inactive training sessions
    'filter_draft_announcements' => true,    // Hide draft announcements (if status field exists)
    'respect_availability_flags' => true     // Respect is_available flags
];

// API Response Settings
$api_settings = [
    'include_metadata' => true,               // Include metadata in API responses
    'compress_json' => false,                 // Compress JSON responses
    'cors_enabled' => true,                   // Enable CORS headers
    'rate_limiting' => false,                 // Enable rate limiting (future implementation)
    'api_version' => '1.0',                   // API version
    'response_format' => 'json'               // Response format
];

// Backup and Recovery Settings
$backup_settings = [
    'enable_content_backup' => false,        // Enable automatic content backup
    'backup_frequency' => 'daily',           // Backup frequency
    'backup_retention_days' => 30,           // Days to keep backups
    'backup_location' => 'backups/content'   // Backup storage location
];

// Feature Flags (for gradual rollout)
$feature_flags = [
    'enable_real_time_updates' => true,      // Enable real-time updates
    'enable_push_notifications' => false,    // Enable push notifications (future)
    'enable_offline_mode' => false,          // Enable offline mode (future)
    'enable_dark_mode' => false,             // Enable dark mode toggle
    'enable_multi_language' => false,        // Enable multiple languages (future)
    'enable_advanced_search' => false,       // Enable advanced search features
    'enable_calendar_integration' => false,  // Enable calendar integration
    'enable_social_login' => false           // Enable social media login
];

/**
 * Get configuration value with fallback
 */
function getDynamicConfig($key, $default = null) {
    global $theme_colors, $service_colors, $merchandise_icons, $status_settings;
    global $seo_settings, $performance_settings, $accessibility_settings;
    global $social_settings, $notification_settings, $error_settings;
    global $content_filters, $api_settings, $backup_settings, $feature_flags;
    
    // Check if constant exists
    if (defined($key)) {
        return constant($key);
    }
    
    // Check arrays
    $arrays = [
        'theme_colors', 'service_colors', 'merchandise_icons', 'status_settings',
        'seo_settings', 'performance_settings', 'accessibility_settings',
        'social_settings', 'notification_settings', 'error_settings',
        'content_filters', 'api_settings', 'backup_settings', 'feature_flags'
    ];
    
    foreach ($arrays as $array_name) {
        if (isset($array_name[$key])) {
            return $array_name[$key];
        }
    }
    
    return $default;
}

/**
 * Check if feature is enabled
 */
function isFeatureEnabled($feature) {
    global $feature_flags;
    return isset($feature_flags[$feature]) ? $feature_flags[$feature] : false;
}

/**
 * Get theme color
 */
function getThemeColor($color) {
    global $theme_colors;
    return isset($theme_colors[$color]) ? $theme_colors[$color] : '#000000';
}

/**
 * Get service color
 */
function getServiceColor($service) {
    global $service_colors;
    return isset($service_colors[$service]) ? $service_colors[$service] : '#6c757d';
}

/**
 * Get merchandise icon
 */
function getMerchandiseIcon($category) {
    global $merchandise_icons;
    return isset($merchandise_icons[$category]) ? $merchandise_icons[$category] : 'box';
}

/**
 * Validate configuration
 */
function validateDynamicConfig() {
    $errors = [];
    
    // Check required constants
    $required_constants = [
        'EVENTS_LIMIT', 'SESSIONS_LIMIT', 'MERCHANDISE_LIMIT', 
        'ANNOUNCEMENTS_LIMIT', 'REFRESH_INTERVAL'
    ];
    
    foreach ($required_constants as $constant) {
        if (!defined($constant)) {
            $errors[] = "Missing required constant: $constant";
        }
    }
    
    // Validate limits
    if (defined('EVENTS_LIMIT') && (EVENTS_LIMIT < 1 || EVENTS_LIMIT > 50)) {
        $errors[] = "EVENTS_LIMIT must be between 1 and 50";
    }
    
    if (defined('REFRESH_INTERVAL') && (REFRESH_INTERVAL < 10 || REFRESH_INTERVAL > 300)) {
        $errors[] = "REFRESH_INTERVAL must be between 10 and 300 seconds";
    }
    
    return $errors;
}

// Validate configuration on load
$config_errors = validateDynamicConfig();
if (!empty($config_errors) && getDynamicConfig('DEBUG_MODE', false)) {
    error_log("Dynamic Content Configuration Errors: " . implode(', ', $config_errors));
}

/**
 * Export configuration for JavaScript
 * Use this function to safely pass configuration to the frontend
 */
function exportConfigToJS() {
    $js_config = [
        'refreshInterval' => getDynamicConfig('REFRESH_INTERVAL', 30) * 1000, // Convert to milliseconds
        'showRefreshIndicator' => getDynamicConfig('SHOW_REFRESH_INDICATOR', true),
        'enableAnimations' => getDynamicConfig('ENABLE_ANIMATIONS', true),
        'animationDuration' => getDynamicConfig('ANIMATION_DURATION', 300),
        'mobileBreakpoint' => getDynamicConfig('MOBILE_BREAKPOINT', 768),
        'debugMode' => getDynamicConfig('DEBUG_MODE', false),
        'apiVersion' => getDynamicConfig('api_version', '1.0'),
        'featureFlags' => [
            'realTimeUpdates' => isFeatureEnabled('enable_real_time_updates'),
            'pushNotifications' => isFeatureEnabled('enable_push_notifications'),
            'darkMode' => isFeatureEnabled('enable_dark_mode')
        ]
    ];
    
    return json_encode($js_config);
}

// Environment-specific overrides
if (file_exists(__DIR__ . '/config_dynamic_local.php')) {
    include __DIR__ . '/config_dynamic_local.php';
}

?>