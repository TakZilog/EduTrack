// Script for users-access.html. Kept out of the page so the Content-Security-Policy
// can refuse inline scripts (see .htaccess).

/*
  Staff accounts: who can sign in, what each person may do, and the changes a
  full-access admin makes to them. The names and descriptions of the access
  levels come from the server (api/admin/permissions.php), so this page always
  says what a level really allows.
*/

(async function () {
  const s = await boot();
  if (!s) return;

  const rows = document.getElementById('adminRows');
  const drawer = document.getElementById('drawer');
  const roleSelect = document.getElementById('newRole');
  const roleHint = document.getElementById('newRoleHint');

  let roles = {};          // role key -> label
  let summaries = {};      // role key -> what that level can do
  let passwordMin = 15;
  let openId = null;

  // Least access first, and the one a new person starts with.
  const ROLE_ORDER = ['faculty', 'admin', 'super_admin'];

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = text;
    return node;
  }

  function fillRoles(select, chosen) {
    select.replaceChildren();
    ROLE_ORDER.filter(key => roles[key]).forEach(key => select.appendChild(new Option(roles[key], key)));
    select.value = chosen || ROLE_ORDER[0];
  }

  async function load() {
    const { data } = await apiGet('settings.php');
    if (!data.ok) { toast(data.error || 'Could not load the staff accounts.', 'bad'); return; }

    roles = data.roles || {};
    summaries = data.roleSummaries || {};
    passwordMin = data.passwordMin || passwordMin;

    if (!roleSelect.options.length) {
      fillRoles(roleSelect);
      roleHint.textContent = summaries[roleSelect.value] || '';
    }
    document.getElementById('newPasswordHint').textContent = 'At least ' + passwordMin + ' characters. A short sentence is easy to remember.';

    rows.replaceChildren();
    data.admins.forEach(admin => {
      const lastLogin = el('span', 'row-when', 'Last sign-in: ' + (admin.lastLogin ? relativeTime(admin.lastLogin) : 'never'));
      rows.appendChild(listRow({
        title: admin.fullName + (admin.isYou ? ' (you)' : ''),
        sub: admin.username,
        end: [pill(admin.roleLabel, 'off'), pill(admin.active ? 'On' : 'Turned off', admin.active ? 'verified' : 'revoked'), lastLogin],
        selected: admin.id === openId,
        onOpen: row => { markOpenRow(rows, row); openUser(admin); }
      }));
    });
    openUser(data.admins.find(admin => admin.id === openId) || null);
  }

  roleSelect.addEventListener('change', () => { roleHint.textContent = summaries[roleSelect.value] || ''; });

  /* ------------------------------------------------------------ one person */

  function openUser(admin) {
    openId = admin ? admin.id : null;
    if (!admin) { drawDrawer(drawer, null); return; }

    const actions = [button(admin.isYou ? 'Edit my name' : 'Edit name or access level', 'btn-quiet', () => editUser(admin))];

    if (admin.isYou) {
      actions.push(button('Change my password', 'btn-quiet', () => changeOwnPassword()));
    } else {
      actions.push(button('Set a new password', 'btn-quiet', () => resetPassword(admin)));
      actions.push(button(
        admin.active ? 'Turn this account off' : 'Turn this account back on',
        admin.active ? 'btn-danger' : 'btn',
        () => setActive(admin)
      ));
    }

    drawDrawer(drawer, {
      title: admin.fullName + (admin.isYou ? ' (you)' : ''),
      sub: admin.username,
      body: facts([
        ['Username', admin.username],
        ['Access level', admin.roleLabel],
        ['What they can do', summaries[admin.role] || 'Set by EduTrack.'],
        ['Status', admin.active ? pill('On', 'verified') : pill('Turned off', 'revoked')],
        ['Last sign-in', admin.lastLogin ? relativeTime(admin.lastLogin) : 'Never']
      ]),
      actions
    });
  }

  async function setActive(admin) {
    const yes = await confirmAction({
      title: admin.active ? 'Turn this account off?' : 'Turn this account back on?',
      message: admin.active
        ? admin.fullName + ' will be signed out straight away and will not be able to sign in again until the account is turned back on.'
        : admin.fullName + ' will be able to sign in again.',
      confirmLabel: admin.active ? 'Yes, turn it off' : 'Yes, turn it on',
      danger: admin.active
    });
    if (!yes) return;
    const { data } = await apiPost('settings.php', { action: 'set-admin-active', id: admin.id, active: !admin.active });
    if (!data.ok) { toast(data.error, 'bad'); return; }
    toast(data.message);
    load();
  }

  /* ------------------------------------------------------------- dialogs */

  function field(id, labelText, control, hint) {
    const box = el('div', 'field');
    const label = el('label', null, labelText);
    label.htmlFor = id;
    control.id = id;
    box.append(label, control);
    if (hint) box.appendChild(el('p', 'field-hint', hint));
    return box;
  }

  function passwordInput(autocomplete) {
    const input = el('input');
    input.type = 'password';
    input.autocomplete = autocomplete;
    return input;
  }

  /* One "Show passwords" box for every password field it is given. */
  function showToggle(inputs) {
    const label = el('label', 'show-passwords');
    const box = el('input');
    box.type = 'checkbox';
    box.addEventListener('change', () => inputs.forEach(i => { i.type = box.checked ? 'text' : 'password'; }));
    label.append(box, ' Show passwords');
    return label;
  }

  /* A small form in a dialog. check() returns an error to show before
     anything is sent, or null; send() returns the server's answer. */
  function ask({ title, message, parts, confirmLabel, check, send }) {
    const dialog = el('dialog');
    const body = el('div', 'dialog-body');
    body.append(el('h2', null, title), el('p', null, message), ...parts);
    const error = el('p', 'field-error');
    error.setAttribute('role', 'alert');
    body.appendChild(error);

    const shut = () => { dialog.close(); dialog.remove(); };
    const foot = el('div', 'dialog-foot');
    const go = button(confirmLabel, 'btn', async () => {
      error.classList.remove('on');
      const problem = check ? check() : null;
      if (problem) { error.textContent = problem; error.classList.add('on'); return; }
      go.disabled = true;
      go.textContent = 'Saving…';
      const { data } = await send();
      go.disabled = false;
      go.textContent = confirmLabel;
      if (!data.ok) { error.textContent = data.error; error.classList.add('on'); return; }
      shut();
      toast(data.message);
      load();
    });
    foot.append(button('Cancel', 'btn-quiet', shut), go);
    dialog.append(body, foot);
    document.body.appendChild(dialog);
    dialog.addEventListener('cancel', e => { e.preventDefault(); shut(); });
    dialog.showModal();
    (dialog.querySelector('input:not([type="checkbox"]), select') || go).focus();
  }

  function samePasswords(first, second) {
    if (first.value.length < passwordMin) return 'The password needs at least ' + passwordMin + ' characters.';
    if (first.value !== second.value) return 'The two passwords are not the same. Type them again.';
    return null;
  }

  function editUser(admin) {
    const name = el('input');
    name.type = 'text';
    name.maxLength = 100;
    name.autocomplete = 'off';
    name.value = admin.fullName;

    const level = el('select');
    fillRoles(level, admin.role);
    level.disabled = admin.isYou;
    const levelBox = field('editLevel', 'Access level', level,
      admin.isYou ? 'You cannot change your own access level. Another full-access admin can.' : summaries[admin.role]);
    const levelHint = levelBox.querySelector('.field-hint');
    if (!admin.isYou) level.addEventListener('change', () => { levelHint.textContent = summaries[level.value] || ''; });

    ask({
      title: admin.isYou ? 'Edit my name' : 'Edit ' + admin.fullName,
      message: admin.isYou
        ? 'This is the name shown in the panel and in the activity log.'
        : 'A new access level applies straight away, even if they are signed in now.',
      parts: [field('editName', 'Full name', name), levelBox],
      confirmLabel: 'Save',
      send: () => apiPost('settings.php', { action: 'update-admin', id: admin.id, fullName: name.value.trim(), role: level.value })
    });
  }

  function resetPassword(admin) {
    const first = passwordInput('new-password');
    const second = passwordInput('new-password');
    ask({
      title: 'Set a new password for ' + admin.fullName,
      message: 'Use this when they have forgotten theirs. Anyone signed in with the old password is signed out. '
        + 'Tell them the new one in person, not by message.',
      parts: [
        field('resetFirst', 'New password', first, 'At least ' + passwordMin + ' characters.'),
        field('resetSecond', 'Repeat the new password', second),
        showToggle([first, second])
      ],
      confirmLabel: 'Set the new password',
      check: () => samePasswords(first, second),
      send: () => apiPost('settings.php', { action: 'reset-password', id: admin.id, password: first.value })
    });
  }

  function changeOwnPassword() {
    const current = passwordInput('current-password');
    const first = passwordInput('new-password');
    const second = passwordInput('new-password');
    ask({
      title: 'Change my password',
      message: 'You stay signed in here. Anywhere else you are signed in is signed out.',
      parts: [
        field('ownCurrent', 'Your current password', current),
        field('ownFirst', 'New password', first, 'At least ' + passwordMin + ' characters.'),
        field('ownSecond', 'Repeat the new password', second),
        showToggle([current, first, second])
      ],
      confirmLabel: 'Change my password',
      check: () => (current.value === '' ? 'Type your current password first.' : samePasswords(first, second)),
      send: () => apiPost('settings.php', { action: 'change-own-password', currentPassword: current.value, password: first.value })
    });
  }

  /* ------------------------------------------------------------- add one */

  const newPassword = document.getElementById('newPassword');
  const newPassword2 = document.getElementById('newPassword2');
  document.getElementById('showNew').addEventListener('change', e => {
    [newPassword, newPassword2].forEach(i => { i.type = e.target.checked ? 'text' : 'password'; });
  });


  document.getElementById('addForm').addEventListener('submit', async event => {
    event.preventDefault();
    const error = document.getElementById('addError');
    error.classList.remove('on');

    const problem = samePasswords(newPassword, newPassword2);
    if (problem) { error.textContent = problem; error.classList.add('on'); newPassword.focus(); return; }

    const addBtn = document.getElementById('addBtn');
    addBtn.disabled = true;
    addBtn.textContent = 'Adding…';
    const { data } = await apiPost('settings.php', {
      action: 'add-admin',
      fullName: document.getElementById('newName').value.trim(),
      username: document.getElementById('newUsername').value.trim(),
      role: roleSelect.value,
      password: newPassword.value
    });
    addBtn.disabled = false;
    addBtn.textContent = 'Add this staff';
    if (!data.ok) { error.textContent = data.error; error.classList.add('on'); return; }

    toast(data.message);
    event.target.reset();
    [newPassword, newPassword2].forEach(i => { i.type = 'password'; });
    roleHint.textContent = summaries[roleSelect.value] || '';
    load();
  });

  drawDrawer(drawer, null);
  load();
})();
