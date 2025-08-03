<?php


require_once __DIR__ . '/../config.php';
ensure_logged_in();
ensure_admin();

$pdo = $GLOBALS['pdo'];
$errorMessage = '';
$successMessage = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_announcement'])) {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);

    if ($title && $content) {
        $stmt = $pdo->prepare("
            INSERT INTO announcements (title, content, posted_at)
            VALUES (?, ?, NOW())
        ");
        $stmt->execute([$title, $content]);
        $successMessage = "Announcement posted successfully!";
    } else {
        $errorMessage = "Both title and content are required.";
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_announcement'])) {
    $announcement_id = (int)$_POST['announcement_id'];
    if ($announcement_id) {
        $stmt = $pdo->prepare("DELETE FROM announcements WHERE announcement_id = ?");
        $stmt->execute([$announcement_id]);
        $successMessage = "Announcement deleted successfully.";
    }
}


$stmt = $pdo->query("SELECT * FROM announcements ORDER BY posted_at DESC");
$announcements = $stmt->fetchAll();


$total_announcements = $pdo->query("SELECT COUNT(*) FROM announcements")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Announcements - PRC Admin</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/sidebar.css?v=<?php echo time(); ?>">  
  <link rel="stylesheet" href="../assets/admin_announcements.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="admin-content">
    <div class="announcements-container">
      <div class="page-header">
        <h1>Manage Announcements</h1>
        <p>Create and manage system-wide announcements</p>
      </div>

      <?php if ($errorMessage): ?>
        <div class="alert error">
          <i class="fas fa-exclamation-circle"></i>
          <?= htmlspecialchars($errorMessage) ?>
        </div>
      <?php endif; ?>
      
      <?php if ($successMessage): ?>
        <div class="alert success">
          <i class="fas fa-check-circle"></i>
          <?= htmlspecialchars($successMessage) ?>
        </div>
      <?php endif; ?>

      <div class="announcement-sections">
        
        <section class="create-announcement card">
          <h2><i class="fas fa-plus-circle"></i> Post New Announcement</h2>
          <form method="POST" class="announcement-form">
            <input type="hidden" name="post_announcement" value="1">
            
            <div class="form-group">
              <label for="title">Title</label>
              <input type="text" id="title" name="title" required>
            </div>
            
            <div class="form-group">
              <label for="content">Content</label>
              <textarea id="content" name="content" rows="5" required></textarea>
            </div>
            
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-bullhorn"></i> Post Announcement
            </button>
          </form>
        </section>

       
        <section class="existing-announcements card">
          <div class="section-header">
            <h2><i class="fas fa-list"></i> Existing Announcements</h2>
            <div class="stats-card">
              <div class="stat-icon blue">
                <i class="fas fa-bullhorn"></i>
              </div>
              <div class="stat-content">
                <h3>Total Announcements</h3>
                <p><?= $total_announcements ?></p>
              </div>
            </div>
          </div>
          
          <?php if (empty($announcements)): ?>
            <div class="empty-state">
              <i class="fas fa-bullhorn"></i>
              <h3>No Announcements</h3>
              <p>There are no announcements to display.</p>
            </div>
          <?php else: ?>
            <div class="announcements-list">
              <?php foreach ($announcements as $a): ?>
                <div class="announcement-card">
                  <div class="announcement-header">
                    <h3><?= htmlspecialchars($a['title']) ?></h3>
                    <span class="announcement-date">
                      <i class="fas fa-calendar-alt"></i>
                      <?= date('F j, Y \a\t g:i a', strtotime($a['posted_at'])) ?>
                    </span>
                  </div>
                  
                  <div class="announcement-content">
                    <?= nl2br(htmlspecialchars($a['content'])) ?>
                  </div>
                  
                  <div class="announcement-actions">
                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this announcement?')">
                      <input type="hidden" name="delete_announcement" value="1">
                      <input type="hidden" name="announcement_id" value="<?= $a['announcement_id'] ?>">
                      <button type="submit" class="btn btn-sm btn-delete">
                        <i class="fas fa-trash-alt"></i> Delete
                      </button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      </div>
    </div>
  </div>
  
  <script>
    
    document.querySelectorAll('.btn-delete').forEach(btn => {
      btn.addEventListener('click', (e) => {
        if (!confirm('Are you sure you want to delete this announcement?')) {
          e.preventDefault();
        }
      });
    });
  </script>
</body>
</html>