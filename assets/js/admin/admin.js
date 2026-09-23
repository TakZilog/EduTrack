/*
  Shared behaviour for every staff panel page.

  Loaded as a plain script (no bundler anywhere in this project). Every page
  calls boot() first, which confirms who is signed in, draws the menu, and
  hands back the session so the page can hide what the person cannot use.

  The panel is built as a desk and a drawer: a list of records on the left,
  the open one on the right. The helpers at the bottom of this file draw both,
  so every page's list rows and record panels behave the same way.
*/

const API = '../api/admin/';

let csrfToken = null;
let session = null;

/* --------------------------------------------------------------- requests */

async function readJson(res) {
  try {
    return await res.json();
  } catch {
    return { ok: false, error: 'The server sent back something unreadable. Please try again.' };
  }
}

async function apiGet(endpoint, params = {}) {
  const query = new URLSearchParams(
    Object.entries(params).filter(([, v]) => v !== '' && v != null)
  ).toString();

  const res = await fetch(API + endpoint + (query ? '?' + query : ''), {
    credentials: 'same-origin'
  });
  const data = await readJson(res);

  if (data.code === 'auth') return toSignIn();
  return { status: res.status, data };
}

async function apiPost(endpoint, body = {}) {
  const send = () => fetch(API + endpoint, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: JSON.stringify(body)
  });

  let res = await send();
  let data = await readJson(res);

  // A rejected token normally means the session idled out. Refresh once.
  if (data.code === 'csrf') {
    const refreshed = await fetch(API + 'session.php', { credentials: 'same-origin' });
    const info = await readJson(refreshed);
    if (info.ok) {
      csrfToken = info.csrfToken;
      res = await send();
      data = await readJson(res);
    }
  }

  if (data.code === 'auth') return toSignIn();
  return { status: res.status, data };
}

function toSignIn() {
  window.location.href = 'login.html';
  return { status: 401, data: { ok: false } };
}

/* ------------------------------------------------------------------ start */

/* One glyph per section, so the rail is scanned by shape rather than counted. */
const MENU = [
  { href: 'index.html',    label: 'Overview',  needs: 'student.view',
    icon: '<rect x="3.5" y="3.5" width="7" height="7" rx="1.2"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.2"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.2"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.2"/>' },
  { href: 'rooms.html',    label: 'Locations', needs: 'room.view',
    icon: '<rect x="6" y="3" width="12" height="18" rx="1"/><circle cx="14.5" cy="12" r="1" fill="currentColor" stroke="none"/>' },
  { href: 'walkthrough.html', label: 'Walkthrough', needs: 'room.view',
    icon: '<path d="M4 5.5 10 3l4 2 4-2.5v16L14 21l-4-2-6 2.5z"/><path d="M10 3v16M14 5v16"/>' },
  { href: 'users-access.html', label: 'Users & Access', needs: 'settings.manage',
    icon: '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19c0-3.3 2.5-5.5 5.5-5.5s5.5 2.2 5.5 5.5"/><circle cx="17.5" cy="9" r="2.6"/><path d="M14.8 13.3c2.2.4 3.7 2.1 3.7 4.4"/>' },
  { href: 'activity.html', label: 'Activity Log', needs: 'audit.view',
    icon: '<path d="M3 12h4l2-7 4 14 2-7h6"/>' },
  { href: 'settings.html', label: 'Settings',  needs: 'settings.manage',
    icon: '<line x1="4" y1="6" x2="20" y2="6"/><circle cx="15" cy="6" r="2"/><line x1="4" y1="12" x2="20" y2="12"/><circle cx="9" cy="12" r="2"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="15" cy="18" r="2"/>' }
];

/* Builds one of the rail's section icons from its path markup. */
function navIcon(pathMarkup) {
  const holder = document.createElement('span');
  holder.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + pathMarkup + '</svg>';
  return holder.firstChild;
}

async function boot() {
  const res = await fetch(API + 'session.php', { credentials: 'same-origin' });
  const data = await readJson(res);

  if (!data.ok) return toSignIn();

  csrfToken = data.csrfToken;
  session = data;
  drawRail();
  return data;
}

function allowed(permission) {
  return session ? session.permissions.includes(permission) : false;
}

