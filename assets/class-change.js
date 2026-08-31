(() => {
  'use strict';
  const dialog=document.querySelector('#class-change-dialog');
  if(!dialog?.showModal)return;
  const form=dialog.querySelector('form'),field=n=>form.elements.namedItem(n),q=s=>dialog.querySelector(s);
  const submit=form.querySelector('[type="submit"]'),summary=q('[data-change-summary]'),retry=q('[data-change-retry]');
  const payload=()=>new URLSearchParams(new FormData(form)).toString();
  let controller,sequence=0,timer,validated='';
  function invalidate(){controller?.abort();clearTimeout(timer);sequence++;validated='';submit.disabled=true;retry.hidden=true;summary.removeAttribute('aria-busy');}
  function prepare(){
    const cancel=field('operation').value==='cancel',bulk=field('scope').value!=='once';
    q('[data-change-fields]').hidden=cancel;
    q('[data-change-fields]').querySelectorAll('input,select,textarea').forEach(el=>el.disabled=cancel);
    field('class_date').readOnly=bulk;if(bulk)field('class_date').value=field('class_date').dataset.originalDate;
    q('[data-bulk-time-help]').hidden=!bulk;
    submit.textContent=cancel?'ยืนยันยกเลิกคลาส':'ยืนยันการแก้ไข';
    submit.classList.toggle('button--danger',cancel);
    form.dataset.confirmDanger=String(cancel);
    invalidate();q('[data-change-lessons]').replaceChildren();summary.classList.remove('is-error');summary.textContent='กำลังเตรียมตรวจรายการ…';timer=setTimeout(check,300);
  }
  async function check(){
    invalidate();const serial=sequence,body=payload();q('[data-change-lessons]').replaceChildren();
    if(!form.checkValidity()){summary.textContent='กรอกวัน ห้อง และเวลาให้ครบ';return;}
    controller=new AbortController();const active=controller,timeout=setTimeout(()=>active.abort(),15000);
    summary.textContent='กำลังตรวจทุกคลาสในขอบเขตที่เลือก…';summary.setAttribute('aria-busy','true');
    try{
      const response=await fetch('?api=class-change-preview',{method:'POST',body:new URLSearchParams(body),credentials:'same-origin',signal:active.signal});
      const result=await response.json();if(serial!==sequence || body!==payload())return;
      if(!response.ok)throw new Error(result.message || 'ตรวจรายการไม่สำเร็จ');
      summary.textContent=result.message;summary.classList.toggle('is-error',!result.ok);
      if(result.ok){
        validated=body;submit.disabled=false;form.dataset.confirm=result.message+' ยืนยันดำเนินการหรือไม่?';
        const ul=document.createElement('ul');
        result.lessons.forEach(row=>{const li=document.createElement('li');const date=new Date(row.date+'T12:00:00').toLocaleDateString('th-TH',{day:'numeric',month:'short',year:'numeric'});li.textContent=date+' · '+row.room+' · '+row.start+'–'+row.end;ul.append(li);});q('[data-change-lessons]').append(ul);
        if(result.count>result.lessons.length){const p=document.createElement('p');p.textContent='และอีก '+(result.count-result.lessons.length)+' คลาส';q('[data-change-lessons]').append(p);}
      }
    }catch(error){if(serial!==sequence)return;summary.textContent='ตรวจรายการไม่สำเร็จ ข้อมูลที่แก้ยังอยู่ กรุณาลองอีกครั้ง';retry.hidden=false;}
    finally{clearTimeout(timeout);if(serial===sequence)summary.removeAttribute('aria-busy');}
  }
  form.addEventListener('input',prepare);form.addEventListener('change',prepare);retry.addEventListener('click',check);
  form.addEventListener('submit',event=>{if(!validated || validated!==payload()){event.preventDefault();event.stopPropagation();prepare();}});
  dialog.querySelectorAll('[data-close-change]').forEach(link=>link.addEventListener('click',event=>{event.preventDefault();if(form.dataset.submitting!=='true')dialog.close();}));
  dialog.addEventListener('cancel',event=>{if(form.dataset.submitting==='true')event.preventDefault();});
  dialog.addEventListener('close',()=>{invalidate();document.body.classList.remove('term-dialog-open');const url=new URL(location.href);url.searchParams.delete('edit_class');history.replaceState(null,'',url);});
  dialog.removeAttribute('open');dialog.showModal();document.body.classList.add('term-dialog-open');prepare();
})();
