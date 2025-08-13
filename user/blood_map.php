<?php


require_once __DIR__ . '/../config.php';
ensure_logged_in();
if (current_user_role() !== 'user') {
    header("Location: /admin/dashboard.php");
    exit;
}

$pdo = $GLOBALS['pdo'];

$banksStmt = $pdo->query("
  SELECT bb.bank_id, bb.branch_name, bb.address, bb.latitude, bb.longitude,
         bi.blood_type, bi.units_available
  FROM blood_banks bb
  LEFT JOIN blood_inventory bi ON bb.bank_id = bi.bank_id
  ORDER BY bb.branch_name
");
$rows = $banksStmt->fetchAll();

$banks = [];
foreach ($rows as $r) {
    $id = $r['bank_id'];
    if (!isset($banks[$id])) {
        $banks[$id] = [
            'branch_name' => $r['branch_name'],
            'address'     => $r['address'],
            'latitude'    => $r['latitude'],
            'longitude'   => $r['longitude'],
            'inventory'   => []
        ];
    }
    if ($r['blood_type']) {
        $banks[$id]['inventory'][] = [
            'blood_type'     => $r['blood_type'],
            'units_available'=> $r['units_available']
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Blood Map - PRC Portal</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/sidebar.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/blood_map.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/dashboard.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css"/>
  <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
    <div class="header-content">
    <?php include 'header.php'; ?>
    
  
    <div class="blood-map-container">
      <div class="page-header">
        <h1 class="blood_map_title">Blood Supply Map</h1>
      </div>

      <?php if (empty($banks)): ?>
        <div class="empty-state">
          <i class="fas fa-map-marked-alt"></i>
          <h3>No Blood Banks Found</h3>
          <p>There are currently no blood bank locations available in our system.</p>
        </div>
      <?php else: ?>
        <div id="map"></div>

        <section class="bank-list">
          <h3 class="section-title">Branch Inventory Details</h3>
          <div class="bank-cards">
            <?php foreach ($banks as $b): ?>
              <div class="bank-card">
                <div class="bank-header">
                  <h4><?= htmlspecialchars($b['branch_name']) ?></h4>
                  <span class="bank-location">
                    <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($b['address']) ?>
                  </span>
                </div>
                <div class="inventory-list">
                  <?php if (empty($b['inventory'])): ?>
                    <div class="inventory-item">
                      <span class="no-inventory">No inventory data available</span>
                    </div>
                  <?php else: ?>
                    <?php foreach ($b['inventory'] as $inv): ?>
                      <div class="inventory-item">
                        <span class="blood-type"><?= htmlspecialchars($inv['blood_type']) ?></span>
                        <span class="units-available"><?= (int)$inv['units_available'] ?> units</span>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
    </div>
  </div>

  <script>
    <?php if (!empty($banks)): ?>
      const map = L.map('map').setView([10.7202, 122.5621], 13);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
      }).addTo(map);

      <?php foreach ($banks as $b): ?>
        (function() {
          const lat = <?= (float)$b['latitude'] ?>;
          const lon = <?= (float)$b['longitude'] ?>;
          const branch = <?= json_encode($b['branch_name']) ?>;
          const address = <?= json_encode($b['address']) ?>;
          
          let inventoryList = "";
          <?php if (empty($b['inventory'])): ?>
            inventoryList = "<div style='padding:0.5rem;color:#6c757d;font-style:italic;'>No inventory data available</div>";
          <?php else: ?>
            inventoryList = "<div style='max-height:200px;overflow-y:auto;padding:0.25rem;'>";
            <?php foreach ($b['inventory'] as $inv): ?>
              inventoryList += `
                <div style="display:flex;justify-content:space-between;align-items:center;padding:0.5rem;margin:0.25rem 0;background:rgba(204,0,0,0.05);border-radius:6px;">
                  <strong style="color:#333;"><?= $inv['blood_type'] ?></strong>
                  <span style="background:#cc0000;color:white;padding:0.15rem 0.75rem;border-radius:12px;font-size:0.8em;"><?= $inv['units_available'] ?> units</span>
                </div>
              `;
            <?php endforeach; ?>
            inventoryList += "</div>";
          <?php endif; ?>
          
          const popupContent = `
            <div style="min-width:220px;">
              <h4 style="margin:0 0 0.5rem 0;color:#cc0000;font-size:1.1em;border-bottom:1px solid #eee;padding-bottom:0.5rem;">${branch}</h4>
              <p style="margin:0 0 0.75rem 0;font-size:0.9em;color:#555;">${address}</p>
              <h5 style="margin:0 0 0.5rem 0;font-size:0.95em;color:#333;">Current Inventory:</h5>
              ${inventoryList}
            </div>
          `;
          
          L.marker([lat, lon], {
            icon: L.divIcon({
              html: '<div style="background:#cc0000;width:32px;height:32px;border-radius:50%;display:flex;justify-content:center;align-items:center;color:white;"><i class="fas fa-tint" style="font-size:16px;"></i></div>',
              iconSize: [32, 32],
              className: 'blood-bank-marker'
            })
          })
          .addTo(map)
          .bindPopup(popupContent);
        })();
      <?php endforeach; ?>
    <?php endif; ?>
  </script>
    <script src="js/general-ui.js?v=<?php echo time(); ?>"></script>
    <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
    <script src="js/darkmode.js?v=<?php echo time(); ?>"></script>
    <script src="js/header.js?v=<?php echo time(); ?>"></script>
</body>
</html>