// Isolated JavaScript with stubs; not an HTTP server or browser test.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const text = fs.readFileSync('Ehtiya/htdocs/assets/app.js', 'utf8');
const source = text.slice(text.indexOf('// Mark displayed conversation as read'));
async function exercise(fetchImpl) {
  const message = {hidden: true}, retry = {hidden: true};
  const form = {querySelector(selector) { return selector.includes('error') ? message : retry; }};
  const calls = [];
  const document = {querySelectorAll() { return [form]; }};
  const fetch = (url, options) => { calls.push({url, options}); return fetchImpl(); };
  vm.runInNewContext(source, {document, fetch, FormData: class {constructor(f) {this.form=f;}}, window: {location: {href:'https://local.invalid/chat/view.php?id=1'}}});
  await new Promise(resolve => setImmediate(resolve));
  return {message, retry, calls, form};
}
test('204 leaves retry hidden, and request is same-origin POST', async () => {
  const x = await exercise(() => Promise.resolve({status: 204}));
  assert.equal(x.message.hidden, true); assert.equal(x.retry.hidden, true);
  assert.equal(x.calls.length, 1); assert.equal(x.calls[0].options.method, 'POST');
  assert.equal(x.calls[0].options.credentials, 'same-origin'); assert.equal(x.calls[0].options.body.form, x.form);
});
test('redirect or non-204 enables retry', async () => {
  for (const status of [200, 403, 500]) {
    const x = await exercise(() => Promise.resolve({status}));
    assert.equal(x.message.hidden, false); assert.equal(x.retry.hidden, false);
  }
});
test('network failure enables retry', async () => {
  const x = await exercise(() => Promise.reject(new Error('network')));
  assert.equal(x.message.hidden, false); assert.equal(x.retry.hidden, false);
});
