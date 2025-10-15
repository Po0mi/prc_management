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

$username = current_username();
$pdo = $GLOBALS['pdo'];

// Handle form submissions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add_blood':
                    $stmt = $pdo->prepare("
                        INSERT INTO blood_inventory (location_name, blood_type, units_available, latitude, longitude, contact_number, address)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $_POST['location_name'],
                        $_POST['blood_type'],
                        $_POST['units_available'],
                        $_POST['latitude'],
                        $_POST['longitude'],
                        $_POST['contact_number'],
                        $_POST['address']
                    ]);
                    $message = "Blood inventory added successfully!";
                    break;

                case 'update_blood':
                    $stmt = $pdo->prepare("
                        UPDATE blood_inventory 
                        SET location_name = ?, blood_type = ?, units_available = ?, 
                            latitude = ?, longitude = ?, contact_number = ?, address = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $_POST['location_name'],
                        $_POST['blood_type'],
                        $_POST['units_available'],
                        $_POST['latitude'],
                        $_POST['longitude'],
                        $_POST['contact_number'],
                        $_POST['address'],
                        $_POST['id']
                    ]);
                    $message = "Blood inventory updated successfully!";
                    break;

                case 'delete_blood':
                    $stmt = $pdo->prepare("DELETE FROM blood_inventory WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    $message = "Blood inventory deleted successfully!";
                    break;
            }
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Fetch all blood inventory
$stmt = $pdo->query("SELECT * FROM blood_inventory ORDER BY location_name, blood_type");
$bloodInventory = $stmt->fetchAll();

// Get statistics
$stmt = $pdo->query("SELECT COUNT(DISTINCT location_name) as total_locations FROM blood_inventory");
$stats = $stmt->fetch();

$stmt = $pdo->query("SELECT SUM(units_available) as total_units FROM blood_inventory");
$totalUnits = $stmt->fetch();

