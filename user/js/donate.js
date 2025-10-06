document.addEventListener("DOMContentLoaded", function () {
  console.log("Donation form initialized");

  // Cache DOM elements
  const tabButtons = document.querySelectorAll(".tab-button");
  const donationFields = document.querySelectorAll(".donation-fields");
  const donationTypeInput = document.getElementById("donation_type");
  const donationForm = document.querySelector(".donation-form");
  const amountInput = document.getElementById("amount");

  // Verify critical elements exist
  if (!donationForm) {
    console.error("Donation form not found!");
    return;
  }

  console.log(
    `Found ${tabButtons.length} tab buttons, ${donationFields.length} donation fields`
  );

  // Tab switching functionality - FIXED
  tabButtons.forEach((button) => {
    button.addEventListener("click", function (e) {
      e.preventDefault();
      const targetTab = this.getAttribute("data-tab");
      console.log("Switching to tab:", targetTab);

      // Remove active class from all buttons
      tabButtons.forEach((btn) => btn.classList.remove("active"));

      // Hide all donation fields
      donationFields.forEach((field) => {
        field.classList.add("hidden");
        field.style.display = "none";
      });

      // Add active class to clicked button
      this.classList.add("active");

      // Show corresponding field
      const targetField = document.getElementById(targetTab + "-fields");
      if (targetField) {
        targetField.classList.remove("hidden");
        targetField.style.display = "block";
        console.log(`Showing ${targetTab} fields`);
      }

      // Update hidden input
      if (donationTypeInput) {
        donationTypeInput.value = targetTab;
        console.log("Donation type updated to:", targetTab);
      }

      // Update required fields
      updateRequiredFields(targetTab);

      // Handle payment method requirements
      if (targetTab === "monetary") {
        initializePaymentMethods();
      }
    });
  });

  // Initialize payment methods
  initializePaymentMethods();

  // Amount input listener for updating fee summary
  if (amountInput) {
    amountInput.addEventListener("input", updateFeeSummary);
  }

  // Form submission handling - FIXED (removed duplicate)
  donationForm.addEventListener("submit", function (e) {
    console.log("Form submission attempted");

    const currentTab = donationTypeInput.value;
    console.log("Current tab for validation:", currentTab);

    let isValid = true;
    let errorMessage = "";

    // Validate common fields
    const donorName = document.getElementById("donor_name");
    const donorEmail = document.getElementById("donor_email");
    const donorPhone = document.getElementById("donor_phone");

    if (!donorName.value.trim()) {
      isValid = false;
      errorMessage = "Please enter your name";
      donorName.focus();
    } else if (!donorEmail.value.trim() || !donorEmail.value.includes("@")) {
      isValid = false;
      errorMessage = "Please enter a valid email address";
      donorEmail.focus();
    } else if (!donorPhone.value.trim()) {
      isValid = false;
      errorMessage = "Please enter your phone number";
      donorPhone.focus();
    }

    // Validate tab-specific fields
    if (isValid && currentTab === "monetary") {
      const amount = document.getElementById("amount");
      const donationDate = document.getElementById("donation_date_monetary");
      const paymentMethod = document.querySelector(
        'input[name="payment_method"]:checked'
      );

      if (!amount.value || parseFloat(amount.value) <= 0) {
        isValid = false;
        errorMessage = "Please enter a valid donation amount";
        amount.focus();
      } else if (!donationDate.value) {
        isValid = false;
        errorMessage = "Please select a donation date";
        donationDate.focus();
      } else if (!paymentMethod) {
        isValid = false;
        errorMessage = "Please select a payment method";
        const firstPaymentOption = document.querySelector(
          'input[name="payment_method"]'
        );
        if (firstPaymentOption) firstPaymentOption.focus();
      }
    }

    if (isValid && currentTab === "inkind") {
      const itemDescription = document.getElementById("item_description");
      const quantity = document.getElementById("quantity");
      const donationDate = document.getElementById("donation_date_inkind");

      if (!itemDescription.value.trim()) {
        isValid = false;
        errorMessage = "Please enter an item description";
        itemDescription.focus();
      } else if (!quantity.value || parseInt(quantity.value) <= 0) {
        isValid = false;
        errorMessage = "Please enter a valid quantity";
        quantity.focus();
      } else if (!donationDate.value) {
        isValid = false;
        errorMessage = "Please select a donation date";
        donationDate.focus();
      }
    }

    if (!isValid) {
      e.preventDefault();
      alert(errorMessage);
      console.log("Form validation failed:", errorMessage);
      return false;
    }

    console.log("Form validation passed - submitting");
    return true;
  });

  // Auto-hide alerts
  setTimeout(() => {
    document.querySelectorAll(".alert").forEach((alert) => {
      if (alert) {
        alert.style.transition = "opacity 0.3s ease";
        alert.style.opacity = "0";
        setTimeout(() => {
          if (alert.parentNode) {
            alert.remove();
          }
        }, 300);
      }
    });
  }, 8000);

  // Initialize fee summary
  updateFeeSummary();

  // Sync date fields
  syncDateFields();

  // Initialize required fields for current tab
  updateRequiredFields(donationTypeInput.value);
});

