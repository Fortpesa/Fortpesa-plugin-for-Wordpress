(function () {
	'use strict';

	var POLL_MS = 3000;
	var MAX_MS = 150000;

	function init(form) {
		var box = form.parentNode.querySelector('.fortpesa-status');
		var btn = form.querySelector('.fortpesa-submit');
		var label = btn.textContent;
		var statusBase = form.getAttribute('data-status');
		var redirect = form.getAttribute('data-redirect');
		var busy = false;

		function show(type, msg) {
			box.className = 'fortpesa-status is-' + type;
			box.textContent = msg;
			box.hidden = false;
		}
		function lock(on) {
			busy = on;
			btn.disabled = on;
			btn.textContent = on ? '...' : label;
		}
		function api(url, opts) {
			return fetch(url, opts).then(function (r) {
				return r.json().then(function (j) { return { ok: r.ok, data: j }; });
			});
		}

		Array.prototype.forEach.call(form.querySelectorAll('.fortpesa-preset'), function (b) {
			b.addEventListener('click', function () {
				var a = form.querySelector('[name=amount]');
				if (a) { a.value = b.getAttribute('data-amount'); }
			});
		});

		function poll(token) {
			var start = Date.now();
			var netErrors = 0;
			(function tick() {
				api(statusBase + token, { headers: { Accept: 'application/json' } }).then(function (res) {
					netErrors = 0;
					var s = res.data || {};
					if (s.terminal) {
						if (s.status === 'success') {
							show('success', s.message);
							Array.prototype.forEach.call(form.querySelectorAll('input,button'), function (el) { el.disabled = true; });
							btn.textContent = label;
							if (redirect) { setTimeout(function () { window.location.href = redirect; }, 1500); }
						} else {
							show('error', s.message);
							lock(false);
						}
						return;
					}
					if (Date.now() - start > MAX_MS) {
						show('warning', 'We have not received confirmation yet. If you completed the payment on your phone it will be confirmed shortly, so please do not pay again.');
						lock(false);
						return;
					}
					setTimeout(tick, POLL_MS);
				}).catch(function () {
					if (++netErrors > 5 || Date.now() - start > MAX_MS) {
						show('warning', 'Connection problem while checking your payment. If you were prompted, complete it on your phone; we will record it automatically.');
						lock(false);
					} else {
						setTimeout(tick, POLL_MS);
					}
				});
			})();
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (busy) { return; }
			if (!form.checkValidity()) { form.reportValidity(); return; }

			var payload = {};
			Array.prototype.forEach.call(form.querySelectorAll('input[name]'), function (el) {
				payload[el.name] = el.value;
			});

			lock(true);
			show('info', 'Sending the M-Pesa prompt to your phone...');

			api(form.getAttribute('data-pay'), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				body: JSON.stringify(payload)
			}).then(function (res) {
				if (!res.ok) {
					show('error', (res.data && res.data.message) || 'Something went wrong. Please try again.');
					lock(false);
					return;
				}
				show('info', res.data.message);
				poll(res.data.token);
			}).catch(function () {
				show('error', 'Network error. Please check your connection and try again.');
				lock(false);
			});
		});
	}

	function ready() {
		Array.prototype.forEach.call(document.querySelectorAll('.fortpesa-form'), init);
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', ready);
	} else {
		ready();
	}
})();
