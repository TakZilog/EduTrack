// Script for login.html. Kept out of the page so the Content-Security-Policy
// can refuse inline scripts (see .htaccess).

document.getElementById('loginForm').addEventListener('submit', function (e) {
  e.preventDefault();
  clearErrors();

  const email = document.getElementById('email').value.trim();
  const password = document.getElementById('password').value;

  let valid = true;
  if (!email) { showError('email'); valid = false; }
  if (!password) { showError('password'); valid = false; }
  if (!valid) return;

  setSubmitting(true);
  apiPost('login.php', { email, password })
    .then(({ data }) => {
      setSubmitting(false);
      if (!data.ok) {
        showAlert(data.error || 'Login failed.', 'error');
        return;
      }
      // Older accounts have no enrolment numbers; the tour would refuse them.
      window.location.href = data.needsDetails ? 'add-details.html' : 'student-home.html';
    })
    .catch(() => {
      setSubmitting(false);
      showAlert('Could not reach server. Try again.', 'error');
    });
});

function setSubmitting(state) {
  const btn = document.getElementById('submitBtn');
  btn.disabled = state;
  btn.textContent = state ? 'Logging in…' : 'Log in';
}
