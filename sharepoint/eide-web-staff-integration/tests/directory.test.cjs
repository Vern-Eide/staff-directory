const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const ts = require('typescript');
const { JSDOM } = require('jsdom');
const vm = require('node:vm');
function load(file, globals = {}) {
  const code = ts.transpileModule(fs.readFileSync(file, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2018 }
  }).outputText;
  const context = { exports: {}, ...globals };
  vm.runInNewContext(code, context);
  return context.exports;
}
const apiFile = 'src/webparts/eideWebStaffIntegration/components/staffApi.ts';
test('only approved listing URLs; remove conflicting modes and preserve existing options', () => {
  const { listingEndpoint } = load(apiFile, { URL });
  const url = listingEndpoint('https://staff.eideweb.com/simple-listing/birthdays/?api=fixture&embed_version=2&debug=1', false);
  assert.equal(url.searchParams.get('type'), 'sharepoint');
  assert.equal(url.searchParams.get('api'), 'fixture');
  assert.equal(url.searchParams.get('title'), '0');
  assert.equal(url.searchParams.get('embed_version'), '2');
  assert.equal(url.searchParams.has('debug'), false);
  for (const bad of ['', 'http://staff.eideweb.com/listing/a/', 'https://evil.example/listing/a/', 'https://staff.eideweb.com/wp-admin/', 'https://user:secret@staff.eideweb.com/listing/a/']) {
    assert.throws(() => listingEndpoint(bad, true));
  }
});
test('reject HTML/login pages and invalid JSON; do not retry 403', async () => {
  let calls = 0;
  let response = { ok: false, status: 403 };
  const { requestPayload } = load(apiFile, { URL, fetch: async () => { calls++; return response; } });
  const signal = new AbortController().signal;
  const run = () => requestPayload('https://staff.eideweb.com/listing/team/', true, signal);
  await assert.rejects(run, /HTTP 403/);
  assert.equal(calls, 1);
  response = { ok: true, headers: { get: () => 'text/html' } };
  await assert.rejects(run, /did not return JSON/);
  response = { ok: true, headers: { get: () => 'application/json' }, json: async () => ({ schemaVersion: 9 }) };
  await assert.rejects(run, /unsupported response/);
});
test('retry temporary failure then accept JSON without cookies or identity disclosure', async () => {
  let calls = 0;
  const payload = { schemaVersion: 1, listId: 2, listType: 'simple-listing', html: '', css: '' };
  const { requestPayload } = load(apiFile, { URL, window: { setTimeout: callback => callback() }, fetch: async (_, options) => {
    assert.equal(options.credentials, 'omit');
    assert.equal(options.method, 'GET');
    assert.equal(options.cache, 'no-store');
    calls++;
    return calls === 1 ? { status: 503 } : { ok: true, headers: { get: () => 'application/json' }, json: async () => payload };
  } });
  assert.equal(await requestPayload('https://staff.eideweb.com/listing/team/', true, new AbortController().signal), payload);
  assert.equal(calls, 2);
});
test('shared runtime isolates instances, filters, resets, expands simple lists, disposes overlays', () => {
  const dom = new JSDOM('<div id="one"></div><div id="two"></div>', { url: 'https://example.sharepoint.com' });
  const { window } = dom;
  window.HTMLDialogElement.prototype.showModal = function () { this.open = true; };
  window.HTMLDialogElement.prototype.close = function () { this.open = false; };
  let listeners = 0;
  window.matchMedia = () => ({ matches: true, addEventListener: () => listeners++, removeEventListener: () => listeners-- });
  const { createDirectoryRuntime } = load('src/runtime/directory.ts', { window, document: window.document, MutationObserver: window.MutationObserver });
  const runtime = createDirectoryRuntime();
  function fixture(id) {
    const root = window.document.getElementById(id).attachShadow({ mode: 'open' });
    root.innerHTML = `<style data-ve-staff></style><div id="veStaffList">
    <button data-ve-staff-filters-open>Filters</button><div class="ve-staff-filters"><input id="employeenamesearch"><button id="resetFilterBtn">Reset</button></div>
    <div class="employee-list"><div class="ve-row"><div class="employee-tile" data-employee-name="Alice"></div><div class="employee-tile" data-employee-name="Bob"></div></div></div>
    <img class="ve-lazy" data-src="https://staff.eideweb.com/photo.jpg"><div class="viewMoreWrapper"><button id="viewMoreBtn">More</button></div></div>`;
    runtime.initialize(root);
    return root;
  }
  const one = fixture('one'); const two = fixture('two');
  const photo = one.querySelector('img');
  assert.equal(photo.src, 'https://staff.eideweb.com/photo.jpg');
  photo.dispatchEvent(new window.Event('load'));
  assert.ok(photo.classList.contains('ve-lazy-loaded'));
  const input = one.querySelector('input'); input.value = 'alice'; input.dispatchEvent(new window.Event('input', { bubbles: true }));
  assert.equal(one.querySelectorAll('.employee-tile.ve-hidden').length, 1);
  assert.equal(two.querySelectorAll('.employee-tile.ve-hidden').length, 0);
  assert.equal(one.querySelectorAll('.ve-filter-pill').length, 1);
  one.querySelector('.ve-filter-pill').click();
  assert.equal(input.value, '');
  assert.equal(one.querySelectorAll('.employee-tile.ve-hidden').length, 0);
  input.value = 'bob'; input.dispatchEvent(new window.Event('input', { bubbles: true }));
  one.querySelector('#resetFilterBtn').click();
  assert.equal(one.querySelectorAll('.employee-tile.ve-hidden').length, 0);
  one.querySelector('.employee-tile').classList.add('ve-hidden');
  one.querySelector('#viewMoreBtn').click();
  assert.equal(one.querySelectorAll('.employee-tile.ve-hidden').length, 0);
  assert.ok(one.querySelector('.viewMoreWrapper.ve-hidden'));
  one.querySelector('[data-ve-staff-filters-open]').click();
  assert.equal(window.document.querySelectorAll('.ve-staff-filter-portal').length, 1);
  runtime.dispose(one); runtime.dispose(two);
  assert.equal(listeners, 0);
  assert.equal(window.document.querySelectorAll('.ve-staff-filter-portal').length, 0);
  dom.window.close();
});
test('contact details toggle independently and preserve the on-page suggestion button', () => {
  const dom = new JSDOM('<div id="host"></div>');
  const root = dom.window.document.getElementById('host').attachShadow({ mode: 'open' });
  root.innerHTML = '<button data-toggle="collapse" data-target="#collapse1"></button><div id="collapse1" class="collapse"></div><button class="ve-suggest-edit-btn">Suggest Edit</button>';
  const { bindCardActions } = load('src/webparts/eideWebStaffIntegration/components/cardActions.ts', { document: dom.window.document, URL });
  const dispose = bindCardActions(root, 'https://staff.eideweb.com/listing/team/?type=embed');
  const button = root.querySelector('button');
  button.click();
  assert.equal(button.getAttribute('aria-expanded'), 'true');
  assert.ok(root.getElementById('collapse1').classList.contains('veshow'));
  button.click();
  assert.equal(button.getAttribute('aria-expanded'), 'false');
  assert.equal(root.querySelector('a'), null);
  assert.ok(root.querySelector('.ve-suggest-edit-btn'));
  dispose(); dom.window.close();
});

