// Script for rooms.html. Kept out of the page so the Content-Security-Policy
// can refuse inline scripts (see .htaccess).

(async function () {
  const s = await boot();
  if (!s) return;

  const rows = document.getElementById('rows');
  const drawer = document.getElementById('drawer');
  const floorSelect = document.getElementById('floor');
  const typeSelect = document.getElementById('type');
  const statusSelect = document.getElementById('status');
  const roomListFooter = document.getElementById('roomListFooter');
  const registerRoom = document.getElementById('registerRoom');

  let all = [];          // rooms visitors can choose
  let unlisted = [];     // photos on the map that are not offered to visitors
  let floors = [];
  let structured = false; // the map is imported by building and floor
  let attentionNames = new Set();
  const state = { q: '', floor: '', type: '', status: '', page: 1, openName: null };
  const PAGE_SIZE = 20;

  async function load() {
    showSkeleton(rows, 8);

    const { data } = await apiGet('rooms.php');
    if (!data.ok) {
      toast(data.error || 'Could not load the rooms.', 'bad');
      return;
    }

    all = data.rooms;
    unlisted = (data.unlisted || []).map(u => ({ ...u, isUnlisted: true }));
    floors = data.floors.filter(Boolean);
    structured = Boolean(data.structured);
    attentionNames = new Set((data.problems || []).flatMap(problem => problem.items || []));

    fillFloorFilter();
    paint();
  }

  function notice(kind, title, detail) {
    const el = document.createElement('div');
    el.className = 'notice notice-' + kind;
    const t = document.createElement('p');
    t.className = 't';
    t.textContent = title;
    const d = document.createElement('p');
    d.className = 'd';
    d.textContent = detail;
    el.append(t, d);
    return el;
  }

  function fillFloorFilter() {
    const chosen = floorSelect.value;
    floorSelect.replaceChildren();
    const every = document.createElement('option');
    every.value = '';
    every.textContent = 'Floor';
    floorSelect.appendChild(every);
    floors.forEach(f => {
      const opt = document.createElement('option');
      opt.value = f;
      opt.textContent = floorLabel(f);
      floorSelect.appendChild(opt);
    });
    if (floors.includes(chosen)) floorSelect.value = chosen;
  }

  floorSelect.addEventListener('change', e => { state.floor = e.target.value; state.page = 1; paint(); });
  typeSelect.addEventListener('change', e => { state.type = e.target.value; state.page = 1; paint(); });
  statusSelect.addEventListener('change', e => { state.status = e.target.value; state.page = 1; paint(); });

  document.getElementById('q').addEventListener('input', debounce(e => {
    state.q = e.target.value.trim().toLowerCase();
    state.page = 1;
    paint();
  }, 250));

  // Rooms are added with their photos, on the Walkthrough page.
  registerRoom.addEventListener('click', () => { window.location.href = 'walkthrough.html'; });

  /* Rooms visitors can choose come first; photos that are on the map but not
     offered to anyone follow, marked, so both live in one list. */
  function paint() {
    const listed = all.filter(r =>
      (state.q === '' || r.name.toLowerCase().includes(state.q)) &&
      (state.floor === '' || r.floor === state.floor) &&
      (state.type === '' || state.type === 'room') &&
      (state.status === '' || roomStatus(r) === state.status)
    );
    const hidden = state.floor !== '' ? [] : unlisted.filter(u =>
      state.q === '' || (u.label || u.nodeId).toLowerCase().includes(state.q)
    );

    const combined = [...listed, ...hidden];
    if (combined.length === 0) {
      showEmpty(rows, 'No locations match',
        'Nothing here fits what you searched for.',
        'Show every location',
        () => {
          state.q = ''; state.floor = ''; state.type = ''; state.status = ''; state.page = 1;
          document.getElementById('q').value = '';
          floorSelect.value = ''; typeSelect.value = ''; statusSelect.value = '';
          paint();
        });
      roomListFooter.replaceChildren();
      return;
    }

    rows.replaceChildren();

    const start = (state.page - 1) * PAGE_SIZE;
    const pageItems = combined.slice(start, start + PAGE_SIZE);
    pageItems.filter(item => !item.isUnlisted).forEach(room => {
      const end = [];
      const status = roomStatus(room);
      end.push(pill(statusLabel(status), status === 'reachable' ? 'verified' : status === 'attention' ? 'warning' : 'revoked'));
      if (room.steps) {
        const steps = document.createElement('span');
        steps.className = 'row-when';
        steps.textContent = room.steps + ' route steps';
        end.push(steps);
      }

      rows.appendChild(listRow({
        title: room.name,
        sub: floorLabel(room.floor),
        end,
        endMode: 'columns',
        selected: state.openName === room.name,
        onOpen: row => { markOpenRow(rows, row); openRoom(room); }
      }));
    });

    pageItems.filter(item => item.isUnlisted).forEach(item => {
      rows.appendChild(listRow({
        title: item.label || item.nodeId,
        sub: 'Not shown to visitors',
        end: [pill(item.reachable ? 'Not listed' : 'Unreachable', item.reachable ? 'off' : 'revoked')],
        endMode: 'columns',
        selected: state.openName === item.nodeId,
        onOpen: row => { markOpenRow(rows, row); openUnlisted(item); }
      }));
    });

    drawRoomPager(combined.length);
  }

  function roomStatus(room) {
    if (!room.reachable) return 'unreachable';
    return attentionNames.has(room.name) ? 'attention' : 'reachable';
  }

  /* "2ND FLOOR SECOND BUILDING" -> "Second Building – 2nd Floor". The older
     single-building map says "1ST FLOOR ADMIN BUILDING" -> "1st Floor". */
  function floorLabel(value) {
    const m = /^(\d+)(st|nd|rd|th)\s+floor\s*(.*)$/i.exec(String(value || '').trim());
    if (!m) return String(value || '');
    const floor = m[1] + m[2].toLowerCase() + ' Floor';
    const building = m[3].toLowerCase().replace(/\b[a-z]/g, letter => letter.toUpperCase());
    return building && !/^admin building$/i.test(building) ? building + ' – ' + floor : floor;
  }

  function statusLabel(status) {
    return status === 'reachable' ? 'Reachable' : status === 'attention' ? 'Needs attention' : 'Unreachable';
  }

  function drawRoomPager(total) {
    roomListFooter.replaceChildren();
    if (total === 0) return;

    const from = (state.page - 1) * PAGE_SIZE + 1;
    const to = Math.min(total, state.page * PAGE_SIZE);
    const count = document.createElement('span');
    count.textContent = 'Showing ' + from + '–' + to + ' of ' + total + ' locations';

    const controls = document.createElement('div');
    controls.className = 'room-page-controls';
    const pages = Math.ceil(total / PAGE_SIZE);
    const previous = button('Previous', 'btn-quiet btn-small', () => { state.page--; paint(); });
    previous.disabled = state.page === 1;
    const next = button('Next', 'btn-quiet btn-small', () => { state.page++; paint(); });
    next.disabled = state.page === pages;
    controls.append(previous, next);
    roomListFooter.append(count, controls);
  }

  /* ------------------------------------------------------------- a room */

  function openRoom(room) {
    state.openName = room.name;

    const actions = [];

    actions.push(button('View Route', 'btn-quiet', () => showRoute(room)));

    if (room.reachable) {
      const open = document.createElement('a');
      open.className = 'btn btn-quiet';
      open.href = '../map/walkthrough.html?room=' + encodeURIComponent(room.name);
      open.target = '_blank';
      open.rel = 'noopener';
      open.textContent = 'View Walkthrough';
      actions.push(open);
    }

    /* Locations is for checking, Walkthrough for changing. On the building
       map every change to a room (photos, name, swap, remove) lives on the
       Walkthrough page, so staff have one place to go. The older map has no
       Walkthrough editor, so its rooms are still changed here. */
    if (allowed('room.edit')) {
      if (structured) {
        const change = document.createElement('a');
        change.className = 'btn';
        change.href = 'walkthrough.html';
        change.textContent = 'Change this room on the Walkthrough page';
        actions.push(change);
      } else {
        actions.push(button('Edit Location', 'btn', () => editRoom(room)));
        actions.push(button('Remove Location', 'btn-danger', () => removeRoom(room)));
      }
    }

    drawDrawer(drawer, {
      title: room.name,
      sub: floorLabel(room.floor),
      body: facts([
        ['Floor', floorLabel(room.floor)],
        ['Status', pill(statusLabel(roomStatus(room)), roomStatus(room) === 'reachable' ? 'verified' : roomStatus(room) === 'attention' ? 'warning' : 'revoked')],
        ['Route', room.steps ? room.steps + ' route steps from the gate' : 'No valid route'],
        ['Walkthrough', room.steps ? room.steps + ' photos / route steps' : 'Unavailable']
      ]),
      actions
    });
  }

  function openUnlisted(item) {
    state.openName = item.nodeId;

    const id = document.createElement('span');
    id.className = 'mono';
    id.textContent = item.nodeId;

    const actions = [];
    if (allowed('room.edit')) {
      actions.push(button('Put back on the list', 'btn', () => relist(item)));
    }

    drawDrawer(drawer, {
      title: item.label || item.nodeId,
      sub: 'This photo is on the map, but visitors are not offered it.',
      body: facts([
        ['Photo', id],
        ['Can visitors reach it', item.reachable ? pill('Yes', 'verified') : pill('No route', 'revoked')]
      ]),
      actions
    });
  }

  /*
    The walk, as pictures, inside the drawer. This is the answer to "which
    photo is which part of the building": nobody reads a node id, they look at
    the corridor and see where it leads.
  */
  async function showRoute(room) {
    const loading = document.createElement('p');
    loading.textContent = 'Loading the walk…';
    drawDrawer(drawer, {
      title: room.name,
      sub: 'The walk from the gate',
      body: loading,
      actions: [button('Back to the room', 'btn-quiet', () => openRoom(room))]
    });

    const { data } = await apiGet('room-route.php', { name: room.name });

    if (!data.ok || !data.reachable) {
      drawDrawer(drawer, {
        title: room.name,
        sub: 'The walk from the gate',
        body: notice('error', 'No walk to show', data.ok ? data.why : (data.error || 'Could not load the walk.')),
        actions: [button('Back to the room', 'btn-quiet', () => openRoom(room))]
      });
      return;
    }

    const intro = document.createElement('p');
    intro.className = 'field-hint';
    intro.style.marginTop = '0';
    intro.textContent = 'From the gate to the door, ' + data.total
      + ' photos. This is exactly what a visitor sees, in order.';

    const strip = document.createElement('ul');
    strip.className = 'walk';

    data.steps.forEach(step => {
      const li = document.createElement('li');
      li.className = 'walk-step';
      li.dataset.last = String(step.isLast);

      const img = document.createElement('img');
      img.src = '../api/admin/thumb.php?node=' + encodeURIComponent(step.nodeId);
      img.loading = 'lazy';
      img.alt = 'Photo at step ' + step.position + ': ' + step.title;

      const text = document.createElement('div');

      const n = document.createElement('div');
      n.className = 'n';
      n.textContent = 'Step ' + step.position + ' of ' + data.total;

      const t = document.createElement('div');
      t.className = 't';
      t.textContent = step.title;

      const d = document.createElement('p');
      d.className = 'd';
      if (step.isLast) {
        d.textContent = 'The door. This is where the walk ends.';
      } else if (step.shared > 6) {
        // Naming forty rooms helps nobody; the count is the useful fact.
        d.textContent = 'A main corridor. ' + step.shared + ' rooms are reached through here.';
      } else if (step.shared > 0) {
        d.textContent = 'On the way to ' + step.leadsTo.join(', ') + '.';
      } else {
        d.textContent = 'Leads only to this room.';
      }

      text.append(n, t, d);

      li.append(img, text);
      strip.appendChild(li);
    });

    drawDrawer(drawer, {
      title: room.name,
      sub: 'The walk from the gate',
      body: [intro, strip],
      actions: [button('Back to the room', 'btn-quiet', () => openRoom(room))]
    });
  }

  /* ------------------------------------------------------- edit and relist */

  /*
    Only the name, and on the older single-building map the floor. The
    photos are changed on the Walkthrough page, where a room's walk is
    chosen again photo by photo.
  */
  function editRoom(room) {
    askFor({
      title: 'Edit location details',
      message: 'This changes what visitors see in the location list. The photos and the walking route are not affected.',
      nameLabel: 'Location name',
      nameValue: room.name,
      nameHint: 'Every room needs a different name. Two rooms with the same name means only one of them can be reached.',
      floorValue: room.floor,
      confirmLabel: 'Save',
      onSave: (newName, floor) => apiPost('room-action.php', {
        action: 'rename', name: room.name, newName, floor
      })
    });
  }

  function relist(item) {
    askFor({
      title: 'Put this location back on the list',
      message: 'Visitors will be able to choose it again. Give it the name and floor they should see.',
      nameLabel: 'Location name',
      nameValue: item.label || '',
      floorValue: floors[0],
      confirmLabel: 'Put it back',
      onSave: (newName, floor) => apiPost('room-action.php', {
        action: 'relist', nodeId: item.nodeId, newName, floor
      })
    });
  }

  /* One small form, asked in a dialog because it must be answered or
     abandoned before the list behind it changes. */
  function askFor({ title, message, nameLabel, nameValue, nameHint, floorValue, confirmLabel, onSave }) {
    const dialog = document.createElement('dialog');

    const body = document.createElement('div');
    body.className = 'dialog-body';

    const h = document.createElement('h2');
    h.textContent = title;
    const p = document.createElement('p');
    p.textContent = message;
    body.append(h, p);

    const nameField = document.createElement('div');
    nameField.className = 'field';
    const nameLab = document.createElement('label');
    nameLab.setAttribute('for', 'roomName');
    nameLab.textContent = nameLabel;
    const nameInput = document.createElement('input');
    nameInput.type = 'text';
    nameInput.id = 'roomName';
    nameInput.maxLength = 100;
    nameInput.value = nameValue;
    nameField.append(nameLab, nameInput);
    if (nameHint) {
      const hint = document.createElement('p');
      hint.className = 'field-hint';
      hint.textContent = nameHint;
      nameField.appendChild(hint);
    }

    const floorField = document.createElement('div');
    floorField.className = 'field';
    const floorLab = document.createElement('label');
    floorLab.setAttribute('for', 'roomFloor');
    floorLab.textContent = 'Floor';
    const floorInput = document.createElement('select');
    floorInput.id = 'roomFloor';
    floors.forEach(f => {
      const opt = document.createElement('option');
      opt.value = f;
      opt.textContent = floorLabel(f);
      if (f === floorValue) opt.selected = true;
      floorInput.appendChild(opt);
    });
    floorField.append(floorLab, floorInput);

    const error = document.createElement('p');
    error.className = 'field-error';
    // On the building map a room's floor comes from its photos: it moves by swapping.
    body.append(nameField, ...(structured ? [] : [floorField]), error);

    const foot = document.createElement('div');
    foot.className = 'dialog-foot';
    const cancel = document.createElement('button');
    cancel.className = 'btn btn-quiet';
    cancel.type = 'button';
    cancel.textContent = 'Cancel';
    const save = document.createElement('button');
    save.className = 'btn';
    save.type = 'button';
    save.textContent = confirmLabel;
    foot.append(cancel, save);

    dialog.append(body, foot);
    document.body.appendChild(dialog);

    const shut = () => { dialog.close(); dialog.remove(); };
    cancel.addEventListener('click', shut);
    dialog.addEventListener('cancel', e => { e.preventDefault(); shut(); });

    save.addEventListener('click', async () => {
      error.classList.remove('on');
      save.disabled = true;
      save.textContent = 'Saving…';

      const { data } = await onSave(nameInput.value.trim(), structured ? '' : floorInput.value);

      save.disabled = false;
      save.textContent = confirmLabel;

      if (!data.ok) {
        error.textContent = data.error;
        error.classList.add('on');
        return;
      }
      shut();
      toast(data.message);
      state.openName = nameInput.value.trim();
      load();
    });

    dialog.showModal();
    nameInput.focus();
    nameInput.select();
  }

  async function removeRoom(room) {
    const yes = await confirmAction({
      title: 'Remove Location ' + room.name + '?',
      // Only reached on the older map: the building map removes rooms on
      // the Walkthrough page. Photos stay on disk here.
      message: 'This will remove the location from the EduTrack navigation system.',
      confirmLabel: 'Remove Location',
      danger: true
    });
    if (!yes) return;

    const { data } = await apiPost('room-action.php', { action: 'remove', name: room.name });
    if (!data.ok) { toast(data.error, 'bad'); return; }
    toast(data.message);
    state.openName = null;
    drawDrawer(drawer, null);
    load();
  }

  drawDrawer(drawer, null);
  await load();
})();
