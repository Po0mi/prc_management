<?php

require_once __DIR__ . '/../config.php';
ensure_logged_in();
ensure_admin();

$pdo = $GLOBALS['pdo'];
$errorMessage = '';
$successMessage = '';

// Get current view (active or archive)
$view = $_GET['view'] ?? 'active';

// Get current filter month
$filterMonth = $_GET['month'] ?? 'all';

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
        INSERT INTO announcements (title, content, image_url, posted_at, archived)
        VALUES (?, ?, ?, NOW(), 0)
    ");
    return $stmt->execute([$title, $content, $imageUrl]);
}

// Update announcement
function updateAnnouncement($id, $title, $content, $imageUrl = null) {
    global $pdo;
    
    if ($imageUrl !== null) {
        $stmt = $pdo->prepare("
            UPDATE announcements 
            SET title = ?, content = ?, image_url = ?, updated_at = NOW()
            WHERE announcement_id = ?
        ");
        return $stmt->execute([$title, $content, $imageUrl, $id]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE announcements 
            SET title = ?, content = ?, updated_at = NOW()
            WHERE announcement_id = ?
        ");
        return $stmt->execute([$title, $content, $id]);
    }
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

// Handle announcement editing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_announcement'])) {
    $announcement_id = (int)$_POST['announcement_id'];
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $imageUrl = null;

    if ($announcement_id && $title && $content) {
        try {
            // Get current announcement for image handling
            $stmt = $pdo->prepare("SELECT image_url FROM announcements WHERE announcement_id = ?");
            $stmt->execute([$announcement_id]);
            $currentAnnouncement = $stmt->fetch();
            
            // Handle image upload
            if (isset($_FILES['announcement_image']) && $_FILES['announcement_image']['error'] === UPLOAD_ERR_OK) {
                // Delete old image if exists
                if ($currentAnnouncement && $currentAnnouncement['image_url']) {
                    $oldImagePath = __DIR__ . '/../' . $currentAnnouncement['image_url'];
                    if (file_exists($oldImagePath)) {
                        unlink($oldImagePath);
                    }
                }
                $imageUrl = uploadImage($_FILES['announcement_image']);
            } else if (isset($_POST['remove_current_image']) && $_POST['remove_current_image'] === '1') {
                // Remove current image
                if ($currentAnnouncement && $currentAnnouncement['image_url']) {
                    $oldImagePath = __DIR__ . '/../' . $currentAnnouncement['image_url'];
                    if (file_exists($oldImagePath)) {
                        unlink($oldImagePath);
                    }
                }
                $imageUrl = '';
            }
            
            if (updateAnnouncement($announcement_id, $title, $content, $imageUrl)) {
                $successMessage = "Announcement updated successfully!";
            } else {
                $errorMessage = "Failed to update announcement.";
            }
            
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
        }
    } else {
        $errorMessage = "Title and content are required.";
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
        
        $successMessage = "Announcement deleted permanently.";
    }
}

// Handle announcement archiving
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_announcement'])) {
    $announcement_id = (int)$_POST['announcement_id'];
    if ($announcement_id) {
        $stmt = $pdo->prepare("UPDATE announcements SET archived = 1 WHERE announcement_id = ?");
        $stmt->execute([$announcement_id]);
        $successMessage = "Announcement archived successfully.";
    }
}

// Handle announcement unarchiving (restore from archive)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_announcement'])) {
    $announcement_id = (int)$_POST['announcement_id'];
    if ($announcement_id) {
        $stmt = $pdo->prepare("UPDATE announcements SET archived = 0 WHERE announcement_id = ?");
        $stmt->execute([$announcement_id]);
        $successMessage = "Announcement restored successfully.";
    }
}

