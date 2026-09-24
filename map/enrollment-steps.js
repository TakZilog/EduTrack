// Script for enrollment-steps.html. Kept out of the page so the Content-Security-Policy
// can refuse inline scripts (see .htaccess).

const params = new URLSearchParams(window.location.search);
const track = params.get('track') === 'incoming' ? 'incoming' : 'ongoing';

const pageTitle = document.getElementById('pageTitle');
const pageLede = document.getElementById('pageLede');
const stepList = document.getElementById('stepList');

const ICON_OFFICE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 21V7l8-4 8 4v14"/><path d="M9 21v-6h6v6M9 11h.01M15 11h.01M9 15h.01M15 15h.01"/></svg>';
const ICON_PEN    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m14 4 6 6L8 22H2v-6Z"/><path d="m12.5 5.5 6 6"/></svg>';
const ICON_ARROW  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
const ICON_EMPTY  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>';
const ICON_CHECK  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>';

const PHOTO_EXTS = ['jpg', 'jpeg', 'webp', 'png'];

function el(tag, className, html) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (html !== undefined) node.innerHTML = html;
  return node;
}

/* Tries each extension in turn; falls back to a plain office icon rather
   than a broken image when nobody has added the photo yet. */
function officePhoto(stem) {
  const wrap = el('span');
  const placeholder = () => {
    wrap.replaceChildren(el('span', 'step-placeholder', ICON_OFFICE));
  };
  if (!stem) { placeholder(); return wrap; }

  let i = 0;
  const img = document.createElement('img');
  img.className = 'step-photo';
  img.alt = '';
  img.loading = 'lazy';
  img.onerror = () => {
    i++;
    if (i < PHOTO_EXTS.length) img.src = `../assets/enrollment/${stem}.${PHOTO_EXTS[i]}`;
    else placeholder();
  };
  img.src = `../assets/enrollment/${stem}.${PHOTO_EXTS[0]}`;
  wrap.appendChild(img);
  return wrap;
}

/* The door photo is looked up by room name, so it shows for guests too: the
   server hands out only the rooms this enrollment map names, without the
   student-only room map. A room with no photo falls back to the office icon. */
function renderRoomMedia(container, roomName) {
  const img = document.createElement('img');
  img.className = 'step-photo';
  img.alt = '';
  img.loading = 'lazy';
  // The light copy: a small card never needs the full 4096px photo.
  img.src = `../api/node-image.php?room=${encodeURIComponent(roomName)}&q=low`;
  img.onerror = () => { img.replaceWith(el('span', 'step-placeholder', ICON_OFFICE)); };
  container.appendChild(img);
}

function routeLink(roomName, label) {
  const a = document.createElement('a');
  a.className = 'step-route';
  const ret = encodeURIComponent(`enrollment-steps.html?track=${track}`);
  a.href = `walkthrough.html?room=${encodeURIComponent(roomName)}&return=${ret}`;
  a.innerHTML = (label || 'See it on the map') + ICON_ARROW;
  return a;
}

/* ------------------------------------------------------------- progress
   Visitors enrolling have no account, so progress lives in this browser
   only (localStorage), per track. Marking a step done never locks another:
   people often finish steps out of order. Storage can be missing or throw
   (private windows, blocked site data); the page then works, it just
   forgets on reload. */
const PROGRESS_KEY = `edutrack.enrollment.${track}.done`;

function loadDone() {
  try {
    const saved = JSON.parse(localStorage.getItem(PROGRESS_KEY) || '[]');
    return new Set(Array.isArray(saved) ? saved : []);
  } catch (err) {
    return new Set();
  }
}

function saveDone(done) {
  try {
    localStorage.setItem(PROGRESS_KEY, JSON.stringify([...done]));
  } catch (err) {
    /* storage unavailable: progress lasts until the page is closed */
  }
}

let done = loadDone();
let totalSteps = 0;
const progressBar = el('div', 'progress-bar');

