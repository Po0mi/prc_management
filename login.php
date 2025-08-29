<?php
require_once __DIR__ . '/config.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = "Please enter both username and password.";
    } else {
        $stmt = $pdo->prepare("SELECT user_id, username, password_hash, role FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id']  = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];

            if ($user['role'] === 'admin') {
                header("Location: admin/dashboard.php");
            } else {
                header("Location: user/dashboard.php");
            }
            exit;
        } else {
            $error = "Invalid username or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | PRC Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/styles.css">
    <link rel="stylesheet" href="assets/login.css?v=<?php echo time(); ?>">
</head>
<body>

    <header class="system-header">
         <div class="logo-title">
    <a href="index.php">
        <img src="./assets/images/logo.png" alt="PRC Logo" class="prc-logo">
    </a>
    <div>
        <h1>Philippine Red Cross</h1>
        <p>Management System Portal</p>
    </div>
</div>
        </div>
        <div class="system-info">
            <span class="tag">Unified Access Portal</span>
            <span class="tag">Secure Login for Admin & Users</span>
        </div>
    </header>


    <div class="login-container">
        <div class="login-box">
            <h2><i class="fas fa-sign-in-alt"></i> PRC Login</h2>
            <p>Please enter your credentials to continue.</p>

            <?php if ($error): ?>
                <div class="error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" class="login-form">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required>
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required>
                </div>
                
                <button type="submit" class="btn btn-login">
                    <i class="fas fa-lock"></i> Log In
                </button>
            </form>
            
            <div class="register-link">
                Don't have an account? <a href="register.php">Register here</a>
            </div>
        </div>
    </div>

    <script src="js/login.js"></script>
</body>
</html>