$stmt = $pdo->query("SELECT COUNT(DISTINCT blood_type) as blood_types FROM blood_inventory");
$bloodTypes = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blood Bank Management - PRC Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../assets/sidebar_admin.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../assets/manage_volunteers.css?v=<?php echo time(); ?>">
    <style>
        #map { height: 500px; width: 100%; border-radius: 12px; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="users-container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="header-content">
                <h1><i class="fas fa-tint"></i> Blood Bank Management</h1>
                <p>Manage blood inventory and locations</p>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($message): ?>
            <div class="alert success">
                <i class="fas fa-check-circle"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- Stats Overview -->
        <div class="stats-overview">
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                    <i class="fas fa-map-marker-alt"></i>
                </div>
                <div>
                    <div><?= $stats['total_locations'] ?></div>
                    <div>Locations</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                    <i class="fas fa-tint"></i>
                </div>
                <div>
                    <div><?= $totalUnits['total_units'] ?></div>
                    <div>Total Units</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #3b82f6, #2563eb);">
                    <i class="fas fa-vials"></i>
                </div>
                <div>
                    <div><?= $bloodTypes['blood_types'] ?></div>
                    <div>Blood Types</div>
                </div>
            </div>
        </div>

        <!-- Action Bar -->
        <div class="action-bar">
            <div class="search-filter-row">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search locations or blood type...">
                </div>
                <button class="btn-create" onclick="openModal()">
                    <i class="fas fa-plus"></i> Add Blood Inventory
                </button>
            </div>
        </div>

        <!-- Map Section -->
        <div class="users-table-wrapper">
            <div class="table-header">
                <h3 class="table-title"><i class="fas fa-map"></i> Blood Bank Locations Map</h3>
            </div>
            <div style="padding: 1.5rem;">
                <div id="map"></div>
            </div>
        </div>

        <!-- Blood Inventory Table -->
        <div class="users-table-wrapper">
            <div class="table-header">
                <h3 class="table-title">
                    <i class="fas fa-list"></i> Blood Inventory
                    <span class="volunteer-count"><?= count($bloodInventory) ?> Records</span>
                </h3>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Location</th>
                        <th>Blood Type</th>
                        <th>Units Available</th>
                        <th>Contact</th>
                        <th>Address</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <?php foreach ($bloodInventory as $item): ?>
                        <tr>
                            <td>
                                <div class="user-info">
                                    <div class="user-avatar" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                                        <i class="fas fa-hospital"></i>
                                    </div>
                                    <div>
                                        <div class="user-name"><?= htmlspecialchars($item['location_name']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="role-badge" style="background: #fee; color: #c00; border-color: #fcc;">
                                    <?= htmlspecialchars($item['blood_type']) ?>
                                </span>
                            </td>
                            <td><strong><?= $item['units_available'] ?> units</strong></td>
                            <td><?= htmlspecialchars($item['contact_number']) ?></td>
                            <td><?= htmlspecialchars(substr($item['address'], 0, 50)) ?>...</td>
                            <td>
                                <div class="actions">
                                    <button class="btn-action btn-edit" onclick='editBlood(<?= json_encode($item) ?>)'>
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <form method="POST" class="inline-form" onsubmit="return confirm('Delete this inventory?')">
                                        <input type="hidden" name="action" value="delete_blood">
                                        <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                        <button type="submit" class="btn-action btn-delete">
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
    </div>

    <!-- Add/Edit Modal -->
    <div id="bloodModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Add Blood Inventory</h2>
                <button class="close-modal" onclick="closeModal()">×</button>
            </div>
            <form method="POST" id="bloodForm">
                <input type="hidden" name="action" id="formAction" value="add_blood">
                <input type="hidden" name="id" id="bloodId">
                
                <div class="form-section">
                    <h3 class="section-title"><i class="fas fa-info-circle"></i> Location Information</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-hospital"></i> Location Name</label>
                            <input type="text" name="location_name" id="location_name" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-tint"></i> Blood Type</label>
                            <select name="blood_type" id="blood_type" required>
                                <option value="">Select Blood Type</option>
                                <option value="A+">A+</option>
                                <option value="A-">A-</option>
                                <option value="B+">B+</option>
                                <option value="B-">B-</option>
                                <option value="AB+">AB+</option>
                                <option value="AB-">AB-</option>
                                <option value="O+">O+</option>
                                <option value="O-">O-</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-vials"></i> Units Available</label>
                            <input type="number" name="units_available" id="units_available" min="0" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Contact Number</label>
                            <input type="text" name="contact_number" id="contact_number" required>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3 class="section-title"><i class="fas fa-map-marker-alt"></i> Location Coordinates</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-map-pin"></i> Latitude</label>
                            <input type="number" step="any" name="latitude" id="latitude" placeholder="e.g., 10.3157" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-map-pin"></i> Longitude</label>
                            <input type="number" step="any" name="longitude" id="longitude" placeholder="e.g., 123.8854" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-map-marked-alt"></i> Full Address</label>
                        <textarea name="address" id="address" rows="3" required></textarea>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Save Blood Inventory
                </button>
            </form>
        </div>
    </div>
    
  <script src="../admin/js/notification_frontend.js?v=<?php echo time(); ?>"></script>
  <script src="../admin/js/sidebar-notifications.js?v=<?php echo time(); ?>"></script>
  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
  <script src="../user/js/sidebar.js?v=<?php echo time(); ?>"></script>
  <script src="../user/js/header.js?v=<?php echo time(); ?>"></script>
  <?php include 'chat_widget.php'; ?>
  <?php include 'floating_notification_widget.php'; ?>                 
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Initialize map
        const map = L.map('map').setView([10.3157, 123.8854], 12);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        // Add markers from PHP data
        const bloodData = <?= json_encode($bloodInventory) ?>;
        const markers = [];

        bloodData.forEach(item => {
            if (item.latitude && item.longitude) {
                const marker = L.marker([item.latitude, item.longitude])
                    .bindPopup(`
                        <strong>${item.location_name}</strong><br>
                        Blood Type: <strong>${item.blood_type}</strong><br>
                        Units: <strong>${item.units_available}</strong><br>
                        Contact: ${item.contact_number}<br>
                        <small>${item.address}</small>
                    `)
                    .addTo(map);
                markers.push(marker);
            }
        });

        // Modal functions
        function openModal() {
            document.getElementById('bloodModal').classList.add('active');
            document.getElementById('modalTitle').textContent = 'Add Blood Inventory';
            document.getElementById('formAction').value = 'add_blood';
            document.getElementById('bloodForm').reset();
        }

        function closeModal() {
            document.getElementById('bloodModal').classList.remove('active');
        }

        function editBlood(data) {
            document.getElementById('bloodModal').classList.add('active');
            document.getElementById('modalTitle').textContent = 'Edit Blood Inventory';
            document.getElementById('formAction').value = 'update_blood';
            document.getElementById('bloodId').value = data.id;
            document.getElementById('location_name').value = data.location_name;
            document.getElementById('blood_type').value = data.blood_type;
            document.getElementById('units_available').value = data.units_available;
            document.getElementById('contact_number').value = data.contact_number;
            document.getElementById('latitude').value = data.latitude;
            document.getElementById('longitude').value = data.longitude;
            document.getElementById('address').value = data.address;
        }

        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const search = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('#tableBody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(search) ? '' : 'none';
            });
        });

        // Close modal on outside click
        document.getElementById('bloodModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
    </script>
</body>
</html>