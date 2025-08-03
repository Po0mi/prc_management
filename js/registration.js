

function initializeRegistrationPage() {
  const form = document.getElementById('registerForm');
  if (!form) return;

  const passwordInput = document.getElementById('password');
  const confirmPasswordInput = document.getElementById('confirmPassword');
  

  const firstNameInput = form.querySelector('input[name="first_name"]');
  if (firstNameInput) firstNameInput.focus();
  
 
  if (passwordInput) {
    passwordInput.addEventListener('input', function() {
      updatePasswordStrengthMeter(this.value);
    });
  }
  
  
  form.addEventListener('submit', function(e) {
    if (!validateForm()) {
      e.preventDefault();
    } else {
      showLoadingState();
    }
  });
  

  if (window.formSuccess) {
    form.reset();
    if (window.grecaptcha) grecaptcha.reset();
  }
  
  function updatePasswordStrengthMeter(password) {
    const strengthMeter = document.createElement('div');
    strengthMeter.className = 'password-strength';
    strengthMeter.innerHTML = '<div class="strength-meter"></div>';
    
    const existingMeter = passwordInput.parentNode.querySelector('.password-strength');
    if (existingMeter) {
      passwordInput.parentNode.removeChild(existingMeter);
    }
    
    passwordInput.parentNode.appendChild(strengthMeter);
    
    const strength = calculatePasswordStrength(password);
    const meter = strengthMeter.querySelector('.strength-meter');
    
    
    if (strength < 30) {
      meter.style.width = '30%';
      meter.style.backgroundColor = '#dc3545';
    } else if (strength < 60) {
      meter.style.width = '60%';
      meter.style.backgroundColor = '#ffc107';
    } else if (strength < 80) {
      meter.style.width = '80%';
      meter.style.backgroundColor = '#28a745';
    } else {
      meter.style.width = '100%';
      meter.style.backgroundColor = '#007bff';
    }
  }
  
  function validateForm() {
   
    if (passwordInput && confirmPasswordInput && 
      passwordInput.value !== confirmPasswordInput.value) {
      alert("Passwords do not match.");
      return false;
    }
    

    if (grecaptcha && grecaptcha.getResponse() === '') {
      alert("Please complete the reCAPTCHA.");
      return false;
    }
    
    return true;
  }
  
  function showLoadingState() {
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) {
      submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
      submitBtn.disabled = true;
    }
  }
  
  function calculatePasswordStrength(password) {
    let strength = 0;
    strength += Math.min(40, (password.length * 5));
    
    if (password.match(/[a-z]/) && password.match(/[A-Z]/)) {
      strength += 20;
    }
    
    if (password.match(/\d/)) {
      strength += 20;
    }
    
    if (password.match(/[^a-zA-Z0-9]/)) {
      strength += 20;
    }
    
    return Math.min(100, strength);
  }
}

document.addEventListener("DOMContentLoaded", function() {
  if (document.getElementById('registerForm')) {
    initializeRegistrationPage();
  }
});