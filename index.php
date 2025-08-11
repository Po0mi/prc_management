<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Philippine Red Cross - Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/index.css?v=<?php echo time(); ?>">
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
            <p style="color:#666;">Latest updates from Philippine Red Cross</p>
        </div>
        <div class="announcement-container">
            <div class="announcement-card">
                <div class="announcement-image" style="background-image: url('assets/blood-drive.jpg');"></div>
                <div class="announcement-content">
                    <h3>New Blood Donation Campaign</h3>
                    <p>Join our nationwide blood donation drive this July. Help save lives in your community.</p>
                    <a href="#" class="read-more">Read more <i class="fas fa-arrow-right"></i></a>
                </div>
            </div>
            <div class="announcement-card">
                <div class="announcement-image" style="background-image: url('assets/hero.jpeg');"></div>
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
                <p style="color:white;">Participate in our humanitarian activities</p>
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
                        <div class="event-image" style="background-image: url('assets/first-aid.jpg');"></div>
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
                        <div class="event-image" style="background-image: url('assets/blood.jpg');"></div>
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
                <div class="news-image" style="background-image: url('assets/update.png');"></div>
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
                <div class="news-image" style="background-image: url('assets/maintenance.png');"></div>
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
                <div class="news-image" style="background-image: url('assets/blood-update.png');"></div>
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
                <div class="item"><img src="assets/training.jpg" alt="Training"></div>
                <div class="item"><img src="assets/feeding.jpg" alt="Feeding program"></div>
                <div class="item"><img src="assets/blood-drive-pic.jpg" alt="Blood drive"></div>
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
                <li></li>
            </ul>
        </div>
    </div>
</section>

    <footer class="system-footer">
        <div class="footer-content">
            <div class="footer-logo">
                <img src="assets/logo.png" alt="PRC Logo" class="prc-logo">
                <p>Philippine Red Cross<br>Management System</p>
            </div>
            <div class="footer-links">
                <a href="#">About Us</a>
                <a href="#">Contact</a>
                <a href="#">Privacy Policy</a>
                <a href="#">Terms of Service</a>
            </div>
            <div class="footer-social">
                <a href="#"><i class="fab fa-facebook"></i></a>
                <a href="#"><i class="fab fa-twitter"></i></a>
                <a href="#"><i class="fab fa-instagram"></i></a>
            </div>
        </div>
        <div class="footer-copyright">
            <p>&copy; 2023 Philippine Red Cross. All rights reserved.</p>
        </div>
    </footer>

    <script>
        document.addEventListener("DOMContentLoaded", () => {
            console.log("Landing page loaded successfully.");
            
            // Add parallax effect
            window.addEventListener('scroll', function() {
                const parallaxElements = document.querySelectorAll('.parallax');
                parallaxElements.forEach(element => {
                    const scrollPosition = window.pageYOffset;
                    const elementPosition = element.offsetTop;
                    const distance = (scrollPosition - elementPosition) * 0.3;
                    element.style.backgroundPositionY = distance + 'px';
                });
            });
        });
    document.addEventListener('DOMContentLoaded', function() {
    const slider = document.querySelector('.slider .list');
    const items = document.querySelectorAll('.slider .list .item');
    const next = document.getElementById('next');
    const prev = document.getElementById('prev');
    const dots = document.querySelectorAll('.slider .dots li');
    let lengthItems = items.length - 1;
    let active = 0;

    next.onclick = function() {
        active = active + 1 <= lengthItems ? active + 1 : 0;
        reloadSlider();
    };

    prev.onclick = function() {
        active = active - 1 >= 0 ? active - 1 : lengthItems;
        reloadSlider();
    };

    let refreshInterval = setInterval(() => { next.click(); }, 3000);

    function reloadSlider() {
        slider.style.left = -items[active].offsetLeft + 'px';

        const lastActiveDot = document.querySelector('.slider .dots li.active');
        lastActiveDot.classList.remove('active');
        dots[active].classList.add('active');

        clearInterval(refreshInterval);
        refreshInterval = setInterval(() => { next.click(); }, 3000);
    }

    dots.forEach((li, key) => {
        li.addEventListener('click', () => {
            active = key;
            reloadSlider();
        });
    });

    window.onresize = function() {
        reloadSlider();
    };
});
    </script>
</body>
</html>