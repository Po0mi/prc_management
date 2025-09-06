<?php
require_once __DIR__ . '/config.php';

// Get database connection
$pdo = $GLOBALS['pdo'];

// Get upcoming events (limit 6 for display)
$upcoming_events = [];
try {
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
    <title>Philippine Red Cross - Management System</title>
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

    <!-- Header -->
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
    </header>

    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-content">
            <h2>Welcome to the PRC Management System</h2>
            <p>This centralized portal allows access for both staff and volunteers to manage blood services, trainings, donations, and more.</p>
            <div class="buttons">
                <a href="login.php" class="btn btn-login"><i class="fas fa-sign-in-alt"></i> Login</a>
                <a href="register.php" class="btn btn-register"><i class="fas fa-user-plus"></i> Register</a>
            </div>
        </div>
    </section>

    <!-- Photo Slider Section -->
    <section class="photo-slider-section">
        <div class="content-section">
            <div class="section-title">
                <h2><i class="fas fa-images"></i> Our Impact in Action</h2>
                <p>Witness the Philippine Red Cross making a difference in communities across the nation</p>
            </div>
            <div class="photo-slider-container">
                <div class="slider-wrapper">
                    <div class="slider" id="photoSlider">
                        <div class="list">
                            <div class="item">
                                <img src="./assets/images/blood-drive.jpg" alt="Blood Donation Drive">
                                <div class="photo-overlay">
                                    <h3>Blood Donation Drives</h3>
                                    <p>Saving lives through voluntary blood donation campaigns nationwide</p>
                                </div>
                            </div>
                            <div class="item">
                                 <img src="./assets/images/blood-drive.jpg" alt="Blood Donation Drive">
                                <div class="photo-overlay">
                                    <h3>Disaster Response</h3>
                                    <p>Providing immediate relief and support during emergencies</p>
                                </div>
                            </div>
                            <div class="item">
                                 <img src="./assets/images/blood-drive.jpg" alt="Blood Donation Drive">
                                <div class="photo-overlay">
                                    <h3>Training & Education</h3>
                                    <p>Empowering communities with life-saving skills and knowledge</p>
                                </div>
                            </div>
                            <div class="item">
                                 <img src="./assets/images/blood-drive.jpg" alt="Blood Donation Drive">
                                <div class="photo-overlay">
                                    <h3>Volunteer Programs</h3>
                                    <p>Building stronger communities through dedicated volunteer service</p>
                                </div>
                            </div>
                            <div class="item">
                                 <img src="./assets/images/blood-drive.jpg" alt="Blood Donation Drive">
                                <div class="photo-overlay">
                                    <h3>Health Services</h3>
                                    <p>Providing accessible healthcare and wellness programs</p>
                                </div>
                            </div>
                        </div>
                        <div class="buttons">
                            <button id="prev">
                                <svg viewBox="0 0 24 24">
                                    <path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/>
                                </svg>
                            </button>
                            <button id="next">
                                <svg viewBox="0 0 24 24">
                                    <path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/>
                                </svg>
                            </button>
                        </div>
                        <ul class="dots">
                            <li class="active"></li>
                            <li></li>
                            <li></li>
                            <li></li>
                            <li></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Dynamic Announcements Section -->
    <?php if (!empty($recent_announcements)): ?>
    <section class="dynamic-section" id="announcements-section">
        <div class="content-section">
            <div class="section-title">
                <h2><i class="fas fa-bullhorn"></i> Latest Announcements</h2>
                <p>Stay updated with the latest news and important information from Philippine Red Cross</p>
            </div>
            <div class="announcement-container" id="announcements-grid">
                <?php foreach ($recent_announcements as $announcement): ?>
                <div class="announcement-card">
                    <?php if ($announcement['image_url']): ?>
                    <div class="announcement-image" style="background-image: url('<?= htmlspecialchars($announcement['image_url']) ?>');"></div>
                    <?php endif; ?>
                    <div class="announcement-content">
                        <h3><?= htmlspecialchars($announcement['title']) ?></h3>
                        <p><?= htmlspecialchars(truncateText($announcement['content'], 150)) ?></p>
                        <div class="announcement-meta">
                            <span><i class="fas fa-calendar-alt"></i> <?= date('F j, Y', strtotime($announcement['posted_at'])) ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Dynamic Events Section -->
    <?php if (!empty($upcoming_events)): ?>
    <section class="dynamic-section" id="events-section">
        <div class="content-section">
            <div class="section-title">
                <h2><i class="fas fa-calendar-alt"></i> Upcoming Events</h2>
                <p>Join our humanitarian activities and make a difference in your community</p>
            </div>
            <div class="announcement-container" id="events-grid">
                <?php foreach ($upcoming_events as $event): ?>
                <div class="event-card">
                    <div class="event-header">
                        <h3><?= htmlspecialchars($event['title']) ?></h3>
                        <span class="event-service"><?= htmlspecialchars($event['major_service']) ?></span>
                    </div>
                    <div class="event-content">
                        <?php if ($event['description']): ?>
                        <p class="event-description"><?= htmlspecialchars(truncateText($event['description'], 120)) ?></p>
                        <?php endif; ?>
                        <div class="event-details">
                            <div class="event-detail">
                                <i class="fas fa-calendar"></i>
                                <span><?= date('M j, Y', strtotime($event['event_date'])) ?></span>
                            </div>
                            <div class="event-detail">
                                <i class="fas fa-clock"></i>
                                <span><?= date('g:i A', strtotime($event['start_time'])) ?></span>
                            </div>
                            <div class="event-detail">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?= htmlspecialchars(truncateText($event['location'], 30)) ?></span>
                            </div>
                            <?php if ($event['fee'] > 0): ?>
                            <div class="event-detail">
                                <i class="fas fa-peso-sign"></i>
                                <span>₱<?= number_format($event['fee'], 2) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="event-footer">
                            <div class="event-capacity">
                                <i class="fas fa-users"></i>
                                <span><?= $event['registrations_count'] ?>/<?= $event['capacity'] ?: '∞' ?> registered</span>
                            </div>
                            <a href="login.php?event_id=<?= $event['event_id'] ?>" class="btn-event">
                                <i class="fas fa-user-plus"></i> Register
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Dynamic Training Sessions Section -->
    <?php if (!empty($upcoming_sessions)): ?>
    <section class="dynamic-section training-section">
        <div class="content-section">
            <div class="section-title">
                <h2><i class="fas fa-graduation-cap"></i> Training Sessions</h2>
                <p>Enhance your skills and knowledge through our professional training programs</p>
            </div>
            <div class="announcement-container" id="sessions-grid">
                <?php foreach ($upcoming_sessions as $session): ?>
                <div class="session-card">
                    <div class="session-header">
                        <h3><?= htmlspecialchars($session['title']) ?></h3>
                        <span class="session-service"><?= htmlspecialchars($session['major_service']) ?></span>
                    </div>
                    <div class="session-content">
                        <div class="session-details">
                            <div class="session-detail">
                                <i class="fas fa-calendar"></i>
                                <span><?= date('M j, Y', strtotime($session['session_date'])) ?></span>
                            </div>
                            <div class="session-detail">
                                <i class="fas fa-clock"></i>
                                <span><?= date('g:i A', strtotime($session['start_time'])) ?> - <?= date('g:i A', strtotime($session['end_time'])) ?></span>
                            </div>
                            <div class="session-detail">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?= htmlspecialchars(truncateText($session['venue'], 40)) ?></span>
                            </div>
                            <?php if ($session['instructor']): ?>
                            <div class="session-detail">
                                <i class="fas fa-user-tie"></i>
                                <span><?= htmlspecialchars($session['instructor']) ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="session-detail">
                                <i class="fas fa-users"></i>
                                <span><?= $session['registrations_count'] ?>/<?= $session['capacity'] ?: '∞' ?> registered</span>
                            </div>
                            <?php if ($session['fee'] > 0): ?>
                            <div class="session-detail">
                                <i class="fas fa-peso-sign"></i>
                                <span>₱<?= number_format($session['fee'], 2) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <a href="user/training_registration.php?session_id=<?= $session['session_id'] ?>" class="btn-event">
                            <i class="fas fa-user-plus"></i> Register for Training
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Quick Actions Section -->
    <section class="content-section">
        <div class="section-title">
            <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
            <p>Access common services and resources</p>
        </div>
        <div class="announcement-container">
            <div class="announcement-card">
                <div class="announcement-image" style="background-image: url('assets/images/blood-drive.jpg');"></div>
                <div class="announcement-content">
                    <h3>Blood Donation Drive</h3>
                    <p>Join our nationwide blood donation drive. Help save lives in your community.</p>
                    <a href="user/blood_donation.php" class="read-more">Learn more <i class="fas fa-arrow-right"></i></a>
                </div>
            </div>
            <div class="announcement-card">
                <div class="announcement-image" style="background-image: url('assets/images/hero.jpeg');"></div>
                <div class="announcement-content">
                    <h3>Disaster Preparedness Training</h3>
                    <p>Sign up for our free disaster preparedness workshops happening this month.</p>
                    <a href="user/training_sessions.php" class="read-more">Learn more <i class="fas fa-arrow-right"></i></a>
                </div>
            </div>
        </div>
    </section>

    <!-- Services Section -->
    <section class="events-section">
        <div class="content-section">
            <div class="section-title">
                <h2><i class="fas fa-heart"></i> Our Services</h2>
                <p>Discover how we serve humanity</p>
            </div>
            <div class="services-grid">
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-tint"></i>
                    </div>
                    <h3>Blood Services</h3>
                    <p>Safe blood collection, testing, and distribution to save lives nationwide.</p>
                </div>
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <h3>Training & Education</h3>
                    <p>Professional courses in first aid, CPR, disaster response, and more.</p>
                </div>
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-hands-helping"></i>
                    </div>
                    <h3>Disaster Response</h3>
                    <p>Emergency relief operations and disaster preparedness programs.</p>
                </div>
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-ambulance"></i>
                    </div>
                    <h3>Emergency Services</h3>
                    <p>24/7 emergency medical services and ambulance operations.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="content-section">
            <div class="footer-content">
                <div class="footer-section">
                    <h3>Philippine Red Cross</h3>
                    <p>The Philippine Red Cross is committed to providing humanitarian services that help vulnerable communities become self-reliant.</p>
                    <div class="social-links">
                        <a href="#"><i class="fab fa-facebook"></i></a>
                        <a href="#"><i class="fab fa-twitter"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                        <a href="#"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
                <div class="footer-section">
                    <h4>Quick Links</h4>
                    <ul>
                        <li><a href="login.php">Staff Login</a></li>
                        <li><a href="register.php">Register</a></li>
                        <li><a href="user/blood_donation.php">Blood Donation</a></li>
                        <li><a href="user/training_sessions.php">Training</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>Services</h4>
                    <ul>
                        <li><a href="#">Blood Services</a></li>
                        <li><a href="#">Training & Education</a></li>
                        <li><a href="#">Disaster Response</a></li>
                        <li><a href="#">Emergency Services</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>Contact Info</h4>
                    <div class="contact-info">
                        <p><i class="fas fa-phone"></i> 143 (Emergency Hotline)</p>
                        <p><i class="fas fa-envelope"></i> info@redcross.org.ph</p>
                        <p><i class="fas fa-map-marker-alt"></i> PRC National Headquarters, Manila</p>
                    </div>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2025 Philippine Red Cross. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Scroll to top button -->
    <button class="scroll-top" id="scrollTop">
        <i class="fas fa-chevron-up"></i>
    </button>

    <!-- Enhanced JavaScript -->
    <script>
        class EnhancedIndexManager {
            constructor() {
                this.refreshInterval = 30000; // 30 seconds
                this.lastUpdate = new Date();
                this.isRefreshing = false;
                this.retryCount = 0;
                this.maxRetries = 3;
                
                this.init();
                this.initPhotoSlider();
                this.initScrollEffects();
            }

            init() {
                this.startAutoRefresh();
                
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible') {
                        this.refreshContent();
                    }
                });

                window.addEventListener('online', () => {
                    this.refreshContent();
                });

                console.log('🔄 Enhanced Index Manager initialized');
                console.log(`📡 Auto-refresh every ${this.refreshInterval / 1000} seconds`);
            }

           // Replace your existing initPhotoSlider method with this fixed version

