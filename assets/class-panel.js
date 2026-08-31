(() => {
  'use strict';
  const dialog=document.querySelector('#class-info-dialog');
  if (!dialog || !dialog.showModal) return;
  const body=dialog.querySelector('[data-class-body]');
  const q=(selector)=>body.querySelector(selector);
  let opener, activeId=Number(dialog.dataset.initialClass), controller, serial=0;
  const feedback=(text)=>{const target=q('[data-class-feedback]');if(target)target.textContent=text;};
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
    const root=q('[data-class-qr]');
    if(!root)return;
    try {
      root.replaceChildren();
      new window.QRCode(root,{text:root.dataset.classQr,width:512,height:512,colorDark:'#000000',colorLight:'#ffffff',correctLevel:window.QRCode.CorrectLevel.M});
      root.dataset.qrReady='true';
      body.querySelectorAll('[data-download-qr],[data-print-qr]').forEach((button)=>button.disabled=false);
    } catch (_) {root.textContent='สร้าง QR ไม่สำเร็จ ใช้ลิงก์ด้านล่างหรือกดรีเฟรชรายชื่อเพื่อลองใหม่';}
    body.querySelectorAll('form').forEach((form)=>{form.action=location.pathname+location.search;});
    body.querySelectorAll('td').forEach((td,index)=>{const headings=[...td.closest('table').querySelectorAll('th')];td.dataset.label=headings[index%headings.length]?.textContent || '';});
    window.LUMS?.renderIcons(body);
    q('[data-refresh-class]')?.addEventListener('click',()=>load(activeId));
    q('[data-class-copy]')?.addEventListener('click',async()=>{
      const input=q('[data-class-link]');
      try {await navigator.clipboard.writeText(input.value);feedback('คัดลอกลิงก์แล้ว');}
      catch (_) {input.focus();input.select();feedback(document.execCommand('copy')?'คัดลอกลิงก์แล้ว':'เลือกลิงก์ไว้แล้ว กด Ctrl+C เพื่อคัดลอก');}
    });
    q('[data-print-qr]')?.addEventListener('click',()=>print('printing-class-qr'));
    q('[data-download-qr]')?.addEventListener('click',download);
  }

  async function download() {
    const button=q('[data-download-qr]');
    const expectedId=activeId;
    button.disabled=true;feedback('กำลังเตรียมรูป QR…');
    try {
      await document.fonts.ready;
      if(activeId!==expectedId || !dialog.open)return;
      const source=q('[data-class-qr] canvas');
      if(!source)throw new Error('ไม่มี QR ให้ส่งออก');
      const canvas=document.createElement('canvas');canvas.width=768;
      const ctx=canvas.getContext('2d');
      const wrap=(text,size)=>{
        ctx.font=`${size}px "Noto Sans Thai", sans-serif`;
        const units=[...new Intl.Segmenter('th',{granularity:'grapheme'}).segment(text)].map((part)=>part.segment);
        const lines=[];let line='';
        units.forEach((unit)=>{if(ctx.measureText(line+unit).width>672){lines.push(line);line=unit;}else line+=unit;});
        if(line)lines.push(line);return lines;
      };
      const blocks=[['LUMS · สแกนเพื่อลงชื่อเข้าเรียน',26],[q('[data-poster-course]').textContent,32],[q('[data-poster-room]').textContent,24],[q('[data-poster-time]').textContent,24]];
      const laidOut=blocks.map(([text,size])=>({size,lines:wrap(text,size)}));
      const state=wrap(q('[data-poster-state]')?.textContent || 'ลงชื่อได้เฉพาะช่วงเวลาของคลาส',22);
      canvas.height=160+laidOut.reduce((sum,b)=>sum+b.lines.length*(b.size+16)+12,0)+576+state.length*34;
      ctx.fillStyle='#ffffff';ctx.fillRect(0,0,canvas.width,canvas.height);ctx.fillStyle='#17212b';ctx.textAlign='center';ctx.textBaseline='top';
      let y=40;
      for(const block of laidOut){ctx.font=`${block.size}px "Noto Sans Thai", sans-serif`;for(const line of block.lines){ctx.fillText(line,384,y);y+=block.size+16;}y+=12;}
      y+=24;ctx.imageSmoothingEnabled=false;ctx.drawImage(source,128,y,512,512);y+=552;
      ctx.font='22px "Noto Sans Thai", sans-serif';for(const line of state){ctx.fillText(line,384,y);y+=34;}
      const link=document.createElement('a');
      link.download=`LUMS-${q('[data-panel-class]').dataset.courseCode.replace(/[^a-z0-9_-]/gi,'_')}-${activeId}.png`;
      link.href=canvas.toDataURL('image/png');document.body.append(link);link.click();link.remove();feedback('ดาวน์โหลดป้าย QR แล้ว');
    } catch (_) {feedback('ดาวน์โหลดไม่สำเร็จ กรุณาลองใหม่ หรือใช้พิมพ์ QR / PDF');}
    finally {button.disabled=false;}
  }

  async function load(id) {
    controller?.abort();const request=++serial;activeId=id;
    body.replaceChildren();const loading=document.createElement('p');loading.setAttribute('role','status');loading.textContent='กำลังโหลด QR และรายชื่อ…';body.append(loading);body.setAttribute('aria-busy','true');
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
