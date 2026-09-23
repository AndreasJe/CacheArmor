/* CacheArmor settings screen: route search and "show enabled only" filter, with a live count. */
(function () {
	var bar = document.getElementById('cachearmor-toolbar');
	var table = document.getElementById('cachearmor-routes');
	if (!bar || !table) { return; }
	var search = document.getElementById('cachearmor-filter');
	var onlyEnabled = document.getElementById('cachearmor-enabled-only');
	var count = document.getElementById('cachearmor-count');
	var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
	function apply() {
		var q = search.value.trim().toLowerCase();
		var shown = 0, total = 0, group = null, groupShown = false;
		rows.forEach(function (row) {
			if (row.classList.contains('cachearmor-ns')) {
				if (group) { group.hidden = !groupShown; }
				group = row; groupShown = false;
				return;
			}
			total++;
			var box = row.querySelector('input[type=checkbox]');
			var match = !q || row.getAttribute('data-route').toLowerCase().indexOf(q) !== -1;
			var visible = match && (!onlyEnabled.checked || (box && box.checked));
			row.hidden = !visible;
			if (visible) { shown++; groupShown = true; }
		});
		if (group) { group.hidden = !groupShown; }
		count.textContent = count.getAttribute('data-template').replace('%1$d', shown).replace('%2$d', total);
	}
	search.addEventListener('input', apply);
	onlyEnabled.addEventListener('change', apply);
	bar.hidden = false;
	apply();
})();
