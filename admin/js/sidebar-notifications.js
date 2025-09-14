// Simple Sidebar Notification System
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
    // Simple mapping of pages to notification types
    const pageMapping = {
      "manage_events.php": ["registration", "urgent_action", "upcoming"],
      "manage_sessions.php": ["training", "training_sessions"],
      "training_request.php": ["requests", "training_requests"],
      "manage_donations.php": ["donation", "blood_donation"],
      "manage_inventory.php": ["inventory", "critical_stock"],
      "manage_volunteers.php": ["volunteers", "volunteer_applications"],
      "manage_users.php": ["user_activity", "new_users"],
      "manage_announcements.php": ["announcements", "announcement"],
    };

    // Add badges to navigation links
    Object.keys(pageMapping).forEach((page) => {
      const navLink = document.querySelector(`a[href*="${page}"]`);
      if (navLink && !navLink.querySelector(".nav-badge")) {
        const badge = document.createElement("span");
        badge.className = "nav-badge hidden";
        badge.dataset.types = JSON.stringify(pageMapping[page]);
        navLink.appendChild(badge);
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
        `${this.options.apiUrl}?action=get_sidebar_counts&t=${Date.now()}`
      );
      const data = await response.json();

      if (data.success) {
        this.updateBadges(data.counts);
      }
    } catch (error) {
      console.error("Error checking notifications:", error);
    }
  }

  updateBadges(counts) {
    document.querySelectorAll(".nav-badge").forEach((badge) => {
      const types = JSON.parse(badge.dataset.types || "[]");
      let totalCount = 0;

      // Sum up counts for this badge's types
      types.forEach((type) => {
        if (counts[type]) {
          totalCount += counts[type];
        }
      });

      // Update badge display
      if (totalCount > 0) {
        badge.textContent = totalCount > 99 ? "99+" : totalCount;
        badge.classList.remove("hidden");

        // Simple priority styling
        badge.className = "nav-badge";
        if (totalCount >= 10) {
          badge.classList.add("high");
        } else if (totalCount >= 5) {
          badge.classList.add("medium");
        } else {
          badge.classList.add("low");
        }
      } else {
        badge.classList.add("hidden");
      }
    });
  }

  // Public method to refresh
  refresh() {
    this.checkForUpdates();
  }
}

// Simple CSS styles
const simpleStyles = `
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
}

.sidebar-nav .nav-link {
    position: relative;
}

/* Collapsed sidebar adjustments */
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
document.head.insertAdjacentHTML("beforeend", simpleStyles);

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", () => {
    window.sidebarNotifications = new SimpleSidebarNotifications();
  });
} else {
  window.sidebarNotifications = new SimpleSidebarNotifications();
}
