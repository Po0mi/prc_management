// notifications_admin.js - Enhanced Admin Real-time Notification System for Floating Widget
class AdminNotificationSystem {
  constructor(options = {}) {
    this.options = {
      apiUrl: "notifications_api_admin.php",
      checkInterval: 90000,
      toastDuration: 10000,
      maxToasts: 3,
      soundEnabled: true,
      ...options,
    };

    this.notifications = [];
    this.unreadCount = 0;
    this.isOpen = false;
    this.checkTimer = null;
    this.toastContainer = null;
    this.bellElement = null;
    this.panelElement = null;
    this.badgeElement = null;

    this.shownNotificationIds = new Set();
    this.lastCheckTimestamp = null;

    this.init();
  }

  init() {
    this.loadShownNotifications();
    this.createElements();
    this.bindEvents();
    this.startPolling();
    this.checkForNotifications();

    console.log("Admin notification system initialized");
  }

  loadShownNotifications() {
    try {
      const stored = localStorage.getItem("admin_notification_shown_ids");
      if (stored) {
        const ids = JSON.parse(stored);
        const oneDayAgo = Date.now() - 24 * 60 * 60 * 1000;
        this.shownNotificationIds = new Set(
          ids
            .filter((item) => item.timestamp > oneDayAgo)
            .map((item) => item.id)
        );
        this.saveShownNotifications();
      }
    } catch (error) {
      console.warn("Could not load shown notifications:", error);
      this.shownNotificationIds = new Set();
    }
  }

  saveShownNotifications() {
    try {
      const idsWithTimestamp = Array.from(this.shownNotificationIds).map(
        (id) => ({
          id: id,
          timestamp: Date.now(),
        })
      );
      localStorage.setItem(
        "admin_notification_shown_ids",
        JSON.stringify(idsWithTimestamp)
      );
    } catch (error) {
      console.warn("Could not save shown notifications:", error);
    }
  }

  createElements() {
    // Create toast container
    this.toastContainer = document.createElement("div");
    this.toastContainer.className = "toast-container";
    this.toastContainer.style.cssText = `
      position: fixed;
      top: 20px;
      right: 20px;
      z-index: 10000;
      pointer-events: none;
    `;
    document.body.appendChild(this.toastContainer);

    // Find notification elements in floating widget
    this.bellElement = document.getElementById("notificationButton");
    this.badgeElement = document.getElementById("notificationBadge");
    this.panelElement = document.getElementById("notificationWindow");

    if (!this.bellElement || !this.badgeElement || !this.panelElement) {
      console.warn("Notification widget elements not found");
      return;
    }

    // Bind mark all read button
    const markAllReadBtn = document.getElementById("markAllReadBtn");
    if (markAllReadBtn) {
      markAllReadBtn.addEventListener("click", () => {
        this.markAllAsRead();
      });
    }
  }

  bindEvents() {
    if (!this.bellElement) return;

    document.addEventListener("visibilitychange", () => {
      if (document.hidden) {
        this.stopPolling();
      } else {
        this.startPolling();
        this.checkForNotifications();
      }
    });
  }

  startPolling() {
    if (this.checkTimer) {
      clearInterval(this.checkTimer);
    }

    this.checkTimer = setInterval(() => {
      this.checkForNotifications();
    }, this.options.checkInterval);
  }

  stopPolling() {
    if (this.checkTimer) {
      clearInterval(this.checkTimer);
      this.checkTimer = null;
    }
  }

  async checkForNotifications() {
    try {
      const url = `${this.options.apiUrl}?action=check&since=${
        this.lastCheckTimestamp || 0
      }&t=${Date.now()}`;

      const response = await fetch(url, {
        method: "GET",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          "Cache-Control": "no-cache",
        },
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }

      const data = await response.json();

      if (data.success) {
        this.updateNotifications(data.notifications);
        this.lastCheckTimestamp = Date.now();
      } else {
        console.error("Failed to fetch admin notifications:", data.message);
      }
    } catch (error) {
      console.error("Error checking admin notifications:", error);
      setTimeout(() => {
        this.checkForNotifications();
      }, 60000);
    }
  }

