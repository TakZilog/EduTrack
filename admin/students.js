// Script for students.html. Kept out of the page so the Content-Security-Policy
// can refuse inline scripts (see .htaccess).

(async function () {
  const s = await boot();
  if (!s) return;

  const rows = document.getElementById('rows');
  const pager = document.getElementById('pager');
  const drawer = document.getElementById('drawer');
  const listTitle = document.getElementById('listTitle');

  const params = new URLSearchParams(window.location.search);
  const state = {
    status: params.get('status') || 'all',
    q: '',
    page: 1,
    openId: null            // the student shown in the drawer
  };

  const STATUS = {
    verified: { label: 'Can sign in', kind: 'verified' },
    pending:  { label: 'Waiting to verify', kind: 'pending' },
    off:      { label: 'Turned off', kind: 'off' }
  };

  const TAB_TEXT = {
    all: 'All students',
    verified: 'Students who can sign in',
    pending: 'Students waiting to verify their email',
    off: 'Students whose accounts are turned off'
  };

  function paintTabs() {
    document.querySelectorAll('.tab').forEach(t => {
      t.setAttribute('aria-pressed', String(t.dataset.status === state.status));
    });
    listTitle.textContent = TAB_TEXT[state.status];
  }

  document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', () => {
      state.status = tab.dataset.status;
      state.page = 1;
      // Kept in the address bar so the view can be refreshed or shared.
      const url = new URL(window.location);
      url.searchParams.set('status', state.status);
      history.replaceState(null, '', url);
      paintTabs();
      load();
    });
  });

  document.getElementById('q').addEventListener('input', debounce(e => {
    state.q = e.target.value.trim();
    state.page = 1;
    load();
  }, 300));

  async function load() {
    showSkeleton(rows, 6);
    const { data } = await apiGet('students.php', {
      status: state.status, q: state.q, page: state.page
    });

    if (!data.ok) {
      toast(data.error || 'Could not load students.', 'bad');
      return;
    }

    if (data.data.length === 0) {
      // Two different situations, two different messages.
      const filtered = state.q !== '' || state.status !== 'all';
      showEmpty(rows,
        filtered ? 'No students match' : 'No students yet',
        filtered
          ? 'Nothing here fits what you searched for.'
          : 'Students appear here once they register.',
        filtered ? 'Show everyone' : null,
        filtered ? () => {
          state.q = ''; state.status = 'all'; state.page = 1;
          document.getElementById('q').value = '';
          paintTabs(); load();
        } : null
      );
      drawPager(pager, data, () => {});
      openStudent(null);
      return;
    }

    rows.replaceChildren();
    data.data.forEach(student => {
      const meta = STATUS[student.status];
      rows.appendChild(listRow({
        title: student.fullName,
        sub: student.email,
        end: [pill(meta.label, meta.kind)],
        selected: student.id === state.openId,
        onOpen: row => { markOpenRow(rows, row); openStudent(student); }
      }));
    });

    drawPager(pager, data, page => { state.page = page; load(); });

    // Keep the drawer honest after a reload: the open record is redrawn from
    // the answer that just arrived, or closed if that student is not here.
    const still = data.data.find(st => st.id === state.openId);
    openStudent(still || null);
  }

  function openStudent(student) {
    state.openId = student ? student.id : null;

    if (!student) {
      drawDrawer(drawer, null);
      return;
    }

    const meta = STATUS[student.status];

    drawDrawer(drawer, {
      title: student.fullName,
      sub: student.email,
      body: facts([
        ['Account', pill(meta.label, meta.kind)],
        ['Student ID number', student.studentNo || 'Not on file'],
        ['Study load number', student.studyLoadNo || 'Not on file'],
        ['Registered', formatDate(student.createdAt)],
        ['Last signed in', student.lastLogin ? relativeTime(student.lastLogin) : 'Never']
      ]),
      actions: buildActions(student)
    });
  }

  function buildActions(student) {
    const out = [];

    if (student.status === 'pending' && allowed('student.verify')) {
      out.push(button('Mark as verified', 'btn', async () => {
        const yes = await confirmAction({
          title: 'Mark as verified?',
          message: student.fullName + ' will be able to sign in straight away, without clicking the link in their email.',
          confirmLabel: 'Yes, mark verified'
        });
        if (yes) act({ action: 'verify', id: student.id });
      }));
    }

    if (allowed('student.deactivate')) {
      if (student.status === 'off') {
        out.push(button('Turn the account back on', 'btn-quiet', async () => {
          const yes = await confirmAction({
            title: 'Turn this account back on?',
            message: student.fullName + ' will be able to sign in again.',
            confirmLabel: 'Yes, turn it on'
          });
          if (yes) act({ action: 'reactivate', id: student.id });
        }));
      } else {
        out.push(button('Turn the account off', 'btn-quiet', async () => {
          const yes = await confirmAction({
            title: 'Turn this account off?',
            message: student.fullName + ' will not be able to sign in. Nothing is deleted, and you can turn it back on at any time.',
            confirmLabel: 'Yes, turn it off'
          });
          if (yes) act({ action: 'deactivate', id: student.id });
        }));
      }
    }

    if (allowed('student.delete')) {
      out.push(button('Delete this student', 'btn-danger', async () => {
        const typed = await confirmAction({
          title: 'Delete this student for good?',
          message: 'This cannot be undone. ' + student.fullName + "'s account will be removed completely. If you only want to stop them signing in, turn the account off instead.",
          confirmLabel: 'Delete permanently',
          danger: true,
          typeToConfirm: student.email
        });
        if (typed) act({ action: 'delete', id: student.id, confirmEmail: typed });
      }));
    }

    return out;
  }

  async function act(payload) {
    const { data } = await apiPost('student-action.php', payload);
    if (!data.ok) {
      toast(data.error || 'That did not work.', 'bad');
      return;
    }
    toast(data.message || 'Done.');
    // A deleted student cannot stay open in the drawer.
    if (payload.action === 'delete') state.openId = null;
    load();
  }

  paintTabs();
  drawDrawer(drawer, null);
  load();
})();
