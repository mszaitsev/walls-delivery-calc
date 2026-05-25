(function () {
	'use strict';

	function updateConditionNames(row, index) {
		row.querySelectorAll('[name]').forEach(function (field) {
			field.name = field.name.replace(/conditions\[[^\]]+\]/, 'conditions[' + index + ']');
		});
	}

	function refreshConditionIndexes(root) {
		root.querySelectorAll('[data-condition-row]').forEach(function (row, index) {
			updateConditionNames(row, index);
		});
	}

	function config() {
		return window.wdcRulesAdmin || { conditions: {}, operatorLabels: {}, strings: {}, locationSearch: {} };
	}

	function parseJson(value, fallback) {
		try {
			return value ? JSON.parse(value) : fallback;
		} catch (error) {
			return fallback;
		}
	}

	function conditionDefinition(type) {
		return config().conditions[type] || null;
	}

	function setHidden(row, key, value) {
		var field = row.querySelector('[data-' + key.replace('_', '-') + ']');
		if (field) {
			field.value = value == null ? '' : String(value);
		}
	}

	function valueState(row) {
		var payload = parseJson(row.dataset.conditionValue || '{}', {});
		var valueJsonField = row.querySelector('[data-value-json]');
		return {
			value_text: row.querySelector('[data-value-text]') ? row.querySelector('[data-value-text]').value : (payload.value_text || ''),
			value_number: row.querySelector('[data-value-number]') ? row.querySelector('[data-value-number]').value : (payload.value_number || ''),
			value_json: valueJsonField ? parseJson(valueJsonField.value, {}) : (payload.value_json || {})
		};
	}

	function syncValueState(row, state) {
		setHidden(row, 'value_text', state.value_text || '');
		setHidden(row, 'value_number', state.value_number == null ? '' : state.value_number);
		setHidden(row, 'value_json', JSON.stringify(state.value_json || {}));
		row.dataset.conditionValue = JSON.stringify(state);
	}

	function option(label, value, selected) {
		var element = document.createElement('option');
		element.value = value;
		element.textContent = label;
		element.selected = selected;
		return element;
	}

	function updateOperators(row) {
		var type = row.querySelector('[name*="[condition_type]"]').value;
		var definition = conditionDefinition(type);
		var select = row.querySelector('[data-condition-operator]');
		var selected = select.dataset.selectedOperator || select.value;
		var labels = config().operatorLabels || {};

		select.innerHTML = '';
		if (!definition) {
			select.appendChild(option('Не выбрано', '', true));
			return;
		}

		(definition.operators || []).forEach(function (operator, index) {
			select.appendChild(option(labels[operator] || operator, operator, selected ? selected === operator : index === 0));
		});
		select.dataset.selectedOperator = select.value;
	}

	function renderSelectValue(row, definition, state, numeric) {
		var wrapper = document.createElement('div');
		var select = document.createElement('select');
		wrapper.className = 'wdc-value-with-unit';
		select.className = 'wdc-condition-specific-control';
		select.appendChild(option(config().strings.selectValue || 'Выберите значение', '', false));
		Object.keys(definition.options || {}).forEach(function (value) {
			var selectedValue = numeric ? String(state.value_number || '') : String(state.value_text || '');
			select.appendChild(option(definition.options[value], value, selectedValue === String(value)));
		});
		select.addEventListener('change', function () {
			if (numeric) {
				state.value_number = select.value;
			} else {
				state.value_text = select.value;
			}
			syncValueState(row, state);
		});
		wrapper.appendChild(select);
		appendUnit(wrapper, definition);
		return wrapper;
	}

	function renderLocationValue(row, state) {
		var wrapper = document.createElement('div');
		var input = document.createElement('input');
		var results = document.createElement('div');
		var displayName = state.value_json && state.value_json.display_name ? state.value_json.display_name : '';
		var fiasId = state.value_json && state.value_json.fias_id ? state.value_json.fias_id : state.value_text;

		input.type = 'text';
		input.className = 'wdc-location-search';
		input.placeholder = config().strings.searchLocation || 'Введите FIAS ID населенного пункта';
		input.value = state.value_text || fiasId || '';
		results.className = 'wdc-location-results';
		wrapper.className = 'wdc-location-field';
		wrapper.appendChild(input);
		wrapper.appendChild(results);
		if (displayName && fiasId) {
			var current = document.createElement('span');
			current.textContent = displayName + ' (' + fiasId + ')';
			results.appendChild(current);
		}

		input.addEventListener('input', function () {
			var query = input.value.trim();
			results.innerHTML = '';
			if (query.length < 3) {
				state.value_text = query;
				state.value_json = query ? { fias_id: query } : {};
				syncValueState(row, state);
				return;
			}

			var search = config().locationSearch || {};
			var url = search.ajaxUrl + '?action=' + encodeURIComponent(search.action) + '&nonce=' + encodeURIComponent(search.nonce) + '&query=' + encodeURIComponent(query);
			window.fetch(url, { credentials: 'same-origin' })
				.then(function (response) { return response.json(); })
				.then(function (payload) {
					var items = payload && payload.success && payload.data && Array.isArray(payload.data.items) ? payload.data.items : [];
					if (!items.length) {
						var empty = document.createElement('span');
						empty.textContent = config().strings.noResults || 'Ничего не найдено';
						results.appendChild(empty);
						state.value_text = query;
						state.value_json = { fias_id: query };
						syncValueState(row, state);
						return;
					}
					var item = items[0];
					var found = document.createElement('span');
					found.textContent = item.display_name + ' (' + item.fias_id + ')';
					results.appendChild(found);
					state.value_text = item.fias_id;
					state.value_json = { fias_id: item.fias_id, display_name: item.display_name };
					syncValueState(row, state);
				});
		});

		return wrapper;
	}

	function renderDimensionsValue(row, state) {
		var wrapper = document.createElement('div');
		var json = state.value_json || {};
		wrapper.className = 'wdc-dimensions-fields';
		[
			['length_cm', 'Длина'],
			['width_cm', 'Ширина'],
			['height_cm', 'Высота']
		].forEach(function (item) {
			var label = document.createElement('label');
			var span = document.createElement('span');
			var input = document.createElement('input');
			span.textContent = item[1] + ', см';
			input.type = 'text';
			input.inputMode = 'decimal';
			input.value = json[item[0]] || '';
			input.addEventListener('input', function () {
				if (input.value === '') {
					delete json[item[0]];
				} else {
					json[item[0]] = input.value;
				}
				state.value_json = json;
				syncValueState(row, state);
			});
			label.appendChild(span);
			label.appendChild(input);
			wrapper.appendChild(label);
		});
		return wrapper;
	}

	function renderValueControl(row) {
		var type = row.querySelector('[name*="[condition_type]"]').value;
		var definition = conditionDefinition(type);
		var target = row.querySelector('[data-condition-value-control]');
		var state = valueState(row);

		target.innerHTML = '';
		if (!definition) {
			syncValueState(row, { value_text: '', value_number: '', value_json: {} });
			return;
		}

		if (definition.storage === 'value_number' && definition.input.indexOf('select') !== 0) {
			var number = document.createElement('input');
			number.type = 'text';
			number.inputMode = definition.input === 'integer' ? 'numeric' : 'decimal';
			number.value = state.value_number || '';
			number.addEventListener('input', function () {
				state.value_number = number.value;
				syncValueState(row, state);
			});
			target.appendChild(wrapWithUnit(number, definition));
		} else if (definition.input === 'select') {
			target.appendChild(renderSelectValue(row, definition, state, false));
		} else if (definition.input === 'select_number') {
			target.appendChild(renderSelectValue(row, definition, state, true));
		} else if (definition.input === 'fias_id') {
			target.appendChild(renderLocationValue(row, state));
		} else if (definition.input === 'dimensions') {
			target.appendChild(renderDimensionsValue(row, state));
		} else if (definition.input === 'date') {
			var date = document.createElement('input');
			date.type = 'text';
			date.placeholder = 'дд.мм.гггг';
			date.value = /^\d{4}-\d{2}-\d{2}$/.test(state.value_text || '') ? state.value_text.replace(/^(\d{4})-(\d{2})-(\d{2})$/, '$3.$2.$1') : (state.value_text || '');
			date.addEventListener('input', function () {
				state.value_text = date.value;
				syncValueState(row, state);
			});
			target.appendChild(date);
		}
	}

	function appendUnit(wrapper, definition) {
		if (!definition.unit) {
			return;
		}

		var unit = document.createElement('span');
		unit.className = 'wdc-condition-unit';
		unit.textContent = definition.unit;
		wrapper.appendChild(unit);
	}

	function wrapWithUnit(control, definition) {
		var wrapper = document.createElement('div');
		wrapper.className = 'wdc-value-with-unit';
		wrapper.appendChild(control);
		appendUnit(wrapper, definition);
		return wrapper;
	}

	function initConditionRow(row) {
		updateOperators(row);
		renderValueControl(row);
	}

	function createConditionRow(groupBlock) {
		var source = groupBlock.querySelector('[data-condition-template]');
		var list = groupBlock.querySelector('[data-condition-list]');
		var group = groupBlock.dataset.conditionGroupBlock || '1';

		if (!source || !list) {
			return;
		}

		var clone = source.cloneNode(true);
		clone.classList.remove('is-template');
		clone.removeAttribute('data-condition-template');
		clone.setAttribute('data-condition-row', '');
		clone.querySelectorAll('input, select').forEach(function (field) {
			field.disabled = false;
			if (field.name.indexOf('[condition_group]') !== -1) {
				field.value = group;
				return;
			}

			field.value = '';
		});
		clone.dataset.conditionValue = '{}';

		list.appendChild(clone);
		refreshConditionIndexes(document);
		initConditionRow(clone);
	}

	function syncOperationFields(root) {
		var action = root.querySelector('[data-action-type]');
		var fields = root.querySelector('[data-operation-fields]');
		var base = root.querySelector('[data-operation-base]');
		var operationType = root.querySelector('[name="operation_type"]');
		var comment = root.querySelector('[data-operation-comment]');

		if (!action || !fields) {
			return;
		}

		var disabled = action.value === 'disable_rate';
		var daysAction = action.value === 'change_delivery_days';
		var commentAction = action.value === 'add_comment';
		var factorAction = operationType && (operationType.value === 'multiply' || operationType.value === 'divide');
		fields.classList.toggle('is-operation-disabled', disabled);
		fields.classList.toggle('is-comment-operation', commentAction);
		fields.querySelectorAll('[data-operation-control]').forEach(function (field) {
			field.disabled = disabled || commentAction;
		});

		if (operationType) {
			operationType.querySelectorAll('option').forEach(function (option) {
				var show = !commentAction || option.value === 'equals';
				option.hidden = !show;
				option.disabled = !show;
			});
			if (commentAction) {
				operationType.value = 'equals';
			}
		}

		if (comment) {
			comment.hidden = !commentAction;
			comment.querySelectorAll('textarea').forEach(function (field) {
				field.disabled = !commentAction;
			});
		}

		if (!base) {
			return;
		}

		if (commentAction) {
			base.value = 'rubles';
			return;
		}

		var selectedVisible = false;
		base.querySelectorAll('option').forEach(function (option) {
			var show = !factorAction && (daysAction ? option.dataset.baseKind === 'days' : option.dataset.baseKind !== 'days');
			option.hidden = !show;
			option.disabled = !show;
			if (option.selected && show) {
				selectedVisible = true;
			}
		});

		if (!selectedVisible) {
			base.value = daysAction ? 'calendar_days' : 'rubles';
		}
		var baseField = root.querySelector('[data-operation-base-field]');
		if (baseField) {
			baseField.hidden = factorAction;
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
			var groupBlock = addButton.closest('[data-condition-group-block]');
			if (groupBlock) {
				createConditionRow(groupBlock);
			}
			return;
		}

		var removeButton = event.target.closest('[data-remove-condition]');
		if (removeButton) {
			var row = removeButton.closest('[data-condition-row]');
			if (row) {
				row.remove();
				refreshConditionIndexes(document);
			}
			return;
		}

		var deleteButton = event.target.closest('.wdc-rules-delete');
		if (deleteButton && !window.confirm('Удалить правило?')) {
			event.preventDefault();
		}
	});

	document.addEventListener('change', function (event) {
		if (event.target.matches('[data-action-type], [name="operation_type"]')) {
			syncOperationFields(document);
		}

		if (event.target.matches('[name*="[condition_type]"]')) {
			var row = event.target.closest('[data-condition-row]');
			if (row) {
				syncValueState(row, { value_text: '', value_number: '', value_json: {} });
				initConditionRow(row);
			}
		}

		if (event.target.matches('[data-condition-operator]')) {
			event.target.dataset.selectedOperator = event.target.value;
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
		document.querySelectorAll('[data-condition-row]').forEach(initConditionRow);
		refreshConditionIndexes(document);
	});
}());
