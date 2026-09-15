const APP_BUILD_KEY = 'comanda_app_build';
const APP_BUILD_VERSION = '20260417-login-refresh-notice';
const SETUP_STATUS_TIMEOUT_MS = 6000;

document.addEventListener('DOMContentLoaded', async () => {
    bindLoginForms();
    dispararBackupSemanalEmSegundoPlano();

    const clientDataRefreshed = await refreshClientDataIfAppUpdated();
    toggleSystemSyncNotice(clientDataRefreshed);

    const setupStatus = await getSetupStatus();

    // Carrega dados apenas para exibir contexto no login, nao para decidir setup.
    let empresa = null;
    
    try {
        empresa = await Storage.getEmpresa();
    } catch (e) {
        console.log('Erro ao carregar dados:', e);
    }
    
    if (setupStatus.allow_setup) {
        // Primeiro acesso real - setup permitido apenas quando schema nao esta pronto.
        document.getElementById('formLogin').style.display = 'none';
        document.getElementById('setupBox').style.display = 'block';
        document.getElementById('setupBox').className = '';
    } else {
        // Login normal
        if (empresa && empresa.nome) {
            document.getElementById('empresaDisplay').style.display = 'block';
            document.getElementById('nomeEmpresa').textContent = empresa.nome;
        }
    }
    
});

function bindLoginForms() {
    const formSetup = document.getElementById('formSetup');
    const formLogin = document.getElementById('formLogin');

    if (formSetup) {
        formSetup.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const nomeEmpresa = document.getElementById('nomeEmpresaSetup').value.trim();
        const nomeAdmin = document.getElementById('nomeAdmin').value.trim();
        const loginAdmin = document.getElementById('loginAdmin').value.trim();
        const senhaAdmin = document.getElementById('senhaAdmin').value;
        
        try {
            const setupGate = await getSetupStatus();
            if (!setupGate.allow_setup) {
                throw new Error('Configuracao inicial bloqueada: sistema ja inicializado.');
            }

            const installResponse = await fetch('install.php');
            const installResult = await installResponse.json();
            if (!installResponse.ok || !installResult.success) {
                throw new Error(installResult.error || 'Falha ao preparar banco de dados');
            }

            await Storage.saveEmpresa({ nome: nomeEmpresa });
            
            await Storage.addFuncionario({
                nome: nomeAdmin,
                login: loginAdmin,
                senha: senhaAdmin,
                is_admin: true
            });

            try {
                const loginResult = await API.login(loginAdmin, senhaAdmin);
                Storage.setSession(loginResult.funcionario);
            } catch (loginError) {
                Toast.warning('Administrador atualizado. Faça login com as novas credenciais.');
                document.getElementById('setupBox').style.display = 'none';
                document.getElementById('formLogin').style.display = 'block';
                document.getElementById('login').value = loginAdmin;
                document.getElementById('senha').value = senhaAdmin;
                return;
            }
            Toast.success('Configuração concluída! Bem-vindo, ' + nomeAdmin);
            
            setTimeout(() => {
                if (window.MobileRouting) {
                    window.MobileRouting.redirectAfterLogin();
                    return;
                }
                window.location.href = 'index.html';
            }, 1500);
        } catch (error) {
            Toast.error('Erro ao configurar: ' + error.message);
        }
    });
    }
    
    if (formLogin) {
        formLogin.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const login = document.getElementById('login').value.trim();
        const senha = document.getElementById('senha').value;
        
        try {
            const result = await API.login(login, senha);
            
            if (result.success) {
                Storage.setSession(result.funcionario);
                Toast.success('Bem-vindo, ' + result.funcionario.nome);
                
                setTimeout(() => {
                    if (window.MobileRouting) {
                        window.MobileRouting.redirectAfterLogin();
                        return;
                    }
                    window.location.href = 'index.html';
                }, 1000);
            } else {
                Toast.error('Login ou senha incorretos');
            }
        } catch (error) {
            Toast.error('Erro ao fazer login: ' + error.message);
        }
    });
    }
}

function dispararBackupSemanalEmSegundoPlano() {
    const controller = new AbortController();
    const timeoutId = window.setTimeout(() => controller.abort(), 3000);

    fetch('backup_auto.php', {
        method: 'GET',
        headers: { 'Accept': 'application/json' },
        signal: controller.signal
    })
        .then(() => {})
        .catch(() => {})
        .finally(() => {
            window.clearTimeout(timeoutId);
        });
}

async function refreshClientDataIfAppUpdated() {
    const currentBuild = localStorage.getItem(APP_BUILD_KEY);

    if (currentBuild === APP_BUILD_VERSION) {
        return false;
    }

    const preservedEntries = preserveImportantLocalSettings();

    try {
        Storage.clearSession();
    } catch (error) {
        console.warn('Falha ao limpar sessao antiga:', error);
    }

    try {
        localStorage.clear();
        sessionStorage.clear();
    } catch (error) {
        console.warn('Falha ao limpar storage local:', error);
    }

    restoreImportantLocalSettings(preservedEntries);
    localStorage.setItem(APP_BUILD_KEY, APP_BUILD_VERSION);

    await Promise.allSettled([
        clearSiteCookies(),
        clearCacheStorage(),
        refreshServiceWorkers()
    ]);

    return true;
}

