/*
  EduTrack offline support.

  Keeps the student and visitor pages, the 360 viewer and the walkthrough
  photos on the phone, so a route that was opened or saved still works without
  signal. Inside the building the signal is often weak.

  What is kept, and how it is used:
    pages            newest from the network; the saved copy when offline
    styles, scripts  the saved copy at once, refreshed in the background
    room map         newest from the network; the saved copy when offline
    photos           a photo with a version in its address never changes, so
                     the saved copy is used; others refresh in the background

  Privacy:
    - Only the pages in PAGE_SECTIONS, the files in DATA_FILES and the viewer's
      libraries are ever handled. Every other request goes to the network
      untouched and is never stored.
    - When the map changes (a photo replaced, a room re-shot), older photos and
      room maps are deleted the next time the phone is online, so a picture that
      was taken down does not stay on phones.
    - Logging out deletes the saved room map and photos (forget() in
      assets/js/offline.js).
*/

const SHELL = 'edutrack-shell-v1';   // pages, styles, scripts, the 360 viewer
const DATA  = 'edutrack-data';       // room map and photos; deleted on logout

const BASE        = new URL('./', self.registration.scope).pathname;   // e.g. /EduTrack/
const VERSION_KEY = BASE + '__map-version';

// The first folder or file under the site: the student and visitor pages.
const PAGE_SECTIONS = new Set(['', 'index.html', 'auth', 'map']);
// The only data the walkthrough needs offline.
const DATA_FILES = { 'tour.php': 'tour', 'node-image.php': 'photo' };
// The 360 viewer and the page fonts.
const LIBRARY_HOSTS = new Set(['cdn.jsdelivr.net', 'fonts.googleapis.com', 'fonts.gstatic.com']);

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const names = await caches.keys();
    await Promise.all(names
      .filter(name => name.startsWith('edutrack-shell-') && name !== SHELL)
      .map(name => caches.delete(name)));
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const kind = classify(new URL(request.url));
  if (!kind) return;
  event.respondWith(fromStudentPage(event).then(yes => yes ? respond(kind, request) : fetch(request)));
});

/* Only what a student or visitor page loads is kept. A shared file (a style,
   a font) asked for by any other page on the site is fetched straight from
   the network and never stored. */
async function fromStudentPage(event) {
  if (event.request.mode === 'navigate') return true;   // the page itself; classify() checked it
  if (!event.clientId) return false;
  const client = await self.clients.get(event.clientId);
  return !!client && classify(new URL(client.url)) === 'page';
}

// A page asks for a list of addresses to be kept (the "Save for offline"
// buttons). Each one goes through the same rules as a normal visit, and the
// page hears back after every file so it can show progress.
self.addEventListener('message', event => {
  const { type, urls } = event.data || {};
  const port = event.ports[0];
  if (type === 'save' && Array.isArray(urls) && port) {
    event.waitUntil(saveAll(urls, port));
  }
});

/** What kind of request this is, or null for anything left to the network. */
function classify(url) {
  if (url.origin !== self.location.origin) {
    return LIBRARY_HOSTS.has(url.hostname) ? 'static' : null;
  }
  if (!url.pathname.startsWith(BASE)) return null;

  const rest    = url.pathname.slice(BASE.length);
  const parts   = rest.split('/');
  const section = parts[0].toLowerCase();

  if (section === 'api') return parts.length === 2 ? (DATA_FILES[parts[1]] || null) : null;
  if (section === 'assets') return 'static';
  if (PAGE_SECTIONS.has(section)) return /\.(js|css)$/i.test(rest) ? 'static' : 'page';
  return null;
}

function respond(kind, request) {
  switch (kind) {
    case 'page':  return networkFirst(SHELL, request, withoutQuery(request.url));
    case 'tour':  return roomMap(request);
    case 'photo': return photo(request);
    default:      return staleWhileRevalidate(SHELL, request);
  }
}

/* A page's address carries only what its script reads (?room=…), so one saved
   copy serves every variant. */
function withoutQuery(url) {
  const u = new URL(url);
  u.search = '';
  u.hash = '';
  return u.href;
}

async function networkFirst(cacheName, request, key) {
  const cache = await caches.open(cacheName);
  try {
    const response = await fetch(request);
    if (response.ok) await cache.put(key, response.clone());
    return response;
  } catch (err) {
    const saved = await cache.match(key);
    if (saved) return saved;
    throw err;
  }
}

async function staleWhileRevalidate(cacheName, request) {
  const cache = await caches.open(cacheName);
  const saved = await cache.match(request);
  const fresh = fetch(request).then(async response => {
    // Libraries from another site come back opaque; they are still worth keeping.
    if (response.ok || response.type === 'opaque') await cache.put(request, response.clone());
    return response;
  });
  if (saved) {
    fresh.catch(() => {});
    return saved;
  }
  return fresh;
}

async function roomMap(request) {
  const cache = await caches.open(DATA);
  try {
    const response = await fetch(request);
    if (response.ok) await keepRoomMap(cache, request.url, response.clone());
    return response;
  } catch (err) {
    // Offline: this room's saved map, or failing that the whole map a
    // signed-in student saved from the room list, which covers every room.
    const saved = await cache.match(request.url) || await cache.match(BASE + 'api/tour.php');
    if (saved) return saved;
    throw err;
  }
}

async function photo(request) {
  if (!new URL(request.url).searchParams.has('v')) {
    return staleWhileRevalidate(DATA, request);
  }
  // A versioned photo never changes under its address: use the saved copy.
  const cache = await caches.open(DATA);
  const saved = await cache.match(request.url);
  if (saved) return saved;
  const response = await fetch(request);
  if (response.ok) await cache.put(request.url, response.clone());
  return response;
}

/*
  Stores a room map, and when its version (the map's timestamp, sent by
  tour.php) differs from the last one seen, first deletes every photo from an
  older version and every other saved room map. A photo that was replaced,
  for example to take a student out of the picture, then leaves the phone.
*/
async function keepRoomMap(cache, url, response) {
  const version = response.headers.get('X-Map-Version');
  if (version) {
    const seen = await cache.match(VERSION_KEY);
    const previous = seen ? await seen.text() : null;
    if (previous !== null && previous !== version) {
      for (const saved of await cache.keys()) {
        const u = new URL(saved.url);
        const file = u.pathname.slice(BASE.length);
        const stale = file === 'api/tour.php'
          ? saved.url !== url
          : file === 'api/node-image.php' && u.searchParams.has('v') && u.searchParams.get('v') !== version;
        if (stale) await cache.delete(saved);
      }
    }
    if (previous !== version) await cache.put(VERSION_KEY, new Response(version));
  }
  await cache.put(url, response);
}

async function saveAll(urls, port) {
  let done = 0;
  let failed = 0;
  for (const raw of urls) {
    try {
      const url  = new URL(raw, self.location.href);
      const kind = classify(url);
      if (!kind) throw new Error('not kept offline');
      const sameSite = url.origin === self.location.origin;
      const response = await respond(kind, new Request(url.href, {
        credentials: 'same-origin',
        mode: sameSite ? 'same-origin' : 'no-cors',
      }));
      if (!response.ok && response.type !== 'opaque') failed++;
    } catch (err) {
      failed++;
    }
    port.postMessage({ done: ++done, total: urls.length, failed });
  }
}
