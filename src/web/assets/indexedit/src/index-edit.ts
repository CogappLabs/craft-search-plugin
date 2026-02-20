/// <reference path="../../types/craft.d.ts" />

(() => {
	const form = document.getElementById(
		"search-index-edit-form",
	) as HTMLFormElement | null;
	if (!form) return;

	const isNew = form.dataset.isNew === "true";

	// Toggle engine config fields and disable hidden inputs so they don't submit
	const engineSelect = document.getElementById(
		"engineType",
	) as HTMLSelectElement | null;

	function syncEngineConfigFields(selectedEngine: string): void {
		document
			.querySelectorAll<HTMLElement>(".engine-config-fields")
			.forEach((el) => {
				const isSelected = el.dataset.engine === selectedEngine;
				el.classList.toggle("hidden", !isSelected);
				el.querySelectorAll<HTMLInputElement>("input").forEach((input) => {
					input.disabled = !isSelected;
				});
			});
	}

	if (engineSelect) {
		// Disable hidden engine inputs on initial load
		syncEngineConfigFields(engineSelect.value);

		engineSelect.addEventListener("change", function () {
			syncEngineConfigFields(this.value);
			const selected = document.querySelector<HTMLElement>(
				`.engine-config-fields[data-engine="${CSS.escape(this.value)}"]`,
			);
			if (selected) {
				Craft.initUiElements(selected);
			}
		});
	}

	// Handle name -> handle auto-generation
	const nameInput = document.getElementById("name") as HTMLInputElement | null;
	const handleInput = document.getElementById(
		"handle",
	) as HTMLInputElement | null;
	let handleManuallySet = !isNew;

	if (nameInput && handleInput) {
		nameInput.addEventListener("input", function () {
			if (!handleManuallySet) {
				handleInput.value = this.value
					.toLowerCase()
					.replace(/[^a-z0-9]+/g, "_")
					.replace(/^_|_$/g, "");
			}
		});

		handleInput.addEventListener("input", () => {
			handleManuallySet = true;
		});
	}

	// Toggle entry type checkboxes based on selected sections
	document
		.querySelectorAll<HTMLInputElement>('#sectionIds input[type="checkbox"]')
		.forEach((cb) => {
			cb.addEventListener("change", function () {
				document
					.querySelectorAll<HTMLElement>(
						`.entry-type-checkbox[data-section="${this.value}"]`,
					)
					.forEach((el) => {
						el.classList.toggle("hidden", !cb.checked);
					});
			});
		});

	// Toggle sources section based on mode
	const modeSelect = document.getElementById(
		"mode",
	) as HTMLSelectElement | null;
	const sourcesSection = document.getElementById(
		"synced-sources",
	) as HTMLElement | null;

	if (modeSelect && sourcesSection) {
		function toggleSources(): void {
			sourcesSection?.classList.toggle(
				"hidden",
				modeSelect?.value === "readonly",
			);
		}

		modeSelect.addEventListener("change", toggleSources);
		toggleSources();
	}

	// AJAX toggle for enabled lightswitch (bypasses project config)
	const toggleWrapper = document.getElementById("si-toggle-enabled");
	if (toggleWrapper) {
		const indexId = toggleWrapper.dataset.indexId;
		const t = {
			enabledNotice: toggleWrapper.dataset.tEnabledNotice ?? "Index enabled.",
			disabledNotice:
				toggleWrapper.dataset.tDisabledNotice ?? "Index disabled.",
			toggleError:
				toggleWrapper.dataset.tToggleError ?? "Failed to update enabled state.",
		};

		const lightswitch = toggleWrapper.querySelector(
			".lightswitch",
		) as HTMLElement | null;
		if (lightswitch) {
			// Watch aria-checked attribute changes (reliable across click + keyboard toggle)
			const observer = new MutationObserver(async () => {
				const enabled = lightswitch.getAttribute("aria-checked") === "true";
				try {
					const response = await Craft.sendActionRequest(
						"POST",
						"search-index/indexes/toggle-enabled",
						{ data: { id: indexId, enabled: enabled ? 1 : 0 } },
					);
					if (response.data?.success) {
						Craft.cp.displayNotice(
							enabled ? t.enabledNotice : t.disabledNotice,
						);
					} else {
						Craft.cp.displayError(t.toggleError);
					}
				} catch {
					Craft.cp.displayError(t.toggleError);
				}
			});
			observer.observe(lightswitch, {
				attributes: true,
				attributeFilter: ["aria-checked"],
			});
		}
	}
})();
