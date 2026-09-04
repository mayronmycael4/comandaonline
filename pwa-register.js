(function () {
    const DISMISS_KEY = 'pwa_install_dismissed_v1';
    const IOS_DISMISS_KEY = 'pwa_ios_install_dismissed_v1';
    let deferredPrompt = null;
    let installUi = null;

    function isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    }

    function isIos() {
        return /iphone|ipad|ipod/i.test(window.navigator.userAgent);
    }

    function isSafari() {
        const ua = window.navigator.userAgent;
        return /safari/i.test(ua) && !/chrome|android|crios|fxios|edgios/i.test(ua);
    }

    function isAndroid() {
        return /android/i.test(window.navigator.userAgent);
    }

    function shouldShowIosGuide() {
        return isIos() && isSafari() && !isStandalone() && localStorage.getItem(IOS_DISMISS_KEY) !== '1';
    }

    function shouldShowInstallPrompt() {
        return !isStandalone() && localStorage.getItem(DISMISS_KEY) !== '1';
    }

    function ensureMetaTags() {
        const head = document.head;
        if (!head) return;

        if (!head.querySelector('link[rel="manifest"]')) {
            const manifest = document.createElement('link');
            manifest.rel = 'manifest';
            manifest.href = 'manifest.php';
            head.appendChild(manifest);
        }

        if (!head.querySelector('meta[name="mobile-web-app-capable"]')) {
            const meta = document.createElement('meta');
            meta.name = 'mobile-web-app-capable';
            meta.content = 'yes';
            head.appendChild(meta);
        }

        if (!head.querySelector('meta[name="apple-mobile-web-app-capable"]')) {
            const meta = document.createElement('meta');
            meta.name = 'apple-mobile-web-app-capable';
            meta.content = 'yes';
            head.appendChild(meta);
        }

        if (!head.querySelector('meta[name="apple-mobile-web-app-title"]')) {
            const meta = document.createElement('meta');
            meta.name = 'apple-mobile-web-app-title';
            meta.content = 'Comandas';
            head.appendChild(meta);
        }

        if (!head.querySelector('link[rel="apple-touch-icon"]')) {
            const link = document.createElement('link');
            link.rel = 'apple-touch-icon';
            link.href = 'icon-192.png';
            head.appendChild(link);
        }
    }

    function removeInstallUi() {
        if (installUi && installUi.parentNode) {
            installUi.parentNode.removeChild(installUi);
        }
        installUi = null;
    }

    function buildInstallUi(kind) {
        removeInstallUi();

        const box = document.createElement('aside');
        box.className = 'pwa-install-banner';
        box.innerHTML = kind === 'ios'
            ? `
                <div class="pwa-install-copy">
                    <strong>Instalar aplicativo</strong>
                    <span>No iPhone, toque em Compartilhar e depois em Adicionar a Tela de Inicio.</span>
                </div>
                <div class="pwa-install-actions">
                    <button type="button" class="btn btn-secondary pwa-install-dismiss">Agora nao</button>
                </div>
            `
            : `
                <div class="pwa-install-copy">
                    <strong>Instalar aplicativo</strong>
                    <span>Adicione o sistema a tela inicial e abra em tela cheia, como um app.</span>
                </div>
                <div class="pwa-install-actions">
                    <button type="button" class="btn btn-primary pwa-install-trigger">Instalar</button>
                    <button type="button" class="btn btn-secondary pwa-install-dismiss">Agora nao</button>
                </div>
            `;

        document.body.appendChild(box);
        installUi = box;

        const dismiss = box.querySelector('.pwa-install-dismiss');
        if (dismiss) {
            dismiss.addEventListener('click', () => {
                localStorage.setItem(kind === 'ios' ? IOS_DISMISS_KEY : DISMISS_KEY, '1');
                removeInstallUi();
            });
        }

        const trigger = box.querySelector('.pwa-install-trigger');
        if (trigger) {
            trigger.addEventListener('click', async () => {
                if (!deferredPrompt) return;
                deferredPrompt.prompt();
                const choice = await deferredPrompt.userChoice;
                if (choice && choice.outcome !== 'accepted') {
                    localStorage.setItem(DISMISS_KEY, '1');
                }
                deferredPrompt = null;
                removeInstallUi();
            });
        }
    }

    function maybeRenderInstallUi() {
        if (isStandalone()) {
            removeInstallUi();
            return;
        }

        if (deferredPrompt && shouldShowInstallPrompt() && isAndroid()) {
            buildInstallUi('android');
            return;
        }

        if (shouldShowIosGuide()) {
            buildInstallUi('ios');
            return;
        }

        removeInstallUi();
    }

    function bindInstallEvents() {
        window.addEventListener('beforeinstallprompt', (event) => {
            event.preventDefault();
            deferredPrompt = event;
            localStorage.removeItem(DISMISS_KEY);
            maybeRenderInstallUi();
        });

        window.addEventListener('appinstalled', () => {
            deferredPrompt = null;
            localStorage.removeItem(DISMISS_KEY);
            localStorage.removeItem(IOS_DISMISS_KEY);
            removeInstallUi();
            if (typeof Toast !== 'undefined' && Toast && typeof Toast.success === 'function') {
                Toast.success('Aplicativo instalado com sucesso!');
            }
        });
    }

    function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) {
            return;
        }

        if (!(window.isSecureContext || window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1')) {
            return;
        }

        window.addEventListener('load', async () => {
            let probe;
            try {
                probe = await fetch('service-worker.js', { method: 'HEAD', cache: 'no-store' });
                if (!probe.ok) return;
            } catch (_e) {
                return;
            }

                // Rejeita se o servidor servir o arquivo com MIME errado (ex: HTML).
                // Isso evita SecurityError no registro do SW em hospedagens compartilhadas.
                const ct = probe.headers.get('content-type') || '';
                if (!ct.includes('javascript') && !ct.includes('ecmascript')) return;

            navigator.serviceWorker.register('service-worker.js', { scope: './' })
                .then((registration) => {
                    registration.update();

                    if (registration.waiting) {
                        registration.waiting.postMessage({ type: 'SKIP_WAITING' });
                    }

                    registration.addEventListener('updatefound', () => {
                        const worker = registration.installing;
                        if (!worker) return;

                        worker.addEventListener('statechange', () => {
                            if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                                worker.postMessage({ type: 'SKIP_WAITING' });
                            }
                        });
                    });

                    navigator.serviceWorker.addEventListener('controllerchange', () => {
                        if (sessionStorage.getItem('sw_reloaded') === '1') return;
                        sessionStorage.setItem('sw_reloaded', '1');
                        window.location.reload();
                    });
                })
                .catch(() => {
                    // Ignore registration errors silently to avoid UI noise.
                });
        });
    }

    ensureMetaTags();
    bindInstallEvents();
    registerServiceWorker();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', maybeRenderInstallUi);
    } else {
        maybeRenderInstallUi();
    }
})();
