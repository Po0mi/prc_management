<?php
require_once __DIR__ . '/config.php';

// Get database connection
$pdo = $GLOBALS['pdo'];

// Get upcoming events (limit 6 for display)
$upcoming_events = [];
try {
   // Replace the existing events query with this updated version
$stmt = $pdo->prepare("
    SELECT 
        event_id,
        title,
        description,
        event_date,
        event_end_date,
        start_time,
        end_time,
        location,
        major_service,
        capacity,
        fee,
        duration_days,
        (SELECT COUNT(*) FROM registrations WHERE event_id = e.event_id) as registrations_count
    FROM events e
    WHERE event_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    AND archived = 0
    ORDER BY event_date ASC
    LIMIT 6
");
    $stmt->execute();
    $upcoming_events = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching events: " . $e->getMessage());
}

// Get upcoming training sessions (limit 4 for display)
$upcoming_sessions = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            session_id,
            title,
            description,
            major_service,
            session_date,
            session_end_date,
            start_time,
            end_time,
            venue,
            instructor,
            instructor_bio,
            instructor_credentials,
            capacity,
            fee,
            duration_days,
            status,
            (SELECT COUNT(*) FROM session_registrations WHERE session_id = ts.session_id) as registrations_count
        FROM training_sessions ts
        WHERE status = 'active' 
        AND session_end_date >= CURDATE()
        AND archived = 0
        ORDER BY session_date ASC
        LIMIT 4
    ");
    $stmt->execute();
    $upcoming_sessions = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching training sessions: " . $e->getMessage());
}

// Get featured merchandise (available items)
$featured_merchandise = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            merch_id,
            name,
            description,
            category,
            price,
            stock_quantity,
            image_url,
            is_available
        FROM merchandise
        WHERE is_available = 1 AND stock_quantity > 0
        ORDER BY created_at DESC
        LIMIT 8
    ");
    $stmt->execute();
    $featured_merchandise = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching merchandise: " . $e->getMessage());
}

