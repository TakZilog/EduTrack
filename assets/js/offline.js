/*
  Offline support for the student and visitor pages. Registers sw.js (at the
  site root) and gives the pages these helpers:

    EduTrackOffline.save(urls, onProgress)  keep these files on the phone
    EduTrackOffline.isSaved(urls)           are they all kept already?
    EduTrackOffline.walkthroughFiles()      the walkthrough page and its files
    EduTrackOffline.downloadControl({...})  a "Download all photos" control:
                                            every photo, in one tap
    EduTrackOffline.forget()                delete the saved room map and photos

  Offline mode is for the Android app only: the app is a WebView that sends
  the EduTrackMobile user agent. In a normal browser `supported` is false, the
  Save buttons stay hidden, nothing is stored, and the site works online as it
  always has. Service workers also need a secure address (https, or localhost
  while developing).
*/
(function () {
  const root = new URL('../../', document.currentScript.src);   // the site's base
  const DATA = 'edutrack-data';                                  // must match sw.js

  const inApp = /EduTrackMobile/i.test(navigator.userAgent);
  const supported = inApp && 'serviceWorker' in navigator && window.isSecureContext;

  if (supported) {
    navigator.serviceWorker.register(new URL('sw.js', root), { scope: root.pathname })
      .catch(() => { /* no offline mode; everything still works online */ });
  } else {
    // A browser that registered the worker before offline mode became
    // app-only: remove it and whatever it saved.
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.getRegistrations()
        .then(regs => regs.filter(r => r.scope === root.href).forEach(r => r.unregister()))
        .catch(() => {});
    }
    if ('caches' in window) {
      caches.keys()
        .then(names => names.filter(n => n.startsWith('edutrack-')).forEach(n => caches.delete(n)))
        .catch(() => {});
    }
  }

  /* Resolves with { done, total, failed } once every file has been tried. */
  async function save(urls, onProgress) {
    if (!supported) throw new Error('Offline saving is not available here.');
    const registration = await Promise.race([
      navigator.serviceWorker.ready,
      new Promise((_, reject) => setTimeout(() => reject(new Error('Offline saving is not ready yet.')), 10000)),
    ]);
    const worker = registration.active;
    if (!worker) throw new Error('Offline saving is not ready yet.');
    if (!urls.length) return { done: 0, total: 0, failed: 0 };

    return new Promise(resolve => {
      const channel = new MessageChannel();
      channel.port1.onmessage = event => {
        if (onProgress) onProgress(event.data);
        if (event.data.done === event.data.total) resolve(event.data);
      };
      const absolute = [...new Set(urls.map(u => new URL(u, location.href).href))];
      worker.postMessage({ type: 'save', urls: absolute }, [channel.port2]);
    });
  }

  /* A page and every script and style it loads, read from the page itself so
     a new script version is always the one saved. */
  async function pageFiles(path) {
    const page = new URL(path, root);
    const html = await fetch(page, { credentials: 'same-origin' }).then(r => r.text());
    const doc  = new DOMParser().parseFromString(html, 'text/html');
    const refs = [...doc.querySelectorAll('script[src], link[rel="stylesheet"][href]')]
      .map(node => new URL(node.getAttribute('src') || node.getAttribute('href'), page).href);
    return [page.href, ...refs];
  }

  function walkthroughFiles() {
    return pageFiles('map/walkthrough.html');
  }

  /* ---------------------------------------------------- download all photos */

  const QUALITY_KEY = 'edutrack.walkQuality';   // the key walkthrough.js keeps the choice under
  const PHOTO_KB = { low: 62, fast: 468 };      // average photo size, measured over the whole map

  /* The quality a walk opens with in the app: the visitor's own choice, else
     the lighter photos a phone opens with. */
  function preferredQuality() {
    try {
      const saved = localStorage.getItem(QUALITY_KEY);
      if (saved === 'low' || saved === 'fast') return saved;
    } catch (err) { /* private mode */ }
    return 'low';
  }

  /* Every photo this visitor may walk through, at one quality, and the room
     maps that name them. A signed-in student gets the whole building; anyone
     else the rooms the enrollment guide names, which are open to everyone.
     The addresses match the ones walkthrough.js asks for, photo for photo. */
  async function allPhotos(quality) {
    const api = new URL('api/', root).href;
    let version, files, maps;

    const tour = await fetch(api + 'tour.php', { credentials: 'same-origin' });
    if (tour.ok) {
      version = tour.headers.get('X-Map-Version') || '';
      files = [...new Set((await tour.json()).nodes.map(node => node.image_file))];
      maps = [api + 'tour.php'];
    } else {
      const res = await fetch(api + 'enrollment-guide.php');
      if (!res.ok) throw new Error('The photo list is not available.');
      const guide = await res.json();
      version = String(guide.version);
      files = guide.images;
      maps = guide.rooms.map(room => api + 'tour.php?room=' + encodeURIComponent(room));
    }

    const q = quality === 'low' ? '&q=low' : '';
    const v = version ? '&v=' + encodeURIComponent(version) : '';
    return { maps, photos: files.map(file => api + 'node-image.php?f=' + file + q + v) };
  }

  /* How many of those photos are already on the phone, and about how much
     the rest weighs. */
  async function photoStatus(quality) {
    const { photos } = await allPhotos(quality);
    let saved = 0;
    for (const url of photos) {
      if (await caches.match(url)) saved++;
    }
    const kb = PHOTO_KB[quality === 'low' ? 'low' : 'fast'];
    return {
      saved,
      total: photos.length,
      missingMb: Math.max(1, Math.round((photos.length - saved) * kb / 1024)),
    };
  }

  /* The whole walk on the phone: the walkthrough and room list pages, the 360
     viewer, the room maps and every photo at this quality. Photos already
     saved are not downloaded again, so running it again only fetches what is
     new or missing. */
  async function downloadAll(quality, onProgress) {
    const { maps, photos } = await allPhotos(quality);
    const pages = [...await walkthroughFiles(), ...await pageFiles('map/select-room.html')];
    return save([...pages, ...maps, ...photos], onProgress);
  }

  /*
    Wires a "Download all photos" control, the same on every page that has
    one: a button, a progress bar (an element holding one span), a status
    line, and optionally a "Remove" button. The page words the button to fit
    its space; the button hides once every photo is on the phone, and comes
    back when the map gets new ones. getQuality() names the quality to
    download at; onChange(all) hears whether every photo is now on the phone.
    Returns { refresh }, to check again, for example after the quality changes.
  */
  function downloadControl({ button, bar, status, remove, getQuality, onChange }) {
    let busy = false;
    let run = 0;

    async function refresh() {
      if (busy) return;
      const mine = ++run;
      try {
        const s = await photoStatus(getQuality());
        if (mine !== run || busy) return;          // a newer check, or a download started
        const all = s.total > 0 && s.saved === s.total;
        button.hidden = all;
        status.textContent = all
          ? `All ${s.total} photos are on this phone.`
          : s.saved
            ? `${s.saved} of ${s.total} photos on this phone. The rest is about ${s.missingMb} MB.`
            : `${s.total} photos, about ${s.missingMb} MB.`;
        if (remove) remove.hidden = s.saved === 0;
        if (onChange) onChange(all);
      } catch (err) {
        // Offline with nothing saved yet, or the list is unavailable: say nothing.
        if (mine === run && !busy) status.textContent = '';
      }
    }

    button.addEventListener('click', async () => {
      if (busy) return;
      busy = true;
      button.disabled = true;
      bar.hidden = false;
      const fill = bar.firstElementChild;
      fill.style.width = '0';
      status.textContent = 'Starting…';
      let failed = 0;
      let error = false;
      try {
        const result = await downloadAll(getQuality(), p => {
          fill.style.width = `${(p.done / p.total) * 100}%`;
          status.textContent = `Downloading… ${p.done} of ${p.total}`;
        });
        failed = result.failed;
      } catch (err) {
        error = true;
      }
      busy = false;
      button.disabled = false;
      bar.hidden = true;
      await refresh();
      if (error) status.textContent = 'Could not download. Check your connection and try again.';
      else if (failed) status.textContent = `${failed} files did not download. Try again with a better signal.`;
    });

    if (remove) {
      remove.addEventListener('click', async () => {
        await forget();
        refresh();
      });
    }

    refresh();
    return { refresh };
  }

  /* True when every one of these is already kept on the phone, so a page can
     say "Saved" on a later visit instead of offering to save again. */
  async function isSaved(urls) {
    if (!supported || !('caches' in window) || !urls.length) return false;
    try {
      for (const u of urls) {
        if (!await caches.match(new URL(u, location.href).href)) return false;
      }
      return true;
    } catch (err) {
      return false;
    }
  }

  /* On logout: the room map and photos go, so the next person on this phone
     cannot open the student tour from what was saved. */
  async function forget() {
    if (!('caches' in window)) return;
    try { await caches.delete(DATA); } catch (err) { /* nothing was saved */ }
  }

  window.EduTrackOffline = {
    supported, save, isSaved, walkthroughFiles, forget,
    preferredQuality, photoStatus, downloadAll, downloadControl,
  };
})();