  updateNotifications(newNotifications) {
    if (!Array.isArray(newNotifications)) {
      console.warn("Invalid notifications data received");
      return;
    }

    const previousIds = new Set(this.notifications.map((n) => n.id));
    const reallyNewNotifications = newNotifications.filter((n) => {
      return !previousIds.has(n.id) && !this.shownNotificationIds.has(n.id);
    });

    this.notifications = newNotifications;
    this.unreadCount = newNotifications.length;

    this.updateBadge();

    if (this.isOpen) {
      this.renderNotifications();
    }

    if (reallyNewNotifications.length > 0) {
      console.log(
        `Showing ${reallyNewNotifications.length} new admin notifications`
      );

      reallyNewNotifications.forEach((n) => {
        this.shownNotificationIds.add(n.id);
      });
      this.saveShownNotifications();

      // Animate bell
      if (this.bellElement) {
        this.bellElement.classList.add("has-new");
        setTimeout(() => {
          this.bellElement.classList.remove("has-new");
        }, 1000);
      }

      // Toast notifications disabled - notifications only show in panel
      // If you want to re-enable toast popups, uncomment the code below:

      /*
      const sortedNewNotifications = reallyNewNotifications.sort((a, b) => {
        const priorityOrder = { critical: 1, high: 2, medium: 3, low: 4 };
        return priorityOrder[a.priority] - priorityOrder[b.priority];
      });

      sortedNewNotifications
        .slice(0, this.options.maxToasts)
        .forEach((notification, index) => {
          setTimeout(() => {
            this.showToast(notification);
          }, index * 400);
        });
      */

      if (this.options.soundEnabled) {
        const hasCritical = reallyNewNotifications.some(
          (n) => n.priority === "critical"
        );
        this.playNotificationSound(hasCritical);
      }
    }
  }

  updateBadge() {
    if (!this.badgeElement) return;

    if (this.unreadCount > 0) {
      this.badgeElement.textContent =
        this.unreadCount > 99 ? "99+" : this.unreadCount;
      this.badgeElement.style.display = "flex";

      const hasCritical = this.notifications.some(
        (n) => n.priority === "critical"
      );
      if (hasCritical) {
        this.badgeElement.classList.add("critical");
      } else {
        this.badgeElement.classList.remove("critical");
      }
    } else {
      this.badgeElement.style.display = "none";
      this.badgeElement.classList.remove("critical");
    }

    const countElement = document.getElementById("notificationCount");
    if (countElement) {
      countElement.textContent = this.unreadCount;
    }
  }

  togglePanel() {
    if (this.isOpen) {
      this.closePanel();
    } else {
      this.openPanel();
    }
  }

  openPanel() {
    if (!this.panelElement) return;
    this.isOpen = true;
    this.renderNotifications();
  }

  closePanel() {
    this.isOpen = false;
  }

