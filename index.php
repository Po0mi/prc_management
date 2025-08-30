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

    <section class="hero parallax">
        <div class="hero-content">
            <h2>Welcome to the PRC Management System</h2>
            <p>This centralized portal allows access for both staff and volunteers to manage blood services, trainings, donations, and more.</p>
            <div class="buttons">
                <a href="login.php" class="btn btn-login"><i class="fas fa-sign-in-alt"></i> Login</a>
                <a href="register.php" class="btn btn-register"><i class="fas fa-user-plus"></i> Register</a>
            </div>
        </div>
    </section>

    <section class="content-section">
        <div class="section-title">
            <h2><i class="fas fa-bullhorn"></i> Announcements</h2>
            <p>Latest updates from Philippine Red Cross</p>
        </div>
        <div class="announcement-container">
            <div class="announcement-card">
                <div class="announcement-image" style="background-image: url('assets/images/blood-drive.jpg');"></div>
                <div class="announcement-content">
                    <h3>New Blood Donation Campaign</h3>
                    <p>Join our nationwide blood donation drive this July. Help save lives in your community.</p>
                    <a href="#" class="read-more">Read more <i class="fas fa-arrow-right"></i></a>
                </div>
            </div>
            <div class="announcement-card">
                <div class="announcement-image" style="background-image: url('assets/images/hero.jpeg');"></div>
                <div class="announcement-content">
                    <h3>Disaster Preparedness Training</h3>
                    <p>Sign up for our free disaster preparedness workshops happening this month.</p>
                    <a href="#" class="read-more">Read more <i class="fas fa-arrow-right"></i></a>
                </div>
            </div>
        </div>
    </section>

    <section class="parallax events-section">
        <div class="content-section">
            <div class="section-title">
                <h2><i class="fas fa-calendar-alt"></i> Upcoming Events</h2>
                <p>Participate in our humanitarian activities</p>
            </div>
            <div class="events-container">
                <div class="event-card">
                    <div class="event-date">
                        <span class="day">15</span>
                        <span class="month">JUL</span>
                    </div>
                    <div class="event-details">
                        <h3>First Aid Training Workshop</h3>
                        <p>9:00 AM - 4:00 PM | PRC Manila Chapter</p>
                        <div class="event-image" style="background-image: url('assets/images/first-aid.jpg');"></div>
                        <a href="#" class="btn btn-event">Register Now</a>
                    </div>
                </div>
                <div class="event-card">
                    <div class="event-date">
                        <span class="day">22</span>
                        <span class="month">JUL</span>
                    </div>
                    <div class="event-details">
                        <h3>Community Blood Drive</h3>
                        <p>8:00 AM - 5:00 PM | SM Megamall</p>
                        <div class="event-image" style="background-image: url('assets/images/blood.jpg');"></div>
                        <a href="#" class="btn btn-event">Register Now</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="content-section">
        <div class="section-title">
            <h2><i class="fas fa-newspaper"></i> Latest News</h2>
            <p>Stay informed with our humanitarian efforts</p>
        </div>
        <div class="news-container">
            <div class="news-card">
                <div class="news-image" style="background-image: url('assets/images/update.png');"></div>
                <div class="news-content">
                    <div class="news-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <h3>New Features Added</h3>
                    <p>Admins can now generate volunteer performance reports directly in the dashboard.</p>
                    <a href="#"><i class="fas fa-arrow-right"></i> Read more</a>
                </div>
            </div>
            <div class="news-card">
                <div class="news-image" style="background-image: url('assets/images/maintenance.png');"></div>
                <div class="news-content">
                    <div class="news-icon">
                        <i class="fas fa-tools"></i>
                    </div>
                    <h3>Maintenance Advisory</h3>
                    <p>Scheduled downtime on June 20, 12:00AM to 2:00AM. Please log out before maintenance begins.</p>
                    <a href="#"><i class="fas fa-arrow-right"></i> Read more</a>
                </div>
            </div>
            <div class="news-card">
                <div class="news-image" style="background-image: url('assets/images/blood-update.png');"></div>
                <div class="news-content">
                    <div class="news-icon">
                        <i class="fas fa-hands-helping"></i>
                    </div>
                    <h3>Volunteer Tools Improved</h3>
                    <p>Volunteers can now see upcoming blood drives and register with one click.</p>
                    <a href="#"><i class="fas fa-arrow-right"></i> Read more</a>
                </div>
            </div>
        </div>
    </section>

    <section class="parallax gallery-section">
        <div class="content-section">
            <div class="section-title">
                <h2><i class="fas fa-images"></i> Photo Gallery</h2>
                <p>Moments from our humanitarian activities</p>
            </div>
            <div class="slider">
                <div class="list">
                    <div class="item"><img src="assets/images/training.jpg" alt="Training"></div>
                    <div class="item"><img src="assets/images/feeding.jpg" alt="Feeding program"></div>
                    <div class="item"><img src="assets/images/blood-drive-pic.jpg" alt="Blood drive"></div>
                </div>
                <div class="buttons">
                    <button id="prev">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z"/>
                        </svg>
                    </button>
                    <button id="next">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"/>
                        </svg>
                    </button>
                </div>
                <ul class="dots">
                    <li class="active"></li>
                    <li></li>
                    <li></li>
                </ul>
            </div>
        </div>
    </section>

    <footer class="system-footer">
        <div class="footer-content">
            <div class="footer-logo">
                <img src="./assets/images/logo.png" alt="PRC Logo" class="prc-logo">
                <p>Philippine Red Cross<br>Management System</p>
            </div>
            <div class="footer-links">
                <a href="https://redcross.org.ph/about-us/history">About Us</a>
                <a href="#">(033) 503-3393/09171170066.<br>iloilo@redcross.org.ph<br>Brgy. Danao, Bonifacio drive, 5000</a>
            </div>
            <div class="footer-social">
                <a href="https://www.facebook.com/profile.php?id=61560549271970&_rdc=1&_rdr"><i class="fab fa-facebook"></i></a>
                <a href="https://www.instagram.com/rcy.iloilo/?hl=en"><i class="fab fa-instagram"></i></a>
            </div>
        </div>
        <div class="footer-copyright">
            <p>&copy; 2023 Philippine Red Cross. All rights reserved.</p>
        </div>
    </footer>

    <script>
      document.addEventListener("DOMContentLoaded", () => {
    console.log("Philippine Red Cross Management System loaded successfully.");
    
    // Header scroll effect
    const header = document.querySelector('.system-header');
    if (header) {
        window.addEventListener('scroll', function() {
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });
    }
    
    // Parallax effect with performance optimization
    let ticking = false;
    window.addEventListener('scroll', function() {
        if (!ticking) {
            window.requestAnimationFrame(function() {
                const parallaxElements = document.querySelectorAll('.parallax');
                const scrollPosition = window.pageYOffset;
                
                parallaxElements.forEach(element => {
                    const elementPosition = element.offsetTop;
                    const elementHeight = element.offsetHeight;
                    
                    // Check if element is in viewport
                    if (scrollPosition > elementPosition - window.innerHeight && 
                        scrollPosition < elementPosition + elementHeight) {
                        const distance = (scrollPosition - elementPosition) * 0.3;
                        element.style.backgroundPositionY = distance + 'px';
                    }
                });
                
                // Show/hide scroll to top button
                const scrollButton = document.querySelector('.scroll-top');
                if (scrollButton) {
                    if (window.pageYOffset > 300) {
                        scrollButton.classList.add('show');
                    } else {
                        scrollButton.classList.remove('show');
                    }
                }
                
                ticking = false;
            });
            
            ticking = true;
        }
    });
    
    // Initialize image slider
    initSlider();
    
    // Create and add scroll to top button
    const scrollButton = document.createElement('div');
    scrollButton.classList.add('scroll-top');
    scrollButton.innerHTML = '<i class="fas fa-arrow-up"></i>';
    document.body.appendChild(scrollButton);
    
    scrollButton.addEventListener('click', () => {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });
    
    // Animation on scroll for cards
    const animatedElements = document.querySelectorAll('.announcement-card, .event-card, .news-card');
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = 1;
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, { threshold: 0.1 });
    
    animatedElements.forEach(element => {
        element.style.opacity = 0;
        element.style.transform = 'translateY(20px)';
        element.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        observer.observe(element);
    });
    
    // Initialize any other components
    initOtherComponents();
});

