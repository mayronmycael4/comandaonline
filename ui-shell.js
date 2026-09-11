/* Shared presentation layer. Menu permissions and action handlers belong to the legacy application. */
(function(){'use strict';
const paths={dashboard:'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',users:'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M16 3a4 4 0 0 1 0 8M22 21v-2a4 4 0 0 0-3-3.87M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0',orders:'M9 3h6v4H9zM9 5H5v16h14V5h-4M8 12h8M8 16h5',table:'M3 4h18v8H3zM5 12v8M19 12v8M5 16h14',kitchen:'M8 3c0 4-3 4-3 8a7 7 0 0 0 14 0c0-3-2-5-3-6 0 4-2 4-3 5 1-5-2-6-5-7',box:'m12 3 9 5v9l-9 5-9-5V8l9-5ZM3 8l9 5 9-5M12 13v9M7 5.8l9 5',chart:'M4 3v18h17M8 16v-5M13 16V7M18 16V4',settings:'M4 7h16M4 17h16M8 4v6M16 14v6',help:'M9 9a3 3 0 1 1 5 2c-2 1-2 2-2 3M12 17h.01M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',download:'M12 3v12m-5-5 5 5 5-5M4 17v4h16v-4',logout:'M9 21H3V3h6M9 12h12m-4-4 4 4-4 4',panel:'M3 3h18v18H3zM9 3v18',qr:'M3 3h6v6H3zM15 3h6v6h-6zM3 15h6v6H3zM15 15h3v3h3v3h-6z',wallet:'M3 5h17v15H3zM16 11h5v5h-5z',plus:'M12 5v14M5 12h14'};
function icon(key){return '<svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="'+(paths[key]||paths.orders)+'"/></svg>';}
function iconFor(href){if(/index/.test(href))return 'dashboard';if(/funcionarios|clientes|empresas/.test(href))return 'users';if(/cozinha/.test(href))return 'kitchen';if(/qr-/.test(href))return 'qr';if(/mesas/.test(href))return 'table';if(/estoque|produtos|compras/.test(href))return 'box';if(/relatorios|monitoramento|auditoria/.test(href))return 'chart';if(/perfil|planos/.test(href))return 'settings';if(/ajuda/.test(href))return 'help';if(/download/.test(href))return 'download';if(/caixa/.test(href))return 'wallet';if(/nova-/.test(href))return 'plus';return 'orders';}
const aliases={'index-mobile.html':'index.html','pedidos-mobile.html':'comandas.html','comanda.html':'comandas.html','cozinha-mobile.html':'cozinha.html','produtos-mobile.html':'produtos.html','estoque-mobile.html':'estoque.html','relatorios-mobile.html':'relatorios.html','funcionarios-mobile.html':'funcionarios.html','perfil-mobile.html':'perfil.html','empresa_form.php':'empresas.php','plano_form.php':'planos.php'};
function init(){
 if(document.querySelector('.ui-sidebar')||document.querySelector('.login-box'))return;
 let saved;try{saved=localStorage.getItem('espetaria_theme');}catch(e){}if(!document.documentElement.dataset.theme)document.documentElement.dataset.theme=saved||(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');
 document.querySelectorAll('table').forEach(table=>{if(table.parentElement.classList.contains('ui-table-scroll'))return;const wrap=document.createElement('div');wrap.className='ui-table-scroll';wrap.tabIndex=0;wrap.setAttribute('role','region');wrap.setAttribute('aria-label','Tabela com rolagem horizontal');table.before(wrap);wrap.append(table);});
 const current=location.pathname.split('/').pop()||'index.html';
 if(['menu-mobile.html','setup.html'].includes(current))return;
 const admin=document.querySelector('.topbar');
 const session=typeof Storage!=='undefined'&&typeof Storage.getSession==='function'?Storage.getSession():null;
 if(!admin&&!session)return;
 document.body.classList.add('ui-shell');
 const originalHeader=admin||document.querySelector('header');
 const sidebar=document.createElement('aside');sidebar.id='ui-sidebar';sidebar.className='ui-sidebar';sidebar.setAttribute('aria-label','Menu principal');
 const brand=document.createElement('div');brand.className='ui-sidebar-brand';brand.innerHTML='<span class="ui-brand-mark">CO</span><span class="ui-brand-copy"><strong class="brand-title">Comanda Online</strong><small>'+(admin?'Painel administrativo':'Gestão de restaurantes')+'</small></span>';
 sidebar.append(brand);
 const nav=document.createElement('nav');nav.className='ui-sidebar-nav';nav.setAttribute('aria-label',admin?'Administração':'Navegação do sistema');
 const items=[];
 if(admin){admin.querySelectorAll('a').forEach(a=>{if(!a.getAttribute('href').includes('logout'))items.push({href:a.getAttribute('href'),label:a.textContent.trim()});});}
 else if(window.MenuGeral){items.push(...MenuGeral.getDesktopItems(session));
 const can=p=>session.isAdmin||Storage.hasPermission(p);
 if(can('comandas'))items.push({href:'nova-comanda-mobile.html',label:'Nova comanda'});
 if(can('relatorios'))items.push({href:'caixa-mobile.html',label:'Caixa'});
 if(can('comandas')||can('funcionarios'))items.push({href:'qr-mesas.html',label:'QR de Mesas'});
 if(can('SISTEMA_VER_LOGS'))items.push({href:'auditoria.html',label:'Auditoria'},{href:'monitoramento.html',label:'Monitoramento'});
 }
 // Preserve page-specific links already present in the old top navigation.
 document.querySelectorAll('nav.desktop-menu a').forEach(a=>{if(!a.hidden&&a.style.display!=='none')items.push({href:a.getAttribute('href'),label:a.textContent.trim()});});
 const seen=new Set();
 for(const group of ['Menu','Geral']){
 const heading=document.createElement('div');heading.className='ui-nav-group';heading.textContent=group;nav.append(heading);
 items.forEach(item=>{if(!item.href||seen.has(item.href))return;const general=/ajuda|perfil|download|auditoria|monitoramento/.test(item.href);if((group==='Geral')!==general)return;seen.add(item.href);
 const link=document.createElement('a');link.href=item.href;link.title=item.label;link.setAttribute('aria-label',item.label);link.innerHTML=icon(iconFor(item.href));const text=document.createElement('span');text.textContent=item.label;link.append(text);
 if((aliases[current]||current)===(aliases[item.href]||item.href)){link.setAttribute('aria-current','page');link.classList.add('ui-active');}
 nav.append(link);});
 if(!heading.nextElementSibling)heading.remove();
 }
 sidebar.append(nav);
 const footer=document.createElement('div');footer.className='ui-sidebar-footer';
 const userName=admin?(admin.querySelector('div:last-child span')?.textContent.trim()||'Administrador'):(session.nome||'Minha conta');
 const user=document.createElement('button');user.type='button';user.className='ui-account-trigger';user.setAttribute('aria-expanded','false');user.setAttribute('aria-controls','ui-account-menu');user.title=userName;
 const avatar=document.createElement('span');avatar.className='ui-account-avatar';avatar.setAttribute('aria-hidden','true');avatar.textContent=userName.split(/\s+/).filter(Boolean).map(p=>p[0]).slice(0,2).join('').toUpperCase();
 const userLabel=document.createElement('span');userLabel.className='ui-account-name';userLabel.textContent=userName;user.append(avatar,userLabel);footer.append(user);
 const account=document.createElement('div');account.id='ui-account-menu';account.className='ui-account-menu';account.hidden=true;
 const profile=document.createElement('a');profile.href=admin?'perfil.php':'perfil.html';profile.textContent='Perfil';account.append(profile);
 const themeLabel=document.createElement('label');themeLabel.htmlFor='ui-account-theme';themeLabel.textContent='Tema';
 const themeSelect=document.createElement('select');themeSelect.id='ui-account-theme';
 for(const [value,label] of [['light','Claro'],['dark','Escuro'],['system','Sistema']]){const option=document.createElement('option');option.value=value;option.textContent=label;themeSelect.append(option);}
 const systemTheme=matchMedia('(prefers-color-scheme: dark)');
 function syncTheme(){let preference;try{preference=localStorage.getItem('espetaria_theme');}catch(e){}themeSelect.value=['light','dark'].includes(preference)?preference:'system';document.documentElement.dataset.theme=themeSelect.value==='system'?(systemTheme.matches?'dark':'light'):themeSelect.value;}
 themeSelect.onchange=()=>{try{if(themeSelect.value==='system')localStorage.removeItem('espetaria_theme');else localStorage.setItem('espetaria_theme',themeSelect.value);}catch(e){}syncTheme();};systemTheme.addEventListener('change',syncTheme);window.addEventListener('storage',syncTheme);syncTheme();account.append(themeLabel,themeSelect);
 const logout=admin?admin.querySelector('a[href*="logout"]'):document.querySelector('header .btn-logout, header .quick-logout, .js-logout-btn');
 const exitButton=document.createElement('button');exitButton.type='button';exitButton.textContent='Sair';exitButton.onclick=()=>{if(logout)logout.click();else{Storage.clearSession();location.href='login.html';}};account.append(exitButton);footer.append(account);
 function closeAccount(){account.hidden=true;user.setAttribute('aria-expanded','false');}
 user.onclick=()=>{account.hidden=!account.hidden;user.setAttribute('aria-expanded',String(!account.hidden));if(!account.hidden){syncTheme();profile.focus();}};
 document.addEventListener('click',e=>{if(!footer.contains(e.target))closeAccount();});
 footer.addEventListener('keydown',e=>{if(e.key==='Escape'&&!account.hidden){e.preventDefault();e.stopPropagation();closeAccount();user.focus();}});
 sidebar.append(footer);
 const toggle=document.createElement('button');toggle.type='button';toggle.className='ui-icon-button ui-sidebar-toggle';toggle.setAttribute('aria-controls','ui-sidebar');toggle.innerHTML=icon('panel');
 const top=document.createElement('div');top.className='ui-topbar';top.append(toggle);
 const title=document.createElement('div');title.className='ui-page-context';const heading=document.querySelector('.container h1,.container h2,main h1');title.textContent=document.title.replace('Comanda Online - ','').replace(' - Comanda Online','');top.append(title);
 document.querySelectorAll('.theme-toggle-btn').forEach(button=>button.hidden=true);
 const controls=document.createElement('div');controls.className='ui-header-actions';
 if(!admin)originalHeader?.querySelectorAll('button,a').forEach(control=>{if(!control.closest('nav,.brand-block,.user-info')&&!control.matches('.menu-toggle,.theme-toggle-btn,.btn-logout,.quick-logout,.js-logout-btn'))controls.append(control);});
 if(controls.childElementCount)top.append(controls);
 const overlay=document.createElement('div');overlay.className='ui-sidebar-overlay';overlay.hidden=true;
 document.body.prepend(sidebar,top,overlay);
 const small=matchMedia('(max-width: 768px)');let collapsed=false,opened=false;try{collapsed=localStorage.getItem('comanda_sidebar_collapsed')==='1';}catch(e){}
 let hiddenSiblings=[];
 function render(){document.body.classList.toggle('ui-sidebar-collapsed',collapsed&&!small.matches);document.body.classList.toggle('ui-sidebar-open',opened&&small.matches);sidebar.inert=small.matches&&!opened;overlay.hidden=!(opened&&small.matches);toggle.setAttribute('aria-expanded',String(small.matches?opened:!collapsed));toggle.setAttribute('aria-label',small.matches?(opened?'Fechar menu':'Abrir menu'):(collapsed?'Expandir menu lateral':'Recolher menu lateral'));}
 function open(value){opened=value;if(small.matches){hiddenSiblings.forEach(([node,was])=>node.inert=was);hiddenSiblings=[];if(value){for(const node of document.body.children){if(node!==sidebar&&node!==overlay&&node!==top&&node instanceof HTMLElement){hiddenSiblings.push([node,node.inert]);node.inert=true;}}}}render();if(value&&small.matches)requestAnimationFrame(()=>{if(opened)(nav.querySelector('[aria-current]')||nav.querySelector('a'))?.focus();});else toggle.focus();}
 toggle.onclick=()=>{if(small.matches)open(!opened);else{collapsed=!collapsed;try{localStorage.setItem('comanda_sidebar_collapsed',collapsed?'1':'0');}catch(e){}render();}};
 overlay.onclick=()=>open(false);
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&opened){e.preventDefault();open(false);}if((e.ctrlKey||e.metaKey)&&e.key==='b'&&!/input|textarea|select/i.test(e.target.tagName)){e.preventDefault();toggle.click();}if(e.key==='Tab'&&opened&&small.matches){const focusables=[toggle,...Array.from(sidebar.querySelectorAll('a,button,input')).filter(n=>n.getClientRects().length&&!n.disabled)];const first=focusables[0],last=focusables[focusables.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}});
 nav.addEventListener('click',e=>{if(e.target.closest('a')&&small.matches)open(false);});
 small.addEventListener('change',()=>{open(false);});
 // Existing swipe and close hooks now open this presentation component.
 if(typeof window.abrirMenu==='function')window.abrirMenu=()=>open(true);
 if(typeof window.fecharMenu==='function')window.fecharMenu=()=>open(false);
 render();
}
if(document.readyState!=='complete')document.addEventListener('DOMContentLoaded',()=>queueMicrotask(init),{once:true});else init();
})();
