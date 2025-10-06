// Store training sessions and user registrations in global scope
window.calendarTrainingsData = <?php echo json_encode($calendarTrainings); ?>;
window.userTrainingRegistrations = <?php echo json_encode($userRegistrationsJS); ?>;
function showVenueModal(sessionTitle, venueText) {
    // Remove any existing venue modal
    const existingModal = document.querySelector('.venue-modal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Create modal
    const modal = document.createElement('div');
    modal.className = 'venue-modal';
    modal.innerHTML = `
        <div class="venue-modal-content">
            <div class="venue-modal-header">
                <h3>
                    <i class="fas fa-map-marker-alt"></i>
                    ${escapeHtml(sessionTitle)} - Training Venue
                </h3>
                <button class="venue-modal-close" onclick="closeVenueModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="venue-modal-body">
                ${escapeHtml(venueText).replace(/\n/g, '<br>')}
            </div>
        </div>
    `;
    
    // Add to body
    document.body.appendChild(modal);
    
    // Show modal
    setTimeout(() => {
        modal.classList.add('active');
    }, 10);
    
    // Add click outside to close
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeVenueModal();
        }
    });
    
    // Store reference
    window.currentVenueModal = modal;
    
    // Prevent body scroll
    document.body.style.overflow = 'hidden';
}

function closeVenueModal() {
    const modal = window.currentVenueModal || document.querySelector('.venue-modal');
    if (modal) {
        modal.classList.remove('active');
        setTimeout(() => {
            modal.remove();
            window.currentVenueModal = null;
            document.body.style.overflow = '';
        }, 300);
    }
}
// Enhanced date formatting function that handles timezone properly
function formatDateToString(date) {
    if (typeof date === 'string') {
        if (date.match(/^\d{4}-\d{2}-\d{2}$/)) {
            return date;
        }
        date = new Date(date + 'T12:00:00');
    }
    
    if (!(date instanceof Date) || isNaN(date)) {
        console.error('Invalid date passed to formatDateToString:', date);
        return '';
    }
    
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

// Create dates in local timezone consistently
function createLocalDate(dateString) {
    if (typeof dateString === 'string' && dateString.match(/^\d{4}-\d{2}-\d{2}$/)) {
        const [year, month, day] = dateString.split('-').map(Number);
        return new Date(year, month - 1, day);
    }
    return new Date(dateString);
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Function to check if user is registered for any training on a specific date
function checkUserTrainingRegistrationForDate(dateStr) {
    if (typeof window.userTrainingRegistrations === 'undefined') return false;
    
    const targetDate = createLocalDate(dateStr);
    
    return window.userTrainingRegistrations.some(reg => {
        const training = window.calendarTrainingsData.find(t => t.session_id === reg.session_id);
        if (training) {
            const trainingStart = createLocalDate(training.session_date);
            const trainingEnd = createLocalDate(training.session_end_date || training.session_date);
            
            const targetTime = targetDate.getTime();
            const startTime = trainingStart.getTime();
            const endTime = trainingEnd.getTime();
            
            return targetTime >= startTime && targetTime <= endTime;
        }
        return false;
    });
}

// Function to check if user is registered for a specific training
function checkUserTrainingRegistrationForSession(sessionId) {
    if (typeof window.userTrainingRegistrations === 'undefined') return false;
    return window.userTrainingRegistrations.some(reg => reg.session_id === parseInt(sessionId));
}

// Get trainings for a specific date
function getTrainingsForDate(dateStr) {
    if (typeof window.calendarTrainingsData === 'undefined') return [];
    
    const targetDate = createLocalDate(dateStr);
    
    return window.calendarTrainingsData.filter(training => {
        const trainingStart = createLocalDate(training.session_date);
        const trainingEnd = createLocalDate(training.session_end_date || training.session_date);
        
        const targetTime = targetDate.getTime();
        const startTime = trainingStart.getTime();
        const endTime = trainingEnd.getTime();
        
        return targetTime >= startTime && targetTime <= endTime;
    });
}

// Get training service color
function getTrainingServiceColor(service) {
    const serviceColors = {
        'Health Service': '#4CAF50',
        'Safety Service': '#FF5722',
        'Welfare Service': '#2196F3',
        'Disaster Management Service': '#FF9800',
        'Red Cross Youth': '#9C27B0'
    };
    return serviceColors[service] || '#607D8B';
}

// Enhanced training calendar generation with multi-day support
function generateTrainingCalendar() {
    const container = document.getElementById('trainingCalendarContainer');
    if (!container) return;
    
    const today = new Date();
    const currentMonth = today.getMonth();
    const currentYear = today.getFullYear();
    
    let calendarHTML = '';
    
    for (let monthOffset = 0; monthOffset < 3; monthOffset++) {
        const month = (currentMonth + monthOffset) % 12;
        const year = currentYear + Math.floor((currentMonth + monthOffset) / 12);
        
        calendarHTML += generateMonthCalendar(year, month, today);
    }
    
    container.innerHTML = calendarHTML;
}

// Enhanced month calendar generation with multi-day training spans
function generateMonthCalendar(year, month, today) {
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];
    
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const todayStr = formatDateToString(today);
    
    let html = `
        <div class="month-calendar">
            <div class="month-header">
                <h3>${monthNames[month]} ${year}</h3>
            </div>
            <div class="calendar-grid">
                <div class="day-header">Sun</div>
                <div class="day-header">Mon</div>
                <div class="day-header">Tue</div>
                <div class="day-header">Wed</div>
                <div class="day-header">Thu</div>
                <div class="day-header">Fri</div>
                <div class="day-header">Sat</div>
    `;
    
    // Fill in the days
    for (let i = 0; i < firstDay; i++) {
        html += '<div class="day-cell empty"></div>';
    }
    
    for (let day = 1; day <= daysInMonth; day++) {
        const dateStr = formatDateToString(new Date(year, month, day));
        const dateTrainings = getTrainingsForDate(dateStr);
        const isRegistered = checkUserTrainingRegistrationForDate(dateStr);
        const isToday = dateStr === todayStr;
        
        let dayClass = 'day-cell';
        let dayContent = `<span class="day-number">${day}</span>`;
        
        if (isToday) dayClass += ' today';
        if (dateTrainings.length > 0) {
            dayClass += ' has-events';
            if (isRegistered) dayClass += ' has-registered-event';
            
            dayContent += '<div class="event-indicators">';
            dateTrainings.slice(0, 3).forEach(training => {
                const isUserRegistered = window.userTrainingRegistrations.some(reg => reg.session_id === training.session_id);
                dayContent += `<div class="event-indicator ${isUserRegistered ? 'registered' : ''}" 
                                  style="background-color: ${getTrainingServiceColor(training.major_service)}"
                                  title="${training.title}"></div>`;
            });
            if (dateTrainings.length > 3) {
                dayContent += `<div class="event-count">+${dateTrainings.length - 3}</div>`;
            }
            dayContent += '</div>';
        }
        
        html += `<div class="${dayClass}" data-date="${dateStr}" 
                     onmouseover="showTrainingTooltip(event, '${dateStr}')"
                     onmouseout="hideTrainingTooltip()">
                     ${dayContent}
                 </div>`;
    }
    
    // Fill remaining cells
    const totalCells = Math.ceil((daysInMonth + firstDay) / 7) * 7;
    const remainingCells = totalCells - (daysInMonth + firstDay);
    for (let i = 0; i < remainingCells; i++) {
        html += '<div class="day-cell empty"></div>';
    }
    
    html += '</div></div>';
    return html;
}

// Large calendar modal functions
function openCalendarModal() {
    const modal = document.getElementById('calendarModal');
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        currentCalendarMonth = new Date().getMonth();
        currentCalendarYear = new Date().getFullYear();
        updateLargeCalendar();
    }
}