test('list discovery follows pages, sends no credentials, and validates every URL', async () => {
  let calls = 0;
  const list = { id: 1, title: 'Team', url: 'https://staff.eideweb.com/listing/team/', listType: 'listing' };
  let body;
  const { requestLists } = load(apiFile, { URL, fetch: async (url, options) => {
    calls++;
    assert.equal(options.credentials, 'omit');
    assert.equal(options.redirect, 'error');
    assert.equal(new URL(url).searchParams.get('ve_staff_lists'), '1');
    return { ok: true, headers: { get: () => 'application/json' }, json: async () => body || ({schemaVersion: 1, lists: [list], nextPage: calls === 1 ? 2 : null}) };
  } });
  const signal = new AbortController().signal;
  assert.equal((await requestLists('listing', signal)).length, 2);
  assert.equal(calls, 2);
  for (const url of ['https://evil.example/listing/team/', 'https://staff.eideweb.com/simple-listing/team/']) {
    body = {schemaVersion: 1, lists: [{...list, url}], nextPage: null};
    await assert.rejects(() => requestLists('listing', signal));
  }
  body = {schemaVersion: 1, lists: [], nextPage: 1};
  await assert.rejects(() => requestLists('listing', signal), /Invalid list catalog/);
  body = {schemaVersion: 1, lists: [], nextPage: null};
  assert.equal((await requestLists('simple-listing', signal)).length, 0);
  await assert.rejects(() => requestLists('staff', signal), /valid list type/);
});

