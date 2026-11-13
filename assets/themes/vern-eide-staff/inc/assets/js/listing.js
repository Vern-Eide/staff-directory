(function (windowObject) {
  'use strict';

  var namespace = windowObject.VEStaffDirectory || {};

  function all(root, selector) {
    return Array.prototype.slice.call(root.querySelectorAll(selector));
  }

  function setHidden(elements, hidden) {
    elements.forEach(function (element) {
      element.classList.toggle('ve-hidden', hidden);
    });
  }

  function value(root, selector) {
    var field = filterField(root, selector);
    return field ? field.value : 'all';
  }

  function resetFields(root, selectors) {
    selectors.forEach(function (selector) {
      var field = filterField(root, selector);
      if (field) {
        if (field.tagName === 'SELECT' && field.multiple) all(field, 'option').forEach(function (option) { option.selected = false; });
        else field.value = field.tagName === 'SELECT' ? field.options[0].value : '';
        if (field.__veRefreshMulti) field.__veRefreshMulti();
      }
    });
  }

  function updateEmptyState(root) {
    var oldMessage = root.querySelector('.ve-no-results');
    if (oldMessage) {
      oldMessage.remove();
    }
    if (root.querySelector('.employee-tile:not(.ve-hidden), .department-header:not(.ve-hidden)')) {
      return;
    }
    var row = root.querySelector('.employee-list .ve-row');
    if (row) {
      var message = document.createElement('h3');
      message.className = 've-no-results ve-text-center';
      message.textContent = 'No Results Found';
      row.appendChild(message);
    }
  }

  var filterDefinitions = [
    ['employeenamesearch', 'Name', 'data-employee-name'],
    ['employeeextsearch', 'Extension', 'data-ext'],
    ['departmentfilterselect', 'Department', 'data-dept'],
    ['locationfilterselect', 'Location', 'data-loc'],
    ['tagfilterselect', 'Tag', 'data-tags'],
    ['employeetitleselect', 'Job title', 'data-search-title'],
    ['employeephonesearch', 'Phone', 'data-search-phone'],
    ['employeeemailsearch', 'Email', 'data-search-email']
  ];

  function activeFilters(root) {
    return filterDefinitions.map(function (definition) {
      var field = filterField(root, '#' + definition[0]);
      if (!field) return null;
      var values = field.tagName === 'SELECT' ? all(field, 'option').filter(function (option) { return option.selected && option.value && option.value !== 'all'; }).map(function (option) { return option.value; }) : [field.value.trim()].filter(Boolean);
      if (!values.length) return null;
      return { definition: definition, field: field, value: values[0], values: values };
    }).filter(Boolean);
  }

  function matchesFilter(card, filter) {
    var raw = card.getAttribute(filter.definition[2]) || '';
    if (filter.definition[2] === 'data-search-phone') {
      var digits = filter.value.replace(/\D/g, '');
      return digits.length > 0 && raw.indexOf(digits) !== -1;
    }
    if (filter.field.tagName !== 'SELECT') return raw.toLowerCase().indexOf(filter.value.toLowerCase()) !== -1;
    if (filter.definition[2] === 'data-dept' || filter.definition[2] === 'data-search-title') return filter.values.indexOf(raw) !== -1;
    var values;
    try { values = JSON.parse(raw); } catch (_) { values = [raw]; }
    if (!Array.isArray(values)) values = [values];
    return values.some(function (entry) { return filter.values.some(function (selected) { return String(entry).replace(/^0+(?=\d)/, '') === selected.replace(/^0+(?=\d)/, ''); }); });
  }

  function renderFilterPills(root, filters) {
    var pills = root.querySelector('.ve-filter-pills');
    if (!pills) {
      pills = document.createElement('div');
      pills.className = 've-filter-pills';
      pills.setAttribute('aria-label', 'Active staff filters');
      var trigger = root.querySelector('[data-ve-staff-filters-open]');
      var panel = root.__veStaffFilterPanel;
      if (trigger) (root.querySelector('.ve-directory-toolbar') || trigger).insertAdjacentElement('afterend', pills);
      else if (panel) panel.insertAdjacentElement('afterend', pills);
      else root.prepend(pills);
    }
    pills.replaceChildren();
    filters.forEach(function (filter) {
      filter.values.forEach(function (selected) {
      var option = all(filter.field, 'option').find(function (item) { return item.value === selected; });
      var label = option ? option.text : selected;
      var button = document.createElement('button');
      button.type = 'button'; button.className = 've-filter-pill';
      button.setAttribute('data-remove-filter', filter.definition[0]);
      button.setAttribute('data-remove-value', selected);
      button.setAttribute('aria-label', 'Remove ' + filter.definition[1] + ' filter: ' + label);
      button.textContent = filter.definition[1] + ': ' + label + ' ×';
      pills.appendChild(button);
      });
    });
    pills.hidden = !filters.length;
  }

  function applyFilters(root) {
    var filters = activeFilters(root);
    var cards = all(root, '.employee-tile');
    cards.forEach(function (card) { card.classList.toggle('ve-hidden', !filters.every(function (filter) { return matchesFilter(card, filter); })); });
    all(root, '.department-header').forEach(function (heading) {
      heading.classList.toggle('ve-hidden', !cards.some(function (card) { return !card.classList.contains('ve-hidden') && card.getAttribute('data-dept') === heading.getAttribute('data-dept'); }));
    });
    renderFilterPills(root, filters);
    updateEmptyState(root);
  }

  function bindFilters(root) {
    ['input', 'change'].forEach(function (type) {
      listenToDirectory(root, type, function (event) {
        if (filterDefinitions.some(function (definition) { return event.target.id === definition[0]; })) applyFilters(root);
      });
    });
    listenToDirectory(root, 'click', function (event) {
      var pill = event.target.closest('[data-remove-filter]');
      if (pill) {
        var field = filterField(root, '#' + pill.getAttribute('data-remove-filter'));
        if (field) {
          if (field.tagName === 'SELECT' && field.multiple) all(field, 'option').forEach(function (option) { if (option.value === pill.getAttribute('data-remove-value')) option.selected = false; });
          else field.value = field.tagName === 'SELECT' ? field.options[0].value : '';
          if (field.__veRefreshMulti) field.__veRefreshMulti();
        }
        applyFilters(root);
        var next = root.querySelector('.ve-filter-pill, [data-ve-staff-filters-open]');
        if (next) next.focus();
      }
      if (event.target.closest('#resetFilterBtn')) {
        event.preventDefault();
        resetFields(root, filterDefinitions.map(function (definition) { return '#' + definition[0]; }));
        applyFilters(root);
      }
      if (event.target.closest('#viewMoreBtn')) {
        applyFilters(root);
        setHidden(all(root, '.viewMoreWrapper'), true);
      }
    });
    renderFilterPills(root, activeFilters(root));
  }

  // Filter controls may be temporarily outside the listing in the body overlay.
  function filterField(root, selector) {
    return root.querySelector(selector) || (root.__veStaffFilterPanel && root.__veStaffFilterPanel.querySelector(selector));
  }

  function listenToDirectory(root, type, handler) {
    root.addEventListener(type, handler);
    var panel = root.__veStaffFilterPanel;
    if (panel) panel.addEventListener(type, function (event) {
      if (!root.contains(panel)) handler(event);
    });
  }

  // Font Awesome Free 6 icons (CC BY 4.0); inline SVG works inside every embed
  // without depending on the host page's icon font or external font requests.
  function detailIcon(kind) {
    var paths = {
      email: ['512 512', 'M48 64C21.5 64 0 85.5 0 112c0 15.1 7.1 29.3 19.2 38.4L236.8 313.6c11.4 8.5 27 8.5 38.4 0L492.8 150.4C504.9 141.3 512 127.1 512 112c0-26.5-21.5-48-48-48H48zM0 176V400c0 26.5 21.5 48 48 48H464c26.5 0 48-21.5 48-48V176L294.4 332.8c-22.8 17.1-54 17.1-76.8 0L0 176z'],
      phone: ['512 512', 'M164.9 24.6c-7.7-18.6-28-28.5-47.4-23.2l-88 24C12.1 30.2 0 46 0 64C0 311.4 200.6 512 448 512c18 0 33.8-12.1 38.6-29.5l24-88c5.3-19.4-4.6-39.7-23.2-47.4l-96-40c-16.3-6.8-35.2-2.1-46.4 11.6L304.6 368C234.2 334.7 177.3 277.8 144 207.4l49.3-40.3c13.7-11.2 18.4-30.1 11.6-46.4l-40-96z'],
      extension: ['448 512', 'M128 0C110.3 0 96 14.3 96 32V96H32C14.3 96 0 110.3 0 128s14.3 32 32 32H96V352H32c-17.7 0-32 14.3-32 32s14.3 32 32 32H96v64c0 17.7 14.3 32 32 32s32-14.3 32-32V416H288v64c0 17.7 14.3 32 32 32s32-14.3 32-32V416h64c17.7 0 32-14.3 32-32s-14.3-32-32-32H352V160h64c17.7 0 32-14.3 32-32s-14.3-32-32-32H352V32c0-17.7-14.3-32-32-32s-32 14.3-32 32V96H160V32c0-17.7-14.3-32-32-32zm32 160H288V352H160V160z']
    };
    paths.chevron = ['448 512', 'M207 381 13 187a24 24 0 0 1 34-34l177 177 177-177a24 24 0 0 1 34 34L241 381a24 24 0 0 1-34 0z'];
    paths.briefcase = ['512 512', 'M184 48h144a40 40 0 0 1 40 40v40h96a48 48 0 0 1 48 48v240a48 48 0 0 1-48 48H48a48 48 0 0 1-48-48V176a48 48 0 0 1 48-48h96V88a40 40 0 0 1 40-40zm8 80h128V96H192z'];
    paths.building = ['448 512', 'M64 32h320v448H64zM112 80v48h48V80zm88 0v48h48V80zm88 0v48h48V80zM112 176v48h48v-48zm88 0v48h48v-48zm88 0v48h48v-48zM112 272v48h48v-48zm88 0v48h48v-48zm88 0v48h48v-48zM192 384v96h64v-96z'];
    var definition = paths[kind] || paths.phone;
    var icon = document.createElement('span'); icon.className = 've-detail-icon fa-solid'; icon.setAttribute('aria-hidden', 'true');
    icon.innerHTML = '<svg viewBox="0 0 ' + definition[0] + '" focusable="false"><path fill="currentColor" d="' + definition[1] + '"></path></svg>';
    return icon;
  }

  function prepareCompactDetails(card) {
    var details = card.querySelector('.employee-details');
    if (!details || details.querySelector('.ve-compact-summary')) return;
    var summary = document.createElement('div'); summary.className = 've-compact-summary';
    ['.employee-title', '.employee-location'].forEach(function (selector) {
      var source = details.querySelector(selector);
      if (source) { var copy = source.cloneNode(true); copy.removeAttribute('id'); summary.appendChild(copy); }
    });
    var contact = card.querySelector('.employee-internal-details .ve-contact-wrap');
    if (contact) {
      var extension = all(contact, '.ve-detail-row').find(function (row) {
        return /^(ext|extension)$/i.test(row.querySelector('.ve-detail-label').textContent.trim());
      });
      if (extension) {
        var ext = document.createElement('span'); ext.className = 've-summary-extension';
        ext.textContent = 'Ext. ' + extension.querySelector('.ve-detail-value').textContent.trim(); summary.appendChild(ext);
      }
      var email = contact.querySelector('a[href^="mailto:"]');
      if (email) { var emailCopy = email.cloneNode(true); emailCopy.className = 've-summary-email'; emailCopy.removeAttribute('id'); summary.appendChild(emailCopy); }
      // Group values separately so actions remain full-width below the grid.
      var values = document.createElement('div'); values.className = 've-contact-values';
      var rows = all(contact, ':scope > .ve-detail-row');
      if (rows.length) { rows[0].before(values); rows.forEach(function (row) { values.appendChild(row); }); }
    }
    details.appendChild(summary);
    var toggle = card.querySelector('.employee-internal-details > button[data-bs-toggle="collapse"], .employee-internal-details > button[data-toggle="collapse"]');
    if (toggle) {
      var text = document.createElement('span'); text.className = 've-contact-toggle-text'; text.textContent = toggle.textContent;
      toggle.replaceChildren(text);
      var icon = detailIcon('email'); icon.classList.add('ve-contact-toggle-icon'); toggle.appendChild(icon);
      var name = card.querySelector('.employee-name');
      toggle.setAttribute('aria-label', 'View more info' + (name ? ' for ' + name.textContent.trim() : ''));
      toggle.title = 'View more info';
    }
  }

  function refineCompactCard(card) {
    var details = card.querySelector('.employee-details');
    if (!details) return;
    var name = details.querySelector('.employee-name');
    var title = details.querySelector(':scope > .employee-title');
    var tags = card.querySelector('.ve-staff-tags--title, .ve-staff-tags--photo');
    var heading = document.createElement('div'); heading.className = 've-compact-heading';
    [name, title, tags].forEach(function (source) { if (source) { var copy = source.cloneNode(true); copy.removeAttribute('id'); heading.appendChild(copy); } });
    details.prepend(heading);
    function icon(element, kind) { if (element) element.prepend(detailIcon(kind)); }
    icon(heading.querySelector('.employee-title'), 'briefcase');
    var summary = details.querySelector('.ve-compact-summary');
    if (summary) {
      var oldTitle = summary.querySelector('.employee-title'); if (oldTitle) oldTitle.remove();
      icon(summary.querySelector('.employee-location'), 'building');
      icon(summary.querySelector('.ve-summary-extension'), 'extension');
      icon(summary.querySelector('.ve-summary-email'), 'email');
    }
    var action = card.querySelector('.ve-contact-toggle-icon');
    if (action) { var arrow = detailIcon('chevron'); arrow.classList.add('ve-contact-toggle-icon'); action.replaceWith(arrow); }
    var contact = card.querySelector('.ve-contact-wrap');
    if (contact) {
      var actions = all(contact, ':scope > .ve-contact-info-btn');
      if (actions.length) { var group = document.createElement('div'); group.className = 've-contact-actions'; actions[0].before(group); actions.forEach(function (item) { group.appendChild(item); }); }
    }
    var image = card.querySelector('.employee-tile-img img');
    if (image) {
      var host = image.parentElement; host.tabIndex = 0; host.setAttribute('aria-label', 'Enlarge ' + (name ? name.textContent.trim() : 'staff') + ' photo');
      var preview = document.createElement('img'); preview.className = 've-photo-preview'; preview.alt = ''; preview.setAttribute('aria-hidden','true');
      preview.src = image.getAttribute('data-src') || image.currentSrc || image.src;
      preview.addEventListener('error', function () { preview.hidden = true; }); host.appendChild(preview);
    }
  }

  function prepareDirectory(root) {
    all(root, '.employee-tile').forEach(function (card) {
      var internal = card.querySelector('.employee-internal-details');
      if (internal) {
        var title = card.querySelector('.employee-title');
        card.setAttribute('data-search-title', title ? title.textContent.trim() : '');
        card.setAttribute('data-search-phone', all(internal, 'a[href^="tel:"]').map(function (link) { return link.getAttribute('href').slice(4).replace(/\D/g, ''); }).join(' '));
        card.setAttribute('data-search-email', all(internal, 'a[href^="mailto:"]').map(function (link) { return link.getAttribute('href').slice(7).split('?')[0]; }).join(' '));
      }
      all(card, '.ve-contact-wrap > span.ve-block').forEach(function (row) {
        var label = row.querySelector('.ve-bolder');
        if (!label || row.classList.contains('ve-detail-row')) return;
        var labelText = label.textContent.replace(/:\s*$/, '').trim();
        var kind = row.querySelector('a[href^="mailto:"]') ? 'email' : /ext/i.test(labelText) ? 'extension' : 'phone';
        label.className = 've-detail-label'; label.textContent = labelText === 'Ext' ? 'Extension' : labelText;
        var content = document.createElement('span'); content.className = 've-detail-content';
        var value = document.createElement('span'); value.className = 've-detail-value';
        Array.prototype.slice.call(row.childNodes).forEach(function (node) {
          if (node !== label && node.nodeName !== 'BR') value.appendChild(node);
        });
        content.appendChild(label); content.appendChild(value);
        row.replaceChildren(detailIcon(kind), content); row.classList.add('ve-detail-row');
      });
      prepareCompactDetails(card);
      refineCompactCard(card);
    });
  }

  var runtimeScriptUrl = document.currentScript && document.currentScript.src;
  function loadSelect2() {
    if (namespace.jquery) return Promise.resolve(namespace.jquery);
    if (namespace.select2Promise) return namespace.select2Promise;
    if (!runtimeScriptUrl) return Promise.reject(new Error('Select2 source is unavailable.'));
    namespace.select2Promise = new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      var url = new URL('ve-select2.js', runtimeScriptUrl); url.searchParams.set('ver', '3.7.1-4.0.13-1');
      script.src = url.toString();
      script.onload = function () { if (namespace.jquery) resolve(namespace.jquery); else reject(new Error('Select2 did not initialize.')); };
      script.onerror = function () { namespace.select2Promise = null; script.remove(); reject(new Error('Searchable filters could not load.')); };
      document.head.appendChild(script);
    });
    return namespace.select2Promise;
  }

  function enhanceMultiSelects(root, panel) {
    var fields = filterDefinitions.map(function (definition) { return panel.querySelector('select#' + definition[0]); }).filter(Boolean);
    fields.forEach(function (field) {
      // Empty selection means all; the former sentinel is not a selectable value.
      all(field, 'option').forEach(function (option) { if (option.value === 'all' || !option.value) option.remove(); });
      field.multiple = true;
      all(field, 'option').forEach(function (option) { option.selected = false; });
    });
    var status = document.createElement('p'); status.className = 've-select2-status'; status.setAttribute('role', 'status'); panel.appendChild(status);
    function setup($) {
      if (root.__veStaffDisposed) return;
      fields.forEach(function (field) {
        var label = field.getAttribute('aria-label') || field.id;
        var control = $(field);
        control.select2({ width: '100%', dropdownParent: $(panel), closeOnSelect: false, placeholder: 'All ' + label.toLowerCase(), allowClear: true });
        control.on('change.veDirectory', function () { applyFilters(root); });
        field.__veRefreshMulti = function () { control.trigger('change.select2'); };
        // Label the generated search input, not only the hidden native select.
        var input = control.data('select2').$container.find('input'); input.attr('aria-label', label + ' — select one or more');
      });
      root.__veStaffDisposeSelect2 = function () {
        fields.forEach(function (field) { var control = $(field); control.off('.veDirectory'); if (control.data('select2')) control.select2('destroy'); delete field.__veRefreshMulti; });
      };
    }
    if (namespace.jquery) setup(namespace.jquery);
    else loadSelect2().then(setup).catch(function () { if (!root.__veStaffDisposed) status.textContent = 'Searchable filters could not load. Use Ctrl or Command to select multiple options, or reload the page.'; });
  }

  function buildPrintDocument(root, doc, orientation) {
    var landscape = orientation === 'landscape';
    var columns = landscape ? 5 : 3;
    doc.title = 'Staff Directory';
    doc.documentElement.lang = 'en';
    doc.head.replaceChildren(); doc.body.replaceChildren();
    var title = doc.createElement('title'); title.textContent = 'Staff Directory'; doc.head.appendChild(title);
    var style = doc.createElement('style');
    style.textContent = '@page{size:Letter ' + (landscape ? 'landscape' : 'portrait') + ';margin:.35in}' +
      '*{box-sizing:border-box}body{margin:0;font:10pt/1.35 Arial,sans-serif;color:#222;background:white}' +
      '.print-help{padding:16px;background:#f2f2f2;margin-bottom:16px}.print-help button{margin-right:12px;padding:8px 16px}' +
      '.print-department{width:100%;table-layout:fixed;border-spacing:8px;margin:0 0 12px;break-inside:auto}' +
      '.print-department thead{display:table-header-group}.print-department th{text-align:left;padding:0 0 6px;font-size:15pt;border-bottom:2px solid #424242}' +
      '.print-department.large{break-before:page;page-break-before:always}.print-department.first{break-before:auto;page-break-before:auto}' +
      '.print-row{break-inside:avoid;page-break-inside:avoid}.print-department.small{break-inside:avoid;page-break-inside:avoid}' +
      '.print-card{vertical-align:top;width:' + (100 / columns) + '%;border:1px solid #bbb;padding:0;overflow-wrap:anywhere}' +
      '.print-card.empty{border:0}.print-photo{display:block;width:100%;aspect-ratio:1;object-fit:cover;object-position:center top;margin:0}' +
      '.print-info{padding:10px}h2{font-size:12pt;margin:0 0 4px}.subtitle{margin:0 0 4px;font-size:9pt}' +
      '@media print{.print-help{display:none!important}body{width:auto}}';
    doc.head.appendChild(style);
    var help = doc.createElement('div'); help.className = 'print-help';
    var instructions = doc.createElement('p');
    instructions.textContent = 'Choose Save as PDF in the print dialog. Use Letter ' + (landscape ? 'landscape' : 'portrait') + ', 100% scale, and turn off browser headers and footers.';
    var print = doc.createElement('button'); print.type = 'button'; print.textContent = 'Save PDF / Print';
    print.addEventListener('click', function () { doc.defaultView.print(); });
    help.appendChild(instructions); help.appendChild(print); doc.body.appendChild(help);
    var cards = all(root, '.employee-tile').filter(function (card) {
      return !card.classList.contains('ve-hidden') && !card.hidden && windowObject.getComputedStyle(card).display !== 'none';
    });
    if (!cards.length) { var empty = doc.createElement('p'); empty.textContent = 'No staff match the current view.'; doc.body.appendChild(empty); }
    var departments = [];
    cards.forEach(function (card) {
      var name = (card.getAttribute('data-dept') || '').trim() || 'Staff';
      var department = departments.find(function (item) { return item.name === name; });
      if (!department) { department = { name: name, cards: [] }; departments.push(department); }
      department.cards.push(card);
    });
    departments.forEach(function (department, departmentIndex) {
      var table = doc.createElement('table');
      table.className = 'print-department ' + (department.cards.length > 3 ? 'large' : 'small') + (departmentIndex === 0 ? ' first' : '');
      var head = doc.createElement('thead'); var headingRow = doc.createElement('tr'); var heading = doc.createElement('th');
      heading.colSpan = columns; heading.scope = 'colgroup'; heading.textContent = department.name;
      headingRow.appendChild(heading); head.appendChild(headingRow); table.appendChild(head);
      var body = doc.createElement('tbody'); table.appendChild(body); doc.body.appendChild(table);
      var row;
      department.cards.forEach(function (source, index) {
        if (index % columns === 0) { row = doc.createElement('tr'); row.className = 'print-row'; body.appendChild(row); }
        var card = doc.createElement('td'); card.className = 'print-card'; row.appendChild(card);
        var image = source.querySelector('.employee-tile-img img');
        if (image) {
          var photo = doc.createElement('img'); photo.className = 'print-photo'; photo.alt = '';
          photo.loading = 'eager'; photo.src = image.getAttribute('data-src') || image.getAttribute('data-lazy-src') || image.getAttribute('data-original') || image.currentSrc || image.src;
          photo.addEventListener('error', function () { photo.remove(); }); card.appendChild(photo);
        }
        var info = doc.createElement('div'); info.className = 'print-info'; card.appendChild(info);
        function textFrom(selector, tag, className) {
          var element = source.querySelector(selector);
          if (element && element.textContent.trim()) { var copy = doc.createElement(tag); copy.className = className; copy.textContent = element.textContent.trim(); info.appendChild(copy); }
        }
        // Copy only these approved display fields, never whole cards or contact markup.
        textFrom('.employee-name', 'h2', '');
        textFrom('.employee-details > .employee-title', 'p', 'subtitle');
        textFrom('.employee-details > .employee-location, .employee-details > .ve-font-12', 'p', 'subtitle');
      });
      if (row) for (var i = department.cards.length % columns; i > 0 && i < columns; i++) { var spacer = doc.createElement('td'); spacer.className = 'print-card empty'; row.appendChild(spacer); }
    });
    return cards.length;
  }

  function bindPrint(root, toolbar) {
    var controls = document.createElement('div'); controls.className = 've-pdf-controls';
    var menu = document.createElement('details'); menu.className = 've-pdf-menu';
    var trigger = document.createElement('summary'); trigger.innerHTML = '<svg aria-hidden="true" viewBox="0 0 24 24"><path fill="currentColor" d="M3 3h15l3 3v15H3V3zm3 2v6h11V5H6zm1 10v4h10v-4H7z"/></svg> PDF'; menu.appendChild(trigger);
    var label = document.createElement('label'); label.textContent = 'PDF layout';
    var select = document.createElement('select'); select.setAttribute('aria-label', 'PDF page layout');
    [['portrait', 'Letter portrait · 3 across'], ['landscape', 'Letter landscape · 5 across']].forEach(function (option) {
      var item = document.createElement('option'); item.value = option[0]; item.textContent = option[1]; select.appendChild(item);
    });
    label.appendChild(select);
    var button = document.createElement('button'); button.type = 'button'; button.textContent = 'Save PDF';
    var status = document.createElement('span'); status.setAttribute('role', 'status');
    button.addEventListener('click', function () {
      menu.open = false;
      var preview = windowObject.open('', '_blank');
      if (!preview) { status.textContent = 'Allow pop-ups for this page, then choose Save PDF again.'; return; }
      preview.opener = null;
      try {
        buildPrintDocument(root, preview.document, select.value);
        status.textContent = 'Print view opened. Choose Save as PDF in its print dialog.';
        var images = all(preview.document, 'img');
        var ready = images.map(function (image) { return image.complete ? Promise.resolve() : new Promise(function (resolve) { image.addEventListener('load', resolve, {once:true}); image.addEventListener('error', resolve, {once:true}); }); });
        var timeout;
        Promise.race([Promise.all(ready), new Promise(function (resolve) { timeout = windowObject.setTimeout(resolve, 10000); })]).then(function () {
          windowObject.clearTimeout(timeout);
          if (!preview.closed) { preview.focus(); preview.print(); }
        });
      } catch (_) { status.textContent = 'The print view could not open. Please retry.'; preview.close(); }
    });
    var choices = document.createElement('div'); choices.className = 've-pdf-choices'; choices.appendChild(label); choices.appendChild(button); menu.appendChild(choices);
    menu.addEventListener('keydown', function (event) { if (event.key === 'Escape') { menu.open = false; trigger.focus(); } });
    controls.appendChild(menu); controls.appendChild(status); toolbar.appendChild(controls);
  }
  if (namespace.integration === 'sharepoint') namespace.buildPrintDocument = buildPrintDocument;

  function prepareFilterToolbar(root, panel, trigger) {
    var fields = panel.querySelector('#employeeHeadingWrapper > .ve-row') || panel.querySelector('#employeeHeadingWrapper') || panel;
    fields.classList.add('ve-advanced-fields');
    var internalCards = all(root, '.employee-tile[data-search-title]');
    if (internalCards.length) {
      [['employeetitleselect', 'Job title', 'select'], ['employeephonesearch', 'Phone', 'search'], ['employeeemailsearch', 'Email', 'search']].forEach(function (definition) {
        if (panel.querySelector('#' + definition[0])) return;
        var wrapper = document.createElement('label'); wrapper.className = 've-advanced-filter';
        var caption = document.createElement('span'); caption.className = 'filter-label'; caption.textContent = definition[1];
        var field = document.createElement(definition[2] === 'select' ? 'select' : 'input'); field.id = definition[0];
        if (definition[2] === 'select') {
          var titles = internalCards.map(function (card) { return card.getAttribute('data-search-title'); }).filter(Boolean);
          var option = document.createElement('option'); option.value = 'all'; option.textContent = 'All job titles'; field.appendChild(option);
          titles.filter(function (title, index) { return titles.indexOf(title) === index; }).sort().forEach(function (title) {
            var choice = document.createElement('option'); choice.value = title; choice.textContent = title; field.appendChild(choice);
          });
        } else { field.type = 'search'; field.placeholder = definition[1] === 'Phone' ? 'Search phone number' : 'Search email address'; }
        wrapper.appendChild(caption); wrapper.appendChild(field); fields.appendChild(wrapper);
      });
    }
    var toolbar = document.createElement('div'); toolbar.className = 've-directory-toolbar';
    trigger.before(toolbar);
    ['employeenamesearch', 'employeeextsearch'].forEach(function (id) {
      var field = panel.querySelector('#' + id); if (!field) return;
      var label = document.createElement('label'); label.className = 've-quick-filter';
      var caption = document.createElement('span'); caption.textContent = id === 'employeenamesearch' ? 'Employee name' : 'Extension';
      var oldWrapper = field.closest('.employeeFilterWrapper, .extFilterWrapper');
      label.appendChild(caption); label.appendChild(field); toolbar.appendChild(label);
      if (oldWrapper) oldWrapper.remove();
    });
    trigger.textContent = 'More filters'; toolbar.appendChild(trigger);
    var layouts = document.createElement('div'); layouts.className = 've-layout-options'; layouts.setAttribute('role', 'group'); layouts.setAttribute('aria-label', 'Staff layout');
    ['Cards', 'Compact'].forEach(function (name, index) {
      var button = document.createElement('button'); button.type = 'button'; button.textContent = name; button.setAttribute('aria-pressed', String(index === 0));
      button.addEventListener('click', function () {
        root.classList.toggle('ve-layout-compact', index === 1);
        all(layouts, 'button').forEach(function (item) { item.setAttribute('aria-pressed', String(item === button)); });
      });
      layouts.appendChild(button);
    });
    toolbar.appendChild(layouts);
    if (namespace.integration === 'sharepoint') bindPrint(root, toolbar);
    var footer = document.createElement('div'); footer.className = 've-filter-actions';
    var reset = panel.querySelector('#resetFilterBtn'); var close = panel.querySelector('[data-ve-staff-filters-close]');
    if (reset) { var resetWrapper = reset.closest('.ve-col-auto'); footer.appendChild(reset); if (resetWrapper && !resetWrapper.textContent.trim()) resetWrapper.remove(); } if (close) footer.appendChild(close); panel.appendChild(footer);
  }

  function bindFilterPanel(root) {
    var panel = root.querySelector('.ve-staff-filters');
    var trigger = root.querySelector('[data-ve-staff-filters-open]');
    if (!panel) {
      var fields = root.querySelector('#employeeHeadingWrapper');
      if (!fields) {
        if (!root.querySelector('.employee-tile')) return;
        fields = document.createElement('div'); fields.id = 'employeeHeadingWrapper';
        fields.innerHTML = '<div class="ve-row"><div class="employeeFilterWrapper"><input id="employeenamesearch" type="search" placeholder="Search name"></div><button type="button" id="resetFilterBtn">Reset</button></div>';
        root.prepend(fields);
      }
      panel = document.createElement('div'); panel.className = 've-staff-filters'; panel.tabIndex = -1;
      fields.before(panel); panel.appendChild(fields);
      var header = document.createElement('div'); header.className = 've-staff-filters-header';
      header.innerHTML = '<h5>Staff filters</h5>';
      panel.prepend(header);
      var closeButton = document.createElement('button'); closeButton.type = 'button';
      closeButton.setAttribute('data-ve-staff-filters-close', ''); closeButton.textContent = 'Close Filters';
      panel.appendChild(closeButton);
    }
    if (!trigger) {
      trigger = document.createElement('button'); trigger.type = 'button'; trigger.className = 've-secondary-button-grey';
      trigger.setAttribute('data-ve-staff-filters-open', ''); trigger.textContent = 'Staff Filters'; panel.before(trigger);
    }
    prepareFilterToolbar(root, panel, trigger);
    root.classList.add('ve-directory-enhanced');
    filterDefinitions.forEach(function (definition) {
      var field = panel.querySelector('#' + definition[0]);
      if (field && !field.getAttribute('aria-label')) field.setAttribute('aria-label', definition[1]);
    });
    root.__veStaffFilterPanel = panel;
    enhanceMultiSelects(root, panel);

    var home = document.createComment('Staff filters inline position');
    panel.parentNode.insertBefore(home, panel);
    var panelId = 've-staff-filters-' + Math.random().toString(36).slice(2);
    panel.id = panelId;
    trigger.setAttribute('aria-controls', panelId);
    trigger.setAttribute('data-ve-staff-filters-open', '#' + panelId);
    var heading = panel.querySelector('.ve-staff-filters-header h5');
    if (heading) {
      heading.id = panelId + '-label';
      panel.setAttribute('aria-labelledby', heading.id);
    }
    var previousFocus;
    var portal;
    var backdrop;
    var surface;

    function createPortal() {
      portal = document.createElement('dialog');
      portal.className = 've-staff-filter-portal';
      portal.setAttribute('aria-label', 'Staff filters');
      portal.addEventListener('cancel', function (event) { event.preventDefault(); close(true); });
      // Only our body child is styled; the host document and Bootstrap stay untouched.
      portal.style.cssText = 'all:initial!important;position:fixed!important;inset:0!important;margin:0!important;padding:0!important;border:0!important;width:100%!important;height:100%!important;max-width:none!important;max-height:none!important;background:transparent!important;z-index:2147483647!important;pointer-events:auto!important;visibility:visible!important;opacity:1!important;transform:none!important;';
      var shadowHost = document.createElement('div');
      portal.appendChild(shadowHost);
      var shadow = shadowHost.attachShadow({ mode: 'open' });
      var source = root.getRootNode();
      all(source, 'style[data-ve-staff-v2], style[data-ve-staff], link[data-ve-staff], link#ve-staff-listing-css').forEach(function (style) {
        shadow.appendChild(style.cloneNode(true));
      });
      var style = document.createElement('style');
      // Critical overlay geometry also works before external listing CSS has loaded.
      style.textContent = '.ve-staff-filter-surface{font:14px/1.5 Arial,sans-serif;color:#222;pointer-events:none}' +
        '.ve-staff-filters-backdrop{position:fixed;inset:0;z-index:0!important;background:rgba(0,0,0,.45);pointer-events:auto}' +
        '.ve-staff-filters.ve-staff-filters--open{display:block!important;position:fixed!important;inset:0!important;z-index:1!important;margin:0!important;width:auto!important;max-width:none!important;height:100%!important;max-height:none!important;box-sizing:border-box!important;padding:clamp(20px,4vw,64px)!important;border:0!important;background:#e9eef3!important;color:#222!important;visibility:visible!important;opacity:1!important;transform:none!important;overflow:auto!important;pointer-events:auto!important;box-shadow:0 8px 24px rgba(0,0,0,.2)}' +
        '.ve-staff-filters-header{display:block!important}.ve-staff-filters-header h5{margin:0 0 12px;font-size:18px}' +
        '[data-ve-staff-filters-close]{display:inline-block!important;cursor:pointer}';
      style.textContent += '@media(min-width:768px){.ve-staff-filters.ve-staff-filters--open{inset:50% auto auto 50%!important;transform:translate(-50%,-50%)!important;width:min(960px,calc(100vw - 64px))!important;height:auto!important;max-height:88vh!important;padding:32px!important;border-radius:24px!important}}';
      shadow.appendChild(style);
      surface = document.createElement('div');
      surface.className = 've-staff-embed ve ve-staff-filter-surface';
      backdrop = document.createElement('div');
      backdrop.className = 've-staff-filters-backdrop';
      backdrop.addEventListener('click', function () { close(true); });
      surface.appendChild(backdrop);
      shadow.appendChild(surface);
    }

    function focusable() {
      return all(panel, 'button, input, select, textarea, a[href], [tabindex="0"]').filter(function (element) {
        return !element.disabled && element.tabIndex >= 0 && element.getClientRects().length;
      });
    }
    function close(restoreFocus) {
      if (namespace.jquery) all(panel, 'select').forEach(function (field) { var control = namespace.jquery(field); if (control.data('select2')) control.select2('close'); });
      if (portal && portal.open) portal.close();
      panel.classList.remove('ve-staff-filters--open');
      panel.removeAttribute('role');
      panel.removeAttribute('aria-modal');
      trigger.setAttribute('aria-expanded', 'false');
      // Move the original controls back, retaining their values and listeners.
      if (portal) {
        home.parentNode.insertBefore(panel, home.nextSibling);
        portal.remove();
      }
      if (restoreFocus && previousFocus) previousFocus.focus();
    }
    listenToDirectory(root, 'click', function (event) {
      if (event.target.closest('[data-ve-staff-filters-open]') === trigger) {
        event.preventDefault();
        if (panel.classList.contains('ve-staff-filters--open')) { close(true); return; }
        previousFocus = trigger;
        if (!portal) createPortal();
        surface.appendChild(panel);
        panel.classList.add('ve-staff-filters--open');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        trigger.setAttribute('aria-expanded', 'true');
        document.body.appendChild(portal);
        portal.showModal();
        (focusable()[0] || panel).focus();
      } else if (event.target.closest('[data-ve-staff-filters-close]')) {
        event.preventDefault();
        close(true);
      }
    });
    listenToDirectory(root, 'keydown', function (event) {
      if (!panel.classList.contains('ve-staff-filters--open')) return;
      if (event.key === 'Escape') { event.preventDefault(); close(true); }
      if (event.key === 'Tab') {
        var fields = focusable();
        var first = fields[0] || panel;
        var last = fields[fields.length - 1] || panel;
        if (event.shiftKey && event.target === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && event.target === last) { event.preventDefault(); first.focus(); }
      }
    });

    root.__veStaffDisposePanel = function () {
      close(false);

    };
  }

  function replaceSiteDomain(root) {
    var domain = windowObject.location.hostname.replace(/^www\./, '');
    all(root, '.employee-list a[href*="{{sitedomain}}"] ').forEach(function (link) {
      link.href = link.getAttribute('href').replace('{{sitedomain}}', domain);
    });
  }

  function bindSuggestions(root) {
    var dialog;
    var opener;
    var request;
    function close() {
      if (request) { request.abort(); request = null; }
      if (dialog) { if (dialog.open) dialog.close(); dialog.remove(); dialog = null; }
      if (opener && opener.isConnected) opener.focus();
    }
    function onClick(event) {
      var button = event.target.closest('.ve-suggest-edit-btn');
      if (!button) return;
      event.preventDefault(); event.stopPropagation();
      var scope = root.closest('.ve-staff-embed') || root.parentNode;
      var template = scope.querySelector('.ve-suggest-edit-modal .vemodal-content');
      if (!template) return;
      close(); opener = button;
      dialog = document.createElement('dialog');
      dialog.className = 've-suggest-dialog';
      dialog.setAttribute('aria-label', 'Suggest an Edit');
      dialog.appendChild(template.cloneNode(true));
      // Avoid duplicate IDs with the hidden template or another directory instance.
      var idPrefix = 've-suggestion-' + Math.random().toString(36).slice(2) + '-';
      all(dialog, '[id]').forEach(function (element) {
        var oldId = element.id;
        all(dialog, 'label[for]').forEach(function (label) {
          if (label.htmlFor === oldId) label.htmlFor = idPrefix + oldId;
        });
        element.id = idPrefix + oldId;
      });
      var form = dialog.querySelector('form');
      if (!form) { close(); return; }
      var employee = button.getAttribute('data-employee-name') || '';
      dialog.querySelector('[data-suggest-edit-employee]').textContent = employee;
      form.elements.namedItem('staff_post_id').value = button.getAttribute('data-staff-post-id') || '';
      form.elements.namedItem('employee_name').value = employee;
      form.elements.namedItem('employee_name_display').value = employee;
      all(form, '[data-current-value]').forEach(function (field) {
        var key = field.getAttribute('data-current-value');
        var current = button.getAttribute('data-current-' + key.replace(/_/g, '-')) || '';
        field.value = current || 'Not set';
        form.elements.namedItem('current_' + key).value = current;
      });
      dialog.addEventListener('cancel', function (event) { event.preventDefault(); close(); });
      dialog.addEventListener('click', function (event) {
        if (event.target.closest('[data-suggest-edit-close]')) { event.preventDefault(); close(); }
      });
      form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (request || form.hasAttribute('data-submitted') || !form.reportValidity()) return;
        var status = form.querySelector('.ve-suggest-edit-status');
        var submit = form.querySelector('[type="submit"]');
        var endpoint;
        try {
          endpoint = new URL(form.getAttribute('data-submit-url'));
          if (endpoint.origin !== 'https://staff.eideweb.com' && endpoint.origin !== windowObject.location.origin) throw new Error();
          if (!endpoint.pathname.endsWith('/admin-ajax.php')) throw new Error();
        } catch (_) { status.textContent = 'Suggestion service is unavailable. Reload the directory and try again.'; return; }
        var data = new URLSearchParams(new FormData(form));
        data.set('action', 've_staff_suggest_edit');
        data.set('nonce', form.getAttribute('data-submit-nonce') || '');
        data.set('list_id', root.getAttribute('data-list-id') || '');
        request = new AbortController();
        var currentRequest = request;
        var timeout = windowObject.setTimeout(function () { currentRequest.abort(); }, 30000);
        submit.disabled = true; status.textContent = 'Submitting your suggestion…';
        try {
          var response = await fetch(endpoint.toString(), { method: 'POST', body: data, credentials: 'same-origin', redirect: 'error', signal: request.signal });
          var result = await response.json();
          if (!response.ok || !result.success) throw new Error(result.data && result.data.message || 'Unable to submit your suggestion. Reload the directory and try again.');
          status.textContent = result.data.message;
          all(form, 'input:not([type="hidden"])').forEach(function (field) { field.disabled = true; });
          form.setAttribute('data-submitted', 'true');
        } catch (error) {
          status.textContent = error instanceof TypeError || error.name === 'AbortError' ? 'Could not confirm submission. Please check before trying again.' : error.message;
        } finally {
          windowObject.clearTimeout(timeout);
          if (request === currentRequest) request = null;
          submit.disabled = form.hasAttribute('data-submitted');
        }
      });
      root.appendChild(dialog);
      dialog.showModal();
      form.elements.namedItem('requester_name').focus();
    }
    // Capture before host Bootstrap handlers can attempt to open the hidden template.
    root.addEventListener('click', onClick, true);
    all(root, '.ve-suggest-edit-btn').forEach(function (button) { button.textContent = 'Suggest an Edit'; });
    root.__veStaffDisposeSuggestions = function () { root.removeEventListener('click', onClick, true); close(); };
  }

  function initialize(root) {
    if (!root) return;
    // Embed callbacks pass a wrapper, while document startup finds the listing.
    // Always bind to the listing itself so bubbling cannot toggle a panel twice.
    if (!root.matches || !root.matches('#veStaffList, #veStaffDisplay')) {
      all(root, '#veStaffList, #veStaffDisplay').forEach(initialize);
      return;
    }
    if (root.__veStaffInitialized) {
      return;
    }
    root.__veStaffInitialized = true;
    root.__veStaffDisposed = false;
    setHidden(all(root, '.loading-section'), true);
    prepareDirectory(root);
    bindFilterPanel(root);
    bindFilters(root);
    bindSuggestions(root);
    replaceSiteDomain(root);
    if (namespace.lazyLoad) {
      root.__veStaffDisposeImages = namespace.lazyLoad.initialize(root);
    }
  }

  namespace.initialize = initialize;
  namespace.dispose = function (scope) {
    var roots = scope.matches && scope.matches('#veStaffList, #veStaffDisplay') ? [scope] : all(scope, '#veStaffList, #veStaffDisplay');
    roots.forEach(function (root) {
      root.__veStaffDisposed = true;
      if (root.__veStaffDisposeSelect2) root.__veStaffDisposeSelect2();
      if (root.__veStaffDisposePanel) root.__veStaffDisposePanel();
      if (root.__veStaffDisposeSuggestions) root.__veStaffDisposeSuggestions();
      if (root.__veStaffDisposeImages) root.__veStaffDisposeImages();
    });
  };
  windowObject.VEStaffDirectory = namespace;

  function initializeDocument() {
    all(document, '#veStaffList, #veStaffDisplay').forEach(function (container) {
      initialize(container);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeDocument, { once: true });
  } else {
    initializeDocument();
  }
}(window));
