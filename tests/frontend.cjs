const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '..', 'index.php'), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
  .replace(/<\?php[\s\S]*?\?>/g, 'true');
new vm.Script(script);

class Element {
  constructor() {
    this.style = {}; this.children = []; this.events = {}; this.value = '';
    this.disabled = false; this.checked = false; this.textContent = '';
  }
  set innerHTML(value) { this.children = []; this.html = value; }
  addEventListener(name, handler) { this.events[name] = handler; }
  appendChild(child) { this.children.push(child); }
  getBoundingClientRect() { return {left: 20, top: 20, bottom: 40, width: 200, height: 40}; }
  getAttribute() { return 'FIXTURE TOOLTIP'; }
}

function harness() {
  const elements = {};
  const element = id => elements[id] ??= new Element();
  const fields = {};
  for (const name of ['days', 'limit', 'dry_run', 'backup_confirmed', 'contact_order_scope', 'anonymize_orders',
    'anonymize_contacts', 'anonymize_order_history', 'confirmation_phrase', 'csrf_token', 'wipe_comments', 'keep_geo']) {
    fields[name] = element(name);
    fields[name].name = name;
  }
  Object.assign(fields.days, {value: '365'});
  fields.limit.value = '1'; fields.contact_order_scope.value = 'all';
  fields.anonymize_orders.value = '1'; fields.backup_confirmed.value = '1';
  fields.confirmation_phrase.value = 'ANONYMIZE'; fields.csrf_token.value = 'fixture-csrf';
  const form = element('mainForm');
  form.elements = [...Object.values(fields), element('runBtn'), element('stopBtn'), element('previewBtn')];
  form.checkValidity = () => true;
  form.querySelector = () => fields.confirmation_phrase;
  form.querySelectorAll = () => element('keysList').children.flatMap(label => label.children).filter(node => node.checked);
  const label = new Element();
  const body = new Element();
  const timers = new Map();
  const requests = [];
  let respond = () => { throw new Error('Unexpected request'); };
  const context = {
    Date, URLSearchParams, Uint8Array,
    FormData: class { get(name) { const field = fields[name]; return field && !field.disabled ? field.value || null : null; } },
    document: {getElementById: element, body, createElement: () => new Element(),
      createTextNode: text => ({textContent: text}), querySelectorAll: () => [label], addEventListener() {}},
    window: {location: {pathname: '/test/tools/depersonalizer/index.php'}, innerWidth: 1024, innerHeight: 768,
      crypto: {getRandomValues: bytes => bytes.fill(7)},
      setTimeout: (callback, delay) => { const id = timers.size + 1; timers.set(id, {callback, delay}); return id; },
      clearTimeout: id => timers.delete(id)},
    fetch: async (_url, options) => {
      const request = Object.fromEntries(new URLSearchParams(options.body));
      requests.push(request);
      const data = await respond(request);
      return {ok: true, json: async () => ({ok: true, data})};
    }
  };
  vm.createContext(context);
  vm.runInContext(script.replace('    setupTooltips();',
    '    globalThis.test = {collectFormState, renderKeys, compactOrderIdentifiers, setBusy};\n    setupTooltips();'), context);
  return {fields, element, form, label, body, timers, requests, test: context.test, respond: callback => { respond = callback; }};
}

