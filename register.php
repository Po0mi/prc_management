<?php
require_once __DIR__ . '/config.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';

    $role = 'user';

    $secretKey = '6LelZWMrAAAAAAOUFB-ncoxGhkt8OMsUrQgcOgTO'; 
    $response = file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=$secretKey&response=$recaptchaResponse");
    $responseData = json_decode($response);

    if (empty($firstName) || empty($lastName) || empty($gender) || empty($username) || 
        empty($email) || empty($phone) || empty($password) || empty($confirm)) {
        $error = "All fields are required.";
    } elseif (!$responseData->success) {
        $error = "reCAPTCHA verification failed.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } elseif (!preg_match('/^[0-9]{10,11}$/', $phone)) {
        $error = "Invalid phone number. Must be 10 or 11 digits.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters.";
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetchColumn() > 0) {
            $error = "Username or email is already taken.";
        } else {
            // Removed verification token generation
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("INSERT INTO users 
                (first_name, last_name, gender, username, email, phone, password_hash, role) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$firstName, $lastName, $gender, $username, $email, $phone, $hashed, $role]);
            
            // Removed email verification sending
            $success = "Registration successful! You can now log in.";
            echo "<script>window.formSuccess = true;</script>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register | PRC Management System</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="assets/styles.css">
  <link rel="stylesheet" href="assets/register.css?v=<?php echo time(); ?>">
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
</head>
<body>
  <header class="system-header">
    <div class="logo-title">
    <a href="index.php">
        <img src="assets/logo.png" alt="PRC Logo" class="prc-logo">
    </a>
    <div>
        <h1>Philippine Red Cross</h1>
        <p>Management System Portal</p>
    </div>
</div>
    </div>
    <div class="system-info">
      <span class="tag">User Registration</span>
      <span class="tag">Create Your PRC Account</span>
    </div>
  </header>

  <div class="register-container">
    <div class="register-box">
      <h2><i class="fas fa-user-plus"></i> Create Your Account</h2>
      <p>All fields are required to continue.</p>

      <?php if ($error): ?>
        <div class="alert error">
          <i class="fas fa-exclamation-circle"></i>
          <?= htmlspecialchars($error) ?>
        </div>
      <?php elseif ($success): ?>
        <div class="alert success">
          <i class="fas fa-check-circle"></i>
          <?= $success ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="register.php" class="register-form" id="registerForm">
        <div class="form-row">
          <div class="form-group">
            <label>First Name</label>
            <input type="text" name="first_name" required>
          </div>
          <div class="form-group">
            <label>Last Name</label>
            <input type="text" name="last_name" required>
          </div>
        </div>

        <div class="form-group">
          <label>Gender</label>
          <select name="gender" required>
            <option value="">Select Gender</option>
            <option value="male">Male</option>
            <option value="female">Female</option>
            <option value="other">Other</option>
            <option value="prefer_not_to_say">Prefer not to say</option>
          </select>
        </div>

        <div class="form-group">
          <label>Username</label>
          <input type="text" name="username" required minlength="4">
          <p class="input-hint">At least 4 characters</p>
        </div>

        <div class="form-group">
          <label>Email</label>
          <input type="email" name="email" required>
          <p class="input-hint">We'll send a verification link (disabled)</p>
        </div>

        <div class="form-group">
          <label>Phone Number</label>
          <input type="tel" name="phone" pattern="[0-9]{10,11}" required>
          <p class="input-hint">10 or 11 digits only</p>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required minlength="8" id="password">
            <p class="input-hint">At least 8 characters</p>
            <div class="password-strength">
              <div class="strength-meter" id="strengthMeter"></div>
            </div>
          </div>
          <div class="form-group">
            <label>Confirm Password</label>
            <input type="password" name="confirm_password" required minlength="8" id="confirmPassword">
          </div>
        </div>

        <div class="form-group">
          <div class="g-recaptcha" data-sitekey="6LelZWMrAAAAAJdF8yehKwL8dUvL1zAjXFA3Foih"></div>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-user-plus"></i> Register
          </button>
        </div>
      </form>

      <div class="login-link">
        Already have an account? <a href="login.php">Log in here</a>
      </div>
    </div>
  </div>
</body>
</html>