// Get recent announcements (limit 3)
$recent_announcements = [];
try {
    $stmt = $pdo->prepare("
    SELECT 
        announcement_id,
        title,
        content,
        image_url,
        posted_at
    FROM announcements
    WHERE archived = 0
    ORDER BY posted_at DESC
    LIMIT 3
");
    $stmt->execute();
    $recent_announcements = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching announcements: " . $e->getMessage());
}

// Function to truncate text
function truncateText($text, $length = 100) {
    return strlen($text) > $length ? substr($text, 0, $length) . '...' : $text;
}

// Function to format service name for CSS class
function formatServiceClass($service) {
    return strtolower(str_replace(' ', '-', $service));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Philippine Red Cross - Serving Humanity</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/index.css?v=<?php echo time(); ?>">
</head>
<body>
    <!-- Auto-refresh indicator -->
    <div class="auto-refresh-indicator" id="refreshIndicator">
        <div class="refresh-pulse"></div>
        <span>Content updated</span>
    </div>

    <!-- Navigation Header -->
    <nav class="landing-nav">
        <div class="nav-container">
            <div class="nav-brand">
                <a href="index.php">
                    <img src="./assets/images/logo.png" alt="PRC Logo" class="nav-logo">
                    <div class="brand-text">
                        <h1>Philippine Red Cross</h1>
                        <span>Serving Humanity</span>
                    </div>
                </a>
            </div>
            <div class="nav-actions">
                <a href="login.php" class="nav-btn primary">
                    <i class="fas fa-sign-in-alt"></i>
                    <span>Login</span>
                </a>
                <a href="register.php" class="nav-btn secondary">
                    <i class="fas fa-user-plus"></i>
                    <span>Register</span>
                </a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="landing-hero">
        <div class="hero-background">
            <div class="hero-overlay"></div>
            <div class="hero-particles">
                <div class="particle"></div>
                <div class="particle"></div>
                <div class="particle"></div>
                <div class="particle"></div>
                <div class="particle"></div>
            </div>
        </div>
        <div class="hero-content">
            <div class="hero-badge">
                <i class="fas fa-heart"></i>
                <span>Humanitarian Excellence</span>
            </div>
            <h1 class="hero-title">
                Empowering Communities
                <span class="title-highlight">Through Compassion</span>
            </h1>
            <p class="hero-subtitle">
                Join the Philippine Red Cross in our mission to alleviate human suffering, 
                protect life and health, and uphold human dignity especially during emergencies.
            </p>
            <div class="hero-actions">
                <a href="login.php" class="hero-btn primary">
                    <span>Get Started</span>
                    <i class="fas fa-arrow-right"></i>
                </a>
                <a href="#services" class="hero-btn secondary">
                    <i class="fas fa-play"></i>
                    <span>Learn More</span>
                </a>
            </div>
            <div class="hero-stats">
                <div class="stat-item">
                    <div class="stat-number">1M+</div>
                    <div class="stat-label">Lives Touched</div>
                </div>
                <div class="stat-divider"></div>
                <div class="stat-item">
                    <div class="stat-number">500+</div>
                    <div class="stat-label">Communities Served</div>
                </div>
                <div class="stat-divider"></div>
                <div class="stat-item">
                    <div class="stat-number">24/7</div>
                    <div class="stat-label">Emergency Response</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Featured Photo Carousel -->
    <section class="landing-carousel">
        <div class="carousel-container">
            <div class="section-header">
                <h2>Our Impact in Action</h2>
                <p>Witness the Philippine Red Cross making a difference across the nation</p>
            </div>
            <div class="carousel-wrapper">
                <div class="carousel-track" id="carouselTrack">
                    <div class="carousel-slide active">
                        <img src="./assets/images/blood-drive.jpg" alt="Blood Donation Drive">
                        <div class="slide-content">
                            <h3>Blood Donation Drives</h3>
                            <p>Saving lives through voluntary blood donation campaigns nationwide</p>
                            <a href="#events" class="slide-cta">Join Our Drives</a>
                        </div>
                    </div>
                    <div class="carousel-slide">
                        <img src="./assets/images/blood-drive.jpg" alt="Disaster Response">
                        <div class="slide-content">
                            <h3>Disaster Response</h3>
                            <p>Providing immediate relief and support during emergencies</p>
                            <a href="#services" class="slide-cta">Learn More</a>
                        </div>
                    </div>
                    <div class="carousel-slide">
                        <img src="./assets/images/blood-drive.jpg" alt="Training Programs">
                        <div class="slide-content">
                            <h3>Training & Education</h3>
                            <p>Empowering communities with life-saving skills and knowledge</p>
                            <a href="#training" class="slide-cta">Enroll Now</a>
                        </div>
                    </div>
                    <div class="carousel-slide">
                        <img src="./assets/images/blood-drive.jpg" alt="Volunteer Programs">
                        <div class="slide-content">
                            <h3>Volunteer Programs</h3>
                            <p>Building stronger communities through dedicated volunteer service</p>
                            <a href="register.php" class="slide-cta">Volunteer Today</a>
                        </div>
                    </div>
                </div>
                <div class="carousel-controls">
                    <button class="carousel-btn prev" id="prevBtn">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <button class="carousel-btn next" id="nextBtn">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
                <div class="carousel-indicators">
                    <button class="indicator active" data-slide="0"></button>
                    <button class="indicator" data-slide="1"></button>
                    <button class="indicator" data-slide="2"></button>
                    <button class="indicator" data-slide="3"></button>
                </div>
            </div>
        </div>
    </section>

    <!-- Services Section -->
    <section class="landing-services" id="services">
        <div class="services-container">
            <div class="section-header">
                <div class="header-badge">
                    <i class="fas fa-heart"></i>
                    <span>Our Services</span>
                </div>
                <h2>Comprehensive Humanitarian Aid</h2>
                <p>Discover how we serve humanity through our life-saving programs and community initiatives</p>
            </div>
            
            <div class="services-grid">
                <div class="service-card health">
                    <div class="service-icon">
                        <i class="fas fa-notes-medical"></i>
                    </div>
                    <h3>Health Services</h3>
                    <p>Improving health outcomes for vulnerable communities through medical programs and health education initiatives.</p>
                    <ul class="service-features">
                        <li>Community Health Programs</li>
                        <li>Epidemic Control</li>
                        <li>Maternal & Child Health</li>
                        <li>Water & Sanitation</li>
                    </ul>
                    <a href="#" class="service-link">
                        <span>Learn More</span>
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>

                <div class="service-card blood">
                    <div class="service-icon">
                        <i class="fas fa-tint"></i>
                    </div>
                    <h3>Blood Services</h3>
                    <p>Safe blood collection, testing, and distribution to save lives through our nationwide blood bank network.</p>
                    <ul class="service-features">
                        <li>Voluntary Blood Donation</li>
                        <li>Blood Testing & Screening</li>
                        <li>Emergency Blood Supply</li>
                        <li>Donor Education Programs</li>
                    </ul>
                    <a href="#" class="service-link">
                        <span>Donate Blood</span>
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>

                <div class="service-card training">
                    <div class="service-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <h3>Training & Safety</h3>
                    <p>Educational programs on first aid, CPR, disaster response, and safety courses for all skill levels.</p>
                    <ul class="service-features">
                        <li>First Aid Certification</li>
                        <li>CPR & Life Support</li>
                        <li>Water Safety Training</li>
                        <li>Emergency Response</li>
                    </ul>
                    <a href="#" class="service-link">
                        <span>Join Training</span>
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>

                <div class="service-card disaster">
                    <div class="service-icon">
                        <i class="fas fa-hands-helping"></i>
                    </div>
                    <h3>Disaster Services</h3>
                    <p>Providing relief and disaster preparedness to minimize suffering during natural and man-made disasters.</p>
                    <ul class="service-features">
                        <li>Emergency Relief Operations</li>
                        <li>Disaster Preparedness</li>
                        <li>Community Response Teams</li>
                        <li>Recovery Support</li>
                    </ul>
                    <a href="#" class="service-link">
                        <span>Get Involved</span>
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>

                <div class="service-card welfare">
                    <div class="service-icon">
                        <i class="fas fa-hands"></i>
                    </div>
                    <h3>Welfare Services</h3>
                    <p>Supporting vulnerable individuals and families through comprehensive social welfare programs.</p>
                    <ul class="service-features">
                        <li>Psychosocial Support</li>
                        <li>Family Assistance</li>
                        <li>Elderly Care</li>
                        <li>Child Protection</li>
                    </ul>
                    <a href="#" class="service-link">
                        <span>Find Support</span>
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>

                <div class="service-card emergency">
                    <div class="service-icon">
                        <i class="fas fa-children"></i>
                    </div>
                    <h3>Red Cross Youth</h3>
                    <p>Its mission is to educate and empower children and youth through Red Cross values by providing training, leadership development, and opportunities to channel their energy into meaningful humanitarian activities.</p>
                    <ul class="service-features">
                        <li>Leadership Development Program</li>
                        <li>HIV/AIDS Awareness Prevention education</li>
                        <li>Substance Abuse Prevention Education</li>
                        <li>International Friendship program</li>
                    </ul>
                    <a href="#" class="service-link">
                        <span>Emergency: 143</span>
                        <i class="fas fa-phone"></i>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Announcements Section -->
    <?php if (!empty($recent_announcements)): ?>
    <section class="landing-announcements" id="announcements">
        <div class="announcements-container">
            <div class="section-header">
                <div class="header-badge">
                    <i class="fas fa-bullhorn"></i>
                    <span>Latest News</span>
                </div>
                <h2>Stay Updated</h2>
                <p>Get the latest announcements and important updates from Philippine Red Cross</p>
            </div>
            <div class="announcements-grid">
                <?php foreach ($recent_announcements as $announcement): ?>
                <article class="announcement-card">
                    <?php if ($announcement['image_url']): ?>
                    <div class="announcement-image">
                        <img src="<?= htmlspecialchars($announcement['image_url']) ?>" alt="<?= htmlspecialchars($announcement['title']) ?>">
                        <div class="image-overlay"></div>
                    </div>
                    <?php endif; ?>
                    <div class="announcement-content">
                        <div class="announcement-meta">
                            <span class="date">
                                <i class="fas fa-calendar-alt"></i>
                                <?= date('M j, Y', strtotime($announcement['posted_at'])) ?>
                            </span>
                        </div>
                        <h3><?= htmlspecialchars($announcement['title']) ?></h3>
                        <p><?= htmlspecialchars(truncateText($announcement['content'], 150)) ?></p>
                        <a href="#" class="read-more">
                            <span>Read More</span>
                            <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Events Section -->
    <?php if (!empty($upcoming_events)): ?>
    <section class="landing-events" id="events">
        <div class="events-container">
            <div class="section-header">
                <div class="header-badge">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Upcoming Events</span>
                </div>
                <h2>Join Our Activities</h2>
                <p>Participate in our humanitarian activities and make a meaningful difference in your community</p>
            </div>
            <div class="events-grid">
                <?php foreach ($upcoming_events as $event): ?>
                <div class="event-card">
                    <div class="event-header">
                        <div class="event-date">
                            <span class="day"><?= date('d', strtotime($event['event_date'])) ?></span>
                            <span class="month"><?= date('M', strtotime($event['event_date'])) ?></span>
                        </div>
                        <div class="event-category">
                            <span class="category-tag"><?= htmlspecialchars($event['major_service']) ?></span>
                        </div>
                    </div>
                    <div class="event-content">
                        <h3><?= htmlspecialchars($event['title']) ?></h3>
                        <?php if ($event['description']): ?>
                        <p class="event-description"><?= htmlspecialchars(truncateText($event['description'], 120)) ?></p>
                        <?php endif; ?>
                        <div class="event-details">
                            <div class="detail-item">
                                <i class="fas fa-clock"></i>
                                <span><?= date('g:i A', strtotime($event['start_time'])) ?></span>
                            </div>
                            <div class="detail-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?= htmlspecialchars(truncateText($event['location'], 30)) ?></span>
                            </div>
                            <?php if ($event['fee'] > 0): ?>
                            <div class="detail-item">
                                <i class="fas fa-peso-sign"></i>
                                <span>₱<?= number_format($event['fee'], 2) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="event-footer">
                            <div class="event-capacity">
                                <i class="fas fa-users"></i>
                                <span><?= $event['registrations_count'] ?>/<?= $event['capacity'] ?: '∞' ?></span>
                            </div>
                            <a href="login.php?event_id=<?= $event['event_id'] ?>" class="event-btn">
                                <span>Register</span>
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Training Sessions Section -->
    <?php if (!empty($upcoming_sessions)): ?>
    <section class="landing-training" id="training">
        <div class="training-container">
            <div class="section-header">
                <div class="header-badge">
                    <i class="fas fa-graduation-cap"></i>
                    <span>Training Programs</span>
                </div>
                <h2>Build Life-Saving Skills</h2>
                <p>Enhance your capabilities through our professional training programs designed for all skill levels</p>
            </div>
            <div class="training-grid">
                <?php foreach ($upcoming_sessions as $session): ?>
                <div class="training-card">
                    <div class="training-header">
                        <div class="training-category">
                            <span class="category-tag"><?= htmlspecialchars($session['major_service']) ?></span>
                        </div>
                        <div class="training-price">
                            <?php if ($session['fee'] > 0): ?>
                                <span class="price">₱<?= number_format($session['fee'], 2) ?></span>
                            <?php else: ?>
                                <span class="free">FREE</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="training-content">
                        <h3><?= htmlspecialchars($session['title']) ?></h3>
                        <div class="training-details">
                            <div class="detail-item">
                                <i class="fas fa-calendar"></i>
                                <span><?= date('M j, Y', strtotime($session['session_date'])) ?></span>
                            </div>
                            <div class="detail-item">
                                <i class="fas fa-clock"></i>
                                <span><?= date('g:i A', strtotime($session['start_time'])) ?> - <?= date('g:i A', strtotime($session['end_time'])) ?></span>
                            </div>
                            <div class="detail-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?= htmlspecialchars(truncateText($session['venue'], 40)) ?></span>
                            </div>
                            <?php if ($session['instructor']): ?>
                            <div class="detail-item">
                                <i class="fas fa-user-tie"></i>
                                <span><?= htmlspecialchars($session['instructor']) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="training-footer">
                            <div class="training-capacity">
                                <i class="fas fa-users"></i>
                                <span><?= $session['registrations_count'] ?>/<?= $session['capacity'] ?: '∞' ?> enrolled</span>
                            </div>
                            <a href="login.php?session_id=<?= $session['session_id'] ?>" class="training-btn">
                                <span>Enroll Now</span>
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- CTA Section -->
    <section class="landing-cta">
        <div class="cta-container">
            <div class="cta-content">
                <h2>Ready to Make a Difference?</h2>
                <p>Join thousands of volunteers and humanitarian workers in serving communities across the Philippines. Your compassion can save lives.</p>
                <div class="cta-actions">
                    <a href="register.php" class="cta-btn primary">
                        <span>Become a Volunteer</span>
                        <i class="fas fa-heart"></i>
                    </a>
                    <a href="login.php" class="cta-btn secondary">
                        <span>Member Login</span>
                        <i class="fas fa-sign-in-alt"></i>
                    </a>
                </div>
            </div>
            <div class="cta-visual">
                <div class="cta-card">
                    <i class="fas fa-hands-helping"></i>
                    <h4>24/7 Emergency Response</h4>
                    <p>Always ready to help when disaster strikes</p>
                </div>
                <div class="cta-card">
                    <i class="fas fa-graduation-cap"></i>
                    <h4>Professional Training</h4>
                    <p>World-class certification programs</p>
                </div>
                <div class="cta-card">
                    <i class="fas fa-users"></i>
                    <h4>Community Impact</h4>
                    <p>Building resilient communities together</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="landing-footer">
        <div class="footer-container">
            <div class="footer-content">
                <div class="footer-section brand">
                    <div class="footer-logo">
                        <img src="./assets/images/logo.png" alt="PRC Logo">
                        <h3>Philippine Red Cross</h3>
                    </div>
                    <p>The Philippine Red Cross is committed to providing humanitarian services that help vulnerable communities become self-reliant.</p>
                    <div class="social-links">
                        <a href="#" aria-label="Facebook"><i class="fab fa-facebook"></i></a>
                        <a href="#" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                        <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                        <a href="#" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
                <div class="footer-section">
                    <h4>Quick Access</h4>
                    <ul>
                        <li><a href="login.php">Member Portal</a></li>
                        <li><a href="register.php">Join Us</a></li>
                        <li><a href="#services">Our Services</a></li>
                        <li><a href="#events">Events</a></li>
                        <li><a href="#training">Training</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>Services</h4>
                    <ul>
                        <li><a href="#">Blood Donation</a></li>
                        <li><a href="#">Emergency Response</a></li>
                        <li><a href="#">Health Programs</a></li>
                        <li><a href="#">Disaster Relief</a></li>
                        <li><a href="#">Training Programs</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>Contact</h4>
                    <div class="contact-info">
                        <div class="contact-item">
                            <i class="fas fa-phone"></i>
                            <span>143 (Emergency Hotline)</span>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-envelope"></i>
                            <span>info@redcross.org.ph</span>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <span>PRC National Headquarters, Manila</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2025 Philippine Red Cross. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Scroll to top button -->
    <button class="scroll-top" id="scrollTop" aria-label="Scroll to top">
        <i class="fas fa-chevron-up"></i>
    </button>

    <!-- Enhanced JavaScript -->
    <script>
// Modern PRC Landing Page JavaScript

class LandingPageManager {
    constructor() {
        this.refreshInterval = 60000; // 1 minute
        this.isRefreshing = false;
        this.retryCount = 0;
        this.maxRetries = 3;
        this.lastUpdate = new Date();
        this.currentSlide = 0;
        this.totalSlides = 4;
        this.autoSlideInterval = null;
        
        this.init();
    }
    
    init() {
        this.initCarousel();
        this.initScrollEffects();
        this.initNavigation();
        this.initAnimations();
        this.startAutoRefresh();
        console.log('Landing Page Manager initialized');
    }

    // Carousel Management
    initCarousel() {
        const track = document.getElementById('carouselTrack');
        const nextBtn = document.getElementById('nextBtn');
        const prevBtn = document.getElementById('prevBtn');
        const indicators = document.querySelectorAll('.indicator');
        
        if (!track) return;

        // Auto-slide functionality
        this.startAutoSlide();

        // Next button
        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                this.nextSlide();
                this.resetAutoSlide();
            });
        }

        // Previous button
        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                this.prevSlide();
                this.resetAutoSlide();
            });
        }

        // Indicator navigation
        indicators.forEach((indicator, index) => {
            indicator.addEventListener('click', () => {
                this.goToSlide(index);
                this.resetAutoSlide();
            });
        });

        // Pause on hover
        const carouselWrapper = document.querySelector('.carousel-wrapper');
        if (carouselWrapper) {
            carouselWrapper.addEventListener('mouseenter', () => {
                this.pauseAutoSlide();
            });

            carouselWrapper.addEventListener('mouseleave', () => {
                this.resumeAutoSlide();
            });
        }

        // Touch/swipe support
        this.initTouchSupport();

        console.log('Carousel initialized');
    }

    startAutoSlide() {
        this.autoSlideInterval = setInterval(() => {
            this.nextSlide();
        }, 5000);
    }

    pauseAutoSlide() {
        if (this.autoSlideInterval) {
            clearInterval(this.autoSlideInterval);
        }
    }

    resumeAutoSlide() {
        this.startAutoSlide();
    }

    resetAutoSlide() {
        this.pauseAutoSlide();
        this.resumeAutoSlide();
    }

    nextSlide() {
        this.currentSlide = (this.currentSlide + 1) % this.totalSlides;
        this.updateCarousel();
    }

    prevSlide() {
        this.currentSlide = (this.currentSlide - 1 + this.totalSlides) % this.totalSlides;
        this.updateCarousel();
    }

    goToSlide(index) {
        this.currentSlide = index;
        this.updateCarousel();
    }

    updateCarousel() {
        const track = document.getElementById('carouselTrack');
        const indicators = document.querySelectorAll('.indicator');
        const slides = document.querySelectorAll('.carousel-slide');
        
        if (!track) return;

        // Update track position
        const translateX = -this.currentSlide * 100;
        track.style.transform = `translateX(${translateX}%)`;

        // Update indicators
        indicators.forEach((indicator, index) => {
            indicator.classList.toggle('active', index === this.currentSlide);
        });

        // Update slide states
        slides.forEach((slide, index) => {
            slide.classList.toggle('active', index === this.currentSlide);
        });
    }

    initTouchSupport() {
        const track = document.getElementById('carouselTrack');
        if (!track) return;

        let startX = 0;
        let currentX = 0;
        let isDragging = false;

        track.addEventListener('touchstart', (e) => {
            startX = e.touches[0].clientX;
            isDragging = true;
            this.pauseAutoSlide();
        });

        track.addEventListener('touchmove', (e) => {
            if (!isDragging) return;
            currentX = e.touches[0].clientX;
            e.preventDefault();
        });

        track.addEventListener('touchend', () => {
            if (!isDragging) return;
            isDragging = false;
            
            const diffX = startX - currentX;
            if (Math.abs(diffX) > 50) {
                if (diffX > 0) {
                    this.nextSlide();
                } else {
                    this.prevSlide();
                }
            }
            
            this.resumeAutoSlide();
        });
    }

    // Navigation Effects
    initNavigation() {
        const nav = document.querySelector('.landing-nav');
        if (!nav) return;

        let lastScrollY = window.scrollY;

        window.addEventListener('scroll', () => {
            const currentScrollY = window.scrollY;
            
            // Add/remove scrolled class for styling
            if (currentScrollY > 50) {
                nav.classList.add('scrolled');
            } else {
                nav.classList.remove('scrolled');
            }

            // Hide/show nav on scroll direction
            if (currentScrollY > lastScrollY && currentScrollY > 100) {
                nav.style.transform = 'translateY(-100%)';
            } else {
                nav.style.transform = 'translateY(0)';
            }

            lastScrollY = currentScrollY;
        });

        console.log('Navigation effects initialized');
    }

    // Scroll Effects and Animations
    initScrollEffects() {
        // Scroll to top button
        const scrollTopBtn = document.getElementById('scrollTop');
        
        if (scrollTopBtn) {
            window.addEventListener('scroll', () => {
                if (window.pageYOffset > 300) {
                    scrollTopBtn.classList.add('show');
                } else {
                    scrollTopBtn.classList.remove('show');
                }
            });

            scrollTopBtn.addEventListener('click', () => {
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });
        }

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        console.log('Scroll effects initialized');
    }

    // Animation Observer
    initAnimations() {
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate-in');
                    
                    // Add staggered animation for cards
                    if (entry.target.classList.contains('service-card') ||
                        entry.target.classList.contains('announcement-card') ||
                        entry.target.classList.contains('event-card') ||
                        entry.target.classList.contains('training-card')) {
                        
                        const cards = entry.target.parentElement.children;
                        const index = Array.from(cards).indexOf(entry.target);
                        entry.target.style.animationDelay = `${index * 0.1}s`;
                    }
                }
            });
        }, observerOptions);

        // Observe elements for scroll animations
        const animatedElements = document.querySelectorAll(`
            .service-card,
            .announcement-card,
            .event-card,
            .training-card,
            .cta-card,
            .section-header
        `);

        animatedElements.forEach(el => {
            observer.observe(el);
        });

        console.log('Animation observer initialized');
    }

    // Auto-refresh functionality
    async startAutoRefresh() {
        setInterval(() => {
            if (!document.hidden && navigator.onLine) {
                this.refreshContent();
            }
        }, this.refreshInterval);
    }

    async refreshContent() {
        if (this.isRefreshing) return;

        this.isRefreshing = true;
        
        try {
            console.log('Refreshing dynamic content...');
            
            const response = await fetch('get_dynamic_content.php', {
                method: 'GET',
                headers: {
                    'Cache-Control': 'no-cache',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();
            
            if (data.success) {
                await this.updateContent(data);
                this.showRefreshIndicator();
                this.retryCount = 0;
                console.log('Content updated successfully');
            } else {
                throw new Error(data.message || 'Failed to fetch content');
            }

        } catch (error) {
            console.error('Error refreshing content:', error);
            this.handleRefreshError();
        } finally {
            this.isRefreshing = false;
            this.lastUpdate = new Date();
        }
    }

    async updateContent(data) {
        await Promise.all([
            this.updateAnnouncements(data.announcements),
            this.updateEvents(data.events),
            this.updateSessions(data.sessions)
        ]);
    }

    async updateAnnouncements(announcements) {
        const container = document.querySelector('.announcements-grid');
        const section = document.querySelector('.landing-announcements');
        
        if (!announcements || announcements.length === 0) {
            if (section) section.style.display = 'none';
            return;
        }

        if (section) section.style.display = 'block';
        if (!container) return;

        const htmlContent = announcements.map(announcement => `
            <article class="announcement-card">
                ${announcement.image_url ? `
                <div class="announcement-image">
                    <img src="${this.escapeHtml(announcement.image_url)}" alt="${this.escapeHtml(announcement.title)}">
                    <div class="image-overlay"></div>
                </div>
                ` : ''}
                <div class="announcement-content">
                    <div class="announcement-meta">
                        <span class="date">
                            <i class="fas fa-calendar-alt"></i>
                            ${this.formatDate(announcement.posted_at)}
                        </span>
                    </div>
                    <h3>${this.escapeHtml(announcement.title)}</h3>
                    <p>${this.escapeHtml(this.truncateText(announcement.content, 150))}</p>
                    <a href="#" class="read-more">
                        <span>Read More</span>
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </article>
        `).join('');

        this.fadeUpdate(container, htmlContent);
    }

    async updateEvents(events) {
        const container = document.querySelector('.events-grid');
        const section = document.querySelector('.landing-events');
        
        if (!events || events.length === 0) {
            if (section) section.style.display = 'none';
            return;
        }

        if (section) section.style.display = 'block';
        if (!container) return;

        const htmlContent = events.map(event => `
            <div class="event-card">
                <div class="event-header">
                    <div class="event-date">
                        <span class="day">${this.formatDay(event.event_date)}</span>
                        <span class="month">${this.formatMonth(event.event_date)}</span>
                    </div>
                    <div class="event-category">
                        <span class="category-tag">${this.escapeHtml(event.major_service)}</span>
                    </div>
                </div>
                <div class="event-content">
                    <h3>${this.escapeHtml(event.title)}</h3>
                    ${event.description ? `
                    <p class="event-description">${this.escapeHtml(this.truncateText(event.description, 120))}</p>
                    ` : ''}
                    <div class="event-details">
                        <div class="detail-item">
                            <i class="fas fa-clock"></i>
                            <span>${this.formatTime(event.start_time)}</span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <span>${this.escapeHtml(this.truncateText(event.location, 30))}</span>
                        </div>
                        ${event.fee > 0 ? `
                        <div class="detail-item">
                            <i class="fas fa-peso-sign"></i>
                            <span>₱${this.formatCurrency(event.fee)}</span>
                        </div>
                        ` : ''}
                    </div>
                    <div class="event-footer">
                        <div class="event-capacity">
                            <i class="fas fa-users"></i>
                            <span>${event.registrations_count}/${event.capacity || '∞'}</span>
                        </div>
                        <a href="login.php?event_id=${event.event_id}" class="event-btn">
                            <span>Register</span>
                            <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        `).join('');

        this.fadeUpdate(container, htmlContent);
    }

    async updateSessions(sessions) {
        const container = document.querySelector('.training-grid');
        const section = document.querySelector('.landing-training');
        
        if (!sessions || sessions.length === 0) {
            if (section) section.style.display = 'none';
            return;
        }

        if (section) section.style.display = 'block';
        if (!container) return;

        const htmlContent = sessions.map(session => `
            <div class="training-card">
                <div class="training-header">
                    <div class="training-category">
                        <span class="category-tag">${this.escapeHtml(session.major_service)}</span>
                    </div>
                    <div class="training-price">
                        ${session.fee > 0 ? `
                            <span class="price">₱${this.formatCurrency(session.fee)}</span>
                        ` : `
                            <span class="free">FREE</span>
                        `}
                    </div>
                </div>
                <div class="training-content">
                    <h3>${this.escapeHtml(session.title)}</h3>
                    <div class="training-details">
                        <div class="detail-item">
                            <i class="fas fa-calendar"></i>
                            <span>${this.formatEventDate(session.session_date)}</span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-clock"></i>
                            <span>${this.formatTime(session.start_time)} - ${this.formatTime(session.end_time)}</span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <span>${this.escapeHtml(this.truncateText(session.venue, 40))}</span>
                        </div>
                        ${session.instructor ? `
                        <div class="detail-item">
                            <i class="fas fa-user-tie"></i>
                            <span>${this.escapeHtml(session.instructor)}</span>
                        </div>
                        ` : ''}
                    </div>
                    <div class="training-footer">
                        <div class="training-capacity">
                            <i class="fas fa-users"></i>
                            <span>${session.registrations_count}/${session.capacity || '∞'} enrolled</span>
                        </div>
                        <a href="login.php?session_id=${session.session_id}" class="training-btn">
                            <span>Enroll Now</span>
                            <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        `).join('');

        this.fadeUpdate(container, htmlContent);
    }

    fadeUpdate(container, htmlContent) {
        container.style.opacity = '0.5';
        container.style.transition = 'opacity 0.3s ease';
        
        setTimeout(() => {
            container.innerHTML = htmlContent;
            container.style.opacity = '1';
            
            // Re-initialize animations for new content
            const newElements = container.querySelectorAll('.announcement-card, .event-card, .training-card');
            newElements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(30px)';
                el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                
                setTimeout(() => {
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, index * 100);
            });
        }, 300);
    }

    handleRefreshError() {
        this.retryCount++;
        
        if (this.retryCount <= this.maxRetries) {
            console.log(`Retrying... (${this.retryCount}/${this.maxRetries})`);
            setTimeout(() => {
                this.refreshContent();
            }, 5000 * this.retryCount);
        } else {
            console.log('Max retries reached, stopping auto-refresh');
            this.showErrorIndicator();
        }
    }

    showRefreshIndicator() {
        const indicator = document.getElementById('refreshIndicator');
        if (indicator) {
            indicator.classList.add('active');
            setTimeout(() => {
                indicator.classList.remove('active');
            }, 2000);
        }
    }

    showErrorIndicator() {
        const indicator = document.getElementById('refreshIndicator');
        if (indicator) {
            indicator.innerHTML = '<i class="fas fa-exclamation-triangle"></i><span>Connection error</span>';
            indicator.style.background = 'rgba(220, 53, 69, 0.9)';
            indicator.classList.add('active');
            
            setTimeout(() => {
                indicator.classList.remove('active');
                indicator.innerHTML = '<div class="refresh-pulse"></div><span>Content updated</span>';
                indicator.style.background = 'var(--prc-red)';
            }, 5000);
        }
    }

    // Utility functions
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    truncateText(text, length) {
        return text && text.length > length ? text.substring(0, length) + '...' : text;
    }

    formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric' 
        });
    }

    formatEventDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { 
            month: 'short', 
            day: 'numeric', 
            year: 'numeric' 
        });
    }

    formatDay(dateString) {
        const date = new Date(dateString);
        return date.getDate().toString().padStart(2, '0');
    }

    formatMonth(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { month: 'short' }).toUpperCase();
    }

    formatTime(timeString) {
        const time = new Date(`1970-01-01T${timeString}`);
        return time.toLocaleTimeString('en-US', { 
            hour: 'numeric', 
            minute: '2-digit',
            hour12: true 
        });
    }

    formatCurrency(amount) {
        return new Intl.NumberFormat('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(amount);
    }
}