function drawRail() {
  const rail = document.getElementById('rail');
  if (!rail) return;

  const here = window.location.pathname.split('/').pop() || 'index.html';

  const head = document.createElement('div');
  head.className = 'rail-head';

  const crest = document.createElement('a');
  crest.className = 'rail-crest';
  crest.href = 'index.html';

  const seal = document.createElement('img');
  seal.src = '../assets/img/aclc-logo.jpg';
  seal.width = 44;
  seal.height = 44;
  seal.alt = '';

  const words = document.createElement('span');
  const mark = document.createElement('span');
  mark.className = 'rail-mark';
  mark.textContent = 'EduTrack';
  const sub = document.createElement('span');
  sub.className = 'rail-sub';
  sub.textContent = 'Staff panel';
  words.append(mark, sub);

  crest.append(seal, words);
  head.appendChild(crest);

  const nav = document.createElement('nav');
  nav.className = 'rail-nav';
  nav.setAttribute('aria-label', 'Sections');

  // Settings sits apart from the working sections, below a divider.
  const work = document.createElement('div');
  work.className = 'nav-group';
  const account = document.createElement('div');
  account.className = 'nav-group';

  MENU.filter(item => allowed(item.needs))
    .forEach(item => {
      const a = document.createElement('a');
      a.className = 'nav-item';
      a.href = item.href;

      const icon = document.createElement('span');
      icon.className = 'nav-icon';
      icon.appendChild(navIcon(item.icon));

      const label = document.createElement('span');
      label.textContent = item.label;

      a.append(icon, label);
      if (item.href === here) a.setAttribute('aria-current', 'page');
      (item.href === 'settings.html' ? account : work).appendChild(a);
    });

  nav.append(work, account);

  const foot = document.createElement('div');
  foot.className = 'rail-foot';

  const who = document.createElement('div');
  const name = document.createElement('div');
  name.className = 'rail-who';
  name.textContent = session.admin.fullName;
  const role = document.createElement('div');
  role.className = 'rail-role';
  role.textContent = session.admin.roleLabel === 'Full access'
    ? 'Administrator / Full Access'
    : session.admin.roleLabel;
  who.append(name, role);

  const out = document.createElement('button');
  out.className = 'btn btn-quiet btn-small';
  out.type = 'button';
  out.textContent = 'Sign out';
  out.addEventListener('click', signOut);

  foot.append(who, out);
  rail.append(head, nav, foot);
}

async function signOut() {
  await apiPost('logout.php');
  window.location.href = 'login.html';
}

/* ----------------------------------------------------------------- toasts */

function toast(message, kind = 'good') {
  let host = document.querySelector('.toasts');
  if (!host) {
    host = document.createElement('div');
    host.className = 'toasts';
    // Announced without stealing focus from whatever the person is doing.
    host.setAttribute('role', 'status');
    host.setAttribute('aria-live', 'polite');
    document.body.appendChild(host);
  }

  const el = document.createElement('div');
  el.className = 'toast ' + kind;
  el.textContent = message;
  host.appendChild(el);

  setTimeout(() => {
    el.dataset.leaving = 'true';
    setTimeout(() => el.remove(), 220);
  }, kind === 'bad' ? 7000 : 4500);
}

/* ----------------------------------------------------------------- dialog */

/**
 * Asks before doing something that cannot be undone. Routine work happens in
 * the drawer; this is only for the questions that must interrupt.
 * When `typeToConfirm` is given, the person must type it exactly.
 */
