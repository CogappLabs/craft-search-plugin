/// <reference path="../../types/craft.d.ts" />
import "./field-mappings.css";

(() => {
	const container = document.getElementById(
		"field-mappings-container",
	) as HTMLElement | null;
	if (!container) return;

	// ── Role chip sync + uniqueness ──
	const table = document.getElementById("si-field-mappings");
	if (table) {
		const roleSelects = table.querySelectorAll<HTMLSelectElement>(
			"select.si-role-input",
		);

		roleSelects.forEach((select) => {
			select.addEventListener("change", () => {
				const newRole = select.value;

				// Enforce one-role-per-index: clear any other select with the same role
				if (newRole) {
					roleSelects.forEach((other) => {
						if (other !== select && other.value === newRole) {
							other.value = "";
							syncChipForSelect(other);
						}
					});
				}

				syncChipForSelect(select);
			});
		});
	}

	function syncChipForSelect(select: HTMLSelectElement): void {
		const row = select.closest("tr");
		if (!row) return;
		const chip = row.querySelector<HTMLElement>(".si-role-chip");
		if (!chip) return;
		chip.dataset.role = select.value;
		chip.textContent = select.value;
		if (select.value) {
			chip.setAttribute("aria-label", `Role: ${select.value}`);
		} else {
			chip.removeAttribute("aria-label");
		}
	}

	// ── Clipboard copy (reads data-markdown from Sprig-rendered buttons) ──
	const tCopied = container.dataset.tCopied || "Copied to clipboard.";
	const tCopyFailed =
		container.dataset.tCopyFailed || "Failed to copy to clipboard.";

	document.addEventListener("click", (e) => {
		const btn = (e.target as HTMLElement).closest<HTMLButtonElement>(
			".js-copy-markdown",
		);
		if (!btn) return;

		const md = btn.dataset.markdown;
		if (!md) return;

		navigator.clipboard.writeText(md).then(
			() => {
				Craft.cp.displayNotice(tCopied);
			},
			() => {
				// Fallback for browsers without clipboard API
				const ta = document.createElement("textarea");
				ta.value = md;
				ta.style.position = "fixed";
				ta.style.opacity = "0";
				document.body.appendChild(ta);
				ta.select();
				const success = document.execCommand("copy");
				document.body.removeChild(ta);
				if (success) {
					Craft.cp.displayNotice(tCopied);
				} else {
					Craft.cp.displayError(tCopyFailed);
				}
			},
		);
	});
})();