function renderProgress() {
  const cards = [...stepList.querySelectorAll('.step-card')];
  const count = cards.filter(c => done.has(Number(c.dataset.step))).length;
  let nextFound = false;

  cards.forEach(card => {
    const isDone = done.has(Number(card.dataset.step));
    const isNext = !isDone && !nextFound;
    if (isNext) nextFound = true;
    card.classList.toggle('is-done', isDone);
    card.classList.toggle('is-next', isNext);

    const btn = card.querySelector('.step-done');
    btn.setAttribute('aria-pressed', String(isDone));
    btn.innerHTML = isDone ? ICON_CHECK + 'Done' : 'Mark as done';
    card.querySelector('.next-tag').hidden = !isNext;
  });

  progressBar.replaceChildren();
  const text = count === totalSteps
    ? `All ${totalSteps} steps done. You are enrolled.`
    : `${count} of ${totalSteps} steps done`;
  progressBar.appendChild(el('p', 'progress-text', text));
  const bar = el('div', 'progress-track');
  const fill = el('span', 'progress-fill');
  fill.style.width = totalSteps ? `${(count / totalSteps) * 100}%` : '0';
  bar.appendChild(fill);
  progressBar.appendChild(bar);
  if (count > 0) {
    const reset = el('button', 'progress-reset', 'Start over');
    reset.type = 'button';
    reset.addEventListener('click', () => {
      done = new Set();
      saveDone(done);
      renderProgress();
    });
    progressBar.appendChild(reset);
  }
}

function toggleDone(n) {
  if (done.has(n)) done.delete(n); else done.add(n);
  saveDone(done);
  renderProgress();
}

/* ---------------------------------------------------------------- steps */

function buildStepCard(step) {
  const li = el('li', 'step-card');
  li.dataset.step = String(step.n);
  li.appendChild(el('span', 'floor-num step-num', String(step.n)));

  const body = el('div', 'step-body');
  const head = el('div', 'step-head');
  head.appendChild(el('h2', 'step-title', step.title));
  if (step.office) head.appendChild(el('span', 'step-office', step.office));
  const nextTag = el('span', 'next-tag', 'Up next');
  nextTag.hidden = true;
  head.appendChild(nextTag);
  body.appendChild(head);
  body.appendChild(el('p', 'step-detail', step.detail));

  if (step.kind === 'group') {
    const ol = el('ol', 'substeps');
    step.substeps.forEach((sub, i) => {
      const item = el('li', 'substep');
      item.appendChild(el('span', 'substep-order', String.fromCharCode(97 + i)));
      item.appendChild(el('span', 'substep-label', sub.label));
      if (sub.kind === 'room') {
        renderRoomMedia(item, sub.room_name);
        item.appendChild(routeLink(sub.room_name, 'Walk there'));
      } else {
        item.appendChild(officePhoto(sub.photo));
      }
      ol.appendChild(item);
    });
    body.appendChild(ol);
  } else {
    const media = el('div', 'step-media');
    if (step.kind === 'room') {
      renderRoomMedia(media, step.room_name);
    } else if (step.kind === 'office') {
      media.appendChild(officePhoto(step.photo));
    } else {
      media.appendChild(el('span', 'step-placeholder', ICON_PEN));
    }
    body.appendChild(media);

    if (step.kind === 'room') body.appendChild(routeLink(step.room_name));
  }

  const doneBtn = el('button', 'step-done');
  doneBtn.type = 'button';
  doneBtn.addEventListener('click', () => toggleDone(step.n));
  body.appendChild(doneBtn);

  li.appendChild(body);
  return li;
}

function renderEmpty() {
  const empty = el('div', 'empty-state');
  empty.innerHTML = ICON_EMPTY;
  empty.appendChild(el('h2', null, 'Steps are coming soon'));
  empty.appendChild(el('p', null, 'The incoming first-year enrollment steps are not published yet. If you already enrolled with ACLC before, the ongoing student steps are ready now.'));
  const link = el('a', 'track-switch', 'See ongoing student steps');
  link.href = 'enrollment-steps.html?track=ongoing';
  empty.appendChild(link);
  stepList.replaceWith(empty);
}

