const HubTheme=(function(){'use strict';const COOKIE='hub_theme';
function getSaved(){const m=document.cookie.match(new RegExp('(^| )'+COOKIE+'=([^;]+)'));return m?m[2]:'auto'}
function save(t){document.cookie=COOKIE+'='+t+'; path=/; max-age=31536000; SameSite=Lax'}
function getSystem(){return window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}
function apply(t){
  const resolved=t==='auto'?getSystem():t;
  document.documentElement.setAttribute('data-theme',resolved);
  document.querySelectorAll('.theme-toggle-btn').forEach(b=>{b.setAttribute('aria-pressed',b.dataset.theme===t?'true':'false')});
}
function set(t){if(!['light','dark','auto'].includes(t))t='auto';save(t);apply(t);announce(t)}
function announce(t){
  const labels={light:'Ljust tema',dark:'Mörkt tema',auto:'Automatiskt tema'};
  let el=document.getElementById('theme-announcement');
  if(!el){el=document.createElement('div');el.id='theme-announcement';el.setAttribute('role','status');el.setAttribute('aria-live','polite');el.className='sr-only';document.body.appendChild(el)}
  el.textContent=labels[t]+' aktiverat';
}
function init(){
  apply(getSaved());
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change',()=>{if(getSaved()==='auto')apply('auto')});
  // Only listen for clicks on actual theme toggle buttons within the theme toggle container
  document.querySelectorAll('.theme-toggle').forEach(container=>{
    container.addEventListener('click',e=>{
      const b=e.target.closest('.theme-toggle-btn');
      if(b&&b.dataset.theme){
        e.preventDefault();
        e.stopPropagation();
        set(b.dataset.theme);
      }
    });
  });
}
return{init,setTheme:set,getTheme:getSaved}})();
document.readyState==='loading'?document.addEventListener('DOMContentLoaded',HubTheme.init):HubTheme.init();
