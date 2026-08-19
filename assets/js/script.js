/**
 * Umsad Tech E-Learning Platform
 * Custom JavaScript
 */

// Document Ready
$(document).ready(function() {
    initializeTooltips();
    initializeFormValidation();
    initializeScrollAnimations();
});

/** Reveal homepage content as it enters the viewport. */
function initializeScrollAnimations() {
    const items = document.querySelectorAll('.reveal');
    if (!items.length) return;

    if (!('IntersectionObserver' in window)) {
        items.forEach(item => item.classList.add('is-visible'));
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.14 });

    items.forEach(item => observer.observe(item));
}

/**
 * Initialize Bootstrap Tooltips
 */
function initializeTooltips() {
    $('[data-bs-toggle="tooltip"]').each(function() {
        new bootstrap.Tooltip(this);
    });
}

/**
 * Initialize Form Validation
 */
function initializeFormValidation() {
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    });
}

/**
 * Show Success Message
 */
function showSuccess(message) {
    const alertHtml = `
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    $(alertHtml).prependTo('.main-content').delay(5000).fadeOut('slow');
}

/**
 * Show Error Message
 */
function showError(message) {
    const alertHtml = `
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    $(alertHtml).prependTo('.main-content').delay(5000).fadeOut('slow');
}

/**
 * Show Warning Message
 */
function showWarning(message) {
    const alertHtml = `
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    $(alertHtml).prependTo('.main-content').delay(5000).fadeOut('slow');
}

/**
 * AJAX Request Helper
 */
function makeRequest(url, method = 'GET', data = null, callback = null) {
    $.ajax({
        url: url,
        type: method,
        data: data,
        dataType: 'json',
        success: function(response) {
            if (callback) {
                callback(response);
            }
        },
        error: function(xhr, status, error) {
            showError('An error occurred: ' + error);
        }
    });
}

/**
 * Confirm Dialog
 */
function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

/**
 * Format Currency
 */
function formatCurrency(amount) {
    return '₦' + parseFloat(amount).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

/**
 * Format Date
 */
function formatDate(date, format = 'DD/MM/YYYY') {
    const d = new Date(date);
    const day = String(d.getDate()).padStart(2, '0');
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const year = d.getFullYear();
    
    switch (format) {
        case 'DD/MM/YYYY':
            return `${day}/${month}/${year}`;
        case 'YYYY-MM-DD':
            return `${year}-${month}-${day}`;
        default:
            return d.toString();
    }
}

/**
 * Get Query Parameter
 */
function getQueryParam(param) {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get(param);
}

/**
 * Calculate Reading Time
 */
function calculateReadingTime(text, wordsPerMinute = 200) {
    const words = text.trim().split(/\s+/).length;
    const minutes = Math.ceil(words / wordsPerMinute);
    return minutes;
}

/**
 * Toggle Password Visibility
 */
function togglePasswordVisibility(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    }
}

/**
 * Debounce Function
 */
function debounce(func, delay) {
    let timeoutId;
    return function(...args) {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => func.apply(this, args), delay);
    };
}

/**
 * Throttle Function
 */
function throttle(func, limit) {
    let inThrottle;
    return function(...args) {
        if (!inThrottle) {
            func.apply(this, args);
            inThrottle = true;
            setTimeout(() => inThrottle = false, limit);
        }
    };
}

/**
 * Local Storage Helper
 */
const Storage = {
    set: function(key, value) {
        localStorage.setItem(key, JSON.stringify(value));
    },
    get: function(key) {
        const item = localStorage.getItem(key);
        return item ? JSON.parse(item) : null;
    },
    remove: function(key) {
        localStorage.removeItem(key);
    },
    clear: function() {
        localStorage.clear();
    }
};

/**
 * Initialize Video Player with Custom Controls
 */
function initializeVideoPlayer(videoId) {
    const video = document.getElementById(videoId);
    if (!video) return;
    
    video.addEventListener('play', function() {
        recordVideoProgress(videoId, 'play');
    });
    
    video.addEventListener('pause', function() {
        recordVideoProgress(videoId, 'pause');
    });
    
    video.addEventListener('ended', function() {
        recordVideoProgress(videoId, 'completed');
    });
}

/**
 * Record Video Progress (AJAX)
 */
function recordVideoProgress(videoId, action) {
    makeRequest('/api/track-video.php', 'POST', {
        video_id: videoId,
        action: action,
        timestamp: new Date().getTime()
    });
}

/**
 * Initialize Paystack Payment
 */
function initializePaystackPayment(publicKey, email, amount, reference) {
    const handler = PaystackPop.setup({
        key: publicKey,
        email: email,
        amount: amount * 100, // Paystack uses kobo
        ref: reference,
        onClose: function() {
            showWarning('Payment window closed.');
        },
        onSuccess: function(response) {
            verifyPaystackPayment(reference);
        }
    });
    handler.openIframe();
}

/**
 * Verify Paystack Payment
 */
function verifyPaystackPayment(reference) {
    makeRequest('/api/verify-payment.php', 'POST', {
        reference: reference
    }, function(response) {
        if (response.success) {
            showSuccess(response.message);
            setTimeout(() => {
                window.location.href = response.redirect || '/student/my-courses.php';
            }, 2000);
        } else {
            showError(response.message);
        }
    });
}

/**
 * Initialize Drag & Drop File Upload
 */
function initializeDragDropUpload(dropZoneId, fileInputId) {
    const dropZone = document.getElementById(dropZoneId);
    const fileInput = document.getElementById(fileInputId);
    
    if (!dropZone || !fileInput) return;
    
    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.classList.add('drag-over');
    });
    
    dropZone.addEventListener('dragleave', () => {
        dropZone.classList.remove('drag-over');
    });
    
    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('drag-over');
        fileInput.files = e.dataTransfer.files;
        fileInput.dispatchEvent(new Event('change'));
    });
}

/**
 * Show Loading Spinner
 */
function showSpinner(containerId) {
    const container = document.getElementById(containerId);
    if (container) {
        container.innerHTML = '<div class="spinner"></div>';
    }
}

/**
 * Initialize Data Table with Search
 */
function initializeDataTable(tableId) {
    const table = document.getElementById(tableId);
    if (!table) return;
    
    const rows = table.querySelectorAll('tbody tr');
    const searchInput = document.querySelector(`[data-table-search="${tableId}"]`);
    
    if (searchInput) {
        searchInput.addEventListener('keyup', debounce(function() {
            const searchTerm = this.value.toLowerCase();
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        }, 300));
    }
}

/**
 * Copy to Clipboard
 */
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showSuccess('Copied to clipboard!');
    }).catch(err => {
        showError('Failed to copy');
    });
}

/**
 * Rate Limiting Function
 */
function rateLimit(func, interval) {
    let lastCall = 0;
    return function(...args) {
        const now = Date.now();
        if (now - lastCall >= interval) {
            lastCall = now;
            return func.apply(this, args);
        }
    };
}

// Export for use in other scripts
window.UmsadTechHelper = {
    showSuccess,
    showError,
    showWarning,
    makeRequest,
    formatCurrency,
    formatDate,
    getQueryParam,
    Storage,
    initializePaystackPayment,
    verifyPaystackPayment
};