test('list discovery reports access denial and undeployed endpoint', async () => {
  let response = { ok: false, status: 403 };
  const { requestLists } = load(apiFile, { URL, fetch: async () => response });
  const run = () => requestLists('listing', new AbortController().signal);
  await assert.rejects(run, /HTTP 403/);
  response = {ok: true, headers: {get: () => 'text/html'}};
  await assert.rejects(run, /Deploy the updated WordPress plugin/);
});

test('explicit embed key survives list changes and cannot be sent to another origin', () => {
  const { withEmbedApiKey } = load(apiFile, { URL });
  for (const path of ['listing/team/', 'simple-listing/birthdays/']) {
    const url = new URL(withEmbedApiKey('https://staff.eideweb.com/' + path, ' fixture-key '));
    assert.equal(url.searchParams.get('api'), 'fixture-key');
    assert.equal(url.searchParams.get('type'), 'sharepoint');
  }
  const legacy = 'https://staff.eideweb.com/listing/team/?api=old&group=TRUE';
  assert.equal(withEmbedApiKey(legacy, ''), legacy);
  const updated = new URL(withEmbedApiKey(legacy, 'new'));
  assert.equal(updated.searchParams.get('api'), 'new');
  assert.equal(updated.searchParams.get('group'), 'TRUE');
  assert.throws(() => withEmbedApiKey('https://evil.example/listing/team/', 'fixture-key'));
});

test('suggestion dialog populates staff, submits once and stays on page', async () => {
  const dom = new JSDOM(`<div class="ve-staff-embed"><div id="veStaffList" data-list-id="12"><button class="ve-suggest-edit-btn" data-staff-post-id="34" data-employee-name="Alice" data-current-title="Advisor">Suggest</button></div>
  <div class="ve-suggest-edit-modal"><div class="vemodal-content"><span data-suggest-edit-employee></span><button data-suggest-edit-close>Close</button><form data-submit-url="https://staff.eideweb.com/wp-admin/admin-ajax.php" data-submit-nonce="fixture">
  <input name="staff_post_id"><input name="employee_name"><input name="employee_name_display"><input name="requester_name"><input name="requester_email"><input name="internal_listing" value="1"><input data-current-value="title"><input name="current_title"><input name="suggested_title" value="Manager"><div class="ve-suggest-edit-status"></div><button type="submit">Send</button></form></div></div></div>`, {url:'https://verneide.sharepoint.com'});
  const {window} = dom;
  window.HTMLDialogElement.prototype.showModal = function () { this.open = true; };
  window.HTMLDialogElement.prototype.close = function () { this.open = false; };
  let calls = 0;
  const {createDirectoryRuntime} = load('src/runtime/directory.ts', {window, document:window.document, MutationObserver:window.MutationObserver, URL, URLSearchParams, FormData:window.FormData, AbortController, fetch:async (url, options) => {
    calls++;
    assert.equal(options.body.get('staff_post_id'), '34');
    assert.equal(options.body.get('list_id'), '12');
    assert.equal(options.body.get('current_title'), 'Advisor');
    assert.equal(options.body.get('nonce'), 'fixture');
    return {ok:true, json:async()=>({success:true,data:{message:'Submitted for review.'}})};
  }});
  const runtime = createDirectoryRuntime(); runtime.initialize(window.document);
  const root = window.document.querySelector('#veStaffList'); root.querySelector('button').click();
  const dialog = root.querySelector('dialog'); assert.ok(dialog.open);
  const form = dialog.querySelector('form');
  assert.equal(form.elements.employee_name.value, 'Alice');
  form.dispatchEvent(new window.Event('submit', {cancelable:true}));
  await new Promise(resolve=>setImmediate(resolve));
  assert.equal(form.querySelector('.ve-suggest-edit-status').textContent, 'Submitted for review.');
  form.dispatchEvent(new window.Event('submit', {cancelable:true}));
  assert.equal(calls, 1);
  dialog.querySelector('[data-suggest-edit-close]').click();
  assert.equal(root.querySelector('dialog'), null);
  runtime.dispose(root); window.close();
});

