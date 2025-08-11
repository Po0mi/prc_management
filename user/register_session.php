<?php


require_once __DIR__ . '/../config.php';
ensure_logged_in();


$userId = $_SESSION['user_id'] ?? 0;
$userName = $_SESSION['username'] ?? '';
$userEmail = $_SESSION['email'] ?? '';
$error = '';


if (empty($userId)) {
    error_log("Invalid user session - no user ID");
    die("Error: Invalid user session. Please log in again.");
}


$sessionId = (int)($_GET['session_id'] ?? 0);
if ($sessionId <= 0) {
    header("Location: schedule.php?error=invalid");
    exit;
}


$stmt = $pdo->prepare("
    SELECT *, COALESCE(fee, 0) as fee 
    FROM training_sessions 
    WHERE session_id = ?
    AND session_date >= CURDATE()");
$stmt->execute([$sessionId]);
$session = $stmt->fetch();

if (!$session) {
    header("Location: schedule.php?error=invalid");
    exit;
}


$check = $pdo->prepare("
    SELECT 1 
    FROM session_registrations 
    WHERE session_id = ? 
    AND user_id = ? 
    LIMIT 1");
$check->execute([$sessionId, $userId]);
if ($check->fetchColumn()) {
    header("Location: schedule.php?success=1");
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_session'])) {
    $purpose = trim($_POST['purpose'] ?? '');
    $emergencyContact = trim($_POST['emergency_contact'] ?? '');
    $medicalInfo = trim($_POST['medical_info'] ?? '');
    $paymentMethod = $_POST['payment_method'] ?? 'free';
    $majorService = $_POST['major_service'] ?? '';
    
    if (empty($purpose)) {
        $error = "Please specify your purpose for attending";
    } else {
        try {
            
            if ($session['capacity'] > 0) {
                $pdo->beginTransaction();
                
            
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM session_registrations 
                    WHERE session_id = ? 
                    FOR UPDATE");
                $stmt->execute([$sessionId]);
                $registeredCount = $stmt->fetchColumn();
                
                if ($registeredCount >= $session['capacity']) {
                    $pdo->rollBack();
                    header("Location: schedule.php?error=full");
                    exit;
                }
            }
            
           
            $stmt = $pdo->prepare("
                INSERT INTO session_registrations (
                    session_id, 
                    user_id,
                    name,
                    major_service,
                    purpose, 
                    emergency_contact, 
                    medical_info, 
                    payment_method,
                    attendance_status,
                    registration_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'registered', NOW())
            ");
            $stmt->execute([
                $sessionId, 
                $userId,
                $userName,
                $majorService,
                $purpose,
                $emergencyContact,
                $medicalInfo,
                $paymentMethod
            ]);
            
          
            $registrationId = $pdo->lastInsertId();
            
            
            if ($session['fee'] > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO payments (
                        registration_id,
                        user_id,
                        session_id, 
                        amount, 
                        payment_method, 
                        payment_date, 
                        status
                    ) VALUES (?, ?, ?, ?, ?, NOW(), 'pending')
                ");
                $stmt->execute([
                    $registrationId,
                    $userId,
                    $sessionId,
                    $session['fee'],
                    $paymentMethod
                ]);
            }
            
            
            if ($session['capacity'] > 0) {
                $pdo->commit();
            }
            
            header("Location: schedule.php?success=1");
            exit;
            
        } catch (PDOException $e) {
            if ($session['capacity'] > 0) {
                $pdo->rollBack();
            }
            error_log("Registration Error for user $userId: " . $e->getMessage());
            $error = "Error registering for session. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register for Training - PRC Portal</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/sidebar.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/forms.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="form-container">
    <div class="page-header">
      <h1>Register for Training</h1>
      <p>Complete your registration for <?= htmlspecialchars($session['title'] ?? 'Training Session') ?></p>
    </div>

    <?php if ($error): ?>
      <div class="alert error">
        <i class="fas fa-exclamation-circle"></i>
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <div class="form-section">
      <div class="form-card">
        <div class="form-header">
          <i class="fas fa-user-edit"></i>
          <h2>Registration Form</h2>
        </div>
        
        <div class="session-info">
          <h3><?= htmlspecialchars($session['title'] ?? 'Training Session') ?> - <?= htmlspecialchars($session['major_service'] ?? '') ?></h3>
          <p><i class="fas fa-calendar-day"></i> <?= isset($session['session_date']) ? date('F j, Y', strtotime($session['session_date'])) : 'Date not set' ?></p>
          <p><i class="fas fa-clock"></i> 
            <?= isset($session['start_time']) ? date('g:i a', strtotime($session['start_time'])) : 'Time not set' ?> - 
            <?= isset($session['end_time']) ? date('g:i a', strtotime($session['end_time'])) : 'Time not set' ?>
          </p>
          <p><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($session['venue'] ?? 'Venue not specified') ?></p>
          <?php if (isset($session['fee']) && $session['fee'] > 0): ?>
            <p class="fee"><i class="fas fa-money-bill-wave"></i> Training Fee: ₱<?= number_format($session['fee'], 2) ?></p>
          <?php else: ?>
            <p class="fee free"><i class="fas fa-gift"></i> Free Training</p>
          <?php endif; ?>
        </div>
        
        <form method="POST" class="registration-form">
          <input type="hidden" name="register_session" value="1">
          <input type="hidden" name="major_service" value="<?= htmlspecialchars($session['major_service']) ?>">
          
          <div class="form-group">
            <label for="name">Full Name</label>
            <input type="text" id="name" value="<?= htmlspecialchars($userName) ?>" disabled>
          </div>
          
          <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" value="<?= htmlspecialchars($userEmail) ?>" disabled>
          </div>
          
          <div class="form-group">
            <label for="purpose">Purpose for Attending *</label>
            <textarea id="purpose" name="purpose" required placeholder="Why are you attending this training? (e.g., Volunteer training, Certification, etc.)"></textarea>
          </div>
          
          <div class="form-group">
            <label for="emergency_contact">Emergency Contact</label>
            <input type="text" id="emergency_contact" name="emergency_contact" placeholder="Name and phone number">
          </div>
          
          <div class="form-group">
            <label for="medical_info">Medical Information</label>
            <textarea id="medical_info" name="medical_info" placeholder="Any medical conditions we should be aware of?"></textarea>
          </div>
          
          <?php if (isset($session['fee']) && $session['fee'] > 0): ?>
            <div class="payment-section">
              <h3><i class="fas fa-credit-card"></i> Payment Information</h3>
              
              <div class="form-group">
                <label>Payment Method *</label>
                <div class="payment-methods">
                  <label class="payment-option">
                    <input type="radio" name="payment_method" value="gcash" required>
                    <div class="payment-card">
                      <i class="fas fa-mobile-alt"></i>
                      <span>GCash</span>
                    </div>
                  </label>
                  
                  <label class="payment-option">
                    <input type="radio" name="payment_method" value="bank_transfer">
                    <div class="payment-card">
                      <i class="fas fa-university"></i>
                      <span>Bank Transfer</span>
                    </div>
                  </label>
                  
                  <label class="payment-option">
                    <input type="radio" name="payment_method" value="credit_card">
                    <div class="payment-card">
                      <i class="fas fa-credit-card"></i>
                      <span>Credit Card</span>
                    </div>
                  </label>
                  
                  <label class="payment-option">
                    <input type="radio" name="payment_method" value="cash">
                    <div class="payment-card">
                      <i class="fas fa-money-bill-wave"></i>
                      <span>Cash Payment</span>
                    </div>
                  </label>
                </div>
              </div>
              <div class="payment-instructions">
                <p>After submitting this form, you will receive payment instructions via email.</p>
              </div>
            </div>
          <?php else: ?>
            <input type="hidden" name="payment_method" value="free">
          <?php endif; ?>
          
          <div class="form-submit">
            <button type="submit" class="submit-btn">
              <i class="fas fa-check-circle"></i> Complete Registration
            </button>
            <a href="schedule.php" class="cancel-btn">
              <i class="fas fa-times"></i> Cancel
            </a>
          </div>
        </form>
      </div>
    </div>
  </div>
  
</body>
</html>