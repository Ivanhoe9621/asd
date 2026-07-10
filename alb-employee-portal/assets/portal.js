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

	var VIEWS = [];

	function buildViews(me) {
		VIEWS = [];

		// Las vistas de empleado solo tienen sentido con un empleado vinculado.
		if (me.employee_ref) {
			VIEWS.push(
				{ key: 'services', label: I18N.services, render: renderServices },
				{ key: 'requests', label: I18N.requests, render: renderRequests },
				{ key: 'customers', label: I18N.customers, render: renderCustomers },
				{ key: 'agenda', label: I18N.agenda, render: renderAgenda },
				{ key: 'stats', label: I18N.stats, render: renderStats }
			);
		}

		if (me.is_admin) {
			VIEWS.push(
				{ key: 'admin_requests', label: I18N.admin_requests, render: renderAdminRequests },
				{ key: 'admin_team', label: I18N.admin_team, render: renderAdminTeam },
				{ key: 'admin_audit', label: I18N.admin_audit, render: renderAdminAudit }
			);
		}

		if (VIEWS.length && !VIEWS.some(function (view) { return view.key === state.view; })) {
			state.view = VIEWS[0].key;
			root.setAttribute('data-view', state.view);
		}
	}

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
							'<span class="alb-ep-badge">' + esc(I18N['apt_' + appointment.status] || appointment.status) + '</span> ' +
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

	// ---------------- vistas admin ----------------

	function renderAdminRequests(status) {
		status = status || 'pending';
		el.view.innerHTML = skeletons(3);
		api('/admin/service-requests?status=' + encodeURIComponent(status)).then(function (body) {
			var items = body.items || [];

			var html = '<div class="alb-ep-bar">' +
				[['pending', I18N.status_pending], ['approved', I18N.status_approved],
				 ['rejected', I18N.status_rejected], ['linked', I18N.status_linked]].map(function (pair) {
					return '<button class="alb-ep-btn' + (pair[0] === status ? ' alb-ep-btn--primary' : '') +
						'" data-req-status="' + pair[0] + '">' + esc(pair[1]) + '</button>';
				}).join('') + '</div>';

			if (!items.length) {
				el.view.innerHTML = html + empty();
			} else {
				el.view.innerHTML = html + '<div class="alb-ep__grid">' + items.map(function (request) {
					var actions = '';
					if (request.status === 'pending') {
						actions = '<div class="alb-ep-bar" style="margin-top:10px;margin-bottom:0">' +
							'<button class="alb-ep-btn alb-ep-btn--primary" data-req-approve="' + request.id + '">' + esc(I18N.approve) + '</button>' +
							'<button class="alb-ep-btn" data-req-reject="' + request.id + '">' + esc(I18N.reject) + '</button>' +
						'</div>';
					} else if (request.status === 'approved') {
						actions = '<p class="alb-ep-card__meta" style="margin-top:10px">' + esc(I18N.link_hint) + '</p>' +
							'<div class="alb-ep-bar" style="margin-bottom:0">' +
							'<input class="alb-ep-input" data-link-input="' + request.id + '" placeholder="ID">' +
							'<button class="alb-ep-btn alb-ep-btn--primary" data-req-link="' + request.id + '">' + esc(I18N.link) + '</button>' +
						'</div>';
					}
					return '<article class="alb-ep-card">' +
						'<div class="alb-ep-card__row">' +
							'<h3 class="alb-ep-card__name">' + esc(request.proposed_name) + '</h3>' +
							'<span class="alb-ep-card__price">' + money(request.proposed_price) + '</span>' +
						'</div>' +
						'<p class="alb-ep-card__meta">' + esc(I18N.employee) + ' #' + esc(request.ext_employee_id) +
							' · ' + esc(String(request.created_at || '').slice(0, 10)) + '</p>' +
						'<p class="alb-ep-card__desc">' + esc(request.proposed_description || '') + '</p>' +
						actions +
					'</article>';
				}).join('') + '</div>';
			}

			function refresh() { renderAdminRequests(status); }

			el.view.querySelectorAll('[data-req-status]').forEach(function (button) {
				button.addEventListener('click', function () {
					renderAdminRequests(button.getAttribute('data-req-status'));
				});
			});
			el.view.querySelectorAll('[data-req-approve]').forEach(function (button) {
				button.addEventListener('click', function () {
					api('/admin/service-requests/' + button.getAttribute('data-req-approve'), {
						method: 'PUT', body: JSON.stringify({ status: 'approved' })
					}).then(function () { toast(I18N.saved); refresh(); }).catch(fail);
				});
			});
			el.view.querySelectorAll('[data-req-reject]').forEach(function (button) {
				button.addEventListener('click', function () {
					api('/admin/service-requests/' + button.getAttribute('data-req-reject'), {
						method: 'PUT', body: JSON.stringify({ status: 'rejected' })
					}).then(function () { toast(I18N.saved); refresh(); }).catch(fail);
				});
			});
			el.view.querySelectorAll('[data-req-link]').forEach(function (button) {
				button.addEventListener('click', function () {
					var id = button.getAttribute('data-req-link');
					var input = el.view.querySelector('[data-link-input="' + id + '"]');
					api('/admin/service-requests/' + id, {
						method: 'PUT',
						body: JSON.stringify({ status: 'linked', ext_service_id: input.value.trim() })
					}).then(function () { toast(I18N.saved); refresh(); }).catch(fail);
				});
			});
		}).catch(function (error) {
			el.view.innerHTML = empty();
			fail(error);
		});
	}

	function renderAdminTeam() {
		el.view.innerHTML = skeletons(3);
		Promise.all([
			api('/admin/employee-map'),
			api('/admin/employees').catch(function () { return { items: [] }; }),
			api('/admin/status').catch(function () { return null; })
		]).then(function (results) {
			var mappings = results[0].items || [];
			var employees = results[1].items || [];
			var status = results[2];

			var byRef = {};
			employees.forEach(function (employee) {
				byRef[employee.id] = (employee.first_name + ' ' + (employee.last_name || '')).trim();
			});

			var options = employees.map(function (employee) {
				return '<option value="' + esc(employee.id) + '">' + esc(byRef[employee.id]) + '</option>';
			}).join('');

			var html = '';
			if (status && status.diagnostics) {
				var diag = status.diagnostics;
				var tablesOk = Object.keys(diag.tables || {}).filter(function (k) { return diag.tables[k]; }).length;
				var tablesTotal = Object.keys(diag.tables || {}).length;
				html += '<div class="alb-ep-card alb-ep-form-card">' +
					'<h3 class="alb-ep-card__name">' + esc(I18N.system_status) + '</h3>' +
					'<p class="alb-ep-card__meta">v' + esc(status.version) + ' · ' + esc(diag.provider) +
						' · tablas ' + tablesOk + '/' + tablesTotal + '</p>' +
					'<p style="margin:8px 0 0"><span class="alb-ep-badge ' + (diag.writes_enabled ? 'alb-ep-badge--ok' : 'alb-ep-badge--warn') + '">' +
						esc(diag.writes_enabled ? I18N.writes_on : I18N.writes_off) + '</span></p>' +
				'</div>';
			}

			html += '<div class="alb-ep-card alb-ep-form-card">' +
				'<h3 class="alb-ep-card__name">' + esc(I18N.map_add) + '</h3>' +
				'<div class="alb-ep-bar" style="margin-top:10px;margin-bottom:0">' +
					'<input class="alb-ep-input" data-map-user type="number" min="1" placeholder="' + esc(I18N.wp_user) + ' (ID)">' +
					'<select class="alb-ep-input" data-map-employee>' + options + '</select>' +
					'<button class="alb-ep-btn alb-ep-btn--primary" data-map-save>' + esc(I18N.save) + '</button>' +
				'</div></div>';

			if (!mappings.length) {
				html += empty();
			} else {
				html += '<ul class="alb-ep-list">' + mappings.map(function (mapping) {
					return '<li class="alb-ep-list__item">' +
						'<div class="alb-ep-list__main">' +
							'<div class="alb-ep-list__name">' + esc(byRef[mapping.ext_employee_id] || ('#' + mapping.ext_employee_id)) + '</div>' +
							'<div class="alb-ep-list__sub">' + esc(I18N.wp_user) + ' #' + esc(mapping.wp_user_id) + '</div>' +
						'</div>' +
						'<span class="alb-ep-badge">' + esc(mapping.provider) + '</span>' +
					'</li>';
				}).join('') + '</ul>';
			}

			el.view.innerHTML = html;
			el.view.querySelector('[data-map-save]').addEventListener('click', function () {
				api('/admin/employee-map', {
					method: 'PUT',
					body: JSON.stringify({
						wp_user_id: el.view.querySelector('[data-map-user]').value,
						ext_employee_id: el.view.querySelector('[data-map-employee]').value
					})
				}).then(function () { toast(I18N.saved); renderAdminTeam(); }).catch(fail);
			});
		}).catch(function (error) {
			el.view.innerHTML = empty();
			fail(error);
		});
	}

	function renderAdminAudit(page) {
		page = page || 1;
		if (page === 1) {
			el.view.innerHTML = skeletons(4);
		}
		api('/admin/audit?page=' + page + '&per_page=20').then(function (body) {
			var items = body.items || [];
			var listHtml = items.map(function (entry) {
				return '<li class="alb-ep-list__item">' +
					'<div class="alb-ep-list__main">' +
						'<div class="alb-ep-list__name">' + esc(entry.action) + ' · ' + esc(entry.entity_type) + ' #' + esc(entry.entity_ref) + '</div>' +
						'<div class="alb-ep-list__sub">' + esc(entry.created_at) + ' · ' + esc(I18N.by) + ' #' + esc(entry.actor_wp_user_id) + '</div>' +
					'</div>' +
					'<span class="alb-ep-badge">' + esc(entry.origin) + '</span>' +
				'</li>';
			}).join('');

			if (page === 1) {
				el.view.innerHTML = (items.length ? '<ul class="alb-ep-list" data-audit-list>' + listHtml + '</ul>' : empty()) +
					'<div style="text-align:center;margin-top:14px">' +
					'<button class="alb-ep-btn" data-audit-more hidden>' + esc(I18N.load_more) + '</button></div>';
			} else {
				el.view.querySelector('[data-audit-list]').insertAdjacentHTML('beforeend', listHtml);
			}

			var moreButton = el.view.querySelector('[data-audit-more]');
			if (moreButton) {
				var shown = el.view.querySelectorAll('[data-audit-list] > li').length;
				moreButton.hidden = shown >= (body.total || 0);
				moreButton.onclick = function () { renderAdminAudit(page + 1); };
			}
		}).catch(function (error) {
			if (page === 1) {
				el.view.innerHTML = empty();
			}
			fail(error);
		});
	}

	// ---------------- arranque ----------------

	el.view.innerHTML = skeletons(3);
	api('/me').then(function (me) {
		state.me = me;
		buildViews(me);
		el.hello.textContent = new Date().getHours() < 12 ? 'Buenos días' : 'Hola';
		el.title.textContent = me.display_name;
		if (!VIEWS.length) {
			el.view.innerHTML = '<div class="alb-ep-empty">' + esc(I18N.not_mapped) + '</div>';
			return;
		}
		renderTabs();
		route();
	}).catch(function (error) {
		el.view.innerHTML = '<div class="alb-ep-empty">' +
			esc(error.code === 'alb_ep_not_mapped' ? I18N.not_mapped : error.message) + '</div>';
	});
})();
