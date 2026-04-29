(function () {
	'use strict';

	var dz = document.querySelector('.abtest-html-dropzone');
	if (!dz) return;

	var input = dz.querySelector('input[type="file"]');
	var hint = dz.querySelector('.abtest-html-dropzone-hint');
	var meta = dz.querySelector('.abtest-html-dropzone-meta');
	var previewRow = document.querySelector('.abtest-html-preview-row');
	var previewFrame = previewRow ? previewRow.querySelector('.abtest-html-preview-frame') : null;
	var maxBytes = parseInt(dz.dataset.maxBytes, 10) || 5 * 1024 * 1024;

	function humanSize(bytes) {
		if (bytes < 1024) return bytes + ' B';
		if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
		return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
	}

	function showError(msg) {
		meta.textContent = msg;
		meta.style.color = '#b32d2e';
		meta.hidden = false;
		if (previewRow) previewRow.hidden = true;
	}

	function showFile(file) {
		var name = file.name || '';
		var lower = name.toLowerCase();
		var isZip = /\.zip$/.test(lower);
		if (!/\.html?$/.test(lower) && !isZip) {
			showError('Only .html, .htm and .zip files are accepted (got: ' + name + ').');
			input.value = '';
			return;
		}
		if (file.size > maxBytes) {
			showError('File too large: ' + humanSize(file.size) + ' (max: ' + humanSize(maxBytes) + ').');
			input.value = '';
			return;
		}

		meta.style.color = '';
		meta.textContent = '✓ ' + name + ' — ' + humanSize(file.size) + (isZip ? '  ·  preview unavailable for .zip (assets extracted on submit)' : '');
		meta.hidden = false;

		// .zip can't be previewed in the iframe (binary, contains assets that need to be extracted server-side).
		if (isZip) {
			if (previewRow) previewRow.hidden = true;
			return;
		}

		// Render HTML preview into the sandboxed iframe via FileReader → srcdoc.
		if (previewFrame && previewRow) {
			var reader = new FileReader();
			reader.onload = function (e) {
				previewFrame.srcdoc = e.target.result || '';
				previewRow.hidden = false;
			};
			reader.onerror = function () {
				showError('Could not read the file for preview.');
			};
			reader.readAsText(file);
		}
	}

	// Apply file from dropzone to the input element so the form submits it.
	function setInputFile(file) {
		try {
			var dt = new DataTransfer();
			dt.items.add(file);
			input.files = dt.files;
		} catch (e) {
			// Browsers without DataTransfer API can't programmatically set file input.
			showError('Drag & drop not supported in this browser. Please use the Browse button.');
		}
	}

	['dragenter', 'dragover'].forEach(function (ev) {
		dz.addEventListener(ev, function (e) {
			e.preventDefault();
			e.stopPropagation();
			dz.classList.add('is-dragover');
		});
	});
	['dragleave', 'dragend', 'drop'].forEach(function (ev) {
		dz.addEventListener(ev, function (e) {
			e.preventDefault();
			e.stopPropagation();
			dz.classList.remove('is-dragover');
		});
	});

	dz.addEventListener('drop', function (e) {
		var files = e.dataTransfer && e.dataTransfer.files;
		if (!files || files.length === 0) return;
		var file = files[0];
		setInputFile(file);
		showFile(file);
	});

	input.addEventListener('change', function () {
		var file = input.files && input.files[0];
		if (file) showFile(file);
	});
})();