initPhotoSlider() {
    const slider = document.getElementById('photoSlider');
    if (!slider) return;

    const list = slider.querySelector('.list');
    const items = slider.querySelectorAll('.item');
    const dots = slider.querySelectorAll('.dots li');
    const prevBtn = slider.querySelector('#prev');
    const nextBtn = slider.querySelector('#next');

    let currentIndex = 0;
    const itemCount = items.length;

    // Auto slide functionality
    let autoSlideInterval = setInterval(() => {
        this.nextSlide();
    }, 5000);

    // Navigation functions
    const updateSlider = () => {
        // Use percentage-based transform instead of fixed pixels
        const translateX = -currentIndex * 20; // 20% per slide (100% ÷ 5 items)
        list.style.transform = `translateX(${translateX}%)`;
        
        // Update dots
        dots.forEach((dot, index) => {
            dot.classList.toggle('active', index === currentIndex);
        });
    };

    this.nextSlide = () => {
        currentIndex = (currentIndex + 1) % itemCount;
        updateSlider();
    };

    this.prevSlide = () => {
        currentIndex = (currentIndex - 1 + itemCount) % itemCount;
        updateSlider();
    };

    // Event listeners
    nextBtn.addEventListener('click', () => {
        clearInterval(autoSlideInterval);
        this.nextSlide();
        autoSlideInterval = setInterval(() => this.nextSlide(), 5000);
    });

    prevBtn.addEventListener('click', () => {
        clearInterval(autoSlideInterval);
        this.prevSlide();
        autoSlideInterval = setInterval(() => this.nextSlide(), 5000);
    });

    // Dot navigation
    dots.forEach((dot, index) => {
        dot.addEventListener('click', () => {
            clearInterval(autoSlideInterval);
            currentIndex = index;
            updateSlider();
            autoSlideInterval = setInterval(() => this.nextSlide(), 5000);
        });
    });

    // Pause on hover
    slider.addEventListener('mouseenter', () => {
        clearInterval(autoSlideInterval);
    });

    slider.addEventListener('mouseleave', () => {
        autoSlideInterval = setInterval(() => this.nextSlide(), 5000);
    });

    // Touch/swipe support
    let startX = 0;
    let currentX = 0;
    let isDragging = false;

    slider.addEventListener('touchstart', (e) => {
        startX = e.touches[0].clientX;
        isDragging = true;
        clearInterval(autoSlideInterval);
    });

    slider.addEventListener('touchmove', (e) => {
        if (!isDragging) return;
        currentX = e.touches[0].clientX;
        e.preventDefault();
    });

    slider.addEventListener('touchend', () => {
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
        
        autoSlideInterval = setInterval(() => this.nextSlide(), 5000);
    });

    // Initialize first slide
    updateSlider();

    console.log('Photo slider initialized with auto-play');
}

            initScrollEffects() {
                // Scroll to top button
                const scrollTopBtn = document.getElementById('scrollTop');
                
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

                // Intersection Observer for animations
                const observerOptions = {
                    threshold: 0.1,
                    rootMargin: '0px 0px -50px 0px'
                };

                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            entry.target.style.opacity = '1';
                            entry.target.style.transform = 'translateY(0)';
                        }
                    });
                }, observerOptions);

                // Observe elements for scroll animations
                const animatedElements = document.querySelectorAll('.announcement-card, .event-card, .session-card, .service-card');
                animatedElements.forEach(el => {
                    el.style.opacity = '0';
                    el.style.transform = 'translateY(30px)';
                    el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                    observer.observe(el);
                });

                console.log('🎨 Scroll effects initialized');
            }

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
                    console.log('🔄 Refreshing dynamic content...');
                    
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
                        console.log('✅ Content updated successfully');
                    } else {
                        throw new Error(data.message || 'Failed to fetch content');
                    }

                } catch (error) {
                    console.error('❌ Error refreshing content:', error);
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
                const container = document.getElementById('announcements-grid');
                const section = document.getElementById('announcements-section');
                
                if (!announcements || announcements.length === 0) {
                    if (section) section.style.display = 'none';
                    return;
                }

                if (section) section.style.display = 'block';
                if (!container) return;

                this.fadeUpdate(container, announcements.map(announcement => `
                    <div class="announcement-card">
                        ${announcement.image_url ? `
                        <div class="announcement-image" style="background-image: url('${this.escapeHtml(announcement.image_url)}');"></div>
                        ` : ''}
                        <div class="announcement-content">
                            <h3>${this.escapeHtml(announcement.title)}</h3>
                            <p>${this.escapeHtml(this.truncateText(announcement.content, 150))}</p>
                            <div class="announcement-meta">
                                <span><i class="fas fa-calendar-alt"></i> ${this.formatDate(announcement.posted_at)}</span>
                            </div>
                        </div>
                    </div>
                `).join(''));
            }

            async updateEvents(events) {
                const container = document.getElementById('events-grid');
                const section = document.getElementById('events-section');
                
                if (!events || events.length === 0) {
                    if (section) section.style.display = 'none';
                    return;
                }

                if (section) section.style.display = 'block';
                if (!container) return;

                this.fadeUpdate(container, events.map(event => `
                    <div class="event-card">
                        <div class="event-header">
                            <h3>${this.escapeHtml(event.title)}</h3>
                            <span class="event-service">${this.escapeHtml(event.major_service)}</span>
                        </div>
                        <div class="event-content">
                            ${event.description ? `
                            <p class="event-description">${this.escapeHtml(this.truncateText(event.description, 120))}</p>
                            ` : ''}
                            <div class="event-details">
                                <div class="event-detail">
                                    <i class="fas fa-calendar"></i>
                                    <span>${this.formatEventDate(event.event_date)}</span>
                                </div>
                                <div class="event-detail">
                                    <i class="fas fa-clock"></i>
                                    <span>${this.formatTime(event.start_time)}</span>
                                </div>
                                <div class="event-detail">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span>${this.escapeHtml(this.truncateText(event.location, 30))}</span>
                                </div>
                                ${event.fee > 0 ? `
                                <div class="event-detail">
                                    <i class="fas fa-peso-sign"></i>
                                    <span>₱${this.formatCurrency(event.fee)}</span>
                                </div>
                                ` : ''}
                            </div>
                            <div class="event-footer">
                                <div class="event-capacity">
                                    <i class="fas fa-users"></i>
                                    <span>${event.registrations_count}/${event.capacity || '∞'} registered</span>
                                </div>
                                <a href="user/event_registration.php?event_id=${event.event_id}" class="btn-event">
                                    <i class="fas fa-user-plus"></i> Register
                                </a>
                            </div>
                        </div>
                    </div>
                `).join(''));
            }

            async updateSessions(sessions) {
                const container = document.getElementById('sessions-grid');
                const section = document.querySelector('.training-section');
                
                if (!sessions || sessions.length === 0) {
                    if (section) section.style.display = 'none';
                    return;
                }

                if (section) section.style.display = 'block';
                if (!container) return;

                this.fadeUpdate(container, sessions.map(session => `
                    <div class="session-card">
                        <div class="session-header">
                            <h3>${this.escapeHtml(session.title)}</h3>
                            <span class="session-service">${this.escapeHtml(session.major_service)}</span>
                        </div>
                        <div class="session-content">
                            <div class="session-details">
                                <div class="session-detail">
                                    <i class="fas fa-calendar"></i>
                                    <span>${this.formatEventDate(session.session_date)}</span>
                                </div>
                                <div class="session-detail">
                                    <i class="fas fa-clock"></i>
                                    <span>${this.formatTime(session.start_time)} - ${this.formatTime(session.end_time)}</span>
                                </div>
                                <div class="session-detail">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span>${this.escapeHtml(this.truncateText(session.venue, 40))}</span>
                                </div>
                                ${session.instructor ? `
                                <div class="session-detail">
                                    <i class="fas fa-user-tie"></i>
                                    <span>${this.escapeHtml(session.instructor)}</span>
                                </div>
                                ` : ''}
                                <div class="session-detail">
                                    <i class="fas fa-users"></i>
                                    <span>${session.registrations_count}/${session.capacity || '∞'} registered</span>
                                </div>
                                ${session.fee > 0 ? `
                                <div class="session-detail">
                                    <i class="fas fa-peso-sign"></i>
                                    <span>₱${this.formatCurrency(session.fee)}</span>
                                </div>
                                ` : ''}
                            </div>
                            <a href="user/training_registration.php?session_id=${session.session_id}" class="btn-event">
                                <i class="fas fa-user-plus"></i> Register for Training
                            </a>
                        </div>
                    </div>
                `).join(''));
            }

            fadeUpdate(container, htmlContent) {
                container.style.opacity = '0.5';
                container.style.transition = 'opacity 0.3s ease';
                
                setTimeout(() => {
                    container.innerHTML = htmlContent;
                    container.style.opacity = '1';
                    
                    // Re-initialize scroll animations for new content
                    const newElements = container.querySelectorAll('.announcement-card, .event-card, .session-card');
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
                    console.log(`🔄 Retrying... (${this.retryCount}/${this.maxRetries})`);
                    setTimeout(() => {
                        this.refreshContent();
                    }, 5000 * this.retryCount);
                } else {
                    console.log('❌ Max retries reached, stopping auto-refresh');
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
                        indicator.style.background = 'rgba(160, 0, 0, 0.9)';
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

        // Initialize the enhanced index manager when the page loads
        document.addEventListener('DOMContentLoaded', () => {
            new EnhancedIndexManager();
        });

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
    </script>
</body>
</html>