// notifications_admin.js - Complete Enhanced Admin Real-time Notification System
class AdminNotificationSystem {
  constructor(options = {}) {
    this.options = {
      apiUrl: "notifications_api_admin.php",
      checkInterval: 90000, // Check more frequently for admins
      toastDuration: 10000, // Longer duration for admin notifications
      maxToasts: 0,
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

    // Track shown notifications to prevent duplicates
    this.shownNotificationIds = new Set();
    this.lastCheckTimestamp = null;

    this.init();
  }

  init() {
    this.loadShownNotifications();
    this.createElements();
    this.bindEvents();
    this.startPolling();

    // Check immediately on load
    this.checkForNotifications();

    console.log("Admin notification system initialized");
  }

  // Load previously shown notifications from localStorage
  loadShownNotifications() {
    try {
      const stored = localStorage.getItem("admin_notification_shown_ids");
      if (stored) {
        const ids = JSON.parse(stored);
        // Only keep IDs from the last 24 hours to prevent unlimited growth
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

  // Save shown notification IDs to localStorage
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
    document.body.appendChild(this.toastContainer);

    // Find notification bell in header
    this.bellElement = document.querySelector(".notification-bell");
    if (!this.bellElement) {
      console.warn("Notification bell not found in header");
      return;
    }

    // Find or create badge
    this.badgeElement = this.bellElement.querySelector(".notification-badge");
    if (!this.badgeElement) {
      this.badgeElement = document.createElement("span");
      this.badgeElement.className = "notification-badge hidden";
      this.bellElement.appendChild(this.badgeElement);
    }

    // Find notification panel
    this.panelElement = this.bellElement.querySelector(".notification-panel");
    if (!this.panelElement) {
      console.warn("Notification panel not found");
      return;
    }

    // Bind mark all read button
    const markAllReadBtn = this.panelElement.querySelector(".mark-all-read");
    if (markAllReadBtn) {
      markAllReadBtn.addEventListener("click", () => {
        this.markAllAsRead();
      });
    }
  }

  bindEvents() {
    if (!this.bellElement) return;

    this.bellElement.addEventListener("click", (e) => {
      e.stopPropagation();
      this.togglePanel();
    });

    this.bellElement.addEventListener("keydown", (e) => {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        this.togglePanel();
      }
    });

    document.addEventListener("click", (e) => {
      if (
        this.isOpen &&
        this.panelElement &&
        !this.panelElement.contains(e.target) &&
        !this.bellElement.contains(e.target)
      ) {
        this.closePanel();
      }
    });

    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && this.isOpen) {
        this.closePanel();
        this.bellElement.focus();
      }
    });

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
      // Add timestamp to prevent caching and get only new notifications
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
      // Retry after 60 seconds on error
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

    // Filter out notifications we've already shown as toasts
    const reallyNewNotifications = newNotifications.filter((n) => {
      return !previousIds.has(n.id) && !this.shownNotificationIds.has(n.id);
    });

    // Update notifications array
    this.notifications = newNotifications;
    this.unreadCount = newNotifications.length;

    // Update badge
    this.updateBadge();

    // Update panel if open
    if (this.isOpen) {
      this.renderNotifications();
    }

    // Show toasts for truly new notifications only
    if (reallyNewNotifications.length > 0) {
      console.log(
        `Showing ${reallyNewNotifications.length} new admin notifications`
      );

      // Mark these notifications as shown
      reallyNewNotifications.forEach((n) => {
        this.shownNotificationIds.add(n.id);
      });
      this.saveShownNotifications();

      // Ring the bell with admin-specific animation
      if (this.bellElement) {
        this.bellElement.classList.add("has-new");
        setTimeout(() => {
          this.bellElement.classList.remove("has-new");
        }, 1000);
      }

      // Show toast notifications for new items (limit to prevent spam)
      // Prioritize critical and high priority notifications
      const sortedNewNotifications = reallyNewNotifications.sort((a, b) => {
        const priorityOrder = { critical: 1, high: 2, medium: 3, low: 4 };
        return priorityOrder[a.priority] - priorityOrder[b.priority];
      });

      sortedNewNotifications
        .slice(0, this.options.maxToasts)
        .forEach((notification, index) => {
          setTimeout(() => {
            this.showToast(notification);
          }, index * 400); // Slightly longer delay for admin notifications
        });

      // Play notification sound (different for critical notifications)
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
      this.badgeElement.classList.remove("hidden");

      // Add urgency indicator for critical notifications
      const hasCritical = this.notifications.some(
        (n) => n.priority === "critical"
      );
      if (hasCritical) {
        this.badgeElement.classList.add("critical");
      } else {
        this.badgeElement.classList.remove("critical");
      }
    } else {
      this.badgeElement.classList.add("hidden");
      this.badgeElement.classList.remove("critical");
    }

