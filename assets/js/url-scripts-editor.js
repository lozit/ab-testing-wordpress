(function () {
	'use strict';

	var container = document.querySelector('.abtest-url-scripts');
	var addBtn = document.querySelector('.abtest-url-script-add');
	if (!container || !addBtn) return;

	function nextIndex() {
		var rows = container.querySelectorAll('.abtest-url-script-row');
		return rows.length;
	}

	function rowTemplate(index) {
		// Mirror the PHP-side row layout. Field names use [index][position|code].
		return (
			'<div class="abtest-url-script-row">' +
				'<div class="abtest-url-script-head">' +
					'<label>Position: ' +
						'<select name="url_scripts[' + index + '][position]">' +
							'<option value="after_body_open">After &lt;body&gt; opening tag</option>' +
							'<option value="before_body_close" selected>Before &lt;/body&gt; closing tag</option>' +
						'</select>' +
					'</label>' +
					'<button type="button" class="button-link abtest-url-script-remove" aria-label="Remove this script">Remove</button>' +
				'</div>' +
				'<textarea name="url_scripts[' + index + '][code]" rows="6" class="large-text code" placeholder="&lt;script&gt;gtag(...)&lt;/script&gt;"></textarea>' +
			'</div>'
		);
	}

	function renumberRows() {
		var rows = container.querySelectorAll('.abtest-url-script-row');
		rows.forEach(function (row, i) {
			row.querySelectorAll('[name]').forEach(function (field) {
				field.name = field.name.replace(/url_scripts\[\d+\]/, 'url_scripts[' + i + ']');
			});
		});
	}

	function clearEmptyMessage() {
		var msg = container.querySelector('.abtest-url-scripts-empty');
		if (msg) msg.remove();
	}

	addBtn.addEventListener('click', function () {
		clearEmptyMessage();
		var idx = nextIndex();
		container.insertAdjacentHTML('beforeend', rowTemplate(idx));
		// Focus the new textarea for immediate paste.
		var rows = container.querySelectorAll('.abtest-url-script-row');
		var last = rows[rows.length - 1];
		if (last) {
			var ta = last.querySelector('textarea');
			if (ta) ta.focus();
		}
	});

	container.addEventListener('click', function (e) {
		var btn = e.target.closest('.abtest-url-script-remove');
		if (!btn) return;
		var row = btn.closest('.abtest-url-script-row');
		if (!row) return;
		row.remove();
		renumberRows();
		// Re-show empty message if we removed the last one.
		if (!container.querySelector('.abtest-url-script-row')) {
			var msg = container.dataset.emptyMsg || 'No scripts yet.';
			container.insertAdjacentHTML('beforeend', '<p class="abtest-url-scripts-empty">' + msg + '</p>');
		}
	});
})();
