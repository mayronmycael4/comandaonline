/* Presentation only: existing forms and authentication handlers stay attached. */
(function(){'use strict';
function init(){
 const box=document.querySelector('.login-box');if(!box||document.querySelector('.ui-login-layout'))return;
 const icon='<svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 3v6a3 3 0 0 0 6 0V3M7 3v18M20 3c-4 2-5 7-5 10h5V3Zm0 10v8"/></svg>';
 document.body.classList.add('ui-login');
 const layout=document.createElement('main');layout.className='ui-login-layout';
 const panel=document.createElement('section');panel.className='ui-login-panel';
 const top=document.createElement('div');top.className='ui-login-top';top.innerHTML='<div class="ui-login-brand"><span class="ui-brand-mark">'+icon+'</span>Comanda Online</div>';
 const toggle=document.createElement('button');toggle.type='button';toggle.className='ui-icon-button';toggle.setAttribute('aria-label','Alternar tema claro e escuro');toggle.textContent='◐';
 let saved;try{saved=localStorage.getItem('espetaria_theme');}catch(e){};
 if(!document.documentElement.dataset.theme)document.documentElement.dataset.theme=saved||(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');
 toggle.onclick=function(){const theme=document.documentElement.dataset.theme==='dark'?'light':'dark';document.documentElement.dataset.theme=theme;try{localStorage.setItem('espetaria_theme',theme);}catch(e){}};
 top.append(toggle);panel.append(top);
 const container=box.closest('.login-container')||box.parentElement;panel.append(container);
 const hero=document.createElement('aside');hero.className='ui-login-hero';hero.innerHTML=icon+'<h2>Controle total da sua operação.</h2><p>Acompanhe comandas, pedidos e sua equipe com uma gestão simples e organizada. Tudo em um só lugar.</p><div class="ui-login-stats"><div><strong>Comandas</strong><span>Atendimento</span></div><div><strong>Pedidos</strong><span>Cozinha conectada</span></div><div><strong>Gestão</strong><span>Seu negócio</span></div></div>';
 layout.append(panel,hero);document.body.prepend(layout);
 const title=box.querySelector('h1');if(title)title.textContent='Entrar na sua conta';
 let description=box.querySelector('.subtitle');if(!description){description=document.createElement('p');description.className='subtitle';title.after(description);}description.textContent='Digite suas credenciais para acessar o painel.';
 box.querySelectorAll('input').forEach((input,i)=>{if(!input.id)input.id='ui-login-field-'+i;const label=input.parentElement.querySelector('label');if(label)label.htmlFor=input.id;if(!input.autocomplete)input.autocomplete=input.type==='password'?(input.closest('#formSetup')?'new-password':'current-password'):(input.type==='email'?'username':input.id==='login'?'username':'on');});
 const note=document.createElement('p');note.className='ui-login-note';note.textContent='Comanda Online · Gestão de restaurantes';box.append(note);
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
