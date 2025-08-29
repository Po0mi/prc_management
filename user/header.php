<?php
// Simple header without notifications functionality
?>

<header class="top-header">
  <div class="page-title">
    <h2>User Dashboard</h2>
  </div>
  
  <div class="user-actions">
    <button title="Toggle Dark Mode" id="darkModeToggle">
      <i class="fas fa-moon"></i>
    </button>
  </div>
</header>

<style>
/* Header Styles */
:root {
  --bg-color: #ffffff;
  --text-color: #333333;
  --header-bg: #f8f9fa;
  --prc-red: #a00000;
  --card-bg: white;
}

.dark-mode {
  --bg-color: #1a1a1a;
  --text-color: #f0f0f0;
  --header-bg: #2d2d2d;
  --card-bg: #2d2d2d;
}

body {
  background-color: var(--bg-color);
  color: var(--text-color);
  transition: background-color 0.3s, color 0.3s;
}

.top-header {
  background-color: var(--header-bg);
  padding: 1rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 1px solid #eee;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.dark-mode .top-header {
  border-bottom-color: #444;
}

.page-title h2 {
  margin: 0;
  color: var(--prc-red);
  font-size: 1.5rem;
  font-weight: 600;
}

.user-actions {
  display: flex;
  gap: 1rem;
  align-items: center;
}

.user-actions button {
  position: relative;
  background: none;
  border: none;
  cursor: pointer;
  font-size: 1.2rem;
  color: var(--text-color);
  padding: 0.5rem;
  border-radius: 50%;
  transition: all 0.3s ease;
  width: 40px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.user-actions button:hover {
  background-color: rgba(160, 0, 0, 0.1);
  color: var(--prc-red);
  transform: scale(1.05);
}

/* Dark mode toggle specific styles */
#darkModeToggle {
  background: linear-gradient(135deg, #ffd700, #ffed4e);
  color: #333;
  border: 2px solid transparent;
}

#darkModeToggle:hover {
  background: linear-gradient(135deg, #ffed4e, #ffd700);
  transform: scale(1.1) rotate(15deg);
}

.dark-mode #darkModeToggle {
  background: linear-gradient(135deg, #4a5568, #2d3748);
  color: #ffd700;
}

.dark-mode #darkModeToggle:hover {
  background: linear-gradient(135deg, #2d3748, #4a5568);
  color: #ffed4e;
}

/* Responsive Design */
@media (max-width: 768px) {
  .top-header {
    padding: 0.75rem 1rem;
  }

  .page-title h2 {
    font-size: 1.25rem;
  }

  .user-actions {
    gap: 0.5rem;
  }

  .user-actions button {
    font-size: 1rem;
    padding: 0.4rem;
    width: 36px;
    height: 36px;
  }
}
</style>

<script>
// Dark mode toggle functionality
document.addEventListener('DOMContentLoaded', function() {
  const darkModeToggle = document.getElementById('darkModeToggle');
  const body = document.body;
  
  // Check for saved dark mode preference or default to light mode
  const savedTheme = localStorage.getItem('theme');
  if (savedTheme === 'dark') {
    body.classList.add('dark-mode');
    darkModeToggle.innerHTML = '<i class="fas fa-sun"></i>';
  }
  
  darkModeToggle.addEventListener('click', function() {
    body.classList.toggle('dark-mode');
    
    if (body.classList.contains('dark-mode')) {
      localStorage.setItem('theme', 'dark');
      this.innerHTML = '<i class="fas fa-sun"></i>';
    } else {
      localStorage.setItem('theme', 'light');
      this.innerHTML = '<i class="fas fa-moon"></i>';
    }
  });
});
</script>