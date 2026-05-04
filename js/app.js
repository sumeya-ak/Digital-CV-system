// ===== Shared App Utilities =====

// Sidebar toggle for mobile
function toggleSidebar() {
  document.querySelector('.sidebar').classList.toggle('open');
}

// Close sidebar on outside click (mobile)
document.addEventListener('click', function(e) {
  const sidebar = document.querySelector('.sidebar');
  const toggle = document.querySelector('.menu-toggle');
  if (sidebar && sidebar.classList.contains('open') && !sidebar.contains(e.target) && toggle && !toggle.contains(e.target)) {
    sidebar.classList.remove('open');
  }
});

// Tab switching
function switchTab(tabGroup, tabName) {
  document.querySelectorAll(`[data-tab-group="${tabGroup}"] .tab`).forEach(t => t.classList.remove('active'));
  document.querySelectorAll(`[data-tab-group="${tabGroup}"] .tab-content`).forEach(c => c.classList.remove('active'));
  document.querySelector(`[data-tab-group="${tabGroup}"] .tab[data-tab="${tabName}"]`).classList.add('active');
  document.getElementById(tabName).classList.add('active');
}

// Modal open/close
function openModal(modalId) {
  document.getElementById(modalId).classList.add('active');
}

function closeModal(modalId) {
  document.getElementById(modalId).classList.remove('active');
}

// Close modal on overlay click
document.addEventListener('click', function(e) {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('active');
  }
});

// Initialize user info in sidebar
function initSidebarUser() {
  const user = getCurrentUser();
  if (!user) return;
  const nameEl = document.querySelector('.sidebar-user .name');
  const roleEl = document.querySelector('.sidebar-user .role');
  const avatarEl = document.querySelector('.sidebar-user .avatar');
  if (nameEl) nameEl.textContent = user.name || (user.first_name + ' ' + user.last_name);
  if (roleEl) roleEl.textContent = capitalizeFirst(user.role_name || user.role);
  if (avatarEl) avatarEl.textContent = (user.first_name || user.name || 'U')[0].toUpperCase();
}

// Set active nav link
function setActiveNav() {
  const page = window.location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.sidebar-nav a').forEach(a => {
    if (a.getAttribute('href') === page) {
      a.classList.add('active');
    }
  });
}

// Capitalize first letter
function capitalizeFirst(str) {
  return str.charAt(0).toUpperCase() + str.slice(1);
}

// Format date
function formatDate(dateStr) {
  if (!dateStr) return '-';
  const d = new Date(dateStr);
  return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

// Format datetime
function formatDateTime(dateStr) {
  if (!dateStr) return '-';
  const d = new Date(dateStr);
  return d.toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

// Generate unique ID
function generateId() {
  return 'id_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
}

// API helper function
async function apiCall(endpoint, options = {}) {
  const url = `${API_BASE}${endpoint}`;
  const opts = {
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    ...options
  };
  if (options.body && typeof options.body === 'object') {
    opts.body = JSON.stringify(options.body);
  }
  const res = await fetch(url, opts);
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || 'API error');
  return data;
}

// Show toast notification
function showToast(message, type = 'info') {
  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  toast.textContent = message;
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 3000);
}

// Init on load
document.addEventListener('DOMContentLoaded', function() {
  initSidebarUser();
  setActiveNav();
});
