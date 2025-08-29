// Notification and Message functionality
let currentModal = null;

// Open notifications modal
function openNotifications() {
  const modal = document.getElementById("notificationModal");
  const body = document.getElementById("notificationBody");

  modal.style.display = "block";
  currentModal = modal;

  // Load notifications
  loadNotifications();

  // Add click outside to close
  modal.onclick = function (event) {
    if (event.target === modal) {
      closeModal("notificationModal");
    }
  };
}

// Open messages modal
function openMessages() {
  const modal = document.getElementById("messagesModal");
  const body = document.getElementById("messagesBody");

  modal.style.display = "block";
  currentModal = modal;

  // Load messages
  loadMessages();

  // Add click outside to close
  modal.onclick = function (event) {
    if (event.target === modal) {
      closeModal("messagesModal");
    }
  };
}

// Close modal
function closeModal(modalId) {
  const modal = document.getElementById(modalId);
  modal.style.display = "none";
  currentModal = null;
}

// Load notifications from server
async function loadNotifications() {
  const body = document.getElementById("notificationBody");

  try {
    const response = await fetch("api.php?action=get_notifications");
    const data = await response.json();

    if (data.success) {
      displayNotifications(data.notifications);
    } else {
      body.innerHTML =
        '<div class="empty-state"><i class="fas fa-bell-slash"></i><h3>No notifications</h3><p>You\'re all caught up! No new notifications at this time.</p></div>';
    }
  } catch (error) {
    console.error("Error loading notifications:", error);
    body.innerHTML =
      '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><h3>Error loading notifications</h3><p>Please try again later.</p></div>';
  }
}

// Load messages from server
async function loadMessages() {
  const body = document.getElementById("messagesBody");

  try {
    const response = await fetch("api.php?action=get_messages");
    const data = await response.json();

    if (data.success) {
      displayMessages(data.messages);
    } else {
      body.innerHTML =
        '<div class="empty-state"><i class="fas fa-envelope-open"></i><h3>No messages</h3><p>Your inbox is empty. No new messages at this time.</p></div>';
    }
  } catch (error) {
    console.error("Error loading messages:", error);
    body.innerHTML =
      '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><h3>Error loading messages</h3><p>Please try again later.</p></div>';
  }
}

// Display notifications
function displayNotifications(notifications) {
  const body = document.getElementById("notificationBody");

  if (notifications.length === 0) {
    body.innerHTML = `
      <div class="empty-state">
        <i class="fas fa-bell-slash"></i>
        <h3>No notifications</h3>
        <p>You're all caught up! No new notifications at this time.</p>
      </div>
    `;
    return;
  }

  const html = notifications
    .map(
      (notification) => `
    <div class="notification-item ${
      notification.is_read === "0" ? "unread" : ""
    }" 
         onclick="markAsRead('notification', ${notification.id}, this)">
      <div class="notification-title">${escapeHtml(notification.title)}</div>
      <div class="notification-content">${escapeHtml(
        notification.content
      )}</div>
      <div class="notification-time">${formatTime(
        notification.created_at
      )}</div>
    </div>
  `
    )
    .join("");

  body.innerHTML = html;
}

// Display messages
function displayMessages(messages) {
  const body = document.getElementById("messagesBody");

  if (messages.length === 0) {
    body.innerHTML = `
      <div class="empty-state">
        <i class="fas fa-envelope-open"></i>
        <h3>No messages</h3>
        <p>Your inbox is empty. No new messages at this time.</p>
      </div>
    `;
    return;
  }

  const html = messages
    .map(
      (message) => `
    <div class="message-item ${message.is_read === "0" ? "unread" : ""}" 
         onclick="markAsRead('message', ${message.id}, this)">
      <div class="message-subject">${escapeHtml(message.subject)}</div>
      <div class="message-preview">${escapeHtml(
        message.content.substring(0, 100)
      )}${message.content.length > 100 ? "..." : ""}</div>
      <div class="message-time">From: ${escapeHtml(
        message.sender_name
      )} • ${formatTime(message.created_at)}</div>
    </div>
  `
    )
    .join("");

  body.innerHTML = html;
}

// Mark notification/message as read
async function markAsRead(type, id, element) {
  try {
    const response = await fetch("api.php?action=mark_as_read", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ type, id }),
    });

    const data = await response.json();

    if (data.success) {
      // Update the UI
      if (element) {
        element.classList.remove("unread");
      }

      // Update badge counts
      updateBadgeCounts();
    }
  } catch (error) {
    console.error("Error marking as read:", error);
    // Still update UI for demo purposes
    if (element) {
      element.classList.remove("unread");
    }
    updateBadgeCounts();
  }
}

// Update badge counts
function updateBadgeCounts() {
  fetch("api.php?action=get_counts")
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        updateBadge(".notification-btn", data.notifications);
        updateBadge(".messages-btn", data.messages);
      }
    })
    .catch((error) => {
      console.error("Error updating counts:", error);
      // Simulate count update for demo
      const notifBadge = document.querySelector(
        ".notification-btn .badge-count"
      );
      const msgBadge = document.querySelector(".messages-btn .badge-count");

      if (notifBadge) {
        let count = parseInt(notifBadge.textContent) - 1;
        if (count <= 0) {
          notifBadge.remove();
        } else {
          notifBadge.textContent = count;
        }
      }
    });
}

