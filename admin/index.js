// Script for index.html. Kept out of the page so the Content-Security-Policy
// can refuse inline scripts (see .htaccess).

(async function () {
  const s = await boot();
  if (!s) return;

  const { data } = await apiGet('overview.php');
  if (!data.ok) {
    toast(data.error || 'Could not load the overview.', 'bad');
    return;
  }

  /* Each figure links to the list it summarises, with the filter already
     applied, so a number is something you can act on rather than just read. */
  const counts = [
    { n: data.rooms.total,      l: 'Mapped Locations',     href: 'walkthrough.html', tone: '' },
    { n: data.rooms.photos,     l: 'Walkthrough Photos',   href: 'walkthrough.html', tone: '' },
    { n: data.rooms.reachable,  l: 'Reachable Locations',  href: 'walkthrough.html', tone: '' },
    { n: data.rooms.problems,   l: 'Navigation Issues',    href: 'walkthrough.html', tone: data.rooms.problems > 0 ? 'is-bad' : '' }
  ];

  const host = document.getElementById('counts');
  counts.forEach(c => {
    const a = document.createElement('a');
    a.className = 'count-card';
    a.href = c.href;

    const n = document.createElement('span');
    n.className = 'count-n' + (c.tone ? ' ' + c.tone : '');
    n.textContent = c.n;

    const l = document.createElement('span');
    l.className = 'count-l';
    l.textContent = c.l;

    a.append(n, l);
    host.appendChild(a);
  });

  /* Map problems */
  const problems = document.getElementById('problems');
  if (data.problems.length === 0) {
    problems.appendChild(notice('good', '✓ ' + data.rooms.reachable + ' locations have valid routes',
      'All mapped locations currently have valid routes.'));
  } else {
    data.problems.forEach(p => {
      problems.appendChild(notice(p.severity === 'error' ? 'error' : 'warning', p.title, p.detail));
    });
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

  /* Recent activity, only for the roles that may read the log */
  if (data.recent && data.recent.length) {
    document.getElementById('activityPanel').hidden = false;
    const list = document.getElementById('recent');

    data.recent.forEach(r => {
      const li = document.createElement('li');
      const row = document.createElement('div');
      row.className = 'row';
      row.style.cursor = 'default';

      const main = document.createElement('div');
      main.className = 'row-main';
      const title = document.createElement('span');
      title.className = 'row-title';
      title.textContent = r.detail || r.action;
      const sub = document.createElement('span');
      sub.className = 'row-sub';
      sub.textContent = r.admin_name;
      main.append(title, sub);

      const end = document.createElement('div');
      end.className = 'row-end';
      const when = document.createElement('span');
      when.className = 'row-when';
      when.textContent = relativeTime(r.created_at);
      end.appendChild(when);

      row.append(main, end);
      li.appendChild(row);
      list.appendChild(li);
    });
  }
})();
