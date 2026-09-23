// Script for verify-otp.html. Kept out of the page so the Content-Security-Policy
// can refuse inline scripts (see .htaccess).

const email = sessionStorage.getItem('pendingEmail');
if (email) {
  document.getElementById('subtitleText').textContent = `Enter the 6-digit code sent to ${email}.`;
}

// Auto-advance between OTP boxes
const digits = document.querySelectorAll('.otp-digit');
digits.forEach((input, i) => {
  input.addEventListener('input', () => {
    input.value = input.value.replace(/[^0-9]/g, '');
    if (input.value && i < digits.length - 1) digits[i + 1].focus();
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Backspace' && !input.value && i > 0) digits[i - 1].focus();
  });
});

document.getElementById('otpForm').addEventListener('submit', function (e) {
  e.preventDefault();
  clearErrors();

  const code = Array.from(digits).map(d => d.value).join('');
  if (code.length !== 6) {
    showFieldErrorById('err-otp');
    return;
  }

  setSubmitting(true);
  apiPost('verify-otp.php', { code })
    .then(({ data }) => {
      setSubmitting(false);
      if (!data.ok) {
        showFieldErrorById('err-otp');
        showAlert(data.error || 'Verification failed.', 'error');
        return;
      }
      showAlert('Verified! Redirecting to login…', 'success');
      setTimeout(() => window.location.href = 'login.html', 900);
    })
    .catch(() => {
      setSubmitting(false);
      showAlert('Could not reach server. Try again.', 'error');
    });
});

document.getElementById('resendLink').addEventListener('click', function (e) {
  e.preventDefault();
  if (!email) {
    showAlert('No pending email found. Please register again.', 'error');
    return;
  }
  apiPost('resend-otp.php')
    .then(({ data }) => {
      showAlert(data.ok ? 'A new code has been sent.' : (data.error || 'Failed to resend code.'), data.ok ? 'success' : 'error');
    })
    .catch(() => showAlert('Could not reach server. Try again.', 'error'));
});

function setSubmitting(state) {
  const btn = document.getElementById('submitBtn');
  btn.disabled = state;
  btn.textContent = state ? 'Verifying…' : 'Verify';
}
