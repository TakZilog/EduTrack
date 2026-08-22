/*
  EduTrack — shared auth form helpers
  Used by register.html, login.html, verify-otp.html.
  Pure client-side validation/UI helpers for now — once api/*.php exists,
  replace the "TEMPORARY mock flow" blocks in each page with real fetch()
  calls to the backend, and keep using these same helper functions.
*/

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
