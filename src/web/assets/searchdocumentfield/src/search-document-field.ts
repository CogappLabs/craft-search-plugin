/// <reference path="../../types/craft.d.ts" />

import './search-document-field.css';

/**
 * Thin JS bridge for SearchDocumentField.
 *
 * The Sprig component handles search, results, and selection state.
 * This script handles two things:
 *   1. Syncing data-* attributes from the Sprig root to Craft's hidden form inputs after each swap.
 *   2. Keyboard navigation (ArrowUp/Down/Enter/Escape) on the results listbox.
 */

// -- Data bridge: sync Sprig state to Craft hidden inputs after each swap --

document.body.addEventListener('htmx:afterSwap', (event: Event) => {
  const target = (event as CustomEvent).detail?.target as HTMLElement | undefined;
  if (!target) return;

  // Find the Sprig root within or as the swapped target
  const sprigRoot = target.classList.contains('sdf-sprig-root')
    ? target
    : target.querySelector<HTMLElement>('.sdf-sprig-root');
  if (!sprigRoot) return;

  // Walk up to the .search-document-field container that holds the hidden inputs
  const container = sprigRoot.closest<HTMLElement>('.search-document-field');
  if (!container) return;

  const fieldMap: Record<string, string> = {
    documentId: sprigRoot.dataset.documentId ?? '',
    sectionHandle: sprigRoot.dataset.sectionHandle ?? '',
    entryTypeHandle: sprigRoot.dataset.entryTypeHandle ?? '',
  };

  for (const [field, value] of Object.entries(fieldMap)) {
    const input = container.querySelector<HTMLInputElement>(`[data-sdf-field="${field}"]`);
    if (input) {
      input.value = value;
    }
  }
});

// -- Keyboard navigation for results listbox --

document.body.addEventListener('keydown', (e: KeyboardEvent) => {
  const target = e.target as HTMLElement;
  if (!target.classList.contains('sdf-query')) return;

  const container = target.closest<HTMLElement>('.search-document-field');
  if (!container) return;

  const items = container.querySelectorAll<HTMLElement>('.sdf-result-item');
  if (!items.length && e.key !== 'Escape') return;

  const results = container.querySelector<HTMLElement>('.sdf-results');
  let activeIndex = -1;
  items.forEach((item, i) => {
    if (item.classList.contains('sdf-active')) activeIndex = i;
  });

  switch (e.key) {
    case 'ArrowDown':
      e.preventDefault();
      setActive(items, activeIndex < items.length - 1 ? activeIndex + 1 : 0, target);
      break;
    case 'ArrowUp':
      e.preventDefault();
      setActive(items, activeIndex > 0 ? activeIndex - 1 : items.length - 1, target);
      break;
    case 'Enter':
      e.preventDefault();
      if (activeIndex >= 0 && activeIndex < items.length) {
        const btn = items[activeIndex].querySelector<HTMLButtonElement>('.sdf-result-btn');
        btn?.click();
      }
      break;
    case 'Escape':
      e.preventDefault();
      if (results) results.classList.add('hidden');
      items.forEach((item) => {
        item.classList.remove('sdf-active');
      });
      target.removeAttribute('aria-activedescendant');
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
