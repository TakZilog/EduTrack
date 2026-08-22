/*
  EduTrack Walkthrough
  ---------------------
  Loads assets/nodes/nodes-edges.json, finds the shortest path from the
  gate node to the room requested via ?room=NAME in the URL, then lets
  the user step through each node's 360 photo with Next/Back.

  Data expected (produced by build_node_graph.py):
  {
    "nodes": [{ node_id, label, image_file, type }],
    "edges": [{ from_node, to_node, direction_label }],
    "rooms": [{ room_name, floor, node_id }]
  }
*/

const GRAPH_URL = '../assets/nodes/nodes-edges.json';
const IMAGE_BASE = '../assets/nodes/';

let graph = null;
let path = [];       // array of node_id, gate -> target room
let currentStep = 0;
let viewer = null;
let arrivedHideTimer = null;

const params = new URLSearchParams(window.location.search);
const targetRoomName = params.get('room');

init();

async function init() {
  if (!targetRoomName) {
    setLabel('No room selected.');
    return;
  }

  try {
    const res = await fetch(GRAPH_URL);
    graph = await res.json();
  } catch (err) {
    setLabel('Could not load map data.');
    console.error(err);
    return;
  }

  const targetRoom = graph.rooms.find(r => r.room_name === targetRoomName);
  if (!targetRoom) {
    setLabel(`Room "${targetRoomName}" not found.`);
    return;
  }

  const gateNode = graph.nodes.find(n => n.type === 'landmark') || graph.nodes[0];

  path = findPath(gateNode.node_id, targetRoom.node_id);
  if (!path || path.length === 0) {
    setLabel(`No route found to ${targetRoomName}.`);
    return;
  }

  buildRouteStrip();
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
  updateRouteStrip(index);
  document.getElementById('prevBtn').disabled = (index === 0);
  document.getElementById('nextBtn').style.display = isLast ? 'none' : '';

  // Arrival-banner logic runs first and unconditionally, so a panorama/CDN
  // failure below can never prevent the banner from showing or auto-hiding.
  const arrivedBanner = document.getElementById('arrivedBanner');
  clearTimeout(arrivedHideTimer);
  arrivedBanner.classList.remove('hiding');

  if (isLast) {
    document.getElementById('arrivedRoomName').textContent = `${node.label}`;
    arrivedBanner.style.display = 'block';
    scheduleArrivedBannerHide(arrivedBanner);
  } else {
    arrivedBanner.style.display = 'none';
  }

  try {
    loadPanorama(IMAGE_BASE + node.image_file);
  } catch (err) {
    console.error('Failed to load panorama viewer:', err);
  }
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

/* Optional: a clickable hotspot inside the panorama itself, in addition
   to the on-screen Next button. Default position (center-ish) — adjust
   pitch/yaw per-node later in nodes-edges.json for accurate placement. */
function buildHotspots() {
  if (currentStep >= path.length - 1) return [];
  return [{
    pitch: 0,
    yaw: 0,
    type: 'custom',
    cssClass: 'next-hotspot',
    createTooltipFunc: (hotSpotDiv) => {
      hotSpotDiv.innerHTML = '<div class="hotspot-arrow" role="button" tabindex="0" aria-label="Continue to next step">'
        + '<svg viewBox="0 0 20 20" fill="none"><path d="M10 15.5V4.5M10 4.5L4.5 10M10 4.5L15.5 10" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
        + '</div>';
      const trigger = () => document.getElementById('nextBtn').click();
      const arrow = hotSpotDiv.querySelector('.hotspot-arrow');
      arrow.addEventListener('click', trigger);
      arrow.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          trigger();
        }
      });
    }
  }];
}

/* Room/landmark nodes carry a meaningful label already. Hallway/junction
   nodes carry the raw source-folder name from whichever room's photos
   happened to create that shared node first, which is misleading to show
   ("101" while walking to room 103) — show the node's type instead. */
function displayLabel(node) {
  if (node.type === 'room' || node.type === 'landmark') return node.label;
  if (node.type === 'junction') return 'Junction';
  return 'Hallway';
}

function buildRouteStrip() {
  const strip = document.getElementById('routeStrip');
  strip.innerHTML = '';
  for (let i = 0; i < path.length; i++) {
    const tick = document.createElement('div');
    tick.className = 'tick';
    strip.appendChild(tick);
  }
}

function updateRouteStrip(index) {
  const ticks = document.querySelectorAll('#routeStrip .tick');
  ticks.forEach((tick, i) => {
    tick.classList.toggle('done', i < index);
    tick.classList.toggle('current', i === index);
  });
}

function setLabel(main, sub) {
  document.getElementById('stepLabel').textContent = main;
  document.getElementById('stepCount').textContent = sub || '';
}
