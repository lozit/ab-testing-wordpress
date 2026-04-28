(function () {
	'use strict';

	if (typeof window.Chart === 'undefined') {
		return;
	}

	// Each canvas is rendered with a sibling <script type="application/json" class="abtest-chart-data">.
	var palette = [
		'#1993fd', '#956eff', '#f067d8', '#00a32a', '#dba617',
		'#b32d2e', '#0a4b78', '#856404', '#155724', '#50575e'
	];

	function colorFor(index) {
		return palette[index % palette.length];
	}

	function variantDash(variant) {
		// Variant A = solid, Variant B = dashed — same color per experiment.
		return variant === 'B' ? [6, 4] : [];
	}

	document.querySelectorAll('canvas.abtest-url-chart').forEach(function (canvas) {
		var holder = canvas.nextElementSibling;
		if (!holder || !holder.classList.contains('abtest-chart-data')) {
			return;
		}
		var data;
		try {
			data = JSON.parse(holder.textContent);
		} catch (e) {
			return;
		}
		if (!data || !data.days || !data.series) {
			return;
		}

		// Group series by experiment_id so we can assign a color per experiment.
		var expIds = Object.keys(data.series).reduce(function (acc, key) {
			var s = data.series[key];
			if (acc.indexOf(s.experiment_id) === -1) acc.push(s.experiment_id);
			return acc;
		}, []);

		var datasets = Object.keys(data.series).map(function (key) {
			var s = data.series[key];
			var color = colorFor(expIds.indexOf(s.experiment_id));
			var label = (data.titles && data.titles[s.experiment_id] ? data.titles[s.experiment_id] : '#' + s.experiment_id) + ' — Variant ' + s.variant;
			return {
				label: label,
				data: s.rates,
				borderColor: color,
				backgroundColor: color + '33',
				borderDash: variantDash(s.variant),
				tension: 0.25,
				pointRadius: 2,
				pointHoverRadius: 5,
				spanGaps: true,
				_impressions: s.impressions,
				_conversions: s.conversions
			};
		});

		new window.Chart(canvas, {
			type: 'line',
			data: { labels: data.days, datasets: datasets },
			options: {
				responsive: true,
				maintainAspectRatio: false,
				interaction: { mode: 'index', intersect: false },
				plugins: {
					legend: { position: 'bottom', labels: { boxWidth: 16, font: { size: 11 } } },
					tooltip: {
						callbacks: {
							label: function (ctx) {
								var ds = ctx.dataset;
								var i = ctx.dataIndex;
								var imp = ds._impressions ? ds._impressions[i] : 0;
								var cv = ds._conversions ? ds._conversions[i] : 0;
								var rate = ctx.parsed.y === null ? '—' : ctx.parsed.y.toFixed(2) + ' %';
								return ds.label + ': ' + rate + ' (' + cv + ' / ' + imp + ')';
							}
						}
					}
				},
				scales: {
					y: {
						title: { display: true, text: 'Conversion rate (%)' },
						beginAtZero: true,
						ticks: { callback: function (v) { return v + ' %'; } }
					},
					x: {
						title: { display: true, text: 'Day' },
						ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 }
					}
				}
			}
		});
	});
})();
