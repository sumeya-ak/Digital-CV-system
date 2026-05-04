// ===== Auth Handler =====
const API_BASE = '/php/api';

function handleLogin(e) {
  e.preventDefault();
  const email = document.getElementById('loginEmail').value.trim();
  const password = document.getElementById('loginPassword').value;

  if (!email || !password) {
    showAuthAlert('login', 'Please fill in all fields.');
    return;
  }

  const btn = e.target.querySelector('button[type="submit"]');
  btn.disabled = true;
  btn.textContent = 'Logging in...';

  fetch(`${API_BASE}/auth.php?action=login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password })
  })
  .then(res => res.json())
  .then(data => {
    if (data.error) {
      showAuthAlert('login', data.error);
    } else {
      localStorage.setItem('dcv_current_user', JSON.stringify(data.user));
      redirectByRole(data.user.role_name || data.user.role);
    }
  })
  .catch(err => {
    console.error('Login error:', err);
    showAuthAlert('login', 'Connection error. Make sure PHP server is running.');
  })
  .finally(() => {
    btn.disabled = false;
    btn.textContent = 'Login';
  });
}

function handleRegister(e) {
  e.preventDefault();
  const role = document.querySelector('input[name="role"]:checked').value;
  const firstName = document.getElementById('regFirstName').value.trim();
  const lastName = document.getElementById('regLastName').value.trim();
  const email = document.getElementById('regEmail').value.trim();
  const institution = document.getElementById('regInstitution').value.trim();
  const password = document.getElementById('regPassword').value;
  const confirmPassword = document.getElementById('regConfirmPassword').value;

  if (!firstName || !lastName || !email || !password) {
    showAuthAlert('reg', 'Please fill in all required fields.');
    return;
  }

  if (password.length < 8) {
    showAuthAlert('reg', 'Password must be at least 8 characters.');
    return;
  }

  if (password !== confirmPassword) {
    showAuthAlert('reg', 'Passwords do not match.');
    return;
  }

  const btn = e.target.querySelector('button[type="submit"]');
  btn.disabled = true;
  btn.textContent = 'Registering...';

  const userData = { email, password, first_name: firstName, last_name: lastName, role };
  if (institution) userData.institution = institution;

  fetch(`${API_BASE}/auth.php?action=register`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(userData)
  })
  .then(res => res.json())
  .then(data => {
    if (data.error) {
      showAuthAlert('reg', data.error);
    } else {
      localStorage.setItem('dcv_current_user', JSON.stringify(data.user));
      redirectByRole(data.user.role_name || data.user.role);
    }
  })
  .catch(err => {
    console.error('Register error:', err);
    showAuthAlert('reg', 'Connection error. Make sure PHP server is running.');
  })
  .finally(() => {
    btn.disabled = false;
    btn.textContent = 'Register';
  });
}

function showAuthAlert(type, msg) {
  const alertEl = document.getElementById(type === 'login' ? 'loginAlert' : 'regAlert');
  const msgEl = document.getElementById(type === 'login' ? 'loginAlertMsg' : 'regAlertMsg');
  msgEl.textContent = msg;
  alertEl.classList.remove('hidden');
  setTimeout(() => alertEl.classList.add('hidden'), 5000);
}

function redirectByRole(role) {
  const pages = {
    student: 'student-dashboard.html',
    supervisor: 'supervisor-dashboard.html',
    examiner: 'examiner-dashboard.html',
    recruiter: 'recruiter-dashboard.html',
    manager: 'manager-dashboard.html'
  };
  window.location.href = pages[role] || 'login.html';
}

function logout() {
  fetch(`${API_BASE}/auth.php?action=logout`, { method: 'POST' })
    .catch(err => console.error('Logout error:', err));
  localStorage.removeItem('dcv_current_user');
  window.location.href = 'login.html';
}

function getCurrentUser() {
  return JSON.parse(localStorage.getItem('dcv_current_user') || 'null');
}

function requireAuth(allowedRoles) {
  const user = getCurrentUser();
  if (!user) {
    window.location.href = 'login.html';
    return null;
  }
  const userRole = user.role_name || user.role;
  if (allowedRoles && !allowedRoles.includes(userRole)) {
    window.location.href = 'login.html';
    return null;
  }
  return user;
}


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
