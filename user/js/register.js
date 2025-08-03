
document.addEventListener("DOMContentLoaded", function() {
    
    highlightCurrentPage();
    
  
    initEventCards();
   
    setupRegistrationForms();
});


function highlightCurrentPage() {
    const currentPage = window.location.pathname.split('/').pop().toLowerCase();
    document.querySelectorAll('.nav-link').forEach(link => {
        const linkPage = link.getAttribute('href').toLowerCase();
        link.classList.toggle('active', linkPage === currentPage);
    });
}


function initEventCards() {
    const eventCards = document.querySelectorAll('.event-card');
    
    eventCards.forEach(card => {
       
        card.addEventListener('mouseenter', () => {
            card.style.transform = 'translateY(-5px)';
            card.style.boxShadow = '0 6px 12px rgba(0,0,0,0.15)';
        });
        
        card.addEventListener('mouseleave', () => {
            card.style.transform = '';
            card.style.boxShadow = '0 4px 8px rgba(0,0,0,0.1)';
        });
        
        
        const registerBtn = card.querySelector('.register-btn');
        if (registerBtn) {
            registerBtn.addEventListener('click', function(e) {
             
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Registering...';
                this.disabled = true;
                
                
            });
        }
    });
}


function setupRegistrationForms() {
    const forms = document.querySelectorAll('.registration-form');
    
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const fullName = form.querySelector('input[name="full_name"]').value.trim();
            const email = form.querySelector('input[name="email"]').value.trim();
            const btn = form.querySelector('button[type="submit"]');
            
            
            if (!fullName || !email) {
                e.preventDefault();
                alert('Please fill in all required fields');
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-user-plus"></i> Register';
                    btn.disabled = false;
                }
                return;
            }
            
           
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                e.preventDefault();
                alert('Please enter a valid email address');
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-user-plus"></i> Register';
                    btn.disabled = false;
                }
                return;
            }
            
            
        });
    });
}


function showSuccessMessage(message) {
    const messageDiv = document.createElement('div');
    messageDiv.className = 'success-message';
    messageDiv.innerHTML = message;
    document.querySelector('.registration-container').prepend(messageDiv);
    

    setTimeout(() => {
        messageDiv.remove();
    }, 5000);
}


if (window.registrationSuccess) {
    showSuccessMessage("Registration successful! Awaiting confirmation.");
}