/**
 * Bale Connector Recipients Page Vanilla JS Handler.
 */
document.addEventListener('DOMContentLoaded', function () {
	var form = document.getElementById('bale-recipient-form');
	if (!form) {
		return;
	}

	var formTitle = document.getElementById('bale-form-title');
	var recipientIdInput = document.getElementById('recipient_id');
	var labelInput = document.getElementById('recipient_label');
	var typeSelect = document.getElementById('recipient_type');
	var chatIdInput = document.getElementById('recipient_chat_id');
	var saveBtn = document.getElementById('bale-save-recipient-btn');
	var cancelBtn = document.getElementById('bale-cancel-edit-btn');
	var formFeedback = document.getElementById('bale-form-feedback');
	var listFeedback = document.getElementById('bale-list-feedback');
	var tbody = document.getElementById('bale-recipients-tbody');

	var config = window.baleConnectorRecipients || {};
	var ajaxUrl = config.ajaxUrl || window.ajaxurl;
	var nonce = config.nonce || '';
	var i18n = config.i18n || {};

	function showFeedback(el, message, type) {
		el.className = 'bale-feedback bale-feedback-' + (type === 'success' ? 'success' : 'error');
		el.textContent = message;
		el.style.display = 'block';
		setTimeout(function () {
			// Auto scroll into view slightly if out of view
		}, 100);
	}

	function hideFeedback(el) {
		el.style.display = 'none';
		el.textContent = '';
	}

	function resetForm() {
		recipientIdInput.value = '0';
		labelInput.value = '';
		typeSelect.value = 'user';
		chatIdInput.value = '';
		formTitle.textContent = i18n.addNew || 'Add New Recipient';
		saveBtn.textContent = i18n.save || 'Save Recipient';
		saveBtn.disabled = false;
		cancelBtn.style.display = 'none';
		hideFeedback(formFeedback);
	}

	cancelBtn.addEventListener('click', function () {
		resetForm();
	});

	// Form Submission (Add / Update)
	form.addEventListener('submit', function (e) {
		e.preventDefault();
		hideFeedback(formFeedback);
		hideFeedback(listFeedback);

		var id = parseInt(recipientIdInput.value, 10) || 0;
		var label = labelInput.value.trim();
		var type = typeSelect.value;
		var chatId = chatIdInput.value.trim();

		if (!label || !chatId) {
			showFeedback(formFeedback, 'Please fill in all required fields.', 'error');
			return;
		}

		saveBtn.disabled = true;
		saveBtn.textContent = i18n.saving || 'Saving...';

		var formData = new URLSearchParams();
		formData.append('action', 'bale_save_recipient');
		formData.append('nonce', nonce);
		formData.append('id', id);
		formData.append('label', label);
		formData.append('type', type);
		formData.append('chat_id', chatId);

		fetch(ajaxUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: formData.toString()
		})
		.then(function (res) { return res.json(); })
		.then(function (data) {
			saveBtn.disabled = false;
			saveBtn.textContent = id > 0 ? (i18n.edit || 'Update Recipient') : (i18n.save || 'Save Recipient');

			if (!data.success) {
				showFeedback(formFeedback, data.data && data.data.message ? data.data.message : (i18n.errorGeneric || 'Error saving recipient.'), 'error');
				return;
			}

			showFeedback(formFeedback, data.data.message || 'Saved successfully.', 'success');

			var rec = data.data.recipient;
			if (data.data.is_new) {
				// Remove no-items row if exists
				var noItemsRow = tbody.querySelector('tr.no-items');
				if (noItemsRow) {
					noItemsRow.remove();
				}

				// Prepend new row
				var newRow = createRecipientRow(rec);
				tbody.insertBefore(newRow, tbody.firstChild);
			} else {
				// Update existing row
				var existingRow = document.getElementById('bale-recipient-row-' + rec.id);
				if (existingRow) {
					updateRecipientRow(existingRow, rec);
				}
			}

			resetForm();
			showFeedback(formFeedback, data.data.message || 'Saved successfully.', 'success');
		})
		.catch(function () {
			saveBtn.disabled = false;
			saveBtn.textContent = id > 0 ? (i18n.edit || 'Update Recipient') : (i18n.save || 'Save Recipient');
			showFeedback(formFeedback, i18n.errorGeneric || 'A network error occurred.', 'error');
		});
	});

	function getTypeLabel(type) {
		if (type === 'user') return 'Person';
		if (type === 'group') return 'Group';
		if (type === 'channel') return 'Channel';
		return type;
	}

	function getStatusText(status) {
		if (status === 'success') return 'Connected';
		if (status === 'failed') return 'Failed';
		return 'Untested';
	}

	function createRecipientRow(rec) {
		var tr = document.createElement('tr');
		tr.id = 'bale-recipient-row-' + rec.id;
		tr.setAttribute('data-id', rec.id);
		tr.setAttribute('data-label', rec.label);
		tr.setAttribute('data-chat-id', rec.chat_id);
		tr.setAttribute('data-type', rec.type);

		var testStatus = rec.last_test_status || 'untested';

		tr.innerHTML =
			'<td>' + rec.id + '</td>' +
			'<td><strong>' + escapeHtml(rec.label) + '</strong></td>' +
			'<td><code>' + escapeHtml(rec.chat_id) + '</code></td>' +
			'<td><span class="bale-badge bale-badge-' + escapeHtml(rec.type) + '">' + escapeHtml(getTypeLabel(rec.type)) + '</span></td>' +
			'<td class="bale-status-cell">' +
				'<span class="bale-status-indicator bale-status-' + escapeHtml(testStatus) + '">' +
					escapeHtml(getStatusText(testStatus)) +
				'</span>' +
			'</td>' +
			'<td class="bale-actions-cell">' +
				'<button type="button" class="button button-small bale-test-btn" data-id="' + rec.id + '" data-chat-id="' + escapeHtml(rec.chat_id) + '">Test</button> ' +
				'<button type="button" class="button button-small bale-edit-btn" data-id="' + rec.id + '">Edit</button> ' +
				'<button type="button" class="button button-small button-link-delete bale-delete-btn" data-id="' + rec.id + '">Delete</button>' +
			'</td>';

		return tr;
	}

	function updateRecipientRow(tr, rec) {
		tr.setAttribute('data-label', rec.label);
		tr.setAttribute('data-chat-id', rec.chat_id);
		tr.setAttribute('data-type', rec.type);

		var labelCell = tr.cells[1];
		var chatCell = tr.cells[2];
		var typeCell = tr.cells[3];
		var testBtn = tr.querySelector('.bale-test-btn');

		if (labelCell) labelCell.innerHTML = '<strong>' + escapeHtml(rec.label) + '</strong>';
		if (chatCell) chatCell.innerHTML = '<code>' + escapeHtml(rec.chat_id) + '</code>';
		if (typeCell) typeCell.innerHTML = '<span class="bale-badge bale-badge-' + escapeHtml(rec.type) + '">' + escapeHtml(getTypeLabel(rec.type)) + '</span>';
		if (testBtn) testBtn.setAttribute('data-chat-id', rec.chat_id);
	}

	function escapeHtml(str) {
		var div = document.createElement('div');
		div.textContent = str || '';
		return div.innerHTML;
	}

	// Table Event Delegation (Test / Edit / Delete)
	tbody.addEventListener('click', function (e) {
		var target = e.target;

		// Edit button
		if (target.classList.contains('bale-edit-btn')) {
			var row = target.closest('tr');
			if (!row) return;

			var id = row.getAttribute('data-id');
			var label = row.getAttribute('data-label');
			var type = row.getAttribute('data-type');
			var chatId = row.getAttribute('data-chat-id');

			recipientIdInput.value = id;
			labelInput.value = label;
			typeSelect.value = type;
			chatIdInput.value = chatId;

			formTitle.textContent = i18n.editTitle || 'Edit Recipient';
			saveBtn.textContent = i18n.edit || 'Update Recipient';
			cancelBtn.style.display = 'inline-block';
			hideFeedback(formFeedback);
			labelInput.focus();
		}

		// Delete button
		if (target.classList.contains('bale-delete-btn')) {
			var row = target.closest('tr');
			if (!row) return;

			var id = row.getAttribute('data-id');
			if (!confirm(i18n.confirmDelete || 'Are you sure you want to delete this recipient?')) {
				return;
			}

			target.disabled = true;
			hideFeedback(listFeedback);

			var formData = new URLSearchParams();
			formData.append('action', 'bale_delete_recipient');
			formData.append('nonce', nonce);
			formData.append('id', id);

			fetch(ajaxUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
				},
				body: formData.toString()
			})
			.then(function (res) { return res.json(); })
			.then(function (data) {
				if (!data.success) {
					target.disabled = false;
					showFeedback(listFeedback, data.data && data.data.message ? data.data.message : (i18n.errorGeneric || 'Failed to delete recipient.'), 'error');
					return;
				}

				row.remove();
				if (recipientIdInput.value === id) {
					resetForm();
				}

				if (tbody.querySelectorAll('tr').length === 0) {
					var emptyTr = document.createElement('tr');
					emptyTr.className = 'no-items';
					emptyTr.innerHTML = '<td colspan="6">No recipients configured yet. Add your first recipient using the form on the left.</td>';
					tbody.appendChild(emptyTr);
				}

				showFeedback(listFeedback, data.data.message || 'Deleted successfully.', 'success');
			})
			.catch(function () {
				target.disabled = false;
				showFeedback(listFeedback, i18n.errorGeneric || 'A network error occurred.', 'error');
			});
		}

		// Test Connection button
		if (target.classList.contains('bale-test-btn')) {
			var row = target.closest('tr');
			if (!row) return;

			var id = row.getAttribute('data-id');
			var statusCell = row.querySelector('.bale-status-cell');

			target.disabled = true;
			hideFeedback(listFeedback);

			if (statusCell) {
				statusCell.innerHTML = '<span class="bale-status-indicator bale-status-testing">' + (i18n.testing || 'Testing...') + '</span>';
			}

			var formData = new URLSearchParams();
			formData.append('action', 'bale_test_recipient_connection');
			formData.append('nonce', nonce);
			formData.append('recipient_id', id);

			fetch(ajaxUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
				},
				body: formData.toString()
			})
			.then(function (res) { return res.json(); })
			.then(function (data) {
				target.disabled = false;

				if (data.success) {
					if (statusCell) {
						statusCell.innerHTML = '<span class="bale-status-indicator bale-status-success" title="' + escapeHtml(data.data.tested_at || '') + '">Connected</span>';
					}
					showFeedback(listFeedback, data.data.message || 'Connection successful.', 'success');
				} else {
					if (statusCell) {
						statusCell.innerHTML = '<span class="bale-status-indicator bale-status-failed">Failed</span>';
					}
					showFeedback(listFeedback, data.data && data.data.message ? data.data.message : 'Connection test failed.', 'error');
				}
			})
			.catch(function () {
				target.disabled = false;
				if (statusCell) {
					statusCell.innerHTML = '<span class="bale-status-indicator bale-status-failed">Failed</span>';
				}
				showFeedback(listFeedback, i18n.errorGeneric || 'Network error during connection test.', 'error');
			});
		}
	});
});
