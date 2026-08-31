(() => {
  'use strict';
  const dialog = document.querySelector('#one-off-dialog');
  if (!dialog?.showModal) return;
  const form=dialog.querySelector('[data-one-off-form]'), field=(name)=>form.elements.namedItem(name), q=(s)=>dialog.querySelector(s);
  const slots=q('[data-once-slots]'), message=q('[data-once-availability]'), submit=form.querySelector('[type="submit"]'), retry=q('[data-once-retry]');
  const start=field('starts_time'), end=field('ends_time'), modeHelp=q('[data-once-mode-help]');
  const semester=()=>field('class_mode').value==='semester';
  const clock=(m)=>String(Math.floor(m/60)).padStart(2,'0')+':'+String(m%60).padStart(2,'0');
  const minutes=(v)=>{const [h,m]=v.split(':').map(Number);return h*60+m;};
  const dateLabel=(d)=>d.toLocaleDateString('th-TH',{day:'numeric',month:'short',year:'numeric'});
  const date=(v)=>new Date(v+'T12:00:00');
  const payload=()=>new URLSearchParams(new FormData(form)).toString();
  let busy=[], ready=false, controller, request=0, opener, anchor=null, timer, validated='';
  function summary() {
    const room=field('room_id').value ? field('room_id').selectedOptions[0].textContent : 'ยังไม่ได้เลือกห้อง';
    const range=room+' · '+(start.value || '—')+'–'+(end.value || '—')+' น.';
    let text='ครั้งเดียว · '+(field('class_date').value ? dateLabel(date(field('class_date').value)) : 'ยังไม่ได้เลือกวันที่')+'\n'+range;
    if (semester()) {
      const option=field('term_id').selectedOptions[0];
      text='เลือกภาคการศึกษาเพื่อแสดงวันที่และจำนวนครั้ง';
      q('[data-class-term-dates]').textContent='ใช้วันเริ่ม–สิ้นสุดตามภาคการศึกษาที่เลือก';
      if (option?.dataset.start) {
        const first=date(option.dataset.start), last=date(option.dataset.end);
        first.setDate(first.getDate()+(Number(field('day_of_week').value)-(first.getDay() || 7)+7)%7);
        const count=Math.max(0,Math.floor((last-first)/604800000)+1), final=new Date(first);
        final.setDate(first.getDate()+(count-1)*7);
        text='ภาคเรียน '+option.textContent+' · ทุก'+field('day_of_week').selectedOptions[0].textContent+' · '+count+' ครั้ง\n'+range+'\nครั้งแรก '+dateLabel(first)+' — ครั้งสุดท้าย '+dateLabel(final);
        q('[data-class-term-dates]').textContent='ช่วงภาคเรียน '+dateLabel(date(option.dataset.start))+'–'+dateLabel(last)+' · ล็อกตามภาคการศึกษา';
      }
    }
    q('[data-class-booking-summary]').textContent=text;
    q('[data-class-result-help]').textContent=semester()
      ? 'บันทึกการใช้ห้องทุกสัปดาห์ลงตารางและปฏิทิน รวมสัปดาห์สอบตามช่วงภาคเรียน เลือกวันที่เรียนในตารางเพื่อเตรียม QR ของแต่ละครั้ง'
      : 'บันทึกการใช้ห้องเฉพาะวันนี้ พร้อม QR สำหรับลงชื่อของคลาสนี้';
    const past=field('class_date').value && end.value && field('class_date').value+'T'+end.value<=modeHelp.dataset.localNow;
    modeHelp.textContent=field('checkin_mode').value==='manual'
      ? 'เปิดรับลงชื่อทันทีหลังบันทึก แม้อยู่นอกเวลาเรียน และรับต่อจนผู้สอนกดปิดเอง'
      : past ? 'พ้นเวลาที่เลือกแล้ว จะบันทึกเป็นแบบร่าง หากต้องการรับตอนนี้ ให้เลือกเปิดจนผู้สอนกดปิดเอง'
      : 'รับเฉพาะเวลาเรียนและหยุดเมื่อถึงเวลาสิ้นสุด หากเป็นแบบร่าง ให้ผู้สอนกดเปิดรับในหน้าคลาส';
  }
  function error(input,text) {
    input.setCustomValidity(text);input.setAttribute('aria-invalid',String(Boolean(text)));
    const hint=q('#once-error-'+input.name);
    if(hint){hint.textContent=text;hint.hidden=!text;}
  }
  function checkTime() {
    summary();
    const a=minutes(start.value), b=minutes(end.value), conflict=busy.find((item)=>a<item.end && b>item.start);
    let text='';
    if(!start.value || !end.value || b<=a)text='เลือกเวลาสิ้นสุดหลังเวลาเริ่มในวันเดียวกัน';
    else if(!semester() && b-a>720)text='คลาสเรียนหนึ่งครั้งต้องไม่เกิน 12 ชั่วโมง';
    else if(!semester() && ready && conflict)text=conflict.reason+' '+clock(conflict.start)+'–'+clock(conflict.end)+' กรุณาเปลี่ยนช่วงเวลา';
    error(end,text);
    submit.disabled=!ready || Boolean(text) || (semester() && validated!==payload());
    slots.querySelectorAll('button').forEach((button)=>button.setAttribute('aria-pressed',String(!button.disabled && Number(button.dataset.start)<b && Number(button.dataset.start)+60>a)));
    q('[data-once-range]').textContent=Number.isFinite(a) && Number.isFinite(b) && b>a
      ? 'ช่วงที่เลือก '+start.value+'–'+end.value+' · รวม '+Math.floor((b-a)/60)+' ชั่วโมง'+((b-a)%60?' '+((b-a)%60)+' นาที':'')+(anchor!==null?' · คลิกช่องสุดท้ายเพื่อขยายช่วง':'')
      : 'กรุณาเลือกเวลาเริ่มและสิ้นสุด';
    return !text && ready;
  }
  function renderSlots() {
    slots.replaceChildren();
    for(let hour=8;hour<20;hour++){
      const a=hour*60,b=a+60,conflict=busy.find((item)=>a<item.end && b>item.start),button=document.createElement('button');
      button.type='button';button.dataset.start=String(a);button.disabled=Boolean(conflict);button.textContent=clock(a)+'–'+clock(b);
      const status=document.createElement('small');status.textContent=conflict?'ไม่ว่าง':'เลือกเวลา';button.append(status);
      button.setAttribute('aria-label',clock(a)+'–'+clock(b)+' '+(conflict?conflict.reason:'เลือกเวลา'));
      button.addEventListener('click',()=>{
        if(anchor===null){anchor=a;start.value=clock(a);end.value=clock(b);}
        else{start.value=clock(Math.min(anchor,a));end.value=clock(Math.max(anchor,a)+60);anchor=null;}
        checkTime();if(semester())queuePreview();
      });
      slots.append(button);
    }
  }
  function cancelRequest(){
    clearTimeout(timer);controller?.abort();request++;validated='';ready=false;submit.disabled=true;retry.hidden=true;
    message.removeAttribute('aria-busy');message.classList.remove('is-error');
  }
  async function refresh(){
    cancelRequest();const current=request;error(end,'');summary();
    const recurring=semester(), selectedDate=field('class_date').value,room=field('room_id').value,lecturer=field('lecturer_user_id').value;
    if(recurring){
      busy=[];renderSlots();checkTime();
      if(![...form.querySelectorAll('[required]:not(:disabled)')].every((input)=>input.value.trim() && input.checkValidity())){
        message.textContent='กรอกภาคเรียน วัน ห้อง ผู้สอน และรายวิชาให้ครบ ระบบจะตรวจเวลาชนตลอดภาคเรียน';return;
      }
    }else{
      slots.replaceChildren();busy=[];
      if(!selectedDate || !room || !lecturer){message.textContent='เลือกวันที่ ห้อง และผู้สอนเพื่อตรวจเวลาว่าง';return;}
    }
    message.textContent=recurring?'กำลังตรวจห้องและผู้สอนทุกสัปดาห์ตลอดภาคเรียน…':'กำลังตรวจเวลาว่างของห้องและผู้สอนในวันที่เลือก…';
    message.setAttribute('aria-busy','true');controller=new AbortController();const activeController=controller;
    const timeout=setTimeout(()=>activeController.abort(),12000),body=payload();
    try{
      const url=recurring?'?api=schedule-preview':'?'+new URLSearchParams({api:'one-off-availability',date:selectedDate,room_id:room,lecturer_user_id:lecturer});
      const response=await fetch(url,{signal:activeController.signal,credentials:'same-origin',cache:'no-store',...(recurring?{method:'POST',body:new URLSearchParams(body)}:{})});
      const result=await response.json();
      if(current!==request || (recurring && body!==payload()))return;
      if(!response.ok)throw new Error(result.message || 'ตรวจเวลาไม่สำเร็จ');
      if(recurring){
        ready=Boolean(result.ok);validated=ready?body:'';
        message.textContent=ready?'ตรวจแล้ว: ห้องและผู้สอนไม่ชนตลอดภาคเรียน พร้อมบันทึกทุกครั้งตามสรุปด้านล่าง':result.message;
        message.classList.toggle('is-error',!ready);
      }else{
        if(!result.ok)throw new Error(result.message || 'ตรวจเวลาไม่สำเร็จ');
        busy=result.busy;ready=true;
        message.textContent=busy.length?'ช่อง “ไม่ว่าง” มีห้องหรือผู้สอนใช้งานอยู่ เลือกช่วงอื่นหรือระบุเวลาเอง':'ไม่พบเวลาชนของห้องและผู้สอนในวันนี้';
        renderSlots();
      }
      checkTime();
    }catch(err){
      if(current!==request)return;
      message.textContent=err.name==='AbortError'?'ตรวจเวลาใช้เวลานาน กรุณาลองอีกครั้ง'
        : err instanceof TypeError || err instanceof SyntaxError ? 'เชื่อมต่อระบบตรวจเวลาไม่สำเร็จ ข้อมูลที่กรอกยังอยู่ กรุณาลองอีกครั้ง' : err.message;
      message.classList.add('is-error');retry.hidden=false;ready=false;submit.disabled=true;
    }finally{clearTimeout(timeout);if(current===request)message.removeAttribute('aria-busy');}
  }
  function queuePreview(){
    cancelRequest();checkTime();message.textContent='รอตรวจช่วงเวลาที่เปลี่ยนตลอดภาคเรียน…';timer=setTimeout(refresh,350);
  }
  function setMode(){
    cancelRequest();busy=[];anchor=null;
    dialog.querySelectorAll('[data-class-mode-panel]').forEach((panel)=>{
      const hidden=panel.dataset.classModePanel!==field('class_mode').value;
      panel.hidden=hidden;panel.querySelectorAll('input,select,textarea').forEach((input)=>{input.disabled=hidden;input.setCustomValidity('');});
    });
    error(end,'');summary();
  }
  function open(trigger){
    opener=trigger || document.querySelector('[data-open-once]');setMode();dialog.showModal();document.body.classList.add('term-dialog-open');
    (q('[data-once-errors]') || q('input[name="class_mode"]:checked')).focus();refresh();
  }
  document.querySelectorAll('[data-open-once]').forEach((link)=>link.addEventListener('click',(event)=>{event.preventDefault();open(link);}));
  document.addEventListener('lums:open-class',(event)=>{
    const {opener:trigger,...context}=event.detail;
    Object.entries(context).forEach(([name,value])=>{if(field(name))field(name).value=value;});open(trigger);
  });
  dialog.querySelectorAll('[data-close-once]').forEach((link)=>link.addEventListener('click',(event)=>{event.preventDefault();if(form.dataset.submitting!=='true')dialog.close();}));
  dialog.addEventListener('cancel',(event)=>{if(form.dataset.submitting==='true')event.preventDefault();});
  dialog.addEventListener('keydown',(event)=>{
    if(event.key==='Escape'){event.preventDefault();if(form.dataset.submitting!=='true')dialog.close();}
    if(event.key==='Tab'){
      const controls=[...dialog.querySelectorAll('a[href],button:not(:disabled),input:not([type="hidden"]):not(:disabled),select:not(:disabled),textarea:not(:disabled)')].filter((el)=>el.getClientRects().length);
      if(event.shiftKey && document.activeElement===controls[0]){event.preventDefault();controls.at(-1)?.focus();}
      if(!event.shiftKey && document.activeElement===controls.at(-1)){event.preventDefault();controls[0]?.focus();}
    }
  });
  dialog.addEventListener('close',()=>{
    cancelRequest();document.body.classList.remove('term-dialog-open');opener?.focus({preventScroll:true});
    const url=new URL(location.href);['new_once','once_date','new_schedule'].forEach((key)=>url.searchParams.delete(key));history.replaceState(null,'',url);
  });
  form.addEventListener('input',(event)=>{
    if(event.target.name==='class_mode')return;
    summary();if([start,end].includes(event.target)){anchor=null;checkTime();}if(semester())queuePreview();
  });
  form.addEventListener('change',(event)=>{
    if(event.target.name==='class_mode'){setMode();refresh();return;}
    if(semester())queuePreview();
    else if(['class_date','room_id','lecturer_user_id'].includes(event.target.name)){anchor=null;refresh();}
  });
  q('[data-once-reset-range]').addEventListener('click',()=>{anchor=null;start.value='';end.value='';checkTime();if(semester())queuePreview();slots.querySelector('button:not(:disabled)')?.focus();});
  retry.addEventListener('click',refresh);
  form.addEventListener('submit',(event)=>{
    if(!checkTime() || !form.checkValidity() || (semester() && validated!==payload())){
      event.preventDefault();event.stopPropagation();form.reportValidity();form.querySelector(':invalid:not(:disabled)')?.focus();
    }
  });
  setMode();
  if(dialog.open){dialog.removeAttribute('open');open();}
  else if(location.hash==='#new-class')open();
})();
