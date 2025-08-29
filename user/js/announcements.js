function initializeAnnouncementsPage() {
  
  document.querySelectorAll('.nav-link').forEach(link => {
    link.classList.remove('active');
    if (link.querySelector('span').textContent === 'Announcements') {
      link.classList.add('active');
    }
  });
  
  
  const importantBtns = document.querySelectorAll('.action-btn.important');
  importantBtns.forEach(btn => {
    btn.addEventListener('click', function() {
      this.classList.toggle('active');
      if (this.classList.contains('active')) {
        this.innerHTML = '<i class="fas fa-check-circle"></i> Marked';
      } else {
        this.innerHTML = '<i class="fas fa-exclamation-circle"></i> Important';
      }
    });
  });
  
 
  const searchInput = document.querySelector('.search-bar input');
  const searchBtn = document.querySelector('.search-bar button');
  
  if (searchBtn) {
    searchBtn.addEventListener('click', function() {
      const searchTerm = searchInput.value.toLowerCase();
      const cards = document.querySelectorAll('.announcement-card');
      
      cards.forEach(card => {
        const title = card.querySelector('.announcement-title').textContent.toLowerCase();
        const content = card.querySelector('.announcement-body').textContent.toLowerCase();
        
        if (title.includes(searchTerm) || content.includes(searchTerm)) {
          card.style.display = 'block';
        } else {
          card.style.display = 'none';
        }
      });
    });
  }
  
  
  const categoryFilter = document.querySelector('.filter-controls select');
  if (categoryFilter) {
    categoryFilter.addEventListener('change', function() {
      const selectedCategory = this.value;
      
      console.log('Filter by:', selectedCategory);
    });
  }
}

document.addEventListener("DOMContentLoaded", function() {
  if (document.querySelector('.announcement-card')) {
    initializeAnnouncementsPage();
  }
});