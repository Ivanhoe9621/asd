/* ALB Employee Portal — app sin frameworks contra la API REST propia.
   Misma filosofía que alb-catalog: vanilla JS, render por vista, estados
   de carga con skeletons y errores visibles pero no destructivos. */

(function () {
	'use strict';

	var CFG = window.ALB_EP_CONFIG || {};
	var I18N = CFG.i18n || {};
	var root = document.getElementById('alb-ep-portal');
	if (!root || !CFG.root) {
		return;
	}

	var el = {
		hello: root.querySelector('[data-ep="hello"]'),
		title: root.querySelector('[data-ep="title"]'),
		tabs: root.querySelector('[data-ep="tabs"]'),
		view: root.querySelector('[data-ep="view"]'),
		toast: root.querySelector('[data-ep="toast"]')
	};

	var state = { me: null, view: 'services' };

	// ---------------- utilidades ----------------

	function esc(value) {
		var div = document.createElement('div');
		div.textContent = value == null ? '' : String(value);
		return div.innerHTML;
	}

	function api(path, options) {
		options = options || {};
		options.headers = Object.assign(
			{ 'X-WP-Nonce': CFG.nonce, 'Content-Type': 'application/json' },
			options.headers || {}
		);
		options.credentials = 'same-origin';
		return fetch(CFG.root + path, options).then(function (response) {
			return response.json().then(function (body) {
				if (!response.ok) {
					var error = new Error((body && body.message) || I18N.error_generic);
					error.code = body && body.code;
					error.status = response.status;
					throw error;
				}
				return body;
			});
		});
	}

	var toastTimer = null;
	function toast(message, bad) {
		el.toast.textContent = message;
		el.toast.classList.toggle('alb-ep__toast--bad', !!bad);
		el.toast.hidden = false;
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () {
			el.toast.hidden = true;
		}, 3200);
	}

	function fail(error) {
		if (error && error.status === 501) {
			toast(I18N.write_pending, true);
		} else {
			toast((error && error.message) || I18N.error_generic, true);
		}
	}

	function skeletons(count) {
		var html = '<div class="alb-ep__grid">';
		for (var i = 0; i < count; i++) {
			html += '<div class="alb-ep-skel"></div>';
		}
		return html + '</div>';
	}

	function empty() {
		return '<div class="alb-ep-empty">' + esc(I18N.empty) + '</div>';
	}

	function money(amount) {
		if (amount == null) {
			return '—';
		}
		return '$' + Number(amount).toFixed(2);
	}

	function formDataFrom(form) {
		var data = {};
		Array.prototype.forEach.call(form.elements, function (input) {
			if (!input.name) {
				return;
			}
			if (input.type === 'checkbox') {
				data[input.name] = input.checked;
			} else if (input.value !== '') {
				data[input.name] = input.value;
			}
		});
		return data;
	}

	// ---------------- pestañas ----------------

	var VIEWS = [
		{ key: 'services', label: I18N.services, render: renderServices },
		{ key: 'requests', label: I18N.requests, render: renderRequests },
		{ key: 'customers', label: I18N.customers, render: renderCustomers },
		{ key: 'agenda', label: I18N.agenda, render: renderAgenda },
		{ key: 'stats', label: I18N.stats, render: renderStats }
	];

	function renderTabs() {
		el.tabs.innerHTML = VIEWS.map(function (view) {
			return '<button class="alb-ep__tab" role="tab" aria-selected="' +
				(view.key === state.view) + '" data-view="' + view.key + '">' +
				esc(view.label) + '</button>';
		}).join('');
	}

	el.tabs.addEventListener('click', function (event) {
		var button = event.target.closest('[data-view]');
		if (!button) {
			return;
		}
		state.view = button.getAttribute('data-view');
		root.setAttribute('data-view', state.view);
		renderTabs();
		route();
	});

	function route() {
		var view = VIEWS.filter(function (v) { return v.key === state.view; })[0];
		if (view) {
			view.render();
		}
	}

	// ---------------- vista: servicios ----------------

	function renderServices() {
		el.view.innerHTML = skeletons(3);
		api('/services').then(function (body) {
			var items = body.items || [];
			if (!items.length) {
				el.view.innerHTML = empty();
				return;
			}
			el.view.innerHTML = '<div class="alb-ep__grid alb-ep__grid--2">' +
				items.map(serviceCard).join('') + '</div>';
		}).catch(function (error) {
			el.view.innerHTML = empty();
			fail(error);
		});
	}

	function serviceCard(service) {
		var meta = service.portal_meta || {};
		var price = service.employee_price != null ? service.employee_price : service.base_price;
		var visible = meta.visible !== false;

		return '<article class="alb-ep-card alb-ep-card--hover" data-service="' + esc(service.id) + '">' +
			'<div class="alb-ep-card__row">' +
				'<h3 class="alb-ep-card__name">' + esc(service.name) + '</h3>' +
				'<span class="alb-ep-card__price">' + money(price) + '</span>' +
			'</div>' +
			'<p class="alb-ep-card__meta">' + esc(service.category_name || '') +
				(service.duration ? ' · ' + Math.round(service.duration / 60) + ' min' : '') + '</p>' +
			'<p class="alb-ep-card__desc">' + esc(meta.short_description || '') + '</p>' +
			'<div class="alb-ep-card__row" style="margin-top:12px">' +
				'<span class="alb-ep-badge ' + (visible ? 'alb-ep-badge--ok' : 'alb-ep-badge--warn') + '">' +
					esc(visible ? I18N.visible : I18N.hidden) + '</span>' +
				'<button class="alb-ep-btn alb-ep-btn--ghost" data-edit>' + esc(I18N.edit) + '</button>' +
			'</div>' +
			'<form class="alb-ep-edit" hidden style="margin-top:12px">' +
				'<label class="alb-ep-field"><span>' + esc(I18N.description) + '</span>' +
					'<textarea name="short_description" rows="2">' + esc(meta.short_description || '') + '</textarea></label>' +
				'<label class="alb-ep-switch"><input type="checkbox" name="visible"' + (visible ? ' checked' : '') + '> ' +
					esc(I18N.visible) + '</label>' +
				'<div class="alb-ep-card__row" style="margin-top:10px">' +
					'<button type="button" class="alb-ep-btn alb-ep-btn--ghost" data-cancel>' + esc(I18N.cancel) + '</button>' +
					'<button type="submit" class="alb-ep-btn alb-ep-btn--primary">' + esc(I18N.save) + '</button>' +
				'</div>' +
			'</form>' +
		'</article>';
	}

	el.view.addEventListener('click', function (event) {
		var card = event.target.closest('[data-service]');
		if (!card) {
			return;
		}
		if (event.target.closest('[data-edit]')) {
			card.querySelector('.alb-ep-edit').hidden = false;
			event.target.closest('[data-edit]').hidden = true;
		}
		if (event.target.closest('[data-cancel]')) {
			renderServices();
		}
	});

	el.view.addEventListener('submit', function (event) {
		var form = event.target;

		if (form.classList.contains('alb-ep-edit')) {
			event.preventDefault();
			var card = form.closest('[data-service]');
			var data = formDataFrom(form);
			api('/services/' + encodeURIComponent(card.getAttribute('data-service')) + '/meta', {
				method: 'PUT',
				body: JSON.stringify({
					short_description: data.short_description || '',
					visible: !!data.visible
				})
			}).then(function () {
				toast(I18N.saved);
				renderServices();
			}).catch(fail);
		}

		if (form.hasAttribute('data-request-form')) {
			event.preventDefault();
			api('/service-requests', { method: 'POST', body: JSON.stringify(formDataFrom(form)) })
				.then(function () {
					toast(I18N.sent);
					renderRequests();
				}).catch(fail);
		}

		if (form.hasAttribute('data-customer-form')) {
			event.preventDefault();
			api('/customers', { method: 'POST', body: JSON.stringify(formDataFrom(form)) })
				.then(function () {
					toast(I18N.saved);
					renderCustomers();
				}).catch(fail);
		}
	});

	// ---------------- vista: propuestas ----------------

	var REQUEST_BADGES = { pending: '', approved: 'alb-ep-badge--ok', rejected: 'alb-ep-badge--bad', linked: 'alb-ep-badge--ok' };

	function renderRequests() {
		el.view.innerHTML = skeletons(2);
		api('/service-requests').then(function (body) {
			var items = body.items || [];
			var html = '<div class="alb-ep-card alb-ep-form-card">' +
				'<form data-request-form>' +
					'<label class="alb-ep-field"><span>' + esc(I18N.new_request) + '</span>' +
						'<input class="alb-ep-input" name="name" required placeholder="' + esc(I18N.new_request) + '"></label>' +
					'<label class="alb-ep-field"><textarea name="description" rows="2" placeholder="…"></textarea></label>' +
					'<div class="alb-ep-bar">' +
						'<input class="alb-ep-input" name="price" type="number" min="0" step="0.01" placeholder="$">' +
						'<input class="alb-ep-input" name="duration" type="number" min="0" step="300" placeholder="seg">' +
						'<button type="submit" class="alb-ep-btn alb-ep-btn--primary">' + esc(I18N.save) + '</button>' +
					'</div>' +
				'</form></div>';

			if (!items.length) {
				el.view.innerHTML = html + empty();
				return;
			}

			html += '<ul class="alb-ep-list">' + items.map(function (request) {
				var statusKey = 'status_' + request.status;
				return '<li class="alb-ep-list__item">' +
					'<div class="alb-ep-list__main">' +
						'<div class="alb-ep-list__name">' + esc(request.proposed_name) + '</div>' +
						'<div class="alb-ep-list__sub">' + money(request.proposed_price) +
							(request.admin_note ? ' · ' + esc(request.admin_note) : '') + '</div>' +
					'</div>' +
					'<span class="alb-ep-badge ' + (REQUEST_BADGES[request.status] || '') + '">' +
						esc(I18N[statusKey] || request.status) + '</span>' +
				'</li>';
			}).join('') + '</ul>';

			el.view.innerHTML = html;
		}).catch(function (error) {
			el.view.innerHTML = empty();
			fail(error);
		});
	}

	// ---------------- vista: clientes ----------------

	function renderCustomers(search) {
		el.view.innerHTML = skeletons(3);
		api('/customers' + (search ? '?search=' + encodeURIComponent(search) : '')).then(function (body) {
			var items = body.items || [];

			var html = '<div class="alb-ep-bar">' +
				'<input class="alb-ep-input" data-customer-search placeholder="' + esc(I18N.search) + '" value="' + esc(search || '') + '">' +
				'<button class="alb-ep-btn" data-customer-new>' + esc(I18N.new_customer) + '</button>' +
			'</div>' +
			'<div class="alb-ep-card alb-ep-form-card" hidden data-customer-form-wrap>' +
				'<form data-customer-form>' +
					'<div class="alb-ep-bar">' +
						'<input class="alb-ep-input" name="first_name" required placeholder="Nombre">' +
						'<input class="alb-ep-input" name="last_name" placeholder="Apellido">' +
					'</div>' +
					'<div class="alb-ep-bar">' +
						'<input class="alb-ep-input" name="phone" required placeholder="Teléfono">' +
						'<input class="alb-ep-input" name="email" type="email" placeholder="Email">' +
					'</div>' +
					'<label class="alb-ep-field"><textarea name="note" rows="2" placeholder="Notas"></textarea></label>' +
					'<button type="submit" class="alb-ep-btn alb-ep-btn--primary">' + esc(I18N.save) + '</button>' +
				'</form></div>';

			if (!items.length) {
				el.view.innerHTML = html + empty();
			} else {
				el.view.innerHTML = html + '<ul class="alb-ep-list">' + items.map(function (customer) {
					return '<li class="alb-ep-list__item">' +
						'<div class="alb-ep-list__main">' +
							'<div class="alb-ep-list__name">' + esc(customer.first_name + ' ' + (customer.last_name || '')) + '</div>' +
							'<div class="alb-ep-list__sub">' + esc(customer.phone || customer.email || '') + '</div>' +
						'</div>' +
						'<span class="alb-ep-badge">' + customer.bookings_count + '×</span>' +
					'</li>';
				}).join('') + '</ul>';
			}

			var searchInput = el.view.querySelector('[data-customer-search]');
			var searchTimer = null;
			searchInput.addEventListener('input', function () {
				clearTimeout(searchTimer);
				searchTimer = setTimeout(function () {
					renderCustomers(searchInput.value.trim());
				}, 350);
			});
			el.view.querySelector('[data-customer-new]').addEventListener('click', function () {
				var wrap = el.view.querySelector('[data-customer-form-wrap]');
				wrap.hidden = !wrap.hidden;
			});
		}).catch(function (error) {
			el.view.innerHTML = empty();
			fail(error);
		});
	}

	// ---------------- vista: agenda ----------------

	function renderAgenda(range) {
		range = range || 'upcoming';
		el.view.innerHTML = skeletons(4);

		var today = new Date().toISOString().slice(0, 10);
		var past = new Date(Date.now() - 90 * 86400000).toISOString().slice(0, 10);
		var future = new Date(Date.now() + 60 * 86400000).toISOString().slice(0, 10);
		var query = range === 'upcoming'
			? '?from=' + today + '&to=' + future
			: '?from=' + past + '&to=' + today + (range === 'canceled' ? '&status=canceled' : '');

		api('/appointments' + query).then(function (body) {
			var items = body.items || [];

			var html = '<div class="alb-ep-bar">' +
				[['upcoming', I18N.upcoming], ['past', I18N.past], ['canceled', I18N.canceled]].map(function (pair) {
					return '<button class="alb-ep-btn' + (pair[0] === range ? ' alb-ep-btn--primary' : '') +
						'" data-range="' + pair[0] + '">' + esc(pair[1]) + '</button>';
				}).join('') + '</div>';

			if (!items.length) {
				el.view.innerHTML = html + empty();
			} else {
				el.view.innerHTML = html + '<ul class="alb-ep-list">' + items.map(function (appointment) {
					var when = String(appointment.starts_at || '').replace(':00', '');
					return '<li class="alb-ep-list__item">' +
						'<div class="alb-ep-list__main">' +
							'<div class="alb-ep-list__name">' + esc(appointment.customer_name || '#' + appointment.service_ref) + '</div>' +
							'<div class="alb-ep-list__sub">' + esc(when) + '</div>' +
						'</div>' +
						'<div style="text-align:right">' +
							'<span class="alb-ep-badge">' + esc(appointment.status) + '</span> ' +
							'<span class="alb-ep-card__price">' + money(appointment.price) + '</span>' +
						'</div>' +
					'</li>';
				}).join('') + '</ul>';
			}

			el.view.querySelectorAll('[data-range]').forEach(function (button) {
				button.addEventListener('click', function () {
					renderAgenda(button.getAttribute('data-range'));
				});
			});
		}).catch(function (error) {
			el.view.innerHTML = empty();
			fail(error);
		});
	}

	// ---------------- vista: estadísticas ----------------

	function renderStats(month) {
		el.view.innerHTML = skeletons(4);
		api('/stats/summary' + (month ? '?month=' + month : '')).then(function (stats) {
			var html = '<div class="alb-ep-bar">' +
				'<input class="alb-ep-input" type="month" data-stats-month value="' + esc(stats.month) + '">' +
			'</div>' +
			'<div class="alb-ep__grid alb-ep__grid--4">' +
				statCard(stats.appointments_total, I18N.appointments) +
				statCard(money(stats.estimated_revenue) + (stats.revenue_complete ? '' : '*'), I18N.revenue) +
				statCard(stats.unique_customers, I18N.unique_clients) +
				statCard(stats.recurring_customers, I18N.recurring) +
			'</div>';

			if (stats.top_services && stats.top_services.length) {
				html += '<div class="alb-ep-card" style="margin-top:14px">' +
					'<h3 class="alb-ep-card__name">' + esc(I18N.top_services) + '</h3>' +
					'<ul class="alb-ep-list" style="margin-top:10px">' +
					stats.top_services.map(function (service) {
						return '<li class="alb-ep-list__item">' +
							'<span class="alb-ep-list__name">' + esc(service.name) + '</span>' +
							'<span class="alb-ep-badge">' + service.bookings + '×</span></li>';
					}).join('') + '</ul></div>';
			}

			el.view.innerHTML = html;
			el.view.querySelector('[data-stats-month]').addEventListener('change', function (event) {
				renderStats(event.target.value);
			});
		}).catch(function (error) {
			el.view.innerHTML = empty();
			fail(error);
		});
	}

	function statCard(value, label) {
		return '<div class="alb-ep-card alb-ep-stat">' +
			'<p class="alb-ep-stat__value">' + value + '</p>' +
			'<p class="alb-ep-stat__label">' + esc(label) + '</p></div>';
	}

	// ---------------- arranque ----------------

	el.view.innerHTML = skeletons(3);
	api('/me').then(function (me) {
		state.me = me;
		el.hello.textContent = new Date().getHours() < 12 ? 'Buenos días' : 'Hola';
		el.title.textContent = me.display_name;
		renderTabs();
		route();
	}).catch(function (error) {
		el.view.innerHTML = '<div class="alb-ep-empty">' +
			esc(error.code === 'alb_ep_not_mapped' ? I18N.not_mapped : error.message) + '</div>';
	});
})();
