function initializeLoginPage() {
  console.log("Login page loaded");
  

  const usernameField = document.querySelector('input[name="username"]');
  if (usernameField) {
    usernameField.focus();
  }
  
  
  const loginBtn = document.querySelector('.btn-login');
  if (loginBtn) {
    loginBtn.addEventListener('mouseenter', () => {
      loginBtn.style.transform = 'translateY(-2px)';
      loginBtn.style.boxShadow = '0 4px 8px rgba(0,0,0,0.1)';
    });
    
    loginBtn.addEventListener('mouseleave', () => {
      loginBtn.style.transform = '';
      loginBtn.style.boxShadow = '';
    });
  }
  
  const loginForm = document.querySelector('.login-form');
  if (loginForm) {
    loginForm.addEventListener('submit', (e) => {
      const btn = loginForm.querySelector('button[type="submit"]');
      if (btn) {
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Logging in...';
        btn.disabled = true;
      }
    });
  }
}

document.addEventListener("DOMContentLoaded", function() {
  if (document.querySelector('.login-form')) {
    initializeLoginPage();
  }
});