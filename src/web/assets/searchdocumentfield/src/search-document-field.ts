/// <reference path="../../types/craft.d.ts" />

import './search-document-field.css';

/**
 * Thin JS bridge for SearchDocumentField.
 *
 * The Sprig component handles search, results, and selection state.
 * This script handles two things the Sprig boundary can't:
 *   1. Syncing data-* attributes from the Sprig root to Craft's namespaced hidden inputs.
 *   2. Keyboard navigation (ArrowUp/Down/Enter/Escape) on the results listbox.
 *
 * Focus is preserved automatically by htmx via the stable `id` on the search input.
 */

// -- Namespace fix: Craft's field namespace prefixes the input name (e.g. "fields[query]")
// which Sprig can't map to the component's $query property. Ensure 'query' is always
// sent as a top-level parameter so the Sprig component receives it correctly. --

document.body.addEventListener('htmx:configRequest', (event: Event) => {
  const el = (event as CustomEvent).detail?.elt as HTMLElement | undefined;
  if (!el?.classList.contains('sdf-query')) return;
  const params = (event as CustomEvent).detail.parameters;
  params.query = (el as HTMLInputElement).value;
});

// -- Data bridge: sync Sprig state → Craft hidden inputs after each swap --

document.body.addEventListener('htmx:afterSettle', (event: Event) => {
  const target = (event as CustomEvent).detail?.target as HTMLElement | undefined;
  if (!target) return;

  const sprigRoot = target.classList.contains('sdf-sprig-root')
    ? target
    : target.querySelector<HTMLElement>('.sdf-sprig-root');
  if (!sprigRoot) return;

  const container = sprigRoot.closest<HTMLElement>('.search-document-field');
  if (!container) return;

  const map: Record<string, string> = {
    documentId: sprigRoot.dataset.documentId ?? '',
    sectionHandle: sprigRoot.dataset.sectionHandle ?? '',
    entryTypeHandle: sprigRoot.dataset.entryTypeHandle ?? '',
  };

  for (const [field, value] of Object.entries(map)) {
    const input = container.querySelector<HTMLInputElement>(`[data-sdf-field="${field}"]`);
    if (input) input.value = value;
  }
});

// -- Keyboard navigation for results listbox --

document.body.addEventListener('keydown', (e: KeyboardEvent) => {
  const el = e.target as HTMLElement;
  if (!el.classList.contains('sdf-query')) return;

  const container = el.closest<HTMLElement>('.search-document-field');
  if (!container) return;

  const items = container.querySelectorAll<HTMLElement>('.sdf-result-item');
  if (!items.length && e.key !== 'Escape') return;

  let activeIndex = -1;
  items.forEach((item, i) => {
    if (item.classList.contains('sdf-active')) activeIndex = i;
  });

  switch (e.key) {
    case 'ArrowDown':
      e.preventDefault();
      setActive(items, activeIndex < items.length - 1 ? activeIndex + 1 : 0, el);
      break;
    case 'ArrowUp':
      e.preventDefault();
      setActive(items, activeIndex > 0 ? activeIndex - 1 : items.length - 1, el);
      break;
    case 'Enter':
      e.preventDefault();
      if (activeIndex >= 0 && activeIndex < items.length) {
        items[activeIndex].querySelector<HTMLButtonElement>('.sdf-result-btn')?.click();
      }
      break;
    case 'Escape':
      e.preventDefault();
      container.querySelector<HTMLElement>('.sdf-results')?.classList.add('hidden');
      items.forEach((item) => {
        item.classList.remove('sdf-active');
      });
      el.removeAttribute('aria-activedescendant');
      break;
  }
});

function setActive(items: NodeListOf<HTMLElement>, index: number, input: HTMLElement): void {
  items.forEach((item, i) => {
    item.classList.toggle('sdf-active', i === index);
  });
  if (index >= 0 && items[index]) {
    input.setAttribute('aria-activedescendant', items[index].id);
    items[index].scrollIntoView({ block: 'nearest' });
  } else {
    input.removeAttribute('aria-activedescendant');
  }
}
