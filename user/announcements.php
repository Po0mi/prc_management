<?php
require_once __DIR__ . '/../config.php';
ensure_logged_in();

$user_role = get_user_role();
if ($user_role) {
    // If user has an admin role, redirect to admin dashboard
    header("Location: /admin/dashboard.php");
    exit;
}

$pdo = $GLOBALS['pdo'];
$stmt = $pdo->query("SELECT * FROM announcements ORDER BY posted_at DESC");
$announcements = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Announcements - PRC Portal</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/sidebar.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/announcements.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/dashboard.css?v=<?php echo time(); ?>">
     <link rel="stylesheet" href="../assets/header.css?v=<?php echo time(); ?>">

</head>
<body>
  <?php include '../user/sidebar.php'; ?>
  
  <div class="header-content">
    <?php include 'header.php'; ?>
    
    <div class="announcements-container">
      <div class="page-header">
        <h1>Announcements</h1>
        <p>Latest updates and important notices</p>
      </div>

      <div class="announcements-content">
        <div class="announcements-toolbar">
          <div class="search-box">
            <input type="text" placeholder="Search announcements...">
            <button type="submit"><i class="fas fa-search"></i></button>
          </div>
          <div class="filter-dropdown">
            <select>
              <option>All Categories</option>
              <option>Events</option>
              <option>Training</option>
              <option>Urgent</option>
              <option>General</option>
            </select>
            <i class="fas fa-chevron-down"></i>
          </div>
        </div>

        <?php if (empty($announcements)): ?>
          <div class="empty-state">
            <i class="fas fa-bullhorn"></i>
            <h3>No Announcements Available</h3>
            <p>There are currently no announcements. Please check back later for updates.</p>
          </div>
        <?php else: ?>
          <div class="announcements-list">
            <?php foreach ($announcements as $a):
               $isUrgent = strpos(strtolower($a['title']), 'urgent') !== false;
            ?>
              <div class="announcement-item <?= $isUrgent ? 'urgent' : '' ?>">
                <div class="announcement-header">
                  <div class="announcement-meta">
                    <span class="announcement-date">
                      <i class="far fa-clock"></i>
                      <?= date('M j, Y \a\t g:i a', strtotime($a['posted_at'])) ?>
                    </span>
                    <?php if ($isUrgent): ?>
                      <span class="status-badge urgent-badge">
                        <i class="fas fa-exclamation-circle"></i> Urgent
                      </span>
                    <?php endif; ?>
                  </div>
                  <h3 class="announcement-title"><?= htmlspecialchars($a['title']) ?></h3>
                </div>
                <div class="announcement-body">
                  <?php if (isset($a['image_url']) && $a['image_url']): ?>
                    <div class="announcement-image">
                      <img src="../<?= htmlspecialchars($a['image_url']) ?>" alt="Announcement Image">
                    </div>
                  <?php endif; ?>
                  <p><?= nl2br(htmlspecialchars($a['content'])) ?></p>
                </div>
                <div class="announcement-footer">
                  <button class="save-btn">
                    <i class="far fa-bookmark"></i> Save
                  </button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <script src="js/announcements.js"></script>
    <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
    <script src="js/general-ui.js?v=<?php echo time(); ?>"></script>
    <script src="js/header.js?v=<?php echo time(); ?>"></script>
</body>
</html>