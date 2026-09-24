// Script for select-room.html. Kept out of the page so the Content-Security-Policy
// can refuse inline scripts (see .htaccess).

const list = document.getElementById('roomList');
const search = document.getElementById('roomSearch');
const railLinks = [...document.querySelectorAll('#rail a')];
const CHEVRON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>';
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

let allRooms = [];
let observer = null;

/* The data stores names and floors in capitals. These change only what is
   shown; the raw room_name still goes to the walkthrough, which matches on it. */
function roomLabel(raw) {
  if (/^\d+$/.test(raw)) return 'Room ' + raw;
  return raw.toLowerCase().replace(/\b([a-z])/g, c => c.toUpperCase());
}

/* "2ND FLOOR SECOND BUILDING": the floor, and the building after it. */
function floorOf(raw) {
  const m = /^(\d+)(st|nd|rd|th)\s+floor\s*(.*)$/i.exec(raw || '');
  if (!m) return { n: 0, label: 'Other', building: '' };
  return {
    n: Number(m[1]),
    label: m[1] + m[2].toLowerCase() + ' floor',
    building: m[3].trim().toLowerCase().replace(/\b([a-z])/g, c => c.toUpperCase())
  };
}

const plural = n => n + (n === 1 ? ' room' : ' rooms');

function setActive(n) {
  railLinks.forEach(a => a.setAttribute('aria-current', String(a.dataset.floor === String(n))));
}

function render(rooms, query) {
  list.replaceChildren();
  list.setAttribute('aria-busy', 'false');
  if (observer) observer.disconnect();

  // Rail counts follow the search, and a floor with nothing to show goes quiet.
  const counts = {};
  rooms.forEach(r => { const n = floorOf(r.floor).n; counts[n] = (counts[n] || 0) + 1; });
  railLinks.forEach(a => {
    const c = counts[a.dataset.floor] || 0;
    a.querySelector('.rail-count').textContent = plural(c);
    a.setAttribute('aria-disabled', String(c === 0));
  });

  if (rooms.length === 0) {
    const note = document.createElement('div');
    note.className = 'list-note';
    const strong = document.createElement('strong');
    strong.textContent = 'No room matches "' + query + '"';
    note.append(strong, 'Try a room number like 204, or part of an office name.');
    list.appendChild(note);
    setActive(null);
    return;
  }

  // One group per building and floor, so two buildings' 1st floors stay apart.
  const groups = new Map();
  rooms.forEach(r => {
    const f = floorOf(r.floor);
    const key = f.building + '|' + f.n;
    if (!groups.has(key)) groups.set(key, { ...f, rooms: [] });
    groups.get(key).rooms.push(r);
  });

  // Building by building, and in each one the ground floor first: 1, 2, 3,
  // the order a student climbs from the gate.
  const order = [...groups.values()].sort((a, b) => a.building.localeCompare(b.building) || a.n - b.n);
  const severalBuildings = new Set(order.map(g => g.building)).size > 1;
  const floorsSeen = new Set();

  order.forEach((group, i) => {
    const n = group.n;
    // The first group of each floor keeps id "floor-N", which the rail and
    // the home page's floor links point at.
    const id = floorsSeen.has(n) ? 'floor-' + n + '-' + i : 'floor-' + n;
    floorsSeen.add(n);

    const section = document.createElement('section');
    section.setAttribute('aria-labelledby', id);
    section.dataset.floor = n;

    const head = document.createElement('h2');
    head.className = 'floor-head';
    head.id = id;
    const num = document.createElement('span');
    num.className = 'floor-num is-small';
    num.setAttribute('aria-hidden', 'true');
    num.textContent = n;
    const count = document.createElement('span');
    count.className = 'count';
    count.textContent = plural(group.rooms.length);
    head.append(num, group.label);
    if (severalBuildings && group.building) {
      const building = document.createElement('span');
      building.className = 'floor-building';
      building.textContent = group.building;
      head.appendChild(building);
    }
    head.appendChild(count);

    const ul = document.createElement('ul');
    ul.className = 'floor-rooms';
    group.rooms.forEach(r => {
      const li = document.createElement('li');
      const a = document.createElement('a');
      a.className = 'room';
      a.href = 'walkthrough.html?room=' + encodeURIComponent(r.room_name);
      const name = document.createElement('span');
      name.textContent = roomLabel(r.room_name);
      a.appendChild(name);
      a.insertAdjacentHTML('beforeend', CHEVRON);
      li.appendChild(a);
      ul.appendChild(li);
    });

    section.append(head, ul);
    list.appendChild(section);
  });

  setActive(order[0].n);

  // The rail follows the list: whichever floor's header sits at the top.
  observer = new IntersectionObserver(entries => {
    const visible = entries.filter(e => e.isIntersecting);
    if (visible.length) setActive(visible[0].target.dataset.floor);
  }, { root: list, rootMargin: '0px 0px -85% 0px' });
  list.querySelectorAll('section').forEach(s => observer.observe(s));
}

