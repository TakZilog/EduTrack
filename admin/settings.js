// Script for settings.html. Kept out of the page so the Content-Security-Policy
// can refuse inline scripts (see .htaccess).

(async function () {
  const s = await boot();
  if (!s) return;

  const settingsFields = document.getElementById('settingsFields');
  const studentVerificationFields = document.getElementById('studentVerificationFields');
  const accessMode = document.getElementById('accessMode');
  const approvedAddressesField = document.getElementById('approvedAddressesField');
  const settingCopy = {
    otp_lifetime_minutes: {
      label: 'Email verification code duration',
      hint: 'How long an email verification code remains valid.'
    },
    login_max_attempts: {
      label: 'Wrong password attempts',
      hint: 'Number of failed attempts allowed before lockout.'
    },
    login_lockout_minutes: {
      label: 'Lockout duration',
      hint: 'How long a user remains locked out after exceeding failed attempts.'
    },
    session_timeout_minutes: {
      label: 'Session timeout',
      hint: 'Automatically sign out inactive administrators.'
    }
  };

  async function load() {
    const { data } = await apiGet('settings.php');
    if (!data.ok) { toast(data.error || 'Could not load settings.', 'bad'); return; }

    settingsFields.replaceChildren();
    data.settings.forEach(setting => {
      const field = document.createElement('div');
      field.className = 'field';
      const copy = settingCopy[setting.key] || { label: setting.label, hint: '' };

      const label = document.createElement('label');
      label.setAttribute('for', 'set-' + setting.key);
      label.textContent = copy.label;

      const input = setting.key === 'session_timeout_minutes'
        ? document.createElement('select')
        : document.createElement('input');
      input.id = 'set-' + setting.key;
      input.dataset.key = setting.key;
      if (setting.key === 'session_timeout_minutes') {
        [15, 30, 60, 120].forEach(minutes => {
          const option = document.createElement('option');
          option.value = minutes;
          option.textContent = minutes + ' minutes';
          input.appendChild(option);
        });
      } else {
        input.type = 'number';
        input.min = setting.min;
        input.max = setting.max;
      }
      input.value = setting.value;

      const hint = document.createElement('p');
      hint.className = 'field-hint';
      hint.textContent = copy.hint || `Between ${setting.min} and ${setting.max} ${setting.unit}.`;

      field.append(label, input, hint);
      settingsFields.appendChild(field);
    });

    studentVerificationFields.replaceChildren();
    (data.studentVerification || []).forEach(item => {
      const field = document.createElement('div');
      field.className = 'field';

      const label = document.createElement('label');
      label.setAttribute('for', 'student-setting-' + item.key);
      label.textContent = item.label;

      const select = document.createElement('select');
      select.id = 'student-setting-' + item.key;
      select.dataset.key = item.key;
      select.innerHTML = '<option value="1">Enabled</option><option value="0">Disabled</option>';
      select.value = item.enabled ? '1' : '0';

      field.append(label, select);
      studentVerificationFields.appendChild(field);
    });

    drawAccess(data.access);
  }

  let yourIp = '';

  function drawAccess(access) {
    yourIp = access.yourIp;
    document.getElementById('allowlist').value = access.allowlist.join('\n');
    accessMode.value = access.enabled ? 'approved' : 'all';
    approvedAddressesField.hidden = !access.enabled;
    document.getElementById('addMine').hidden = !access.enabled;

    const host = document.getElementById('accessState');
    host.replaceChildren();

    const notice = document.createElement('div');
    notice.className = 'notice ' + (access.enabled ? 'notice-good' : 'notice-warning');

    const t = document.createElement('p');
    t.className = 't';
    t.textContent = access.enabled
      ? 'Limited to ' + access.allowlist.length + ' address(es)'
      : 'Any computer on the network can open this panel';

    const d = document.createElement('p');
    d.className = 'd';
    d.textContent = access.enabled
      ? 'You are on ' + access.yourIp + '.'
      : 'This panel is served without encryption, so anyone on the same network can reach the sign-in page. '
        + 'You are on ' + access.yourIp + '.';

    notice.append(t, d);
    host.appendChild(notice);
  }

  accessMode.addEventListener('change', () => {
    approvedAddressesField.hidden = accessMode.value !== 'approved';
    document.getElementById('addMine').hidden = accessMode.value !== 'approved';
  });

  document.getElementById('addMine').addEventListener('click', () => {
    const field = document.getElementById('allowlist');
    const lines = field.value.split('\n').map(l => l.trim()).filter(Boolean);
    if (lines.includes(yourIp)) {
      toast('This computer is already on the list.');
      return;
    }
    lines.push(yourIp);
    field.value = lines.join('\n');
    field.focus();
  });

  document.getElementById('accessForm').addEventListener('submit', async e => {
    e.preventDefault();
    const error = document.getElementById('accessError');
    error.classList.remove('on');

    const value = accessMode.value === 'approved'
      ? document.getElementById('allowlist').value.trim()
      : '';

    // Turning the restriction on is worth a second look: get it wrong and the
    // only way back is the server itself.
    if (value !== '') {
      const yes = await confirmAction({
        title: 'Limit the panel to these computers?',
        message: 'Anyone on a computer not in this list will not be able to open the staff panel, '
          + 'including other staff. You are on ' + yourIp + '. The server itself can always get in.',
        confirmLabel: 'Yes, limit it'
      });
      if (!yes) return;
    }

    const btn = document.getElementById('accessSave');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    const { data } = await apiPost('settings.php', { action: 'save-ip-allowlist', allowlist: value });

    btn.disabled = false;
    btn.textContent = 'Save Restrictions';

    if (!data.ok) {
      error.textContent = data.error;
      error.classList.add('on');
      return;
    }
    toast(data.message);
    load();
  });

  document.getElementById('settingsForm').addEventListener('submit', async e => {
    e.preventDefault();
    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    const payload = { action: 'save-settings' };
    settingsFields.querySelectorAll('[data-key]').forEach(input => {
      payload[input.dataset.key] = Number(input.value);
    });

    const { data } = await apiPost('settings.php', payload);
    btn.disabled = false;
    btn.textContent = 'Save Security Settings';

    if (!data.ok) { toast(data.error, 'bad'); return; }
    toast(data.message);
  });

  document.getElementById('studentVerificationForm').addEventListener('submit', async e => {
    e.preventDefault();
    const btn = document.getElementById('saveStudentVerificationBtn');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    const payload = { action: 'save-student-verification-settings' };
    studentVerificationFields.querySelectorAll('[data-key]').forEach(select => {
      payload[select.dataset.key] = select.value;
    });

    const { data } = await apiPost('settings.php', payload);
    btn.disabled = false;
    btn.textContent = 'Save Verification Settings';

    if (!data.ok) { toast(data.error, 'bad'); return; }
    toast(data.message);
  });

  load();
})();