function confirmAction({ title, message, confirmLabel = 'Yes, do it', danger = false, typeToConfirm = null }) {
  return new Promise(resolve => {
    const dialog = document.createElement('dialog');

    const body = document.createElement('div');
    body.className = 'dialog-body';

    const h = document.createElement('h2');
    h.textContent = title;
    const p = document.createElement('p');
    p.textContent = message;
    body.append(h, p);

    let input = null;
    if (typeToConfirm) {
      const label = document.createElement('label');
      label.setAttribute('for', 'confirmField');
      label.textContent = 'Type ' + typeToConfirm + ' to continue';
      input = document.createElement('input');
      input.type = 'text';
      input.id = 'confirmField';
      input.autocomplete = 'off';
      body.append(label, input);
    }

    const foot = document.createElement('div');
    foot.className = 'dialog-foot';

    const cancel = document.createElement('button');
    cancel.className = 'btn btn-quiet';
    cancel.type = 'button';
    cancel.textContent = 'Cancel';

    const go = document.createElement('button');
    go.className = 'btn' + (danger ? ' btn-danger' : '');
    go.type = 'button';
    go.textContent = confirmLabel;

    foot.append(cancel, go);
    dialog.append(body, foot);
    document.body.appendChild(dialog);

    const close = value => {
      dialog.close();
      dialog.remove();
      resolve(value);
    };

    cancel.addEventListener('click', () => close(null));
    go.addEventListener('click', () => {
      if (typeToConfirm && input.value.trim() !== typeToConfirm) {
        toast('That does not match. Nothing was changed.', 'bad');
        input.focus();
        return;
      }
      close(typeToConfirm ? input.value.trim() : true);
    });
    dialog.addEventListener('cancel', e => { e.preventDefault(); close(null); });

    dialog.showModal();
    (input || go).focus();
  });
}

/* -------------------------------------------------------------- formatting */