function updateRequiredFields(activeTab) {
  console.log("Updating required fields for:", activeTab);

  // Remove required from all tab-specific fields
  document
    .querySelectorAll(".monetary-required, .inkind-required")
    .forEach((field) => {
      field.required = false;
    });

  // Add required only to active tab fields
  if (activeTab === "monetary") {
    document.querySelectorAll(".monetary-required").forEach((field) => {
      field.required = true;
    });

    // Handle payment method requirement separately
    const paymentMethods = document.querySelectorAll(
      'input[name="payment_method"]'
    );
    paymentMethods.forEach((method) => {
      method.required = true;
    });
  } else if (activeTab === "inkind") {
    document.querySelectorAll(".inkind-required").forEach((field) => {
      field.required = true;
    });

    // Remove payment method requirement for in-kind
    const paymentMethods = document.querySelectorAll(
      'input[name="payment_method"]'
    );
    paymentMethods.forEach((method) => {
      method.required = false;
      method.checked = false;
    });
  }
}

function initializePaymentMethods() {
  console.log("Initializing payment methods");

  const paymentMethods = document.querySelectorAll(
    'input[name="payment_method"]'
  );
  paymentMethods.forEach((method) => {
    method.addEventListener("change", function () {
      updatePaymentReceipt();
      updatePaymentSummary();
    });
  });

  // File upload handling
  const paymentReceipt = document.getElementById("payment_receipt");
  if (paymentReceipt) {
    paymentReceipt.addEventListener("change", function () {
      handleFileUpload(this);
    });
  }

  // Initialize payment receipt visibility
  updatePaymentReceipt();
}

function updatePaymentReceipt() {
  const selectedMethod = document.querySelector(
    'input[name="payment_method"]:checked'
  );
  const receiptUpload = document.getElementById("receiptUpload");

  if (selectedMethod) {
    console.log("Payment method selected:", selectedMethod.value);

    // Show payment details for selected method
    document.querySelectorAll(".payment-form").forEach((form) => {
      form.style.display = "none";
    });

    const selectedForm = document.getElementById(
      selectedMethod.value + "_form"
    );
    if (selectedForm) {
      selectedForm.style.display = "block";
    }

    if (selectedMethod.value !== "cash") {
      if (receiptUpload) {
        receiptUpload.style.display = "block";
      }
    } else {
      if (receiptUpload) {
        receiptUpload.style.display = "none";
      }
    }

    // Show payment summary
    const paymentSummary = document.getElementById("paymentSummary");
    if (paymentSummary) {
      paymentSummary.style.display = "block";
    }

    updatePaymentSummary();
  } else {
    // Hide all payment details if no method selected
    document.querySelectorAll(".payment-form").forEach((form) => {
      form.style.display = "none";
    });

    const receiptUpload = document.getElementById("receiptUpload");
    if (receiptUpload) {
      receiptUpload.style.display = "none";
    }

    const paymentSummary = document.getElementById("paymentSummary");
    if (paymentSummary) {
      paymentSummary.style.display = "none";
    }
  }
}

