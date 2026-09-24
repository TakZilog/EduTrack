/*
  EduTrack Walkthrough
  ---------------------
  Loads assets/nodes/nodes-edges.json, finds the shortest path from the
  gate node to the room requested via ?room=NAME in the URL, then lets
  the user step through each node's 360 photo with Next/Back.

  Data expected (built by tools/import-photos.php):
  {
    "nodes": [{ node_id, label, image_file, type }],
    "edges": [{ from_node, to_node, direction_label }],
    "rooms": [{ room_name, floor, node_id }]
  }
*/

const GRAPH_URL = '../api/tour.php';   // students; guests only for enrollment rooms, else 401 sends them to log in
const IMAGE_BASE = '../api/node-image.php?f=';

let graph = null;
let path = [];       // array of node_id, gate -> target room
let currentStep = 0;
let viewer = null;
let arrivedHideTimer = null;

/* Photo quality for low-internet mode. 'low' asks node-image.php for a lighter
   copy of each panorama; 'fast' loads the full one. Remembered per browser,
   changeable from the top-bar pill. */
const QUALITY_KEY = 'edutrack.walkQuality';
let quality = readStoredQuality();

function readStoredQuality() {
  try {
    const v = localStorage.getItem(QUALITY_KEY);
    return v === 'low' || v === 'fast' ? v : null;
  } catch { return null; }
}
function saveQuality(q) {
  try { localStorage.setItem(QUALITY_KEY, q); } catch { /* private mode: this visit only */ }
}
// Set from the X-Map-Version header on the tour.php response: the map file's
// timestamp. Appended to every image URL so a replaced photo gets a fresh URL
// and node-image.php can otherwise cache photos for a year.
let mapVersion = '';

function imageUrl(file) {
  let url = IMAGE_BASE + file;
  if (quality === 'low') url += '&q=low';
  if (mapVersion) url += '&v=' + encodeURIComponent(mapVersion);
  return url;
}

// Quietly pull the next and previous photos while this one is on screen, so
// stepping the walk is instant instead of a wait on every tap. Low priority
// (rel=prefetch), so it never competes with the photo being viewed.
const prefetched = new Set();
function prefetchPhoto(file) {
  const url = imageUrl(file);
  if (prefetched.has(url)) return;
  prefetched.add(url);
  const link = document.createElement('link');
  link.rel = 'prefetch';
  link.href = url;
  document.head.appendChild(link);
}
function prefetchAround(index) {
  for (const i of [index + 1, index - 1]) {
    if (i < 0 || i >= path.length) continue;
    const node = graph.nodes.find(n => n.node_id === path[i]);
    if (node) prefetchPhoto(node.image_file);
  }
}

const params = new URLSearchParams(window.location.search);
const targetRoomName = params.get('room');

/* Where "All rooms" and "Other rooms" send you back to. Defaults to the
   room picker; a walk started from the enrollment map (?return=...) goes
   back there instead. Restricted to a plain file in this same folder, with
   an optional simple query string, so this can never become an open
   redirect off a crafted link. */
function resolveReturnTo() {
  const raw = params.get('return');
  if (raw && /^[a-zA-Z0-9_-]+\.html(\?[a-zA-Z0-9=&_.%-]*)?$/.test(raw)) return raw;
  return 'select-room.html';
}
const returnTo = resolveReturnTo();
const returnToEnrollment = returnTo.startsWith('enrollment-steps.html');
const backLink = document.getElementById('backLink');
const otherRoomsLink = document.getElementById('otherRoomsLink');
backLink.href = returnTo;
otherRoomsLink.href = returnTo;
if (returnToEnrollment) {
  backLink.querySelector('span').textContent = 'Enrollment steps';
  otherRoomsLink.textContent = 'Back to enrollment steps';
}

init();

