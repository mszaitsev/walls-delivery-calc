(function () {
	'use strict';

	function updateConditionNames(row, index) {
		row.querySelectorAll('[name]').forEach(function (field) {
			field.name = field.name.replace(/conditions\[[^\]]+\]/, 'conditions[' + index + ']');
		});
	}

	function refreshConditionIndexes(container) {
		container.querySelectorAll('[data-condition-row]').forEach(function (row, index) {
			updateConditionNames(row, index);
		});
	}

	function createConditionRow(container) {
		var rows = container.querySelectorAll('[data-condition-row]');
		var source = rows.length ? rows[rows.length - 1] : null;

		if (!source) {
			return;
		}

		var clone = source.cloneNode(true);
		clone.querySelectorAll('input, select').forEach(function (field) {
			if (field.name.indexOf('[condition_group]') !== -1) {
				field.value = '1';
				return;
			}

			field.value = '';
		});

		container.appendChild(clone);
		refreshConditionIndexes(container);
	}

	function syncOperationFields(root) {
		var action = root.querySelector('[data-action-type]');
		var fields = root.querySelector('[data-operation-fields]');
		var base = root.querySelector('[data-operation-base]');

		if (!action || !fields) {
			return;
		}

		var disabled = action.value === 'disable_rate';
		var daysAction = action.value === 'change_delivery_days';
		fields.classList.toggle('is-operation-disabled', disabled);
		fields.querySelectorAll('[data-operation-control]').forEach(function (field) {
			field.disabled = disabled;
		});

		if (!base) {
			return;
		}

		var selectedVisible = false;
		base.querySelectorAll('option').forEach(function (option) {
			var show = daysAction ? option.dataset.baseKind === 'days' : option.dataset.baseKind !== 'days';
			option.hidden = !show;
			option.disabled = !show;
			if (option.selected && show) {
				selectedVisible = true;
			}
		});

		if (!selectedVisible) {
			base.value = daysAction ? 'calendar_days' : 'rubles';
		}
	}

	function submitRuleOrder() {
		var form = document.querySelector('[data-reorder-form]');
		var input = form ? form.querySelector('[data-ordered-rule-ids]') : null;
		var rows = Array.prototype.slice.call(document.querySelectorAll('[data-rule-row]'));

		if (!form || !input || rows.length < 2) {
			return;
		}

		input.value = rows.map(function (row) {
			return row.dataset.ruleId;
		}).filter(Boolean).join(',');
		form.submit();
	}

	function rowAfterPointer(tbody, y) {
		var rows = Array.prototype.slice.call(tbody.querySelectorAll('[data-rule-row]:not(.is-dragging)'));

		return rows.reduce(function (closest, row) {
			var box = row.getBoundingClientRect();
			var offset = y - box.top - box.height / 2;

			if (offset < 0 && offset > closest.offset) {
				return { offset: offset, row: row };
			}

			return closest;
		}, { offset: Number.NEGATIVE_INFINITY, row: null }).row;
	}

	document.addEventListener('click', function (event) {
		var addButton = event.target.closest('[data-add-condition]');
		if (addButton) {
			var container = document.querySelector('[data-conditions]');
			if (container) {
				createConditionRow(container);
			}
			return;
		}

		var removeButton = event.target.closest('[data-remove-condition]');
		if (removeButton) {
			var row = removeButton.closest('[data-condition-row]');
			var container = row ? row.parentElement : null;
			if (row && container && container.querySelectorAll('[data-condition-row]').length > 1) {
				row.remove();
				refreshConditionIndexes(container);
			} else if (row) {
				row.querySelectorAll('input, select').forEach(function (field) {
					field.value = field.name.indexOf('[condition_group]') !== -1 ? '1' : '';
				});
			}
			return;
		}

		var deleteButton = event.target.closest('.wdc-rules-delete');
		if (deleteButton && !window.confirm('Удалить правило?')) {
			event.preventDefault();
		}
	});

	document.addEventListener('change', function (event) {
		if (event.target.matches('[data-action-type]')) {
			syncOperationFields(document);
		}
	});

	document.addEventListener('dragstart', function (event) {
		var row = event.target.closest('[data-rule-row]');
		if (!row) {
			return;
		}

		row.classList.add('is-dragging');
		event.dataTransfer.effectAllowed = 'move';
		event.dataTransfer.setData('text/plain', row.dataset.ruleId || '');
	});

	document.addEventListener('dragover', function (event) {
		var tbody = event.target.closest('.wdc-rules-table tbody');
		var dragging = document.querySelector('[data-rule-row].is-dragging');

		if (!tbody || !dragging) {
			return;
		}

		event.preventDefault();
		var after = rowAfterPointer(tbody, event.clientY);
		if (after) {
			tbody.insertBefore(dragging, after);
		} else {
			tbody.appendChild(dragging);
		}
	});

	document.addEventListener('dragend', function (event) {
		var row = event.target.closest('[data-rule-row]');
		if (!row) {
			return;
		}

		row.classList.remove('is-dragging');
		submitRuleOrder();
	});

	document.addEventListener('DOMContentLoaded', function () {
		syncOperationFields(document);
	});
}());