test('quick filters stay inline, internal filters combine, compact layout preserves selection', () => {
  const dom = new JSDOM('<div id="host"></div>', {url:'https://verneide.sharepoint.com'});
  const {window} = dom;
  window.HTMLDialogElement.prototype.showModal = function () { this.open = true; };
  window.HTMLDialogElement.prototype.close = function () { this.open = false; };
  const root = window.document.getElementById('host').attachShadow({mode:'open'});
  const card = (name,title,phone,email) => `<div class="employee-tile" data-employee-name="${name}" data-ext="123"><span class="employee-title">${title}</span><div class="employee-internal-details"><div class="ve-contact-wrap"><span class="ve-block"><span class="ve-bolder">Email: </span><a href="mailto:${email}">${email}</a></span><a href="tel:${phone}">${phone}</a></div></div></div>`;
  root.innerHTML = `<style data-ve-staff></style><div id="veStaffList"><button data-ve-staff-filters-open>Filters</button><div class="ve-staff-filters"><div id="employeeHeadingWrapper"><div class="ve-row"><div class="employeeFilterWrapper"><input id="employeenamesearch"></div><div class="extFilterWrapper"><input id="employeeextsearch"></div></div></div><button id="resetFilterBtn">Reset</button><button data-ve-staff-filters-close>Close</button></div><div class="employee-list"><div class="ve-row">${card('Alice','Advisor','605-555-1234','alice@example.com')}${card('Bob','Manager','6055559999','bob@example.com')}</div></div></div>`;
  const {createDirectoryRuntime} = load('src/runtime/directory.ts', {window, document:window.document, MutationObserver:window.MutationObserver});
  const runtime=createDirectoryRuntime(); runtime.initialize(root);
  assert.ok(root.querySelector('.ve-directory-toolbar #employeenamesearch'));
  assert.ok(root.querySelector('.ve-directory-toolbar #employeeextsearch'));
  assert.equal(root.querySelector('.ve-staff-filters #employeenamesearch'),null);
  assert.equal(root.querySelectorAll('#employeetitleselect option').length,2);
  assert.ok(root.querySelector('.ve-detail-label').textContent === 'Email');
  assert.ok(root.querySelector('.ve-detail-icon svg'));
  const change = (id,value) => {const field=root.querySelector('#'+id); field.value=value; field.dispatchEvent(new window.Event('input',{bubbles:true}));};
  change('employeephonesearch','(605) 555-1234');
  change('employeeemailsearch','ALICE@');
  change('employeetitleselect','Advisor');
  assert.equal(root.querySelectorAll('.employee-tile:not(.ve-hidden)').length,1);
  assert.equal(root.querySelectorAll('.ve-filter-pill').length,3);
  root.querySelectorAll('.ve-layout-options button')[1].click();
  assert.ok(root.querySelector('.ve-layout-compact'));
  assert.equal(root.querySelectorAll('.employee-tile:not(.ve-hidden)').length,1);
  root.querySelector('[data-ve-staff-filters-open]').click();
  const portal=window.document.querySelector('dialog'); assert.ok(portal.open);
  assert.ok(root.querySelector('#employeeextsearch'));
  const panel=portal.querySelector('div').shadowRoot;
  panel.querySelector('[data-ve-staff-filters-close]').click();
  assert.equal(root.querySelector('#employeephonesearch').value,'(605) 555-1234');
  root.querySelector('[data-remove-filter="employeephonesearch"]').click();
  assert.equal(root.querySelector('#employeephonesearch').value,'');
  root.querySelector('#resetFilterBtn').click();
  assert.equal(root.querySelectorAll('.employee-tile.ve-hidden').length,0);
  runtime.dispose(root); window.close();
});