    if (this.panelElement) {
      const countElement = this.panelElement.querySelector(
        ".notification-count"
      );
      if (countElement) {
        countElement.textContent = this.unreadCount;
      }
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
    if (!this.panelElement || !this.bellElement) return;

    this.isOpen = true;
    this.panelElement.classList.add("active");
    this.panelElement.setAttribute("aria-hidden", "false");
    this.bellElement.setAttribute("aria-expanded", "true");

    this.renderNotifications();

    setTimeout(() => {
      const firstItem = this.panelElement.querySelector(".notification-item");
      if (firstItem) {
        firstItem.focus();
      }
    }, 100);
  }

  closePanel() {
    if (!this.panelElement || !this.bellElement) return;

    this.isOpen = false;
    this.panelElement.classList.remove("active");
    this.panelElement.setAttribute("aria-hidden", "true");
    this.bellElement.setAttribute("aria-expanded", "false");
  }

  renderNotifications() {
    if (!this.panelElement) return;

    const listContainer = this.panelElement.querySelector(".notification-list");

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
                            <div class="notification-title">${this.escapeHtml(
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

      item.addEventListener("keydown", (e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          if (!e.target.closest(".notification-actions")) {
            this.handleNotificationClick(item);
          }
        }
      });
    });

    // Bind mark as read buttons
    listContainer.querySelectorAll(".mark-read-btn").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        e.stopPropagation();
        const notificationId = btn.dataset.id;
        this.markAsRead(notificationId);
      });
    });
  }

  // Enhanced notification panel click handler
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

  // Enhanced showToast with proper routing
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

    // Determine the action URL and button text based on notification type
    const actionConfig = this.getNotificationActionConfig(notification);

    toast.innerHTML = `
            <div class="toast-priority ${priorityClass}"></div>
            <div class="toast-icon">
                <i class="${notification.icon}"></i>
            </div>
            <div class="toast-content">
                <div class="toast-title">${this.escapeHtml(
                  notification.title
                )}</div>
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

    // Auto-hide after duration (longer for critical notifications)
    const duration =
      notification.priority === "critical"
        ? this.options.toastDuration * 2
        : this.options.toastDuration;

    const hideTimeout = setTimeout(() => {
      this.hideToast(toast);
    }, duration);

    // Enhanced action button click with proper routing
    toast.querySelector(".toast-action").addEventListener("click", (e) => {
      e.stopPropagation();
      clearTimeout(hideTimeout);
      this.hideToast(toast);

      // Navigate to the appropriate page
      this.navigateToNotificationPage(notification, actionConfig.url);
    });

    // Close button click
    toast.querySelector(".toast-close").addEventListener("click", () => {
      clearTimeout(hideTimeout);
      this.hideToast(toast);

      // Mark as read when closed
      this.markAsRead(notification.id);
    });

    // Toast click (excluding buttons) - also navigate
    toast.addEventListener("click", (e) => {
      if (e.target.closest(".toast-actions")) return;

      clearTimeout(hideTimeout);
      this.hideToast(toast);

      // Navigate to the appropriate page
      this.navigateToNotificationPage(notification, actionConfig.url);
    });
  }

  // New method to determine action configuration based on notification type
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

  // Enhanced navigation method with proper URL handling
  navigateToNotificationPage(notification, targetUrl) {
    // Mark notification as read
    this.markAsRead(notification.id);

    // Handle different URL types
    if (!targetUrl || targetUrl === "#") {
      console.warn("No valid URL for notification:", notification);
      return;
    }

    // Since your admin files are in the admin directory,
    // ensure the URL includes the admin path if not already present
    if (
      !targetUrl.startsWith("http") &&
      !targetUrl.startsWith("/") &&
      !targetUrl.includes("admin/")
    ) {
      targetUrl = "admin/" + targetUrl;
    }

    // Handle special notification parameters
    targetUrl = this.addNotificationParameters(notification, targetUrl);

    // Navigate with a small delay
    setTimeout(() => {
      window.location.href = targetUrl;
    }, 150);
  }

  // Add notification-specific parameters to URLs
  addNotificationParameters(notification, url) {
    const urlObj = new URL(url, window.location.origin);

    // Add notification ID for tracking
    urlObj.searchParams.set("notification_id", notification.id);

    // Add notification-specific parameters
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
        // Remove from current notifications
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
      // Create audio context if needed
      if (!this.audioContext) {
        this.audioContext = new (window.AudioContext ||
          window.webkitAudioContext)();
      }

      // Play different sound for critical notifications
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
    // Play a more urgent sound pattern for critical notifications
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
      // Less than 1 minute
      return "Just now";
    } else if (diff < 3600000) {
      // Less than 1 hour
      const minutes = Math.floor(diff / 60000);
      return `${minutes}m ago`;
    } else if (diff < 86400000) {
      // Less than 1 day
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

  // Public method to manually refresh notifications
  refresh() {
    this.checkForNotifications();
  }

  // Public method to enable/disable sounds
  setSoundEnabled(enabled) {
    this.options.soundEnabled = enabled;
    localStorage.setItem("admin_notifications_sound", enabled.toString());
  }

  // Public method to get current notification count
  getUnreadCount() {
    return this.unreadCount;
  }

  // Cleanup method
  destroy() {
    this.stopPolling();

    if (this.toastContainer && this.toastContainer.parentNode) {
      this.toastContainer.parentNode.removeChild(this.toastContainer);
    }

    if (this.audioContext) {
      this.audioContext.close();
    }
  }

  // Page-specific highlighting functionality
  static highlightPageElement(type, id) {
    // This should be called on the target page to highlight specific elements
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
        // Scroll to element
        targetElement.scrollIntoView({
          behavior: "smooth",
          block: "center",
        });

        // Add highlight effect
        targetElement.classList.add("notification-highlight");

        // Remove highlight after 3 seconds
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

  // Check for highlight parameters
  for (const [key, value] of urlParams) {
    if (key.startsWith("highlight_")) {
      AdminNotificationSystem.highlightPageElement(key, value);
    }
  }

  // Mark notification as handled if notification_id is present
  const notificationId = urlParams.get("notification_id");
  if (notificationId && window.adminNotifications) {
    window.adminNotifications.markAsRead(notificationId);
  }
}

// CSS styles for notification toasts and items + highlighting
const notificationStyles = `
<style>
/* Admin Notification Toast Styles */
.toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 2000;
    pointer-events: none;
}

