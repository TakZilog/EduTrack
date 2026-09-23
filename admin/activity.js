// Script for activity.html. Kept out of the page so the Content-Security-Policy
// can refuse inline scripts (see .htaccess).

(async function () {
  const s = await boot();
  if (!s) return;

  const rows = document.getElementById('rows');
  const pager = document.getElementById('pager');
  const drawer = document.getElementById('drawer');
  const searchInput = document.getElementById('search');
  const whoSelect = document.getElementById('who');
  const whatSelect = document.getElementById('what');
  const dateSelect = document.getElementById('date');
  const customDates = document.getElementById('customDates');
  const dateFrom = document.getElementById('dateFrom');
  const dateTo = document.getElementById('dateTo');
  const exportToggle = document.getElementById('exportToggle');
  const exportMenu = document.getElementById('exportMenu');
  const exportCsv = document.getElementById('exportCsv');
  const exportPdf = document.getElementById('exportPdf');

  const state = { search: '', who: '', action: '', date: '', dateFrom: '', dateTo: '', page: 1, openKey: null };
  let filtersBuilt = false;
  let visibleEntries = [];

  /* The stored action name is a dotted key; these are the words for it. */
  const WORDS = {
    'admin.login': 'Signed in',
    'admin.logout': 'Signed out',
    'admin.create': 'User created',
    'admin.enable': 'User access changed',
    'admin.disable': 'User access changed',
    'settings.save': 'Settings changed',
    'access.denied': 'Access denied',
    'room.register': 'Room registered',
    'room.update': 'Room updated',
    'room.remove': 'Room removed',
    'route.create': 'Route created',
    'route.update': 'Route updated',
    'route.remove': 'Route removed',
    'walkthrough.add': 'Walkthrough added',
    'walkthrough.update': 'Walkthrough updated',
    'walkthrough.photo.remove': 'Walkthrough photo removed',
    'map.issue.detected': 'Map issue detected',
    'map.issue.resolved': 'Map issue resolved',
    'user.create': 'User created',
    'user.access.change': 'User access changed',
    'admin.failed_login': 'Failed sign-in'
  };

  /* The log stores the role key. These are the same words the rest of the
     panel uses for it. */
  const ROLES = {
    super_admin: 'Administrator',
    admin: 'Map Editor',
    faculty: 'View Only'
  };

  const reload = () => { state.page = 1; load(); };
  searchInput.addEventListener('input', debounce(e => { state.search = e.target.value.trim(); reload(); }, 350));
  whoSelect.addEventListener('change', e => { state.who = e.target.value; reload(); });
  whatSelect.addEventListener('change', e => { state.action = e.target.value; reload(); });
  dateSelect.addEventListener('change', e => {
    state.date = e.target.value;
    customDates.hidden = state.date !== 'custom';
    reload();
  });
  [dateFrom, dateTo].forEach(input => input.addEventListener('change', e => {
    state[e.target === dateFrom ? 'dateFrom' : 'dateTo'] = e.target.value;
    if (state.date === 'custom') reload();
  }));

  /* Entries have no id of their own, so an open one is remembered by the
     things that make it unique: when it happened, who did it, and what. */
  const keyOf = entry => entry.created_at + '|' + entry.admin_name + '|' + entry.action;

  async function load() {
    showSkeleton(rows, 8);
    const { data } = await apiGet('activity.php', {
      search: state.search, who: state.who, action: state.action, date: state.date,
      date_from: state.dateFrom, date_to: state.dateTo, page: state.page
    });

    if (!data.ok) { toast(data.error || 'Could not load the activity log.', 'bad'); return; }

    if (!filtersBuilt) {
      data.people.forEach(p => {
        const o = document.createElement('option');
        o.value = p; o.textContent = p;
        whoSelect.appendChild(o);
      });
      data.actions.forEach(a => {
        const o = document.createElement('option');
        o.value = a; o.textContent = WORDS[a] || a;
        whatSelect.appendChild(o);
      });
      filtersBuilt = true;
    }

    if (data.data.length === 0) {
      const filtered = state.search !== '' || state.who !== '' || state.action !== '' || state.date !== '';
      showEmpty(rows,
        filtered ? 'Nothing matches' : 'Nothing has happened yet',
        filtered
          ? 'No activity fits the filters you chose.'
          : 'Actions taken in this panel will be listed here as they happen.',
        filtered ? 'Clear the filters' : null,
        filtered ? () => {
          state.search = ''; state.who = ''; state.action = ''; state.date = ''; state.dateFrom = ''; state.dateTo = ''; state.page = 1;
          searchInput.value = ''; whoSelect.value = ''; whatSelect.value = ''; dateSelect.value = '';
          dateFrom.value = ''; dateTo.value = ''; customDates.hidden = true;
          load();
        } : null);
      drawPager(pager, data, () => {});
      openEntry(null);
      return;
    }

    visibleEntries = data.data;
    rows.replaceChildren();
    data.data.forEach(entry => {
      const end = [];
      const isError = entry.action === 'access.denied' || entry.action === 'admin.failed_login';
      const isWarning = entry.action === 'map.issue.detected';
      const isSuccess = ['room.register', 'route.create', 'walkthrough.add', 'map.issue.resolved', 'user.create'].includes(entry.action);
      if (isError) end.push(pill('Security', 'revoked'));
      else if (isWarning) end.push(pill('Review', 'warning'));
      else if (isSuccess) end.push(pill('Success', 'verified'));
      const when = document.createElement('span');
      when.className = 'row-when';
      when.textContent = relativeTime(entry.created_at);
      end.push(when);

      rows.appendChild(listRow({
        title: WORDS[entry.action] || entry.action,
        sub: entry.admin_name + (entry.detail ? ' · ' + entry.detail : ''),
        end,
        selected: keyOf(entry) === state.openKey,
        onOpen: row => { markOpenRow(rows, row); openEntry(entry); }
      }));
      rows.lastElementChild.querySelector('.row').dataset.status = isError ? 'error' : isWarning ? 'warning' : isSuccess ? 'success' : 'normal';
    });

    drawPager(pager, data, page => { state.page = page; load(); });

    const still = data.data.find(e => keyOf(e) === state.openKey);
    openEntry(still || null);
  }

  function openEntry(entry) {
    state.openKey = entry ? keyOf(entry) : null;

    if (!entry) { drawDrawer(drawer, null); return; }

    const ip = document.createElement('span');
    ip.className = 'mono';
    ip.textContent = entry.ip || '—';

    drawDrawer(drawer, {
      title: WORDS[entry.action] || entry.action,
      sub: relativeTime(entry.created_at),
      body: facts([
        ['Who', entry.admin_name],
        ['Access level', ROLES[entry.role] || entry.role],
        ['Affected room or route', ['room', 'route'].includes(entry.target_type) ? entry.target_id : null],
        ['Location', entry.target_type === 'room' ? 'Admin Building' : null],
        ['Building', entry.target_type === 'building' ? entry.target_id : null],
        ['Floor', entry.target_type === 'floor' ? entry.target_id : null],
        ['Exactly when', formatDateTime(entry.created_at)],
        ['Action details', entry.detail || 'None recorded'],
        ['IP address', ip]
      ])
    });
  }

  drawDrawer(drawer, null);
  exportToggle.addEventListener('click', () => {
    const isOpen = !exportMenu.hidden;
    exportMenu.hidden = isOpen;
    exportToggle.setAttribute('aria-expanded', String(!isOpen));
  });
  document.addEventListener('click', event => {
    if (!event.target.closest('.export-menu')) {
      exportMenu.hidden = true;
      exportToggle.setAttribute('aria-expanded', 'false');
    }
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      exportMenu.hidden = true;
      exportToggle.setAttribute('aria-expanded', 'false');
    }
  });
  exportCsv.addEventListener('click', () => {
    const lines = [['Action', 'Username', 'Description', 'Timestamp', 'Target'], ...visibleEntries.map(entry => [
      WORDS[entry.action] || entry.action, entry.admin_name, entry.detail || '', formatDateTime(entry.created_at), entry.target_id || ''
    ])];
    const csv = lines.map(line => line.map(value => '"' + String(value).replaceAll('"', '""') + '"').join(',')).join('\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    link.download = 'edutrack-activity-log.csv';
    link.click();
    URL.revokeObjectURL(link.href);
  });
  exportPdf.addEventListener('click', () => window.print());
  load();
})();
