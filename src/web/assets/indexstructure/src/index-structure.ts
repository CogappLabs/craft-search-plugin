/// <reference path="../../types/craft.d.ts" />
import './index-structure.css';

(() => {
  const container = document.getElementById('index-structure-container') as HTMLElement | null;
  if (!container) return;

  const indexId = container.dataset.indexId;
  if (!indexId) return;

  const output = document.getElementById('structure-output') as HTMLPreElement | null;
  const refreshBtn = document.getElementById('refresh-structure-btn') as HTMLButtonElement | null;
  if (!output || !refreshBtn) return;

  function loadStructure(): void {
    refreshBtn!.classList.add('loading');

    Craft.sendActionRequest<{ success: boolean; schema?: unknown; message?: string }>(
      'POST',
      'search-index/indexes/structure',
      {
        data: { id: indexId },
      },
    )
      .then((response) => {
        refreshBtn!.classList.remove('loading');
        if (response.data.success) {
          output!.textContent = JSON.stringify(response.data.schema, null, 2);
        } else {
          output!.textContent = response.data.message || 'Failed to retrieve schema.';
        }
      })
      .catch(() => {
        refreshBtn!.classList.remove('loading');
        output!.textContent = 'Request failed.';
      });
  }

  refreshBtn.addEventListener('click', loadStructure);

  // Load on page init
  loadStructure();
})();
