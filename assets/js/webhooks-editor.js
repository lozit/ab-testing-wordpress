(function () {
	'use strict';

	var container = document.querySelector('.abtest-webhooks');
	var addBtn = document.querySelector('.abtest-webhook-add');
	if (!container || !addBtn) return;

	function nextIndex() {
		return container.querySelectorAll('.abtest-webhook-row').length;
	}

	function rowTemplate(index) {
		return (
			'<div class="abtest-webhook-row">' +
				'<div class="abtest-webhook-head">' +
					'<label>Name: <input type="text" name="webhooks[' + index + '][name]" placeholder="e.g. Slack alerts"></label>' +
					'<label class="abtest-webhook-enabled"><input type="checkbox" name="webhooks[' + index + '][enabled]" value="1" checked> Enabled</label>' +
					'<button type="button" class="button-link abtest-webhook-remove" aria-label="Remove this webhook">Remove</button>' +
				'</div>' +
				'<div class="abtest-webhook-fields">' +
					'<label>URL <input type="url" name="webhooks[' + index + '][url]" class="large-text code" placeholder="https://hooks.zapier.com/hooks/catch/..."></label>' +
					'<label>Secret (optional) <input type="text" name="webhooks[' + index + '][secret]" class="large-text code" autocomplete="off"><small>HMAC SHA256 signature header when set.</small></label>' +
					'<label>Fire on: ' +
						'<select name="webhooks[' + index + '][fire_on]">' +
							'<option value="all">All events (impressions + conversions)</option>' +
							'<option value="conversion">Conversions only (low volume)</option>' +
						'</select>' +
					'</label>' +
				'</div>' +
			'</div>'
		);
	}

	function renumberRows() {
		var rows = container.querySelectorAll('.abtest-webhook-row');
		rows.forEach(function (row, i) {
			row.querySelectorAll('[name]').forEach(function (field) {
				field.name = field.name.replace(/webhooks\[\d+\]/, 'webhooks[' + i + ']');
			});
		});
	}

	addBtn.addEventListener('click', function () {
		var msg = container.querySelector('.abtest-webhooks-empty');
		if (msg) msg.remove();
		container.insertAdjacentHTML('beforeend', rowTemplate(nextIndex()));
		// Note: newly-added webhooks need to be saved before "Send test event" works
		// (the test button uses the stored array index, not the in-form one).
	});

	container.addEventListener('click', function (e) {
		var btn = e.target.closest('.abtest-webhook-remove');
		if (!btn) return;
		var row = btn.closest('.abtest-webhook-row');
		if (!row) return;
		row.remove();
		renumberRows();
		if (!container.querySelector('.abtest-webhook-row')) {
			var emptyMsg = container.dataset.emptyMsg || 'No webhooks configured.';
			container.insertAdjacentHTML('beforeend', '<p class="abtest-webhooks-empty">' + emptyMsg + '</p>');
		}
	});
})();
