(function () {
	function updateDayState(day) {
		var toggle = day.querySelector('.wdc-calendar-day-toggle');
		if (!toggle) {
			return;
		}

		day.classList.toggle('is-working', toggle.checked);
		day.classList.toggle('is-non-working', !toggle.checked);
	}

	document.addEventListener('change', function (event) {
		if (!event.target.classList.contains('wdc-calendar-day-toggle')) {
			return;
		}

		var day = event.target.closest('.wdc-calendar-day');
		if (day) {
			updateDayState(day);
		}
	});
})();
