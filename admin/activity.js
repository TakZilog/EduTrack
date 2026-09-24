// Script for activity.html. Kept out of the page so the Content-Security-Policy
// can refuse inline scripts (see .htaccess).

(async function () {
  const s = await boot();
  if (!s) return;

  const rows = document.getElementById('rows');
  const pager = document.getElementById('pager');
  const drawer = document.getElementById('drawer');
  const printHost = document.getElementById('printLog');
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

  const state = { search: '', who: '', action: '', date: '', dateFrom: '', dateTo: '', page: 1, openId: null };
  let filtersBuilt = false;

  /* On a phone the details sit below the list, out of sight. */
  const PHONE = window.matchMedia('(max-width: 720px)');

  /* The stored action name is a dotted key; these are the words for it.
     Every action the API writes with audit_log() belongs here. */
  const WORDS = {
    'admin.login': 'Signed in',
    'admin.logout': 'Signed out',
    'admin.failed_login': 'Failed sign-in',
    'admin.create': 'Staff added',
    'admin.update': 'Staff details changed',
    'admin.enable': 'Staff account turned on',
    'admin.disable': 'Staff account turned off',
    'admin.password': 'Password changed',
    'access.denied': 'Access denied',
    'settings.save': 'Settings changed',
    'settings.student-verification': 'Student verification changed',
    'settings.access': 'Access restriction changed',
    'room.add': 'Room added',
    'room.photos': 'Room photos replaced',
    'room.rename': 'Room renamed',
    'room.swap': 'Rooms swapped',
    'room.remove': 'Room removed',
    'room.relist': 'Room listed again',
    'photo.replace': 'Photo replaced',
    'student.verify': 'Student verified',
    'student.deactivate': 'Student account turned off',
    'student.reactivate': 'Student account turned on',
    'student.delete': 'Student deleted'
  };
  const labelOf = action => WORDS[action] || action;

  /* Actions worth a second look get a colour and a tag. */
  const KINDS = {
    'access.denied': ['error', 'Security', 'revoked'],
    'admin.failed_login': ['error', 'Security', 'revoked'],
    'room.remove': ['warning', 'Deleted', 'warning'],
    'student.delete': ['warning', 'Deleted', 'warning'],
    'admin.create': ['success', 'Added', 'verified'],
    'room.add': ['success', 'Added', 'verified']
  };

  /* The log stores the role key. These are the same words the rest of the
     panel uses for it. */
  const ROLES = {
    super_admin: 'Administrator',
    admin: 'Map Editor',
    faculty: 'View Only',
    none: 'Not signed in',
    server: 'Server command line'
  };

  /* The request for the current filters. The words on screen are not stored,
     so a search also sends the actions whose words match it. */
  function query(extra) {
    const term = state.search.toLowerCase();
    const named = term === '' ? [] : Object.keys(WORDS).filter(k => WORDS[k].toLowerCase().includes(term));
    return {
      search: state.search, search_actions: named.join(','), who: state.who, action: state.action,
      date: state.date, date_from: state.dateFrom, date_to: state.dateTo, ...extra
    };
  }

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

  async function load() {
    showSkeleton(rows, 8);
    const { data } = await apiGet('activity.php', query({ page: state.page }));

    if (!data.ok) { toast(data.error || 'Could not load the activity log.', 'bad'); return; }

    if (!filtersBuilt) {
      data.people.forEach(p => {
        const o = document.createElement('option');
        o.value = p; o.textContent = p;
        whoSelect.appendChild(o);
      });
      data.actions
        .slice()
        .sort((a, b) => labelOf(a).localeCompare(labelOf(b)))
        .forEach(a => {
          const o = document.createElement('option');
          o.value = a; o.textContent = labelOf(a);
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

    rows.replaceChildren();
    data.data.forEach(entry => {
      const title = labelOf(entry.action);
      const [status, tag, tone] = KINDS[entry.action] || ['normal'];
      const end = [];
      if (tag) end.push(pill(tag, tone));
      const when = document.createElement('span');
      when.className = 'row-when';
      when.textContent = relativeTime(entry.created_at);
      end.push(when);

      // "Signed in" over "Jason · Signed in." says it twice.
      const detail = entry.detail && entry.detail.replace(/\.$/, '') !== title ? entry.detail : '';

      rows.appendChild(listRow({
        title,
        sub: entry.admin_name + (detail ? ' · ' + detail : ''),
        end,
        selected: entry.id === state.openId,
        onOpen: row => {
          markOpenRow(rows, row);
          openEntry(entry);
          if (PHONE.matches) drawer.scrollIntoView({ block: 'start' });
        }
      }));
      rows.lastElementChild.querySelector('.row').dataset.status = status;
    });

    drawPager(pager, data, page => { state.page = page; load(); });

    const still = data.data.find(e => e.id === state.openId);
    openEntry(still || null);
  }

  function openEntry(entry) {
    state.openId = entry ? entry.id : null;

    if (!entry) { drawDrawer(drawer, null); return; }

    const ip = document.createElement('span');
    ip.className = 'mono';
    ip.textContent = entry.ip || '—';

    drawDrawer(drawer, {
      title: labelOf(entry.action),
      sub: relativeTime(entry.created_at),
      body: facts([
        ['Who', entry.admin_name],
        ['Access level', ROLES[entry.role] || entry.role],
        ['Room', entry.target_type === 'room' ? entry.target_id : null],
        ['Exactly when', formatDateTime(entry.created_at)],
        ['Action details', entry.detail || 'None recorded'],
        ['IP address', ip]
      ])
    });
  }

  drawDrawer(drawer, null);

  function closeExportMenu() {
    exportMenu.hidden = true;
    exportToggle.setAttribute('aria-expanded', 'false');
  }
  exportToggle.addEventListener('click', () => {
    const isOpen = !exportMenu.hidden;
    exportMenu.hidden = isOpen;
    exportToggle.setAttribute('aria-expanded', String(!isOpen));
  });
  document.addEventListener('click', event => {
    if (!event.target.closest('.export-menu')) closeExportMenu();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeExportMenu();
  });

  /* An export covers every entry that fits the filters, not just this page. */
  async function everyMatch() {
    closeExportMenu();
    exportToggle.disabled = true;
    try {
      const { data } = await apiGet('activity.php', query({ all: 1 }));
      if (!data.ok) { toast(data.error || 'Could not export the activity log.', 'bad'); return null; }
      if (data.data.length === 0) { toast('Nothing to export. No activity fits the filters.', 'bad'); return null; }
      return data.data;
    } finally {
      exportToggle.disabled = false;
    }
  }

  /* The filters in words, so a saved copy says what it contains. */
  function filterSummary() {
    const parts = [];
    if (state.search) parts.push('Matching "' + state.search + '"');
    if (state.who) parts.push('By ' + state.who);
    if (state.action) parts.push(labelOf(state.action));
    if (state.date === 'custom') parts.push((state.dateFrom || 'Start') + ' to ' + (state.dateTo || 'today'));
    else if (state.date) parts.push(dateSelect.selectedOptions[0].textContent);
    return parts.length ? parts.join(' · ') : 'All activity';
  }

  exportCsv.addEventListener('click', async () => {
    const entries = await everyMatch();
    if (!entries) return;

    // A cell starting with = + - or @ runs as a formula in a spreadsheet.
    const cell = value => {
      let text = String(value ?? '');
      if (/^[=+\-@\t\r]/.test(text)) text = "'" + text;
      return '"' + text.replaceAll('"', '""') + '"';
    };
    const lines = [['When', 'Who', 'Access level', 'Action', 'Details', 'Target', 'IP address'], ...entries.map(entry => [
      entry.created_at, entry.admin_name, ROLES[entry.role] || entry.role, labelOf(entry.action),
      entry.detail || '', entry.target_id || '', entry.ip || ''
    ])];
    // The byte-order mark makes Excel read names with accents correctly.
    const csv = '﻿' + lines.map(line => line.map(cell).join(',')).join('\r\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    link.download = 'edutrack-activity-log-' + new Date().toLocaleDateString('en-CA') + '.csv';
    link.click();
    URL.revokeObjectURL(link.href);
  });

  exportPdf.addEventListener('click', async () => {
    const entries = await everyMatch();
    if (!entries) return;

    const note = document.createElement('p');
    note.textContent = filterSummary() + ' · ' + entries.length + (entries.length === 1 ? ' entry' : ' entries')
      + ' · Exported ' + new Date().toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' });

    const table = document.createElement('table');
    const head = table.createTHead().insertRow();
    ['When', 'Who', 'Action', 'Details', 'IP address'].forEach(text => {
      const th = document.createElement('th');
      th.textContent = text;
      head.appendChild(th);
    });
    const body = table.createTBody();
    entries.forEach(entry => {
      const row = body.insertRow();
      [formatDateTime(entry.created_at), entry.admin_name, labelOf(entry.action), entry.detail || '', entry.ip || '']
        .forEach(value => { row.insertCell().textContent = value; });
    });

    printHost.replaceChildren(note, table);
    document.body.classList.add('print-export');
    window.print();
  });
  window.addEventListener('afterprint', () => document.body.classList.remove('print-export'));

  load();
})();
