// Script for add-details.html. Kept out of the page so the Content-Security-Policy
// can refuse inline scripts (see .htaccess).

/* Where to go once the numbers are saved. Only the two tour pages are
   accepted, so ?next= cannot be used to bounce someone off the site. */
function nextPage() {
  const next = new URLSearchParams(window.location.search).get('next') || '';
  return /^(select-room|walkthrough)\.html(\?[^#]*)?$/.test(next) ? '../map/' + next : '../map/select-room.html';
}

document.getElementById('detailsForm').addEventListener('submit', function (e) {
  e.preventDefault();
  clearErrors();

  const studentNo = document.getElementById('studentNo').value.trim();
  const studyLoadNo = document.getElementById('studyLoadNo').value.trim();

  let valid = true;
  if (!/^[A-Za-z0-9-]{4,20}$/.test(studentNo)) { showError('studentNo'); valid = false; }
  if (!/^[A-Za-z0-9-]{3,30}$/.test(studyLoadNo)) { showError('studyLoadNo'); valid = false; }
  if (!valid) return;

  setSubmitting(true);
  apiPost('enrolment-details.php', { studentNo, studyLoadNo })
    .then(({ status, data }) => {
      // 409: already on file, so the tour is open anyway.
      if (data.ok || status === 409) { window.location.href = nextPage(); return; }
      if (data.code === 'tour_locked') { window.location.href = 'login.html?next=tour'; return; }
      setSubmitting(false);
      showAlert(data.error || 'Could not save your details.', 'error');
    })
    .catch(() => {
      setSubmitting(false);
      showAlert('Could not reach server. Try again.', 'error');
    });
});

function setSubmitting(state) {
  const btn = document.getElementById('submitBtn');
  btn.disabled = state;
  btn.textContent = state ? 'Saving…' : 'Open the room tour';
}
