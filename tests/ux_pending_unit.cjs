// Node-only event stubs exercise the existing submission guard; not a browser or DOM integration test.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('Ehtiya/htdocs/assets/app.js', 'utf8');
const confirmCode = source.slice(0, source.indexOf("document.querySelectorAll('input[type=file][multiple]')"));
const pendingCode = source.slice(source.indexOf('const pendingForms = new Map();'), source.indexOf('// Accessible mobile navigation drawer'));
class Target {
  constructor() { this.listeners = {}; this.dataset = {}; }
  addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }
  dispatch(type, event) { for (const fn of this.listeners[type] || []) fn(event); return !event.defaultPrevented; }
}
class Button extends Target {
  constructor(form) { super(); this.form = form; this.attrs = {}; this.childNodes = ['حفظ']; this.textContent = 'حفظ'; }
  getAttribute(name) { return this.attrs[name] ?? null; }
  setAttribute(name, value) { this.attrs[name] = value; }
  removeAttribute(name) { delete this.attrs[name]; }
  replaceChildren(...nodes) { this.childNodes = nodes; this.textContent = nodes.join(''); }
  closest() { return null; }
}
function setup(withFormConfirm = false, withButtonConfirm = false) {
  const form = new Target(), button = new Button(form), win = new Target();
  if (withFormConfirm) form.dataset.confirm = 'تأكيد';
  if (withButtonConfirm) button.dataset.confirm = 'تأكيد';
  let confirmations = 0, confirmed = true;
  win.confirm = () => { confirmations++; return confirmed; };
  const document = {querySelectorAll(selector) {
    if (selector === 'form[data-confirm]') return withFormConfirm ? [form] : [];
    if (selector === 'button[data-confirm],a[data-confirm]') return withButtonConfirm ? [button] : [];
    if (selector === 'form:not(.offer-review-form)') return [form];
    return [];
  }};
  vm.runInNewContext(confirmCode + pendingCode, {document, window: win, HTMLButtonElement: Button, queueMicrotask});
  const event = () => ({submitter: button, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }});
  return {form, button, win, event, get confirmations() {return confirmations;}, set confirmed(v) {confirmed = v;}};
}
test('confirmed form blocks a second submit without another prompt and restores on pageshow', () => {
  const x = setup(true); assert.equal(x.form.dispatch('submit', x.event()), true);
  assert.equal(x.button.getAttribute('aria-busy'), 'true');
  assert.equal(x.form.dispatch('submit', x.event()), false); assert.equal(x.confirmations, 1);
  x.win.dispatch('pageshow', {}); assert.equal(x.button.getAttribute('aria-busy'), null);
  assert.equal(x.button.textContent, 'حفظ'); assert.equal(x.form.dispatch('submit', x.event()), true);
});
test('cancelled confirmation allows retry', () => {
  const x = setup(true); x.confirmed = false; assert.equal(x.form.dispatch('submit', x.event()), false);
  assert.equal(x.button.getAttribute('aria-busy'), null); x.confirmed = true;
  assert.equal(x.form.dispatch('submit', x.event()), true);
});
test('pending form blocks a second button confirmation', () => {
  const x = setup(false, true); assert.equal(x.button.dispatch('click', x.event()), true);
  assert.equal(x.form.dispatch('submit', x.event()), true);
  assert.equal(x.button.dispatch('click', x.event()), false); assert.equal(x.confirmations, 1);
});
test('later cancellation restores the pending state', async () => {
  const x = setup(); x.form.addEventListener('submit', e => e.preventDefault());
  assert.equal(x.form.dispatch('submit', x.event()), false); await Promise.resolve();
  assert.equal(x.button.getAttribute('aria-busy'), null); assert.equal(x.button.textContent, 'حفظ');
});