function formatDate(value) {
  if (!value) return '—';
  const d = new Date(value.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

function formatDateTime(value) {
  if (!value) return '—';
  const d = new Date(value.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleString(undefined, {
    day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit'
  });
}

/** Plain-language relative time, e.g. "in 43 minutes" or "2 days ago". */
function relativeTime(value) {
  if (!value) return '—';
  const d = new Date(value.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return value;

  const seconds = Math.round((d.getTime() - Date.now()) / 1000);
  const future = seconds > 0;
  const abs = Math.abs(seconds);

  const say = (n, unit) => {
    const plural = n === 1 ? unit : unit + 's';
    return future ? `in ${n} ${plural}` : `${n} ${plural} ago`;
  };

  if (abs < 60) return future ? 'in a moment' : 'just now';
  if (abs < 3600) return say(Math.round(abs / 60), 'minute');
  if (abs < 86400) return say(Math.round(abs / 3600), 'hour');
  if (abs < 2592000) return say(Math.round(abs / 86400), 'day');
  return formatDate(value);
}

/* --------------------------------------------------------- desk + drawer */

function pill(text, kind) {
  const span = document.createElement('span');
  span.className = 'pill pill-' + kind;
  span.textContent = text;
  return span;
}

function button(label, kind, onClick) {
  const b = document.createElement('button');
  b.className = 'btn ' + kind;
  b.type = 'button';
  b.addEventListener('click', onClick);
  b.textContent = label;
  return b;
}

/**
 * One row on the desk. The whole row opens the record, so it is a button:
 * a title, a quieter second line, and whatever belongs at the right edge
 * (a status word, a date).
 */
function listRow({ title, sub, end = [], endMode = 'group', selected = false, onOpen }) {
  const li = document.createElement('li');

  const row = document.createElement('button');
  row.className = 'row';
  row.type = 'button';
  if (selected) row.setAttribute('aria-current', 'true');

  const main = document.createElement('div');
  main.className = 'row-main';

  const t = document.createElement('span');
  t.className = 'row-title';
  t.textContent = title;
  main.appendChild(t);

  if (sub) {
    const s = document.createElement('span');
    s.className = 'row-sub';
    s.textContent = sub;
    main.appendChild(s);
  }

  row.appendChild(main);

  if (end.length && endMode === 'columns') {
    end.forEach(node => {
      node.classList.add('row-end-column');
      row.appendChild(node);
    });
  } else if (end.length) {
    const tail = document.createElement('div');
    tail.className = 'row-end';
    end.forEach(node => tail.appendChild(node));
    row.appendChild(tail);
  }

  row.addEventListener('click', () => onOpen(row));
  li.appendChild(row);
  return li;
}

/** Marks the row that is open, so the list shows where the drawer came from. */
function markOpenRow(list, row) {
  list.querySelectorAll('.row[aria-current]').forEach(r => r.removeAttribute('aria-current'));
  if (row) row.setAttribute('aria-current', 'true');
}

/** A short list of facts inside the drawer: label on the left, value right. */
function facts(pairs) {
  const dl = document.createElement('dl');
  dl.className = 'facts';
  pairs.forEach(([label, value]) => {
    if (value === null || value === undefined) return;
    const wrap = document.createElement('div');
    const dt = document.createElement('dt');
    dt.textContent = label;
    const dd = document.createElement('dd');
    if (value instanceof Node) dd.appendChild(value);
    else dd.textContent = value === '' ? '—' : value;
    wrap.append(dt, dd);
    dl.appendChild(wrap);
  });
  return dl;
}

/**
 * Fills the drawer: a heading, a quieter line under it, the facts, then the
 * buttons. Called with nothing, it shows the "pick one" hint instead.
 */
function drawDrawer(host, record) {
  host.replaceChildren();

  if (!record) {
    const hint = document.createElement('div');
    hint.className = 'drawer-hint';
    const strong = document.createElement('strong');
    strong.textContent = host.dataset.emptyTitle || 'Nothing open';
    const p = document.createElement('p');
    p.style.margin = '0';
    p.textContent = host.dataset.emptyText || 'Choose one from the list to see it here.';
    hint.append(strong, p);
    host.appendChild(hint);
    return;
  }

  const head = document.createElement('div');
  head.className = 'drawer-head';
  const h = document.createElement('h2');
  h.textContent = record.title;
  head.appendChild(h);
  if (record.sub) {
    const s = document.createElement('p');
    s.className = 'drawer-sub';
    s.textContent = record.sub;
    head.appendChild(s);
  }
  host.appendChild(head);

  if (record.body) {
    const body = document.createElement('div');
    body.className = 'drawer-body';
    (Array.isArray(record.body) ? record.body : [record.body]).forEach(node => body.appendChild(node));
    host.appendChild(body);
  }

  if (record.actions && record.actions.length) {
    const foot = document.createElement('div');
    foot.className = 'drawer-foot';
    record.actions.forEach(node => foot.appendChild(node));
    host.appendChild(foot);
  }
}

/* Loading rows, so the list has a shape before the answer arrives. */
function showSkeleton(list, rows = 6) {
  list.replaceChildren();
  for (let i = 0; i < rows; i++) {
    const li = document.createElement('li');
    li.className = 'skeleton';
    li.setAttribute('aria-hidden', 'true');
    const wide = document.createElement('div');
    wide.className = 'skeleton-bar';
    wide.style.width = (45 + ((i * 17) % 35)) + '%';
    const narrow = document.createElement('div');
    narrow.className = 'skeleton-bar';
    li.append(wide, narrow);
    list.appendChild(li);
  }
}

function showEmpty(list, title, detail, actionLabel, onAction) {
  list.replaceChildren();
  const li = document.createElement('li');

  const box = document.createElement('div');
  box.className = 'empty';
  const h = document.createElement('h3');
  h.textContent = title;
  const p = document.createElement('p');
  p.textContent = detail;
  box.append(h, p);

  if (actionLabel && onAction) {
    box.appendChild(button(actionLabel, 'btn-quiet', onAction));
  }

  li.appendChild(box);
  list.appendChild(li);
}

/** Draws "Showing 1 to 25 of 138" plus previous and next. */
function drawPager(host, result, onPage) {
  host.replaceChildren();
  host.hidden = !result || result.total === 0;
  if (host.hidden) return;

  const from = (result.page - 1) * result.per_page + 1;
  const to = Math.min(result.total, result.page * result.per_page);

  const count = document.createElement('div');
  count.className = 'count';
  count.textContent = `Showing ${from} to ${to} of ${result.total}`;

  const controls = document.createElement('div');
  controls.className = 'controls';

  const prev = document.createElement('button');
  prev.className = 'btn btn-quiet btn-small';
  prev.type = 'button';
  prev.textContent = 'Previous';
  prev.disabled = result.page <= 1;
  prev.addEventListener('click', () => onPage(result.page - 1));

  const next = document.createElement('button');
  next.className = 'btn btn-quiet btn-small';
  next.type = 'button';
  next.textContent = 'Next';
  next.disabled = result.page >= result.pages;
  next.addEventListener('click', () => onPage(result.page + 1));

  controls.append(prev, next);
  host.append(count, controls);
}

/** Debounce, so search does not fire a request on every keystroke. */
function debounce(fn, wait = 300) {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), wait);
  };
}
