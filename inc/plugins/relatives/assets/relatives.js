/**
 * relatives.js - Verwandtschaften & Stammbaum
 *
 * Shared confirm dialog for destructive actions (delete person, delete family). Looks
 * like the family-tree panels (same classes from relatives-tree.css, which is attached
 * globally) instead of the browser's native confirm().
 *
 * Usage:
 *   - Forms: <form data-relatives-confirm="Question?" data-relatives-confirm-title="…"
 *            data-relatives-confirm-ok="Delete" data-relatives-confirm-cancel="Cancel">
 *     The submit is held back until the dialog is confirmed.
 *   - Script: RelativesConfirm.open({ message, title, ok, cancel }, onConfirm)
 * No external libraries (project rule).
 */
(function () {
	"use strict";

	var root = null;
	var lastFocus = null;
	var onKey = null;

	function close() {
		if (root && root.parentNode) { root.parentNode.removeChild(root); }
		root = null;
		if (onKey) { document.removeEventListener("keydown", onKey); onKey = null; }
		if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
	}

	function button(label, className, handler) {
		var btn = document.createElement("button");
		btn.type = "button";
		btn.className = className;
		btn.textContent = label;
		btn.addEventListener("click", handler);
		return btn;
	}

	function open(options, onConfirm) {
		close();
		options = options || {};
		lastFocus = document.activeElement;

		// .relatives_tree_page carries the colour tokens the panel classes rely on.
		root = document.createElement("div");
		root.className = "relatives_tree_page relatives_confirm";

		var backdrop = document.createElement("div");
		backdrop.className = "relatives_tree_backdrop";
		backdrop.addEventListener("click", close);
		root.appendChild(backdrop);

		var panel = document.createElement("div");
		panel.className = "relatives_tree_panel relatives_confirm_panel";
		panel.setAttribute("role", "alertdialog");
		panel.setAttribute("aria-modal", "true");
		panel.setAttribute("aria-labelledby", "relatives-confirm-title");
		panel.setAttribute("aria-describedby", "relatives-confirm-message");

		var head = document.createElement("div");
		head.className = "relatives_tree_panel_header";
		var heading = document.createElement("h3");
		heading.id = "relatives-confirm-title";
		heading.textContent = options.title || "";
		head.appendChild(heading);
		var closeBtn = button("×", "relatives_tree_panel_close", close);
		closeBtn.setAttribute("aria-label", options.cancel || "");
		head.appendChild(closeBtn);
		panel.appendChild(head);

		var message = document.createElement("p");
		message.id = "relatives-confirm-message";
		message.className = "relatives_tree_panel_lead";
		message.textContent = options.message || "";
		panel.appendChild(message);

		var actions = document.createElement("div");
		actions.className = "relatives_confirm_actions";
		var cancelBtn = button(options.cancel || "", "relatives_tree_menu_button", close);
		actions.appendChild(button(options.ok || "", "relatives_tree_menu_button relatives_tree_menu_button_danger", function () {
			close();
			if (onConfirm) { onConfirm(); }
		}));
		actions.appendChild(cancelBtn);
		panel.appendChild(actions);

		root.appendChild(panel);
		document.body.appendChild(root);

		onKey = function (ev) { if (ev.key === "Escape") { close(); } };
		document.addEventListener("keydown", onKey);
		// Focus the safe choice: Enter must not delete by accident.
		cancelBtn.focus();
	}

	// Forms marked with data-relatives-confirm ask first. form.submit() does not fire
	// the submit event again, so the confirmed form goes straight through.
	document.addEventListener("submit", function (ev) {
		var form = ev.target;
		if (!form || !form.getAttribute || !form.hasAttribute("data-relatives-confirm")) { return; }
		ev.preventDefault();
		open({
			message: form.getAttribute("data-relatives-confirm"),
			title: form.getAttribute("data-relatives-confirm-title"),
			ok: form.getAttribute("data-relatives-confirm-ok"),
			cancel: form.getAttribute("data-relatives-confirm-cancel")
		}, function () { form.submit(); });
	});

	window.RelativesConfirm = { open: open, close: close };
})();
