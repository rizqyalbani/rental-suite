const RENTAL_CACHE_VERSION = 'v12';
const RENTAL_STATIC_CACHE = `rental-outfit-static-${RENTAL_CACHE_VERSION}`;
const RENTAL_DATA_CACHE = `rental-outfit-data-${RENTAL_CACHE_VERSION}`;
const RENTAL_DEBUG_URL = '';
const RENTAL_DEBUG_SESSION = '';

function rentalDebug(hypothesisId, msg, data) {
    if (!RENTAL_DEBUG_URL) {
        return;
    }
    try {
        fetch(RENTAL_DEBUG_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                sessionId: RENTAL_DEBUG_SESSION,
                runId: 'post-fix',
                hypothesisId,
                location: 'public/sw.js',
                msg: '[DEBUG] ' + String(msg || ''),
                data: data || {},
                ts: Date.now(),
            }),
        }).catch(() => {});
    } catch {}
}

self.addEventListener('install', (event) => {
    // #region debug-point A:sw-install
    rentalDebug('A', 'sw install start', { cache: RENTAL_STATIC_CACHE });
    // #endregion
    event.waitUntil(
        caches.open(RENTAL_STATIC_CACHE).then((cache) =>
            cache.addAll([
                '/manifest.webmanifest',
                '/icons/icon-192.png',
                '/icons/icon-512.png',
                '/icons/apple-touch-icon.png',
            ]),
        ),
    );
    // #region debug-point A:sw-install-catch
    event.waitUntil((async () => {
        const urls = [
            '/manifest.webmanifest',
            '/icons/icon-192.png',
            '/icons/icon-512.png',
            '/icons/apple-touch-icon.png',
        ];
        try {
            const cache = await caches.open(RENTAL_STATIC_CACHE);
            await cache.addAll(urls);
            rentalDebug('A', 'sw install precache ok', { count: urls.length });
        } catch (e) {
            rentalDebug('A', 'sw install precache failed', { err: String(e && e.message ? e.message : e) });
            const diag = [];
            for (const u of urls) {
                try {
                    const r = await fetch(u, { cache: 'no-store', redirect: 'follow' });
                    diag.push({ url: u, ok: r.ok, status: r.status, redirected: r.redirected, finalUrl: r.url });
                } catch (fe) {
                    diag.push({ url: u, fetchError: String(fe && fe.message ? fe.message : fe) });
                }
            }
            rentalDebug('A', 'sw install precache diagnostics', { diag });
        }
    })());
    // #endregion
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    // #region debug-point C:sw-activate
    event.waitUntil((async () => {
        try {
            const keys = await caches.keys();
            rentalDebug('C', 'sw activate begin', { keys });
        } catch (e) {
            rentalDebug('C', 'sw activate keys failed', { err: String(e && e.message ? e.message : e) });
        }
    })());
    // #endregion
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(keys.map((k) => {
            if (k === RENTAL_STATIC_CACHE || k === RENTAL_DATA_CACHE) return Promise.resolve(false);
            if (!k.startsWith('rental-outfit-')) return Promise.resolve(false);
            return caches.delete(k);
        }))),
    );
    self.clients.claim();
});

function isCacheableRequest(request) {
    if (request.method !== 'GET') return false;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return false;
    if (url.pathname.startsWith('/logout')) return false;
    if (url.pathname.startsWith('/login')) return true;
    if (url.pathname.startsWith('/settings')) return false;
    return true;
}

function stripEphemeralParams(url) {
    const normalized = new URL(url.toString());
    if (normalized.searchParams.has('_')) {
        normalized.searchParams.delete('_');
    }
    return normalized;
}

function getDataCacheKey(url) {
    const normalized = stripEphemeralParams(url);
    return normalized.toString();
}

async function cacheFirst(request) {
    const cache = await caches.open(RENTAL_STATIC_CACHE);
    const cached = await cache.match(request);
    if (cached) return cached;
    const fresh = await fetch(request);
    if (fresh && fresh.ok) {
        cache.put(request, fresh.clone());
    }
    return fresh;
}

async function networkFirst(request) {
    const cache = await caches.open(RENTAL_STATIC_CACHE);
    try {
        const fresh = await fetch(request);
        if (fresh && fresh.ok) {
            cache.put(request, fresh.clone());
        }
        return fresh;
    } catch {
        const cached = await cache.match(request);
        if (cached) return cached;
        return cache.match('/');
    }
}

function offlineJsonFallbackForPath(pathname) {
    if (pathname.startsWith('/rentals/search')) {
        return {
            data: [],
            current_page: 1,
            from: 0,
            to: 0,
            total: 0,
            pagination_links_html: '',
            pending_approval_rows: [],
        };
    }
    if (pathname.startsWith('/outfits/available')) {
        return { outfits: [] };
    }
    return {};
}

async function staleWhileRevalidateData(event) {
    const request = event.request;
    // Respect explicit no-store — bypass SW cache entirely so fresh DB data is always returned
    if (request.cache === 'no-store' || (request.headers.get('cache-control') || '').includes('no-store')) {
        return fetch(request);
    }
    const url = new URL(request.url);
    const key = getDataCacheKey(url);
    const cache = await caches.open(RENTAL_DATA_CACHE);
    const cached = await cache.match(key);

    const fetchPromise = fetch(request)
        .then((fresh) => {
            if (fresh && fresh.ok) {
                cache.put(key, fresh.clone());
            }
            return fresh;
        })
        .catch(() => null);

    if (cached) {
        event.waitUntil(fetchPromise);
        return cached;
    }

    const fresh = await fetchPromise;
    if (fresh) return fresh;

    return new Response(JSON.stringify(offlineJsonFallbackForPath(url.pathname)), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
    });
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (!isCacheableRequest(request)) {
        return;
    }
    const dest = request.destination;
    const url = new URL(request.url);

    if (url.pathname.startsWith('/rentals/search') || url.pathname.startsWith('/outfits/available')) {
        event.respondWith(staleWhileRevalidateData(event));
        return;
    }
    if (dest === 'document' || request.headers.get('accept')?.includes('text/html')) {
        event.respondWith(networkFirst(request));
        return;
    }
    if (dest === 'script' || dest === 'style' || dest === 'font' || dest === 'image') {
        event.respondWith(cacheFirst(request));
        return;
    }
    event.respondWith(networkFirst(request));
});