(async () => {
  const h = harness();
  for (const scope of ['processed', 'unprocessed', 'all']) {
    h.fields.anonymize_contacts.value = '1';
    h.fields.anonymize_orders.value = '';
    h.fields.contact_order_scope.value = scope;
    assert.equal(h.test.collectFormState().contact_catchup_only, '1');
    assert.equal(h.test.collectFormState().anonymize_orders, '0');
  }
  h.test.renderKeys(['email', 'comment', 'customer_comment']);
  assert.deepEqual(h.element('keysList').children.map(label => label.children[0].checked), [true, false, false]);
  h.element('keysList').children[0].children[0].checked = false;
  h.test.renderKeys(['email', 'comment']);
  assert.deepEqual(h.element('keysList').children.map(label => label.children[0].checked), [false, false]);
  h.fields.anonymize_contacts.disabled = true;
  h.test.setBusy(true);
  assert.equal(h.fields.days.disabled, true);
  h.test.setBusy(false);
  assert.equal(h.fields.days.disabled, false);
  assert.equal(h.fields.anonymize_contacts.disabled, true);
  assert.equal(h.test.compactOrderIdentifiers(['#1', '#2', '#4']), '#1-#2, #4');
  assert.equal(h.test.compactOrderIdentifiers(['#001', '#002']), '#001, #002');
  assert.equal(h.test.compactOrderIdentifiers(['#9007199254740993', '#2']), '#9007199254740993, #2');
  h.label.events.mouseenter();
  const timer = [...h.timers.values()][0];
  assert.equal(timer.delay, 2000);
  assert.notEqual(h.body.children[0].style.display, 'block');
  timer.callback();
  assert.equal(h.body.children[0].style.display, 'block');
  h.label.events.mouseleave();
  assert.equal(h.body.children[0].style.display, 'none');
  assert.equal(h.timers.size, 0);
  assert.match(h.element('cutoffClientText').textContent, /\d{2}\.\d{2}\.\d{4}/);
  console.log('PASS selection, independent modes, disabled controls, ranges, delayed tooltips and cutoff');

  for (const dry of [false, true]) {
    const run = harness();
    run.fields.dry_run.value = dry ? '1' : '';
    run.test.renderKeys(['email']);
    let runId;
    run.respond(request => {
      assert.equal(run.fields.days.disabled, true);
      if (request.action === 'start') {
        assert.equal(request.days, '365');
        assert.equal(request.dry_run, dry ? '1' : '0');
        assert.equal(request.confirmation_phrase, 'ANONYMIZE');
        assert.equal(request['include_keys[]'], 'email');
        runId = request.run_id;
        run.fields.days.value = '1';
        return {run_id: runId, cutoff: '2020-01-01 00:00:00', total: 2, next_batch: 0};
      }
      assert.equal(request.action, 'run');
      assert.equal(request.run_id, runId);
      assert.deepEqual(Object.keys(request).sort(), ['action', 'batch', 'csrf_token', 'run_id']);
      assert.equal(run.fields.confirmation_phrase.value, dry ? 'ANONYMIZE' : '');
      const batch = Number(request.batch);
      return {next_batch: batch + 1, done: batch === 1, progress: batch + 1, total: 2,
        processed_order_numbers: ['#' + (batch + 1)], processed_contacts: [], skipped_contacts: [],
        cursor: batch + 1, contact_cursor: 0, history_cursor: 0};
    });
    await run.element('runBtn').events.click();
    assert.equal(run.requests.length, 3);
    assert.equal(run.fields.days.disabled, false);
    assert.equal(run.fields.backup_confirmed.value, '1');
    assert.equal(run.fields.confirmation_phrase.value, dry ? 'ANONYMIZE' : '');
    assert.match(run.element('log').textContent, /Номера: #1-#2/);
  }
  console.log('PASS immutable start/batch protocol and confirmation clearing');

  const preview = harness();
  preview.fields.days.value = '900';
  preview.respond(request => {
    assert.equal(request.action, 'preview');
    assert.equal(request.days, '900');
    return {cutoff: '2020-01-02 03:04:05', candidate_keys: ['email'], total_orders: 1};
  });
  await preview.element('previewBtn').events.click();
  assert.match(preview.element('previewInfo').textContent, /2020-01-02 03:04:05/);
  const failure = harness();
  failure.respond(request => {
    if (request.action === 'start') throw new Error('Fixture network loss after start');
    assert.equal(request.action, 'stop');
    return {};
  });
  await failure.element('runBtn').events.click();
  assert.equal(failure.fields.confirmation_phrase.value, '');
  assert.equal(failure.requests[0].run_id, failure.requests[1].run_id);
  assert.equal(failure.fields.backup_confirmed.value, '1');
  assert.equal(failure.fields.days.disabled, false);
  console.log('PASS preview serialization and lost-start-response cleanup');
})().catch(error => { console.error(error); process.exitCode = 1; });
