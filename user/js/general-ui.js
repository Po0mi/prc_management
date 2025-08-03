function initializeGeneralUI() {

  const badges = document.querySelectorAll('.badge');
  
  badges.forEach(badge => {
    badge.addEventListener('animationend', () => {
      badge.style.animation = '';
    });
    
    setTimeout(() => {
      badge.style.animation = 'pulse 1.5s';
    }, 1000);
  });
  

  const sidebarToggle = document.createElement('button');
  sidebarToggle.className = 'sidebar-toggle';
  sidebarToggle.innerHTML = '<i class="fas fa-bars"></i>';
  sidebarToggle.addEventListener('click', () => {
    document.querySelector('.sidebar').classList.toggle('collapsed');
  });
  
  document.querySelector('.user-actions').prepend(sidebarToggle);
  
  
  const currentPage = location.pathname.split('/').pop();
  document.querySelectorAll('.nav-link').forEach(link => {
    link.classList.remove('active');
    if (link.getAttribute('href') === currentPage) {
      link.classList.add('active');
    }
  });
}


document.addEventListener("DOMContentLoaded", initializeGeneralUI);