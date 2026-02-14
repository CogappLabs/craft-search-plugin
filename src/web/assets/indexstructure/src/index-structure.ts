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

  const tFailed = container.dataset.tFailed || 'Failed to retrieve schema.';
  const tRequestFailed = container.dataset.tRequestFailed || 'Request failed.';

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
          output!.classList.remove('si-code-error');
          output!.textContent = JSON.stringify(response.data.schema, null, 2);
        } else {
          output!.classList.add('si-code-error');
          output!.textContent = response.data.message || tFailed;
        }
      })
      .catch(() => {
        refreshBtn!.classList.remove('loading');
        output!.classList.add('si-code-error');
        output!.textContent = tRequestFailed;
      });
  }

  refreshBtn.addEventListener('click', loadStructure);

  // Load on page init
  loadStructure();
})();