async function init() {
  const stepsRes = await fetch('../assets/enrollment/enrollment-steps.json').then(r => r.json());

  const data = stepsRes[track];
  pageTitle.textContent = data.label + ' enrollment steps';
  pageLede.textContent = track === 'incoming'
    ? 'The first-time enrollment steps for new students.'
    : 'Follow these in order. Every step with a room links straight to the walkthrough from the gate.';

  if (!data.steps.length) {
    renderEmpty();
    return;
  }

  totalSteps = data.steps.length;
  data.steps.forEach(step => stepList.appendChild(buildStepCard(step)));
  stepList.before(progressBar);
  renderProgress();
}

/* ------------------------------------------------------------- offline */

/* Keeps this guide and the walk to every room it names on the phone, so it
   can be followed at the gate without signal (assets/js/offline.js, sw.js).
   Everything saved is already open to everyone; the list comes from
   api/enrollment-guide.php. The walks are saved with the lighter photos,
   which is what a phone opens with. Shown only in the app. */
function setupSaveGuide() {
  const button = document.getElementById('saveGuide');
  const note = document.getElementById('saveGuideNote');
  const offline = window.EduTrackOffline;
  if (!button || !offline || !offline.supported) return;
  button.hidden = false;
  const api = new URL('../api/', location.href).href;

  // Saved on an earlier visit? Say so, judged by the walks this page links to.
  const rooms = [...new Set([...document.querySelectorAll('a.step-route')]
    .map(link => new URL(link.href).searchParams.get('room'))
    .filter(Boolean))];
  offline.isSaved(rooms.flatMap(room => [
    api + 'tour.php?room=' + encodeURIComponent(room),
    api + 'node-image.php?room=' + encodeURIComponent(room) + '&q=low',
  ])).then(saved => { if (saved) showGuideSaved(button); });

  button.addEventListener('click', async () => {
    if (button.getAttribute('aria-busy') === 'true') return;
    button.setAttribute('aria-busy', 'true');
    note.textContent = 'Saving…';
    try {
      const res = await fetch('../api/enrollment-guide.php');
      if (!res.ok) throw new Error('guide list unavailable');
      const guide = await res.json();
      const urls = [
        'enrollment.html',
        'enrollment-steps.html',
        '../assets/enrollment/enrollment-steps.json',
        ...[...document.querySelectorAll('script[src], link[rel="stylesheet"][href]')]
          .map(node => node.getAttribute('src') || node.getAttribute('href')),
        ...await offline.walkthroughFiles(),
        ...guide.rooms.flatMap(room => [
          api + 'tour.php?room=' + encodeURIComponent(room),
          api + 'node-image.php?room=' + encodeURIComponent(room) + '&q=low',
        ]),
        ...guide.images.map(file => api + 'node-image.php?f=' + file + '&q=low&v=' + guide.version),
        ...guide.photos.map(path => '../' + path),
      ];
      const result = await offline.save(urls, p => { note.textContent = `Saving ${p.done} of ${p.total}…`; });
      if (!result.failed) showGuideSaved(button);
      note.textContent = result.failed
        ? `${result.failed} of ${result.total} files did not save. Try again with a better signal.`
        : 'The steps and the walk to each room now open without signal.';
    } catch (err) {
      note.textContent = 'Could not save the guide. Check your connection and try again.';
    } finally {
      button.removeAttribute('aria-busy');
    }
  });
}

function showGuideSaved(button) {
  button.classList.add('is-saved');
  document.getElementById('saveGuideText').textContent = 'Saved on this phone';
}

// After the steps are on the page, since the save button reads their walks.
init().finally(setupSaveGuide);