// Hero Stats Counter Animation
class StatsCounter {
    constructor() {
        this.initCounters();
    }

    initCounters() {
        const counters = document.querySelectorAll('.stat-number');
        const observerOptions = {
            threshold: 0.5,
            rootMargin: '0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    this.animateCounter(entry.target);
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);

        counters.forEach(counter => {
            observer.observe(counter);
        });
    }

    animateCounter(element) {
        const target = element.textContent;
        const isNumber = /^\d+[\+]?$/.test(target);
        
        if (!isNumber) return;

        const finalNumber = parseInt(target.replace(/[^\d]/g, ''));
        const suffix = target.includes('+') ? '+' : '';
        const duration = 2000;
        const steps = 60;
        const increment = finalNumber / steps;
        
        let current = 0;
        let step = 0;

        const timer = setInterval(() => {
            current += increment;
            step++;
            
            if (step >= steps) {
                current = finalNumber;
                clearInterval(timer);
            }
            
            element.textContent = Math.floor(current).toLocaleString() + suffix;
        }, duration / steps);
    }
}

// Parallax Effects
class ParallaxManager {
    constructor() {
        this.initParallax();
    }

    initParallax() {
        window.addEventListener('scroll', this.throttle(() => {
            this.updateParallax();
        }, 16));
    }