// Update individual badge
function updateBadge(selector, count) {
  const button = document.querySelector(selector);
  let badge = button.querySelector(".badge-count");

  if (count > 0) {
    if (!badge) {
      badge = document.createElement("span");
      badge.className = "badge-count";
      button.appendChild(badge);
    }
    badge.textContent = count;
  } else {
    if (badge) {
      badge.remove();
    }
  }
}

// Utility functions
function escapeHtml(text) {
  const map = {
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#039;",
  };
  return text.replace(/[&<>"']/g, function (m) {
    return map[m];
  });
}

function formatTime(timestamp) {
  const date = new Date(timestamp);
  const now = new Date();
  const diff = now.getTime() - date.getTime();

  // Less than a minute
  if (diff < 60000) {
    return "Just now";
  }

  // Less than an hour
  if (diff < 3600000) {
    const minutes = Math.floor(diff / 60000);
    return `${minutes} minute${minutes > 1 ? "s" : ""} ago`;
  }

  // Less than a day
  if (diff < 86400000) {
    const hours = Math.floor(diff / 3600000);
    return `${hours} hour${hours > 1 ? "s" : ""} ago`;
  }

  // Less than a week
  if (diff < 604800000) {
    const days = Math.floor(diff / 86400000);
    return `${days} day${days > 1 ? "s" : ""} ago`;
  }

  // Show actual date
  return date.toLocaleDateString();
}

// Close modal on Escape key
document.addEventListener("keydown", function (event) {
  if (event.key === "Escape" && currentModal) {
    currentModal.style.display = "none";
    currentModal = null;
  }
});

// Auto-refresh counts every 30 seconds
setInterval(updateBadgeCounts, 30000);

// Initialize badge counts on page load
document.addEventListener("DOMContentLoaded", function () {
  // Set initial counts from PHP
  if (typeof notificationCount !== "undefined") {
    updateBadge(".notification-btn", notificationCount);
  }
  if (typeof messageCount !== "undefined") {
    updateBadge(".messages-btn", messageCount);
  }
});

// Add notification sound effect
function playNotificationSound() {
  try {
    const audioContext = new (window.AudioContext ||
      window.webkitAudioContext)();
    const oscillator = audioContext.createOscillator();
    const gainNode = audioContext.createGain();

    oscillator.connect(gainNode);
    gainNode.connect(audioContext.destination);

    oscillator.frequency.value = 800;
    oscillator.type = "sine";

    gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
    gainNode.gain.exponentialRampToValueAtTime(
      0.01,
      audioContext.currentTime + 0.5
    );

    oscillator.start(audioContext.currentTime);
    oscillator.stop(audioContext.currentTime + 0.5);
  } catch (error) {
    console.log("Audio not supported or blocked");
  }
}

// Check for new notifications from admin (called periodically)
function checkForNewNotifications() {
  fetch("api.php?action=get_counts")
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        const currentNotifCount = document.querySelector(
          ".notification-btn .badge-count"
        );
        const currentCount = currentNotifCount
          ? parseInt(currentNotifCount.textContent)
          : 0;

        // If count increased, show toast and play sound
        if (data.notifications > currentCount) {
          playNotificationSound();
          showNewNotificationToast();
        }

        updateBadge(".notification-btn", data.notifications);
        updateBadge(".messages-btn", data.messages);
      }
    })
    .catch((error) => console.error("Error checking notifications:", error));
}

// Show toast when new notification arrives from admin
function showNewNotificationToast() {
  const toast = document.createElement("div");
  toast.className = "toast-notification";
  toast.innerHTML = `
    <div class="toast-icon">
      <i class="fas fa-bell"></i>
    </div>
    <div class="toast-content">
      <h4>New Notification</h4>
      <p>You have a new notification from the admin</p>
    </div>
    <button class="toast-close" onclick="this.parentElement.remove()">
      <i class="fas fa-times"></i>
    </button>
  `;

  // Add styles if not exist
  if (!document.getElementById("toast-styles")) {
    const toastStyles = document.createElement("style");
    toastStyles.id = "toast-styles";
    toastStyles.textContent = `
      .toast-notification {
        position: fixed;
        top: 100px;
        right: 20px;
        background: var(--card-bg);
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        z-index: 1002;
        min-width: 300px;
        max-width: 400px;
        animation: slideInRight 0.3s ease;
      }
      
      @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
      }
      
      .toast-icon {
        color: var(--prc-red);
        font-size: 1.2rem;
      }
      
      .toast-content h4 {
        margin: 0 0 5px 0;
        color: var(--text-color);
        font-size: 14px;
      }
      
      .toast-content p {
        margin: 0;
        color: var(--text-color);
        opacity: 0.8;
        font-size: 12px;
      }
      
      .toast-close {
        background: none;
        border: none;
        color: #999;
        cursor: pointer;
        padding: 5px;
        margin-left: auto;
      }
      
      .dark-mode .toast-notification {
        border-color: #444;
      }
    `;
    document.head.appendChild(toastStyles);
  }

  document.body.appendChild(toast);

  // Auto remove after 5 seconds
  setTimeout(() => {
    if (toast.parentElement) {
      toast.style.animation = "slideInRight 0.3s ease reverse";
      setTimeout(() => toast.remove(), 300);
    }
  }, 5000);
}

// Check for new notifications every 15 seconds
setInterval(checkForNewNotifications, 15000);

// Auto-refresh counts every 30 seconds (notifications come from admin only)
setInterval(updateBadgeCounts, 30000);