// Image slider functionality
function initSlider() {
    const slider = document.querySelector('.slider .list');
    const items = document.querySelectorAll('.slider .list .item');
    const next = document.getElementById('next');
    const prev = document.getElementById('prev');
    const dots = document.querySelectorAll('.slider .dots li');
    
    if (!slider || items.length === 0) return;
    
    let lengthItems = items.length - 1;
    let active = 0;
    let refreshInterval;
    let isAnimating = false;
    
    const reloadSlider = () => {
        if (isAnimating) return;
        
        isAnimating = true;
        
        // Add smooth transition
        slider.style.transition = 'left 0.5s ease';
        slider.style.left = -items[active].offsetLeft + 'px';
        
        // Update dots
        const lastActiveDot = document.querySelector('.slider .dots li.active');
        if (lastActiveDot) lastActiveDot.classList.remove('active');
        if (dots[active]) dots[active].classList.add('active');
        
        // Reset animation flag after transition completes
        setTimeout(() => {
            isAnimating = false;
            slider.style.transition = '';
        }, 500);
        
        // Reset autoplay timer
        clearInterval(refreshInterval);
        refreshInterval = setInterval(() => { 
            if (next) next.click(); 
        }, 5000);
    };
    
    // Next button functionality
    if (next) {
        next.addEventListener('click', function() {
            active = active + 1 <= lengthItems ? active + 1 : 0;
            reloadSlider();
        });
    }
    
    // Previous button functionality
    if (prev) {
        prev.addEventListener('click', function() {
            active = active - 1 >= 0 ? active - 1 : lengthItems;
            reloadSlider();
        });
    }
    
    // Dot navigation
    dots.forEach((li, key) => {
        li.addEventListener('click', () => {
            active = key;
            reloadSlider();
        });
    });
    
    // Pause autoplay on hover
    const sliderContainer = document.querySelector('.slider');
    if (sliderContainer) {
        sliderContainer.addEventListener('mouseenter', () => {
            clearInterval(refreshInterval);
        });
        
        sliderContainer.addEventListener('mouseleave', () => {
            clearInterval(refreshInterval);
            refreshInterval = setInterval(() => { 
                if (next) next.click(); 
            }, 5000);
        });
    }
    
    // Handle window resize with debounce
    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            reloadSlider();
        }, 250);
    });
    
    // Initialize slider
    reloadSlider();
    
    // Add keyboard navigation
    document.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowRight') {
            if (next) next.click();
        } else if (e.key === 'ArrowLeft') {
            if (prev) prev.click();
        }
    });
}

