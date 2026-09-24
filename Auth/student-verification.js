// Script for student-verification.html. Kept out of the page so the Content-Security-Policy
// can refuse inline scripts (see .htaccess).

// Verification is for a student who has already logged in; it is not a way in.
fetch('../api/whoami.php', { credentials: 'same-origin' })
  .then(response => response.json())
  .then(data => { if (!data.signedIn) window.location.href = 'login.html'; })
  .catch(() => { window.location.href = 'login.html'; });

const panels = [...document.querySelectorAll('[data-step]')];
const progress = [...document.querySelectorAll('[data-progress]')];
const state = { step: 1, studentName: '', maskedStudentId: '', program: '' };

function showStep(step) {
  state.step = step;
  panels.forEach(panel => { panel.hidden = panel.dataset.step !== String(step); });
  progress.forEach(item => {
    const number = Number(item.dataset.progress);
    item.classList.toggle('is-current', number === step);
    item.classList.toggle('is-done', number < step);
  });
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function fillIdentity(data) {
  state.studentName = data.studentName;
  state.maskedStudentId = data.maskedStudentId;
  state.program = data.program;
  document.querySelectorAll('[data-student-name]').forEach(el => { el.textContent = state.studentName; });
  document.querySelectorAll('[data-student-id]').forEach(el => { el.textContent = state.maskedStudentId; });
  document.querySelectorAll('[data-program]').forEach(el => { el.textContent = state.program; });
}

function showAlert(id, message, type = 'error') {
  const alert = document.getElementById(id);
  alert.textContent = message;
  alert.className = 'alert is-visible is-' + type;
}

function clearAlert(id) {
  const alert = document.getElementById(id);
  alert.className = 'alert';
  alert.textContent = '';
}

document.getElementById('startForm').addEventListener('submit', async event => {
  event.preventDefault();
  clearAlert('startAlert');
  const studentId = formatStudentNo(document.getElementById('studentId').value);
  if (!STUDENT_NO_RE.test(studentId)) {
    showAlert('startAlert', STUDENT_NO_MESSAGE);
    document.getElementById('studentId').focus();
    return;
  }
  const button = document.getElementById('verifyStudentButton');
  button.disabled = true;
  button.textContent = 'Verifying…';

  try {
    const { data } = await apiPost('student-verification.php', {
      action: 'lookup',
      studentId,
      schoolYear: document.getElementById('schoolYear').value,
      semester: document.getElementById('semester').value
    });
    if (!data.ok) {
      if (data.code === 'tour_locked') { window.location.href = 'login.html'; return; }
      showStep('failed');
      return;
    }
    fillIdentity(data);
    showStep(2);
  } catch {
    showStep('failed');
  } finally {
    button.disabled = false;
    button.textContent = 'Verify Student';
  }
});

document.getElementById('identityContinue').addEventListener('click', () => showStep(3));

document.getElementById('verifyStudyButton').addEventListener('click', async () => {
  clearAlert('studyAlert');
  const button = document.getElementById('verifyStudyButton');
  button.disabled = true;
  button.textContent = 'Verifying…';
  try {
    const { data } = await apiPost('student-verification.php', { action: 'verify-study-load' });
    if (!data.ok) {
      showAlert('studyAlert', data.error || 'We could not verify your current study load.');
      return;
    }
    fillIdentity(data);
    showStep(4);
  } catch {
    showAlert('studyAlert', 'Could not reach EduTrack. Try again.');
  } finally {
    button.disabled = false;
    button.textContent = 'Verify Study Load';
  }
});

document.querySelectorAll('[data-back]').forEach(button => button.addEventListener('click', () => showStep(Math.max(1, state.step - 1))));
document.getElementById('tryAgain').addEventListener('click', () => {
  document.getElementById('startForm').reset();
  clearAlert('startAlert');
  showStep(1);
});
document.getElementById('continueToEduTrack').addEventListener('click', () => {
  window.location.href = 'student-home.html';
});

attachStudentNoFormat(document.getElementById('studentId'));