function closeCalendarModal() {
    const modal = document.getElementById('calendarModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

function changeMonth(direction) {
    currentCalendarMonth += direction;
    
    if (currentCalendarMonth > 11) {
        currentCalendarMonth = 0;
        currentCalendarYear++;
    } else if (currentCalendarMonth < 0) {
        currentCalendarMonth = 11;
        currentCalendarYear--;
    }
    
    updateLargeCalendar();
}

function updateLargeCalendar() {
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];
    
    const currentMonthYear = document.getElementById('currentMonthYear');
    if (currentMonthYear) {
        currentMonthYear.textContent = `${monthNames[currentCalendarMonth]} ${currentCalendarYear}`;
    }
    
    const calendarContainer = document.getElementById('largeCalendarContainer');
    if (calendarContainer) {
        calendarContainer.innerHTML = generateLargeCalendarGrid(currentCalendarYear, currentCalendarMonth);
    }
}

// Enhanced large calendar generation for modal with multi-day support
function generateLargeCalendarGrid(year, month) {
    const today = new Date();
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const todayStr = formatDateToString(today);
    
    let html = `
        <div class="large-calendar-grid">
            <div class="calendar-weekdays">
                <div class="weekday">Sun</div>
                <div class="weekday">Mon</div>
                <div class="weekday">Tue</div>
                <div class="weekday">Wed</div>
                <div class="weekday">Thu</div>
                <div class="weekday">Fri</div>
                <div class="weekday">Sat</div>
            </div>
            <div class="calendar-days">
    `;
    
    // Fill in the days
    for (let i = 0; i < firstDay; i++) {
        html += '<div class="calendar-day empty"></div>';
    }
    
    for (let day = 1; day <= daysInMonth; day++) {
        const dateStr = formatDateToString(new Date(year, month, day));
        const dateTrainings = getTrainingsForDate(dateStr);
        const isRegistered = checkUserTrainingRegistrationForDate(dateStr);
        const isToday = dateStr === todayStr;
        const cellDate = new Date(dateStr + 'T00:00:00');
        const isPast = cellDate < today && !isToday;
        
        let dayClass = 'calendar-day';
        
        if (dateTrainings.length > 0) {
            dayClass += ' has-events';
            if (isRegistered) dayClass += ' has-registered-event';
        }
        
        if (isToday) dayClass += ' today';
        if (isPast) dayClass += ' past';
        
        html += `<div class="${dayClass}" data-date="${dateStr}" 
                    onmouseover="showTrainingTooltip(event, '${dateStr}')"
                    onmouseout="hideTrainingTooltip()">
            <div class="day-number">${day}</div>`;
        
        if (dateTrainings.length > 0) {
            html += '<div class="event-display">';
            
            dateTrainings.slice(0, 2).forEach(training => {
                const isUserRegistered = window.userTrainingRegistrations.some(reg => reg.session_id === training.session_id);
                const trainingClass = `event-bar ${isUserRegistered ? 'registered' : ''}`;
                
                html += `<div class="${trainingClass}" 
                           style="--event-color: ${getTrainingServiceColor(training.major_service)};"
                           title="${escapeHtml(training.title)}${isUserRegistered ? ' (Registered)' : ''}">
                           ${truncateText(training.title, 12)}
                         </div>`;
            });
            
            if (dateTrainings.length > 2) {
                html += '<div class="event-dots">';
                dateTrainings.slice(2, 5).forEach(training => {
                    const isUserRegistered = window.userTrainingRegistrations.some(reg => reg.session_id === training.session_id);
                    const dotClass = `event-dot ${isUserRegistered ? 'registered' : ''}`;
                    html += `<div class="${dotClass}" 
                               style="background-color: ${getTrainingServiceColor(training.major_service)};"
                               title="${escapeHtml(training.title)}${isUserRegistered ? ' (Registered)' : ''}"></div>`;
                });
                
                if (dateTrainings.length > 5) {
                    html += `<div class="event-count">+${dateTrainings.length - 5}</div>`;
                }
                html += '</div>';
            }
            
            html += '</div>';
            
            if (isRegistered) {
                html += '<div class="registration-indicator">✓</div>';
            }
        }
        
        html += '</div>';
    }

    // Fill remaining cells
    const totalCells = Math.ceil((daysInMonth + firstDay) / 7) * 7;
    const remainingCells = totalCells - (daysInMonth + firstDay);
    for (let i = 0; i < remainingCells; i++) {
        html += '<div class="calendar-day empty"></div>';
    }
    
    html += '</div></div>';
    return html;
}

function truncateText(text, maxLength) {
    if (!text) return '';
    return text.length > maxLength ? text.substring(0, maxLength) + '...' : text;
}

// Enhanced training tooltip with multi-day training info
function showTrainingTooltip(event, dateStr) {
    const tooltip = document.getElementById('trainingTooltip');
    if (!tooltip || typeof window.calendarTrainingsData === 'undefined') return;
    
    const dayTrainings = getTrainingsForDate(dateStr);
    if (dayTrainings.length === 0) return;
    
    let tooltipContent = '';
    dayTrainings.forEach(trainingData => {
        const isRegistered = checkUserTrainingRegistrationForSession(trainingData.session_id);
        const registrationStatus = isRegistered ? 
            '<div class="tooltip-event-status registered">✓ Registered</div>' : 
            '<div class="tooltip-event-status available">Available</div>';
        
        const startDate = new Date(trainingData.session_date);
        const endDate = new Date(trainingData.session_end_date || trainingData.session_date);
        const durationDays = trainingData.duration_days || 1;
        
        const durationText = durationDays > 1 ? 
            `📅 ${durationDays} days (${formatDateForTooltip(startDate)} - ${formatDateForTooltip(endDate)})` :
            `📅 ${formatDateForTooltip(startDate)}`;
        
        const timeText = `🕐 ${formatTime(trainingData.start_time)} - ${formatTime(trainingData.end_time)}`;
        
        tooltipContent += `
            <div class="tooltip-event ${isRegistered ? 'registered' : ''}">
                <div class="tooltip-event-title">${escapeHtml(trainingData.title)}</div>
                <div class="tooltip-event-duration">${durationText}</div>
                <div class="tooltip-event-time">${timeText}</div>
                <div class="tooltip-event-location">📍 ${escapeHtml(trainingData.venue)}</div>
                <div class="tooltip-event-capacity">👥 ${trainingData.registrations_count || 0}/${trainingData.capacity || '∞'}</div>
                ${trainingData.fee > 0 ? `<div class="tooltip-event-fee">💰 ₱${parseFloat(trainingData.fee).toFixed(2)}</div>` : '<div class="tooltip-event-fee">🆓 Free</div>'}
                <div class="tooltip-event-service">🏥 ${escapeHtml(trainingData.major_service)}</div>
                ${registrationStatus}
            </div>
        `;
    });
    
    tooltip.querySelector('.tooltip-content').innerHTML = tooltipContent;
    tooltip.style.display = 'block';
    
    const rect = event.target.getBoundingClientRect();
    tooltip.style.position = 'fixed';
    tooltip.style.left = (rect.left + window.scrollX) + 'px';
    tooltip.style.top = (rect.bottom + window.scrollY + 5) + 'px';
}

function hideTrainingTooltip() {
    const tooltip = document.getElementById('trainingTooltip');
    if (tooltip) {
        tooltip.style.display = 'none';
    }
}

function formatDateForTooltip(date) {
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function formatTime(timeString) {
    if (!timeString) return '';
    const time = new Date('1970-01-01T' + timeString + 'Z');
    return time.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    });
}

// Enhanced openRegisterModal function with multi-day support
function openRegisterModal(session) {
    currentSession = session;
    document.getElementById('sessionId').value = session.session_id;
    document.getElementById('modalTitle').textContent = 'Register for ' + session.title;
    
    const trainingInfo = document.getElementById('trainingInfo');
    const sessionStartDate = createLocalDate(session.session_date);
    const sessionEndDate = createLocalDate(session.session_end_date || session.session_date);
    const durationDays = session.duration_days || 1;
    
    // Format date display based on duration
    let dateDisplay;
    if (durationDays === 1) {
        dateDisplay = sessionStartDate.toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'});
    } else {
        const startStr = sessionStartDate.toLocaleDateString('en-US', {month: 'short', day: 'numeric'});
        const endStr = sessionEndDate.toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'});
        dateDisplay = `${startStr} - ${endStr} (${durationDays} days)`;
    }
    
    trainingInfo.innerHTML = `
        <div class="event-details">
            <h3>${escapeHtml(session.title)}</h3>
            <p><i class="fas fa-calendar"></i> ${dateDisplay}</p>
            <p><i class="fas fa-clock"></i> ${formatTime(session.start_time)} - ${formatTime(session.end_time)}</p>
            <p><i class="fas fa-map-marker-alt"></i> ${escapeHtml(session.venue)}</p>
            <p><i class="fas fa-tag"></i> ${escapeHtml(session.major_service)}</p>
            ${session.fee > 0 ? `<p><i class="fas fa-money-bill"></i> Fee: ₱${parseFloat(session.fee).toFixed(2)}</p>` : '<p><i class="fas fa-gift"></i> Free Training</p>'}
            <p><i class="fas fa-users"></i> Available Slots: ${session.capacity > 0 ? (session.capacity - session.registered_count) : 'Unlimited'}</p>
        </div>
    `;
    
    // Auto-fill training type and date for both tabs
    document.getElementById('training_type_individual').value = session.title;
    document.getElementById('training_date_individual').value = session.session_date;
    document.getElementById('training_type_org').value = session.title;
    document.getElementById('training_date_org').value = session.session_date;
    
    // Handle payment section
    updatePaymentSection(session.fee);
    
    // Reset form to individual tab
    switchTab('individual');
    
    document.getElementById('registerModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function updatePaymentSection(fee) {
    const paymentSection = document.getElementById('paymentSection');
    const trainingFeeAmount = document.getElementById('trainingFeeAmount');
    const totalAmountDisplay = document.getElementById('totalAmountDisplay');
    const hiddenPaymentAmount = document.getElementById('hiddenPaymentAmount');

    if (fee > 0) {
        paymentSection.style.display = 'block';
        const formattedFee = '₱' + parseFloat(fee).toFixed(2);
        trainingFeeAmount.textContent = formattedFee;
        totalAmountDisplay.textContent = formattedFee;
        hiddenPaymentAmount.value = fee;
        
        // Update summary
        document.getElementById('summaryTrainingFee').textContent = formattedFee;
        document.getElementById('summaryTotalAmount').textContent = formattedFee;
        
        // Make payment method required
        const paymentModeInputs = document.querySelectorAll('input[name="payment_method"]');
        paymentModeInputs.forEach(input => input.required = true);
    } else {
        paymentSection.style.display = 'none';
        hiddenPaymentAmount.value = '0';
        
        // Make payment method not required for free training
        const paymentModeInputs = document.querySelectorAll('input[name="payment_method"]');
        paymentModeInputs.forEach(input => input.required = false);
    }
}

function closeRegisterModal() {
    document.getElementById('registerModal').classList.remove('active');
    document.body.style.overflow = '';
    
    currentSession = null;
    
    const form = document.getElementById('registerForm');
    if (form) {
        form.reset();
        switchTab('individual');
        resetFileUploads();
        resetPaymentForms();
        
        const submitBtn = form.querySelector('.btn-submit');
        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fas fa-user-plus"></i> Register for Training';
            submitBtn.disabled = false;
        }
    }
}