// Initialize other components
function initOtherComponents() {
    // Add any other component initializations here
    
    // Example: Smooth scrolling for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('href');
            if (targetId === '#') return;
            
            const targetElement = document.querySelector(targetId);
            if (targetElement) {
                targetElement.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
    
    // Example: Lazy loading for images
    if ('IntersectionObserver' in window) {
        const lazyImages = document.querySelectorAll('img[data-src]');
        
        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    img.src = img.dataset.src;
                    img.removeAttribute('data-src');
                    imageObserver.unobserve(img);
                }
            });
        });
        
        lazyImages.forEach(img => {
            imageObserver.observe(img);
        });
    }
}

// Handle page visibility changes for autoplay
document.addEventListener('visibilitychange', function() {
    const slider = document.querySelector('.slider');
    if (!slider) return;
    
    if (document.hidden) {
        // Page is hidden, pause autoplay
        const nextButton = document.getElementById('next');
        if (nextButton && nextButton.autoplayInterval) {
            clearInterval(nextButton.autoplayInterval);
        }
    } else {
        // Page is visible, restart autoplay
        const nextButton = document.getElementById('next');
        if (nextButton) {
            nextButton.autoplayInterval = setInterval(() => { 
                nextButton.click(); 
            }, 5000);
        }
    }
});

// Add touch support for mobile devices
function addTouchSupport() {
    const slider = document.querySelector('.slider');
    if (!slider) return;
    
    let startX, moveX, currentX = 0;
    let isDragging = false;
    
    slider.addEventListener('touchstart', (e) => {
        startX = e.touches[0].clientX;
        isDragging = true;
        
        // Pause autoplay during interaction
        const nextButton = document.getElementById('next');
        if (nextButton && nextButton.autoplayInterval) {
            clearInterval(nextButton.autoplayInterval);
        }
    });
    
    slider.addEventListener('touchmove', (e) => {
        if (!isDragging) return;
        moveX = e.touches[0].clientX;
        const diffX = moveX - startX;
        
        // Move slider temporarily
        slider.style.transform = `translateX(${currentX + diffX}px)`;
    });
    
    slider.addEventListener('touchend', (e) => {
        if (!isDragging) return;
        isDragging = false;
        
        const diffX = moveX - startX;
        const nextButton = document.getElementById('next');
        const prevButton = document.getElementById('prev');
        
        // Determine if it's a swipe (more than 50px)
        if (Math.abs(diffX) > 50) {
            if (diffX > 0 && prevButton) {
                // Swipe right - go to previous
                prevButton.click();
            } else if (diffX < 0 && nextButton) {
                // Swipe left - go to next
                nextButton.click();
            }
        }
        
        // Reset temporary transform
        slider.style.transform = '';
        currentX = 0;
        
        // Restart autoplay
        if (nextButton) {
            nextButton.autoplayInterval = setInterval(() => { 
                nextButton.click(); 
            }, 5000);
        }
    });
}

// Initialize touch support
document.addEventListener('DOMContentLoaded', addTouchSupport);
    </script>
</body>
</html>