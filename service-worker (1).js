const CACHE_NAME = 'comanda-online-v25';

const APP_SHELL = [
    './',
    'index.html',
    'index-mobile.html',
    'nova-comanda-mobile.html',
    'pedidos-mobile.html',
    'caixa-mobile.html',
    'perfil-mobile.html',
    'produtos-mobile.html',
    'estoque-mobile.html',
    'relatorios-mobile.html',
    'funcionarios-mobile.html',
    'comanda.html',
    'comandas.html',
    'produtos.html',
    'perfil.html',
    'style.css',
    'mobile-first.css',
    'mobile-routing.js',
    'branding.js',
    'api.js',
    'toast.js',
    'storage.js',
    'cozinha.html',
    'cozinha-mobile.html',
    'menu-geral.js',
    'menu-admin.js',
    'mobile-navbar.js',
    'cozinha.js',
    'cozinha-mobile.js',
    'cozinha-shared.js',
    'mobile-navbar.js',
    'nova-comanda-mobile.js',
    'pedidos-mobile.js',
    'caixa-mobile.js',
    'perfil-mobile.js',
    'estoque-mobile.js',
    'pwa-register.js',
    'manifest.json',
    'favicon.ico',
    'icon-192.png',
    'icon-512.png',
    'html2canvas.min.js'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) =>
            // addAll falha se qualquer recurso der 404; cacheia individualmente para tolerar ausências
            Promise.allSettled(
                APP_SHELL.map((url) =>
                    cache.add(url).catch((err) => {
                        console.warn('[SW] Falha ao cachear:', url, err.message);
                    })
                )
            )
        )
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys
                .filter((key) => key !== CACHE_NAME)
                .map((key) => caches.delete(key))
        ))
    );
    self.clients.claim();
});

self.addEventListener('message', (event) => {
    const data = event.data || {};
    if (data && data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET') {
        return;
    }

    const isSameOrigin = url.origin === self.location.origin;

    if (url.pathname.endsWith('/manifest.json') || url.pathname.endsWith('manifest.json') || url.pathname.endsWith('/manifest.webmanifest') || url.pathname.endsWith('manifest.webmanifest') || url.pathname.endsWith('/app.webmanifest') || url.pathname.endsWith('app.webmanifest') || url.pathname.endsWith('/manifest.php') || url.pathname.endsWith('manifest.php')) {
        return;
    }

    // Não intercepta requisições cross-origin (CDNs, APIs externas, etc.)
    if (!isSameOrigin) {
        return;
    }

    const isPhpApiRequest = url.pathname.endsWith('.php');
    const isDynamicAppAsset = request.mode === 'navigate'
        || request.destination === 'document'
        || request.destination === 'script'
        || request.destination === 'style';

    const offlineFallback = (cached) =>
        cached || new Response('Offline', { status: 503, statusText: 'Service Unavailable' });

    // API responses must be fresh; use network-first to avoid stale kitchen/order states.
    if (isPhpApiRequest) {
        event.respondWith(
            fetch(request)
                .then((networkResponse) => networkResponse)
                .catch(() => caches.match(request).then(offlineFallback))
        );
        return;
    }

    if (isDynamicAppAsset) {
        event.respondWith(
            fetch(request)
                .then((networkResponse) => {
                    const copy = networkResponse.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
                    return networkResponse;
                })
                .catch(() => caches.match(request).then(offlineFallback))
        );
        return;
    }

    event.respondWith(
        caches.match(request).then((cachedResponse) => {
            if (cachedResponse) {
                return cachedResponse;
            }

            return fetch(request, { redirect: 'manual' })
                .then((networkResponse) => {
                    // opaqueredirect = InfinityFree redirecionou para domínio externo (erro 404)
                    // Não armazena nem segue — retorna 404 limpo
                    if (networkResponse.type === 'opaqueredirect' || !networkResponse.ok && networkResponse.type === 'opaque') {
                        return new Response('Not Found', { status: 404, statusText: 'Not Found' });
                    }
                    const copy = networkResponse.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
                    return networkResponse;
                })
                .catch(() => {
                    return new Response('Offline', { status: 503, statusText: 'Service Unavailable' });
                });
        })
    );
});
