// Enhanced Sidebar Notification System
class SimpleSidebarNotifications {
  constructor(options = {}) {
    this.options = {
      apiUrl: "notifications_api_admin.php",
      checkInterval: 30000, // 30 seconds
      ...options,
    };

    this.init();
  }

  init() {
    this.createBadges();
    this.startPolling();
    this.checkForUpdates();
  }

  createBadges() {
    // Mapping of pages to notification types
    const pageMapping = {
      "manage_events.php": "events",
      "manage_sessions.php": "sessions",
      "training_request.php": "training_requests",
      "manage_donations.php": "donations",
      "manage_users.php": "users",
      "manage_inventory.php": "inventory",
      "manage_merch.php": "merch",
      "manage_volunteers.php": "volunteers",
    };

    // Add badges to navigation links
    Object.keys(pageMapping).forEach((page) => {
      const navLink = document.querySelector(`a[href*="${page}"]`);
      if (navLink && !navLink.querySelector(".nav-badge")) {
        const badge = document.createElement("span");
        badge.className = "nav-badge hidden";
        badge.dataset.type = pageMapping[page];
        badge.dataset.page = page;
        navLink.appendChild(badge);

        // Mark as viewed when clicked
        navLink.addEventListener("click", () => {
          this.markAsViewed(pageMapping[page]);
        });
      }
    });
  }

  startPolling() {
    setInterval(() => {
      this.checkForUpdates();
    }, this.options.checkInterval);
  }

  async checkForUpdates() {
    try {
      const response = await fetch(
        `${this.options.apiUrl}?action=get_notifications&t=${Date.now()}`
      );
      const data = await response.json();

      if (data.success) {
        this.updateBadges(data.notifications);
      }
    } catch (error) {
      console.error("Error checking notifications:", error);
    }
  }

  updateBadges(notifications) {
    document.querySelectorAll(".nav-badge").forEach((badge) => {
      const type = badge.dataset.type;
      const count = notifications[type] || 0;

      if (count > 0) {
        badge.textContent = count > 99 ? "99+" : count;
        badge.classList.remove("hidden");

        // Add pulse animation
        if (!badge.classList.contains("pulse")) {
          badge.classList.add("pulse");
          setTimeout(() => badge.classList.remove("pulse"), 2000);
        }

        // Priority styling
        badge.className = "nav-badge";
        if (count >= 10) {
          badge.classList.add("high");
        } else if (count >= 5) {
          badge.classList.add("medium");
        } else {
          badge.classList.add("low");
        }
      } else {
        badge.classList.add("hidden");
      }
    });
  }

  async markAsViewed(type) {
    try {
      await fetch(this.options.apiUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: `action=mark_viewed&type=${encodeURIComponent(type)}`,
      });

      // Hide badge immediately
      const badge = document.querySelector(`[data-type="${type}"]`);
      if (badge) {
        badge.classList.add("hidden");
      }
    } catch (error) {
      console.error("Error marking as viewed:", error);
    }
  }

  refresh() {
    this.checkForUpdates();
  }
}

// Notification Styles
const notificationStyles = `
<style>
.nav-badge {
    position: absolute;
    top: 8px;
    right: 12px;
    background: #dc3545;
    color: white;
    border-radius: 10px;
    padding: 2px 6px;
    font-size: 0.7rem;
    font-weight: 600;
    min-width: 18px;
    text-align: center;
    z-index: 10;
    transition: all 0.3s ease;
}

.nav-badge.hidden {
    display: none;
}

.nav-badge.low {
    background: #28a745;
}

.nav-badge.medium {
    background: #ffc107;
    color: #333;
}

.nav-badge.high {
    background: #dc3545;
    box-shadow: 0 0 10px rgba(220, 53, 69, 0.5);
}

.nav-badge.pulse {
    animation: pulse 1s ease-in-out 2;
}

@keyframes pulse {
    0%, 100% {
        transform: scale(1);
        opacity: 1;
    }
    50% {
        transform: scale(1.2);
        opacity: 0.8;
    }
}

.sidebar-nav .nav-link {
    position: relative;
}

.sidebar.collapsed .nav-badge {
    top: 6px;
    right: 6px;
    font-size: 0.6rem;
    padding: 1px 4px;
    min-width: 14px;
}
</style>
`;

// Initialize
document.head.insertAdjacentHTML("beforeend", notificationStyles);

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", () => {
    window.sidebarNotifications = new SimpleSidebarNotifications();
  });
} else {
  window.sidebarNotifications = new SimpleSidebarNotifications();
}
