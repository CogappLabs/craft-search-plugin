/**
 * Search Index — Histogram Modal
 *
 * Renders an SVG bar chart inside a <dialog> for numeric range filters.
 * Reads histogram data from data-* attributes set by Twig, wires open/close
 * and Apply (copies values to main form, triggers Sprig submit).
 *
 * Re-initialises automatically after each Sprig swap via htmx:afterSettle.
 */

interface Bucket {
	key: number;
	count: number;
}

const SVG_NS = "http://www.w3.org/2000/svg";
const W = 500;
const H = 250;
const PAD = { top: 10, right: 15, bottom: 40, left: 55 };

function formatNumber(n: number): string {
	if (n >= 1e6) return `${(n / 1e6).toFixed(n % 1e6 === 0 ? 0 : 1)}M`;
	if (n >= 1e3) return `${(n / 1e3).toFixed(n % 1e3 === 0 ? 0 : 1)}K`;
	return String(n);
}

function yTicks(maxVal: number): number[] {
	if (maxVal <= 0) return [0];
	const rough = maxVal / 4;
	const mag = 10 ** Math.floor(Math.log10(rough));
	const residual = rough / mag;
	let nice: number;
	if (residual <= 1) nice = 1;
	else if (residual <= 2) nice = 2;
	else if (residual <= 5) nice = 5;
	else nice = 10;
	const step = nice * mag;
	const ticks: number[] = [];
	for (let v = 0; v <= maxVal; v += step) {
		ticks.push(Math.round(v));
	}
	if (ticks[ticks.length - 1] < maxVal) {
		ticks.push(ticks[ticks.length - 1] + Math.round(step));
	}
	return ticks;
}

function svgEl(
	tag: string,
	attrs: Record<string, string | number>,
): SVGElement {
	const el = document.createElementNS(SVG_NS, tag);
	for (const [k, v] of Object.entries(attrs)) {
		el.setAttribute(k, String(v));
	}
	return el;
}

function renderChart(svg: SVGElement, buckets: Bucket[]): void {
	svg.innerHTML = "";
	svg.setAttribute("viewBox", `0 0 ${W} ${H}`);
	svg.setAttribute("preserveAspectRatio", "xMidYMid meet");

	if (!buckets.length) return;

	let maxCount = Math.max(...buckets.map((b) => b.count));
	if (maxCount <= 0) maxCount = 1;

	const ticks = yTicks(maxCount);
	const yMax = ticks[ticks.length - 1] || 1;

	const plotW = W - PAD.left - PAD.right;
	const plotH = H - PAD.top - PAD.bottom;
	const barW = Math.max(1, plotW / buckets.length - 1);

	// Y-axis line
	svg.appendChild(
		svgEl("line", {
			x1: PAD.left,
			y1: PAD.top,
			x2: PAD.left,
			y2: PAD.top + plotH,
			stroke: "#94a3b8",
			"stroke-width": 1,
		}),
	);

	// X-axis line
	svg.appendChild(
		svgEl("line", {
			x1: PAD.left,
			y1: PAD.top + plotH,
			x2: PAD.left + plotW,
			y2: PAD.top + plotH,
			stroke: "#94a3b8",
			"stroke-width": 1,
		}),
	);

	// Y-axis ticks + labels + grid lines
	for (const tick of ticks) {
		const yPos = PAD.top + plotH - (tick / yMax) * plotH;

		svg.appendChild(
			svgEl("line", {
				x1: PAD.left - 4,
				y1: yPos,
				x2: PAD.left,
				y2: yPos,
				stroke: "#94a3b8",
				"stroke-width": 1,
			}),
		);
		svg.appendChild(
			svgEl("line", {
				x1: PAD.left,
				y1: yPos,
				x2: PAD.left + plotW,
				y2: yPos,
				stroke: "#e2e8f0",
				"stroke-width": 0.5,
			}),
		);

		const label = svgEl("text", {
			x: PAD.left - 8,
			y: yPos + 4,
			"text-anchor": "end",
			"font-size": 11,
			fill: "#64748b",
		});
		label.textContent = formatNumber(tick);
		svg.appendChild(label);
	}

	// Bars
	for (let i = 0; i < buckets.length; i++) {
		const bh = (buckets[i].count / yMax) * plotH;
		const bx = PAD.left + i * (barW + 1);
		const by = PAD.top + plotH - bh;

		const rect = svgEl("rect", {
			x: bx,
			y: by,
			width: barW,
			height: Math.max(bh, 0),
			fill: "#94a3b8",
			rx: 1,
		});
		const title = svgEl("title", {});
		title.textContent = `${buckets[i].key}: ${buckets[i].count}`;
		rect.appendChild(title);
		svg.appendChild(rect);
	}

	// X-axis labels — show every Nth to avoid overlap
	const maxLabels = Math.floor(plotW / 40);
	const labelStep = Math.max(1, Math.ceil(buckets.length / maxLabels));
	for (let j = 0; j < buckets.length; j += labelStep) {
		const lx = PAD.left + j * (barW + 1) + barW / 2;
		const xLabel = svgEl("text", {
			x: lx,
			y: PAD.top + plotH + 16,
			"text-anchor": "middle",
			"font-size": 10,
			fill: "#64748b",
		});
		xLabel.textContent = formatNumber(Number(buckets[j].key));
		svg.appendChild(xLabel);
	}
}

function initHistograms(): void {
	const triggers = document.querySelectorAll<HTMLElement>(
		".si-histogram-trigger[data-histogram]:not([data-si-init])",
	);

	for (const trigger of triggers) {
		trigger.setAttribute("data-si-init", "1");

		let buckets: Bucket[];
		try {
			buckets = JSON.parse(trigger.getAttribute("data-histogram") ?? "[]");
		} catch {
			continue;
		}

		const dialogId = trigger.getAttribute("data-dialog-id");
		const dialog = document.getElementById(
			dialogId ?? "",
		) as HTMLDialogElement | null;
		if (!dialog) continue;

		const svg = dialog.querySelector("svg");
		if (svg) renderChart(svg, buckets);

		// Open
		trigger.addEventListener("click", () => dialog.showModal());

		// Close button
		const closeBtn = dialog.querySelector<HTMLElement>(".si-histogram-close");
		closeBtn?.addEventListener("click", () => dialog.close());

		// Backdrop click
		dialog.addEventListener("click", (e) => {
			if (e.target === dialog) dialog.close();
		});

		// Apply button — copy values to main form, trigger Sprig submit
		const applyBtn = dialog.querySelector<HTMLElement>(".si-histogram-apply");
		applyBtn?.addEventListener("click", () => {
			const minInput =
				dialog.querySelector<HTMLInputElement>(".si-histogram-min");
			const maxInput =
				dialog.querySelector<HTMLInputElement>(".si-histogram-max");
			const minTarget = document.getElementById(
				applyBtn.getAttribute("data-min-target") ?? "",
			) as HTMLInputElement | null;
			const maxTarget = document.getElementById(
				applyBtn.getAttribute("data-max-target") ?? "",
			) as HTMLInputElement | null;
			const applyTarget = document.getElementById(
				applyBtn.getAttribute("data-apply-target") ?? "",
			) as HTMLElement | null;

			if (minTarget && minInput) minTarget.value = minInput.value;
			if (maxTarget && maxInput) maxTarget.value = maxInput.value;
			dialog.close();
			applyTarget?.click();
		});
	}
}

// Init on page load
if (document.readyState === "loading") {
	document.addEventListener("DOMContentLoaded", initHistograms);
} else {
	initHistograms();
}

// Re-init after Sprig swaps
document.addEventListener("htmx:afterSettle", initHistograms);
