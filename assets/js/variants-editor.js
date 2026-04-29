(function () {
	'use strict';

	var container = document.querySelector('.abtest-variants');
	var addBtn = document.querySelector('.abtest-variant-add');
	if (!container || !addBtn) return;

	var max = parseInt(container.dataset.max, 10) || 4;
	var labels = ['A', 'B', 'C', 'D', 'E', 'F'];

	function rowCount() {
		return container.querySelectorAll('.abtest-variant-row').length;
	}

	function renumber() {
		var rows = container.querySelectorAll('.abtest-variant-row');
		rows.forEach(function (row, i) {
			row.dataset.index = i;
			var labelSpan = row.querySelector('.abtest-variant-label');
			if (labelSpan) labelSpan.textContent = labels[i] || '?';
			row.querySelectorAll('[name]').forEach(function (field) {
				field.name = field.name.replace(/variants\[\d+\]/, 'variants[' + i + ']');
			});
			// Remove the "Remove" button from the first row only.
			var rmBtn = row.querySelector('.abtest-variant-remove');
			if (i === 0 && rmBtn) rmBtn.remove();
		});
		updateAddBtn();
	}

	function updateAddBtn() {
		addBtn.disabled = rowCount() >= max;
	}

	function buildRow(index) {
		// Clone the first row's <select> options so the user picks from the same page list.
		var firstSelect = container.querySelector('.abtest-variant-select');
		var optionsHtml = firstSelect ? firstSelect.innerHTML : '';
		// First option in cloned list says "Select page" — for non-first rows, change that label.
		optionsHtml = optionsHtml.replace(
			/<option value="0">[^<]+<\/option>/,
			'<option value="0">— None / remove —</option>'
		);

		var div = document.createElement('div');
		div.className = 'abtest-variant-row';
		div.dataset.index = index;
		div.innerHTML =
			'<span class="abtest-variant-label">' + (labels[index] || '?') + '</span>' +
			'<select name="variants[' + index + '][post_id]" class="abtest-variant-select">' + optionsHtml + '</select>' +
			'<button type="button" class="button-link abtest-variant-remove" aria-label="Remove this variant">Remove</button>';
		// Reset selection on the clone.
		var sel = div.querySelector('select');
		if (sel) sel.value = '0';
		return div;
	}

	addBtn.addEventListener('click', function () {
		if (rowCount() >= max) return;
		var idx = rowCount();
		container.appendChild(buildRow(idx));
		renumber();
	});

	container.addEventListener('click', function (e) {
		var btn = e.target.closest('.abtest-variant-remove');
		if (!btn) return;
		var row = btn.closest('.abtest-variant-row');
		if (!row) return;
		row.remove();
		renumber();
	});

	updateAddBtn();
})();
