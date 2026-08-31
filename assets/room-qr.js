(() => {
  'use strict';
  const dialog=document.querySelector('#room-qr-dialog');if(!dialog)return;
  const root=dialog.querySelector('[data-room-qr]'),feedback=dialog.querySelector('[data-room-qr-feedback]');
  dialog.removeAttribute('open');dialog.showModal();document.body.classList.add('term-dialog-open');
  try {new QRCode(root,{text:root.dataset.roomQr,width:512,height:512,correctLevel:QRCode.CorrectLevel.M});dialog.querySelectorAll('[data-download-room-qr],[data-print-room-qr]').forEach(b=>b.disabled=false);}
  catch {feedback.textContent='สร้าง QR ไม่สำเร็จ กรุณาโหลดใหม่ หรือคัดลอกลิงก์ด้านล่าง';}
  dialog.querySelector('[data-close-room-qr]').addEventListener('click',e=>{e.preventDefault();dialog.close();});
  dialog.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();dialog.close();}});
  dialog.addEventListener('close',()=>{document.body.classList.remove('term-dialog-open','printing-room-qr');const url=new URL(location.href),room=url.searchParams.get('qr_room');url.searchParams.delete('qr_room');history.replaceState(null,'',url);document.querySelector('a[href="?page=rooms&qr_room='+room+'"]')?.focus();});
  dialog.querySelector('[data-copy-room-link]').addEventListener('click',async()=>{const input=dialog.querySelector('[data-room-link]');try{await navigator.clipboard.writeText(input.value);feedback.textContent='คัดลอกลิงก์แล้ว';}catch{input.focus();input.select();feedback.textContent='เลือกลิงก์ไว้แล้ว กด Ctrl+C เพื่อคัดลอก';}});
  const preparePrint=()=>{if(dialog.open)document.body.classList.add('printing-room-qr');};
  window.addEventListener('beforeprint',preparePrint);window.addEventListener('afterprint',()=>document.body.classList.remove('printing-room-qr'));
  dialog.querySelector('[data-print-room-qr]').addEventListener('click',()=>{preparePrint();window.print();});
  dialog.querySelector('[data-download-room-qr]').addEventListener('click',async()=>{
    const button=dialog.querySelector('[data-download-room-qr]');button.disabled=true;
    try{
      await document.fonts.ready;
      const canvas=document.createElement('canvas');canvas.width=800;canvas.height=1000;const ctx=canvas.getContext('2d');
      ctx.fillStyle='white';ctx.fillRect(0,0,800,1000);ctx.fillStyle='#17212b';ctx.textAlign='center';
      ctx.font='bold 44px "Noto Sans Thai", sans-serif';ctx.fillText(dialog.querySelector('[data-room-poster] h2').textContent,400,72);
      ctx.font='26px "Noto Sans Thai", sans-serif';ctx.fillText(dialog.querySelector('[data-room-poster] h3').textContent,400,124,720);
      ctx.imageSmoothingEnabled=false;ctx.drawImage(root.querySelector('canvas'),144,176,512,512);
      ctx.fillText('สแกนลงชื่อเข้าใช้ห้อง',400,752);ctx.font='22px "Noto Sans Thai", sans-serif';ctx.fillText('มีคลาส: เลือกลงชื่อเข้าเรียน',400,806);ctx.fillText('ไม่มีคลาส: ระบุวัตถุประสงค์ และกดออกเมื่อใช้เสร็จ',400,852);ctx.fillText('QR ประจำห้อง · ใช้ได้ทุกครั้ง',400,912);
      const link=document.createElement('a');link.href=canvas.toDataURL('image/png');link.download='LUMS-room-'+dialog.querySelector('[data-room-poster] h2').textContent.replace(/[^a-z0-9_-]/gi,'_')+'.png';link.click();feedback.textContent='ดาวน์โหลดป้าย QR แล้ว';
    }catch{feedback.textContent='ดาวน์โหลดไม่สำเร็จ กรุณาลองใหม่หรือใช้พิมพ์ป้าย / PDF';}finally{button.disabled=false;}
  });
})();