async function init() {
  if (!targetRoomName) {
    setLabel('No room selected.');
    return;
  }

  // Shown at once so the visitor can pick Low before the first photo loads;
  // the map JSON downloads behind it. Resolves immediately if they chose before.
  setupSpeedToggle();
  const qualityChosen = chooseQualityIfNeeded();

  try {
    // ?room= lets a guest walk to an enrollment room; students get the full map.
    const res = await fetch(GRAPH_URL + '?room=' + encodeURIComponent(targetRoomName), { credentials: 'same-origin' });
    graph = await res.json();
    mapVersion = res.headers.get('X-Map-Version') || '';
    if (res.status === 401) {
      // No enrolment numbers yet: add them, then come straight back to this room.
      window.location.href = graph.code === 'details_missing'
        ? '../Auth/add-details.html?next=' + encodeURIComponent('walkthrough.html?room=' + encodeURIComponent(targetRoomName))
        : '../Auth/login.html?next=tour';
      return;
    }
  } catch (err) {
    hideChoice();
    setLabel('Could not load map data.');
    console.error(err);
    return;
  }

  const targetRoom = graph.rooms.find(r => r.room_name === targetRoomName);
  if (!targetRoom) {
    hideChoice();
    setLabel(`Room "${targetRoomName}" not found.`);
    return;
  }

  const gateNode = graph.nodes.find(n => n.type === 'landmark') || graph.nodes[0];

  path = findPath(gateNode.node_id, targetRoom.node_id);
  if (!path || path.length === 0) {
    hideChoice();
    setLabel(`No route found to ${targetRoomName}.`);
    return;
  }

  // Wait for the quality choice so the first photo loads at the right size.
  await qualityChosen;

  currentStep = 0;
  showStep(currentStep);

  document.getElementById('nextBtn').addEventListener('click', () => {
    if (currentStep < path.length - 1) {
      currentStep++;
      showStep(currentStep);
    }
  });
  document.getElementById('prevBtn').addEventListener('click', () => {
    if (currentStep > 0) {
      currentStep--;
      showStep(currentStep);
    }
  });
}

/* Breadth-first search over the edges list, treated as bidirectional
   (a hallway can be walked in either direction), returns list of node_ids. */
function findPath(startId, endId) {
  if (startId === endId) return [startId];

  const adjacency = {};
  graph.edges.forEach(e => {
    (adjacency[e.from_node] ??= []).push(e.to_node);
    (adjacency[e.to_node] ??= []).push(e.from_node);
  });

  const visited = new Set([startId]);
  const queue = [[startId]];

  while (queue.length) {
    const currentPath = queue.shift();
    const node = currentPath[currentPath.length - 1];

    for (const neighbor of (adjacency[node] || [])) {
      if (visited.has(neighbor)) continue;
      const nextPath = [...currentPath, neighbor];
      if (neighbor === endId) return nextPath;
      visited.add(neighbor);
      queue.push(nextPath);
    }
  }
  return null; // no route
}

function showStep(index) {
  const nodeId = path[index];
  const node = graph.nodes.find(n => n.node_id === nodeId);
  if (!node) return;

  const isLast = index === path.length - 1;

  setLabel(displayLabel(node), `Step ${index + 1} of ${path.length}`);
  // The how-to-walk hint goes after the first step and does not come back.
  if (index !== 0) document.getElementById('walkHint').hidden = true;
  updateRouteProgress(index);
  document.getElementById('prevBtn').disabled = (index === 0);
  document.getElementById('nextBtn').style.display = isLast ? 'none' : '';

  // Arrival-banner logic runs first and unconditionally, so a panorama/CDN
  // failure below can never prevent the banner from showing or auto-hiding.
  const arrivedBanner = document.getElementById('arrivedBanner');
  clearTimeout(arrivedHideTimer);
  arrivedBanner.classList.remove('hiding');

  if (isLast) {
    document.getElementById('arrivedRoomName').textContent = roomLabel(node.label);
    arrivedBanner.style.display = 'flex';
    scheduleArrivedBannerHide(arrivedBanner);
  } else {
    arrivedBanner.style.display = 'none';
  }

  try {
    loadPanorama(imageUrl(node.image_file));
  } catch (err) {
    console.error('Failed to load panorama viewer:', err);
  }

  prefetchAround(index);
}

function scheduleArrivedBannerHide(arrivedBanner) {
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  arrivedHideTimer = setTimeout(() => {
    if (reduceMotion) {
      arrivedBanner.style.display = 'none';
      return;
    }
    arrivedBanner.classList.add('hiding');
    arrivedHideTimer = setTimeout(() => {
      arrivedBanner.style.display = 'none';
      arrivedBanner.classList.remove('hiding');
    }, 250);
  }, 5000);
}

function loadPanorama(imageUrl) {
  if (viewer) {
    viewer.destroy();
  }
  viewer = pannellum.viewer('panorama', {
    type: 'equirectangular',
    panorama: imageUrl,
    title: '',
    autoLoad: true,
    compass: false,
    showControls: false,
    hotSpots: buildHotspots()
  });
}

/* Walking is done in the photo, the way Street View does it: a chevron on
   the floor ahead walks forward, one on the floor behind walks back. The
   photos are taken facing along the route, so ahead is yaw 0 and behind is
   yaw 180; turn around to see the way back. The Back/Next buttons remain in
   the page for keyboard and screen reader users only (see #controls). */
const GROUND_PITCH = -20;
const CHEVRON = '<svg viewBox="0 0 64 40" fill="none" aria-hidden="true">'
  + '<path class="edge" d="M10 32 32 10l22 22"/>'
  + '<path class="face" d="M10 32 32 10l22 22"/>'
  + '</svg>';

