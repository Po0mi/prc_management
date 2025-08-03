document.addEventListener('DOMContentLoaded', function() {
    // Initialize cart count on page load
    updateCartCount();
    
    // Add to cart functionality
    document.querySelectorAll('.add-to-cart').forEach(button => {
        button.addEventListener('click', function() {
            const productId = this.getAttribute('data-id');
            const productCard = this.closest('.merchandise-card');
            const quantity = parseInt(productCard.querySelector('.quantity').value);
            const size = productCard.querySelector('.product-size')?.value;
            const color = productCard.querySelector('.product-color')?.value;
            const productName = productCard.querySelector('h3').textContent;
            const productPrice = parseFloat(productCard.querySelector('.product-price').textContent.replace('₱', ''));
            const productImage = productCard.querySelector('.product-image img').src.split('/').pop();
            
            addToCart({
                id: productId,
                name: productName,
                price: productPrice,
                image: productImage,
                quantity: quantity,
                size: size || null,
                color: color || null,
                max_stock: parseInt(productCard.querySelector('.quantity').max)
            });
        });
    });
    
    // Quantity adjustments with better validation
    document.querySelectorAll('.quantity-btn').forEach(button => {
        button.addEventListener('click', function() {
            const input = this.parentElement.querySelector('.quantity');
            let quantity = parseInt(input.value);
            const max = parseInt(input.getAttribute('max'));
            
            if (this.classList.contains('minus')) {
                quantity = Math.max(parseInt(input.getAttribute('min')), quantity - 1);
            } else if (this.classList.contains('plus')) {
                quantity = Math.min(max, quantity + 1);
            }
            
            input.value = quantity;
        });
    });
    
    // Enhanced quantity input validation
    document.querySelectorAll('.quantity').forEach(input => {
        input.addEventListener('change', function() {
            const min = parseInt(this.getAttribute('min'));
            const max = parseInt(this.getAttribute('max'));
            let value = parseInt(this.value);
            
            if (isNaN(value) || value < min) {
                this.value = min;
            } else if (value > max) {
                this.value = max;
                showToast(`Maximum available quantity is ${max}`, 'warning');
            }
        });
        
        // Prevent non-numeric input
        input.addEventListener('keydown', function(e) {
            if (['e', 'E', '+', '-'].includes(e.key)) {
                e.preventDefault();
            }
        });
    });
});

/**
 * Add item to cart with comprehensive data
 */
function addToCart(item) {
    // Show loading state
    const button = document.querySelector(`.add-to-cart[data-id="${item.id}"]`);
    const originalText = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
    
    fetch('../api/cart.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'add',
            product_id: item.id,
            name: item.name,
            price: item.price,
            image: item.image,
            quantity: item.quantity,
            size: item.size,
            color: item.color,
            max_stock: item.max_stock
        })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            updateCartCount(data.cart_count);
            showToast(`${item.quantity} ${item.name} added to cart`);
            
            // Pulse animation on cart icon
            const cartIcon = document.querySelector('.cart-link i');
            cartIcon.classList.add('pulse');
            setTimeout(() => cartIcon.classList.remove('pulse'), 1000);
        } else {
            showToast(data.message || 'Error adding to cart', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to add item to cart. Please try again.', 'error');
    })
    .finally(() => {
        button.disabled = false;
        button.innerHTML = originalText;
    });
}

/**
 * Update cart count display
 */
function updateCartCount(count) {
    if (count === undefined) {
        // Fetch current count if not provided
        fetch('../api/cart.php?action=count')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    setCartCount(data.count);
                }
            })
            .catch(console.error);
    } else {
        setCartCount(count);
    }
}

function setCartCount(count) {
    document.querySelectorAll('#cart-count, .cart-count').forEach(el => {
        el.textContent = count;
        el.classList.add('updated');
        setTimeout(() => el.classList.remove('updated'), 500);
    });
}

/**
 * Show toast notification
 */
function showToast(message, type = 'success') {
    // Remove existing toasts
    document.querySelectorAll('.toast').forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <i class="fas ${type === 'success' ? 'fa-check-circle' : 
                         type === 'error' ? 'fa-exclamation-circle' : 
                         'fa-info-circle'}"></i>
        <span>${message}</span>
    `;
    
    document.body.appendChild(toast);
    
    // Animation in
    setTimeout(() => toast.classList.add('show'), 10);
    
    // Auto-remove after delay
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Add click handler for toast dismissal
document.addEventListener('click', function(e) {
    if (e.target.closest('.toast')) {
        const toast = e.target.closest('.toast');
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }
});