/*
  Offline support for the student and visitor pages. Registers sw.js (at the
  site root) and gives the pages these helpers:

    EduTrackOffline.save(urls, onProgress)  keep these files on the phone
    EduTrackOffline.isSaved(urls)           are they all kept already?
    EduTrackOffline.walkthroughFiles()      the walkthrough page and its files
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

  /* Read from the page itself, so a new script version is always the one saved. */
  async function walkthroughFiles() {
    const page = new URL('map/walkthrough.html', root);
    const html = await fetch(page, { credentials: 'same-origin' }).then(r => r.text());
    const doc  = new DOMParser().parseFromString(html, 'text/html');
    const refs = [...doc.querySelectorAll('script[src], link[rel="stylesheet"][href]')]
      .map(node => new URL(node.getAttribute('src') || node.getAttribute('href'), page).href);
    return [page.href, ...refs];
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

  window.EduTrackOffline = { supported, save, walkthroughFiles, isSaved, forget };
})();
