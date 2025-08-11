document.addEventListener("DOMContentLoaded", function () {
  const darkModeToggle = document.getElementById("darkModeToggle");
  const icon = darkModeToggle.querySelector("i");

  // Check for saved user preference or system preference
  const prefersDarkScheme = window.matchMedia("(prefers-color-scheme: dark)");
  const currentTheme = localStorage.getItem("theme");

  if (currentTheme === "dark" || (!currentTheme && prefersDarkScheme.matches)) {
    document.body.classList.add("dark-mode");
    icon.classList.replace("fa-moon", "fa-sun");
  }

  // Toggle dark mode
  darkModeToggle.addEventListener("click", function () {
    const isDarkMode = document.body.classList.toggle("dark-mode");

    if (isDarkMode) {
      icon.classList.replace("fa-moon", "fa-sun");
      localStorage.setItem("theme", "dark");
    } else {
      icon.classList.replace("fa-sun", "fa-moon");
      localStorage.setItem("theme", "light");
    }
  });

  // Watch for system preference changes
  prefersDarkScheme.addEventListener("change", (e) => {
    if (!localStorage.getItem("theme")) {
      if (e.matches) {
        document.body.classList.add("dark-mode");
        icon.classList.replace("fa-moon", "fa-sun");
      } else {
        document.body.classList.remove("dark-mode");
        icon.classList.replace("fa-sun", "fa-moon");
      }
    }
  });
});
