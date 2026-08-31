(() => {
  'use strict';
  const dialog = document.querySelector('#one-off-dialog');
  if (!dialog || !dialog.showModal) return;
  const form = dialog.querySelector('[data-one-off-form]');
  const field = (name) => form.elements.namedItem(name);
  const slots = dialog.querySelector('[data-once-slots]');
  const message = dialog.querySelector('[data-once-availability]');
  const submit = form.querySelector('[type="submit"]');
  const retry = dialog.querySelector('[data-once-retry]');
  const start = field('starts_time'), end = field('ends_time');
  const time = (minutes) => `${String(Math.floor(minutes/60)).padStart(2,'0')}:${String(minutes%60).padStart(2,'0')}`;
  const minutes = (value) => { const [h,m] = value.split(':').map(Number); return h*60+m; };
  let busy = [], ready = false, controller, request = 0, opener, rangeAnchor = null;
  const rangeMessage = dialog.querySelector('[data-once-range]');
  const modeHelp = dialog.querySelector('[data-once-mode-help]');
  const updatePolicyHint = () => {
    const past=field('class_date').value && end.value && `${field('class_date').value}T${end.value}`<=modeHelp.dataset.localNow;
    modeHelp.textContent=field('checkin_mode').value==='manual'
      ? 'จะเปิดรับลงชื่อทันทีหลังบันทึก แม้เป็นคาบในอดีตหรืออนาคต และรับต่อจนผู้สอนกดปิดเอง เวลาเรียนยังคงเดิม'
      : past ? 'คาบนี้พ้นเวลาที่เลือกแล้ว จะบันทึกเป็นแบบร่างและยังลงชื่อไม่ได้ หากต้องการรับตอนนี้ ให้เลือกเปิดจนผู้สอนกดปิดเอง'
      : 'รับเฉพาะเวลาเรียนและหยุดเมื่อถึงเวลาสิ้นสุด หากเป็นแบบร่าง ให้ผู้สอนกดเปิดรับในหน้าคลาสก่อนใช้งาน';
  };
  const error = (input, text) => {
    input.setCustomValidity(text);
    input.setAttribute('aria-invalid',String(Boolean(text)));
    const hint = document.getElementById(`once-error-${input.name}`);
    if (hint) { hint.textContent=text; hint.hidden=!text; }
  };
  const checkTime = () => {
    updatePolicyHint();
    const a=minutes(start.value), b=minutes(end.value);
    const conflict = busy.find((item) => a<item.end && b>item.start);
    let text = '';
    if (!start.value || !end.value || b<=a) text='เลือกเวลาสิ้นสุดหลังเวลาเริ่มในวันเดียวกัน';
    else if (b-a>720) text='คาบหนึ่งครั้งต้องไม่เกิน 12 ชั่วโมง';
    else if (ready && conflict) text=`${conflict.reason} ${time(conflict.start)}–${time(conflict.end)} กรุณาเปลี่ยนช่วงเวลา`;
    error(end,text);
    submit.disabled=!ready || Boolean(text);
    slots.querySelectorAll('button').forEach((button) => button.setAttribute('aria-pressed',String(!button.disabled && Number(button.dataset.start)<b && Number(button.dataset.start)+60>a)));
    rangeMessage.textContent=Number.isFinite(a) && Number.isFinite(b) && b>a ? `ช่วงที่เลือก ${start.value}–${end.value} · รวม ${Math.floor((b-a)/60)} ชั่วโมง${(b-a)%60?' '+((b-a)%60)+' นาที':''}${rangeAnchor!==null?' · คลิกช่องสุดท้ายเพื่อขยายช่วง':''}` : 'กรุณาเลือกเวลาเริ่มและสิ้นสุด';
    return !text && ready;
  };
  const refresh = async () => {
    updatePolicyHint();
    controller?.abort();
    const current=++request;
    ready=false; rangeAnchor=null; submit.disabled=true; retry.hidden=true; slots.replaceChildren();
    message.removeAttribute('aria-busy');
    error(end,'');
    const date=field('class_date').value, room=field('room_id').value, lecturer=field('lecturer_user_id').value;
    if (!date || !room || !lecturer) { message.textContent='เลือกวันที่ ห้อง และผู้สอนเพื่อตรวจเวลาว่าง'; return; }
    message.textContent='กำลังตรวจตารางทั้งภาคและคาบที่มีอยู่…';
    message.setAttribute('aria-busy','true');
    controller=new AbortController();
    const currentController=controller;
    const timeout=setTimeout(()=>currentController.abort(),12000);
    try {
      const response=await fetch(`?${new URLSearchParams({api:'one-off-availability',date,room_id:room,lecturer_user_id:lecturer})}`,{signal:controller.signal,credentials:'same-origin',cache:'no-store'});
      const result=await response.json();
      if (current!==request) return;
      if (!response.ok || !result.ok) throw new Error(result.message || 'ตรวจเวลาไม่สำเร็จ');
      busy=result.busy; ready=true;
      message.textContent=busy.length?'ช่องที่ระบุ “ไม่ว่าง” มีห้องหรือผู้สอนติดคาบอยู่ เลือกช่องอื่นหรือระบุเวลาเอง':'ไม่พบเวลาชนของห้องและผู้สอนในวันนี้ เลือกช่วงเวลาที่ต้องการ';
      for(let hour=8;hour<20;hour++) {
        const a=hour*60, b=a+60, conflict=busy.find((item)=>a<item.end && b>item.start);
        const button=document.createElement('button');
        button.type='button'; button.dataset.start=String(a); button.disabled=Boolean(conflict);
        button.textContent=`${time(a)}–${time(b)}`;
        const status=document.createElement('small'); status.textContent=conflict?'ไม่ว่าง':'เลือกเวลา'; button.append(status);
        button.setAttribute('aria-label',`${time(a)}–${time(b)} ${conflict?conflict.reason:'เลือกเวลา'}`);
        button.addEventListener('click',()=>{
          if(rangeAnchor===null){rangeAnchor=a;start.value=time(a);end.value=time(b);}
          else {start.value=time(Math.min(rangeAnchor,a));end.value=time(Math.max(rangeAnchor,a)+60);rangeAnchor=null;}
          checkTime();
        });
        slots.append(button);
      }
      checkTime();
    } catch (err) {
      if (current!==request) return;
      message.textContent=err.name==='AbortError'?'ตรวจเวลาใช้เวลานาน กรุณาลองอีกครั้ง':(err.message || 'ตรวจเวลาไม่สำเร็จ กรุณาลองอีกครั้ง');
      retry.hidden=false; ready=false; submit.disabled=true;
    } finally { clearTimeout(timeout); if(current===request) message.removeAttribute('aria-busy'); }
  };
  const open = (trigger) => {
    opener=trigger || document.querySelector('[data-open-once]');
    dialog.showModal(); document.body.classList.add('term-dialog-open');
    (dialog.querySelector('[data-once-errors]') || field('class_date')).focus();
    refresh();
  };
  document.querySelectorAll('[data-open-once]').forEach((link)=>link.addEventListener('click',(event)=>{event.preventDefault();open(link);}));
  dialog.querySelectorAll('[data-close-once]').forEach((link)=>link.addEventListener('click',(event)=>{event.preventDefault();if(form.dataset.submitting!=='true')dialog.close();}));
  dialog.addEventListener('cancel',(event)=>{if(form.dataset.submitting==='true')event.preventDefault();});
  dialog.addEventListener('keydown',(event)=>{
    if(event.key==='Escape') {event.preventDefault();if(form.dataset.submitting!=='true')dialog.close();}
    if(event.key==='Tab') {
      const controls=[...dialog.querySelectorAll('a[href],button:not(:disabled):not([hidden]),input:not([type="hidden"]),select,textarea')];
      if(event.shiftKey && document.activeElement===controls[0]) {event.preventDefault();controls.at(-1).focus();}
      if(!event.shiftKey && document.activeElement===controls.at(-1)) {event.preventDefault();controls[0].focus();}
    }
  });
  dialog.addEventListener('close',()=>{
    controller?.abort(); ++request;
    document.body.classList.remove('term-dialog-open'); opener?.focus({preventScroll:true});
    const url=new URL(location.href);url.searchParams.delete('new_once');url.searchParams.delete('once_date');history.replaceState(null,'',url);
  });
  ['class_date','room_id','lecturer_user_id'].forEach((name)=>field(name).addEventListener('change',refresh));
  [start,end].forEach((input)=>input.addEventListener('input',()=>{rangeAnchor=null;checkTime();}));
  field('checkin_mode').addEventListener('change',updatePolicyHint);
  dialog.querySelector('[data-once-reset-range]').addEventListener('click',()=>{rangeAnchor=null;start.value='';end.value='';checkTime();slots.querySelector('button:not(:disabled)')?.focus();});
  retry.addEventListener('click',refresh);
  form.addEventListener('submit',(event)=>{
    if (!checkTime() || !form.checkValidity()) {
      event.preventDefault();event.stopPropagation();
      form.reportValidity();form.querySelector(':invalid')?.focus();
    }
  });
  if(dialog.open) {dialog.removeAttribute('open');open();}
  else if(location.hash==='#new-class')open();
})();