test('public lists never gain internal search fields or contact attributes', () => {
  const dom=new JSDOM('<div id="veStaffList"><div class="employee-list"><div class="employee-tile"><span class="employee-title">Advisor</span><a href="mailto:public@example.com">Contact</a></div></div></div>');
  const {window}=dom;
  const {createDirectoryRuntime}=load('src/runtime/directory.ts',{window,document:window.document,MutationObserver:window.MutationObserver});
  const runtime=createDirectoryRuntime(); runtime.initialize(window.document);
  assert.equal(window.document.querySelector('#employeeemailsearch, #employeephonesearch, #employeetitleselect, [data-search-email]'),null);
  assert.ok(window.document.querySelector('.ve-layout-options'));
  runtime.dispose(window.document);window.close();
});

test('compact summaries preserve contact links and the original expandable details', () => {
  const dom = new JSDOM(`<div id="veStaffList"><div class="employee-list"><div class="employee-tile"><div class="employee-details"><h3 class="employee-name">Alice</h3><span class="employee-title">Advisor</span><span class="employee-location">Main Office</span></div><div class="employee-internal-details"><button data-bs-toggle="collapse" data-bs-target="#contact">View More Info</button><div id="contact" class="collapse"><div class="ve-contact-wrap"><span class="ve-block"><span class="ve-bolder">Ext: </span>123</span><span class="ve-block"><span class="ve-bolder">Email: </span><a href="mailto:alice@example.com">alice@example.com</a></span><span class="ve-block"><span class="ve-bolder">Direct: </span><a href="tel:6055551234">605-555-1234</a></span></div></div></div></div></div></div>`);
  const {window}=dom;
  const {createDirectoryRuntime}=load('src/runtime/directory.ts',{window,document:window.document,MutationObserver:window.MutationObserver});
  const runtime=createDirectoryRuntime(); runtime.initialize(window.document);
  const summary=window.document.querySelector('.ve-compact-summary');
  assert.equal(summary.querySelector('.employee-location').textContent,'Main Office');
  assert.equal(summary.querySelector('.ve-summary-extension').textContent,'Ext. 123');
  assert.equal(summary.querySelector('a').getAttribute('href'),'mailto:alice@example.com');
  assert.equal(window.document.querySelectorAll('.ve-contact-values .ve-detail-row').length,3);
  assert.equal(window.document.querySelector('.ve-contact-values a[href^="mailto:"]').textContent,'alice@example.com');
  const button=window.document.querySelector('[data-bs-toggle="collapse"]');
  assert.equal(button.getAttribute('data-bs-target'),'#contact');
  assert.equal(button.getAttribute('aria-label'),'View more info for Alice');
  assert.ok(button.querySelector('.ve-contact-toggle-icon svg'));
  runtime.dispose(window.document);window.close();
});

