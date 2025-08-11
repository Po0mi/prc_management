// user/js/sidebar.js
document.addEventListener("DOMContentLoaded", function () {
  const dropdownNavs = document.querySelectorAll(".dropdown-nav");

  dropdownNavs.forEach((nav) => {
    nav.addEventListener("click", function () {
      this.classList.toggle("active");
    });
  });
});
