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
 await test('responsive table gains column labels',async()=>{
  const {dom}=await make('<div class="table-responsive"><table><thead><tr><th>الاسم</th><th>الحالة</th></tr></thead><tbody><tr><td>طالب</td><td>نشط</td></tr></tbody></table></div>');
  const cells=dom.window.document.querySelectorAll('td');assert.equal(cells[0].dataset.label,'الاسم');assert.equal(cells[1].dataset.label,'الحالة');dom.window.close();
 });
 process.exitCode=failed?1:0;
})();
