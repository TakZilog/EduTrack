// Script for walkthrough.html. Kept out of the page so the Content-Security-Policy
// can refuse inline scripts (see .htaccess).

/*
  The Walkthrough page: one building and floor at a time.

  Every walk starts at the main gate. Each floor has a fixed path from the gate
  to its floor point, which is locked: its pictures can be replaced, never
  removed. Each room is its own short walk from the floor point, the last
  photo being the room. Staff change a room by choosing its photos again, or
  swap, rename or remove it. Nobody sees a photo id.

  It is also where staff check the map: whether every room can be reached
  from the gate, and the walk to any room as pictures.

  Photos are prepared in the browser before anything is sent: put in the order
  they were taken, checked to be 360 photos, and shrunk, so a slow connection
  carries a fraction of what the camera wrote. Nothing reaches the server
  until Save, so Cancel leaves nothing behind.
*/

(async function () {
  const s = await boot();
  if (!s) return;

  const whereSelect = document.getElementById('where');
  const roomSelect = document.getElementById('room');
  const view = document.getElementById('floorView');
  const canEdit = allowed('room.edit');

  const MAX_PHOTOS = 20;          // room-walk.php takes at most this many
  const PANORAMA_WIDTH = 4096;    // the size every map photo is kept at
  const WHERE_KEY = 'walkthrough.where';

  const LOCK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="1.5"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>';

  const health = document.getElementById('health');

  let data = null;     // what walkthrough.php sent
  let checks = null;   // what rooms.php sent: can each room be reached from the gate
  let editor = null;   // the open photo editor, or null

  /* ------------------------------------------------------------- helpers */

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = text;
    return node;
  }

  /* Names are stored in capitals and numbered rooms as bare numbers. Shown
     the way visitors see them: "Room 204", "Cashier". */
  function roomLabel(raw) {
    if (/^\d+$/.test(raw)) return 'Room ' + raw;
    return raw.toLowerCase().replace(/(^|[\s(/&-])([a-z])/g, (m, a, b) => a + b.toUpperCase());
  }

  function thumbUrl(nodeId, bust) {
    return '../api/admin/thumb.php?node=' + encodeURIComponent(nodeId) + (bust ? '&v=' + Date.now() : '');
  }

  function photos(n) { return n + (n === 1 ? ' photo' : ' photos'); }

  function remember(value) {
    try { sessionStorage.setItem(WHERE_KEY, value); } catch { /* private mode: no memory, still works */ }
  }
  function remembered() {
    try { return sessionStorage.getItem(WHERE_KEY) || ''; } catch { return ''; }
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

  /* The building map is chosen a building and floor at a time. The older
     single-building map is one list, narrowed by floor. */
  function current() {
    if (!data.structured) return null;
    const [code, n] = whereSelect.value.split('|');
    const building = data.buildings.find(b => b.code === code);
    const floor = building && building.floors.find(f => String(f.n) === n);
    return floor ? { building, floor } : null;
  }

  function inGuide(name) {
    return (data.enrollmentRooms || []).includes(name);
  }

  /* ---------------------------------------------------------------- load */

  async function load(keepRoom) {
    const [{ data: answer }, { data: check }] = await Promise.all([apiGet('walkthrough.php'), apiGet('rooms.php')]);
    if (!answer.ok) {
      view.replaceChildren(notice('error', 'The walkthrough could not be loaded', answer.error || 'Please reload the page.'));
      return;
    }
    data = answer;
    checks = check.ok ? {
      rooms: check.rooms,
      unlisted: check.unlisted || [],
      floors: check.floors.filter(Boolean),
      problems: check.problems || [],
      byName: new Map(check.rooms.map(r => [r.name, r])),
      attention: new Set((check.problems || []).flatMap(p => p.items || []))
    } : null;
    drawHealth();

    if (!data.structured && !checks) {
      view.replaceChildren(notice('error', 'The rooms could not be loaded', check.error || 'Please reload the page.'));
      return;
    }

    const chosen = whereSelect.value || remembered();
    whereSelect.replaceChildren();
    if (data.structured) {
      data.buildings.forEach(b => b.floors.forEach(f => {
        whereSelect.appendChild(new Option(b.name + ' – ' + f.label, b.code + '|' + f.n));
      }));
    } else {
      whereSelect.appendChild(new Option('Every floor', ''));
      checks.floors.forEach(f => whereSelect.appendChild(new Option(floorLabel(f), f)));
    }
    if ([...whereSelect.options].some(o => o.value === chosen)) whereSelect.value = chosen;
    whereSelect.disabled = false;

    fillRooms(keepRoom);
    render();
  }

  /* Problems visitors would hit, above whatever floor is open: this is what
     the dashboard's "Navigation Issues" count links to. Nothing when all is well. */
  function drawHealth() {
    health.replaceChildren(...(checks ? checks.problems : [])
      .map(p => notice(p.severity === 'error' ? 'error' : 'warning', p.title, p.detail)));
  }

  function statusPill(name) {
    const room = checks && checks.byName.get(name);
    if (!room) return null;
    if (!room.reachable) return pill('Cannot be reached from the gate', 'revoked');
    if (checks.attention.has(name)) return pill('Needs attention', 'warning');
    return pill('Reachable', 'verified');
  }

  function notice(kind, title, detail) {
    const box = el('div', 'notice notice-' + kind);
    box.append(el('p', 't', title), el('p', 'd', detail));
    return box;
  }

  function roomsHere() {
    if (!data.structured) return checks.rooms.filter(r => !whereSelect.value || r.floor === whereSelect.value);
    const here = current();
    return here ? here.floor.rooms : [];
  }

  function fillRooms(keep) {
    const rooms = roomsHere();
    const want = keep !== undefined ? keep : roomSelect.value;
    roomSelect.replaceChildren(new Option(data.structured || whereSelect.value ? 'All rooms on this floor' : 'All rooms', ''));
    rooms.forEach(r => roomSelect.appendChild(new Option(roomLabel(r.name), r.name)));
    roomSelect.value = [...roomSelect.options].some(o => o.value === want) ? want : '';
    roomSelect.disabled = rooms.length === 0;
  }

  whereSelect.addEventListener('change', () => {
    remember(whereSelect.value);
    fillRooms('');
    render();
  });
  roomSelect.addEventListener('change', render);

  /* -------------------------------------------------------------- a floor */

  function render() {
    if (!data.structured) { renderOlderMap(); return; }
    const here = current();
    if (!here) { view.replaceChildren(); return; }
    view.replaceChildren(floorHead(here), ...(editor ? [editorPanel()] : [pathPanel(here), roomsPanel(here)]));
  }

  /* The older single-building map has no fixed paths or per-room walks, so
     its rooms can be renamed, moved to another floor or taken off the list,
     and nothing else. */
  function renderOlderMap() {
    const panel = el('section', 'panel wt-rooms');
    panel.setAttribute('aria-labelledby', 'roomsTitle');
    const head = el('div', 'panel-head');
    const title = el('h2', null, whereSelect.value ? 'Rooms on the ' + floorLabel(whereSelect.value) : 'Every room');
    title.id = 'roomsTitle';
    head.appendChild(title);
    panel.appendChild(head);

    const rooms = roomsHere().filter(r => !roomSelect.value || r.name === roomSelect.value);
    if (rooms.length === 0) {
      const empty = el('div', 'empty');
      empty.append(el('h3', null, 'No rooms here'), el('p', null, 'No room on the list is on this floor.'));
      panel.appendChild(empty);
    } else {
      const list = el('ul', 'wt-room-list');
      rooms.forEach(room => list.appendChild(roomRow(room)));
      panel.appendChild(list);
    }

    view.replaceChildren(
      notice('info', 'This map is not set up by building and floor yet',
        'Rooms can be renamed, moved to another floor or taken off the list here. '
        + 'Their photos can be changed once the school\'s photos are imported by building and floor.'),
      panel,
      ...(checks.unlisted.length && !whereSelect.value ? [unlistedPanel()] : [])
    );
  }

  /* Room photos on the map that visitors are not offered, because a room was
     taken off the list. Without this they could never be found again. */
  function unlistedPanel() {
    const panel = el('section', 'panel wt-rooms');
    panel.setAttribute('aria-labelledby', 'unlistedTitle');
    const head = el('div', 'panel-head');
    const title = el('h2', null, 'Not shown to visitors');
    title.id = 'unlistedTitle';
    head.appendChild(title);

    const list = el('ul', 'wt-room-list');
    checks.unlisted.forEach(item => {
      const row = el('li', 'wt-room');
      const img = el('img');
      img.src = thumbUrl(item.nodeId);
      img.loading = 'lazy';
      img.alt = (item.label || 'This room') + ', a room photo visitors are not offered';

      const text = el('div', 'wt-room-text');
      text.append(el('h3', null, item.label ? roomLabel(item.label) : 'Unnamed room'),
        el('p', 'wt-room-meta', item.reachable ? 'Can be reached from the gate' : 'Cannot be reached from the gate'));

      const actions = el('div', 'wt-room-actions');
      if (canEdit) actions.appendChild(button('Put back on the list', 'btn btn-small', () => relist(item)));

      row.append(img, text, actions);
      list.appendChild(row);
    });

    panel.append(head, list);
    return panel;
  }

  function floorHead({ building, floor }) {
    const head = el('div', 'wt-floor-head');
    const num = el('span', 'floor-num', String(floor.n));
    num.setAttribute('aria-hidden', 'true');

    const words = el('div', 'wt-floor-words');
    words.append(
      el('h2', null, building.name + ' – ' + floor.label),
      el('p', 'wt-floor-sub', floor.rooms.length === 1 ? '1 room' : floor.rooms.length + ' rooms')
    );
    head.append(num, words);

    if (canEdit && !editor) {
      head.appendChild(button('Add a room', 'btn', () => openEditor('add', null)));
    }
    return head;
  }

  function pathPanel({ building, floor }) {
    const panel = el('section', 'panel wt-path');
    panel.setAttribute('aria-labelledby', 'pathTitle');

    const head = el('div', 'panel-head');
    const title = el('h2', null, 'Fixed path from the main gate');
    title.id = 'pathTitle';
    const lock = el('span', 'wt-lock');
    lock.innerHTML = LOCK;
    lock.append('Locked');
    head.append(title, lock);

    const body = el('div', 'panel-body');
    body.appendChild(el('p', 'wt-note',
      'Every walk to a room on this floor starts with these photos. You can replace a picture, '
      + 'but the photos cannot be removed.'));

    const strip = el('ol', 'wt-strip');
    floor.path.forEach((nodeId, i) => {
      const last = i === floor.path.length - 1;
      const name = i === 0 ? 'Main gate' : last ? 'Floor point' : 'Photo ' + i;

      const shot = el('li', 'wt-shot');
      const img = el('img');
      img.src = thumbUrl(nodeId);
      img.loading = 'lazy';
      img.alt = name + ', ' + building.name + ' ' + floor.label;
      shot.append(img, el('p', 'wt-shot-name', name));

      if (canEdit) {
        shot.appendChild(button('Replace photo', 'btn-quiet btn-small', () => replaceFixed(nodeId, name, img)));
      }
      strip.appendChild(shot);
    });
    body.appendChild(strip);

    panel.append(head, body);
    return panel;
  }

  function roomsPanel({ floor }) {
    const panel = el('section', 'panel wt-rooms');
    panel.setAttribute('aria-labelledby', 'roomsTitle');
    const head = el('div', 'panel-head');
    const title = el('h2', null, 'Rooms on this floor');
    title.id = 'roomsTitle';
    head.appendChild(title);
    panel.appendChild(head);

    if (floor.rooms.length === 0) {
      const empty = el('div', 'empty');
      empty.append(el('h3', null, 'No rooms on this floor yet'),
        el('p', null, 'Add a room by choosing the photos from the floor point to its door.'));
      if (canEdit) empty.appendChild(button('Add a room', 'btn', () => openEditor('add', null)));
      panel.appendChild(empty);
      return panel;
    }

    const list = el('ul', 'wt-room-list');
    floor.rooms
      .filter(r => !roomSelect.value || r.name === roomSelect.value)
      .forEach(room => list.appendChild(roomRow(room)));
    panel.appendChild(list);
    return panel;
  }

  function roomRow(room) {
    const row = el('li', 'wt-room');

    const img = el('img');
    img.src = thumbUrl(room.nodeId);
    img.loading = 'lazy';
    img.alt = roomLabel(room.name) + ', the last photo of its walk';

    const text = el('div', 'wt-room-text');
    text.append(el('h3', null, roomLabel(room.name)),
      el('p', 'wt-room-meta', data.structured ? photos(room.photos) + ' from the floor point' : floorLabel(room.floor)));
    const state = statusPill(room.name);
    if (state) {
      const line = el('p', 'wt-room-state');
      line.appendChild(state);
      text.appendChild(line);
    }
    const guide = inGuide(room.name);
    if (guide) text.appendChild(el('p', 'wt-tag', 'In the enrollment guide'));

    const actions = el('div', 'wt-room-actions');
    const visit = el('a', 'btn btn-quiet btn-small', 'See it as a visitor');
    visit.href = '../map/walkthrough.html?room=' + encodeURIComponent(room.name);
    visit.target = '_blank';
    visit.rel = 'noopener';

    if (canEdit) {
      if (data.structured) {
        actions.append(
          button('Update photos', 'btn btn-small', () => openEditor('update', room)),
          button('Swap with…', 'btn-quiet btn-small', () => swapRoom(room))
        );
      }
      actions.appendChild(button('Rename', 'btn-quiet btn-small', () => renameRoom(room)));
      const remove = button('Remove', 'btn-danger btn-small', () => removeRoom(room));
      if (guide) {
        // Removing it would break the enrollment guide; the server refuses too.
        remove.disabled = true;
        remove.setAttribute('aria-describedby', 'guide-' + room.nodeId);
        const why = el('p', 'wt-why', 'The enrollment guide sends visitors here, so it cannot be removed. Rename or swap it instead.');
        why.id = 'guide-' + room.nodeId;
        text.appendChild(why);
      }
      actions.appendChild(remove);
    }
    actions.append(button('View route', 'btn-quiet btn-small', () => showRoute(room)), visit);

    row.append(img, text, actions);
    return row;
  }

  /*
    The walk from the gate to the door, as pictures, in order. This is the
    answer to "which photo is which part of the building": nobody reads a
    photo id, they look at the corridor and see where it leads.
  */
  async function showRoute(room) {
    const dialog = el('dialog', 'wt-route');
    const body = el('div', 'dialog-body');
    const loading = el('p', null, 'Loading the walk…');
    body.append(el('h2', null, 'The walk to ' + roomLabel(room.name)), loading);
    const foot = el('div', 'dialog-foot');
    foot.appendChild(button('Close', 'btn-quiet', () => dialog.close()));
    dialog.append(body, foot);
    dialog.addEventListener('close', () => dialog.remove());
    document.body.appendChild(dialog);
    dialog.showModal();

    const { data: answer } = await apiGet('room-route.php', { name: room.name });
    if (!dialog.open) return;

    if (!answer.ok || !answer.reachable) {
      loading.replaceWith(notice('error', 'No walk to show',
        answer.ok ? answer.why : (answer.error || 'Could not load the walk.')));
      return;
    }

    const strip = el('ol', 'walk');
    answer.steps.forEach(step => {
      const li = el('li', 'walk-step');
      li.dataset.last = String(step.isLast);

      const img = el('img');
      img.src = thumbUrl(step.nodeId);
      img.loading = 'lazy';
      img.alt = 'Photo at step ' + step.position + ': ' + step.title;

      let where;
      if (step.isLast) {
        where = 'The door. This is where the walk ends.';
      } else if (step.shared > 6) {
        // Naming forty rooms helps nobody; the count is the useful fact.
        where = 'A main corridor. ' + step.shared + ' rooms are reached through here.';
      } else if (step.shared > 0) {
        where = 'On the way to ' + step.leadsTo.map(roomLabel).join(', ') + '.';
      } else {
        where = 'Leads only to this room.';
      }

      const text = el('div');
      text.append(el('div', 'n', 'Step ' + step.position + ' of ' + answer.total),
        el('div', 't', step.isLast ? roomLabel(step.title) : step.title), el('p', 'd', where));

      li.append(img, text);
      strip.appendChild(li);
    });

    loading.replaceWith(el('p', null, 'From the gate to the door, ' + photos(answer.total)
      + '. This is exactly what a visitor sees, in order.'), strip);
  }

  /* ------------------------------------------------------ preparing photos */

  /* When a JPEG was taken, from its EXIF DateTimeOriginal, or null. Cameras
     write it; some phone apps strip it when a photo is edited. */
  async function takenTime(file) {
    try {
      const bytes = new DataView(await file.slice(0, 256 * 1024).arrayBuffer());
      if (bytes.getUint16(0) !== 0xFFD8) return null;
      for (let p = 2; p + 10 < bytes.byteLength; ) {
        const marker = bytes.getUint16(p);
        if (marker === 0xFFE1 && bytes.getUint32(p + 4) === 0x45786966) return exifDate(bytes, p + 10);
        if ((marker & 0xFF00) !== 0xFF00 || marker === 0xFFDA) return null;
        p += 2 + bytes.getUint16(p + 2);
      }
    } catch { /* a damaged or unusual file: treat as undated */ }
    return null;
  }

  function exifDate(bytes, tiff) {
    const little = bytes.getUint16(tiff) === 0x4949;
    const u16 = o => bytes.getUint16(tiff + o, little);
    const u32 = o => bytes.getUint32(tiff + o, little);
    const entry = (ifd, tag) => {
      for (let i = 0, n = u16(ifd); i < n; i++) {
        if (u16(ifd + 2 + i * 12) === tag) return ifd + 2 + i * 12;
      }
      return null;
    };
    const pointer = entry(u32(4), 0x8769);              // IFD0 -> EXIF IFD
    if (pointer === null) return null;
    const date = entry(u32(pointer + 8), 0x9003);        // DateTimeOriginal
    if (date === null) return null;
    let text = '';
    for (let i = 0; i < 19; i++) text += String.fromCharCode(bytes.getUint8(tiff + u32(date + 8) + i));
    return /^\d{4}:\d\d:\d\d \d\d:\d\d:\d\d$/.test(text) && !text.startsWith('0000') ? text : null;
  }

  function readableTime(exif, fallbackMs) {
    const d = exif ? new Date(exif.replace(/^(\d{4}):(\d\d):(\d\d) /, '$1-$2-$3T')) : new Date(fallbackMs);
    return d.toLocaleString(undefined, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit', second: '2-digit' });
  }

  function toBlob(canvas, quality) {
    return new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', quality));
  }

  /* Opens one chosen file, checks it is a 360 photo, and makes the copy that
     is sent (at most 4096 wide) and a small one for the preview. */
  async function prepare(file) {
    let bitmap;
    try {
      bitmap = await createImageBitmap(file);
    } catch {
      return { file, error: file.name + ' was left out: it is not a picture this browser can open.' };
    }

    const ratio = bitmap.width / bitmap.height;
    if (ratio < 1.9 || ratio > 2.1) {
      const error = file.name + ' was left out: it is not a 360 photo. It is ' + bitmap.width + ' by '
        + bitmap.height + ', and a 360 photo is always twice as wide as it is tall.';
      bitmap.close();
      return { file, error };
    }

    const width = Math.min(PANORAMA_WIDTH, bitmap.width);
    const big = document.createElement('canvas');
    big.width = width;
    big.height = Math.round(width / ratio);
    big.getContext('2d').drawImage(bitmap, 0, 0, big.width, big.height);

    const small = document.createElement('canvas');
    small.width = 320;
    small.height = 160;
    small.getContext('2d').drawImage(bitmap, 0, 0, 320, 160);
    bitmap.close();

    const [blob, preview] = await Promise.all([toBlob(big, 0.9), toBlob(small, 0.8)]);
    if (!blob || !preview) return { file, error: file.name + ' was left out: it could not be prepared.' };

    return {
      name: file.name,
      taken: await takenTime(file),
      modified: file.lastModified,
      blob,
      preview: URL.createObjectURL(preview)
    };
  }

  /* ----------------------------------------------------------- the editor */

  function openEditor(mode, room) {
    editor = { mode, room, where: current(), photos: [], notes: [], name: '', busy: false };
    whereSelect.disabled = true;
    roomSelect.disabled = true;
    render();
    const heading = document.getElementById('editorTitle');
    if (heading) { heading.tabIndex = -1; heading.focus(); }
  }

  function closeEditor() {
    if (editor) editor.photos.forEach(p => URL.revokeObjectURL(p.preview));
    editor = null;
    whereSelect.disabled = false;
    fillRooms();
    render();
  }

  function editorPanel() {
    const { mode, room, where } = editor;
    const panel = el('section', 'panel wt-editor');
    panel.setAttribute('aria-labelledby', 'editorTitle');

    const head = el('div', 'panel-head');
    const title = el('h2', null, mode === 'add'
      ? 'Add a room to ' + where.building.name + ' – ' + where.floor.label
      : 'New photos for ' + roomLabel(room.name));
    title.id = 'editorTitle';
    head.appendChild(title);

    const body = el('div', 'panel-body');

    if (mode === 'add') {
      const input = el('input');
      input.type = 'text';
      input.maxLength = 100;
      input.autocomplete = 'off';
      input.value = editor.name;
      input.disabled = editor.busy;
      input.addEventListener('input', () => { editor.name = input.value; syncSave(); });
      body.appendChild(field('newRoomName', 'Room name', input,
        'What visitors see in the room list, for example "Cashier" or "Room 204".'));
    }

    body.appendChild(el('p', 'wt-note',
      'Choose every photo from the floor point to the ' + (mode === 'add' ? 'room' : 'door of ' + roomLabel(room.name))
      + ', up to ' + MAX_PHOTOS + '. They are put in the order they were taken. The last photo must be the room itself.'));

    const picker = el('input');
    picker.type = 'file';
    picker.multiple = true;
    picker.accept = 'image/jpeg,image/png,image/webp';
    picker.hidden = true;
    picker.addEventListener('change', () => choose([...picker.files]));
    const pick = button(editor.photos.length ? 'Choose the photos again' : 'Choose photos', 'btn-quiet wt-pick', () => picker.click());
    pick.disabled = editor.busy;
    body.append(picker, pick);

    const status = el('div', 'wt-status');
    status.setAttribute('role', 'status');
    editor.notes.forEach(line => status.appendChild(el('p', null, line)));
    body.append(status, stepsList());

    const foot = el('div', 'wt-editor-foot');
    const save = button(mode === 'add' ? 'Add the room' : 'Save the new photos', 'btn', saveWalk);
    save.id = 'editorSave';
    const cancel = button('Cancel', 'btn-quiet', closeEditor);
    cancel.disabled = editor.busy;
    foot.append(save, cancel);

    panel.append(head, body, foot);
    queueMicrotask(syncSave);
    return panel;
  }

  function syncSave() {
    const save = document.getElementById('editorSave');
    if (!save || !editor) return;
    const named = editor.mode !== 'add' || editor.name.trim() !== '';
    save.disabled = editor.busy || editor.photos.length === 0 || !named;
  }

  function say(lines) {
    editor.notes = lines;
    const status = view.querySelector('.wt-status');
    if (status) status.replaceChildren(...lines.map(line => el('p', null, line)));
  }

  async function choose(files) {
    if (!files.length) return;
    editor.photos.forEach(p => URL.revokeObjectURL(p.preview));
    editor.photos = [];
    editor.busy = true;
    render();

    const notes = [];
    if (files.length > MAX_PHOTOS) {
      notes.push('Only the first ' + MAX_PHOTOS + ' photos were taken. A walk can have at most ' + MAX_PHOTOS + '.');
      files = files.slice(0, MAX_PHOTOS);
    }

    // One at a time: a 360 photo can take hundreds of megabytes to open.
    const ready = [];
    for (let i = 0; i < files.length; i++) {
      say(['Getting photo ' + (i + 1) + ' of ' + files.length + ' ready…']);
      const photo = await prepare(files[i]);
      if (photo.error) notes.push(photo.error); else ready.push(photo);
    }

    const byName = (a, b) => a.name.localeCompare(b.name, undefined, { numeric: true });
    const allDated = ready.every(p => p.taken);
    ready.sort((a, b) => (allDated ? a.taken.localeCompare(b.taken) : a.modified - b.modified) || byName(a, b));
    if (ready.length > 1 && !allDated) {
      notes.push('Some photos do not say when they were taken, so they are in the order they were saved. '
        + 'Check the order, and move any photo that is out of place.');
    }

    editor.photos = ready;
    editor.busy = false;
    editor.notes = notes;
    render();
  }

  /* The walk as a visitor will see it: the locked fixed path, then the new
     photos, the last one being the room. */
  function stepsList() {
    const list = el('ol', 'wt-steps');
    list.setAttribute('aria-label', 'The walk, in order');

    const fixed = el('li', 'wt-step is-fixed');
    const shots = el('div', 'wt-fixed-shots');
    editor.where.floor.path.forEach(nodeId => {
      const img = el('img');
      img.src = thumbUrl(nodeId);
      img.alt = '';
      shots.appendChild(img);
    });
    const fixedText = el('div', 'wt-step-text');
    const lock = el('p', 'wt-step-title');
    lock.innerHTML = LOCK;
    lock.append('Fixed path');
    fixedText.append(lock, el('p', 'wt-step-meta',
      'From the main gate to the floor point, ' + photos(editor.where.floor.path.length) + '. The same for every room on this floor.'));
    fixed.append(shots, fixedText);
    list.appendChild(fixed);

    if (editor.photos.length === 0) {
      if (!editor.busy) list.appendChild(el('li', 'wt-step is-empty', 'No photos chosen yet.'));
      return list;
    }

    const count = editor.photos.length;
    editor.photos.forEach((photo, i) => {
      const last = i === count - 1;
      const item = el('li', 'wt-step' + (last ? ' is-room' : ''));
      item.draggable = !editor.busy;
      item.dataset.index = String(i);

      const img = el('img');
      img.src = photo.preview;
      img.alt = '';

      const text = el('div', 'wt-step-text');
      text.append(
        el('p', 'wt-step-title', 'Step ' + (i + 1) + (last ? ' — this is the room' : '')),
        el('p', 'wt-step-meta', photo.name + ' · ' + (photo.taken ? 'taken ' : 'saved ') + readableTime(photo.taken, photo.modified))
      );

      const moves = el('div', 'wt-step-moves');
      const up = button('Move up', 'btn-quiet btn-small', () => move(i, i - 1, 'up'));
      up.dataset.move = 'up';
      up.setAttribute('aria-label', 'Move step ' + (i + 1) + ' up');
      const down = button('Move down', 'btn-quiet btn-small', () => move(i, i + 1, 'down'));
      down.dataset.move = 'down';
      down.setAttribute('aria-label', 'Move step ' + (i + 1) + ' down');
      const out = button('Take out', 'btn-quiet btn-small', () => takeOut(i));
      out.setAttribute('aria-label', 'Take step ' + (i + 1) + ' out');
      up.disabled = editor.busy || i === 0;
      down.disabled = editor.busy || last;
      out.disabled = editor.busy;
      moves.append(up, down, out);

      item.append(img, text, moves);
      if (!editor.busy) dragging(item, i);
      list.appendChild(item);
    });
    return list;
  }

  function move(from, to, keep) {
    if (to < 0 || to >= editor.photos.length) return;
    const [photo] = editor.photos.splice(from, 1);
    editor.photos.splice(to, 0, photo);
    render();
    // Keep the keyboard on the photo that moved.
    const step = view.querySelector('.wt-step[data-index="' + to + '"]');
    const again = step && step.querySelector('[data-move="' + keep + '"]');
    const target = again && !again.disabled ? again : step && step.querySelector('button:not(:disabled)');
    if (target) target.focus();
  }

  function takeOut(i) {
    const [photo] = editor.photos.splice(i, 1);
    URL.revokeObjectURL(photo.preview);
    render();
  }

  function dragging(item, index) {
    item.addEventListener('dragstart', e => {
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', String(index));
      item.classList.add('is-dragging');
    });
    item.addEventListener('dragend', () => item.classList.remove('is-dragging'));
    item.addEventListener('dragover', e => { e.preventDefault(); item.classList.add('is-over'); });
    item.addEventListener('dragleave', () => item.classList.remove('is-over'));
    item.addEventListener('drop', e => {
      e.preventDefault();
      const from = Number(e.dataTransfer.getData('text/plain'));
      if (Number.isInteger(from) && from !== index) move(from, index, 'up');
    });
  }

  /* --------------------------------------------------------------- saving */

  /* A file upload with progress, which fetch() cannot report. Same session,
     CSRF header and one retry on a stale token as apiPost(). */
  function upload(endpoint, form, onProgress) {
    const send = () => new Promise(resolve => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', API + endpoint);
      xhr.setRequestHeader('X-CSRF-Token', csrfToken);
      xhr.upload.addEventListener('progress', e => {
        if (e.lengthComputable && onProgress) onProgress(e.loaded / e.total);
      });
      xhr.addEventListener('load', () => {
        try { resolve(JSON.parse(xhr.responseText)); }
        catch { resolve({ ok: false, error: 'The server sent back something unreadable. Nothing was changed.' }); }
      });
      xhr.addEventListener('error', () => resolve({ ok: false, error: 'Could not reach the server. Nothing was changed.' }));
      xhr.send(form);
    });

    return send().then(async answer => {
      if (answer.code === 'csrf') {
        const { data: info } = await apiGet('session.php');
        if (info && info.ok) { csrfToken = info.csrfToken; answer = await send(); }
      }
      if (answer.code === 'auth') return toSignIn().data;
      return answer;
    });
  }

  async function saveWalk() {
    const { mode, room, where } = editor;
    const form = new FormData();
    form.append('mode', mode);
    form.append('building', where.building.code);
    form.append('floor', String(where.floor.n));
    form.append('room', mode === 'add' ? editor.name.trim() : room.name);
    form.append('count', String(editor.photos.length));
    editor.photos.forEach((photo, i) => form.append('photos[]', photo.blob, 'step-' + String(i + 1).padStart(2, '0') + '.jpg'));

    editor.busy = true;
    render();
    say(['Sending the photos… 0%']);

    const answer = await upload('room-walk.php', form, part => {
      say([part < 1 ? 'Sending the photos… ' + Math.round(part * 100) + '%' : 'Saving on the server…']);
    });

    if (!answer.ok) {
      editor.busy = false;
      editor.notes = [answer.error || 'The photos could not be saved. Nothing was changed.'];
      render();
      const status = view.querySelector('.wt-status');
      if (status) status.setAttribute('role', 'alert');
      return;
    }

    closeEditor();
    toast(answer.message);
    await load(answer.room);
  }

  /* One fixed-path picture swapped in place. The route does not change. */
  function replaceFixed(nodeId, name, img) {
    const picker = el('input');
    picker.type = 'file';
    picker.accept = 'image/jpeg,image/png,image/webp';

    picker.addEventListener('change', async () => {
      const file = picker.files[0];
      if (!file) return;

      const yes = await confirmAction({
        title: 'Replace the ' + name.toLowerCase() + ' photo?',
        message: (name === 'Main gate'
          ? 'Every walk, to every floor, starts with this picture. '
          : 'Every walk to a room on this floor passes this picture. ')
          + 'The old picture is deleted. The new one must be a 360 photo, twice as wide as it is tall.',
        confirmLabel: 'Replace photo'
      });
      if (!yes) return;

      toast('Getting the photo ready…');
      const photo = await prepare(file);
      if (photo.error) { toast(photo.error, 'bad'); return; }
      URL.revokeObjectURL(photo.preview);

      const form = new FormData();
      form.append('node', nodeId);
      form.append('photo', photo.blob, 'photo.jpg');
      const answer = await upload('photo-replace.php', form);
      if (!answer.ok) { toast(answer.error || 'The photo could not be replaced.', 'bad'); return; }

      toast(answer.message);
      img.src = thumbUrl(nodeId, true);
    });

    picker.click();
  }

  /* ------------------------------------------------ swap, rename, remove */

  function field(id, labelText, control, hint) {
    const box = el('div', 'field');
    const label = el('label', null, labelText);
    label.htmlFor = id;
    control.id = id;
    box.append(label, control);
    if (hint) box.appendChild(el('p', 'field-hint', hint));
    return box;
  }

  /* A small form in a dialog: answered or abandoned before the page behind
     it changes. onSave returns the server's answer. */
  function ask({ title, message, fields, confirmLabel, onSave }) {
    const dialog = el('dialog');
    const body = el('div', 'dialog-body');
    body.append(el('h2', null, title), el('p', null, message), ...fields);
    const error = el('p', 'field-error');
    error.setAttribute('role', 'alert');
    body.appendChild(error);

    const shut = () => { dialog.close(); dialog.remove(); };
    const foot = el('div', 'dialog-foot');
    const cancel = button('Cancel', 'btn-quiet', shut);
    const go = button(confirmLabel, 'btn', async () => {
      error.classList.remove('on');
      go.disabled = true;
      go.textContent = 'Saving…';
      const answer = await onSave();
      go.disabled = false;
      go.textContent = confirmLabel;
      if (!answer.ok) {
        error.textContent = answer.error;
        error.classList.add('on');
        return;
      }
      shut();
      toast(answer.message);
      await load('');
    });
    foot.append(cancel, go);
    dialog.append(body, foot);
    document.body.appendChild(dialog);

    dialog.addEventListener('cancel', e => { e.preventDefault(); shut(); });
    dialog.showModal();
    (dialog.querySelector('input, select') || go).focus();
  }

  function renameRoom(room) {
    const input = el('input');
    input.type = 'text';
    input.maxLength = 100;
    input.autocomplete = 'off';
    input.value = roomLabel(room.name);

    const fields = [field('renameField', 'New name', input,
      inGuide(room.name) ? 'The enrollment guide sends visitors here, and it will use the new name too.' : null)];
    // On the building map a room's floor comes from its photos: it moves by swapping.
    const floor = data.structured ? null : floorChoice(room.floor);
    if (floor) fields.push(field('renameFloor', 'Floor', floor));

    ask({
      title: 'Rename ' + roomLabel(room.name),
      message: 'The photos stay the same. Visitors see the new name in the room list and when they arrive.',
      fields,
      confirmLabel: 'Rename',
      onSave: async () => (await apiPost('room-action.php', {
        action: 'rename', name: room.name, newName: input.value, floor: floor ? floor.value : ''
      })).data
    });
  }

  function floorChoice(selected) {
    const select = el('select');
    checks.floors.forEach(f => select.appendChild(new Option(floorLabel(f), f, false, f === selected)));
    return select;
  }

  /* Older map only: a room photo taken off the list goes back on it. */
  function relist(item) {
    const input = el('input');
    input.type = 'text';
    input.maxLength = 100;
    input.autocomplete = 'off';
    input.value = item.label ? roomLabel(item.label) : '';
    const floor = floorChoice(checks.floors[0]);

    ask({
      title: 'Put this room back on the list',
      message: 'Visitors will be able to choose it again. Give it the name and floor they should see.',
      fields: [field('relistName', 'Room name', input), field('relistFloor', 'Floor', floor)],
      confirmLabel: 'Put it back',
      onSave: async () => (await apiPost('room-action.php', {
        action: 'relist', nodeId: item.nodeId, newName: input.value, floor: floor.value
      })).data
    });
  }

  function swapRoom(room) {
    const select = el('select');
    data.buildings.forEach(b => b.floors.forEach(f => {
      const others = f.rooms.filter(r => r.name !== room.name);
      if (!others.length) return;
      const group = el('optgroup');
      group.label = b.name + ' – ' + f.label;
      others.forEach(r => group.appendChild(new Option(roomLabel(r.name), r.name)));
      select.appendChild(group);
    }));
    if (!select.options.length) {
      toast('There is no other room to swap with yet.', 'bad');
      return;
    }

    ask({
      title: 'Swap ' + roomLabel(room.name) + ' with another room',
      message: 'Use this when an office moves but the doors stay where they are. The two names trade places: '
        + roomLabel(room.name) + ' will lead to the other room\'s door, and the other name will lead here.',
      fields: [field('swapField', 'Swap with', select)],
      confirmLabel: 'Swap the rooms',
      onSave: async () => (await apiPost('room-action.php', { action: 'swap', name: room.name, otherName: select.value })).data
    });
  }

  async function removeRoom(room) {
    const yes = await confirmAction({
      title: 'Remove ' + roomLabel(room.name) + '?',
      // On the older map hallway photos are shared, so the server keeps them.
      message: data.structured
        ? 'Visitors will no longer be able to choose it, and its ' + photos(room.photos)
          + ' are deleted for good. The fixed path is not affected.'
        : 'Visitors will no longer be able to choose it. Its photos are kept, and it can be put back on the list from this page.',
      confirmLabel: 'Remove the room',
      danger: true
    });
    if (!yes) return;

    const { data: answer } = await apiPost('room-action.php', { action: 'remove', name: room.name });
    if (!answer.ok) { toast(answer.error, 'bad'); return; }
    toast(answer.message);
    await load('');
  }

  await load();
})();
