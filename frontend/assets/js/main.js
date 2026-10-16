/**
 * EVORA HRMS — Core Interactive Engine & Demo State Manager
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
   * Demo User Personas for local UI testing
   */
  demoPersonas: {
    admin: {
      role: 'admin',
      name: 'Eleanor Vance',
      email: 'admin@evora.test',
      designation: 'Head of People Operations',
      status: 'approved'
    },
    employee: {
      role: 'employee',
      name: 'Jane Doe',
      email: 'employee@evora.test',
      department: 'Engineering & IT',
      designation: 'Software Associate',
      status: 'approved'
    }
  },

  /**
   * Get current demo user from sessionStorage
   */
  getDemoUser() {
    try {
      const data = sessionStorage.getItem('evora_demo_user');
      return data ? JSON.parse(data) : null;
    } catch (e) {
      return null;
    }
  },

  /**
   * Set demo user in sessionStorage
   */
  setDemoUser(persona) {
    try {
      sessionStorage.setItem('evora_demo_user', JSON.stringify(persona));
    } catch (e) {
      console.error('Failed to store demo user state', e);
    }
  },

  /**
   * Switch demo role and navigate to appropriate workspace
   */
  switchDemoRole(role) {
    const persona = this.demoPersonas[role];
    if (!persona) return;
    this.setDemoUser(persona);
    this.showToast(`Switched to ${role.toUpperCase()} Demo mode (${persona.name})`, 'info');
    
    // Resolve relative path to dashboard
    const isEmployeePath = window.location.pathname.includes('/pages/employee/');
    const isAdminPath = window.location.pathname.includes('/pages/admin/');
    const isAuthPath = window.location.pathname.includes('/pages/');

    if (role === 'admin') {
      if (isAdminPath) {
        window.location.reload();
      } else if (isEmployeePath) {
        window.location.href = '../admin/dashboard.html';
      } else if (isAuthPath) {
        window.location.href = 'admin/dashboard.html';
      } else {
        window.location.href = 'pages/admin/dashboard.html';
      }
    } else {
      if (isEmployeePath) {
        window.location.reload();
      } else if (isAdminPath) {
        window.location.href = '../employee/dashboard.html';
      } else if (isAuthPath) {
        window.location.href = 'employee/dashboard.html';
      } else {
        window.location.href = 'pages/employee/dashboard.html';
      }
    }
  },

  /**
   * Exit demo mode, clear state, and return to login
   */
  exitDemo() {
    sessionStorage.removeItem('evora_demo_user');
    this.showToast('Exited Demo Mode', 'info');
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
    }, 300);
  },

  /**
   * Initialize user session and profile dropdown for portal pages
   * (Top demo banner removed as requested)
   */
  initDemoBanner(currentRole) {
    let user = this.getDemoUser();
    if (!user) {
      // Default to the matching role persona if entering directly
      user = this.demoPersonas[currentRole] || this.demoPersonas.employee;
      this.setDemoUser(user);
    }

    this.initProfileDropdown();
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
        <span>${message}</span>
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

    // Focus on first input or close button
    const focusable = backdrop.querySelector('input, select, textarea, button.btn-primary, button.modal-close');
    if (focusable) focusable.focus();

    // Close on ESC
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
   * Attendance Interactive Demo State (Check In / Check Out)
   */
  initAttendanceControls() {
    const btnClock = document.getElementById('btn-clock-toggle');
    const statusText = document.getElementById('clock-status-text');
    const timeText = document.getElementById('clock-time-text');
    if (!btnClock) return;

    // Check stored state
    let isCheckedIn = sessionStorage.getItem('evora_clocked_in') === 'true';

    const updateDisplay = () => {
      if (isCheckedIn) {
        btnClock.textContent = 'Clock Out';
        btnClock.className = 'btn btn-secondary';
        if (statusText) statusText.textContent = 'Present (Checked In)';
        if (statusText) statusText.className = 'badge badge-approved';
      } else {
        btnClock.textContent = 'Clock In';
        btnClock.className = 'btn btn-primary';
        if (statusText) statusText.textContent = 'Not Checked In';
        if (statusText) statusText.className = 'badge badge-neutral';
      }
    };

    updateDisplay();

    btnClock.addEventListener('click', () => {
      isCheckedIn = !isCheckedIn;
      sessionStorage.setItem('evora_clocked_in', isCheckedIn);
      const now = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

      if (isCheckedIn) {
        if (timeText) timeText.textContent = `Today: Clocked in at ${now}`;
        this.showToast(`Checked In at ${now} (Demo simulation)`, 'success');
      } else {
        if (timeText) timeText.textContent = `Shift ended at ${now}`;
        this.showToast(`Clocked Out at ${now} (Demo simulation)`, 'info');
      }
      updateDisplay();
    });
  },

  /**
   * Leave Application Demo Submission
   */
  initLeaveForm() {
    const form = document.getElementById('form-leave-apply');
    if (!form) return;

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const type = document.getElementById('leave-type')?.value || 'Casual';
      const start = document.getElementById('leave-start')?.value;
      const end = document.getElementById('leave-end')?.value;
      const reason = document.getElementById('leave-reason')?.value;

      if (!start || !end) {
        this.showToast('Please select valid start and end dates.', 'error');
        return;
      }

      if (new Date(end) < new Date(start)) {
        this.showToast('End date cannot precede the start date.', 'error');
        return;
      }

      const tableBody = document.getElementById('leave-history-tbody');
      if (tableBody) {
        const newRow = document.createElement('tr');
        newRow.innerHTML = `
          <td><strong>${type.charAt(0).toUpperCase() + type.slice(1)}</strong></td>
          <td>${start} &mdash; ${end}</td>
          <td>${reason || 'No additional details'}</td>
          <td><span class="badge badge-pending">Pending</span></td>
          <td><em>Awaiting administrator review</em></td>
        `;
        tableBody.insertBefore(newRow, tableBody.firstChild);
      }

      form.reset();
      this.showToast('Leave request submitted (Demo only &mdash; pending review simulation)', 'success');
    });
  },

  /**
   * Profile Form Demo Save
   */
  initProfileForm() {
    const form = document.getElementById('form-profile');
    if (!form) return;

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const name = document.getElementById('profile-name')?.value;
      const phone = document.getElementById('profile-phone')?.value;

      let user = this.getDemoUser() || this.demoPersonas.employee;
      if (name) user.name = name;
      if (phone) user.phone = phone;
      this.setDemoUser(user);

      this.showToast('Profile updated in session memory (Demo mode &mdash; not persisted to DB)', 'success');
    });
  },

  /**
   * Admin Employee Approval / Rejection Interaction
   */
  reviewEmployee(rowId, action, applicantName) {
    const row = document.getElementById(rowId);
    if (!row) return;

    if (action === 'approve') {
      row.style.transition = 'all 0.3s ease';
      row.style.backgroundColor = 'var(--status-approved-bg)';
      setTimeout(() => {
        row.remove();
        this.showToast(`Approved ${applicantName} (Demo simulation: account marked approved)`, 'success');
        this.updatePendingCount(-1);
      }, 400);
    } else {
      if (!confirm(`Are you sure you want to reject registration for ${applicantName}?`)) return;
      row.style.transition = 'all 0.3s ease';
      row.style.backgroundColor = 'var(--status-rejected-bg)';
      setTimeout(() => {
        row.remove();
        this.showToast(`Rejected registration for ${applicantName} (Demo simulation)`, 'warning');
        this.updatePendingCount(-1);
      }, 400);
    }
  },

  /**
   * Admin Leave Review Interaction
   */
  reviewLeaveRequest(rowId, action, employeeName) {
    const row = document.getElementById(rowId);
    if (!row) return;

    const actionCell = row.querySelector('.action-cell');
    const statusCell = row.querySelector('.status-cell');

    if (action === 'approve') {
      if (statusCell) statusCell.innerHTML = '<span class="badge badge-approved">Approved</span>';
      if (actionCell) actionCell.innerHTML = '<span style="font-size:0.8rem;color:var(--color-text-muted);">Approved by Admin</span>';
      this.showToast(`Approved leave request for ${employeeName} (Demo simulation)`, 'success');
    } else {
      if (statusCell) statusCell.innerHTML = '<span class="badge badge-rejected">Rejected</span>';
      if (actionCell) actionCell.innerHTML = '<span style="font-size:0.8rem;color:var(--color-text-muted);">Rejected</span>';
      this.showToast(`Rejected leave request for ${employeeName} (Demo simulation)`, 'warning');
    }
  },

  /**
   * Helper to decrement badge counts dynamically
   */
  updatePendingCount(delta) {
    document.querySelectorAll('.pending-counter').forEach(el => {
      let count = parseInt(el.textContent, 10);
      if (!isNaN(count)) {
        count = Math.max(0, count + delta);
        el.textContent = count;
      }
    });
  },

  /**
   * Organizational Departments (Matching MySQL database schema)
   */
  departments: [
    { id: 1, name: 'Human Resources', code: 'HR' },
    { id: 2, name: 'Engineering & IT', code: 'ENG' },
    { id: 3, name: 'Marketing & Sales', code: 'MKT' },
    { id: 4, name: 'Finance & Accounts', code: 'FIN' },
    { id: 5, name: 'Operations', code: 'OPS' }
  ],

  /**
   * In-Memory / SessionStorage Employee Store for complete Admin Employee Management
   */
  EmployeeStore: {
    key: 'evora_employees_store',
    getInitialData() {
      return [
        { id: 1, name: 'Jane Doe', email: 'employee@evora.test', phone: '+91 98765 43210', dept_id: 2, department: 'Engineering & IT', designation: 'Software Associate', role: 'employee', status: 'approved', joined_date: '2026-01-15' },
        { id: 2, name: 'Michael Scott', email: 'michael.scott@example.com', phone: '+91 98765 11111', dept_id: 5, department: 'Operations', designation: 'Regional Coordinator', role: 'employee', status: 'approved', joined_date: '2025-08-10' },
        { id: 3, name: 'David Wallace', email: 'david.wallace@example.com', phone: '+91 98765 22222', dept_id: 4, department: 'Finance & Accounts', designation: 'CFO / Controller', role: 'employee', status: 'approved', joined_date: '2025-05-01' },
        { id: 4, name: 'Pam Beesly', email: 'pam.beesly@example.com', phone: '+91 98765 33333', dept_id: 1, department: 'Human Resources', designation: 'People Specialist', role: 'employee', status: 'approved', joined_date: '2025-11-20' },
        { id: 5, name: 'Jim Halpert', email: 'jim.halpert@example.com', phone: '+91 98765 44444', dept_id: 3, department: 'Marketing & Sales', designation: 'Account Lead', role: 'employee', status: 'approved', joined_date: '2025-09-15' },
        { id: 101, name: 'Alex Mercer', email: 'alex.mercer@example.com', phone: '+91 98765 55555', dept_id: 2, department: 'Engineering & IT', designation: 'Backend Developer', role: 'employee', status: 'pending', joined_date: '2026-10-16' },
        { id: 102, name: 'Sarah Lin', email: 'sarah.lin@example.com', phone: '+91 98765 66666', dept_id: 3, department: 'Marketing & Sales', designation: 'Brand Strategist', role: 'employee', status: 'pending', joined_date: '2026-10-15' },
        { id: 103, name: 'Vikram Patel', email: 'vikram.patel@example.com', phone: '+91 98765 77777', dept_id: 4, department: 'Finance & Accounts', designation: 'Financial Analyst', role: 'employee', status: 'pending', joined_date: '2026-10-14' }
      ];
    },
    getAll() {
      try {
        const stored = sessionStorage.getItem(this.key);
        if (stored) return JSON.parse(stored);
      } catch (e) {}
      const initial = this.getInitialData();
      this.saveAll(initial);
      return initial;
    },
    saveAll(list) {
      try {
        sessionStorage.setItem(this.key, JSON.stringify(list));
      } catch (e) {}
    },
    getById(id) {
      return this.getAll().find(e => e.id == id);
    },
    add(data) {
      const list = this.getAll();
      const newId = list.reduce((max, e) => Math.max(max, e.id), 0) + 1;
      const today = new Date().toISOString().split('T')[0];
      const newEmp = {
        id: newId,
        name: data.name.trim(),
        email: data.email.trim(),
        phone: data.phone ? data.phone.trim() : '+91 98765 00000',
        dept_id: parseInt(data.dept_id, 10) || 1,
        department: data.department || 'Human Resources',
        designation: data.designation.trim(),
        role: 'employee',
        status: data.status || 'approved',
        joined_date: data.joined_date || today
      };
      list.unshift(newEmp);
      this.saveAll(list);
      return newEmp;
    },
    update(id, data) {
      const list = this.getAll();
      const idx = list.findIndex(e => e.id == id);
      if (idx !== -1) {
        list[idx] = { ...list[idx], ...data };
        this.saveAll(list);
        return list[idx];
      }
      return null;
    },
    remove(id) {
      let list = this.getAll();
      const beforeCount = list.length;
      list = list.filter(e => e.id != id);
      this.saveAll(list);
      return list.length < beforeCount;
    },
    approve(id) {
      return this.update(id, { status: 'approved' });
    },
    reject(id) {
      return this.update(id, { status: 'rejected' });
    },
    getStats() {
      const list = this.getAll();
      const approved = list.filter(e => e.status === 'approved').length;
      const pending = list.filter(e => e.status === 'pending').length;
      const rejected = list.filter(e => e.status === 'rejected').length;
      return { total: list.length, approved, pending, rejected };
    }
  },

  /**
   * Check if current user is allowed to access Admin features
   */
  checkAdminAccess() {
    const user = this.getDemoUser();
    if (user && user.role === 'employee') {
      const modal = document.getElementById('modal-access-denied');
      if (modal) {
        this.openModal('modal-access-denied');
        return false;
      }
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

    // Populate user info from demo state
    const user = this.getDemoUser() || this.demoPersonas.employee;
    const initials = user.name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();

    const initialsEl = document.getElementById('topbar-avatar-initials');
    if (initialsEl) initialsEl.textContent = initials;

    const dropdownAvatar = document.getElementById('dropdown-avatar-circle');
    if (dropdownAvatar) dropdownAvatar.textContent = initials;

    const nameEl = document.getElementById('dropdown-user-name');
    if (nameEl) nameEl.textContent = user.name;

    const emailEl = document.getElementById('dropdown-user-email');
    if (emailEl) emailEl.textContent = user.email;

    const roleEl = document.getElementById('dropdown-user-role');
    if (roleEl) {
      roleEl.textContent = user.role.toUpperCase();
      roleEl.className = user.role === 'admin' ? 'dropdown-user-role badge badge-approved' : 'dropdown-user-role badge badge-neutral';
    }

    const viewProfileLink = document.getElementById('dropdown-view-profile');
    if (viewProfileLink) {
      const isEmployeeSubdir = window.location.pathname.includes('/pages/employee/');
      viewProfileLink.href = isEmployeeSubdir ? 'profile.html' : '../employee/profile.html';
      viewProfileLink.innerHTML = '<span class="dropdown-link-icon">&#128100;</span> View Profile';
    }

    // Only attach events once
    if (avatarBtn.dataset.bound === 'true') return;
    avatarBtn.dataset.bound = 'true';

    // Toggle dropdown on avatar click
    avatarBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const isShown = dropdown.classList.contains('show');
      dropdown.classList.toggle('show');
      avatarBtn.setAttribute('aria-expanded', !isShown);
    });

    // Close on outside click
    document.addEventListener('click', (e) => {
      if (!dropdown.contains(e.target) && !avatarBtn.contains(e.target)) {
        dropdown.classList.remove('show');
        avatarBtn.setAttribute('aria-expanded', 'false');
      }
    });

    // Close on ESC
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && dropdown.classList.contains('show')) {
        dropdown.classList.remove('show');
        avatarBtn.setAttribute('aria-expanded', 'false');
        avatarBtn.focus();
      }
    });
  },

  /**
   * Global Initialization
   */
  init() {
    this.initPasswordToggles();
    this.initMobileNav();
    this.initProfileDropdown();
  }
};

window.EVORA = EVORA;

document.addEventListener('DOMContentLoaded', () => {
  EVORA.init();
});
