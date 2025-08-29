document.addEventListener("DOMContentLoaded", function() {
    
    const tabButtons = document.querySelectorAll('.tab-button');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const tab = this.dataset.tab;
            
            
            tabButtons.forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
            
            
            document.querySelectorAll('.donation-fields').forEach(fields => {
                fields.style.display = 'none';
            });
            document.getElementById(`${tab}-fields`).style.display = 'block';
            
            
            document.getElementById('donation_type').value = tab;
        });
    });

    
    const donationDateInputs = document.querySelectorAll('input[name="donation_date"]');
    donationDateInputs.forEach(input => {
        input.addEventListener('change', function(e) {
            donationDateInputs.forEach(otherInput => {
                if (otherInput !== e.target) {
                    otherInput.value = e.target.value;
                }
            });
        });
    });

    
    const donationForm = document.querySelector('.donation-form');
    if (donationForm) {
        donationForm.addEventListener('submit', function(e) {
            
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                submitBtn.disabled = true;
            }
            
            
            return true;
        });
    }

    
    function validateDonationForm() {
        const activeTab = document.getElementById('donation_type').value;
        const requiredFields = [
            { id: 'donor_name', message: 'Please enter your full name' },
            { id: 'donor_email', message: 'Please enter your email address' }
        ];

        
        if (activeTab === 'monetary') {
            requiredFields.push(
                { id: 'amount', message: 'Please enter donation amount' },
                { id: 'payment_method', message: 'Please select payment method' }
            );
        } else {
            requiredFields.push(
                { id: 'item_description', message: 'Please describe the item' },
                { id: 'quantity', message: 'Please enter quantity' }
            );
        }

        
        for (const field of requiredFields) {
            const element = document.getElementById(field.id);
            if (element && element.value.trim() === '') {
                alert(field.message);
                element.focus();
                return false;
            }
        }

       
        const email = document.getElementById('donor_email').value;
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            alert('Please enter a valid email address');
            return false;
        }

        return true;
    }
});