const fs=require('node:fs'),cp=require('node:child_process'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const base='716e875';
const pages=fs.readdirSync('.').filter(f=>f.endsWith('.html')&&!f.includes(' (1)'));
let checks=0;
for(const page of pages){const current=fs.readFileSync(page,'utf8'),old=cp.execFileSync('git',['show',base+':'+page],{encoding:'utf8'});for(const pattern of [/\bid\s*=\s*["']([^"']+)["']/g,/<(?:input|select|textarea)\b[^>]*\bname\s*=\s*["']([^"']+)["']/g,/<form\b[^>]*>/g]){const values=t=>[...t.matchAll(pattern)].map(m=>m[1]||m[0]).sort();assert.deepEqual(values(current),values(old),page+': contrato de formulário/ID alterado');checks++;}assert(current.includes('ui-theme.css'),page+': tema ausente');checks++;}
for(const page of ['login.html','admin/login.php']){const current=fs.readFileSync(page,'utf8');assert(current.includes('ui-login.js'));assert(current.includes('ui-login.css'));checks++;}
const php=fs.readFileSync('admin/login.php','utf8').split('?>')[0];const oldPhp=cp.execFileSync('git',['show',base+':admin/login.php'],{encoding:'utf8'}).split('?>')[0];assert.equal(php,oldPhp,'Lógica de autenticação admin alterada');checks++;
assert(fs.readFileSync('admin/partials/header.php','utf8').includes('ui-shell.js'));checks++;
const businessBaseline='tmp_ui_business_baseline.json';let preserved=0;
if(fs.existsSync(businessBaseline)){for(const [file,hash]of Object.entries(JSON.parse(fs.readFileSync(businessBaseline,'utf8')))){if(['admin/login.php','service-worker.js'].includes(file))continue;assert.equal(crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex'),hash,file+': fonte operacional alterada');preserved++;}}
console.log(JSON.stringify({status:'PASS',contractChecks:checks,htmlPages:pages.length,operationalFilesUnchanged:preserved,scope:'Contratos estáticos. Não certifica operações financeiras nem sessões/permissões de outros usuários.'},null,2));