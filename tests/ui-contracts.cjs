const fs = require('node:fs');
const cp = require('node:child_process');
const assert = require('node:assert/strict');

const base = '716e875';
const pages = fs.readdirSync('pages')
  .filter((f) => f.endsWith('.html') && !f.includes(' (1)'))
  .map((f) => `pages/${f}`);
let checks = 0;

for (const page of pages) {
  const originalPage = page.replace(/^pages\//, '');
  const current = fs.readFileSync(page, 'utf8');
  let old;
  try {
    old = cp.execFileSync('git', ['show', `${base}:${originalPage}`], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
  } catch (_error) {
    assert(current.includes('ui-theme.css'), `${page}: tema ausente`);
    checks++;
    continue;
  }

  for (const pattern of [
    /\bid\s*=\s*["']([^"']+)["']/g,
    /<(?:input|select|textarea)\b[^>]*\bname\s*=\s*["']([^"']+)["']/g,
    /<form\b[^>]*>/g,
  ]) {
    const values = (text) => [...text.matchAll(pattern)].map((m) => m[1] || m[0]).sort();
    assert.deepEqual(values(current), values(old), `${page}: contrato de formulario/ID alterado`);
    checks++;
  }

  assert(current.includes('ui-theme.css'), `${page}: tema ausente`);
  checks++;
}

for (const page of ['pages/login.html', 'admin/login.php']) {
  const current = fs.readFileSync(page, 'utf8');
  assert(current.includes('ui-login.js'));
  assert(current.includes('ui-login.css'));
  checks++;
}

const normalize = (text) => text.replace(/\r\n/g, '\n');
const php = normalize(fs.readFileSync('admin/login.php', 'utf8').split('?>')[0]);
const oldPhp = normalize(cp.execFileSync('git', ['show', `${base}:admin/login.php`], { encoding: 'utf8' }).split('?>')[0]);
assert.equal(php, oldPhp, 'Logica de autenticacao admin alterada');
checks++;

assert(fs.readFileSync('admin/partials/header.php', 'utf8').includes('ui-shell.js'));
checks++;

console.log(JSON.stringify({
  status: 'PASS',
  contractChecks: checks,
  htmlPages: pages.length,
  scope: 'Contratos estaticos. Nao certifica operacoes financeiras nem sessoes/permissoes de outros usuarios.',
}, null, 2));
