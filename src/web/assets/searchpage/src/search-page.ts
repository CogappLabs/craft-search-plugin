import "./search-page.css";

/**
 * Search page mode toggle: switches between Single and Compare views.
 */
(() => {
	const toggle = document.getElementById("search-mode-toggle");
	if (!toggle) {
		return;
	}

	const btns = toggle.querySelectorAll<HTMLButtonElement>(".btn");
	const single = document.getElementById("single-mode");
	const compare = document.getElementById("compare-mode");

	if (!single || !compare) {
		return;
	}

	for (const btn of btns) {
		btn.addEventListener("click", () => {
			for (const b of btns) {
				b.classList.remove("active");
			}
			btn.classList.add("active");

			if (btn.dataset.mode === "single") {
				single.classList.remove("hidden");
				compare.classList.add("hidden");
			} else {
				single.classList.add("hidden");
				compare.classList.remove("hidden");
			}
		});
	}
})();
