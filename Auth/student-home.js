// Script for student-home.html. Kept out of the page so the Content-Security-Policy
// can refuse inline scripts (see .htaccess).

fetch('../api/whoami.php', { credentials: 'same-origin' })
  .then(response => response.json())
  .then(data => {
    if (!data.signedIn) {
      window.location.href = 'login.html';
      return;
    }
    document.getElementById('studentName').textContent = data.fullName;
    document.getElementById('studentId').textContent = data.studentId;
    document.getElementById('program').textContent = data.program;
  })
  .catch(() => { window.location.href = 'login.html'; });

document.querySelectorAll('.class-row').forEach(row => {
  row.addEventListener('click', () => {
    const detail = document.getElementById(row.getAttribute('aria-controls'));
    const open = detail.hidden;
    detail.hidden = !open;
    row.setAttribute('aria-expanded', String(open));
  });
});

document.getElementById('signOut').addEventListener('click', async () => {
  // The room map and photos saved for offline go first, whether or not the
  // server answers, so the next person on this phone cannot open them.
  if (window.EduTrackOffline) await EduTrackOffline.forget();
  await apiPost('logout.php');
  window.location.href = '../index.html';
});
