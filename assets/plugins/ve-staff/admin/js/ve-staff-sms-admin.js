jQuery(document).ready( function() {

   jQuery("#purge-sms-subscribers-btn").click( function(e) {
      e.preventDefault(); 

      jQuery.ajax({
         dataType: 'JSON',
         url : veAjax.ajaxurl,
         data : {
            action: "ve_sms_purge"
         },
         success: function(response) {
            if(response.type == "success") {
               jQuery("#purge-sms-subscribers-response").html(response.purge_data);
            }
            else {
               jQuery("#purge-sms-subscribers-response").html(response.purge_data);
               alert("SMS Purge Error");
            }
         }
      })   

   })

})

jQuery(function ($) {
	function nameOnly(text) { return (text || '').split('|')[0].trim(); }
	$('.ve-sms-recipients').each(function () {
		if (!$.fn.select2) return;
		$(this).select2({width: '100%', placeholder: 'Search staff by name…',
			templateSelection: function (item) { return nameOnly(item.text); },
			ajax: {url: veAjax.ajaxurl, dataType: 'json', delay: 250, data: function (params) {
				return {action: 've_sms_search_recipients', nonce: veAjax.nonce, q: params.term || ''};
			}}
		});
	});
	function addRecipients($select, rows, replace) {
		if (replace) $select.empty();
		var selected = ($select.val() || []).map(String), added = 0;
		rows.forEach(function (row) {
			if (selected.indexOf(String(row.id)) !== -1) return;
			$select.find('option').filter(function () { return this.value === String(row.id); }).remove();
			$select.append(new Option(row.text, row.id, true, true));
			selected.push(String(row.id)); added++;
		});
		$select.trigger('change'); return added;
	}
	var $status = $('<p class="ve-sms-status" role="status" aria-live="polite"></p>');
	$('.ve-sms-load-recipients').filter(function () { return $(this).data('recipient-target') !== '#ve-sms-recipients'; }).on('click', function () {
		var $button = $(this), $select = $($button.data('recipient-target'));
		function values(name) {
			var $group = $('.ve-sms-group-filter[data-filter="' + name + '"]');
			if ($group.length) return $group.find('input:checked').map(function () { return this.value; }).get();
			var $field = $('[data-name="sms_msg_' + name + '"]');
			return $field.find('select').length ? ($field.find('select').val() || []) : $field.find('input:checked').map(function () { return this.value; }).get();
		}
		var locations = values('location'), departments = values('department');
		if (!locations.length || !departments.length) { $status.insertAfter($button).text('Choose at least one location and department first.'); return; }
		$button.prop('disabled', true); $status.insertAfter($button).text('Finding matching staff…');
		$.post(veAjax.ajaxurl, {action: 've_sms_load_recipients', nonce: veAjax.nonce, locations: locations, departments: departments})
			.done(function (response) {
				if (!response.success) { $status.text(response.data.message); return; }
				addRecipients($select, response.data, true);
				$status.text(response.data.length + ' staff selected. Remove names or search to add more below.');
			}).fail(function () { $status.text('Unable to load staff. Your list has not changed. Please try again.'); })
			.always(function () { $button.prop('disabled', false); });
	});

	var $slot = $('#ve-sms-recipient-slot');
	if (!$slot.length) return;
	$('body').addClass('ve-sms-composer');
	var $box = $('#ve-sms-recipients-box'), $select = $('#ve-sms-recipients');
	$slot.append($box.find('.inside').children()); $box.remove();
	var $load = $slot.find('.ve-sms-load-recipients');
	var $filters = $('[data-name="sms_msg_location"] select, [data-name="sms_msg_department"] select');
	var $matches = $('#ve-sms-filter-preview');
	var $matchStatus = $('<p class="ve-sms-match-status" role="status" aria-live="polite"></p>').appendTo($matches);
	var $matchList = $('<ul class="ve-sms-match-list" aria-label="Staff matching your filters"></ul>').appendTo($matches);
	$matches.append('<p class="description">Preview only. Setting recipients replaces your current list with these staff. You can then add or remove names below.</p>');
	var $action = $('<div class="ve-sms-filter-action"></div>').appendTo($matches);
	$load.text('Set recipients from Filters').prop('disabled', true).appendTo($action);
	var $retry = $('<button type="button" class="button ve-sms-retry-preview">Retry preview</button>').hide().appendTo($action);
	var matches = null, request = null, timer = null, revision = 0, normalizing = false;
	function filterValues(name) { return $('[data-name="sms_msg_' + name + '"] select').val() || []; }
	function refreshMatches() {
		var currentRevision = ++revision;
		clearTimeout(timer);
		if (request) { request.abort(); request = null; }
		matches = null; $load.prop('disabled', true); $retry.hide(); $matchList.empty();
		var locations = filterValues('location'), departments = filterValues('department');
		if (!locations.length || !departments.length) {
			$matches.attr('aria-busy', 'false');
			$matchStatus.text('Choose a location and department (or All) to preview matching staff.'); return;
		}
		$matches.attr('aria-busy', 'true'); $matchStatus.text('Finding matching staff…');
		timer = setTimeout(function () {
			request = $.post(veAjax.ajaxurl, {action: 've_sms_load_recipients', nonce: veAjax.nonce, locations: locations, departments: departments})
				.done(function (response) {
					if (currentRevision !== revision) return;
					if (!response.success || !Array.isArray(response.data)) {
						$matchStatus.text('Unable to preview staff. Your selected recipients have not changed.'); $retry.show(); return;
					}
					matches = response.data.slice().sort(function (a, b) { return nameOnly(a.text).localeCompare(nameOnly(b.text)); });
					matches.forEach(function (row) { $('<li class="ve-sms-match-pill"></li>').text(nameOnly(row.text)).appendTo($matchList); });
					$matchStatus.text(matches.length ? matches.length + ' staff match your filters. Review the names before setting recipients.' : 'No eligible staff match these filters. Your selected recipients have not changed.');
					$load.prop('disabled', matches.length === 0);
				}).fail(function (xhr, status) {
					if (currentRevision !== revision || status === 'abort') return;
					$matchStatus.text('Unable to preview staff. Your selected recipients have not changed.'); $retry.show();
				}).always(function () { if (currentRevision === revision) { request = null; $matches.attr('aria-busy', 'false'); } });
		}, 250);
	}
	$filters.each(function () { $(this).data('ve-sms-previous', $(this).val() || []); });
	$filters.on('change.veSmsPreview', function () {
		if (normalizing) return;
		var $filter = $(this), values = $filter.val() || [], previous = $filter.data('ve-sms-previous') || [];
		if (values.length > 1 && values.indexOf('__all') !== -1) {
			values = previous.indexOf('__all') === -1 ? ['__all'] : values.filter(function (value) { return value !== '__all'; });
			normalizing = true; $filter.val(values).trigger('change.select2'); normalizing = false;
		}
		$filter.data('ve-sms-previous', values.slice()); refreshMatches();
	});
	$retry.on('click', refreshMatches);
	$load.on('click', function () {
		if (!matches || !matches.length || $load.prop('disabled')) return;
		addRecipients($select, matches, true);
		$matchStatus.text(matches.length + ' recipients set from your filters. You can now add or remove names below.');
	});
	refreshMatches();
	$slot.find('p.description').text('Only the names selected here will receive this message. Search to add staff, or use × to remove a person.');
	$select.attr('aria-label', 'Selected recipients');
	var $count = $('<p class="ve-sms-count" role="status"></p>').prependTo($slot);
	$select.on('change', function () { $count.text(($select.val() || []).length + ' recipients selected'); }).trigger('change');
	var $imports = $('<details class="ve-sms-import"><summary>Import or paste recipients</summary><p>Add staff using phone numbers or work email addresses. Only matching staff you can message are added; duplicates, unknown, ambiguous and SMS-disabled contacts are skipped.</p><p><a download="staff-sms-recipients.csv" href="data:text/csv;charset=utf-8,phone%2Cemail%0A">Download CSV template</a> · Fill either column for each person. Maximum 2,000 contacts / 200 KB.</p><label for="ve-sms-file">Upload CSV</label><input type="file" id="ve-sms-file" accept=".csv,text/csv"><label for="ve-sms-paste">Or paste phone numbers and email addresses</label><textarea id="ve-sms-paste" rows="4" placeholder="6055550100, person@example.com"></textarea><button type="button" class="button ve-sms-import-button">Match and add staff</button><p class="ve-sms-import-status" role="status" aria-live="polite"></p></details>').appendTo($slot);
	$imports.find('button').on('click', async function () {
		var $button = $(this), $result = $imports.find('.ve-sms-import-status'), file = $('#ve-sms-file')[0].files[0];
		$button.prop('disabled', true);
		try {
			if (file && (!/\.csv$/i.test(file.name) || file.size > 200000)) throw new Error('Choose a CSV file smaller than 200 KB.');
			var contacts = (file ? await file.text() : '') + '\n' + $('#ve-sms-paste').val();
			if (!contacts.trim()) throw new Error('Choose a CSV or paste contacts first.');
			if (new Blob([contacts]).size > 200000) throw new Error('Keep the combined file and paste list below 200 KB.');
			$result.text('Matching staff…');
			var response = await $.post(veAjax.ajaxurl, {action: 've_sms_import_recipients', nonce: veAjax.nonce, contacts: contacts});
			if (!response.success) throw new Error(response.data.message);
			var data = response.data, added = addRecipients($select, data.recipients, false);
			$result.text(added + ' added; ' + (data.duplicates + data.recipients.length - added) + ' duplicates skipped; ' + data.skipped + ' unmatched, unavailable or ambiguous contacts skipped.');
		} catch (error) { $result.text(error.responseJSON && error.responseJSON.data ? error.responseJSON.data.message : (error.message || 'Import failed. Please try again.')); }
		finally { $button.prop('disabled', false); }
	});
	var $group = $slot.closest('.acf-postbox');
	var $title = $('#title');
	if ($title.length && !$title.val().trim() && document.body.classList.contains('post-new-php')) {
		var created = new Date();
		$title.val('SMS Message - ' + new Intl.DateTimeFormat(undefined, {dateStyle: 'medium', timeStyle: 'short'}).format(created));
	}
	var $navigation = $('<div class="ve-sms-navigation"><button type="button" class="button ve-sms-back">Back</button><span class="ve-sms-step-label"></span><button type="button" class="button button-primary ve-sms-next">Continue</button></div>').appendTo($group.find('> .inside'));
	var $delivery = $('[data-name="scheduled_message"] .acf-input');
	var $publishBox = $('#postbox-container-1 #submitdiv').first();
	if (!$publishBox.length) $publishBox = $('#submitdiv').first();
	if ($delivery.length && $publishBox.length) {
		var $publishActions = $('<div class="ve-sms-publish-actions"></div>');
		$publishActions.append($publishBox.find('#minor-publishing-actions').first(), $publishBox.find('#major-publishing-actions').first());
		$delivery.append($publishActions);
		$('.ve-sms-composer #submitdiv').hide();
	}
	function tabs() { return $group.find('.acf-tab-wrap .acf-tab-button'); }
	function current() { return tabs().index(tabs().filter(function () { return $(this).parent().hasClass('active'); })); }
	function updateStep() {
		var index = current();
		$navigation.find('.ve-sms-back').prop('disabled', index <= 0);
		$navigation.find('.ve-sms-next').toggle(index < 2);
		$navigation.find('.ve-sms-step-label').text('Step ' + (index + 1) + ' of 3');
		$publishActions && $publishActions.toggle(index === 2);
	}
	$navigation.on('click', 'button', function () { var index = current() + ($(this).hasClass('ve-sms-next') ? 1 : -1); tabs().eq(index).trigger('click').trigger('focus'); updateStep(); });
	$group.on('click', '.acf-tab-button', function () { setTimeout(updateStep, 0); });
	var $text = $('[data-name="sms_msg_text"] textarea');
	var $preview = $('<div class="ve-sms-preview"><span>MESSAGE PREVIEW</span><p></p><small></small></div>').insertAfter($text);
	$text.on('input', function () { $preview.find('p').text(this.value || 'Your message will appear here.'); $preview.find('small').text(this.value.length + ' / 1,400 characters · Sender and opt-out text may be added when sent.'); }).trigger('input');
	$('[data-name="scheduled_message"] > .acf-input').prepend('<p>Send when you confirm, or choose a future date and time in the site timezone.</p>');
	function deliveryLabel() {
		var scheduled = $('[data-name="sms_msg_scheduled"] input[type="checkbox"]').prop('checked');
		$('#publish').val(scheduled ? 'Schedule message' : 'Send message now');
	}
	$('[data-name="sms_msg_scheduled"] input').on('change', deliveryLabel);
	if (window.acf) window.acf.addAction('ready', function () { updateStep(); deliveryLabel(); });
	setTimeout(function () { updateStep(); deliveryLabel(); }, 0);
});