    updateParallax() {
        const scrollY = window.pageYOffset;
        const heroParticles = document.querySelectorAll('.particle');
        
        heroParticles.forEach((particle, index) => {
            const speed = 0.5 + (index * 0.1);
            const translateY = scrollY * speed;
            particle.style.transform = `translateY(${translateY}px)`;
        });
    }

    throttle(func, delay) {
        let timeoutId;
        let lastExecTime = 0;
        return function (...args) {
            const currentTime = Date.now();
            
            if (currentTime - lastExecTime > delay) {
                func.apply(this, args);
                lastExecTime = currentTime;
            } else {
                clearTimeout(timeoutId);
                timeoutId = setTimeout(() => {
                    func.apply(this, args);
                    lastExecTime = Date.now();
                }, delay - (currentTime - lastExecTime));
            }
        };
    }
}

// Initialize everything when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Initialize the main landing page manager
    window.landingManager = new LandingPageManager();
    
    // Initialize stats counter
    window.statsCounter = new StatsCounter();
    
    // Initialize parallax effects
    window.parallaxManager = new ParallaxManager();
    
    // Add CSS animation classes
    const style = document.createElement('style');
    style.textContent = `
        .animate-in {
            animation: fadeInUp 0.8s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .landing-nav {
            transition: transform 0.3s ease, background-color 0.3s ease;
        }
        
        .landing-nav.scrolled {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
    `;
    document.head.appendChild(style);
    
    console.log('All landing page components initialized');
});

// Handle window resize
window.addEventListener('resize', () => {
    if (window.landingManager) {
        // Reset carousel if needed
        window.landingManager.updateCarousel();
    }
});

// Handle page visibility changes
document.addEventListener('visibilitychange', () => {
    if (window.landingManager) {
        if (document.hidden) {
            window.landingManager.pauseAutoSlide();
        } else {
            window.landingManager.resumeAutoSlide();
        }
    }
});

// Handle page unload cleanup
window.addEventListener('beforeunload', () => {
    if (window.landingManager && window.landingManager.autoSlideInterval) {
        clearInterval(window.landingManager.autoSlideInterval);
    }
});

// Export for potential external use
window.LandingPageManager = LandingPageManager;
window.StatsCounter = StatsCounter;
window.ParallaxManager = ParallaxManager;
    </script>
</body>
</html>