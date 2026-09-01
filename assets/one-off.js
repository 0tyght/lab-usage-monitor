(() => {
  'use strict';
  const dialog=document.querySelector('#one-off-dialog');
  if(!dialog?.showModal)return;
  const form=dialog.querySelector('form'),field=(n)=>form.elements.namedItem(n),q=(s)=>dialog.querySelector(s);
  const catalog=JSON.parse(document.querySelector('[data-class-catalog]').textContent);
  const days=['','วันจันทร์','วันอังคาร','วันพุธ','วันพฤหัสบดี','วันศุกร์','วันเสาร์','วันอาทิตย์'];
  const grid=q('[data-class-picker]'),list=q('[data-class-slots]'),feedback=q('[data-class-preview]'),retry=q('[data-class-retry]'),submit=form.querySelector('[type="submit"]');
  const clock=(n)=>String(Math.floor(n/60)).padStart(2,'0')+':'+String(n%60).padStart(2,'0');
  const minutes=(t)=>{const [h,m]=t.split(':').map(Number);return h*60+m;};
  const semester=()=>field('class_mode').value==='semester';
  const formatDate=(v)=>new Date(v+'T12:00:00').toLocaleDateString('th-TH',{day:'numeric',month:'short',year:'numeric'});
  const element=(tag,text,className)=>{const el=document.createElement(tag);if(text)el.textContent=text;if(className)el.className=className;return el;};
  let slots=[];try{slots=JSON.parse(field('slots_json').value);if(!Array.isArray(slots))slots=[];slots=slots.filter(s=>s && typeof s==='object' && !Array.isArray(s)).slice(0,20).map(s=>({room_id:String(s.room_id ?? ''),day_of_week:Number(s.day_of_week)||1,class_date:String(s.class_date ?? field('class_date').value),starts_time:String(s.starts_time ?? ''),ends_time:String(s.ends_time ?? '')}));}catch{slots=[];}
  let pending=null,opener,controller,sequence=0,timer,validated='';
  const payload=()=>new URLSearchParams(new FormData(form)).toString();
  function sync(){
    field('slots_json').value=JSON.stringify(slots);
    q('[data-slot-count]').textContent=String(slots.length);
    const preset=catalog[field('academic_year').value]?.terms[field('semester').value];
    q('[data-class-term-dates]').textContent=preset?'ช่วงภาคเรียน '+formatDate(preset.start)+' – '+formatDate(preset.end)+' · วันที่กำหนดไว้แล้ว ไม่ต้องเพิ่มภาคการศึกษาแยก':'ยังไม่มีข้อมูลวันภาคเรียนนี้';
    let count=slots.length,first='',last='';
    if(semester() && preset){
      count=0;
      slots.forEach((slot)=>{
        const a=new Date(preset.start+'T12:00:00'),b=new Date(preset.end+'T12:00:00');
        a.setDate(a.getDate()+(Number(slot.day_of_week)-(a.getDay()||7)+7)%7);
        const n=Math.max(0,Math.floor((b-a)/604800000)+1);count+=n;
        const end=new Date(a);end.setDate(a.getDate()+(n-1)*7);
        const iso=(d)=>d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
        if(!first || iso(a)<first)first=iso(a);if(iso(end)>last)last=iso(end);
      });
    }else if(slots.length){const dates=slots.map(s=>s.class_date).sort();first=dates[0];last=dates.at(-1);}
    q('[data-class-summary]').textContent=slots.length
      ? slots.length+' ช่วงเวลา · '+count+' คลาส ใช้ QR ประจำห้อง'+(first?' · '+formatDate(first)+(last!==first?' – '+formatDate(last):''):'')
      : 'ยังไม่ได้เลือกช่วงเวลา';
  }
  function renderGrid(){
    const active=grid.contains(document.activeElement)?{day:document.activeElement.dataset.day,minute:document.activeElement.dataset.minute}:null;
    grid.replaceChildren();
    const header=element('div','', 'class-picker-row');header.append(element('strong','วัน / เวลา'));
    for(let h=8;h<20;h++)header.append(element('span',clock(h*60)));grid.append(header);
    const visibleDays=semester()?[1,2,3,4,5,6,7]:[new Date(field('class_date').value+'T12:00:00').getDay()||7];
    visibleDays.forEach((day)=>{
      const row=element('div','', 'class-picker-row');row.append(element('strong',semester()?days[day]:formatDate(field('class_date').value)));
      for(let h=8;h<20;h++){
        const a=h*60,button=element('button');button.type='button';button.dataset.day=day;button.dataset.minute=a;
        button.disabled=!field('room_id').value || (!semester() && !field('class_date').value);
        button.setAttribute('aria-label',days[day]+' '+clock(a)+'–'+clock(a+60));
        const selected=slots.some(s=>String(s.room_id)===field('room_id').value && (semester()?Number(s.day_of_week)===day:s.class_date===field('class_date').value) && a<minutes(s.ends_time) && a+60>minutes(s.starts_time));
        button.setAttribute('aria-pressed',String(selected));
        if(selected){button.textContent='เลือกแล้ว';button.setAttribute('aria-label',button.getAttribute('aria-label')+' เลือกแล้ว กดเพื่อเอาออก');}
        if(pending && pending.day===day && a>=pending.start && a<pending.end)button.classList.add('is-pending');
        button.addEventListener('click',()=>{
          if(selected){removeHour(day,a);}
          else if(pending && pending.day===day){
            pending.start=Math.min(pending.start,a);pending.end=Math.max(pending.end,a+60);addRange();
          }else{pending={day,start:a,end:a+60};renderPending();renderGrid();}
        });
        button.addEventListener('keydown',event=>{
          if(event.key==='Enter' || event.key===' '){event.preventDefault();button.click();return;}
          const nextMinute=a+(event.key==='ArrowRight'?60:event.key==='ArrowLeft'?-60:0);
          const nextDay=day+(event.key==='ArrowDown'?1:event.key==='ArrowUp'?-1:0);
          if(nextMinute!==a || nextDay!==day){event.preventDefault();grid.querySelector(`button[data-day="${nextDay}"][data-minute="${nextMinute}"]:not(:disabled)`)?.focus({preventScroll:true});}
        });
        row.append(button);
      }
      grid.append(row);
    });
    if(active)grid.querySelector(`button[data-day="${active.day}"][data-minute="${active.minute}"]`)?.focus({preventScroll:true});
  }
  function renderPending(){
    q('[data-add-range]').disabled=!pending;
    q('[data-pending-range]').textContent=pending?days[pending.day]+' '+clock(pending.start)+'–'+clock(pending.end)+' · คลิกช่องสุดท้าย หรือกดเพิ่มช่วงนี้สำหรับ 1 ชั่วโมง':'คลิกเลือกช่วงใหม่ได้ทันที เลือกห้องอื่นด้านบนได้หากต้องการ';
  }
  function normalizeSlots(){
    const groups=new Map(),invalid=[];
    slots.forEach((slot)=>{
      const start=minutes(slot.starts_time),end=minutes(slot.ends_time);
      if(!slot.room_id || !Number.isFinite(start) || !Number.isFinite(end) || start>=end){invalid.push(slot);return;}
      const key=[slot.room_id,slot.day_of_week,slot.class_date].join('|');
      if(!groups.has(key))groups.set(key,[]);groups.get(key).push({...slot,start,end});
    });
    const merged=[];
    groups.forEach((items)=>{
      items.sort((a,b)=>a.start-b.start);
      items.forEach((item)=>{
        const previous=merged.at(-1);
        const same=previous && String(previous.room_id)===String(item.room_id) && Number(previous.day_of_week)===Number(item.day_of_week) && previous.class_date===item.class_date;
        if(same && minutes(previous.ends_time)>=item.start)previous.ends_time=clock(Math.max(minutes(previous.ends_time),item.end));
        else merged.push({room_id:String(item.room_id),day_of_week:Number(item.day_of_week),class_date:item.class_date,starts_time:clock(item.start),ends_time:clock(item.end)});
      });
    });
    slots=[...merged,...invalid];
  }
  function removeHour(day,start){
    const end=start+60,next=[];
    slots.forEach((slot)=>{
      const same=String(slot.room_id)===field('room_id').value && (semester()?Number(slot.day_of_week)===day:slot.class_date===field('class_date').value);
      const from=minutes(slot.starts_time),to=minutes(slot.ends_time);
      if(!same || !Number.isFinite(from) || !Number.isFinite(to) || from>=end || to<=start){next.push(slot);return;}
      if(from<start)next.push({...slot,ends_time:clock(start)});
      if(to>end)next.push({...slot,starts_time:clock(end)});
    });
    slots=next;pending=null;normalizeSlots();renderSlots();renderGrid();renderPending();queue();
  }
  function addRange(){
    if(!pending)return;
    if(slots.length>=20){feedback.textContent='เลือกได้ไม่เกิน 20 ช่วงต่อครั้ง';return;}
    slots.push({room_id:field('room_id').value,day_of_week:pending.day,class_date:field('class_date').value,starts_time:clock(pending.start),ends_time:clock(pending.end)});normalizeSlots();
    pending=null;renderSlots();renderGrid();renderPending();queue();
  }
  function rowField(row,label,control){
    const wrapper=element('label','', 'field');wrapper.append(element('span',label),control);row.append(wrapper);return control;
  }
  function renderSlots(){
    list.replaceChildren();
    if(!slots.length)list.append(element('p','ยังไม่มีช่วงเวลา เลือกจากตารางด้านบนได้เลย','class-slot-empty'));
    slots.forEach((slot,index)=>{
      const row=element('div','', 'class-slot-row');
      const when=element(semester()?'select':'input');
      if(semester()){
        days.slice(1).forEach((label,i)=>{const option=element('option',label);option.value=i+1;when.append(option);});when.value=slot.day_of_week;
      }else{when.type='date';when.value=slot.class_date;}
      rowField(row,(semester()?'วันเรียน':'วันที่')+' · ช่วง '+(index+1),when);
      when.addEventListener('change',()=>{if(semester())slot.day_of_week=Number(when.value);else {slot.class_date=when.value;slot.day_of_week=new Date(when.value+'T12:00:00').getDay()||7;}renderGrid();queue();});
      const room=field('room_id').cloneNode(true);room.removeAttribute('name');room.value=slot.room_id;rowField(row,'ห้อง · ช่วง '+(index+1),room);
      room.addEventListener('change',()=>{slot.room_id=room.value;renderGrid();queue();});
      [['starts_time','เริ่ม'],['ends_time','สิ้นสุด']].forEach(([key,label])=>{
        const input=element('input');input.type='time';input.value=slot[key];input.required=true;rowField(row,label+' · ช่วง '+(index+1),input);
        input.addEventListener('input',()=>{slot[key]=input.value;renderGrid();queue();});
      });
      const remove=element('button','×','icon-button');remove.type='button';remove.setAttribute('aria-label','ลบช่วงที่ '+(index+1));
      remove.addEventListener('click',()=>{slots.splice(index,1);renderSlots();renderGrid();queue();(grid.querySelector('button:not(:disabled)') || field('room_id')).focus({preventScroll:true});});row.append(remove);list.append(row);
    });
    sync();
  }
  function invalidate(){controller?.abort();sequence++;clearTimeout(timer);validated='';submit.disabled=true;retry.hidden=true;feedback.removeAttribute('aria-busy');feedback.classList.remove('is-error');}
  function queue(){invalidate();sync();feedback.textContent='กำลังเตรียมตรวจทุกรายการ…';timer=setTimeout(check,350);}
  async function check(){
    invalidate();sync();const serial=sequence;
    if(!slots.length || ![...form.querySelectorAll('[required]:not(:disabled)')].every(el=>el.value && el.checkValidity())){
      feedback.textContent='เลือกช่วงเวลาและกรอกรหัสวิชา ชื่อวิชา ผู้สอนให้ครบ';return;
    }
    const body=payload();controller=new AbortController();const active=controller,timeout=setTimeout(()=>active.abort(),15000);
    feedback.textContent='กำลังตรวจห้องและผู้สอนทุกวันที่เลือก…';feedback.setAttribute('aria-busy','true');
    try{
      const response=await fetch('?api=class-batch-preview',{method:'POST',body:new URLSearchParams(body),signal:active.signal,credentials:'same-origin'});
      const result=await response.json();if(serial!==sequence || body!==payload())return;
      if(!response.ok)throw new Error(result.message || 'ตรวจรายการไม่สำเร็จ กรุณาลองอีกครั้ง');
      feedback.textContent=result.message;feedback.classList.toggle('is-error',!result.ok);
      if(result.ok){validated=body;submit.disabled=false;}
    }catch(error){
      if(serial!==sequence)return;
      feedback.textContent=error.name==='AbortError'?'ตรวจรายการใช้เวลานาน กรุณาลองอีกครั้ง':error instanceof TypeError || error instanceof SyntaxError?'เชื่อมต่อไม่ได้ ข้อมูลที่เลือกยังอยู่ กรุณาลองอีกครั้ง':error.message;
      feedback.classList.add('is-error');retry.hidden=false;
    }finally{clearTimeout(timeout);if(serial===sequence)feedback.removeAttribute('aria-busy');}
  }
  function mode(){
    pending=null;dialog.querySelectorAll('[data-class-mode-panel]').forEach(panel=>{panel.hidden=panel.dataset.classModePanel!==field('class_mode').value;});
    renderSlots();renderGrid();renderPending();queue();
  }
  function open(trigger){
    opener=trigger || document.querySelector('[data-open-once]');mode();dialog.showModal();document.body.classList.add('term-dialog-open');
    (q('[data-once-errors]') || q('input[name="class_mode"]:checked')).focus();
  }
  document.querySelectorAll('[data-open-once]').forEach(link=>link.addEventListener('click',event=>{event.preventDefault();open(link);}));
  document.addEventListener('lums:open-class',event=>{
    const context=event.detail;
    ['room_id','class_date'].forEach(key=>{if(context[key])field(key).value=context[key];});
    if(context.academic_year)field('academic_year').value=context.academic_year;if(context.semester)field('semester').value=context.semester;
    if(context.starts_time && context.ends_time)slots.push({room_id:context.room_id || '',day_of_week:Number(context.day_of_week),class_date:context.class_date,starts_time:context.starts_time,ends_time:context.ends_time});
    open(context.opener);
  });
  q('[data-add-range]').addEventListener('click',addRange);retry.addEventListener('click',check);
  form.addEventListener('input',event=>{if(event.target.name && event.target.name!=='class_mode')queue();});
  form.addEventListener('change',event=>{
    if(['class_mode','academic_year','semester'].includes(event.target.name))mode();
    else if(['room_id','class_date'].includes(event.target.name)){pending=null;renderGrid();renderPending();queue();}
  });
  dialog.querySelectorAll('[data-close-once]').forEach(link=>link.addEventListener('click',event=>{event.preventDefault();if(form.dataset.submitting!=='true')dialog.close();}));
  dialog.addEventListener('cancel',event=>{if(form.dataset.submitting==='true')event.preventDefault();});
  dialog.addEventListener('keydown',event=>{
    if(event.key==='Escape'){event.preventDefault();if(form.dataset.submitting!=='true')dialog.close();}
    if(event.key==='Tab'){
      const controls=[...dialog.querySelectorAll('a[href],button:not(:disabled),input:not([type="hidden"]):not(:disabled),select,textarea')].filter(el=>el.getClientRects().length);
      if(event.shiftKey && document.activeElement===controls[0]){event.preventDefault();controls.at(-1)?.focus();}
      if(!event.shiftKey && document.activeElement===controls.at(-1)){event.preventDefault();controls[0]?.focus();}
    }
  });
  dialog.addEventListener('close',()=>{
    invalidate();if(!document.querySelector('dialog[open]'))document.body.classList.remove('term-dialog-open');opener?.focus({preventScroll:true});
    const url=new URL(location.href);['new_once','once_date','new_schedule','new_term'].forEach(k=>url.searchParams.delete(k));history.replaceState(null,'',url);
  });
  form.addEventListener('submit',event=>{if(!validated || validated!==payload() || !form.checkValidity()){event.preventDefault();event.stopPropagation();form.reportValidity();queue();}});
  mode();if(dialog.open){dialog.removeAttribute('open');open();}else if(location.hash==='#new-class')open();
})();