  renderNotifications() {
    const listContainer = document.getElementById("notificationList");
    if (!listContainer) return;

    if (this.notifications.length === 0) {
      listContainer.innerHTML = `
        <div class="notification-empty">
          <i class="fas fa-shield-check"></i>
          <h4>All systems normal</h4>
          <p>No pending notifications. System running smoothly.</p>
        </div>
      `;
      return;
    }

    listContainer.innerHTML = this.notifications
      .map((notification) => {
        const priorityClass =
          notification.priority === "critical"
            ? "critical"
            : notification.priority === "high"
            ? "high"
            : notification.priority === "medium"
            ? "medium"
            : "low";

        return `
          <div class="notification-item unread ${priorityClass}" 
               data-id="${notification.id}"
               data-url="${notification.url || "#"}"
               role="menuitem"
               tabindex="0">
            <div class="notification-priority ${priorityClass}"></div>
            <div class="notification-content">
              <div class="notification-icon ${notification.type}">
                <i class="${notification.icon}"></i>
              </div>
              <div class="notification-text">
                <div class="notification-title-text">${this.escapeHtml(
                  notification.title
                )}</div>
                <div class="notification-message">${this.escapeHtml(
                  notification.message
                )}</div>
                <div class="notification-time">
                  <i class="fas fa-clock"></i>
                  ${this.formatTime(notification.created_at)}
                </div>
              </div>
            </div>
            <div class="notification-actions">
              <button class="mark-read-btn" data-id="${
                notification.id
              }" title="Mark as read">
                <i class="fas fa-check"></i>
              </button>
            </div>
          </div>
        `;
      })
      .join("");

    // Bind event listeners
    listContainer.querySelectorAll(".notification-item").forEach((item) => {
      item.addEventListener("click", (e) => {
        if (!e.target.closest(".notification-actions")) {
          this.handleNotificationClick(item);
        }
      });
    });

    listContainer.querySelectorAll(".mark-read-btn").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        e.stopPropagation();
        const notificationId = btn.dataset.id;
        this.markAsRead(notificationId);
      });
    });
  }

  handleNotificationClick(item) {
    const notificationId = item.dataset.id;
    const notification = this.notifications.find(
      (n) => n.id === notificationId
    );

    if (!notification) {
      console.warn("Notification not found:", notificationId);
      return;
    }

    const actionConfig = this.getNotificationActionConfig(notification);

    this.closePanel();
    this.navigateToNotificationPage(notification, actionConfig.url);
  }

  showToast(notification) {
    const toast = document.createElement("div");
    const priorityClass =
      notification.priority === "critical"
        ? "critical"
        : notification.priority === "high"
        ? "high"
        : notification.priority === "medium"
        ? "medium"
        : "low";

    toast.className = `toast-notification admin-toast ${notification.type} ${priorityClass}`;
    toast.setAttribute("role", "alert");
    toast.setAttribute(
      "aria-live",
      notification.priority === "critical" ? "assertive" : "polite"
    );

    const actionConfig = this.getNotificationActionConfig(notification);

    toast.innerHTML = `
      <div class="toast-priority ${priorityClass}"></div>
      <div class="toast-icon">
        <i class="${notification.icon}"></i>
      </div>
      <div class="toast-content">
        <div class="toast-title">${this.escapeHtml(notification.title)}</div>
        <div class="toast-message">${this.escapeHtml(
          notification.message
        )}</div>
        <div class="toast-priority-label">${notification.priority.toUpperCase()}</div>
      </div>
      <div class="toast-actions">
        <button class="toast-action" aria-label="${
          actionConfig.label
        }" title="${actionConfig.label}">
          <i class="${actionConfig.icon}"></i>
        </button>
        <button class="toast-close" aria-label="Close notification">
          <i class="fas fa-times"></i>
        </button>
      </div>
    `;

    this.toastContainer.appendChild(toast);

    setTimeout(() => {
      toast.classList.add("show");
    }, 100);

    const duration =
      notification.priority === "critical"
        ? this.options.toastDuration * 2
        : this.options.toastDuration;

    const hideTimeout = setTimeout(() => {
      this.hideToast(toast);
    }, duration);

    toast.querySelector(".toast-action").addEventListener("click", (e) => {
      e.stopPropagation();
      clearTimeout(hideTimeout);
      this.hideToast(toast);
      this.navigateToNotificationPage(notification, actionConfig.url);
    });

    toast.querySelector(".toast-close").addEventListener("click", () => {
      clearTimeout(hideTimeout);
      this.hideToast(toast);
      this.markAsRead(notification.id);
    });

    toast.addEventListener("click", (e) => {
      if (e.target.closest(".toast-actions")) return;
      clearTimeout(hideTimeout);
      this.hideToast(toast);
      this.navigateToNotificationPage(notification, actionConfig.url);
    });
  }

  getNotificationActionConfig(notification) {
    const configs = {
      new_user: {
        label: "View User",
        icon: "fas fa-user",
        url: notification.url || "./admin/manage_users.php",
      },
      inventory_low: {
        label: "Check Inventory",
        icon: "fas fa-boxes",
        url: notification.url || "manage_inventory.php",
      },
      inventory_critical: {
        label: "Urgent: Check Stock",
        icon: "fas fa-exclamation-triangle",
        url: notification.url || "manage_inventory.php?filter=critical",
      },
      new_donation: {
        label: "View Donation",
        icon: "fas fa-hand-holding-heart",
        url: notification.url || "manage_donations.php",
      },
      donation_approved: {
        label: "View Details",
        icon: "fas fa-check-circle",
        url: notification.url || "manage_donations.php",
      },
      training_scheduled: {
        label: "View Training",
        icon: "fas fa-calendar-check",
        url: notification.url || "manage_sessions.php",
      },
      training_reminder: {
        label: "Join Training",
        icon: "fas fa-play-circle",
        url: notification.url || "manage_sessions.php",
      },
      document_uploaded: {
        label: "View Document",
        icon: "fas fa-file-alt",
        url: notification.url || "manage_users.php",
      },
      system_alert: {
        label: "View System",
        icon: "fas fa-cog",
        url: notification.url || "dashboard.php",
      },
      announcement: {
        label: "Read More",
        icon: "fas fa-bullhorn",
        url: notification.url || "manage_announcements.php",
      },
      volunteer_application: {
        label: "Review Application",
        icon: "fas fa-user-plus",
        url: notification.url || "manage_volunteers.php",
      },
      event_reminder: {
        label: "View Event",
        icon: "fas fa-calendar-alt",
        url: notification.url || "manage_events.php",
      },
      maintenance_due: {
        label: "Schedule Maintenance",
        icon: "fas fa-wrench",
        url: notification.url || "manage_inventory.php",
      },
      approval_required: {
        label: "Review & Approve",
        icon: "fas fa-check-double",
        url: notification.url || "training_request.php",
      },
    };

    return (
      configs[notification.type] || {
        label: "View Details",
        icon: "fas fa-eye",
        url: notification.url || "#",
      }
    );
  }

  navigateToNotificationPage(notification, targetUrl) {
    this.markAsRead(notification.id);

    if (!targetUrl || targetUrl === "#") {
      console.warn("No valid URL for notification:", notification);
      return;
    }

    if (
      !targetUrl.startsWith("http") &&
      !targetUrl.startsWith("/") &&
      !targetUrl.includes("admin/")
    ) {
      targetUrl = "admin/" + targetUrl;
    }

    targetUrl = this.addNotificationParameters(notification, targetUrl);

    setTimeout(() => {
      window.location.href = targetUrl;
    }, 150);
  }

  addNotificationParameters(notification, url) {
    const urlObj = new URL(url, window.location.origin);

    urlObj.searchParams.set("notification_id", notification.id);

    switch (notification.type) {
      case "new_user":
        if (notification.user_id) {
          urlObj.searchParams.set("highlight_user", notification.user_id);
        }
        break;

      case "inventory_low":
      case "inventory_critical":
        if (notification.item_id) {
          urlObj.searchParams.set("highlight_item", notification.item_id);
        }
        if (notification.priority === "critical") {
          urlObj.searchParams.set("filter", "critical");
        }
        break;

      case "new_donation":
      case "donation_approved":
        if (notification.donation_id) {
          urlObj.searchParams.set(
            "highlight_donation",
            notification.donation_id
          );
        }
        break;

      case "training_scheduled":
      case "training_reminder":
        if (notification.training_id) {
          urlObj.searchParams.set(
            "highlight_training",
            notification.training_id
          );
        }
        break;

      case "document_uploaded":
        if (notification.document_id) {
          urlObj.searchParams.set(
            "highlight_document",
            notification.document_id
          );
        }
        break;

      case "volunteer_application":
        if (notification.application_id) {
          urlObj.searchParams.set(
            "highlight_application",
            notification.application_id
          );
        }
        break;

      case "event_reminder":
        if (notification.event_id) {
          urlObj.searchParams.set("highlight_event", notification.event_id);
        }
        break;
    }

    return urlObj.toString();
  }

  hideToast(toast) {
    toast.classList.add("hide");
    setTimeout(() => {
      if (toast.parentNode) {
        toast.parentNode.removeChild(toast);
      }
    }, 300);
  }

  async markAsRead(notificationId) {
    try {
      const response = await fetch(this.options.apiUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          action: "mark_read",
          notification_id: notificationId,
        }),
      });

      if (response.ok) {
        this.notifications = this.notifications.filter(
          (n) => n.id !== notificationId
        );
        this.unreadCount = this.notifications.length;
        this.updateBadge();

        if (this.isOpen) {
          this.renderNotifications();
        }
      }
    } catch (error) {
      console.error("Error marking notification as read:", error);
    }
  }

  async markAllAsRead() {
    try {
      const response = await fetch(this.options.apiUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          action: "mark_all_read",
        }),
      });

      if (response.ok) {
        this.notifications = [];
        this.unreadCount = 0;
        this.updateBadge();
        this.renderNotifications();
      }
    } catch (error) {
      console.error("Error marking all notifications as read:", error);
    }
  }

  playNotificationSound(isCritical = false) {
    if (!this.options.soundEnabled) return;

    try {
      if (!this.audioContext) {
        this.audioContext = new (window.AudioContext ||
          window.webkitAudioContext)();
      }

      if (isCritical) {
        this.playUrgentSound();
      } else {
        this.playRegularSound();
      }
    } catch (error) {
      console.warn("Could not play notification sound:", error);
    }
  }

  playRegularSound() {
    const frequency = 800;
    const duration = 200;

    const oscillator = this.audioContext.createOscillator();
    const gainNode = this.audioContext.createGain();

    oscillator.connect(gainNode);
    gainNode.connect(this.audioContext.destination);

    oscillator.frequency.setValueAtTime(
      frequency,
      this.audioContext.currentTime
    );
    oscillator.type = "sine";

    gainNode.gain.setValueAtTime(0.1, this.audioContext.currentTime);
    gainNode.gain.exponentialRampToValueAtTime(
      0.01,
      this.audioContext.currentTime + duration / 1000
    );

    oscillator.start(this.audioContext.currentTime);
    oscillator.stop(this.audioContext.currentTime + duration / 1000);
  }

  playUrgentSound() {
    const frequencies = [1000, 800, 1000];
    const duration = 150;

    frequencies.forEach((freq, index) => {
      setTimeout(() => {
        const oscillator = this.audioContext.createOscillator();
        const gainNode = this.audioContext.createGain();

        oscillator.connect(gainNode);
        gainNode.connect(this.audioContext.destination);

        oscillator.frequency.setValueAtTime(
          freq,
          this.audioContext.currentTime
        );
        oscillator.type = "square";

        gainNode.gain.setValueAtTime(0.15, this.audioContext.currentTime);
        gainNode.gain.exponentialRampToValueAtTime(
          0.01,
          this.audioContext.currentTime + duration / 1000
        );

        oscillator.start(this.audioContext.currentTime);
        oscillator.stop(this.audioContext.currentTime + duration / 1000);
      }, index * (duration + 50));
    });
  }

  formatTime(timestamp) {
    const date = new Date(timestamp);
    const now = new Date();
    const diff = now - date;

    if (diff < 60000) {
      return "Just now";
    } else if (diff < 3600000) {
      const minutes = Math.floor(diff / 60000);
      return `${minutes}m ago`;
    } else if (diff < 86400000) {
      const hours = Math.floor(diff / 3600000);
      return `${hours}h ago`;
    } else {
      return date.toLocaleDateString();
    }
  }

  escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
  }

  refresh() {
    this.checkForNotifications();
  }

  setSoundEnabled(enabled) {
    this.options.soundEnabled = enabled;
    localStorage.setItem("admin_notifications_sound", enabled.toString());
  }

  getUnreadCount() {
    return this.unreadCount;
  }

  destroy() {
    this.stopPolling();

    if (this.toastContainer && this.toastContainer.parentNode) {
      this.toastContainer.parentNode.removeChild(this.toastContainer);
    }

    if (this.audioContext) {
      this.audioContext.close();
    }
  }

  static highlightPageElement(type, id) {
    setTimeout(() => {
      let targetElement = null;

      switch (type) {
        case "highlight_user":
          targetElement = document.querySelector(`[data-user-id="${id}"]`);
          break;

        case "highlight_item":
          targetElement = document.querySelector(`[data-item-id="${id}"]`);
          break;

        case "highlight_donation":
          targetElement = document.querySelector(`[data-donation-id="${id}"]`);
          break;

        case "highlight_training":
          targetElement = document.querySelector(`[data-training-id="${id}"]`);
          break;

        case "highlight_document":
          targetElement = document.querySelector(`[data-document-id="${id}"]`);
          break;

        case "highlight_application":
          targetElement = document.querySelector(
            `[data-application-id="${id}"]`
          );
          break;

        case "highlight_event":
          targetElement = document.querySelector(`[data-event-id="${id}"]`);
          break;
      }

      if (targetElement) {
        targetElement.scrollIntoView({
          behavior: "smooth",
          block: "center",
        });

        targetElement.classList.add("notification-highlight");

        setTimeout(() => {
          targetElement.classList.remove("notification-highlight");
        }, 3000);
      }
    }, 500);
  }
}

// Initialize page highlighting on page load
function initializePageHighlighting() {
  const urlParams = new URLSearchParams(window.location.search);

  for (const [key, value] of urlParams) {
    if (key.startsWith("highlight_")) {
      AdminNotificationSystem.highlightPageElement(key, value);
    }
  }

  const notificationId = urlParams.get("notification_id");
  if (notificationId && window.adminNotifications) {
    window.adminNotifications.markAsRead(notificationId);
  }
}

// Initialize the admin notification system when DOM is ready
if (typeof document !== "undefined") {
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
      window.adminNotifications = new AdminNotificationSystem();
      initializePageHighlighting();
    });
  } else {
    window.adminNotifications = new AdminNotificationSystem();
    initializePageHighlighting();
  }
}

// Export for module systems
if (typeof module !== "undefined" && module.exports) {
  module.exports = AdminNotificationSystem;
}