function goToFloor(n, smooth) {
  const head = document.getElementById('floor-' + n);
  if (!head) return;
  list.scrollTo({ top: head.parentElement.offsetTop, behavior: smooth && !reduceMotion ? 'smooth' : 'auto' });
  setActive(n);
}

railLinks.forEach(a => a.addEventListener('click', e => {
  e.preventDefault();
  history.replaceState(null, '', '#floor-' + a.dataset.floor);
  goToFloor(a.dataset.floor, true);
}));

function matches(r, q) {
  return r.room_name.toLowerCase().includes(q) || roomLabel(r.room_name).toLowerCase().includes(q);
}

function current() {
  const q = search.value.trim().toLowerCase();
  return { q, rooms: q ? allRooms.filter(r => matches(r, q)) : allRooms };
}

fetch('../api/tour.php', { credentials: 'same-origin' })
  .then(r => r.json().then(data => ({ status: r.status, data })))
  .then(({ status, data }) => {
    // The full tour is for enrolled students. A guest is sent to log in; a
    // signed-in student with no enrolment numbers yet is sent to add them.
    if (status === 401) {
      window.location.href = data.code === 'details_missing'
        ? '../Auth/add-details.html?next=select-room.html'
        : '../Auth/login.html?next=tour';
      throw new Error('locked');
    }
    allRooms = data.rooms.sort((a, b) => a.room_name.localeCompare(b.room_name, undefined, { numeric: true }));
    render(current().rooms, '');
    // Arriving from a floor on the home page: open at that floor.
    const m = /^#floor-(\d+)$/.exec(location.hash);
    if (m) goToFloor(m[1], false);
  })
  .catch(err => {
    list.setAttribute('aria-busy', 'false');
    list.innerHTML = '<div class="list-note"><strong>The room list did not load</strong>Check your connection and reload the page.</div>';
    console.error(err);
  });

search.addEventListener('input', () => {
  render(current().rooms, search.value.trim());
  list.scrollTop = 0;
});

// Enter on a search with exactly one result opens it: "302" then Enter.
search.addEventListener('keydown', e => {
  if (e.key !== 'Enter') return;
  const { rooms } = current();
  if (rooms.length === 1) {
    e.preventDefault();
    window.location.href = 'walkthrough.html?room=' + encodeURIComponent(rooms[0].room_name);
  }
});

/* ---------------------------------------------------------- session */

/* Reached both by a student who just signed in and by a guest. Ask the
   server which before drawing anything, so a guest is never offered a
   logout and a student is never left without one. */
const ICON_LOGOUT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 17l5-5-5-5M20 12H9M12 3H6a1 1 0 00-1 1v16a1 1 0 001 1h6"/></svg>';
const ICON_BACK   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>';
const sessionFooter = document.getElementById('sessionFooter');

function escapeHtml(text) { const p = document.createElement('p'); p.textContent = text; return p.innerHTML; }

function showGuestFooter() {
  sessionFooter.innerHTML =
    '<span class="session-who">Browsing as a guest</span>' +
    '<a class="session-action" href="../index.html">' + ICON_BACK + 'Home</a>';
  sessionFooter.hidden = false;
}

function showSignedInFooter(fullName) {
  sessionFooter.innerHTML =
    '<span class="session-who">Signed in as <strong>' + escapeHtml(fullName) + '</strong></span>' +
    '<button type="button" class="session-action is-exit" id="logoutBtn">' + ICON_LOGOUT + 'Log out</button>';
  sessionFooter.hidden = false;

  document.getElementById('logoutBtn').addEventListener('click', async function () {
    this.disabled = true;
    this.textContent = 'Logging out...';
    // The room map and photos saved for offline go first, whether or not the
    // server answers, so the next person on this phone cannot open them.
    if (window.EduTrackOffline) await EduTrackOffline.forget();
    apiPost('logout.php')
      .then(() => { window.location.href = '../index.html'; })
      .catch(() => {
        this.disabled = false;
        this.innerHTML = ICON_LOGOUT + 'Log out';
        const who = sessionFooter.querySelector('.session-who');
        if (who) { who.textContent = 'Could not reach the server. Try again.'; who.setAttribute('role', 'alert'); }
      });
  });
}

fetch('../api/whoami.php', { credentials: 'same-origin' })
  .then(r => r.json())
  .then(data => { data.signedIn ? showSignedInFooter(data.fullName) : showGuestFooter(); })
  .catch(() => showGuestFooter());

