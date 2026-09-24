/*
  EduTrack — shared auth form helpers
  Used by Auth/login.html, Auth/register.html and Auth/verify-otp.html.

  All POSTs go through apiPost(), which attaches the CSRF token the server
  requires on every state-changing request. Calling fetch() directly will be
  rejected with a 419.
*/

const API_BASE = '../api/';

let csrfToken = null;

/* Fetches the session's CSRF token once and caches it for the page's lifetime. */
async function getCsrfToken() {
  if (csrfToken) return csrfToken;

  const res = await fetch(API_BASE + 'csrf-token.php', { credentials: 'same-origin' });
  if (!res.ok) throw new Error('Could not start a secure session.');

  const data = await res.json();
  if (!data.ok || !data.token) throw new Error('Could not start a secure session.');

  csrfToken = data.token;
  return csrfToken;
}

function postOnce(path, body, token) {
  return fetch(path, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': token
    },
    body: JSON.stringify(body || {})
  });
}

async function readJson(res) {
  try {
    return await res.json();
  } catch {
    return { ok: false, error: 'The server sent back something unreadable. Try again.' };
  }
}

/*
  POSTs JSON and returns the parsed body alongside the status.
  The server marks a rejected CSRF token with code: 'csrf', which normally
  means the session idled out. That is worth one silent refresh and retry;
  anything else is a real answer and goes straight back to the caller.
*/
async function apiPost(endpoint, body) {
  const path = API_BASE + endpoint;

  let res  = await postOnce(path, body, await getCsrfToken());
  let data = await readJson(res);

  if (data.code === 'csrf') {
    csrfToken = null;
    res  = await postOnce(path, body, await getCsrfToken());
    data = await readJson(res);
  }

  return { status: res.status, data };
}

/* The Student No. printed on the study load, like C24-01-9477-MAN121.
   Same rule as api/student-no.php. */
const STUDENT_NO_RE = /^[A-Z]\d{2}-\d{2}-\d{4}-[A-Z]{3}\d{3}$/;
const STUDENT_NO_MESSAGE = 'Use the Student No. on your study load, like C24-01-9477-MAN121.';

function formatStudentNo(raw) {
  const c = raw.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 15);
  return [c.slice(0, 3), c.slice(3, 5), c.slice(5, 9), c.slice(9)].filter(Boolean).join('-');
}

/* Capitals and dashes appear as the student types, so the format shows
   itself instead of being explained. */
function attachStudentNoFormat(input) {
  if (!input) return;
  input.addEventListener('input', () => { input.value = formatStudentNo(input.value); });
}

function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function showError(fieldId) {
  const input = document.getElementById(fieldId);
  const err = document.getElementById('err-' + fieldId);
  if (input) input.style.borderColor = 'var(--danger)';
  if (err) err.classList.add('visible');
}

function showFieldErrorById(errId) {
  const err = document.getElementById(errId);
  if (err) err.classList.add('visible');
}

function clearErrors() {
  document.querySelectorAll('.field-error').forEach(el => el.classList.remove('visible'));
  document.querySelectorAll('input').forEach(el => el.style.borderColor = '');
  const alertBox = document.getElementById('alertBox');
  if (alertBox) {
    alertBox.classList.remove('visible', 'success', 'error');
  }
}

function showAlert(message, type) {
  const alertBox = document.getElementById('alertBox');
  if (!alertBox) return;
  alertBox.textContent = message;
  alertBox.classList.remove('success', 'error');
  alertBox.classList.add('visible', type);
}
