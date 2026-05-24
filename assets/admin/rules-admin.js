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

		if (!action || !fields) {
			return;
		}

		var disabled = action.value === 'disable_rate';
		fields.classList.toggle('is-operation-disabled', disabled);
		fields.querySelectorAll('[data-operation-control]').forEach(function (field) {
			field.disabled = disabled;
		});
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

	document.addEventListener('DOMContentLoaded', function () {
		syncOperationFields(document);
	});
}());
