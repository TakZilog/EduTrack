/*
  Shared behaviour for every staff panel page.

  Loaded as a plain script (no bundler anywhere in this project). Every page
  calls boot() first, which confirms who is signed in, draws the menu, and
  hands back the session so the page can hide what the person cannot use.
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

const MENU = [
  { href: 'index.html',    label: 'Overview',  needs: 'student.view' },
  { href: 'students.html', label: 'Students',  needs: 'student.view' },
  { href: 'codes.html',    label: 'Codes',     needs: 'code.view.redacted', or: 'code.view' },
  { href: 'rooms.html',    label: 'Rooms',     needs: 'room.view' },
  { href: 'activity.html', label: 'Activity',  needs: 'audit.view' },
  { href: 'settings.html', label: 'Settings',  needs: 'settings.manage' }
];

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

  const nav = document.createElement('nav');
  nav.className = 'rail-nav';
  nav.setAttribute('aria-label', 'Sections');

  MENU.filter(item => allowed(item.needs) || (item.or && allowed(item.or)))
    .forEach(item => {
      const a = document.createElement('a');
      a.className = 'nav-item';
      a.href = item.href;
      a.textContent = item.label;
      if (item.href === here) a.setAttribute('aria-current', 'page');
      nav.appendChild(a);
    });

  const foot = document.createElement('div');
  foot.className = 'rail-foot';

  const who = document.createElement('div');
  const name = document.createElement('div');
  name.className = 'who';
  name.textContent = session.admin.fullName;
  const role = document.createElement('div');
  role.className = 'role';
  role.textContent = session.admin.roleLabel;
  who.append(name, role);

  const out = document.createElement('button');
  out.className = 'btn btn-quiet btn-small';
  out.type = 'button';
  out.textContent = 'Sign out';
  out.style.marginTop = '14px';
  out.addEventListener('click', signOut);

  foot.append(who, out);
  rail.append(nav, foot);
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
 * Asks before doing something that cannot be undone.
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

/* ------------------------------------------------------------ table pieces */

function pill(text, kind) {
  const span = document.createElement('span');
  span.className = 'pill pill-' + kind;
  span.textContent = text;
  return span;
}

function cell(text, className) {
  const td = document.createElement('td');
  if (className) td.className = className;
  td.textContent = text ?? '—';
  return td;
}

function showSkeleton(tbody, rows = 6, columns = 5) {
  tbody.replaceChildren();
  for (let i = 0; i < rows; i++) {
    const tr = document.createElement('tr');
    for (let c = 0; c < columns; c++) {
      const td = document.createElement('td');
      const bar = document.createElement('div');
      bar.className = 'skeleton-bar';
      bar.style.width = (40 + ((i * 13 + c * 27) % 45)) + '%';
      td.appendChild(bar);
      tr.appendChild(td);
    }
    tbody.appendChild(tr);
  }
}

function showEmpty(tbody, columns, title, detail, actionLabel, onAction) {
  tbody.replaceChildren();
  const tr = document.createElement('tr');
  const td = document.createElement('td');
  td.colSpan = columns;

  const box = document.createElement('div');
  box.className = 'empty';
  const h = document.createElement('h3');
  h.textContent = title;
  const p = document.createElement('p');
  p.textContent = detail;
  box.append(h, p);

  if (actionLabel && onAction) {
    const btn = document.createElement('button');
    btn.className = 'btn btn-quiet';
    btn.type = 'button';
    btn.textContent = actionLabel;
    btn.addEventListener('click', onAction);
    box.appendChild(btn);
  }

  td.appendChild(box);
  tr.appendChild(td);
  tbody.appendChild(tr);
}

/** Draws "Showing 1 to 25 of 138" plus previous/next. */
function drawPager(host, result, onPage) {
  host.replaceChildren();
  if (!result || result.total === 0) return;

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