function updateFeeSummary() {
  const amount = parseFloat(document.getElementById("amount")?.value) || 0;
  const donationAmountDisplay = document.getElementById(
    "donationAmountDisplay"
  );
  const totalAmountDisplay = document.getElementById("totalAmountDisplay");

  if (donationAmountDisplay) {
    donationAmountDisplay.textContent = `₱${amount.toFixed(2)}`;
  }

  if (totalAmountDisplay) {
    totalAmountDisplay.textContent = `₱${amount.toFixed(2)}`;
  }

  updatePaymentSummary();
}

function updatePaymentSummary() {
  const amount = parseFloat(document.getElementById("amount")?.value) || 0;
  const selectedMethod = document.querySelector(
    'input[name="payment_method"]:checked'
  );
  const selectedMethodDisplay = document.getElementById(
    "selectedPaymentMethod"
  );
  const summaryDonationAmount = document.getElementById(
    "summaryDonationAmount"
  );
  const summaryTotalAmount = document.getElementById("summaryTotalAmount");

  if (selectedMethodDisplay && selectedMethod) {
    selectedMethodDisplay.textContent = selectedMethod.value
      .replace("_", " ")
      .toUpperCase();
  }

  if (summaryDonationAmount) {
    summaryDonationAmount.textContent = `₱${amount.toFixed(2)}`;
  }

  if (summaryTotalAmount) {
    let totalAmount = amount;

    // Add processing fee for credit card
    if (selectedMethod && selectedMethod.value === "credit_card") {
      const processingFee = amount * 0.035;
      totalAmount += processingFee;
    }

    summaryTotalAmount.textContent = `₱${totalAmount.toFixed(2)}`;
  }
}

function handleFileUpload(input) {
  const file = input.files[0];
  const container = input.closest(".receipt-upload");
  const uploadText = container ? container.querySelector(".upload-text") : null;

  if (!container || !uploadText) return;

  if (file) {
    console.log("File selected:", file.name);

    // Check file size (5MB limit)
    const maxSize = 5 * 1024 * 1024;
    if (file.size > maxSize) {
      alert("File size too large. Maximum allowed: 5MB");
      input.value = "";
      return;
    }

    // Check file type
    const allowedTypes = [
      "image/jpeg",
      "image/jpg",
      "image/png",
      "application/pdf",
    ];
    if (!allowedTypes.includes(file.type)) {
      alert("Invalid file type. Please upload JPG, PNG, or PDF files only.");
      input.value = "";
      return;
    }

    container.classList.add("has-file");
    uploadText.textContent = `Selected: ${file.name}`;
  } else {
    container.classList.remove("has-file");
    uploadText.textContent = "Upload Payment Receipt";
  }
}

function syncDateFields() {
  const monetaryDate = document.getElementById("donation_date_monetary");
  const inkindDate = document.getElementById("donation_date_inkind");

  if (monetaryDate && inkindDate) {
    // When monetary date changes, update inkind date
    monetaryDate.addEventListener("change", function () {
      inkindDate.value = this.value;
    });

    // When inkind date changes, update monetary date
    inkindDate.addEventListener("change", function () {
      monetaryDate.value = this.value;
    });

    // Initialize with current date if empty
    const currentDate = new Date().toISOString().split("T")[0];
    if (!monetaryDate.value) monetaryDate.value = currentDate;
    if (!inkindDate.value) inkindDate.value = currentDate;
  }
}

// Debug functions
window.debugDonationForm = function () {
  const form = document.querySelector(".donation-form");
  const formData = new FormData(form);
  console.log("=== FORM DEBUG ===");
  console.log("Form method:", form.method);
  console.log("Form action:", form.action);
  console.log("Form data:");
  for (let [key, value] of formData.entries()) {
    console.log(`${key}:`, value);
  }
  console.log("=== END DEBUG ===");
};

window.debugFormState = function () {
  console.log("=== FORM STATE DEBUG ===");
  console.log("Active tab:", document.getElementById("donation_type").value);

  // Check required fields
  const requiredFields = document.querySelectorAll("[required]");
  console.log("Required fields:");
  requiredFields.forEach((field) => {
    console.log(
      `${field.name}: ${field.value} (visible: ${field.offsetParent !== null})`
    );
  });

  // Check payment method
  const paymentMethod = document.querySelector(
    'input[name="payment_method"]:checked'
  );
  console.log(
    "Payment method selected:",
    paymentMethod ? paymentMethod.value : "NONE"
  );

  console.log("=== END DEBUG ===");
};
