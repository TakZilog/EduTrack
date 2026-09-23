// Script for login.html. Kept out of the page so the Content-Security-Policy
// can refuse inline scripts (see .htaccess).

const form = document.getElementById('form');
const submit = document.getElementById('submit');
const alertBox = document.getElementById('alert');
const alertText = document.getElementById('alertText');

let token = null;

async function getToken() {
  if (token) return token;
  const res = await fetch('../api/csrf-token.php', { credentials: 'same-origin' });
  const data = await res.json();
  token = data.token;
  return token;
}

function fail(message, status) {
  // 429 is the throttle, not a bad password. Saying "could not sign in"
  // there sends people back to retype a password that was already right.
  document.getElementById('alertTitle').textContent =
    status === 429 ? 'Too many tries' : 'Could not sign in';
  alertText.textContent = message;
  alertBox.hidden = false;
}

function busy(state) {
  submit.disabled = state;
  submit.textContent = state ? 'Signing in…' : 'Sign in';
}

form.addEventListener('submit', async e => {
  e.preventDefault();
  alertBox.hidden = true;
  document.querySelectorAll('.field-error').forEach(el => el.classList.remove('on'));

  const username = document.getElementById('username').value.trim();
  const password = document.getElementById('password').value;

  let ok = true;
  if (!username) { document.getElementById('err-username').classList.add('on'); ok = false; }
  if (!password) { document.getElementById('err-password').classList.add('on'); ok = false; }
  if (!ok) return;

  busy(true);
  try {
    const res = await fetch('../api/admin/login.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': await getToken() },
      body: JSON.stringify({ username, password })
    });
    const data = await res.json();

    if (!data.ok) {
      token = null;             // it may have been the token that was stale
      busy(false);
      fail(data.error || 'Sign in failed. Please try again.', res.status);
      return;
    }
    window.location.href = 'index.html';
  } catch {
    busy(false);
    fail('Could not reach the server. Check that XAMPP is running, then try again.', 0);
  }
});