test('compact widths use a separate variable from standard column breakpoints', () => {
  const css=fs.readFileSync('../../assets/themes/vern-eide-staff/inc/assets/css/listing.css','utf8');
  const rules=[...css.matchAll(/#veStaffList\.ve-layout-compact \.employee-list \.employee-tile\s*\{([^}]+)\}/g)].map(match=>match[1]);
  assert.equal(rules.length,3);
  assert.ok(rules.every(rule=>!rule.includes('--ve-staff-card-width')));
  assert.ok(rules[0].includes('var(--ve-compact-card-width)'));
  assert.ok(rules[1].includes('--ve-compact-card-width: 50%'));
  assert.ok(rules[2].includes('--ve-compact-card-width: 33.333333%'));
});

test('actual Select2 supports OR within a field, AND across fields, pills and reset in Shadow DOM', () => {
  const dom = new JSDOM('<div id="host"></div>', {url:'https://verneide.sharepoint.com'});
  const {window}=dom; window.HTMLDialogElement.prototype.showModal=function(){this.open=true;}; window.HTMLDialogElement.prototype.close=function(){this.open=false;};
  const root=window.document.getElementById('host').attachShadow({mode:'open'});
  root.innerHTML=`<div id="veStaffList"><button data-ve-staff-filters-open>Filters</button><div class="ve-staff-filters"><select id="locationfilterselect"><option value="all">All locations</option><option value="001">North</option><option value="002">South</option><option value="003">West</option></select><select id="departmentfilterselect"><option value="all">All departments</option><option value="Sales">Sales</option><option value="Service">Service</option></select><button id="resetFilterBtn">Reset</button><button data-ve-staff-filters-close>Close</button></div><div class="employee-list"><div class="ve-row"><div class="employee-tile" data-loc='["001"]' data-dept="Sales"></div><div class="employee-tile" data-loc='["002"]' data-dept="Service"></div><div class="employee-tile" data-loc='["003"]' data-dept="Sales"></div></div></div></div>`;
  const {createDirectoryRuntime}=load('src/runtime/directory.ts',{window,document:window.document,MutationObserver:window.MutationObserver});
  const runtime=createDirectoryRuntime(); runtime.initialize(root); const $=runtime.jquery;
  assert.equal(window.jQuery,undefined);
  const location=root.querySelector('#locationfilterselect'); const department=root.querySelector('#departmentfilterselect');
  assert.ok($(location).data('select2')); assert.ok(location.multiple);
  $(location).val(['001','002']).trigger('change');
  assert.equal(root.querySelectorAll('.employee-tile:not(.ve-hidden)').length,2);
  $(department).val(['Sales']).trigger('change');
  assert.equal(root.querySelectorAll('.employee-tile:not(.ve-hidden)').length,1);
  root.querySelector('[data-remove-value="001"]').click();
  assert.deepEqual(Array.from(location.selectedOptions, option=>option.value),['002']);
  assert.equal(root.querySelectorAll('.employee-tile:not(.ve-hidden)').length,0);
  root.querySelector('[data-ve-staff-filters-open]').click();
  $(location).select2('open');
  assert.ok(location.closest('.ve-staff-filters').querySelector('.select2-results'));
  location.closest('.ve-staff-filters').querySelector('#resetFilterBtn').click();
  assert.equal(location.selectedOptions.length,0); assert.equal(department.selectedOptions.length,0);
  assert.equal(root.querySelectorAll('.employee-tile:not(.ve-hidden)').length,3);
  runtime.dispose(root); assert.equal($(location).data('select2'),undefined); window.close();
});

test('PDF view exports visible staff with only photo, name, title and location', () => {
  const dom=new JSDOM('<div id="veStaffList"></div>'); const {window}=dom;
  const root=window.document.querySelector('#veStaffList');
  root.innerHTML='<div class="ve-directory-toolbar">Secret filter text</div><div class="ve-filter-pills">Selected location</div>';
  for(let i=0;i<7;i++) root.insertAdjacentHTML('beforeend',`<div class="employee-tile ${i===6?'ve-hidden':''}"><div class="employee-details"><h3 class="employee-name">Employee ${i}</h3><span class="employee-title">Advisor</span><span class="employee-location">Office</span></div><div class="ve-contact-wrap"><div class="ve-detail-row"><span class="ve-detail-label">Email</span><span class="ve-detail-value">person${i}@example.com</span></div><button>Suggest an Edit</button><a href="https://example.com/?api=secret">Download vCard</a></div><button>View More Info</button></div>`);
  const {createDirectoryRuntime}=load('src/runtime/directory.ts',{window,document:window.document,MutationObserver:window.MutationObserver});
  const runtime=createDirectoryRuntime();
  for(const [orientation,columns] of [['portrait',3],['landscape',5]]) {
    const doc=window.document.implementation.createHTMLDocument('');
    assert.equal(runtime.buildPrintDocument(root,doc,orientation),6);
    assert.equal(doc.querySelectorAll('.print-row').length,2);
    assert.equal(doc.querySelector('.print-row').children.length,columns);
    assert.equal(doc.querySelectorAll('.print-card:not(.empty)').length,6);
    assert.ok(doc.querySelector('style').textContent.includes('size:Letter '+orientation));
    assert.equal(doc.body.textContent.includes('person0@example.com'), false);
    assert.ok(doc.body.textContent.includes('Advisor'));
    assert.ok(doc.body.textContent.includes('Office'));
    for(const omitted of ['Employee 6','Secret filter text','Selected location','Suggest an Edit','Download vCard','View More Info','api=secret']) assert.equal(doc.body.innerHTML.includes(omitted),false);
  }
  window.close();
});

test('PDF departments use visible counts for breaks, repeated headers and fixed card columns', () => {
  const dom=new JSDOM('<div id="veStaffList"></div>');const {window}=dom;const root=window.document.querySelector('#veStaffList');
  for(const [department,count] of [['Small A',1],['Small B',3],['Large',4],['Filtered',5]]) {
    for(let i=0;i<count;i++) root.insertAdjacentHTML('beforeend',`<div class="employee-tile ${department==='Filtered'&&i>0?'ve-hidden':''}" data-dept="${department}"><div class="employee-tile-img"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=="></div><div class="employee-details"><h3 class="employee-name">Person ${i}</h3><span class="employee-title">Advisor</span><span class="employee-location">Office</span></div><div class="ve-staff-tags">Private tag</div><div class="years-service">17 years of service</div><div class="ve-contact-wrap">Private contact</div></div>`);
  }
  const {createDirectoryRuntime}=load('src/runtime/directory.ts',{window,document:window.document,MutationObserver:window.MutationObserver});const runtime=createDirectoryRuntime();const doc=window.document.implementation.createHTMLDocument('');
  assert.equal(runtime.buildPrintDocument(root,doc,'landscape'),9);
  const tables=Array.from(doc.querySelectorAll('.print-department'));
  assert.deepEqual(tables.map(table=>table.querySelector('thead').textContent),['Small A','Small B','Large','Filtered']);
  assert.deepEqual(tables.map(table=>table.classList.contains('large')),[false,false,true,false]);
  for(const table of tables) {assert.equal(table.querySelector('th').colSpan,5);assert.equal(table.querySelector('tbody tr').children.length,5);}
  for(const omitted of ['Private tag','17 years','Private contact']) assert.equal(doc.body.textContent.includes(omitted),false);
  assert.equal(doc.querySelectorAll('.print-photo').length,9);
  window.close();
});

test('Save PDF controls exist only in the SharePoint runtime, not site or embed listings', () => {
  for (const integration of ['sharepoint',undefined]) {
    const dom=new JSDOM('<div id="veStaffList"><div class="employee-list"><div class="employee-tile"></div></div></div>');const {window}=dom;
    const {createDirectoryRuntime}=load('src/runtime/directory.ts',{window,document:window.document,MutationObserver:window.MutationObserver});const runtime=createDirectoryRuntime();
    runtime.integration=integration;runtime.initialize(window.document);
    assert.equal(!!window.document.querySelector('.ve-pdf-controls'),integration==='sharepoint');
    runtime.dispose(window.document);window.close();
  }
});

test('compact public actions retain destinations and biography targets with accessible icons', () => {
  const dom=new JSDOM('<div id="veStaffList"><div class="employee-tile"><div class="employee-wrapper"><div class="employee-details"><h3 class="employee-name">Alice</h3></div><div class="ve-contact-wrap"><div><a href="tel:6055551234">Call</a></div><div><a href="tel:6055551234">605-555-1234</a></div><div><a class="appointment" href="https://example.com/book">Book Appointment</a></div><div><button data-bs-target="#biomodal42" data-bs-toggle="vemodal">About Alice</button></div></div></div></div></div>');
  const {window}=dom;const {createDirectoryRuntime}=load('src/runtime/directory.ts',{window,document:window.document,MutationObserver:window.MutationObserver});const runtime=createDirectoryRuntime();runtime.initialize(window.document);
  const card=window.document.querySelector('.employee-tile');
  assert.equal(card.querySelectorAll('.ve-public-action-icon svg').length,4);
  assert.equal(card.querySelectorAll('.ve-duplicate-call').length,1);
  assert.equal(card.querySelector('.appointment').getAttribute('href'),'https://example.com/book');
  assert.equal(card.querySelector('[data-bs-target]').getAttribute('data-bs-target'),'#biomodal42');
  assert.equal(card.querySelector('[data-bs-target]').getAttribute('aria-label'),'View bio — Alice');
  assert.equal(card.querySelector('.appointment .ve-public-action-text').textContent,'Book Appointment');
  runtime.dispose(window.document);window.close();
});
