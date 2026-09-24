// Script for register.html. Kept out of the page so the Content-Security-Policy
// can refuse inline scripts (see .htaccess).

document.getElementById('registerForm').addEventListener('submit', function (e) {
  e.preventDefault();
  clearErrors();

  const fullName = document.getElementById('fullName').value.trim().replace(/\s+/g, ' ');
  const email = document.getElementById('email').value.trim();
  const studentNo = formatStudentId(document.getElementById('studentNo').value);
  const studyLoadNo = formatStudyLoadNo(document.getElementById('studyLoadNo').value);
  const password = document.getElementById('password').value;
  const confirmPassword = document.getElementById('confirmPassword').value;

  let valid = true;
  if (fullName.length < 2) { showError('fullName'); valid = false; }
  if (!STUDENT_ID_RE.test(studentNo)) { showError('studentNo'); valid = false; }
  if (!STUDY_LOAD_RE.test(studyLoadNo)) { showError('studyLoadNo'); valid = false; }
  if (!isValidEmail(email)) { showError('email'); valid = false; }
  if (password.length < 8) { showError('password'); valid = false; }
  if (password !== confirmPassword) { showError('confirmPassword'); valid = false; }


  if (!valid) return;

  setSubmitting(true);
  apiPost('register.php', { fullName, email, password, studentNo, studyLoadNo })
    .then(({ data }) => {
      if (!data.ok) {
        showAlert(data.error || 'Failed to send verification code.', 'error');
        setSubmitting(false);
        return;
      }
      sessionStorage.setItem('pendingEmail', email);
      window.location.href = 'verify-otp.html';
    })
    .catch(() => {
      showAlert('Could not reach server. Try again.', 'error');
      setSubmitting(false);
    });
});

function setSubmitting(state) {
  const btn = document.getElementById('submitBtn');
  btn.disabled = state;
  btn.textContent = state ? 'Creating account…' : 'Create account';
}

attachStudyLoadFormat(document.getElementById('studyLoadNo'));