function switchTab(tabName) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelector(`[onclick="switchTab('${tabName}')"]`).classList.add('active');
    
    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
    document.getElementById(`${tabName}-tab`).classList.add('active');
    
    if (tabName === 'individual') {
        document.getElementById('registration_type_individual').disabled = false;
        document.getElementById('registration_type_organization').disabled = true;
        setRequiredFields(true, false);
        
        document.getElementById('organization_name').value = '';
        document.getElementById('pax_count').value = '';
        
        if (currentSession) {
            document.getElementById('training_type_individual').value = currentSession.title;
            document.getElementById('training_date_individual').value = currentSession.session_date;
        }
    } else {
        document.getElementById('registration_type_individual').disabled = true;
        document.getElementById('registration_type_organization').disabled = false;
        setRequiredFields(false, true);
        
        document.getElementById('full_name').value = '';
        document.getElementById('location').value = '';
        document.getElementById('age').value = '';
        document.getElementById('rcy_status').value = '';
        
        if (currentSession) {
            document.getElementById('training_type_org').value = currentSession.title;
            document.getElementById('training_date_org').value = currentSession.session_date;
        }
    }
}

function setRequiredFields(individual, organization) {
    document.getElementById('full_name').required = individual;
    document.getElementById('location').required = individual;
    document.getElementById('age').required = individual;
    document.getElementById('rcy_status').required = individual;
    
    document.getElementById('organization_name').required = organization;
    document.getElementById('pax_count').required = organization;
}

function filterService(service) {
    const urlParams = new URLSearchParams(window.location.search);
    if (service === 'all') {
        urlParams.delete('service');
    } else {
        urlParams.set('service', service);
    }
    
    const currentSearch = urlParams.get('search');
    if (currentSearch) {
        urlParams.set('search', currentSearch);
    }
    
    window.location.search = urlParams.toString();
}

// Payment handling functions
function handlePaymentMethodChange(selectedMethod) {
    document.querySelectorAll('.payment-form').forEach(form => {
        form.classList.remove('active');
        form.style.display = 'none';
    });
    
    const selectedForm = document.getElementById(selectedMethod + '_form');
    if (selectedForm) {
        selectedForm.style.display = 'block';
        selectedForm.classList.add('active');
    }
    
    const receiptUpload = document.getElementById('receiptUpload');
    const paymentSummary = document.getElementById('paymentSummary');
    
    if (selectedMethod === 'cash') {
        if (receiptUpload) receiptUpload.style.display = 'none';
        const receiptInput = document.getElementById('payment_receipt');
        if (receiptInput) receiptInput.required = false;
    } else {
        if (receiptUpload) receiptUpload.style.display = 'block';
        const receiptInput = document.getElementById('payment_receipt');
        if (receiptInput) receiptInput.required = true;
    }
    
    if (paymentSummary) paymentSummary.style.display = 'block';
    const selectedMethodSpan = document.getElementById('selectedPaymentMethod');
    if (selectedMethodSpan) selectedMethodSpan.textContent = getPaymentMethodName(selectedMethod);
}

function getPaymentMethodName(method) {
    const names = {
        'bank_transfer': 'Bank Transfer',
        'gcash': 'GCash',
        'paymaya': 'PayMaya',
        'cash': 'Cash Payment'
    };
    return names[method] || method;
}

function resetPaymentForms() {
    document.querySelectorAll('.payment-form').forEach(form => {
        form.style.display = 'none';
        form.classList.remove('active');
    });
    
    const receiptUpload = document.getElementById('receiptUpload');
    const paymentSummary = document.getElementById('paymentSummary');
    if (receiptUpload) receiptUpload.style.display = 'none';
    if (paymentSummary) paymentSummary.style.display = 'none';
    
    document.querySelectorAll('input[name="payment_method"]').forEach(input => {
        input.checked = false;
    });
    
    document.querySelectorAll('.payment-form input').forEach(input => {
        input.value = '';
        input.removeAttribute('required');
    });
}

function resetFileUploads() {
    document.querySelectorAll('.file-upload-container, .receipt-upload').forEach(container => {
        container.classList.remove('has-file');
    });
}

function handleFileUpload(inputElement) {
    const container = inputElement.closest('.file-upload-container') || inputElement.closest('.receipt-upload');
    const info = container.querySelector('.file-upload-info span') || container.querySelector('.upload-text');
    
    if (inputElement.files && inputElement.files[0]) {
        const file = inputElement.files[0];
        let maxSize = inputElement.name === 'valid_id' || inputElement.name === 'payment_receipt' ? 5 * 1024 * 1024 : 10 * 1024 * 1024;
        
        if (file.size > maxSize) {
            alert(`File size too large. Maximum allowed: ${maxSize / (1024 * 1024)}MB`);
            inputElement.value = '';
            return;
        }
        
        const allowedTypes = inputElement.name === 'valid_id' || inputElement.name === 'payment_receipt' ? 
            ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'] :
            ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        
        if (!allowedTypes.includes(file.type)) {
            alert('Invalid file type. Please upload a supported file format.');
            inputElement.value = '';
            return;
        }
        
        container.classList.add('has-file');
        info.textContent = `Selected: ${file.name}`;
    } else {
        container.classList.remove('has-file');
        if (inputElement.name === 'valid_id') {
            info.textContent = 'Upload a clear photo of your valid ID';
        } else if (inputElement.name === 'payment_receipt') {
            info.textContent = 'Upload Payment Receipt';
        } else {
            info.textContent = 'Upload requirements/supporting documents';
        }
    }
}

