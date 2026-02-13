/// <reference path="../../types/craft.d.ts" />

(() => {
  const container = document.getElementById('index-structure-container') as HTMLElement | null;
  if (!container) return;

  const indexId = container.dataset.indexId!;
  const output = document.getElementById('structure-output') as HTMLPreElement;
  const refreshBtn = document.getElementById('refresh-structure-btn') as HTMLButtonElement;

  function loadStructure(): void {
    refreshBtn.classList.add('loading');

    Craft.sendActionRequest('POST', 'search-index/indexes/structure', {
      data: { id: indexId },
    })
      .then((response) => {
        refreshBtn.classList.remove('loading');
        const data = response.data as Record<string, unknown>;
        if (data.success) {
          output.textContent = JSON.stringify(data.schema, null, 2);
        } else {
          output.textContent = (data.message as string) || 'Failed to retrieve schema.';
        }
      })
      .catch(() => {
        refreshBtn.classList.remove('loading');
        output.textContent = 'Request failed.';
      });
  }

  refreshBtn.addEventListener('click', loadStructure);

  // Load on page init
  loadStructure();
})();
