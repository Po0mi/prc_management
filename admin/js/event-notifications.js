// Complete Unified Inline Registration Notification System
class UnifiedInlineNotifier {
  constructor() {
    this.registrationCounts = new Map();
    this.newRegistrationCounts = new Map(); // Track new registrations count
    this.checkInterval = 30000; // 30 seconds
    this.lastCheck = new Date(Date.now() - 60000); // 1 minute ago
    this.pageType = this.detectPageType();
    this.init();
  }

  detectPageType() {
    if (document.querySelector(".events-container")) return "events";
    if (document.querySelector(".sessions-container")) return "sessions";
    return null;
  }

  init() {
    if (this.pageType) {
      this.loadCurrentCounts();
      this.startChecking();
      this.setupBadgeClickHandlers();
      console.log(
        `Unified inline notifications initialized for ${this.pageType}`
      );
    }
  }

  // Load current registration counts from the table
  loadCurrentCounts() {
    document.querySelectorAll(".registrations-badge").forEach((badge) => {
      const row = badge.closest("tr");

      // Look for item ID in the table row using multiple methods
      let itemId = null;

      // Method 1: Look for ID in the ID text (ID: #123)
      const idElement = row?.querySelector('div[style*="color: var(--gray)"]');
      if (idElement && idElement.textContent.includes("ID: #")) {
        const match = idElement.textContent.match(/ID: #(\d+)/);
        if (match) {
          itemId = parseInt(match[1]);
        }
      }

      // Method 2: Look in the "View Registrations" link URL
      if (!itemId) {
        const viewParam =
          this.pageType === "events" ? "view_event=" : "view_session=";
        const viewLink = row?.querySelector(`a[href*="${viewParam}"]`);
        if (viewLink) {
          const urlMatch = viewLink.href.match(
            new RegExp(`${viewParam}(\\d+)`)
          );
          if (urlMatch) {
            itemId = parseInt(urlMatch[1]);
          }
        }
      }

      // Method 3: Look in the edit button onclick attribute
      if (!itemId) {
        const editButton = row?.querySelector(
          'button[onclick*="openEditModal"]'
        );
        if (editButton) {
          const idField =
            this.pageType === "events" ? "event_id" : "session_id";
          const onclickMatch = editButton
            .getAttribute("onclick")
            .match(new RegExp(`${idField}['":\\s]*(\\d+)`));
          if (onclickMatch) {
            itemId = parseInt(onclickMatch[1]);
          }
        }
      }

      if (itemId) {
        const countText = badge.textContent;
        const match = countText.match(/(\d+)/);
        const currentCount = match ? parseInt(match[1]) : 0;

        this.registrationCounts.set(itemId, currentCount);
        this.newRegistrationCounts.set(itemId, 0); // Initialize new count
        console.log(
          `Loaded count for ${this.pageType.slice(
            0,
            -1
          )} ${itemId}: ${currentCount}`
        );

        // Add data attribute for future reference
        const dataAttr =
          this.pageType === "events" ? "data-event-id" : "data-session-id";
        badge.setAttribute(dataAttr, itemId);
      }
    });
    console.log(
      `Total ${this.pageType} tracked:`,
      this.registrationCounts.size
    );
  }

  startChecking() {
    setInterval(() => {
      this.checkForNewRegistrations();
    }, this.checkInterval);
  }

  async checkForNewRegistrations() {
    try {
      const type = this.pageType === "events" ? "event" : "training";
      const response = await fetch(
        `./check_new_registrations.php?since=${this.lastCheck.toISOString()}&type=${type}`
      );

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const data = await response.json();

      if (
        data.success &&
        data.newRegistrations &&
        data.newRegistrations.length > 0
      ) {
        this.processNewRegistrations(data.newRegistrations);
        this.lastCheck = new Date();
      } else if (!data.success) {
        console.error("API Error:", data.error, data.debug || "");
      }
    } catch (error) {
      console.error("Error checking registrations:", error);
    }
  }

  processNewRegistrations(registrations) {
    // Group registrations by item ID
    const registrationsByItem = new Map();

    registrations.forEach((reg) => {
      // Handle both event_id and session_id based on page type
      const itemId =
        this.pageType === "events"
          ? parseInt(reg.event_id)
          : parseInt(reg.session_id);

      if (itemId) {
        if (!registrationsByItem.has(itemId)) {
          registrationsByItem.set(itemId, []);
        }
        registrationsByItem.get(itemId).push(reg);
      }
    });

    // Process each item's new registrations
    registrationsByItem.forEach((regs, itemId) => {
      this.addNewRegistrations(itemId, regs.length);
      this.updateRegistrationCount(itemId, regs.length);
      this.showPersistentNotificationBadge(itemId);
    });
  }

  addNewRegistrations(itemId, count) {
    const currentNewCount = this.newRegistrationCounts.get(itemId) || 0;
    this.newRegistrationCounts.set(itemId, currentNewCount + count);
    console.log(
      `Added ${count} new registrations to ${this.pageType.slice(
        0,
        -1
      )} ${itemId}. Total new: ${currentNewCount + count}`
    );
  }

  showPersistentNotificationBadge(itemId) {
    const dataAttr =
      this.pageType === "events" ? "data-event-id" : "data-session-id";
    const badge = document.querySelector(`[${dataAttr}="${itemId}"]`);

    if (!badge) {
      console.warn(
        `No registrations-badge found for ${this.pageType.slice(
          0,
          -1
        )} ${itemId}`
      );
      return;
    }

    // Remove existing notification badge
    const existingBadge = badge.querySelector(".new-reg-counter");
    if (existingBadge) {
      existingBadge.remove();
    }

    const newCount = this.newRegistrationCounts.get(itemId) || 0;
    if (newCount === 0) return;

    // Create persistent notification counter
    const notificationBadge = document.createElement("div");
    notificationBadge.className = "new-reg-counter";
    notificationBadge.textContent = newCount;
    notificationBadge.title = `${newCount} new registration${
      newCount > 1 ? "s" : ""
    }. Click to dismiss.`;

    // Add to badge container
    badge.style.position = "relative";
    badge.appendChild(notificationBadge);

    console.log(
      `Added persistent notification badge for ${this.pageType.slice(
        0,
        -1
      )} ${itemId}: ${newCount} new`
    );
  }

  setupBadgeClickHandlers() {
    // Add click handler to clear notification when badge is clicked
    document.addEventListener("click", (e) => {
      const badge = e.target.closest(".registrations-badge");
      if (badge) {
        const dataAttr =
          this.pageType === "events" ? "data-event-id" : "data-session-id";
        const itemId = parseInt(badge.getAttribute(dataAttr));

        if (itemId) {
          this.clearNotificationBadge(itemId);
        }
      }
    });
  }

  clearNotificationBadge(itemId) {
    const dataAttr =
      this.pageType === "events" ? "data-event-id" : "data-session-id";
    const badge = document.querySelector(`[${dataAttr}="${itemId}"]`);

    if (badge) {
      const notificationBadge = badge.querySelector(".new-reg-counter");
      if (notificationBadge) {
        notificationBadge.remove();
        this.newRegistrationCounts.set(itemId, 0);
        console.log(
          `Cleared notification for ${this.pageType.slice(0, -1)} ${itemId}`
        );
      }
    }
  }

  updateRegistrationCount(itemId, newCount) {
    // Find the badge using the data attribute we added
    const dataAttr =
      this.pageType === "events" ? "data-event-id" : "data-session-id";
    const badge = document.querySelector(`[${dataAttr}="${itemId}"]`);

    if (!badge) {
      console.warn(
        `No badge found for ${this.pageType.slice(0, -1)} ${itemId}`
      );
      return;
    }

    // Update count
    const oldCount = this.registrationCounts.get(itemId) || 0;
    const totalCount = oldCount + newCount;
    this.registrationCounts.set(itemId, totalCount);

    // Update badge text (excluding the notification counter)
    const currentText = badge.innerHTML;
    const updatedText = currentText.replace(/(\d+)/, totalCount);
    badge.innerHTML = updatedText;

    // Re-add the notification badge if it exists
    const notificationCount = this.newRegistrationCounts.get(itemId) || 0;
    if (notificationCount > 0) {
      this.showPersistentNotificationBadge(itemId);
    }

    console.log(
      `Updated ${this.pageType.slice(
        0,
        -1
      )} ${itemId} count from ${oldCount} to ${totalCount}`
    );
  }

  escapeHtml(text) {
    if (!text) return "";
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
  }

  // Manual testing methods
  testNotification(itemId, count = 1) {
    console.log(
      `Testing notification for ${this.pageType.slice(
        0,
        -1
      )} ${itemId} with ${count} new registrations`
    );
    this.addNewRegistrations(itemId, count);
    this.showPersistentNotificationBadge(itemId);
  }

  clearAllNotifications() {
    this.newRegistrationCounts.forEach((count, itemId) => {
      if (count > 0) {
        this.clearNotificationBadge(itemId);
      }
    });
    console.log("Cleared all notifications");
  }

  // Debug method to show current state
  showDebugInfo() {
    console.log("=== Debug Info ===");
    console.log("Page Type:", this.pageType);
    console.log("Registration Counts:", this.registrationCounts);
    console.log("New Registration Counts:", this.newRegistrationCounts);
    console.log("Tracked Items:", this.registrationCounts.size);
    console.log("==================");
  }
}

// CSS for persistent notification counter (like sidebar)
const unifiedNotificationCSS = `
<style>
.new-reg-counter {
  position: absolute;
  top: -8px;
  right: -8px;
  background: #dc3545;
  color: white;
  border-radius: 50%;
  min-width: 18px;
  height: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  font-weight: bold;
  border: 2px solid white;
  box-shadow: 0 2px 4px rgba(0,0,0,0.2);
  z-index: 1000;
  cursor: pointer;
  transition: all 0.2s ease;
}

.new-reg-counter:hover {
  background: #c82333;
  transform: scale(1.1);
}

/* For double-digit numbers */
.new-reg-counter {
  padding: 0 4px;
  min-width: 18px;
  width: auto;
  border-radius: 12px;
}

/* Ensure proper positioning context */
.registrations-badge {
  position: relative !important;
  display: inline-block;
}

/* Add a subtle pulse animation for new notifications */
@keyframes notification-pulse {
  0% {
    box-shadow: 0 2px 4px rgba(0,0,0,0.2), 0 0 0 0 rgba(220, 53, 69, 0.7);
  }
  50% {
    box-shadow: 0 2px 4px rgba(0,0,0,0.2), 0 0 0 4px rgba(220, 53, 69, 0);
  }
  100% {
    box-shadow: 0 2px 4px rgba(0,0,0,0.2), 0 0 0 0 rgba(220, 53, 69, 0);
  }
}

.new-reg-counter.new {
  animation: notification-pulse 2s ease-out;
}

/* Mobile adjustments */
@media (max-width: 768px) {
  .new-reg-counter {
    min-width: 16px;
    height: 16px;
    font-size: 10px;
    top: -6px;
    right: -6px;
  }
}

/* Badge hover effect */
.registrations-badge:hover .new-reg-counter {
  transform: scale(1.05);
}
</style>
`;

// Initialize when page loads
document.addEventListener("DOMContentLoaded", function () {
  // Only run on admin pages with registration badges
  if (document.querySelector(".registrations-badge")) {
    // Add CSS
    document.head.insertAdjacentHTML("beforeend", unifiedNotificationCSS);

    // Initialize unified notification system
    window.unifiedInlineNotifier = new UnifiedInlineNotifier();

    // Add debug methods to window for easy testing
    window.testNotification = function (itemId, count = 1) {
      if (window.unifiedInlineNotifier) {
        window.unifiedInlineNotifier.testNotification(itemId, count);
      } else {
        console.error("Notification system not initialized");
      }
    };

    window.clearNotification = function (itemId) {
      if (window.unifiedInlineNotifier) {
        window.unifiedInlineNotifier.clearNotificationBadge(itemId);
      } else {
        console.error("Notification system not initialized");
      }
    };

    window.clearAllNotifications = function () {
      if (window.unifiedInlineNotifier) {
        window.unifiedInlineNotifier.clearAllNotifications();
      } else {
        console.error("Notification system not initialized");
      }
    };

    window.showNotificationDebug = function () {
      if (window.unifiedInlineNotifier) {
        window.unifiedInlineNotifier.showDebugInfo();
      } else {
        console.error("Notification system not initialized");
      }
    };
  }
});