function validateForm(form) {
    const activeTab = document.querySelector('.tab-content.active');
    const isIndividual = activeTab && activeTab.id === 'individual-tab';
    
    if (isIndividual) {
        const fullName = form.querySelector('#full_name');
        const location = form.querySelector('#location');
        const age = form.querySelector('#age');
        const rcyStatus = form.querySelector('#rcy_status');
        
        if (!fullName || !fullName.value.trim()) {
            alert('Please enter your full name.');
            if (fullName) fullName.focus();
            return false;
        }
        
        if (!location || !location.value.trim()) {
            alert('Please enter your location.');
            if (location) location.focus();
            return false;
        }
        
        if (!age || !age.value || age.value < 1 || age.value > 120) {
            alert('Please enter a valid age (1-120).');
            if (age) age.focus();
            return false;
        }
        
        if (!rcyStatus || !rcyStatus.value) {
            alert('Please select your RCY status.');
            if (rcyStatus) rcyStatus.focus();
            return false;
        }
    } else {
        const orgName = form.querySelector('#organization_name');
        const paxCount = form.querySelector('#pax_count');
        
        if (!orgName || !orgName.value.trim()) {
            alert('Please enter the organization/company name.');
            if (orgName) orgName.focus();
            return false;
        }
        
        if (!paxCount || !paxCount.value || paxCount.value < 1) {
            alert('Please enter the number of participants.');
            if (paxCount) paxCount.focus();
            return false;
        }
    }
    
    // Check payment method for paid sessions
    if (currentSession && parseFloat(currentSession.fee) > 0) {
        const paymentMode = form.querySelector('input[name="payment_method"]:checked');
        if (!paymentMode) {
            alert('Please select a payment method.');
            return false;
        }
        
        // Check receipt upload for non-cash payments
        if (paymentMode.value !== 'cash') {
            const receiptInput = form.querySelector('#payment_receipt');
            if (!receiptInput || !receiptInput.files || !receiptInput.files[0]) {
                alert('Please upload your payment receipt.');
                if (receiptInput) receiptInput.focus();
                return false;
            }
        }
    }
    
    // Check valid ID upload
    const validId = form.querySelector('#valid_id');
    if (!validId || !validId.files || !validId.files[0]) {
        alert('Please upload a valid ID.');
        if (validId) validId.focus();
        return false;
    }
    
    return true;
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    generateTrainingCalendar();
    const startDateInput = document.getElementById('preferred_start_date');
    const endDateInput = document.getElementById('preferred_end_date');
    
    // Initialize payment method listeners
    const paymentMethods = document.querySelectorAll('input[name="payment_method"]');
    paymentMethods.forEach(method => {
        method.addEventListener('change', function() {
            handlePaymentMethodChange(this.value);
        });
    });
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && window.currentVenueModal) {
            closeVenueModal();
        }
    });

    // Enhanced date change handlers for Training Request Modal
    if (startDateInput) {
        startDateInput.addEventListener('change', function() {
            const startDate = this.value;
            if (endDateInput) {
                endDateInput.min = startDate;
                if (endDateInput.value && endDateInput.value < startDate) {
                    endDateInput.value = startDate;
                }
                if (!endDateInput.value) {
                    endDateInput.value = startDate;
                }
            }
            updateRequestDatePreview();
        });
    }
    
    if (endDateInput) {
        endDateInput.addEventListener('change', function() {
            updateRequestDatePreview();
        });
    }
    
    // Phone number formatting and validation for Training Request Modal
    const phoneInput = document.getElementById('contact_number');
    if (phoneInput) {
        phoneInput.addEventListener('input', function() {
            let value = this.value.replace(/[^\d\+\-\s\(\)]/g, '');
            
            // Auto-format common patterns
            if (value.match(/^09\d{9}$/)) {
                // Format 09XXXXXXXXX to 09XX XXX XXXX
                value = value.replace(/(\d{4})(\d{3})(\d{4})/, '$1 $2 $3');
            } else if (value.match(/^0\d{2}\d{7}$/)) {
                // Format 0XXXXXXXXXX to 0XX XXX XXXX
                value = value.replace(/(\d{3})(\d{3})(\d{4})/, '$1 $2 $3');
            }
            
            this.value = value;
        });
        
        // Clear validation on input change
        phoneInput.addEventListener('input', function() {
            const feedbackElement = document.getElementById('phoneValidationFeedback');
            if (feedbackElement) {
                feedbackElement.style.display = 'none';
            }
            this.classList.remove('valid', 'invalid');
        });
    }

    // Initialize file upload handlers
    const validIdInput = document.getElementById('valid_id');
    const requirementsInput = document.getElementById('requirements');
    const receiptInput = document.getElementById('payment_receipt');
    
    if (validIdInput) {
        validIdInput.addEventListener('change', function() {
            handleFileUpload(this);
        });
    }

    if (requirementsInput) {
        requirementsInput.addEventListener('change', function() {
            handleFileUpload(this);
        });
    }

    if (receiptInput) {
        receiptInput.addEventListener('change', function() {
            handleFileUpload(this);
        });
    }

    // Form submission handler for registration
    const registerForm = document.getElementById('registerForm');
    if (registerForm) {
        registerForm.addEventListener('submit', function(e) {
            if (!validateForm(this)) {
                e.preventDefault();
                return;
            }

            const submitBtn = this.querySelector('.btn-submit');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing Registration...';
                submitBtn.disabled = true;
            }
        });
    }

    // Form submission handler for training request
    const trainingRequestForm = document.getElementById('trainingRequestForm');
    if (trainingRequestForm) {
        trainingRequestForm.addEventListener('submit', function(e) {
            const errors = validateTrainingRequestForm();
            
            if (errors.length > 0) {
                e.preventDefault();
                alert('Please fix the following errors:\n\n' + errors.join('\n'));
                return false;
            }
            
            // Check phone validation status
            const phoneInput = document.getElementById('contact_number');
            if (phoneInput && phoneInput.classList.contains('invalid')) {
                e.preventDefault();
                alert('Please enter a valid Philippine phone number before submitting.');
                phoneInput.focus();
                return false;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('.btn-submit');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting Request...';
                submitBtn.disabled = true;
            }
            
            return true;
        });
    }

    // Initialize form state for training request
    toggleOrganizationSection();
    
    // Event listeners for dynamic sections
    const participantCountInput = document.getElementById('participant_count');
    if (participantCountInput) {
        participantCountInput.addEventListener('input', toggleOrganizationSection);
    }
    
    const trainingProgramSelect = document.getElementById('training_program');
    if (trainingProgramSelect) {
        trainingProgramSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const programDescription = document.getElementById('program_description');
            const descriptionText = programDescription.querySelector('small');
            
            if (selectedOption.dataset.description) {
                descriptionText.textContent = selectedOption.dataset.description;
                programDescription.style.display = 'block';
            } else {
                programDescription.style.display = 'none';
            }
        });
    }

    // Close modal when clicking outside
    const modal = document.getElementById('registerModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeRegisterModal();
            }
        });
    }

    // Close calendar modal when clicking outside
    const calendarModal = document.getElementById('calendarModal');
    if (calendarModal) {
        calendarModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeCalendarModal();
            }
        });
    }

    // Close training request modal when clicking outside
    const trainingRequestModal = document.getElementById('trainingRequestModal');
    if (trainingRequestModal) {
        trainingRequestModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeTrainingRequestModal();
            }
        });
    }

    // Keyboard navigation
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (calendarModal && calendarModal.classList.contains('active')) {
                closeCalendarModal();
            } else if (modal && modal.classList.contains('active')) {
                closeRegisterModal();
            } else if (trainingRequestModal && trainingRequestModal.classList.contains('active')) {
                closeTrainingRequestModal();
            }
        }
        
        if (calendarModal && calendarModal.classList.contains('active')) {
            if (e.key === 'ArrowLeft') {
                e.preventDefault();
                changeMonth(-1);
            } else if (e.key === 'ArrowRight') {
                e.preventDefault();
                changeMonth(1);
            }
        }
    });

    // Auto-dismiss alerts
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-20px)';
                    setTimeout(() => {
                        if (alert.parentNode) {
                            alert.remove();
                        }
                    }, 300);
                }
            }, 5000);
        });
    }, 100);

    // Calendar day click handling
    document.addEventListener('click', function(e) {
        const dayCell = e.target.closest('.day-cell.has-events');
        if (dayCell) {
            const date = dayCell.getAttribute('data-date');
            if (date) {
                let dayTrainings = getTrainingsForDate(date);
                if (dayTrainings.length > 0) {
                    showDayTrainings(date, dayTrainings);
                }
            }
        }
    });

    // Initialize calendar styles
    injectMultiDayTrainingStyles();

    // Debug logs
    console.log('Training Calendar Data:', typeof window.calendarTrainingsData !== 'undefined' ? window.calendarTrainingsData : 'Not available');
    console.log('User Training Registrations:', typeof window.userTrainingRegistrations !== 'undefined' ? window.userTrainingRegistrations : 'Not available');
});

function showDayTrainings(date, trainings) {
    const trainingDate = new Date(date + 'T00:00:00');
    const formattedDate = trainingDate.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });

    alert(`Training Sessions on ${formattedDate}:\n${trainings.map(t => {
        const startDate = new Date(t.session_date);
        const endDate = new Date(t.session_end_date || t.session_date);
        const durationDays = t.duration_days || 1;
        const durationText = durationDays > 1 ? ` (${durationDays} days)` : '';
        return `• ${t.title}${durationText} - ${t.venue}`;
    }).join('\n')}`);
}
function handleFileUpload(inputElement) {
    const container = inputElement.closest('.file-upload-container');
    const info = container.querySelector('.file-upload-info span');
    
    if (inputElement.files && inputElement.files.length > 0) {
        if (inputElement.multiple) {
            // Handle multiple files
            handleMultipleFileUpload(inputElement);
        } else {
            // Handle single file
            const file = inputElement.files[0];
            const maxSize = getMaxFileSize(inputElement.name);
            
            if (file.size > maxSize) {
                alert(`File size too large. Maximum allowed: ${maxSize / (1024 * 1024)}MB`);
                inputElement.value = '';
                return;
            }
            
            if (!validateFileType(file, inputElement.accept)) {
                alert('Invalid file type. Please upload a supported file format.');
                inputElement.value = '';
                return;
            }
            
            container.classList.add('has-file');
            info.textContent = `Selected: ${file.name}`;
        }
        
        // Show participant list section for groups
        if (inputElement.name === 'participant_count') {
            toggleParticipantListSection();
        }
    } else {
        container.classList.remove('has-file');
        resetFileUploadInfo(inputElement);
    }
}

