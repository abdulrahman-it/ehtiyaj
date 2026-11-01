const { JSDOM, VirtualConsole } = require('jsdom');
const fs = require('fs');
const assert = require('assert');
// Test only: run with jsdom available outside the project, e.g. NODE_PATH=/tmp/ui-audit/node_modules.
const source = fs.readFileSync(process.argv[2] || 'Ehtiya/htdocs/assets/app.js', 'utf8');
const wait = ms => new Promise(r => setTimeout(r, ms));
async function make(html, url='https://test.invalid/requests/view.php?id=5') {
  const errors=[]; const vc = new VirtualConsole(); vc.on('jsdomError', e => errors.push(e.message));
  const dom = new JSDOM(html, {url, runScripts:'outside-only', virtualConsole:vc, pretendToBeVisual:true});
  dom.window.confirm=()=>true;
  try { dom.window.eval(source); } catch(e){errors.push(e.message)}
  return {dom, errors};
}
(async()=>{
 let failed=0;
 const test=async(name, fn)=>{try{await fn();console.log('PASS',name)}catch(e){failed++;console.log('FAIL',name,e.message)}};
 await test('accept flash without sessionStorage does not throw',async()=>{
  const {errors}=await make('<div data-flash-message="تم قبول العروض المحددة وإنشاء المحادثات."></div><a data-request-chat-link href="/chat/index.php">chats</a>');
  assert.deepEqual(errors,[]);
 });
 await test('mobile close cancels deferred focus',async()=>{
  const {dom}=await make('<button class="mobile-menu-toggle" aria-expanded="false"></button><div class="mobile-menu-scrim"></div><aside id="mobile-menu" aria-hidden="true"><button data-menu-close>Close</button><a href="/foo">Foo</a></aside>');
  const d=dom.window.document,t=d.querySelector('.mobile-menu-toggle');t.click();d.querySelector('[data-menu-close]').click();await wait(90);
  assert.equal(d.activeElement,t);assert.equal(t.getAttribute('aria-expanded'),'false');dom.window.close();
 });
 await test('navigation click resets mobile label',async()=>{
  const {dom}=await make('<button class="mobile-menu-toggle" aria-expanded="false" aria-label="فتح القائمة"></button><div class="mobile-menu-scrim"></div><aside id="mobile-menu" aria-hidden="true"><button data-menu-close>Close</button><a href="#foo">Foo</a></aside>');
  const d=dom.window.document,t=d.querySelector('.mobile-menu-toggle');t.click();d.querySelector('aside a').click();assert.equal(t.getAttribute('aria-label'),'فتح القائمة');dom.window.close();
 });
 await test('restored page closes mobile navigation',async()=>{
  const {dom}=await make('<button class="mobile-menu-toggle" aria-expanded="false"></button><div class="mobile-menu-scrim"></div><aside id="mobile-menu" aria-hidden="true"><button data-menu-close>Close</button></aside>');
  const d=dom.window.document,t=d.querySelector('.mobile-menu-toggle');t.click();dom.window.dispatchEvent(new dom.window.PageTransitionEvent('pageshow',{persisted:true}));assert.equal(t.getAttribute('aria-expanded'),'false');dom.window.close();
 });
 await test('mobile drawer opens, closes with Escape, and returns focus',async()=>{
  const {dom}=await make('<button class="mobile-menu-toggle" aria-expanded="false"></button><div class="mobile-menu-scrim"></div><aside id="mobile-menu" aria-hidden="true"><button data-menu-close>Close</button><a href="#">Home</a></aside>');
  const d=dom.window.document,t=d.querySelector('.mobile-menu-toggle');t.click();assert.equal(t.getAttribute('aria-expanded'),'true');
  d.dispatchEvent(new dom.window.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));assert.equal(t.getAttribute('aria-expanded'),'false');assert.equal(d.activeElement,t);dom.window.close();
 });
 await test('account dropdown closes on outside click and Escape',async()=>{
  const {dom}=await make('<details class="nav-more"><summary>حسابي</summary><div class="nav-more-menu"><a href="#">الملف</a></div></details><button id="outside">outside</button>');
  const d=dom.window.document,m=d.querySelector('details');m.open=true;d.querySelector('#outside').click();assert.equal(m.open,false);
  m.open=true;d.dispatchEvent(new dom.window.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));assert.equal(m.open,false);assert.equal(d.activeElement,m.querySelector('summary'));dom.window.close();
 });
 await test('password visibility toggle preserves value',async()=>{
  const {dom}=await make('<input id="pw" type="password" value="example"><button type="button" data-password-target="pw"></button>');
  const d=dom.window.document,b=d.querySelector('button'),p=d.querySelector('input');b.click();assert.equal(p.type,'text');assert.equal(p.value,'example');b.click();assert.equal(p.type,'password');dom.window.close();
 });
 await test('confirmation cancellation prevents action',async()=>{
  const {dom}=await make('<form><button type="submit" data-confirm="تأكيد">Delete</button></form>');
  const d=dom.window.document;dom.window.confirm=()=>false;let sent=false;d.querySelector('form').addEventListener('submit',e=>{sent=true;e.preventDefault()});d.querySelector('button').click();assert.equal(sent,false);dom.window.close();
 });
 await test('pending form preserves submitter data and restores original markup on page return',async()=>{
  const {dom,errors}=await make('<form><input name="csrf" value="example"><button name="action" value="save"><i class="icon"></i> حفظ</button></form>');
  await wait(5); const w=dom.window,d=w.document,f=d.querySelector('form'),b=d.querySelector('button'),icon=b.querySelector('i');
  const submit=()=>new w.SubmitEvent('submit',{bubbles:true,cancelable:true,submitter:b});
  assert.equal(f.dispatchEvent(submit()),true);assert.equal(b.disabled,false);assert.equal(b.getAttribute('aria-busy'),'true');
  assert.equal(new w.FormData(f,b).get('action'),'save');assert.equal(f.dispatchEvent(submit()),false);
  w.dispatchEvent(new w.PageTransitionEvent('pageshow',{persisted:true}));
  assert.equal(b.querySelector('i'),icon);assert.equal(b.textContent,' حفظ');assert.equal(b.hasAttribute('aria-busy'),false);
  assert.equal(f.dispatchEvent(submit()),true);assert.deepEqual(errors,[]);dom.window.close();
 });
 await test('cancelled submit restores button even when cancelled by a later listener',async()=>{
  const {dom}=await make('<form><button aria-busy="false"><i></i> حفظ</button></form>');await wait(5);
  const w=dom.window,f=w.document.querySelector('form'),b=f.querySelector('button');
  f.addEventListener('submit',e=>e.preventDefault());
  assert.equal(f.dispatchEvent(new w.SubmitEvent('submit',{bubbles:true,cancelable:true,submitter:b})),false);
  await Promise.resolve();assert.equal(b.textContent,' حفظ');assert.equal(b.getAttribute('aria-busy'),'false');assert.equal(b.hasAttribute('aria-disabled'),false);dom.window.close();
 });
 await test('offer acceptance keeps successful submitter and resets on return',async()=>{
  const {dom}=await make('<form class="offer-review-form" data-request-id="5"><input type="checkbox" name="selected[]" value="8" class="offer-accept-checkbox" data-offer-name="طالب" checked><button type="submit" name="offers[]" value="8" data-accept-offer>قبول</button><button type="submit" data-accept-selected>قبول المحدد</button></form>');await wait(5);
  const w=dom.window,f=w.document.querySelector('form'),b=f.querySelector('[data-accept-offer]');let confirms=0;w.confirm=()=>{confirms++;return true};
  const event=()=>new w.SubmitEvent('submit',{bubbles:true,cancelable:true,submitter:b});
  assert.equal(f.dispatchEvent(event()),true);assert.equal(b.disabled,false);assert.equal(new w.FormData(f,b).get('offers[]'),'8');
  assert.equal(f.dispatchEvent(event()),false);assert.equal(confirms,1);
  w.dispatchEvent(new w.PageTransitionEvent('pageshow',{persisted:true}));assert.equal(b.hasAttribute('aria-busy'),false);
  assert.equal(f.dispatchEvent(event()),true);assert.equal(confirms,2);dom.window.close();
 });
 await test('offer confirmation cancellation does not lock form',async()=>{
  const {dom}=await make('<form class="offer-review-form"><input type="checkbox" class="offer-accept-checkbox" value="8" checked><button data-accept-selected>قبول</button></form>');await wait(5);
  const w=dom.window,f=w.document.querySelector('form'),b=f.querySelector('button');let confirms=0;w.confirm=()=>{confirms++;return false};
  const event=()=>new w.SubmitEvent('submit',{bubbles:true,cancelable:true,submitter:b});
  assert.equal(f.dispatchEvent(event()),false);assert.equal(f.dispatchEvent(event()),false);assert.equal(confirms,2);assert.equal(b.disabled,false);dom.window.close();
 });
 await test('implicit submit and unrelated disabled controls are not changed on return',async()=>{
  const {dom}=await make('<form><button disabled>غير متاح</button><button>إرسال</button></form>');await wait(5);
  const w=dom.window,f=w.document.querySelector('form'),buttons=f.querySelectorAll('button');
  const event=()=>new w.SubmitEvent('submit',{bubbles:true,cancelable:true});
  assert.equal(f.dispatchEvent(event()),true);assert.equal(f.dispatchEvent(event()),false);
  w.dispatchEvent(new w.PageTransitionEvent('pageshow',{persisted:true}));
  assert.equal(buttons[0].disabled,true);assert.equal(buttons[1].disabled,false);assert.equal(f.dispatchEvent(event()),true);dom.window.close();
 });
 await test('externally associated submitter is restored',async()=>{
  const {dom}=await make('<form id="target"></form><button form="target" name="action" value="reject"><i></i> رفض</button>');await wait(5);
  const w=dom.window,f=w.document.querySelector('form'),b=w.document.querySelector('button');
  assert.equal(f.dispatchEvent(new w.SubmitEvent('submit',{bubbles:true,cancelable:true,submitter:b})),true);
  assert.equal(new w.FormData(f,b).get('action'),'reject');
  w.dispatchEvent(new w.PageTransitionEvent('pageshow',{persisted:true}));assert.ok(b.querySelector('i'));assert.equal(b.textContent,' رفض');dom.window.close();
 });
 await test('responsive table gains column labels',async()=>{
  const {dom}=await make('<div class="table-responsive"><table><thead><tr><th>الاسم</th><th>الحالة</th></tr></thead><tbody><tr><td>طالب</td><td>نشط</td></tr></tbody></table></div>');
  const cells=dom.window.document.querySelectorAll('td');assert.equal(cells[0].dataset.label,'الاسم');assert.equal(cells[1].dataset.label,'الحالة');dom.window.close();
 });
 process.exitCode=failed?1:0;
})();
