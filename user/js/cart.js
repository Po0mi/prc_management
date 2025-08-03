document.addEventListener('DOMContentLoaded', function() {
    // Quantity adjustments
    document.querySelectorAll('.quantity-btn').forEach(button => {
        button.addEventListener('click', function() {
            const itemId = this.getAttribute('data-id');
            const input = this.parentElement.querySelector('.quantity');
            let quantity = parseInt(input.value);
            
            if (this.classList.contains('minus')) {
                if (quantity > 1) {
                    quantity--;
                }
            } else if (this.classList.contains('plus')) {
                const max = parseInt(input.getAttribute('max'));
                if (quantity < max) {
                    quantity++;
                }
            }
            
            input.value = quantity;
            updateCartItem(itemId, quantity);
        });
    });
    
    // Remove item
    document.querySelectorAll('.remove-item').forEach(button => {
        button.addEventListener('click', function() {
            const itemId = this.getAttribute('data-id');
            removeCartItem(itemId);
        });
    });
    
    // Checkout button
    const checkoutBtn = document.getElementById('checkout-button');
    if (checkoutBtn) {
        checkoutBtn.addEventListener('click', function() {
            document.getElementById('checkout-modal').style.display = 'block';
        });
    }
    
    // Close modal
    const closeModal = document.querySelector('.close-modal');
    if (closeModal) {
        closeModal.addEventListener('click', function() {
            document.getElementById('checkout-modal').style.display = 'none';
        });
    }
    
    // Checkout form submission
    const checkoutForm = document.getElementById('checkout-form');
    if (checkoutForm) {
        checkoutForm.addEventListener('submit', function(e) {
            e.preventDefault();
            processCheckout();
        });
    }
    
    // Update cart when quantity input changes
    document.querySelectorAll('.quantity').forEach(input => {
        input.addEventListener('change', function() {
            const itemId = this.closest('.cart-item').getAttribute('data-id');
            const quantity = parseInt(this.value);
            const max = parseInt(this.getAttribute('max'));
            
            if (quantity < 1) this.value = 1;
            if (quantity > max) this.value = max;
            
            updateCartItem(itemId, parseInt(this.value));
        });
    });
});

function updateCartItem(itemId, quantity) {
    fetch('../api/cart.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'update',
            item_id: itemId,
            quantity: quantity
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateCartDisplay(data.cart);
            showToast('Cart updated');
        } else {
            showToast(data.message || 'Error updating cart', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error updating cart', 'error');
    });
}

function removeCartItem(itemId) {
    fetch('../api/cart.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'remove',
            item_id: itemId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.querySelector(`.cart-item[data-id="${itemId}"]`).remove();
            updateCartDisplay(data.cart);
            showToast('Item removed from cart');
            
            // If cart is empty, reload the page to show empty state
            if (data.cart_count === 0) {
                location.reload();
            }
        } else {
            showToast(data.message || 'Error removing item', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error removing item', 'error');
    });
}

function processCheckout() {
    const form = document.getElementById('checkout-form');
    const formData = new FormData(form);
    const data = {
        action: 'checkout',
        shipping_address: formData.get('shipping_address'),
        contact_number: formData.get('contact_number'),
        payment_method: formData.get('payment_method')
    };
    
    fetch('../api/cart.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Order placed successfully!', 'success');
            document.getElementById('checkout-modal').style.display = 'none';
            
            // Redirect to order confirmation page
            setTimeout(() => {
                window.location.href = `order_confirmation.php?order_id=${data.order_id}`;
            }, 1500);
        } else {
            showToast(data.message || 'Error processing order', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error processing order', 'error');
    });
}

function updateCartDisplay(cartData) {
    // Update cart count in header
    const cartCountElements = document.querySelectorAll('#cart-count, .cart-count');
    cartCountElements.forEach(el => {
        el.textContent = cartData.cart_count || 0;
    });
    
    // Update item totals
    cartData.items.forEach(item => {
        const itemElement = document.querySelector(`.cart-item[data-id="${item.id}"]`);
        if (itemElement) {
            const totalElement = itemElement.querySelector('.item-total');
            if (totalElement) {
                totalElement.textContent = `₱${(item.price * item.quantity).toFixed(2)}`;
            }
        }
    });
    
    // Update cart total
    const totalAmountElement = document.getElementById('cart-total-amount');
    if (totalAmountElement) {
        totalAmountElement.textContent = `₱${cartData.total_amount.toFixed(2)}`;
    }
}

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.classList.add('show');
    }, 10);
    
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => {
            toast.remove();
        }, 300);
    }, 3000);
}