function groundArrow(yaw, label, buttonId) {
  return {
    pitch: GROUND_PITCH,
    yaw,
    type: 'custom',
    cssClass: 'walk-hotspot',
    createTooltipFunc: (hotSpotDiv) => {
      hotSpotDiv.innerHTML = '<div class="hotspot-arrow" role="button" tabindex="0" aria-label="' + label + '">' + CHEVRON + '</div>';
      const trigger = () => document.getElementById(buttonId).click();
      const arrow = hotSpotDiv.querySelector('.hotspot-arrow');
      arrow.addEventListener('click', trigger);
      arrow.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          trigger();
        }
      });
    }
  };
}

function buildHotspots() {
  const spots = [];
  if (currentStep < path.length - 1) spots.push(groundArrow(0, 'Walk forward', 'nextBtn'));
  if (currentStep > 0) spots.push(groundArrow(180, 'Walk back', 'prevBtn'));
  return spots;
}

/* Room/landmark nodes carry a meaningful label already. Hallway/junction
   nodes carry the raw source-folder name from whichever room's photos
   happened to create that shared node first, which is misleading to show
   ("101" while walking to room 103) — show the node's type instead. */
function displayLabel(node) {
  if (node.type === 'room') return roomLabel(node.label);
  if (node.type === 'landmark') return node.label;
  if (node.type === 'junction') return 'Junction';
  return 'Hallway';
}

/* Room names are stored in capitals and numbered rooms as bare numbers.
   Shown the same way the room picker shows them: "Room 103", "Kitchen Lab". */
function roomLabel(raw) {
  if (/^\d+$/.test(raw)) return 'Room ' + raw;
  return raw.toLowerCase().replace(/\b([a-z])/g, c => c.toUpperCase());
}

/* The hairline under the top bar fills to the current step. Set, never
   animated: nothing at the top should move when you press Next. */
function updateRouteProgress(index) {
  const fill = document.querySelector('#routeProgress span');
  if (fill) fill.style.width = ((index + 1) / path.length * 100) + '%';
}

function setLabel(main, sub) {
  document.getElementById('stepLabel').textContent = main;
  document.getElementById('stepCount').textContent = sub || '';
}

/* -------------------------------------------------------- data speed choice */

/* Resolves once a quality is known. A visitor who chose before never sees the
   overlay; otherwise it is shown and the promise waits for a button. */
function chooseQualityIfNeeded() {
  const overlay = document.getElementById('speedChoice');
  if (quality) {
    reflectQuality();
    return Promise.resolve();
  }

  // A hint, not a default: recommend Low when the browser reports a slow or
  // data-saving connection, but still let the visitor decide.
  const conn = navigator.connection || navigator.webkitConnection;
  if (conn && (conn.saveData || /(^|-)(slow-2g|2g|3g)$/.test(conn.effectiveType || ''))) {
    const rec = overlay.querySelector('.speed-rec');
    if (rec) rec.hidden = false;
  }

  overlay.hidden = false;
  return new Promise(resolve => {
    overlay.querySelectorAll('.speed-opt').forEach(btn => {
      btn.addEventListener('click', () => {
        quality = btn.dataset.quality === 'low' ? 'low' : 'fast';
        saveQuality(quality);
        reflectQuality();
        overlay.hidden = true;
        resolve();
      }, { once: true });
    });
    const first = overlay.querySelector('.speed-opt');
    if (first) first.focus();
  });
}

function hideChoice() {
  const overlay = document.getElementById('speedChoice');
  if (overlay) overlay.hidden = true;
}

/* The top-bar pill: flips quality and reloads the photo on screen. */
function setupSpeedToggle() {
  const toggle = document.getElementById('speedToggle');
  if (!toggle) return;
  reflectQuality();
  toggle.addEventListener('click', () => {
    quality = quality === 'low' ? 'fast' : 'low';
    saveQuality(quality);
    reflectQuality();
    if (path.length) {
      const node = graph.nodes.find(n => n.node_id === path[currentStep]);
      if (node) loadPanorama(imageUrl(node.image_file));
    }
  });
}

function reflectQuality() {
  const toggle = document.getElementById('speedToggle');
  if (!toggle) return;
  const low = quality === 'low';
  toggle.classList.toggle('on', low);
  toggle.setAttribute('aria-pressed', low ? 'true' : 'false');
  toggle.title = low ? 'Switch to full-quality photos' : 'Switch to low-data photos';
  const text = document.getElementById('speedToggleText');
  if (text) text.textContent = low ? 'Low data' : 'Fast';
}
