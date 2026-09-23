// Script for enrollment-steps.html. Kept out of the page so the Content-Security-Policy
// can refuse inline scripts (see .htaccess).

const params = new URLSearchParams(window.location.search);
const track = params.get('track') === 'incoming' ? 'incoming' : 'ongoing';

const pageTitle = document.getElementById('pageTitle');
const pageLede = document.getElementById('pageLede');
const stepList = document.getElementById('stepList');
const loadNote = document.getElementById('loadNote');

const ICON_OFFICE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 21V7l8-4 8 4v14"/><path d="M9 21v-6h6v6M9 11h.01M15 11h.01M9 15h.01M15 15h.01"/></svg>';
const ICON_PEN    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m14 4 6 6L8 22H2v-6Z"/><path d="m12.5 5.5 6 6"/></svg>';
const ICON_ARROW  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
const ICON_EMPTY  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>';

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

function roomPhoto(imageFile) {
  const img = document.createElement('img');
  img.className = 'step-photo';
  img.alt = '';
  img.loading = 'lazy';
  img.src = `../api/node-image.php?f=${encodeURIComponent(imageFile)}`;
  img.onerror = () => { img.replaceWith(el('span', 'step-placeholder', ICON_OFFICE)); };
  return img;
}

function routeLink(roomName, label) {
  const a = document.createElement('a');
  a.className = 'step-route';
  const ret = encodeURIComponent(`enrollment-steps.html?track=${track}`);
  a.href = `walkthrough.html?room=${encodeURIComponent(roomName)}&return=${ret}`;
  a.innerHTML = (label || 'See it on the map') + ICON_ARROW;
  return a;
}

/* graph is the building's node/room data from api/tour.php, or null when
   it could not be loaded (weak signal at the gate is the expected case,
   not an error) — every render below works either way. */
function renderRoomMedia(container, roomName, graph) {
  const room = graph && graph.rooms.find(r => r.room_name === roomName);
  const node = room && graph.nodes.find(n => n.node_id === room.node_id);
  if (node) container.appendChild(roomPhoto(node.image_file));
  else container.appendChild(el('span', 'step-placeholder', ICON_OFFICE));
}

function buildStepCard(step, graph) {
  const li = el('li', 'step-card');
  li.appendChild(el('span', 'floor-num step-num', String(step.n)));

  const body = el('div', 'step-body');
  const head = el('div', 'step-head');
  head.appendChild(el('h2', 'step-title', step.title));
  if (step.office) head.appendChild(el('span', 'step-office', step.office));
  body.appendChild(head);
  body.appendChild(el('p', 'step-detail', step.detail));

  if (step.kind === 'group') {
    const ol = el('ol', 'substeps');
    step.substeps.forEach((sub, i) => {
      const item = el('li', 'substep');
      item.appendChild(el('span', 'substep-order', String.fromCharCode(97 + i)));
      item.appendChild(el('span', 'substep-label', sub.label));
      if (sub.kind === 'room') {
        renderRoomMedia(item, sub.room_name, graph);
        if (graph && graph.rooms.some(r => r.room_name === sub.room_name)) {
          item.appendChild(routeLink(sub.room_name, 'Walk there'));
        }
      } else {
        item.appendChild(officePhoto(sub.photo));
      }
      ol.appendChild(item);
    });
    body.appendChild(ol);
  } else {
    const media = el('div', 'step-media');
    if (step.kind === 'room') {
      renderRoomMedia(media, step.room_name, graph);
    } else if (step.kind === 'office') {
      media.appendChild(officePhoto(step.photo));
    } else {
      media.appendChild(el('span', 'step-placeholder', ICON_PEN));
    }
    body.appendChild(media);

    if (step.kind === 'room' && graph && graph.rooms.some(r => r.room_name === step.room_name)) {
      body.appendChild(routeLink(step.room_name));
    }
  }

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

async function loadGraph() {
  try {
    const res = await fetch('../api/tour.php', { credentials: 'same-origin' });
    if (res.status === 401) return null; // guest is allowed here; a signed-in, incomplete account just loses the room links
    if (!res.ok) return null;
    return await res.json();
  } catch (err) {
    console.error('Could not load the room map:', err);
    return null;
  }
}

async function init() {
  const [stepsRes, graph] = await Promise.all([
    fetch('../assets/enrollment/enrollment-steps.json').then(r => r.json()),
    loadGraph()
  ]);

  const data = stepsRes[track];
  pageTitle.textContent = data.label + ' enrollment steps';
  pageLede.textContent = track === 'incoming'
    ? 'The first-time enrollment steps for new students.'
    : 'Follow these in order. Every step with a room links straight to the walkthrough from the gate.';

  if (!graph) loadNote.hidden = false;

  if (!data.steps.length) {
    renderEmpty();
    return;
  }

  data.steps.forEach(step => stepList.appendChild(buildStepCard(step, graph)));
}

init();

