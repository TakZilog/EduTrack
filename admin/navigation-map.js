// Script for navigation-map.html. Kept out of the page so the Content-Security-Policy
// can refuse inline scripts (see .htaccess).

(async function () {
  const s = await boot();
  if (!s) return;
  const floorSelect = document.getElementById('mapFloor');
  const locationSelect = document.getElementById('mapLocation');
  const statusSelect = document.getElementById('mapStatus');
  const workspace = document.getElementById('routeWorkspace');
  const { data } = await apiGet('rooms.php');
  if (!data.ok) { toast(data.error || 'Could not load navigation data.', 'bad'); return; }

  const floors = [...new Set(data.rooms.map(room => room.floor).filter(Boolean))];
  floors.forEach(floor => {
    const option = document.createElement('option');
    option.value = floor;
    option.textContent = floor.replace(/\s+ADMIN BUILDING\s*$/i, '').toLowerCase().replace(/\b[a-z]/g, letter => letter.toUpperCase());
    floorSelect.appendChild(option);
  });

  function refreshLocations() {
    const chosenFloor = floorSelect.value;
    const chosenStatus = statusSelect.value;
    locationSelect.replaceChildren(new Option('Select Location', ''));
    data.rooms
      .filter(room => (!chosenFloor || room.floor === chosenFloor) && (!chosenStatus || (chosenStatus === 'reachable' ? room.reachable : !room.reachable)))
      .forEach(room => locationSelect.appendChild(new Option(room.name, room.name)));
  }

  async function showRoute(room) {
    workspace.replaceChildren();
    const loading = document.createElement('p');
    loading.className = 'route-loading';
    loading.textContent = 'Loading route structure...';
    workspace.appendChild(loading);
    const { data: route } = await apiGet('room-route.php', { name: room.name });
    workspace.replaceChildren();

    const status = document.createElement('div');
    status.className = 'route-status ' + (route.ok && route.reachable ? 'is-good' : 'is-bad');
    status.textContent = route.ok && route.reachable ? '✓ Reachable' : '⚠ Needs attention';
    workspace.appendChild(status);

    const chain = document.createElement('ol');
    chain.className = 'route-chain';
    if (route.ok && route.reachable) {
      route.steps.forEach(step => {
        const item = document.createElement('li');
        item.textContent = step.title;
        chain.appendChild(item);
      });
    } else {
      ['Gate', 'Main Hallway', 'Missing connection', room.name].forEach(title => {
        const item = document.createElement('li');
        item.textContent = title;
        if (title === 'Missing connection') item.className = 'is-missing';
        chain.appendChild(item);
      });
    }
    workspace.appendChild(chain);

    const detail = document.createElement('p');
    detail.className = 'route-detail-copy';
    detail.textContent = route.ok && route.reachable
      ? route.total + ' walkthrough photos connect the gate to ' + room.name + '.'
      : (route.why || 'The connection to this location is missing.');
    workspace.appendChild(detail);

    const actions = document.createElement('div');
    actions.className = 'route-actions';
    if (route.ok && route.reachable) {
      const walkthrough = document.createElement('a');
      walkthrough.className = 'btn btn-quiet';
      walkthrough.href = '../map/walkthrough.html?room=' + encodeURIComponent(room.name);
      walkthrough.target = '_blank';
      walkthrough.rel = 'noopener';
      walkthrough.textContent = 'Manage Walkthrough';
      actions.appendChild(walkthrough);
    }
    actions.appendChild(button('View Location Details', 'btn-quiet', () => window.location.href = 'rooms.html'));
    workspace.appendChild(actions);
  }

  floorSelect.addEventListener('change', refreshLocations);
  statusSelect.addEventListener('change', refreshLocations);
  locationSelect.addEventListener('change', () => {
    const room = data.rooms.find(item => item.name === locationSelect.value);
    if (room) showRoute(room);
  });
  refreshLocations();
})();
