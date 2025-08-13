(() => {
  const SIDEBAR_COLLAPSED_KEY = "sidebarCollapsed";

  // Set CSS variables immediately
  document.documentElement.style.setProperty("--sidebar-width", "250px");
  document.documentElement.style.setProperty(
    "--sidebar-collapsed-width",
    "70px"
  );

  function findElements() {
    const collapseBtn = document.querySelector(".collapse-btn");
    const sidebar = document.querySelector(".sidebar");
    const mainContent =
      document.querySelector(".main-content") ||
      document.querySelector(".dashboard-content") ||
      document.querySelector(".donate-content") ||
      document.querySelector(".announcements-content") ||
      document.querySelector(".blood-map-container") ||
      document.querySelector(".sessions-container") ||
      document.querySelector(".admin-content") ||
      document.querySelector(".users-container") ||
      document.body;
    return { collapseBtn, sidebar, mainContent };
  }

  function applyCollapsedStyles(sidebar, mainContent, collapsed) {
    if (!sidebar || !mainContent) return;

    // Temporarily disable transitions
    sidebar.style.transition = "none";

    // Update CSS variable
    document.documentElement.style.setProperty(
      "--sidebar-width",
      collapsed ? "70px" : "250px"
    );

    // Update classes
    if (collapsed) {
      sidebar.classList.add("collapsed");
      sidebar.setAttribute("aria-expanded", "false");
    } else {
      sidebar.classList.remove("collapsed");
      sidebar.setAttribute("aria-expanded", "true");
    }

    // Force reflow before re-enabling transitions
    void sidebar.offsetWidth;
    sidebar.style.transition = "";

    // Rotate chevron icon
    const iconChevron = sidebar.querySelector(
      ".collapse-btn i.fa-chevron-left"
    );
    if (iconChevron) {
      iconChevron.style.transform = collapsed
        ? "rotate(180deg)"
        : "rotate(0deg)";
    }
  }

  function attachHandlers(sidebar, collapseBtn, mainContent) {
    if (!sidebar || !collapseBtn || !mainContent) {
      console.warn("[general-ui] attachHandlers: missing elements", {
        sidebar,
        collapseBtn,
        mainContent,
      });
      return;
    }

    // Mark sidebar as initialized
    sidebar.classList.add("initialized");

    // Apply saved state
    const saved = localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === "true";
    applyCollapsedStyles(sidebar, mainContent, saved);

    if (!collapseBtn._hasGeneralUiHandler) {
      collapseBtn.addEventListener("click", (e) => {
        e.preventDefault();
        const collapsed = !sidebar.classList.contains("collapsed");
        applyCollapsedStyles(sidebar, mainContent, collapsed);
        localStorage.setItem(SIDEBAR_COLLAPSED_KEY, collapsed);
        // Set cookie for PHP to access
        document.cookie = `sidebarCollapsed=${collapsed}; path=/; max-age=${
          60 * 60 * 24 * 30
        }`;
      });
      collapseBtn._hasGeneralUiHandler = true;
    }
  }

  function initUI() {
    let { collapseBtn, sidebar, mainContent } = findElements();

    if (!sidebar || !collapseBtn) {
      const ob = new MutationObserver((mutations, o) => {
        ({ collapseBtn, sidebar, mainContent } = findElements());
        if (sidebar && collapseBtn) {
          o.disconnect();
          attachHandlers(sidebar, collapseBtn, mainContent);
        }
      });
      ob.observe(document.body, { childList: true, subtree: true });
    } else {
      attachHandlers(sidebar, collapseBtn, mainContent);
    }
  }

  // Initialize
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initUI);
  } else {
    initUI();
  }
})();
