<?php

require_once __DIR__ . '/../config.php';
ensure_logged_in();
ensure_admin();

$pdo = $GLOBALS['pdo'];
$errorMessage = '';
$successMessage = '';

// Handle image upload
function uploadImage($file) {
    $uploadDir = __DIR__ . '/../uploads/announcements/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception('Invalid file type. Only JPEG, PNG, and GIF are allowed.');
    }
    
    if ($file['size'] > $maxSize) {
        throw new Exception('File size too large. Maximum 5MB allowed.');
    }
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '.' . $extension;
    $filepath = $uploadDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return 'uploads/announcements/' . $filename;
    } else {
        throw new Exception('Failed to upload image.');
    }
}

// Create public announcement (visible to all users)
function createPublicAnnouncement($title, $content, $imageUrl = null) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        INSERT INTO announcements (title, content, image_url, posted_at)
        VALUES (?, ?, ?, NOW())
    ");
    return $stmt->execute([$title, $content, $imageUrl]);
}

// Handle announcement posting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_announcement'])) {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $imageUrl = null;

    if ($title && $content) {
        try {
            // Handle image upload
            if (isset($_FILES['announcement_image']) && $_FILES['announcement_image']['error'] === UPLOAD_ERR_OK) {
                $imageUrl = uploadImage($_FILES['announcement_image']);
            }
            
            if (createPublicAnnouncement($title, $content, $imageUrl)) {
                $successMessage = "Announcement posted successfully!";
            } else {
                $errorMessage = "Failed to post announcement.";
            }
            
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
        }
    } else {
        $errorMessage = "Both title and content are required.";
    }
}

// Handle announcement deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_announcement'])) {
    $announcement_id = (int)$_POST['announcement_id'];
    if ($announcement_id) {
        // Get image URL to delete file
        $stmt = $pdo->prepare("SELECT image_url FROM announcements WHERE announcement_id = ?");
        $stmt->execute([$announcement_id]);
        $announcement = $stmt->fetch();
        
        // Delete the announcement
        $stmt = $pdo->prepare("DELETE FROM announcements WHERE announcement_id = ?");
        $stmt->execute([$announcement_id]);
        
        // Delete image file if exists
        if ($announcement && $announcement['image_url']) {
            $imagePath = __DIR__ . '/../' . $announcement['image_url'];
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }
        }
        
        $successMessage = "Announcement deleted successfully.";
    }
}

// Get announcements
$stmt = $pdo->query("SELECT * FROM announcements ORDER BY posted_at DESC");
$announcements = $stmt->fetchAll();

// Get total announcements
$total_announcements = $pdo->query("SELECT COUNT(*) FROM announcements")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Announcements - PRC Admin</title>
  <?php $collapsed = isset($_COOKIE['sidebarCollapsed']) && $_COOKIE['sidebarCollapsed'] === 'true'; ?>
  <script>
    (function() {
      var collapsed = document.cookie.split('; ').find(row => row.startsWith('sidebarCollapsed='));
      var root = document.documentElement;
      if (collapsed && collapsed.split('=')[1] === 'true') {
        root.style.setProperty('--sidebar-width', '70px');
      } else {
        root.style.setProperty('--sidebar-width', '250px');
      }
    })();
  </script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/sidebar_admin.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/admin_announcements.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="admin-content">
    <div class="announcements-container">
      <div class="page-header">
        <h1>Manage Announcements</h1>
        <p>Post public announcements visible to all users</p>
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
          <h2><i class="fas fa-plus-circle"></i> Create New Announcement</h2>
          <form method="POST" enctype="multipart/form-data" class="announcement-form">
            <input type="hidden" name="post_announcement" value="1">
            
            <div class="form-group">
              <label for="title">Title</label>
              <input type="text" id="title" name="title" required>
            </div>
            
            <div class="form-group">
              <label for="content">Content</label>
              <textarea id="content" name="content" rows="5" required></textarea>
            </div>
            
            <div class="form-group">
              <label for="announcement_image">Image (Optional)</label>
              <div class="file-upload-wrapper">
                <input type="file" id="announcement_image" name="announcement_image" accept="image/*">
                <div class="file-upload-info">
                  <i class="fas fa-cloud-upload-alt"></i>
                  <span>Choose image or drag and drop</span>
                  <small>JPEG, PNG, GIF up to 5MB</small>
                </div>
              </div>
              <div id="image-preview" class="image-preview" style="display: none;">
                <img id="preview-img" src="" alt="Preview">
                <button type="button" id="remove-image" class="remove-image-btn">
                  <i class="fas fa-times"></i>
                </button>
              </div>
            </div>
            
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-bullhorn"></i> Post Announcement
            </button>
          </form>
        </section>

        <section class="existing-announcements card">
          <div class="section-header">
            <h2><i class="fas fa-list"></i> All Announcements</h2>
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
                  
                  <?php if ($a['image_url']): ?>
                    <div class="announcement-image">
                      <img src="../<?= htmlspecialchars($a['image_url']) ?>" alt="Announcement Image">
                    </div>
                  <?php endif; ?>
                  
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
    // Image upload preview
    document.getElementById('announcement_image').addEventListener('change', function(e) {
      const file = e.target.files[0];
      const preview = document.getElementById('image-preview');
      const previewImg = document.getElementById('preview-img');
      
      if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
          previewImg.src = e.target.result;
          preview.style.display = 'block';
        };
        reader.readAsDataURL(file);
      }
    });
    
    // Remove image preview
    document.getElementById('remove-image').addEventListener('click', function() {
      document.getElementById('announcement_image').value = '';
      document.getElementById('image-preview').style.display = 'none';
    });
  </script>
  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
</body>
</html>