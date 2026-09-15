const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('SSO loads its script and opens the tenant dashboard from /api/', () => {
  const entry = new URL('https://example.test/clientes/empresa-a/api/sso_login.php');
  const html = fs.readFileSync('api/sso_login.php', 'utf8');
  const src = html.match(/<script src="([^"]+)"/)[1];
  assert.equal(new URL(src, entry).pathname, '/clientes/empresa-a/assets/sso_login.js');
  const values = new Map();
  let destination;
  vm.runInNewContext(fs.readFileSync('assets/sso_login.js', 'utf8'), {
    document: { getElementById: () => ({ textContent: JSON.stringify({ sessao: '{"funcionarioId":1}', adminUrl: 'https://example.test/admin/empresas.php' }) }) },
    localStorage: { setItem: (key, value) => values.set(key, value) },
    window: { location: { replace: (value) => { destination = new URL(value, entry); } } },
  });
  assert.equal(destination.pathname, '/clientes/empresa-a/pages/index.html');
  assert.equal(values.get('comanda_session'), '{"funcionarioId":1}');
  assert.equal(values.get('comanda_admin_panel_url'), 'https://example.test/admin/empresas.php');
});

test('login assets stay inside the tenant and exist in the release', () => {
  const html = fs.readFileSync('pages/login.html', 'utf8');
  const page = new URL('https://example.test/clientes/empresa-a/pages/login.html');
  for (const [, relative] of html.matchAll(/(?:src|href)="(\.\.\/[^"?#]+)(?:[^" ]*)"/g)) {
    const url = new URL(relative, page);
    assert(url.pathname.startsWith('/clientes/empresa-a/'));
    assert(fs.existsSync(url.pathname.replace('/clientes/empresa-a/', '')), relative);
  }
});

test('successful login preserves hosting protection cookies', async () => {
  const values = new Map();
  const document = { addEventListener() {}, cookie: '__test=hosting-session' };
  const context = vm.createContext({
    document,
    localStorage: { getItem: (key) => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), clear: () => values.clear() },
    sessionStorage: { clear() {} },
    window: {}, navigator: {}, console,
  });
  vm.runInContext(fs.readFileSync('assets/login-init.js', 'utf8'), context);
  await vm.runInContext('prepareFreshSessionEnvironment()', context);
  assert.equal(document.cookie, '__test=hosting-session');
});
