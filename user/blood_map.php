<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
ensure_logged_in();

$pdo = $GLOBALS['pdo'];

// Fetch all blood inventory grouped by location
$stmt = $pdo->query("
    SELECT 
        location_name,
        MAX(latitude) as latitude,
        MAX(longitude) as longitude,
        MAX(contact_number) as contact_number,
        MAX(address) as address,
        GROUP_CONCAT(CONCAT(blood_type, ':', units_available) ORDER BY blood_type SEPARATOR '|') as blood_info
    FROM blood_inventory
    GROUP BY location_name
    ORDER BY location_name
");
$locations = $stmt->fetchAll();

// Get blood type statistics
$stmt = $pdo->query("
    SELECT blood_type, SUM(units_available) as total_units
    FROM blood_inventory
    GROUP BY blood_type
    ORDER BY blood_type
");
$bloodStats = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blood Bank Map - PRC Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../assets/sidebar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../assets/manage_volunteers.css?v=<?php echo time(); ?>">
    <style>
        #bloodMap { 
            height: 600px; 
            width: 100%; 
            border-radius: 16px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        .location-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }
        .location-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e5e7eb;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        .location-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }
        .location-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        .location-header {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e5e7eb;
        }
        .location-icon {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            color: white;
            font-size: 1.5rem;
        }
        .location-info h3 {
            margin: 0 0 0.25rem 0;
            font-size: 1.125rem;
            font-weight: 700;
            color: #111827;
        }
        .location-info p {
            margin: 0;
            color: #6b7280;
            font-size: 0.875rem;
        }
        .blood-types {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin: 1rem 0;
        }
        .blood-badge {
            background: #fee;
            color: #c00;
            padding: 0.5rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            border: 1px solid #fcc;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .contact-info {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 2px solid #e5e7eb;
        }
        .contact-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            color: #6b7280;
            font-size: 0.875rem;
        }
        .contact-item i {
            color: #ef4444;
            width: 20px;
        }
        .view-map-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            background: linear-gradient(135deg, #a00000, #800000);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 700;
            transition: all 0.2s ease;
            margin-top: 1rem;
            border: none;
            cursor: pointer;
            width: 100%;
            justify-content: center;
        }
        .view-map-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .blood-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .stat-badge {
            background: white;
            padding: 1rem;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 2px solid #fee;
        }
        .stat-badge-type {
            font-size: 1.25rem;
            font-weight: 800;
            color: #c00;
            margin-bottom: 0.25rem;
        }
        .stat-badge-units {
            font-size: 0.875rem;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="users-container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="header-content">
                <h1><i class="fas fa-tint"></i> Blood Bank Locations</h1>
                <p>Find blood banks near you</p>
            </div>
        </div>

        <!-- Blood Type Stats -->
        <div class="blood-stats">
            <?php foreach ($bloodStats as $stat): ?>
                <div class="stat-badge">
                    <div class="stat-badge-type"><?= htmlspecialchars($stat['blood_type']) ?></div>
                    <div class="stat-badge-units"><?= $stat['total_units'] ?> units</div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Map Section -->
        <div class="users-table-wrapper">
            <div class="table-header">
                <h3 class="table-title"><i class="fas fa-map"></i> Interactive Blood Bank Map</h3>
            </div>
            <div style="padding: 1.5rem;">
                <div id="bloodMap"></div>
            </div>
        </div>

        <!-- Location Cards -->
        <div class="location-cards">
            <?php foreach ($locations as $location): 
                $bloodTypes = [];
                if ($location['blood_info']) {
                    $types = explode('|', $location['blood_info']);
                    foreach ($types as $type) {
                        list($bloodType, $units) = explode(':', $type);
                        $bloodTypes[] = ['type' => $bloodType, 'units' => $units];
                    }
                }
            ?>
                <div class="location-card">
                    <div class="location-header">
                        <div class="location-icon">
                            <i class="fas fa-hospital"></i>
                        </div>
                        <div class="location-info">
                            <h3><?= htmlspecialchars($location['location_name']) ?></h3>
                            <p><i class="fas fa-map-marker-alt"></i> Blood Bank Center</p>
                        </div>
                    </div>

                    <div class="blood-types">
                        <?php foreach ($bloodTypes as $bt): ?>
                            <div class="blood-badge">
                                <i class="fas fa-tint"></i>
                                <span><?= htmlspecialchars($bt['type']) ?></span>
                                <strong><?= $bt['units'] ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="contact-info">
                        <div class="contact-item">
                            <i class="fas fa-phone"></i>
                            <span><?= htmlspecialchars($location['contact_number']) ?></span>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-map-marked-alt"></i>
                            <span><?= htmlspecialchars($location['address']) ?></span>
                        </div>
                    </div>

                    <button class="view-map-btn" onclick="focusLocation(<?= $location['latitude'] ?>, <?= $location['longitude'] ?>)">
                        <i class="fas fa-map-pin"></i> View on Map
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
      <script src="js/general-ui.js?v=<?php echo time(); ?>"></script>
  <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
  <script src="js/header.js?v=<?php echo time(); ?>"></script>
  <?php include 'chat_widget.php'; ?>
    <?php include 'floating_notification_widget.php'; ?>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Initialize map centered on Cebu
        const bloodMap = L.map('bloodMap').setView([10.3157, 123.8854], 12);
        
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(bloodMap);

        // Custom red icon for blood banks
        const redIcon = L.icon({
            iconUrl: 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCAzMCA0MCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cGF0aCBkPSJNMTUgNDBDMTUgNDAgMCAyNSAwIDE1QzAgNi43MTYgNi43MTYgMCAxNSAwQzIzLjI4NCAwIDMwIDYuNzE2IDMwIDE1QzMwIDI1IDE1IDQwIDE1IDQwWiIgZmlsbD0iI2VmNDQ0NCIvPjxwYXRoIGQ9Ik0xNSA4VjIyTTggMTVIMjIiIHN0cm9rZT0id2hpdGUiIHN0cm9rZS13aWR0aD0iMyIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIi8+PC9zdmc+',
            iconSize: [30, 40],
            iconAnchor: [15, 40],
            popupAnchor: [0, -40]
        });

        // Add markers from PHP data
        const locations = <?= json_encode($locations) ?>;
        const markers = [];

        locations.forEach(loc => {
            if (loc.latitude && loc.longitude) {
                // Parse blood types
                let bloodHTML = '';
                if (loc.blood_info) {
                    const types = loc.blood_info.split('|');
                    bloodHTML = '<div style="margin: 10px 0;"><strong>Available Blood Types:</strong><br>';
                    types.forEach(type => {
                        const [bloodType, units] = type.split(':');
                        bloodHTML += `<span style="display: inline-block; background: #fee; color: #c00; padding: 4px 8px; border-radius: 12px; margin: 2px; font-size: 11px; font-weight: bold;">${bloodType}: ${units} units</span> `;
                    });
                    bloodHTML += '</div>';
                }

                const marker = L.marker([loc.latitude, loc.longitude], { icon: redIcon })
                    .bindPopup(`
                        <div style="min-width: 200px;">
                            <h3 style="margin: 0 0 10px 0; color: #111827; font-size: 16px;">
                                <i class="fas fa-hospital"></i> ${loc.location_name}
                            </h3>
                            ${bloodHTML}
                            <p style="margin: 5px 0; color: #6b7280; font-size: 13px;">
                                <i class="fas fa-phone"></i> ${loc.contact_number}
                            </p>
                            <p style="margin: 5px 0; color: #6b7280; font-size: 12px;">
                                <i class="fas fa-map-marker-alt"></i> ${loc.address}
                            </p>
                        </div>
                    `)
                    .addTo(bloodMap);
                markers.push({ marker: marker, lat: loc.latitude, lng: loc.longitude });
            }
        });

        // Function to focus on specific location
        function focusLocation(lat, lng) {
            bloodMap.setView([lat, lng], 16);
            // Find and open the marker popup
            markers.forEach(m => {
                if (m.lat == lat && m.lng == lng) {
                    m.marker.openPopup();
                }
            });
            // Smooth scroll to map
            document.getElementById('bloodMap').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    </script>
</body>
</html>