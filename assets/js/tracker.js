(function () {
	'use strict';

	if (typeof window.AbtestTracker === 'undefined') {
		return;
	}

	var cfg = window.AbtestTracker;

	function fireConversion() {
		try {
			fetch(cfg.restUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce
				},
				body: JSON.stringify({ experiment_id: cfg.experimentId }),
				keepalive: true
			});
		} catch (e) {
			// swallow — tracking must never break the page
		}
	}

	function matchesUrlGoal(href) {
		if (!cfg.goalValue) {
			return false;
		}
		try {
			var url = new URL(href, window.location.href);
			var goalUrl = new URL(cfg.goalValue, window.location.href);
			// path equality is the simple, predictable rule for v1
			return url.pathname.replace(/\/$/, '') === goalUrl.pathname.replace(/\/$/, '');
		} catch (e) {
			return false;
		}
	}

	if (cfg.goalType === 'url' && cfg.goalValue) {
		document.addEventListener(
			'click',
			function (event) {
				var anchor = event.target && event.target.closest ? event.target.closest('a[href]') : null;
				if (!anchor) {
					return;
				}
				if (matchesUrlGoal(anchor.getAttribute('href'))) {
					fireConversion();
				}
			},
			true
		);
	}

	if (cfg.goalType === 'selector' && cfg.goalValue) {
		document.addEventListener(
			'click',
			function (event) {
				var target = event.target;
				if (!target || !target.closest) {
					return;
				}
				try {
					if (target.closest(cfg.goalValue)) {
						fireConversion();
					}
				} catch (e) {
					// invalid selector — ignore
				}
			},
			true
		);
	}
})();
