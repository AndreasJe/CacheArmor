/* CacheArmor settings screen: tabs, route filters, toggles, lifetime labels and unsaved-change tracking. */
(function () {
	var wrap = document.querySelector('.wrap.cachearmor');
	var form = document.getElementById('cachearmor-form');
	if (!wrap || !form) { return; }
	var L = window.cachearmorL10n || {};
	wrap.classList.add('is-js');

	function fmt(str, n) { return String(str).replace('%s', n); }
	function plural(pair, n) { return Array.isArray(pair) ? fmt(pair[n === 1 ? 0 : 1], n) : fmt(pair, n); }

	/* Tabs */
	var tabInput = document.getElementById('cachearmor-tab');
	var tabLinks = Array.prototype.slice.call(wrap.querySelectorAll('[data-tab]'));
	var panels = Array.prototype.slice.call(wrap.querySelectorAll('.cachearmor-panel'));
	function showTab(name, focus) {
		if (!wrap.querySelector('[data-panel="' + name + '"]')) { name = 'routes'; }
		panels.forEach(function (p) { p.classList.toggle('is-active', p.getAttribute('data-panel') === name); });
		tabLinks.forEach(function (a) {
			if (!a.classList.contains('nav-tab')) { return; }
			var on = a.getAttribute('data-tab') === name;
			a.classList.toggle('nav-tab-active', on);
			if (on) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); }
			if (on && focus) { a.focus(); }
		});
		if (tabInput) { tabInput.value = name; }
	}
	tabLinks.forEach(function (a) {
		a.addEventListener('click', function (e) {
			e.preventDefault();
			var name = a.getAttribute('data-tab');
			showTab(name, false);
			if (history.replaceState) { history.replaceState(null, '', '#' + name); }
		});
	});
	showTab((location.hash || '#routes').slice(1), false);

	/* Lifetime labels */
	function duration(s) {
		if (s >= 86400 && s % 86400 === 0) { return plural(L.day || ['%s day', '%s days'], s / 86400); }
		if (s >= 3600 && s % 3600 === 0) { return plural(L.hour || ['%s hour', '%s hours'], s / 3600); }
		if (s >= 60 && s % 60 === 0) { return fmt(L.min || '%s min', s / 60); }
		return fmt(L.sec || '%s s', s);
	}
	function updateTtl(input) {
		var label = input.parentNode.querySelector('.cachearmor-ttl-label');
		if (!label) { return; }
		var v = parseInt(input.value, 10);
		label.textContent = v > 0 ? duration(v) : (L['default'] || 'default');
	}

	/* Routes */
	var table = document.getElementById('cachearmor-routes');
	var bar = document.getElementById('cachearmor-toolbar');
	var groups = table ? Array.prototype.slice.call(table.querySelectorAll('tbody.cachearmor-group')) : [];
	var search = document.getElementById('cachearmor-filter');
	var access = document.getElementById('cachearmor-access');
	var onlyEnabled = document.getElementById('cachearmor-enabled-only');
	var count = document.getElementById('cachearmor-count');

	function applyFilters() {
		if (!table) { return; }
		var q = search ? search.value.trim().toLowerCase() : '';
		var type = access ? access.value : '';
		var shown = 0, total = 0;
		groups.forEach(function (group) {
			var groupShown = 0, on = 0, rows = group.querySelectorAll('.cachearmor-row');
			Array.prototype.forEach.call(rows, function (row) {
				total++;
				var box = row.querySelector('input[type=checkbox]');
				var enabled = !!(box && box.checked);
				if (enabled) { on++; }
				var visible = (!q || row.getAttribute('data-route').toLowerCase().indexOf(q) !== -1) &&
					(!type || row.getAttribute('data-access') === type) &&
					(!onlyEnabled || !onlyEnabled.checked || enabled);
				row.hidden = !visible;
				if (visible) { groupShown++; shown++; }
			});
			group.hidden = groupShown === 0;
			var c = group.querySelector('.cachearmor-ns-count');
			if (c && L.enabled) { c.textContent = L.enabled.replace('%1$d', on).replace('%2$d', rows.length); }
		});
		if (count) { count.textContent = count.getAttribute('data-template').replace('%1$d', shown).replace('%2$d', total); }
	}

	if (table) {
		table.addEventListener('change', function (e) {
			var t = e.target;
			if (t.type === 'checkbox') {
				var row = t.closest('.cachearmor-row');
				if (row) { row.classList.toggle('is-enabled', t.checked); }
				applyFilters();
			}
		});
		groups.forEach(function (group) {
			var btn = group.querySelector('.cachearmor-ns-toggle');
			if (!btn) { return; }
			btn.addEventListener('click', function () {
				var open = btn.getAttribute('aria-expanded') !== 'false';
				btn.setAttribute('aria-expanded', open ? 'false' : 'true');
				group.classList.toggle('is-collapsed', open);
			});
		});
		[search, onlyEnabled, access].forEach(function (el) {
			if (el) { el.addEventListener(el === search ? 'input' : 'change', applyFilters); }
		});
		if (search) {
			// Enter in the filter box must not submit the settings form.
			search.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
		}
		if (bar) { bar.hidden = false; }
	}

	/* Unsaved changes */
	var fields = Array.prototype.slice.call(form.querySelectorAll('input:not([type=hidden]), select, textarea'))
		.filter(function (el) { return el.name; });
	var unsaved = document.getElementById('cachearmor-unsaved');
	var unsavedCount = document.getElementById('cachearmor-unsaved-count');
	var discard = document.getElementById('cachearmor-discard');
	var dirty = 0, submitting = false;

	function changed(el) {
		if (el.type === 'checkbox' || el.type === 'radio') { return el.checked !== el.defaultChecked; }
		if (el.tagName === 'SELECT') {
			return Array.prototype.some.call(el.options, function (o) { return o.selected !== o.defaultSelected; });
		}
		return el.value !== el.defaultValue;
	}
	function track() {
		dirty = fields.filter(changed).length;
		if (unsaved) { unsaved.hidden = dirty === 0; }
		if (unsavedCount) { unsavedCount.textContent = plural(L.unsaved || ['%s unsaved change', '%s unsaved changes'], dirty); }
		if (discard) { discard.hidden = dirty === 0; }
	}
	function refreshAll() {
		Array.prototype.forEach.call(form.querySelectorAll('.cachearmor-ttl'), updateTtl);
		Array.prototype.forEach.call(form.querySelectorAll('.cachearmor-row'), function (row) {
			var box = row.querySelector('input[type=checkbox]');
			if (box) { row.classList.toggle('is-enabled', box.checked); }
		});
		applyFilters();
		track();
	}

	form.addEventListener('input', function (e) {
		if (e.target.classList.contains('cachearmor-ttl')) { updateTtl(e.target); }
		track();
	});
	form.addEventListener('change', track);
	if (discard) {
		discard.addEventListener('click', function () {
			form.reset();
			// reset() fires no input events; let it finish, then redraw.
			setTimeout(refreshAll, 0);
		});
	}
	form.addEventListener('submit', function () { submitting = true; });
	window.addEventListener('beforeunload', function (e) {
		if (dirty && !submitting) {
			e.preventDefault();
			e.returnValue = L.leave || '';
			return L.leave || '';
		}
	});

	refreshAll();
})();
