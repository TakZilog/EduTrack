// Script for users-access.html. Kept out of the page so the Content-Security-Policy
// can refuse inline scripts (see .htaccess).

(async function () {
  const s = await boot();
  if (!s) return;
  const rows = document.getElementById('adminRows');
  const drawer = document.getElementById('drawer');
  let openId = null;
  const roleLabels = { faculty: 'View Only', admin: 'Map Editor', super_admin: 'Administrator' };
  const rolePermissions = {
    faculty: 'Can view locations, routes, and walkthroughs.',
    admin: 'Can add and update locations, routes, and walkthroughs.',
    super_admin: 'Full access to EduTrack.'
  };

  async function load() {
    const { data } = await apiGet('settings.php');
    if (!data.ok) { toast(data.error || 'Could not load authorized users.', 'bad'); return; }
    rows.replaceChildren();
    data.admins.forEach(admin => {
      const lastLogin = document.createElement('span');
      lastLogin.className = 'row-when';
      lastLogin.textContent = 'Last login: ' + (admin.lastLogin ? relativeTime(admin.lastLogin) : 'Never');
      rows.appendChild(listRow({
        title: admin.fullName + (admin.isYou ? ' (you)' : ''),
        sub: admin.username,
        end: [pill(roleLabels[admin.role] || admin.roleLabel, 'off'), pill(admin.active ? 'Active' : 'Inactive', admin.active ? 'verified' : 'revoked'), lastLogin],
        selected: admin.id === openId,
        onOpen: row => { markOpenRow(rows, row); openUser(admin); }
      }));
    });
    openUser(data.admins.find(admin => admin.id === openId) || null);
  }

  function openUser(admin) {
    openId = admin ? admin.id : null;
    if (!admin) { drawDrawer(drawer, null); return; }
    const actions = [];
    if (!admin.isYou) {
      actions.push(button(
        admin.active ? 'Turn this account off' : 'Turn this account back on',
        admin.active ? 'btn-danger' : 'btn',
        async () => {
          const yes = await confirmAction({
            title: admin.active ? 'Turn this account off?' : 'Turn this account back on?',
            message: admin.active ? admin.fullName + ' will not be able to sign in to this panel.' : admin.fullName + ' will be able to sign in again.',
            confirmLabel: admin.active ? 'Yes, turn it off' : 'Yes, turn it on',
            danger: admin.active
          });
          if (!yes) return;
          const { data } = await apiPost('settings.php', { action: 'set-admin-active', id: admin.id, active: !admin.active });
          if (!data.ok) { toast(data.error, 'bad'); return; }
          toast(data.message); load();
        }
      ));
    }
    drawDrawer(drawer, {
      title: admin.fullName + (admin.isYou ? ' (you)' : ''),
      sub: admin.username,
      body: facts([
        ['Username', admin.username],
        ['Access level', roleLabels[admin.role] || admin.roleLabel],
        ['Status', admin.active ? pill('Active', 'verified') : pill('Inactive', 'revoked')],
        ['Last login', admin.lastLogin ? relativeTime(admin.lastLogin) : 'Never'],
        ['Permissions', rolePermissions[admin.role] || 'Managed by EduTrack.']
      ]),
      actions
    });
  }

  document.getElementById('createUserTop').addEventListener('click', () => {
    document.getElementById('newName').focus();
    document.getElementById('createUserPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
  document.getElementById('addForm').addEventListener('submit', async event => {
    event.preventDefault();
    const error = document.getElementById('addError');
    error.classList.remove('on');
    const button = document.getElementById('addBtn');
    button.disabled = true;
    button.textContent = 'Creating…';
    const { data } = await apiPost('settings.php', {
      action: 'add-admin',
      fullName: document.getElementById('newName').value.trim(),
      username: document.getElementById('newUsername').value.trim(),
      role: document.getElementById('newRole').value,
      password: document.getElementById('newPassword').value
    });
    button.disabled = false;
    button.textContent = 'Create User';
    if (!data.ok) { error.textContent = data.error; error.classList.add('on'); return; }
    toast(data.message); event.target.reset(); load();
  });
  drawDrawer(drawer, null);
  load();
})();