function toggleSystemSyncNotice(isVisible) {
    const notice = document.getElementById('systemSyncNotice');
    if (!notice) {
        return;
    }

    if (!isVisible) {
        notice.classList.remove('is-visible', 'is-hiding');
        return;
    }

    notice.classList.remove('is-hiding');
    notice.classList.add('is-visible');

    window.setTimeout(() => {
        notice.classList.add('is-hiding');

        window.setTimeout(() => {
            notice.classList.remove('is-visible', 'is-hiding');
        }, 380);
    }, 4200);
}

function preserveImportantLocalSettings() {
    const keysToKeep = ['espetaria_theme'];
    return keysToKeep
        .map((key) => [key, localStorage.getItem(key)])
        .filter(([, value]) => value !== null);
}

function restoreImportantLocalSettings(entries) {
    entries.forEach(([key, value]) => {
        localStorage.setItem(key, value);
    });
}

async function clearCacheStorage() {
    if (!('caches' in window)) {
        return;
    }

    const cacheKeys = await caches.keys();
    await Promise.all(cacheKeys.map((cacheKey) => caches.delete(cacheKey)));
}

async function refreshServiceWorkers() {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    const registrations = await navigator.serviceWorker.getRegistrations();
    await Promise.all(registrations.map(async (registration) => {
        await registration.update();

        if (registration.waiting) {
            registration.waiting.postMessage({ type: 'SKIP_WAITING' });
        }
    }));
}

async function clearSiteCookies() {
    const cookies = document.cookie ? document.cookie.split(';') : [];
    if (cookies.length === 0) {
        return;
    }

    const hostname = window.location.hostname;
    const domainParts = hostname.split('.');
    const domainVariants = [''];

    for (let index = 0; index < domainParts.length; index += 1) {
        const domain = domainParts.slice(index).join('.');
        domainVariants.push(domain, `.${domain}`);
    }

    const pathParts = window.location.pathname.split('/').filter(Boolean);
    const paths = ['/'];
    let currentPath = '';

    pathParts.forEach((part) => {
        currentPath += `/${part}`;
        paths.push(`${currentPath}/`);
    });

    cookies.forEach((cookieEntry) => {
        const separatorIndex = cookieEntry.indexOf('=');
        const cookieName = separatorIndex === -1 ? cookieEntry.trim() : cookieEntry.slice(0, separatorIndex).trim();

        paths.forEach((path) => {
            domainVariants.forEach((domain) => {
                const domainAttribute = domain ? `;domain=${domain}` : '';
                document.cookie = `${cookieName}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=${path}${domainAttribute}`;
            });
        });
    });
}

async function getSetupStatus(attempt = 0) {
    const controller = new AbortController();
    const timeoutId = window.setTimeout(() => controller.abort(), SETUP_STATUS_TIMEOUT_MS);

    try {
        const response = await fetch('setup_status.php', {
            headers: {
                'Accept': 'application/json'
            },
            signal: controller.signal,
            credentials: 'same-origin',
            cache: 'no-store'
        });

        const text = await response.text();
        let data = null;

        try {
            data = JSON.parse(text);
        } catch (_e) {
            const sample = String(text || '').toLowerCase();
            if (sample.includes('/aes.js') || sample.includes('function tonumbers(')) {
                console.warn('setup_status.php bloqueado por protecao da hospedagem (aes.js challenge).');
                if (attempt < 1 && window.API && typeof window.API.warmupHostingChallenge === 'function') {
                    await window.API.warmupHostingChallenge('setup_status.php');
                    return getSetupStatus(attempt + 1);
                }
                return {
                    allow_setup: false,
                    reason: 'hosting_challenge'
                };
            }

            console.warn('setup_status.php retornou conteudo nao JSON:', String(text || '').slice(0, 240));
            return {
                allow_setup: false,
                reason: 'status_unavailable'
            };
        }

        if (!response.ok || !data || data.success === false) {
            return {
                allow_setup: false,
                reason: data && data.reason ? data.reason : 'status_http_error'
            };
        }

        return {
            allow_setup: !!data.allow_setup,
            reason: data.reason || 'unknown'
        };
    } catch (error) {
        if (error && error.name === 'AbortError') {
            console.warn('setup_status.php excedeu o tempo limite');
        }
        console.warn('Falha ao consultar setup_status.php:', error);
        return {
            allow_setup: false,
            reason: 'status_unavailable'
        };
    } finally {
        window.clearTimeout(timeoutId);
    }
}
