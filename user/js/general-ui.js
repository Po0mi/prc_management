(() => {
  const SIDEBAR_COLLAPSED_KEY = "sidebarCollapsed";

  function findElements() {
    const collapseBtn = document.querySelector(".collapse-btn");
    const sidebar = document.querySelector(".sidebar");
    const mainContent =
      document.querySelector(".main-content") ||
      document.querySelector(".dashboard-content") ||
      document.querySelector(".donate-content") ||
      document.querySelector(".announcements-content") ||
      document.querySelector(".blood-map-container") ||
      document.querySelector(".sessions-container") || // Changed to match your container
      document.querySelector(".admin-content") ||
      document.body;
    return { collapseBtn, sidebar, mainContent };
  }

  function applyCollapsedStyles(sidebar, mainContent, collapsed) {
    if (!sidebar || !mainContent) return;

    // Set CSS variable based on collapsed state
    document.documentElement.style.setProperty(
      "--sidebar-width",
      collapsed ? "70px" : "250px"
    );

    // Update sidebar classes and ARIA
    if (collapsed) {
      sidebar.classList.add("collapsed");
      sidebar.setAttribute("aria-expanded", "false");
    } else {
      sidebar.classList.remove("collapsed");
      sidebar.setAttribute("aria-expanded", "true");
    }

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

  function initUI() {
    console.log("[general-ui] initUI running");
    let { collapseBtn, sidebar, mainContent } = findElements();
    console.log("[general-ui] found:", { collapseBtn, sidebar, mainContent });

    if (!sidebar || !collapseBtn) {
      const ob = new MutationObserver((mutations, o) => {
        ({ collapseBtn, sidebar, mainContent } = findElements());
        if (sidebar && collapseBtn) {
          console.log(
            "[general-ui] found missing elements via MutationObserver"
          );
          o.disconnect();
          attachHandlers(sidebar, collapseBtn, mainContent);
        }
      });
      ob.observe(document.body, { childList: true, subtree: true });
      if (sidebar && collapseBtn) {
        attachHandlers(sidebar, collapseBtn, mainContent);
      }
    } else {
      attachHandlers(sidebar, collapseBtn, mainContent);
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

    const saved = localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === "true";
    applyCollapsedStyles(sidebar, mainContent, saved);
    console.log("[general-ui] restored collapsed state:", saved);

    if (!collapseBtn._hasGeneralUiHandler) {
      collapseBtn.addEventListener("click", (e) => {
        e.preventDefault();
        const collapsed = !sidebar.classList.contains("collapsed");
        applyCollapsedStyles(sidebar, mainContent, collapsed);
        localStorage.setItem(SIDEBAR_COLLAPSED_KEY, collapsed);
        console.log(
          "[general-ui] collapse button clicked. collapsed =",
          collapsed
        );
      });
      collapseBtn._hasGeneralUiHandler = true;
      console.log("[general-ui] click handler attached to collapse button");
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initUI);
  } else {
    initUI();
  }
})();