function handleMultipleFileUpload(inputElement) {
    const files = Array.from(inputElement.files);
    const listContainer = document.getElementById('additional_docs_list');
    
    // Clear existing list
    listContainer.innerHTML = '';
    
    if (files.length > 0) {
        listContainer.classList.add('has-files');
        
        files.forEach((file, index) => {
            const maxSize = 5 * 1024 * 1024; // 5MB
            
            if (file.size > maxSize) {
                alert(`File "${file.name}" is too large. Maximum allowed: 5MB`);
                return;
            }
            
            if (!validateFileType(file, inputElement.accept)) {
                alert(`File "${file.name}" has an invalid type.`);
                return;
            }
            
            const fileElement = document.createElement('div');
            fileElement.className = 'uploaded-file';
            fileElement.innerHTML = `
                <i class="fas ${getFileIconClass(file.name)}"></i>
                <span title="${file.name}">${file.name}</span>
                <button type="button" onclick="removeFile(this, ${index})" title="Remove file">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            listContainer.appendChild(fileElement);
        });
        
        const container = inputElement.closest('.file-upload-container');
        const info = container.querySelector('.file-upload-info span');
        container.classList.add('has-file');
        info.textContent = `Selected: ${files.length} file(s)`;
    } else {
        listContainer.classList.remove('has-files');
    }
}

function removeFile(button, index) {
    const fileInput = document.getElementById('additional_docs');
    const dt = new DataTransfer();
    
    // Rebuild file list without the removed file
    Array.from(fileInput.files).forEach((file, i) => {
        if (i !== index) {
            dt.items.add(file);
        }
    });
    
    fileInput.files = dt.files;
    
    // Update the display
    handleMultipleFileUpload(fileInput);
}

function getMaxFileSize(fileName) {
    if (fileName === 'valid_id_request' || fileName === 'additional_docs[]') {
        return 5 * 1024 * 1024; // 5MB
    }
    return 10 * 1024 * 1024; // 10MB
}

function validateFileType(file, acceptedTypes) {
    if (!acceptedTypes) return true;
    
    const accepted = acceptedTypes.split(',').map(type => type.trim());
    const fileExtension = '.' + file.name.split('.').pop().toLowerCase();
    
    return accepted.some(type => {
        if (type.startsWith('.')) {
            return type === fileExtension;
        } else {
            return file.type === type || file.type.startsWith(type.split('/')[0] + '/');
        }
    });
}

function getFileIconClass(filename) {
    const extension = filename.split('.').pop().toLowerCase();
    const iconMap = {
        'pdf': 'fa-file-pdf',
        'jpg': 'fa-file-image',
        'jpeg': 'fa-file-image',
        'png': 'fa-file-image',
        'gif': 'fa-file-image',
        'doc': 'fa-file-word',
        'docx': 'fa-file-word',
        'xls': 'fa-file-excel',
        'xlsx': 'fa-file-excel',
        'csv': 'fa-file-csv'
    };
    return iconMap[extension] || 'fa-file';
}

function resetFileUploadInfo(inputElement) {
    const container = inputElement.closest('.file-upload-container');
    const info = container.querySelector('.file-upload-info span');
    
    container.classList.remove('has-file');
    
    switch (inputElement.name) {
        case 'valid_id_request':
            info.textContent = 'Upload a clear photo of your valid ID';
            break;
        case 'participant_list':
            info.textContent = 'Upload list of participants (for groups of 5 or more)';
            break;
        case 'additional_docs[]':
            info.textContent = 'Upload supporting documents (certificates, authorization letters, etc.)';
            break;
    }
}

function toggleOrganizationSection() {
    const participantCount = parseInt(document.getElementById('participant_count').value) || 0;
    const orgSection = document.getElementById('organization_section');
    const participantListSection = document.getElementById('participant_list_section');
    
    if (participantCount >= 5) {
        orgSection.style.display = 'block';
        participantListSection.style.display = 'block';
        document.getElementById('participant_list').required = true;
    } else {
        orgSection.style.display = 'none';
        participantListSection.style.display = 'none';
        document.getElementById('participant_list').required = false;
        document.getElementById('organization_name').value = '';
    }
}

function toggleParticipantListSection() {
    const participantCount = parseInt(document.getElementById('participant_count').value) || 0;
    const participantListSection = document.getElementById('participant_list_section');
    
    if (participantCount >= 5) {
        participantListSection.style.display = 'block';
        document.getElementById('participant_list').required = true;
    } else {
        participantListSection.style.display = 'none';
        document.getElementById('participant_list').required = false;
    }
}

// Update training programs dropdown (enhanced version)
function updateTrainingPrograms() {
    const serviceType = document.getElementById('service_type').value;
    const programSelect = document.getElementById('training_program');
    const programDescription = document.getElementById('program_description');
    const participantCount = document.getElementById('participant_count');
    
    // Clear program selection
    programSelect.innerHTML = '<option value="">Select training program</option>';
    programDescription.style.display = 'none';
    
    if (serviceType && trainingPrograms[serviceType]) {
        // Enable program selection
        programSelect.disabled = false;
        
        // Populate programs for selected service
        trainingPrograms[serviceType].forEach(program => {
            const option = document.createElement('option');
            option.value = program.code;
            option.textContent = program.name;
            option.dataset.description = program.description;
            option.dataset.duration = program.duration;
            programSelect.appendChild(option);
        });
    } else {
        // Disable program selection
        programSelect.disabled = true;
    }
    
    // Update organization section visibility
    toggleOrganizationSection();
}

// Enhanced form validation
function validateTrainingRequestForm() {
    const form = document.getElementById('trainingRequestForm');
    const formData = new FormData(form);
    const errors = [];
    
    // Basic field validation
    const requiredFields = {
        'service_type': 'Service Type',
        'training_program': 'Training Program',
        'contact_person': 'Contact Person',
        'contact_number': 'Contact Number',
        'email': 'Email Address'
    };
    
    Object.entries(requiredFields).forEach(([field, label]) => {
        if (!formData.get(field) || formData.get(field).trim() === '') {
            errors.push(`${label} is required.`);
        }
    });
    
    // Enhanced phone validation
    const phone = formData.get('contact_number');
    if (phone) {
        const cleanNumber = phone.replace(/[^0-9]/g, '');
        const validPatterns = [
            /^(09\d{9})$/,
            /^(\+639\d{9})$/,
            /^(639\d{9})$/,
            /^(02\d{7,8})$/,
            /^(032\d{7})$/,
            /^(033\d{7})$/,
            /^(034\d{7})$/,
            /^(035\d{7})$/,
            /^(036\d{7})$/,
            /^(038\d{7})$/,
            /^(045\d{7})$/,
            /^(046\d{7})$/,
            /^(047\d{7})$/,
            /^(048\d{7})$/,
            /^(049\d{7})$/,
            /^(063\d{7})$/,
            /^(078\d{7})$/,
            /^(082\d{7})$/,
            /^(083\d{7})$/,
            /^(085\d{7})$/,
            /^(088\d{7})$/
        ];
        
        let phoneValid = false;
        for (const pattern of validPatterns) {
            if (pattern.test(cleanNumber)) {
                phoneValid = true;
                break;
            }
        }
        
        if (!phoneValid) {
            errors.push('Please enter a valid Philippine phone number.');
        }
    }
    
    // Email validation
    const email = formData.get('email');
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        errors.push('Please enter a valid email address.');
    }
    
    // Participant count validation
    const participantCount = parseInt(formData.get('participant_count')) || 0;
    if (participantCount < 1 || participantCount > 100) {
        errors.push('Participant count must be between 1 and 100.');
    }
    
    // Organization name required for large groups
    if (participantCount >= 5 && !formData.get('organization_name')) {
        errors.push('Organization name is required for groups of 5 or more participants.');
    }
    
    // Date validation
    const startDate = formData.get('preferred_start_date');
    const endDate = formData.get('preferred_end_date');
    
    if (startDate) {
        const start = new Date(startDate);
        const nextWeek = new Date();
        nextWeek.setDate(nextWeek.getDate() + 7);
        
        if (start < nextWeek) {
            errors.push('Preferred start date must be at least 1 week from now.');
        }
        
        if (endDate) {
            const end = new Date(endDate);
            if (end < start) {
                errors.push('End date cannot be before start date.');
            }
            
            const duration = Math.ceil((end - start) / (1000 * 60 * 60 * 24)) + 1;
            if (duration > 365) {
                errors.push('Training duration cannot exceed 365 days.');
            }
        }
    }
    
    // File validation
    const validIdFile = document.getElementById('valid_id_request').files[0];
    if (!validIdFile) {
        errors.push('Valid ID upload is required.');
    }
    
    const participantListFile = document.getElementById('participant_list').files[0];
    if (participantCount >= 5 && !participantListFile) {
        errors.push('Participant list is required for groups of 5 or more participants.');
    }
    
    return errors;
}

// Form submission handler
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('trainingRequestForm');
    
    
   if (form) {
        form.addEventListener('submit', function(e) {
            const errors = validateTrainingRequestForm();
            
            if (errors.length > 0) {
                e.preventDefault();
                alert('Please fix the following errors:\n\n' + errors.join('\n'));
                return false;
            }
            
            // Check phone validation status
            const phoneInput = document.getElementById('contact_number');
            if (phoneInput && phoneInput.classList.contains('invalid')) {
                e.preventDefault();
                alert('Please enter a valid Philippine phone number before submitting.');
                phoneInput.focus();
                return false;
            }
            
            // Show loading state
            const submitBtn = form.querySelector('.btn-submit');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting Request...';
                submitBtn.disabled = true;
            }
            
            return true;
        });
    }
    
    // Initialize form state
    toggleOrganizationSection();
    
    // Event listeners for dynamic sections
    const participantCountInput = document.getElementById('participant_count');
    if (participantCountInput) {
        participantCountInput.addEventListener('input', toggleOrganizationSection);
    }
    
    const trainingProgramSelect = document.getElementById('training_program');
    if (trainingProgramSelect) {
        trainingProgramSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const programDescription = document.getElementById('program_description');
            const descriptionText = programDescription.querySelector('small');
            
            if (selectedOption.dataset.description) {
                descriptionText.textContent = selectedOption.dataset.description;
                programDescription.style.display = 'block';
            } else {
                programDescription.style.display = 'none';
            }
        });
    }
});
// Update request date preview
function updateRequestDatePreview() {
    const startDateInput = document.getElementById('preferred_start_date');
    const endDateInput = document.getElementById('preferred_end_date');
    const previewContainer = document.getElementById('requestDatePreviewContainer');
    
    if (!startDateInput || !endDateInput || !previewContainer) return;
    
    const previewStartDate = document.getElementById('requestPreviewStartDate');
    const previewEndDate = document.getElementById('requestPreviewEndDate');
    const previewDuration = document.getElementById('requestPreviewDuration');
    
    const startDate = startDateInput.value;
    const endDate = endDateInput.value;
    
    if (startDate) {
        const start = new Date(startDate + 'T00:00:00');
        let end = start;
        let duration = 1;
        
        if (endDate) {
            end = new Date(endDate + 'T00:00:00');
            const timeDiff = end.getTime() - start.getTime();
            duration = Math.ceil(timeDiff / (1000 * 3600 * 24)) + 1;
            
            if (duration < 1) {
                duration = 1;
                end = start;
            }
        }
        
        if (previewStartDate) {
            previewStartDate.textContent = start.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }
        
        if (previewEndDate) {
            previewEndDate.textContent = end.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }
        
        if (previewDuration) {
            const durationText = duration === 1 ? '1 day' : `${duration} days`;
            previewDuration.textContent = durationText;
        }
        
        previewContainer.style.display = 'block';
    } else {
        previewContainer.style.display = 'none';
    }
}

// Philippine phone number validation with API simulation
async function validatePhoneNumber(input) {
    const phoneNumber = input.value.trim();
    const feedbackElement = document.getElementById('phoneValidationFeedback');
    
    if (!phoneNumber) {
        feedbackElement.style.display = 'none';
        input.classList.remove('valid', 'invalid');
        return;
    }
    
    // Show loading state
    feedbackElement.style.display = 'block';
    feedbackElement.className = 'phone-validation-feedback loading';
    feedbackElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Validating phone number...';
    
    // Simulate API delay
    await new Promise(resolve => setTimeout(resolve, 1000));
    
    // Client-side validation patterns for Philippine numbers
    const cleanNumber = phoneNumber.replace(/[^0-9]/g, '');
    
    const validPatterns = [
        /^(09\d{9})$/,           // 09XXXXXXXXX (mobile)
        /^(\+639\d{9})$/,        // +639XXXXXXXXX (mobile with country code) 
        /^(639\d{9})$/,          // 639XXXXXXXXX (mobile without +)
        /^(02\d{7,8})$/,         // 02XXXXXXX or 02XXXXXXXX (Manila landline)
        /^(032\d{7})$/,          // 032XXXXXXX (Cebu landline)
        /^(033\d{7})$/,          // 033XXXXXXX (Iloilo landline)
        /^(034\d{7})$/,          // 034XXXXXXX (Bacolod landline)
        /^(035\d{7})$/,          // 035XXXXXXX (Dumaguete landline)
        /^(036\d{7})$/,          // 036XXXXXXX (Kalibo landline)
        /^(038\d{7})$/,          // 038XXXXXXX (Tagbilaran landline)
        /^(045\d{7})$/,          // 045XXXXXXX (Cabanatuan landline)
        /^(046\d{7})$/,          // 046XXXXXXX (Batangas landline)
        /^(047\d{7})$/,          // 047XXXXXXX (Lipa landline)
        /^(048\d{7})$/,          // 048XXXXXXX (San Pablo landline)
        /^(049\d{7})$/,          // 049XXXXXXX (Los Baños landline)
        /^(063\d{7})$/,          // 063XXXXXXX (Davao landline)
        /^(078\d{7})$/,          // 078XXXXXXX (Cagayan de Oro landline)
        /^(082\d{7})$/,          // 082XXXXXXX (Davao landline alt)
        /^(083\d{7})$/,          // 083XXXXXXX (Butuan landline)
        /^(085\d{7})$/,          // 085XXXXXXX (Zamboanga landline)
        /^(088\d{7})$/,          // 088XXXXXXX (Cagayan de Oro landline alt)
    ];
    
    let isValid = false;
    let numberType = '';
    let formattedNumber = '';
    
    // Check against patterns
    for (const pattern of validPatterns) {
        if (pattern.test(cleanNumber)) {
            isValid = true;
            
            if (cleanNumber.startsWith('09') || cleanNumber.startsWith('639')) {
                numberType = 'Mobile';
                // Format mobile number
                if (cleanNumber.startsWith('09')) {
                    formattedNumber = cleanNumber.replace(/(\d{4})(\d{3})(\d{4})/, '$1 $2 $3');
                } else {
                    formattedNumber = '+63 ' + cleanNumber.substring(2).replace(/(\d{3})(\d{3})(\d{4})/, '$1 $2 $3');
                }
            } else {
                numberType = 'Landline';
                // Format landline number
                formattedNumber = cleanNumber.replace(/(\d{2,3})(\d{3})(\d{4})/, '$1 $2 $3');
            }
            break;
        }
    }
    
    // Simulate additional validation checks (carrier verification, etc.)
    if (isValid) {
        // Simulate carrier check
        await new Promise(resolve => setTimeout(resolve, 500));
        
        feedbackElement.className = 'phone-validation-feedback valid';
        feedbackElement.innerHTML = `
            <i class="fas fa-check-circle"></i>
            <div class="validation-details">
                <div class="validation-status">Valid Philippine ${numberType} Number</div>
                <div class="formatted-number">${formattedNumber}</div>
                <div class="validation-note">Number format verified</div>
            </div>
        `;
        input.classList.remove('invalid');
        input.classList.add('valid');
    } else {
        feedbackElement.className = 'phone-validation-feedback invalid';
        feedbackElement.innerHTML = `
            <i class="fas fa-times-circle"></i>
            <div class="validation-details">
                <div class="validation-status">Invalid Philippine Number</div>
                <div class="validation-suggestions">
                    <strong>Valid formats:</strong><br>
                    • Mobile: 09XX XXX XXXX or +63 9XX XXX XXXX<br>
                    • Landline: 032 XXX XXXX (Cebu), 02 XXXX XXXX (Manila)
                </div>
            </div>
        `;
        input.classList.remove('valid');
        input.classList.add('invalid');
    }
}

// Function to inject multi-day training styles
function injectMultiDayTrainingStyles() {
    const multiDayTrainingStyles = `
/* Multi-day training span styles */
.session-datetime {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}

.session-date-single .session-date {
    font-weight: 600;
    color: var(--dark);
}

.session-date-start {
    font-weight: 600;
    color: var(--dark);
    font-size: 0.9rem;
}

.session-date-end {
    font-size: 0.85rem;
    color: var(--gray);
    font-style: italic;
}

.session-time {
     font-size: 0.8rem;
    color: #2196F3;
    font-weight: 500;
    background: rgba(33, 150, 243, 0.1);
    padding: 0.1rem 0.3rem;
    border-radius: 4px;
    display: inline-block;
    margin: 0.1rem 0;
    border: 1px solid rgba(33, 150, 243, 0.2);
}

.session-duration {
    font-size: 0.75rem;
    background: rgba(33, 150, 243, 0.1);
    color: var(--blue);
    padding: 0.2rem 0.4rem;
    border-radius: 4px;
    display: inline-block;
    margin-top: 0.2rem;
    font-weight: 500;
    border: 1px solid rgba(33, 150, 243, 0.2);
}

.status-badge.ongoing {
    background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
    color: #856404;
    border: 1px solid #f7dc6f;
}

.status-badge.ongoing i {
    color: #ff9800;
}

.ongoing-badge {
    background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);
    color: white;
    padding: 0.2rem 0.5rem;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
    margin-top: 0.3rem;
    display: inline-block;
    animation: pulse 2s infinite;
}

/* Enhanced calendar styles for multi-day training */
.event-indicators {
    display: flex;
    flex-wrap: wrap;
    gap: 1px;
    margin-top: 2px;
    position: relative;
    z-index: 1;
}

.event-indicator {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    border: 1px solid white;
    box-shadow: 0 1px 2px rgba(0,0,0,0.2);
    transition: transform 0.2s ease;
}

.event-indicator.registered {
    border: 2px solid #4CAF50;
    box-shadow: 0 0 4px rgba(76, 175, 80, 0.4);
}

.event-count {
    font-size: 7px;
    background: rgba(0,0,0,0.6);
    color: white;
    padding: 1px 3px;
    border-radius: 3px;
    margin-top: 1px;
}

/* Large calendar event bars */
.event-display {
    display: flex;
    flex-direction: column;
    gap: 2px;
    margin-top: 4px;
}

.event-bar {
    height: 16px;
    border-radius: 3px;
    font-size: 10px;
    font-weight: 600;
    color: white;
    text-shadow: 0 1px 2px rgba(0,0,0,0.3);
    padding: 2px 4px;
    position: relative;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    background: var(--event-color, #607D8B);
    border: 1px solid rgba(255,255,255,0.2);
    line-height: 12px;
    transition: transform 0.2s ease;
}

.event-bar.registered {
    background: linear-gradient(45deg, var(--event-color, #607D8B) 0%, #4CAF50 100%);
    box-shadow: 0 0 6px rgba(76, 175, 80, 0.4);
}

/* Enhanced event dots */
.event-dots {
    display: flex;
    flex-wrap: wrap;
    gap: 2px;
    margin-top: 2px;
}

.event-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    border: 1px solid white;
    box-shadow: 0 1px 2px rgba(0,0,0,0.2);
    transition: transform 0.2s ease;
}

.event-dot.registered {
    border: 2px solid #4CAF50;
    box-shadow: 0 0 4px rgba(76, 175, 80, 0.4);
}

/* Registration indicator */
.registration-indicator {
    position: absolute;
    top: 2px;
    right: 2px;
    width: 12px;
    height: 12px;
    background: #4CAF50;
    color: white;
    border-radius: 50%;
    font-size: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    box-shadow: 0 1px 3px rgba(0,0,0,0.3);
    z-index: 2;
}

/* Enhanced calendar day cells */
.day-cell.has-events {
    border: 2px solid rgba(33, 150, 243, 0.3);
}

.day-cell.has-registered-event {
    border: 2px solid rgba(76, 175, 80, 0.5);
    background: linear-gradient(135deg, rgba(76, 175, 80, 0.05) 0%, rgba(76, 175, 80, 0.1) 100%);
}

.calendar-day.has-registered-event {
    border: 2px solid rgba(76, 175, 80, 0.5);
    background: linear-gradient(135deg, rgba(76, 175, 80, 0.05) 0%, rgba(76, 175, 80, 0.1) 100%);
}

/* Tooltip styles for multi-day training */
.tooltip-event-duration {
    color: #666;
    font-size: 0.85rem;
    margin: 2px 0;
    display: flex;
    align-items: center;
    gap: 4px;
}
/* Tooltip styles for multi-day training */
.tooltip-event-duration {
    color: #666;
    font-size: 0.85rem;
    margin: 2px 0;
    display: flex;
    align-items: center;
    gap: 4px;
}

.tooltip-event-time {
    color: #666;
    font-size: 0.8rem;
    margin: 2px 0;
    display: flex;
    align-items: center;
    gap: 4px;
}

.tooltip-event-service {
    color: #666;
    font-size: 0.8rem;
    margin: 2px 0;
    display: flex;
    align-items: center;
    gap: 4px;
}

/* Animation for spans */
.event-indicator, .event-bar {
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-3px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.7; }
    100% { opacity: 1; }
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .event-indicator {
        width: 5px;
        height: 5px;
    }
    
    .event-bar {
        height: 14px;
        font-size: 9px;
    }
    
    .event-dot {
        width: 6px;
        height: 6px;
    }
    
    .session-datetime {
        font-size: 0.8rem;
    }
    
    .session-duration {
        font-size: 0.7rem;
        padding: 0.1rem 0.3rem;
    }
}

@media (max-width: 576px) {
    .session-date-start,
    .session-date-end {
        font-size: 0.75rem;
    }
    
    .session-duration {
        font-size: 0.65rem;
    }
    
    .session-time {
        font-size: 0.75rem;
    }
}
    `;
    
    // Create and append style element
    const styleElement = document.createElement('style');
    styleElement.innerHTML = multiDayTrainingStyles;
    document.head.appendChild(styleElement);
}
// Training Programs Data
const trainingPrograms = {
    'Safety Service': [
        {
            code: 'EFAT',
            name: 'Emergency First Aid Training (EFAT)',
            description: 'Basic emergency first aid skills and techniques - 8 hours duration',
            duration: 8
        },
        {
            code: 'OFAT', 
            name: 'Occupational First Aid Training (OFAT)',
            description: 'Workplace-specific first aid training for occupational safety - 16 hours duration',
            duration: 16
        },
        {
            code: 'OTC',
            name: 'Occupational Training Course (OTC)', 
            description: 'Comprehensive occupational safety and health training - 24 hours duration',
            duration: 24
        }
    ],
    'Red Cross Youth': [
        {
            code: 'YVFC',
            name: 'Youth Volunteer Formation Course (YVFC)',
            description: 'Foundational training for youth volunteers in Red Cross principles - 12 hours duration',
            duration: 12
        },
        {
            code: 'LDP',
            name: 'Leadership Development Program (LDP)', 
            description: 'Advanced leadership skills for youth officers and coordinators - 20 hours duration',
            duration: 20
        },
        {
            code: 'AD',
            name: 'Advocacy Dissemination (AD)',
            description: 'Training on advocacy techniques and community outreach methods - 8 hours duration', 
            duration: 8
        }
    ]
};

// Open Training Request Modal
function openTrainingRequestModal() {
    const modal = document.getElementById('trainingRequestModal');
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        // Reset form
        const form = document.getElementById('trainingRequestForm');
        if (form) {
            form.reset();
        }
        
        // Reset form state
        const programSelect = document.getElementById('training_program');
        const programDescription = document.getElementById('program_description');
        const orgSection = document.getElementById('organization_section');
        const participantListSection = document.getElementById('participant_list_section');
        
        if (programSelect) {
            programSelect.disabled = true;
            programSelect.innerHTML = '<option value="">Select service type first</option>';
        }
        if (programDescription) {
            programDescription.style.display = 'none';
        }
        if (orgSection) {
            orgSection.style.display = 'none';
        }
        if (participantListSection) {
            participantListSection.style.display = 'none';
        }
        
        // Set default times
        setDefaultTimes();
        
        // Set minimum date to 1 week from now
        const nextWeek = new Date();
        nextWeek.setDate(nextWeek.getDate() + 7);
        const minDate = nextWeek.toISOString().split('T')[0];
        
        const startDateInput = document.getElementById('preferred_start_date');
        const endDateInput = document.getElementById('preferred_end_date');
        
        if (startDateInput) {
            startDateInput.min = minDate;
            startDateInput.value = '';
        }
        if (endDateInput) {
            endDateInput.min = minDate;
            endDateInput.value = '';
        }
        
        // Hide date preview
        const previewContainer = document.getElementById('requestDatePreviewContainer');
        if (previewContainer) {
            previewContainer.style.display = 'none';
        }
        
        // Reset file uploads
        const fileContainers = document.querySelectorAll('#trainingRequestModal .file-upload-container');
        fileContainers.forEach(container => {
            container.classList.remove('has-file');
            const input = container.querySelector('input[type="file"]');
            if (input) {
                input.value = '';
                const info = container.querySelector('.file-upload-info span');
                if (info) {
                    resetFileUploadInfo(input);
                }
            }
        });
        
        // Clear uploaded files list
        const filesList = document.getElementById('additional_docs_list');
        if (filesList) {
            filesList.innerHTML = '';
            filesList.classList.remove('has-files');
        }
        
        // Clear phone validation
        const phoneInput = document.getElementById('contact_number');
        const phoneValidation = document.getElementById('phoneValidationFeedback');
        if (phoneInput) {
            phoneInput.classList.remove('valid', 'invalid');
        }
        if (phoneValidation) {
            phoneValidation.style.display = 'none';
        }
        
        // Clear any previous errors
        document.querySelectorAll('#trainingRequestModal .form-group.error').forEach(group => {
            group.classList.remove('error');
            const errorMsg = group.querySelector('.error-message');
            if (errorMsg) errorMsg.remove();
        });
    }
}

// Set default times based on preference or specific times
function setDefaultTimes() {
    const startTimeInput = document.getElementById('preferred_start_time');
    const endTimeInput = document.getElementById('preferred_end_time');
    
    // Default times
    let startTime = '09:00';
    let endTime = '17:00';
    
    // Check if specific times were provided (when editing existing request)
    if (startTimeInput && startTimeInput.value) {
        startTime = startTimeInput.value;
    }
    if (endTimeInput && endTimeInput.value) {
        endTime = endTimeInput.value;
    }
    
    // Fallback to preference-based times if specific times not provided
    if ((!startTimeInput || !startTimeInput.value) && preferredTimeSelect && preferredTimeSelect.value) {
        const preferredTime = preferredTimeSelect.value;
        
        if (preferredTime === 'morning') {
            startTime = '09:00';
            endTime = '17:00';
        } else if (preferredTime === 'afternoon') {
            startTime = '13:00';
            endTime = '17:00';
        } else if (preferredTime === 'evening') {
            startTime = '18:00';
            endTime = '20:00';
        } else if (preferredTime === 'flexible') {
            startTime = '09:00';
            endTime = '17:00';
        }
    }
    
    // Set the time inputs
    if (startTimeInput) {
        startTimeInput.value = startTime;
    }
    if (endTimeInput) {
        endTimeInput.value = endTime;
    }
}

// Update times based on preferred time selection
function updateTimesBasedOnPreference() {
    const preferredTimeSelect = document.getElementById('preferred_time');
    const startTimeInput = document.getElementById('preferred_start_time');
    const endTimeInput = document.getElementById('preferred_end_time');
    
    if (!preferredTimeSelect || !startTimeInput || !endTimeInput) return;
    
    const preferredTime = preferredTimeSelect.value;
    
    // Only update if user hasn't manually set specific times
    const hasCustomStartTime = startTimeInput.value && startTimeInput.value !== '09:00';
    const hasCustomEndTime = endTimeInput.value && endTimeInput.value !== '17:00';
    
    if (!hasCustomStartTime || !hasCustomEndTime) {
        switch (preferredTime) {
            case 'morning':
                startTimeInput.value = '09:00';
                endTimeInput.value = '17:00';
                break;
            case 'afternoon':
                startTimeInput.value = '13:00';
                endTimeInput.value = '17:00';
                break;
            case 'evening':
                startTimeInput.value = '18:00';
                endTimeInput.value = '20:00';
                break;
            case 'flexible':
                startTimeInput.value = '09:00';
                endTimeInput.value = '17:00';
                break;
            default:
                startTimeInput.value = '09:00';
                endTimeInput.value = '17:00';
        }
    }
}

// Close Training Request Modal
function closeTrainingRequestModal() {
    const modal = document.getElementById('trainingRequestModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// Update Training Programs based on Service Type
function updateTrainingPrograms() {
    const serviceType = document.getElementById('service_type').value;
    const programSelect = document.getElementById('training_program');
    const programDescription = document.getElementById('program_description');
    
    // Clear program selection
    programSelect.innerHTML = '<option value="">Select training program</option>';
    programDescription.style.display = 'none';
    
    if (serviceType && trainingPrograms[serviceType]) {
        // Enable program selection
        programSelect.disabled = false;
        
        // Populate programs for selected service
        trainingPrograms[serviceType].forEach(program => {
            const option = document.createElement('option');
            option.value = program.code;
            option.textContent = program.name;
            option.dataset.description = program.description;
            option.dataset.duration = program.duration;
            programSelect.appendChild(option);
        });
    } else {
        // Disable program selection
        programSelect.disabled = true;
    }
    
    // Update organization section visibility
    toggleOrganizationSection();
}

// Enhanced form validation
function validateTrainingRequestForm() {
    const form = document.getElementById('trainingRequestForm');
    const formData = new FormData(form);
    const errors = [];
    
    // Basic field validation
    const requiredFields = {
        'service_type': 'Service Type',
        'training_program': 'Training Program',
        'contact_person': 'Contact Person',
        'contact_number': 'Contact Number',
        'email': 'Email Address'
    };
    
    Object.entries(requiredFields).forEach(([field, label]) => {
        if (!formData.get(field) || formData.get(field).trim() === '') {
            errors.push(`${label} is required.`);
        }
    });
    
    // Enhanced phone validation
    const phone = formData.get('contact_number');
    if (phone) {
        const cleanNumber = phone.replace(/[^0-9]/g, '');
        const validPatterns = [
            /^(09\d{9})$/,
            /^(\+639\d{9})$/,
            /^(639\d{9})$/,
            /^(02\d{7,8})$/,
            /^(032\d{7})$/,
            /^(033\d{7})$/,
            /^(034\d{7})$/,
            /^(035\d{7})$/,
            /^(036\d{7})$/,
            /^(038\d{7})$/,
            /^(045\d{7})$/,
            /^(046\d{7})$/,
            /^(047\d{7})$/,
            /^(048\d{7})$/,
            /^(049\d{7})$/,
            /^(063\d{7})$/,
            /^(078\d{7})$/,
            /^(082\d{7})$/,
            /^(083\d{7})$/,
            /^(085\d{7})$/,
            /^(088\d{7})$/
        ];
        
        let phoneValid = false;
        for (const pattern of validPatterns) {
            if (pattern.test(cleanNumber)) {
                phoneValid = true;
                break;
            }
        }
        
        if (!phoneValid) {
            errors.push('Please enter a valid Philippine phone number.');
        }
    }
    
    // Email validation
    const email = formData.get('email');
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        errors.push('Please enter a valid email address.');
    }
    
    // Participant count validation
    const participantCount = parseInt(formData.get('participant_count')) || 0;
    if (participantCount < 1 || participantCount > 100) {
        errors.push('Participant count must be between 1 and 100.');
    }
    
    // Organization name required for large groups
    if (participantCount >= 5 && !formData.get('organization_name')) {
        errors.push('Organization name is required for groups of 5 or more participants.');
    }
    
    // Date validation
    const startDate = formData.get('preferred_start_date');
    const endDate = formData.get('preferred_end_date');
    
    if (startDate) {
        const start = new Date(startDate);
        const nextWeek = new Date();
        nextWeek.setDate(nextWeek.getDate() + 7);
        
        if (start < nextWeek) {
            errors.push('Preferred start date must be at least 1 week from now.');
        }
        
        if (endDate) {
            const end = new Date(endDate);
            if (end < start) {
                errors.push('End date cannot be before start date.');
            }
            
            const duration = Math.ceil((end - start) / (1000 * 60 * 60 * 24)) + 1;
            if (duration > 365) {
                errors.push('Training duration cannot exceed 365 days.');
            }
        }
    }
    
    // Time validation
    const startTime = formData.get('preferred_start_time');
    const endTime = formData.get('preferred_end_time');
    
    if (startTime && endTime) {
        const start = new Date('1970-01-01T' + startTime);
        const end = new Date('1970-01-01T' + endTime);
        
        if (end <= start) {
            errors.push('End time must be after start time.');
        }
    }
    
    // File validation
    const validIdFile = document.getElementById('valid_id_request').files[0];
    if (!validIdFile) {
        errors.push('Valid ID upload is required.');
    }
    
    const participantListFile = document.getElementById('participant_list').files[0];
    if (participantCount >= 5 && !participantListFile) {
        errors.push('Participant list is required for groups of 5 or more participants.');
    }
    
    return errors;
}

// Helper function to show field errors
function showFieldError(fieldId, message) {
    const field = document.getElementById(fieldId);
    if (field) {
        const formGroup = field.closest('.form-group');
        if (formGroup) {
            formGroup.classList.add('error');
            
            const errorElement = document.createElement('div');
            errorElement.className = 'error-message';
            errorElement.textContent = message;
            formGroup.appendChild(errorElement);
        }
    }
}

// Initialize event listeners when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Training program change listener
    const trainingProgramSelect = document.getElementById('training_program');
    if (trainingProgramSelect) {
        trainingProgramSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const programDescription = document.getElementById('program_description');
            const descriptionText = programDescription.querySelector('small');
            
            if (selectedOption.dataset.description) {
                descriptionText.textContent = selectedOption.dataset.description;
                programDescription.style.display = 'block';
            } else {
                programDescription.style.display = 'none';
            }
        });
    }

    // Participant count change listener
    const participantCountInput = document.getElementById('participant_count');
    if (participantCountInput) {
        participantCountInput.addEventListener('input', function() {
            toggleOrganizationSection();
        });
    }

    // Preferred time change listener
    const preferredTimeSelect = document.getElementById('preferred_time');
    if (preferredTimeSelect) {
        preferredTimeSelect.addEventListener('change', function() {
            updateTimesBasedOnPreference();
        });
    }

    // Form submission handler
    const trainingRequestForm = document.getElementById('trainingRequestForm');
    if (trainingRequestForm) {
        trainingRequestForm.addEventListener('submit', function(e) {
            const errors = validateTrainingRequestForm();
            
            if (errors.length > 0) {
                e.preventDefault();
                alert('Please fix the following errors:\n\n' + errors.join('\n'));
                return false;
            }
            
            // Check phone validation status
            const phoneInput = document.getElementById('contact_number');
            if (phoneInput && phoneInput.classList.contains('invalid')) {
                e.preventDefault();
                alert('Please enter a valid Philippine phone number before submitting.');
                phoneInput.focus();
                return false;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('.btn-submit');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting Request...';
                submitBtn.disabled = true;
            }
            
            return true;
        });
    }

    // Close modal when clicking outside
    const trainingRequestModal = document.getElementById('trainingRequestModal');
    if (trainingRequestModal) {
        trainingRequestModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeTrainingRequestModal();
            }
        });
    }

    // Keyboard navigation
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('trainingRequestModal');
            if (modal && modal.classList.contains('active')) {
                closeTrainingRequestModal();
            }
        }
    });
});