// Get announcements based on view and filter
// Get announcements based on view and filter
if ($view === 'archive') {
    // Get archived announcements
    if ($filterMonth === 'all') {
        $stmt = $pdo->prepare("
            SELECT * FROM announcements 
            WHERE archived = 1 
            ORDER BY posted_at DESC
        ");
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare("
            SELECT * FROM announcements 
            WHERE archived = 1 
            AND DATE_FORMAT(posted_at, '%Y-%m') = ?
            ORDER BY posted_at DESC
        ");
        $stmt->execute([$filterMonth]);
    }
    $announcements = $stmt->fetchAll();
} else {
    // Get active announcements
    if ($filterMonth === 'all') {
        $stmt = $pdo->prepare("
            SELECT * FROM announcements 
            WHERE archived = 0 
            ORDER BY posted_at DESC
        ");
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare("
            SELECT * FROM announcements 
            WHERE archived = 0 
            AND DATE_FORMAT(posted_at, '%Y-%m') = ?
            ORDER BY posted_at DESC
        ");
        $stmt->execute([$filterMonth]);
    }
    $announcements = $stmt->fetchAll();
}

// Get statistics
$total_active = $pdo->query("SELECT COUNT(*) FROM announcements WHERE archived = 0")->fetchColumn();
$total_archived = $pdo->query("SELECT COUNT(*) FROM announcements WHERE archived = 1")->fetchColumn();

// Get available months for filter
$stmt = $pdo->query("
    SELECT DISTINCT DATE_FORMAT(posted_at, '%Y-%m') as month_year,
                   DATE_FORMAT(posted_at, '%M %Y') as month_name
    FROM announcements 
    ORDER BY month_year DESC
");
$availableMonths = $stmt->fetchAll();

// Get specific announcement for editing
$editAnnouncement = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM announcements WHERE announcement_id = ?");
    $stmt->execute([$editId]);
    $editAnnouncement = $stmt->fetch();
}
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
        <p>Post and manage public announcements visible to all users</p>
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

      <!-- Statistics Overview -->
      <div class="stats-overview">
        <div class="stat-item">
          <i class="fas fa-bullhorn"></i>
          <h3><?= $total_active ?></h3>
          <p>Active Announcements</p>
        </div>
        <div class="stat-item">
          <i class="fas fa-archive"></i>
          <h3><?= $total_archived ?></h3>
          <p>Archived Announcements</p>
        </div>
      </div>

      <!-- View Toggle -->
      <div class="view-toggle">
        <a href="?view=active&month=<?= htmlspecialchars($filterMonth) ?>" 
           class="btn <?= $view === 'active' ? 'active' : '' ?>">
          <i class="fas fa-bullhorn"></i> Active Announcements
        </a>
        <a href="?view=archive&month=<?= htmlspecialchars($filterMonth) ?>" 
           class="btn <?= $view === 'archive' ? 'active' : '' ?>">
          <i class="fas fa-archive"></i> Archived Announcements
        </a>
      </div>

      <!-- Filter Section -->
      <div class="filter-section">
        <div class="filter-controls">
          <label for="month-filter"><strong>Filter by Month:</strong></label>
     <select id="month-filter" onchange="filterByMonth()">
    <option value="all" <?= $filterMonth === 'all' ? 'selected' : '' ?>>
        All Months
    </option>
    <?php foreach ($availableMonths as $month): ?>
      <option value="<?= $month['month_year'] ?>" 
              <?= $month['month_year'] === $filterMonth ? 'selected' : '' ?>>
        <?= $month['month_name'] ?>
      </option>
    <?php endforeach; ?>
</select>
          
          <?php if ($view === 'archive'): ?>
            <div class="archive-notice">
              <i class="fas fa-info-circle"></i>
              You are viewing archived announcements. These can be restored or permanently deleted.
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="announcement-sections">
        
        <?php if ($view === 'active'): ?>
        <!-- Create/Edit Announcement Form -->
        <section class="create-announcement card">
          <h2>
            <i class="fas fa-<?= $editAnnouncement ? 'edit' : 'plus-circle' ?>"></i> 
            <?= $editAnnouncement ? 'Edit Announcement' : 'Create New Announcement' ?>
          </h2>
          
          <?php if ($editAnnouncement): ?>
            <div class="edit-form">
              <div style="display: flex; justify-content: between; align-items: center; margin-bottom: 15px;">
                <h3>Editing: <?= htmlspecialchars($editAnnouncement['title']) ?></h3>
                <a href="?" class="btn btn-secondary" style="margin-left: auto;">
                  <i class="fas fa-times"></i> Cancel Edit
                </a>
              </div>
            </div>
          <?php endif; ?>
          
          <form method="POST" enctype="multipart/form-data" class="announcement-form">
            <input type="hidden" name="<?= $editAnnouncement ? 'edit_announcement' : 'post_announcement' ?>" value="1">
            <?php if ($editAnnouncement): ?>
              <input type="hidden" name="announcement_id" value="<?= $editAnnouncement['announcement_id'] ?>">
            <?php endif; ?>
            
            <div class="form-group">
              <label for="title">Title</label>
              <input type="text" id="title" name="title" 
                     value="<?= $editAnnouncement ? htmlspecialchars($editAnnouncement['title']) : '' ?>" required>
            </div>
            
            <div class="form-group">
              <label for="content">Content</label>
              <textarea id="content" name="content" rows="5" required><?= $editAnnouncement ? htmlspecialchars($editAnnouncement['content']) : '' ?></textarea>
            </div>
            
            <div class="form-group">
              <label for="announcement_image">Image (Optional)</label>
              
              <?php if ($editAnnouncement && $editAnnouncement['image_url']): ?>
                <div class="current-image-section">
                  <p><strong>Current Image:</strong></p>
                  <img src="../<?= htmlspecialchars($editAnnouncement['image_url']) ?>" 
                       alt="Current image" class="current-image">
                  <div>
                    <label>
                      <input type="checkbox" name="remove_current_image" value="1"> Remove current image
                    </label>
                  </div>
                </div>
              <?php endif; ?>
              
              <div class="file-upload-wrapper">
                <input type="file" id="announcement_image" name="announcement_image" accept="image/*">
                <div class="file-upload-info">
                  <i class="fas fa-cloud-upload-alt"></i>
                  <span><?= $editAnnouncement ? 'Replace image or drag and drop' : 'Choose image or drag and drop' ?></span>
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
              <i class="fas fa-<?= $editAnnouncement ? 'save' : 'bullhorn' ?>"></i> 
              <?= $editAnnouncement ? 'Update Announcement' : 'Post Announcement' ?>
            </button>
            
            <?php if ($editAnnouncement): ?>
            <?php endif; ?>
          </form>
        </section>
        <?php endif; ?>

        <!-- Existing Announcements -->
        <section class="existing-announcements card">
          <div class="section-header">
            <h2>
              <i class="fas fa-<?= $view === 'archive' ? 'archive' : 'list' ?>"></i> 
              <?= $view === 'archive' ? 'Archived' : 'Active' ?> Announcements
            </h2>
          </div>
          
          <?php if (empty($announcements)): ?>
            <div class="empty-state">
              <i class="fas fa-<?= $view === 'archive' ? 'archive' : 'bullhorn' ?>"></i>
              <h3>No <?= $view === 'archive' ? 'Archived' : 'Active' ?> Announcements</h3>
              <p>There are no <?= $view === 'archive' ? 'archived' : 'active' ?> announcements for <?= date('F Y', strtotime($filterMonth . '-01')) ?>.</p>
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
                      <?php if ($a['updated_at']): ?>
                        <br><small style="color: #666;">
                          <i class="fas fa-edit"></i> Updated: <?= date('F j, Y \a\t g:i a', strtotime($a['updated_at'])) ?>
                        </small>
                      <?php endif; ?>
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
                    <?php if ($view === 'active'): ?>
                      <!-- Active announcement actions -->
                      <a href="?edit=<?= $a['announcement_id'] ?>&month=<?= htmlspecialchars($filterMonth) ?>" 
                         class="btn btn-sm btn-secondary">
                        <i class="fas fa-edit"></i> Edit
                      </a>
                      
                      <form method="POST" onsubmit="return confirm('Are you sure you want to archive this announcement?')" style="display: inline-block;">
                        <input type="hidden" name="archive_announcement" value="1">
                        <input type="hidden" name="announcement_id" value="<?= $a['announcement_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-archive">
                          <i class="fas fa-archive"></i> Archive
                        </button>
                      </form>
                      
                    <?php else: ?>
                      <!-- Archived announcement actions -->
                      <form method="POST" onsubmit="return confirm('Are you sure you want to restore this announcement?')" style="display: inline-block;">
                        <input type="hidden" name="restore_announcement" value="1">
                        <input type="hidden" name="announcement_id" value="<?= $a['announcement_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-restore">
                          <i class="fas fa-undo"></i> Restore
                        </button>
                      </form>
                    <?php endif; ?>
                    
                    <form method="POST" onsubmit="return confirm('Are you sure you want to permanently delete this announcement? This action cannot be undone.')" style="display: inline-block;">
                      <input type="hidden" name="delete_announcement" value="1">
                      <input type="hidden" name="announcement_id" value="<?= $a['announcement_id'] ?>">
                      <button type="submit" class="btn btn-sm btn-delete">
                        <i class="fas fa-trash-alt"></i> <?= $view === 'archive' ? 'Delete Permanently' : 'Delete' ?>
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
  
  <script src="../admin/js/notification_frontend.js?v=<?php echo time(); ?>"></script>
  <script src="../admin/js/sidebar-notifications.js?v=<?php echo time(); ?>"></script>
  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
  <?php include 'chat_widget.php'; ?>
    <?php include 'floating_notification_widget.php'; ?>
  
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
    
    // Filter by month function
    function filterByMonth() {
      const monthSelect = document.getElementById('month-filter');
      const selectedMonth = monthSelect.value;
      const currentView = '<?= $view ?>';
      
      window.location.href = `?view=${currentView}&month=${selectedMonth}`;
    }
    
    // Auto-hide success/error messages after 5 seconds
    setTimeout(function() {
      const alerts = document.querySelectorAll('.alert');
      alerts.forEach(alert => {
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 300);
      });
    }, 5000);
  </script>
  
</body>
</html>