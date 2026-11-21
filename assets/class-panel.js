(() => {
  'use strict';
  const dialog=document.querySelector('#class-info-dialog');
  if (!dialog || !dialog.showModal) return;
  const body=dialog.querySelector('[data-class-body]');
  const q=(selector)=>body.querySelector(selector);
  let opener, activeId=Number(dialog.dataset.initialClass), controller, serial=0;
  const closeUrl=()=>{const url=new URL(location.href);url.searchParams.delete('class_id');return url;};
  const restorePrint=()=>document.body.classList.remove('printing-class-qr','printing-calendar','printing-day');
  window.addEventListener('afterprint',restorePrint);
  window.addEventListener('beforeprint',()=>{
    // Ctrl+P must apply the same privacy boundary as the dedicated QR button.
    if(document.body.matches('.printing-class-qr,.printing-calendar,.printing-day'))return;
    if(dialog.open)document.body.classList.add('printing-class-qr');
    else if(document.querySelector('#calendar-day-dialog[open]'))document.body.classList.add('printing-day');
    else if(document.querySelector('.calendar-page'))document.body.classList.add('printing-calendar');
  });
  const print=(mode)=>{restorePrint();document.body.classList.add(mode);window.print();};
  document.querySelector('[data-print-calendar]')?.addEventListener('click',()=>print('printing-calendar'));
  document.querySelector('[data-print-day]')?.addEventListener('click',()=>print('printing-day'));

  function enhance() {
    body.querySelectorAll('form').forEach((form)=>{form.action=location.pathname+location.search;});
    body.querySelectorAll('td').forEach((td,index)=>{const headings=[...td.closest('table').querySelectorAll('th')];td.dataset.label=headings[index%headings.length]?.textContent || '';});
    window.LUMS?.renderIcons(body);
    q('[data-refresh-class]')?.addEventListener('click',()=>load(activeId));
  }

  async function load(id) {
    controller?.abort();const request=++serial;activeId=id;
    body.replaceChildren();const loading=document.createElement('p');loading.setAttribute('role','status');loading.textContent='กำลังโหลดรายละเอียดคลาส…';body.append(loading);body.setAttribute('aria-busy','true');
    controller=new AbortController();const currentController=controller;const timeout=setTimeout(()=>currentController.abort(),12000);
    try {
      const response=await fetch(`?fragment=class-panel&id=${encodeURIComponent(id)}`,{credentials:'same-origin',cache:'no-store',signal:controller.signal});
      const html=await response.text();if(request!==serial)return;
      if(!response.ok)throw new Error(response.status===401?'หมดเวลาเข้าสู่ระบบ กรุณารีเฟรชหน้าและเข้าสู่ระบบอีกครั้ง':response.status===404?'ไม่พบคลาสหรือไม่มีสิทธิ์เข้าถึง':'โหลดไม่สำเร็จ กรุณาลองใหม่');
      // HTML comes only from the authorized same-origin, escaped PHP template.
      body.innerHTML=html;enhance();dialog.querySelector('[data-close-class]').focus();
    } catch(error) {
      if(request!==serial)return;
      body.replaceChildren();const note=document.createElement('p');note.setAttribute('role','alert');note.textContent=error.name==='AbortError'?'การเชื่อมต่อใช้เวลานาน กรุณาลองใหม่':error.message;body.append(note);
      const retry=document.createElement('button');retry.type='button';retry.className='button button--secondary';retry.textContent='ลองอีกครั้ง';retry.addEventListener('click',()=>load(id));body.append(retry);retry.focus();
    } finally {clearTimeout(timeout);if(request===serial)body.removeAttribute('aria-busy');}
  }
  const open=(id,trigger)=>{
    opener=trigger;const url=new URL(location.href);url.searchParams.set('class_id',String(id));history.replaceState(null,'',url);
    if(!dialog.open)dialog.showModal();document.body.classList.add('term-dialog-open');dialog.querySelector('[data-close-class]').focus();load(id);
  };
  document.addEventListener('click',(event)=>{
    const link=event.target.closest('a[data-class-id]');
    if(!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button!==0)return;
    const id=Number(link.dataset.classId);if(!Number.isSafeInteger(id)||id<1)return;
    event.preventDefault();open(id,link);
  });
  dialog.querySelectorAll('[data-close-class]').forEach((link)=>link.addEventListener('click',(event)=>{event.preventDefault();dialog.close();}));
  dialog.addEventListener('keydown',(event)=>{
    if(event.key==='Escape'){event.preventDefault();dialog.close();}
    if(event.key==='Tab'){
      const controls=[...dialog.querySelectorAll('a[href],button:not(:disabled),input:not([type="hidden"]),select,textarea')];
      if(event.shiftKey && document.activeElement===controls[0]){event.preventDefault();controls.at(-1).focus();}
      if(!event.shiftKey && document.activeElement===controls.at(-1)){event.preventDefault();controls[0].focus();}
    }
  });
  dialog.addEventListener('close',()=>{
    controller?.abort();++serial;restorePrint();
    if(!document.querySelector('dialog[open]'))document.body.classList.remove('term-dialog-open');
    history.replaceState(null,'',closeUrl());opener?.focus({preventScroll:true});
  });
  if(dialog.open){dialog.removeAttribute('open');dialog.showModal();document.body.classList.add('term-dialog-open');enhance();dialog.querySelector('[data-close-class]').focus();}
})();
