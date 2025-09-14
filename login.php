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
    <title>Login | Philippine Red Cross</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/login.css?v=<?php echo time(); ?>">
</head>
<body>
    <!-- Navigation Header -->
    <nav class="login-nav">
        <div class="nav-container">
            <div class="nav-brand">
                <a href="index.php">
                    <img src="./assets/images/logo.png" alt="PRC Logo" class="nav-logo">
                    <div class="brand-text">
                        <h1>Philippine Red Cross</h1>
                        <span>Management System Portal</span>
                    </div>
                </a>
            </div>
            <div class="nav-actions">
                <div class="nav-tags">
                    <span class="nav-tag">Unified Access Portal</span>
                    <span class="nav-tag">Secure Login for Admin & Users</span>
                </div>
                <a href="index.php" class="nav-btn back-btn">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Home</span>
                </a>
            </div>
        </div>
    </nav>

    <!-- Login Section -->
    <section class="login-section">
        <div class="login-background">
            <div class="login-overlay"></div>
            <div class="background-particles">
                <div class="particle"></div>
                <div class="particle"></div>
                <div class="particle"></div>
                <div class="particle"></div>
            </div>
        </div>
        
        <div class="login-container">
            <div class="login-content">
                <!-- Left Side - Welcome Message -->
                <div class="login-welcome">
                    <div class="welcome-content">
                        <h2>Welcome Back</h2>
                        <p>Access your Philippine Red Cross management portal to continue serving humanity through our comprehensive programs and services.</p>
                        <div class="welcome-features">
                            <div class="feature-item">
                                <i class="fas fa-shield-alt"></i>
                                <span>Secure Access</span>
                            </div>
                            <div class="feature-item">
                                <i class="fas fa-users"></i>
                                <span>Member Portal</span>
                            </div>
                            <div class="feature-item">
                                <i class="fas fa-heart"></i>
                                <span>Humanitarian Service</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Side - Login Form -->
                <div class="login-form-section">
                    <div class="login-box">
                        <div class="login-header">
                            <h3>Sign In</h3>
                            <p>Enter your credentials to access your account</p>
                        </div>

                        <?php if ($error): ?>
                            <div class="error-alert">
                                <i class="fas fa-exclamation-triangle"></i>
                                <span><?= htmlspecialchars($error) ?></span>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="login.php" class="login-form">
                            <div class="form-group">
                                <label for="username">
                                    <i class="fas fa-user"></i>
                                    Username
                                </label>
                                <input 
                                    type="text" 
                                    id="username"
                                    name="username" 
                                    placeholder="Enter your username"
                                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                                    required
                                    autocomplete="username"
                                >
                            </div>

                            <div class="form-group">
                                <label for="password">
                                    <i class="fas fa-lock"></i>
                                    Password
                                </label>
                                <div class="password-input">
                                    <input 
                                        type="password" 
                                        id="password"
                                        name="password" 
                                        placeholder="Enter your password"
                                        required
                                        autocomplete="current-password"
                                    >
                                    <button type="button" class="password-toggle" onclick="togglePassword()">
                                        <i class="fas fa-eye" id="password-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="submit" class="login-btn">
                                <span>Sign In</span>
                                <i class="fas fa-arrow-right"></i>
                            </button>
                        </form>

                        <div class="login-footer">
                            <div class="divider">
                                <span>or</span>
                            </div>
                            <div class="register-link">
                                <span>Don't have an account?</span>
                                <a href="register.php">Create Account</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script>
        // Password toggle functionality
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const passwordEye = document.getElementById('password-eye');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                passwordEye.classList.remove('fa-eye');
                passwordEye.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                passwordEye.classList.remove('fa-eye-slash');
                passwordEye.classList.add('fa-eye');
            }
        }

        // Form animations
        document.addEventListener('DOMContentLoaded', function() {
            const formGroups = document.querySelectorAll('.form-group');
            
            formGroups.forEach((group, index) => {
                group.style.animationDelay = `${index * 0.1}s`;
                group.classList.add('animate-in');
            });

            // Input focus effects
            const inputs = document.querySelectorAll('.login-form input');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.classList.add('focused');
                });
                
                input.addEventListener('blur', function() {
                    if (!this.value) {
                        this.parentElement.classList.remove('focused');
                    }
                });
                
                // Check if input has value on page load
                if (input.value) {
                    input.parentElement.classList.add('focused');
                }
            });
        });
    </script>
</body>
</html>