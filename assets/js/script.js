/**
 * Umsad Tech application interactions.
 * Kept framework-free so the core experience survives a third-party CDN issue.
 */
(function () {
    'use strict';

    const appMeta = document.querySelector('meta[name="app-url"]');
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const baseUrl = (appMeta?.content || '').replace(/\/$/, '');
    const csrfToken = csrfMeta?.content || '';

    document.addEventListener('DOMContentLoaded', () => {
        initializeTooltips();
        initializeFormValidation();
        initializeScrollAnimations();
        initializeNavbar();
        initializeAutoDismissAlerts();
        initializeCourseAnnouncement();
    });

    function initializeTooltips() {
        if (!window.bootstrap?.Tooltip) return;
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(element => {
            new window.bootstrap.Tooltip(element);
        });
    }

    function initializeFormValidation() {
        document.querySelectorAll('form[novalidate]').forEach(form => {
            form.addEventListener('submit', event => {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                    const firstInvalid = form.querySelector(':invalid');
                    firstInvalid?.focus({ preventScroll: true });
                    firstInvalid?.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'center' });
                }
                form.classList.add('was-validated');
            });
        });
    }

    function initializeScrollAnimations() {
        const items = document.querySelectorAll('.reveal');
        if (!items.length) return;

        if (prefersReducedMotion() || !('IntersectionObserver' in window)) {
            items.forEach(item => item.classList.add('is-visible'));
            return;
        }

        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -24px' });

        items.forEach(item => observer.observe(item));
    }

    function initializeNavbar() {
        const navbar = document.querySelector('.site-navbar');
        if (!navbar) return;

        const sync = () => navbar.classList.toggle('is-scrolled', window.scrollY > 16);
        sync();
        window.addEventListener('scroll', throttle(sync, 80), { passive: true });

        document.querySelectorAll('.site-navbar .nav-link').forEach(link => {
            const target = new URL(link.href, window.location.href);
            if (target.pathname === window.location.pathname) {
                link.setAttribute('aria-current', 'page');
                link.classList.add('active');
            }
        });
    }

    function initializeAutoDismissAlerts() {
        document.querySelectorAll('[data-auto-dismiss]').forEach(alert => {
            window.setTimeout(() => {
                if (window.bootstrap?.Alert) {
                    window.bootstrap.Alert.getOrCreateInstance(alert).close();
                } else {
                    alert.remove();
                }
            }, Number(alert.dataset.autoDismiss) || 5000);
        });
    }

    function initializeCourseAnnouncement() {
        const element = document.getElementById('courseAnnouncementModal');
        if (!element || !window.bootstrap?.Modal) return;

        const storageKey = element.dataset.announcementKey || 'umsad.courseAnnouncement.v1';
        try {
            if (window.localStorage.getItem(storageKey) === 'seen') return;
        } catch (error) {
            // Private browsing/storage restrictions should not hide the course.
        }

        window.setTimeout(() => {
            try {
                window.bootstrap.Modal.getOrCreateInstance(element).show();
                try {
                    window.localStorage.setItem(storageKey, 'seen');
                } catch (error) {
                    // The modal still works when persistent storage is blocked.
                }
            } catch (error) {
                // Leave the rest of the homepage fully usable if Bootstrap fails.
            }
        }, prefersReducedMotion() ? 0 : 280);
    }

    function prefersReducedMotion() {
        return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    }

    function showMessage(message, type = 'success') {
        const host = document.querySelector('.flash-region') || document.querySelector('.main-content') || document.body;
        const alert = document.createElement('div');
        alert.className = 'app-toast alert alert-' + type;
        alert.setAttribute('role', type === 'danger' ? 'alert' : 'status');

        const icon = document.createElement('i');
        icon.className = type === 'success'
            ? 'fas fa-circle-check'
            : type === 'warning' ? 'fas fa-triangle-exclamation' : 'fas fa-circle-exclamation';
        icon.setAttribute('aria-hidden', 'true');

        const copy = document.createElement('span');
        copy.textContent = String(message);

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close';
        close.setAttribute('aria-label', 'Dismiss message');
        close.addEventListener('click', () => alert.remove());

        alert.append(icon, copy, close);
        host.prepend(alert);
        requestAnimationFrame(() => alert.classList.add('is-visible'));
        window.setTimeout(() => {
            alert.classList.remove('is-visible');
            window.setTimeout(() => alert.remove(), 220);
        }, 5200);
    }

    const showSuccess = message => showMessage(message, 'success');
    const showError = message => showMessage(message, 'danger');
    const showWarning = message => showMessage(message, 'warning');

    async function apiRequest(path, options = {}) {
        const headers = new Headers(options.headers || {});
        headers.set('Accept', 'application/json');
        if (csrfToken) headers.set('X-CSRF-Token', csrfToken);

        let body = options.body;
        if (body && !(body instanceof FormData) && typeof body !== 'string') {
            headers.set('Content-Type', 'application/json');
            body = JSON.stringify(body);
        }

        const response = await fetch(/^https?:/.test(path) ? path : baseUrl + path, {
            ...options,
            body,
            headers,
            credentials: 'same-origin'
        });

        let result;
        try {
            result = await response.json();
        } catch (error) {
            throw new Error('The server returned an unexpected response.');
        }

        if (!response.ok || result.success === false) {
            throw new Error(result.message || 'The request could not be completed.');
        }
        return result;
    }

    function makeRequest(url, method = 'GET', data = null, callback = null) {
        return apiRequest(url, {
            method,
            body: method === 'GET' ? null : data
        }).then(response => {
            if (callback) callback(response);
            return response;
        }).catch(error => {
            showError(error.message);
            throw error;
        });
    }

    function togglePasswordVisibility(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon = document.getElementById(iconId);
        if (!input) return;

        const reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        if (icon) {
            icon.classList.toggle('fa-eye', !reveal);
            icon.classList.toggle('fa-eye-slash', reveal);
        }

        const button = input.parentElement?.querySelector('.password-toggle');
        button?.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
    }

    function formatCurrency(amount) {
        return new Intl.NumberFormat('en-NG', {
            style: 'currency',
            currency: 'NGN',
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        }).format(Number(amount) || 0);
    }

    function formatDate(date) {
        const value = new Date(date);
        return Number.isNaN(value.getTime())
            ? ''
            : new Intl.DateTimeFormat('en-NG', { day: 'numeric', month: 'short', year: 'numeric' }).format(value);
    }

    function getQueryParam(param) {
        return new URLSearchParams(window.location.search).get(param);
    }

    function debounce(func, delay = 250) {
        let timeoutId;
        return function (...args) {
            window.clearTimeout(timeoutId);
            timeoutId = window.setTimeout(() => func.apply(this, args), delay);
        };
    }

    function throttle(func, limit = 100) {
        let waiting = false;
        return function (...args) {
            if (waiting) return;
            func.apply(this, args);
            waiting = true;
            window.setTimeout(() => { waiting = false; }, limit);
        };
    }

    async function copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(String(text));
            showSuccess('Copied to clipboard.');
        } catch (error) {
            showError('Unable to copy to your clipboard.');
        }
    }

    const Storage = {
        set(key, value) {
            localStorage.setItem(key, JSON.stringify(value));
        },
        get(key) {
            const value = localStorage.getItem(key);
            if (!value) return null;
            try {
                return JSON.parse(value);
            } catch (error) {
                return null;
            }
        },
        remove(key) {
            localStorage.removeItem(key);
        },
        clear() {
            localStorage.clear();
        }
    };

    window.togglePasswordVisibility = togglePasswordVisibility;
    window.UmsadTechHelper = {
        apiRequest,
        makeRequest,
        showSuccess,
        showError,
        showWarning,
        formatCurrency,
        formatDate,
        getQueryParam,
        copyToClipboard,
        Storage
    };
})();