.toast-notification {
    display: flex;
    align-items: stretch;
    background: white;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    margin-bottom: 10px;
    min-width: 320px;
    max-width: 400px;
    pointer-events: auto;
    opacity: 0;
    transform: translateX(100%);
    transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    border-left: 4px solid #ddd;
    overflow: hidden;
}

.toast-notification.show {
    opacity: 1;
    transform: translateX(0);
}

.toast-notification.hide {
    opacity: 0;
    transform: translateX(100%);
    transition: all 0.3s ease-in;
}

.toast-notification.admin-toast {
    border-left-width: 6px;
}

.toast-notification.critical {
    border-left-color: #dc3545;
    animation: criticalPulse 2s infinite;
}

.toast-notification.high {
    border-left-color: #fd7e14;
}

.toast-notification.medium {
    border-left-color: #ffc107;
}

.toast-notification.low {
    border-left-color: #28a745;
}

@keyframes criticalPulse {
    0%, 100% { box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15); }
    50% { box-shadow: 0 4px 20px rgba(220, 53, 69, 0.4); }
}

.toast-priority {
    width: 6px;
    flex-shrink: 0;
}

.toast-priority.critical {
    background: linear-gradient(180deg, #dc3545, #c82333);
}

.toast-priority.high {
    background: linear-gradient(180deg, #fd7e14, #e8680a);
}

.toast-priority.medium {
    background: linear-gradient(180deg, #ffc107, #e0a800);
}

.toast-priority.low {
    background: linear-gradient(180deg, #28a745, #1e7e34);
}

.toast-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 15px;
    font-size: 1.25rem;
    color: #666;
    background: #f8f9fa;
    flex-shrink: 0;
}

.toast-content {
    flex: 1;
    padding: 15px;
}

.toast-title {
    font-weight: 600;
    color: #333;
    margin-bottom: 5px;
    font-size: 0.9rem;
}

.toast-message {
    color: #666;
    font-size: 0.85rem;
    line-height: 1.4;
    margin-bottom: 8px;
}

.toast-priority-label {
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #999;
}

.toast-actions {
    display: flex;
    flex-direction: column;
    padding: 10px;
    gap: 5px;
    background: #f8f9fa;
    border-left: 1px solid #e9ecef;
}

.toast-action,
.toast-close {
    background: none;
    border: none;
    cursor: pointer;
    padding: 8px;
    border-radius: 4px;
    color: #666;
    transition: all 0.2s ease;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

.toast-action:hover {
    background: #007bff;
    color: white;
}

.toast-close:hover {
    background: #dc3545;
    color: white;
}

/* Notification Panel Items */
.notification-item {
    display: flex;
    align-items: stretch;
    padding: 0;
    border-bottom: 1px solid #f0f0f0;
    cursor: pointer;
    transition: background-color 0.2s ease;
    position: relative;
    overflow: hidden;
}

.notification-item:hover {
    background-color: #f8f9fa;
}

.notification-item.unread {
    background-color: #fff5f5;
}

.notification-item.critical {
    background-color: #ffebee;
    border-left: 4px solid #dc3545;
}

.notification-item.high {
    background-color: #fff8e1;
    border-left: 4px solid #fd7e14;
}

.notification-item.medium {
    background-color: #fffde7;
    border-left: 4px solid #ffc107;
}

.notification-item.low {
    background-color: #f1f8e9;
    border-left: 4px solid #28a745;
}

.notification-priority {
    width: 4px;
    flex-shrink: 0;
}

.notification-priority.critical {
    background: #dc3545;
}

.notification-priority.high {
    background: #fd7e14;
}

.notification-priority.medium {
    background: #ffc107;
}

.notification-priority.low {
    background: #28a745;
}

.notification-content {
    display: flex;
    align-items: center;
    flex: 1;
    padding: 12px;
    gap: 12px;
}

.notification-icon {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
    background: #f8f9fa;
    color: #666;
}

.notification-icon.critical {
    background: #ffebee;
    color: #dc3545;
}

.notification-icon.inventory {
    background: #e3f2fd;
    color: #1976d2;
}

.notification-icon.donation {
    background: #fce4ec;
    color: #c2185b;
}

.notification-icon.training {
    background: #f3e5f5;
    color: #7b1fa2;
}

.notification-icon.announcement {
    background: #fff3e0;
    color: #f57c00;
}

.notification-icon.new_user {
    background: #e8f5e8;
    color: #2e7d32;
}

.notification-text {
    flex: 1;
    min-width: 0;
}

.notification-title {
    font-weight: 600;
    color: #333;
    margin-bottom: 4px;
    font-size: 0.9rem;
    line-height: 1.3;
}

.notification-message {
    color: #666;
    font-size: 0.85rem;
    line-height: 1.4;
    margin-bottom: 6px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.notification-time {
    font-size: 0.75rem;
    color: #999;
    display: flex;
    align-items: center;
    gap: 4px;
}

.notification-actions {
    display: flex;
    align-items: center;
    padding: 12px;
}

.mark-read-btn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 6px;
    border-radius: 4px;
    color: #666;
    transition: all 0.2s ease;
    font-size: 0.9rem;
}

.mark-read-btn:hover {
    background: #28a745;
    color: white;
}

.notification-empty {
    padding: 40px 20px;
    text-align: center;
    color: #666;
}

.notification-empty i {
    font-size: 3rem;
    color: #28a745;
    margin-bottom: 15px;
    display: block;
}

.notification-empty h4 {
    margin: 0 0 10px 0;
    color: #333;
    font-size: 1.1rem;
}

.notification-empty p {
    margin: 0;
    font-size: 0.9rem;
    color: #666;
}

.notification-badge.critical {
    background: linear-gradient(45deg, #dc3545, #c82333);
    animation: criticalBadgePulse 1s infinite;
}

@keyframes criticalBadgePulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.2); }
}

.notification-bell.has-new {
    animation: bellRingUrgent 0.6s ease-in-out;
}

@keyframes bellRingUrgent {
    0%, 100% { transform: rotate(0deg); }
    10%, 30%, 50%, 70%, 90% { transform: rotate(-15deg); }
    20%, 40%, 60%, 80% { transform: rotate(15deg); }
}

/* Notification highlighting styles */
.notification-highlight {
    background: linear-gradient(90deg, #fff3cd, #ffeaa7, #fff3cd) !important;
    border: 2px solid #ffc107 !important;
    border-radius: 8px !important;
    animation: notificationGlow 2s ease-in-out;
    transition: all 0.3s ease !important;
    transform: scale(1.02) !important;
    box-shadow: 0 4px 20px rgba(255, 193, 7, 0.3) !important;
}

@keyframes notificationGlow {
    0%, 100% { 
        box-shadow: 0 4px 20px rgba(255, 193, 7, 0.3);
    }
    50% { 
        box-shadow: 0 6px 30px rgba(255, 193, 7, 0.6);
        transform: scale(1.03);
    }
}

/* Smooth transition back to normal */
.notification-highlight.remove {
    animation: notificationFadeOut 0.5s ease-out forwards;
}

@keyframes notificationFadeOut {
    to {
        background: initial !important;
        border: initial !important;
        transform: scale(1) !important;
        box-shadow: initial !important;
    }
}

/* Special highlighting for different element types */
.notification-highlight.user-row {
    background: linear-gradient(90deg, #e8f5e8, #c8e6c9, #e8f5e8) !important;
    border-color: #4caf50 !important;
}

.notification-highlight.inventory-item {
    background: linear-gradient(90deg, #fff3e0, #ffcc80, #fff3e0) !important;
    border-color: #ff9800 !important;
}

.notification-highlight.donation-item {
    background: linear-gradient(90deg, #fce4ec, #f8bbd9, #fce4ec) !important;
    border-color: #e91e63 !important;
}

.notification-highlight.critical-item {
    background: linear-gradient(90deg, #ffebee, #ffcdd2, #ffebee) !important;
    border-color: #f44336 !important;
    animation: criticalItemPulse 1s infinite;
}

@keyframes criticalItemPulse {
    0%, 100% { 
        box-shadow: 0 4px 20px rgba(244, 67, 54, 0.3);
    }
    50% { 
        box-shadow: 0 6px 30px rgba(244, 67, 54, 0.6);
    }
}

/* Responsive adjustments */
@media (max-width: 480px) {
    .toast-container {
        right: 10px;
        left: 10px;
        top: 10px;
    }
    
    .toast-notification {
        min-width: auto;
        max-width: none;
    }
    
    .notification-panel {
        width: calc(100vw - 20px);
        right: 10px;
    }
}
</style>
`;

// Inject styles into the document
if (typeof document !== "undefined") {
  document.head.insertAdjacentHTML("beforeend", notificationStyles);
}

// Initialize the admin notification system when DOM is ready
if (typeof document !== "undefined") {
  // Initialize page highlighting
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
