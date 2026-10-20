/**
 * EVORA HRMS — Core Interactive Engine & API Client
 * College Web Development Project
 * Strictly Vanilla JavaScript (ES6+)
 */

const EVORA = {
  /**
   * Dynamically resolves the backend API base URL relative to document location
   */
  get apiBase() {
    const path = window.location.pathname;
    const frontendIdx = path.indexOf('/frontend');
    if (frontendIdx !== -1) {
      return path.substring(0, frontendIdx) + '/backend';
    }
    return '/backend';
  },

  /**
   * Universal fetch client with CSRF token management and error handling
   */
  async api(endpoint, options = {}) {
    let cleanEndpoint = endpoint.startsWith('/') ? endpoint : `/${endpoint}`;
    if (cleanEndpoint.startsWith('/backend/')) {
      cleanEndpoint = cleanEndpoint.substring(8);
    }
    const url = `${this.apiBase}${cleanEndpoint}`;
    const method = (options.method || 'GET').toUpperCase();
    const headers = Object.assign({}, options.headers || {});

    headers['Accept'] = 'application/json';

    if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
      const csrfToken = sessionStorage.getItem('evora_csrf_token');
      if (csrfToken && !headers['X-CSRF-Token']) {
        headers['X-CSRF-Token'] = csrfToken;
      }
      if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(options.body);
      } else if (typeof options.body === 'string' && !headers['Content-Type']) {
        headers['Content-Type'] = 'application/json';
      }
    }

    const fetchOptions = {
      method,
      headers,
      credentials: 'same-origin',
      ...options,
    };

    try {
      const res = await fetch(url, fetchOptions);
      const data = await res.json().catch(() => null);

      if (res.status === 401) {
        this.setCurrentUser(null);
        const isSubdir = window.location.pathname.includes('/pages/admin/') || window.location.pathname.includes('/pages/employee/');
        window.location.replace(isSubdir ? '../login.html' : 'login.html');
        return { ok: false, success: false, status: 401, message: 'Authentication required' };
      }

      return {
        ok: res.ok,
        status: res.status,
        data: data || {},
        ...(data || {}),
      };
    } catch (err) {
      console.error(`API Error [${method} ${endpoint}]:`, err);
      return {
        ok: false,
        status: 0,
        success: false,
        message: 'Unable to connect to the server. Please verify your connection.',
      };
    }
  },

  /**
   * XSS protection helper for dynamic HTML rendering
   */
  escapeHtml(str) {
    if (str === null || str === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
  },

  /**
   * Format 24-hr time string (HH:MM:SS) to 12-hr with AM/PM
   */
  formatTime(timeStr) {
    if (!timeStr) return '—';
    const parts = timeStr.split(':');
    if (parts.length < 2) return timeStr;
    const hours = parseInt(parts[0], 10);
    const minutes = parts[1];
    const ampm = hours >= 12 ? 'PM' : 'AM';
    const formattedHours = hours % 12 || 12;
    return `${String(formattedHours).padStart(2, '0')}:${minutes} ${ampm}`;
  },

  /**
   * Get current authenticated user session data cached locally
   */
  getCurrentUser() {
    try {
      const data = sessionStorage.getItem('evora_user');
      return data ? JSON.parse(data) : null;
    } catch (e) {
      return null;
    }
  },

  /**
   * Set user in session storage
   */
  setCurrentUser(user) {
    try {
      if (user) {
        sessionStorage.setItem('evora_user', JSON.stringify(user));
      } else {
        sessionStorage.removeItem('evora_user');
      }
    } catch (e) {
      console.error('Failed to store user state', e);
    }
  },

  /**
   * Genuine User Logout: Clears backend PHP session, cookies, and client state
   */
  async logout() {
    try {
      await fetch(`${this.apiBase}/auth/logout.php`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });
    } catch (e) {
      console.warn('Logout API request error', e);
    }

    try {
      sessionStorage.removeItem('evora_user');
      sessionStorage.removeItem('evora_csrf_token');
      sessionStorage.removeItem('evora_clocked_in');
      localStorage.removeItem('evora_auth_token');
    } catch (e) {}

    this.showToast('Signed out successfully.', 'info');
    const isInsidePages = window.location.pathname.includes('/pages/');
    const isSubdir = window.location.pathname.includes('/pages/admin/') || window.location.pathname.includes('/pages/employee/');
    
    setTimeout(() => {
      if (isSubdir) {
        window.location.href = '../login.html';
      } else if (isInsidePages) {
        window.location.href = 'login.html';
      } else {
        window.location.href = 'pages/login.html';
      }
    }, 250);
  },

  /**
   * Backwards-compatible alias for existing markup
   */
  exitDemo() {
    this.logout();
  },

  /**
   * Populate topbar, dropdown, and profile views with verified user session data
   */
  populateUserProfile(user) {
    if (!user) return;
    const name = user.name || user.full_name || 'Staff Member';
    const email = user.email || '';
    const role = (user.role || 'employee').toLowerCase();
    const initials = name.split(' ').filter(Boolean).map(n => n[0]).join('').substring(0, 2).toUpperCase() || 'EV';

    // Topbar avatar
    const initialsEl = document.getElementById('topbar-avatar-initials');
    if (initialsEl) initialsEl.textContent = initials;

    // Dropdown profile info
    const dropdownAvatar = document.getElementById('dropdown-avatar-circle');
    if (dropdownAvatar) dropdownAvatar.textContent = initials;

    const nameEl = document.getElementById('dropdown-user-name');
    if (nameEl) nameEl.textContent = name;

    const emailEl = document.getElementById('dropdown-user-email');
    if (emailEl) emailEl.textContent = email;

    const roleEl = document.getElementById('dropdown-user-role');
    if (roleEl) {
      roleEl.textContent = role.toUpperCase();
      roleEl.className = role === 'admin' ? 'dropdown-user-role badge badge-approved' : 'dropdown-user-role badge badge-neutral';
    }

    // Dynamic View Profile link destination based on role and location
    const viewProfileLink = document.getElementById('dropdown-view-profile');
    if (viewProfileLink) {
      const isEmployeeSubdir = window.location.pathname.includes('/pages/employee/');
      const isAdminSubdir = window.location.pathname.includes('/pages/admin/');
      if (role === 'admin') {
        viewProfileLink.href = isAdminSubdir ? 'profile.html' : 'admin/profile.html';
      } else {
        viewProfileLink.href = isEmployeeSubdir ? 'profile.html' : 'employee/profile.html';
      }
    }

    // Profile page elements if present
    const displayProfileName = document.getElementById('display-profile-name');
    if (displayProfileName) displayProfileName.textContent = name;

    const profileNameInput = document.getElementById('profile-name');
    if (profileNameInput) profileNameInput.value = name;

    const profileEmailInput = document.getElementById('profile-email');
    if (profileEmailInput) profileEmailInput.value = email;

    const profilePhoneInput = document.getElementById('profile-phone');
    if (profilePhoneInput && user.phone) profilePhoneInput.value = user.phone;

    const profileDeptInput = document.getElementById('profile-dept');
    if (profileDeptInput && user.department_name) profileDeptInput.value = user.department_name;

    const profileDesigInput = document.getElementById('profile-designation');
    if (profileDesigInput && user.designation) profileDesigInput.value = user.designation;

    const profileHeroAvatar = document.querySelector('.profile-hero .profile-avatar-lg');
    if (profileHeroAvatar) profileHeroAvatar.textContent = initials;

    const heroSubtitle = document.getElementById('profile-hero-subtitle');
    if (heroSubtitle) {
      const desig = user.designation || (role === 'admin' ? 'System Administrator' : 'Staff Member');
      const dept = user.department_name || 'General';
      heroSubtitle.textContent = role === 'admin' ? 'System Administrator • Workforce Governance' : `${desig} • ${dept} Department`;
    }

    const heroId = document.getElementById('profile-hero-id');
    if (heroId) {
      heroId.textContent = role === 'admin' ? 'ADM-2026-001' : `EMP-2026-${String(user.id || 1).padStart(3, '0')}`;
    }

    const heroJoined = document.getElementById('profile-hero-joined');
    if (heroJoined && user.joined_date) heroJoined.textContent = user.joined_date;

    const heroEmail = document.getElementById('profile-hero-email');
    if (heroEmail) heroEmail.textContent = email;

    const welcomeTitle = document.querySelector('.welcome-title');
    if (welcomeTitle && welcomeTitle.textContent.includes('Welcome back')) {
      welcomeTitle.textContent = `Welcome back, ${name}`;
    }
  },

  /**
   * Verify authenticated PHP session and enforce role authorization
   */
  async checkSession(requiredRole = null) {
    const isSubdir = window.location.pathname.includes('/pages/admin/') || window.location.pathname.includes('/pages/employee/');
    const loginTarget = isSubdir ? '../login.html' : 'login.html';

    try {
      const res = await fetch(`${this.apiBase}/auth/me.php`, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });

      if (!res.ok) {
        this.setCurrentUser(null);
        window.location.replace(loginTarget);
        return null;
      }

      const data = await res.json();
      if (!data.authenticated || !data.user) {
        this.setCurrentUser(null);
        window.location.replace(loginTarget);
        return null;
      }

      const user = data.user;
      this.setCurrentUser(user);

      if (data.csrf_token) {
        sessionStorage.setItem('evora_csrf_token', data.csrf_token);
      }

      const isEmployeePage = window.location.pathname.includes('/pages/employee/');
      const isAdminPage = window.location.pathname.includes('/pages/admin/');
      const userRole = (user.role || '').toLowerCase();

      // Enforce role separation:
      // 1. Admin is an overseer/monitor, NOT staff -> redirect away from employee workspace immediately!
      if (isEmployeePage && userRole === 'admin') {
        window.location.replace('../admin/dashboard.html');
        return null;
      }

      // 2. Employee cannot access administrator operations
      if (isAdminPage && userRole !== 'admin') {
        const modal = document.getElementById('modal-access-denied');
        if (modal) {
          this.openModal('modal-access-denied');
          return null;
        }
        window.location.replace('../employee/dashboard.html');
        return null;
      }

      // 3. Explicit requiredRole parameter check
      if (requiredRole) {
        const neededRole = requiredRole.toLowerCase();
        if (neededRole !== userRole) {
          const target = userRole === 'admin' ? '../admin/dashboard.html' : '../employee/dashboard.html';
          window.location.replace(target);
          return null;
        }
      }

      this.populateUserProfile(user);
      return user;
    } catch (e) {
      console.error('Session verification error', e);
      this.setCurrentUser(null);
      window.location.replace(loginTarget);
      return null;
    }
  },

  /**
   * Navigation Guard & User Session initialization for portal pages
   */
  guardPage(requiredRole) {
    this.initProfileDropdown();
    this.initNotificationWidget();
    return this.checkSession(requiredRole);
  },

  /**
   * Role-based access guard alias
   */
  requireRole(requiredRole) {
    return this.guardPage(requiredRole);
  },

  /**
   * Backwards-compatible alias for existing markup
   */
  initDemoBanner(currentRole) {
    return this.guardPage(currentRole);
  },

  /**
   * Toast Notification System
   */
  showToast(message, type = 'info', duration = 3800) {
    let container = document.getElementById('toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'toast-container';
      container.setAttribute('aria-live', 'polite');
      document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.setAttribute('role', 'status');

    const icons = {
      success: '✓',
      error: '✕',
      warning: '⚠',
      info: 'ℹ'
    };

    toast.innerHTML = `
      <div style="display:flex;align-items:center;gap:0.6rem;">
        <span style="font-weight:700;font-size:1.05rem;">${icons[type] || 'ℹ'}</span>
        <span>${this.escapeHtml(message)}</span>
      </div>
      <button style="background:none;border:none;color:inherit;cursor:pointer;font-size:1.1rem;padding:0.1rem 0.3rem;" onclick="this.parentElement.remove()" aria-label="Close notification">&times;</button>
    `;

    container.appendChild(toast);

    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateX(100%)';
      toast.style.transition = 'all 0.3s ease';
      setTimeout(() => toast.remove(), 300);
    }, duration);
  },

  /**
   * Accessible Modal System
   */
  openModal(modalId) {
    const backdrop = document.getElementById(modalId);
    if (!backdrop) return;
    backdrop.classList.add('active');
    document.body.style.overflow = 'hidden';

    const focusable = backdrop.querySelector('input, select, textarea, button.btn-primary, button.modal-close');
    if (focusable) focusable.focus();

    const escListener = (e) => {
      if (e.key === 'Escape') {
        this.closeModal(modalId);
        document.removeEventListener('keydown', escListener);
      }
    };
    document.addEventListener('keydown', escListener);
  },

  closeModal(modalId) {
    const backdrop = document.getElementById(modalId);
    if (!backdrop) return;
    backdrop.classList.remove('active');
    document.body.style.overflow = '';
  },

  /**
   * Password Visibility Toggles
   */
  initPasswordToggles() {
    document.querySelectorAll('.password-toggle-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const input = btn.previousElementSibling;
        if (!input) return;
        if (input.type === 'password') {
          input.type = 'text';
          btn.textContent = 'Hide';
          btn.setAttribute('aria-label', 'Hide password');
        } else {
          input.type = 'password';
          btn.textContent = 'Show';
          btn.setAttribute('aria-label', 'Show password');
        }
      });
    });
  },

  /**
   * Mobile Navigation Toggle
   */
  initMobileNav() {
    const toggleBtn = document.getElementById('mobile-nav-toggle');
    const menu = document.getElementById('mobile-nav-menu');
    if (!toggleBtn || !menu) return;

    toggleBtn.addEventListener('click', () => {
      const isExpanded = toggleBtn.getAttribute('aria-expanded') === 'true';
      toggleBtn.setAttribute('aria-expanded', !isExpanded);
      menu.classList.toggle('active');
    });
  },

  /**
   * Real Database Attendance Controls (Employee Check In / Check Out)
   */
  async initAttendanceControls() {
    const btnClock = document.getElementById('btn-clock-toggle');
    const statusText = document.getElementById('clock-status-text');
    const timeText = document.getElementById('clock-time-text');
    if (!btnClock) return;

    // Administrators oversee the system and do not have attendance clock-in options
    const currentUser = this.getCurrentUser();
    if (currentUser && (currentUser.role || '').toLowerCase() === 'admin') {
      const widget = document.querySelector('.clock-pill-card');
      if (widget) widget.style.display = 'none';
      return;
    }

    btnClock.disabled = true;

    // Load real today status from API
    const res = await this.api('/employee/attendance.php');
    btnClock.disabled = false;

    if (!res.ok || !res.success) {
      if (statusText) statusText.textContent = 'Offline';
      return;
    }

    const today = res.data?.today ?? res.today ?? null;

    const updateDisplay = (rec) => {
      if (!rec || !rec.check_in_time) {
        btnClock.textContent = 'Clock In';
        btnClock.className = 'btn btn-primary';
        btnClock.disabled = false;
        if (statusText) {
          statusText.textContent = 'Not Checked In';
          statusText.className = 'badge badge-neutral';
        }
        if (timeText) timeText.textContent = 'Shift not started today';
      } else if (rec.check_in_time && !rec.check_out_time) {
        btnClock.textContent = 'Clock Out';
        btnClock.className = 'btn btn-secondary';
        btnClock.disabled = false;
        const formattedIn = this.formatTime(rec.check_in_time);
        if (statusText) {
          statusText.textContent = rec.status === 'late' ? 'Present (Late Arrival)' : 'Present (Checked In)';
          statusText.className = rec.status === 'late' ? 'badge badge-pending' : 'badge badge-approved';
        }
        if (timeText) timeText.textContent = `Today: Clocked in at ${formattedIn}`;
      } else {
        btnClock.textContent = 'Shift Completed';
        btnClock.className = 'btn btn-secondary';
        btnClock.disabled = true;
        const formattedIn = this.formatTime(rec.check_in_time);
        const formattedOut = this.formatTime(rec.check_out_time);
        if (statusText) {
          statusText.textContent = 'Shift Concluded';
          statusText.className = 'badge badge-approved';
        }
        if (timeText) timeText.textContent = `In: ${formattedIn} • Out: ${formattedOut}`;
      }
    };

    updateDisplay(today);

    // Click handler for Check-In / Check-Out
    btnClock.onclick = async () => {
      const isCheckingIn = btnClock.textContent === 'Clock In';
      const action = isCheckingIn ? 'check_in' : 'check_out';

      btnClock.disabled = true;
      btnClock.textContent = isCheckingIn ? 'Clocking In...' : 'Clocking Out...';

      const actionRes = await this.api('/employee/attendance.php', {
        method: 'POST',
        body: { action }
      });

      btnClock.disabled = false;

      if (actionRes.ok && actionRes.success) {
        this.showToast(actionRes.message, 'success');
        const updatedRec = actionRes.data?.record ?? actionRes.record ?? null;
        updateDisplay(updatedRec);

        // If attendance table is on page, reload it
        if (typeof renderAttendanceTable === 'function') {
          renderAttendanceTable();
        }
      } else {
        this.showToast(actionRes.message || 'Failed to update attendance status.', 'error');
        // Revert display
        const refresh = await this.api('/employee/attendance.php');
        if (refresh.ok && refresh.success) {
          updateDisplay(refresh.data?.today ?? refresh.today ?? null);
        }
      }
    };
  },

  /**
   * Real Database Leave Application Form Submission
   */
  initLeaveForm() {
    const form = document.getElementById('form-leave-apply');
    if (!form) return;

    form.onsubmit = async (e) => {
      e.preventDefault();
      const type = document.getElementById('leave-type')?.value;
      const start = document.getElementById('leave-start')?.value;
      const end = document.getElementById('leave-end')?.value;
      const reason = document.getElementById('leave-reason')?.value;
      const submitBtn = document.getElementById('btn-submit-leave');

      if (!type || !start || !end || !reason) {
        this.showToast('Please fill out all required fields.', 'error');
        return;
      }

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting Request...';
      }

      const res = await this.api('/employee/leaves.php', {
        method: 'POST',
        body: {
          leave_type: type,
          start_date: start,
          end_date: end,
          reason: reason
        }
      });

      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Submit Leave Request';
      }

      if (res.ok && res.success) {
        this.showToast(res.message, 'success');
        form.reset();
        // If on leaves.html and renderLeaveHistory exists, reload
        if (typeof renderLeaveHistory === 'function') {
          renderLeaveHistory();
        }
      } else {
        this.showToast(res.message || 'Failed to submit leave request.', 'error');
      }
    };
  },

  /**
   * Real Database Profile Form Save Handler
   */
  initProfileForm() {
    const form = document.getElementById('form-profile');
    if (!form) return;

    form.onsubmit = async (e) => {
      e.preventDefault();
      const name = document.getElementById('profile-name')?.value;
      const phone = document.getElementById('profile-phone')?.value;
      const saveBtn = document.getElementById('btn-save-profile');

      if (!name || name.trim().length < 2) {
        this.showToast('Please enter a valid full name.', 'error');
        return;
      }

      if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving Changes...';
      }

      const res = await this.api('/employee/profile.php', {
        method: 'POST',
        body: {
          full_name: name.trim(),
          phone: phone ? phone.trim() : ''
        }
      });

      if (saveBtn) {
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save Profile Changes';
      }

      if (res.ok && res.success) {
        this.showToast(res.message || 'Profile updated successfully.', 'success');
        const user = this.getCurrentUser() || {};
        user.name = name.trim();
        user.full_name = name.trim();
        user.phone = phone ? phone.trim() : '';
        this.setCurrentUser(user);
        this.populateUserProfile(user);
      } else {
        this.showToast(res.message || 'Failed to update profile.', 'error');
      }
    };
  },

  /**
   * Check if current session user has administrator role
   */
  checkAdminAccess() {
    const user = this.getCurrentUser();
    if (!user || user.role !== 'admin') {
      const modal = document.getElementById('modal-access-denied');
      if (modal) {
        this.openModal('modal-access-denied');
        return false;
      }
      return false;
    }
    return true;
  },

  /**
   * Initializes the top-right profile avatar and dropdown menu
   */
  initProfileDropdown() {
    const avatarBtn = document.getElementById('profile-avatar-btn');
    const dropdown = document.getElementById('profile-dropdown');
    if (!avatarBtn || !dropdown) return;

    const user = this.getCurrentUser();
    if (user) {
      this.populateUserProfile(user);
    }

    if (avatarBtn.dataset.bound === 'true') return;
    avatarBtn.dataset.bound = 'true';

    avatarBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const isShown = dropdown.classList.contains('show');
      dropdown.classList.toggle('show');
      avatarBtn.setAttribute('aria-expanded', !isShown);
    });

    document.addEventListener('click', (e) => {
      if (!dropdown.contains(e.target) && !avatarBtn.contains(e.target)) {
        dropdown.classList.remove('show');
        avatarBtn.setAttribute('aria-expanded', 'false');
      }
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && dropdown.classList.contains('show')) {
        dropdown.classList.remove('show');
        avatarBtn.setAttribute('aria-expanded', 'false');
        avatarBtn.focus();
      }
    });
  },

  /**
   * Real Database User Notifications Topbar Widget
   */
  async initNotificationWidget() {
    const topbarRight = document.querySelector('.topbar-right');
    if (!topbarRight || document.getElementById('notif-widget')) return;

    const widget = document.createElement('div');
    widget.className = 'notif-widget';
    widget.id = 'notif-widget';
    widget.innerHTML = `
      <button class="notif-btn" id="notif-bell-btn" aria-label="Notifications" title="Notifications">
        <span>&#128276;</span>
        <span class="notif-badge" id="notif-badge" style="display:none;">0</span>
      </button>
      <div class="notif-dropdown" id="notif-dropdown">
        <div class="notif-dropdown-header">
          <span class="notif-dropdown-title">Notifications</span>
          <button class="btn btn-secondary btn-sm" id="btn-mark-all-read" style="padding: 0.2rem 0.5rem; font-size: 0.72rem;">Mark all as read</button>
        </div>
        <div class="notif-list" id="notif-list">
          <div style="padding: 1.5rem; text-align: center; color: var(--color-text-muted); font-size: 0.82rem;">Loading notices...</div>
        </div>
      </div>
    `;

    const profileWidget = document.getElementById('profile-widget');
    if (profileWidget) {
      topbarRight.insertBefore(widget, profileWidget);
    } else {
      topbarRight.appendChild(widget);
    }

    const bellBtn = document.getElementById('notif-bell-btn');
    const dropdown = document.getElementById('notif-dropdown');
    const badge = document.getElementById('notif-badge');
    const list = document.getElementById('notif-list');
    const markAllBtn = document.getElementById('btn-mark-all-read');

    // Toggle dropdown
    bellBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const isShown = dropdown.classList.contains('show');
      dropdown.classList.toggle('show');
      // Close profile dropdown if open
      const profileDropdown = document.getElementById('profile-dropdown');
      if (profileDropdown) profileDropdown.classList.remove('show');
    });

    document.addEventListener('click', (e) => {
      if (!dropdown.contains(e.target) && !bellBtn.contains(e.target)) {
        dropdown.classList.remove('show');
      }
    });

    // Fetch and render
    const loadNotifs = async () => {
      try {
        const res = await this.api('/notifications/index.php');
        if (!res || !res.success) return;

        const notifs = res.data?.notifications || res.notifications || [];
        const unread = res.data?.unread_count ?? res.unread_count ?? 0;

        if (unread > 0) {
          badge.textContent = unread > 99 ? '99+' : unread;
          badge.style.display = 'flex';
        } else {
          badge.style.display = 'none';
        }

        if (!notifs.length) {
          list.innerHTML = `<div style="padding: 1.75rem 1rem; text-align: center; color: var(--color-text-muted); font-size: 0.82rem;">No notifications yet.</div>`;
          return;
        }

        list.innerHTML = notifs.map(n => {
          const isUnread = !n.is_read;
          const unreadClass = isUnread ? 'unread' : '';
          const dot = isUnread ? '<span class="notif-dot"></span>' : '';
          const time = n.created_at ? n.created_at.split(' ')[0] : '';

          return `
            <div class="notif-item ${unreadClass}" data-id="${n.id}">
              <div class="notif-item-title">
                <span>${this.escapeHtml(n.title)}</span>
                ${dot}
              </div>
              <div class="notif-item-msg">${this.escapeHtml(n.message)}</div>
              <div class="notif-item-time">${this.escapeHtml(time)}</div>
            </div>
          `;
        }).join('');

        // Item click -> mark as read
        list.querySelectorAll('.notif-item.unread').forEach(item => {
          item.addEventListener('click', async () => {
            const id = item.dataset.id;
            await EVORA.api('/notifications/index.php', {
              method: 'POST',
              body: JSON.stringify({ id: parseInt(id, 10) })
            });
            item.classList.remove('unread');
            const dot = item.querySelector('.notif-dot');
            if (dot) dot.remove();
            const curVal = parseInt(badge.textContent, 10) || 0;
            if (curVal <= 1) {
              badge.style.display = 'none';
            } else {
              badge.textContent = curVal - 1;
            }
          });
        });

      } catch (err) {
        // silent fail
      }
    };

    // Mark all as read
    markAllBtn.addEventListener('click', async (e) => {
      e.stopPropagation();
      try {
        await EVORA.api('/notifications/index.php', {
          method: 'POST',
          body: JSON.stringify({ action: 'mark_all' })
        });
        badge.style.display = 'none';
        list.querySelectorAll('.notif-item.unread').forEach(item => {
          item.classList.remove('unread');
          const dot = item.querySelector('.notif-dot');
          if (dot) dot.remove();
        });
        EVORA.showToast('All notifications marked as read.', 'info');
      } catch (err) {}
    });

    loadNotifs();
  },

  /**
   * Global Initialization
   */
  init() {
    this.initPasswordToggles();
    this.initMobileNav();
    this.initProfileDropdown();
    this.initNotificationWidget();
  }
};

window.EVORA = EVORA;

document.addEventListener('DOMContentLoaded', () => {
  EVORA.init();
});
