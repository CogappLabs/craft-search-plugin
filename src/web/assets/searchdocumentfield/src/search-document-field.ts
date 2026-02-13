/// <reference path="../../types/craft.d.ts" />

import './search-document-field.css';

(() => {
  document
    .querySelectorAll<HTMLElement>('.search-document-field[data-field-id]')
    .forEach(initField);

  function initField(container: HTMLElement): void {
    const indexHandleInput = container.querySelector<HTMLInputElement>('.sdf-index-handle')!;
    const documentIdInput = container.querySelector<HTMLInputElement>('.sdf-document-id')!;
    const queryInput = container.querySelector<HTMLInputElement>('.sdf-query')!;
    const resultsContainer = container.querySelector<HTMLElement>('.sdf-results')!;
    const resultsList = container.querySelector<HTMLElement>('.sdf-results-list')!;
    const selectedContainer = container.querySelector<HTMLElement>('.sdf-selected')!;
    const selectedTitle = container.querySelector<HTMLElement>('.sdf-selected-title')!;
    const searchContainer = container.querySelector<HTMLElement>('.sdf-search')!;
    const clearBtn = container.querySelector<HTMLButtonElement>('.sdf-clear')!;

    let debounceTimer: ReturnType<typeof setTimeout>;
    const perPage = parseInt(container.dataset.perPage || '10', 10) || 10;

    // Debounced search
    queryInput.addEventListener('input', function () {
      clearTimeout(debounceTimer);
      const q = this.value.trim();
      if (q.length < 1) {
        resultsContainer.style.display = 'none';
        return;
      }
      debounceTimer = setTimeout(() => doSearch(q), 300);
    });

    function doSearch(query: string): void {
      Craft.sendActionRequest('POST', 'search-index/search/search', {
        data: {
          indexHandle: indexHandleInput.value,
          query,
          perPage,
        },
      })
        .then((response) => {
          const data = response.data as Record<string, unknown>;
          resultsList.innerHTML = '';

          const hits = data.hits as Array<Record<string, unknown>> | undefined;
          if (!data.success || !hits || hits.length === 0) {
            resultsList.innerHTML = '<li class="sdf-no-results"><em>No results found.</em></li>';
            resultsContainer.style.display = '';
            return;
          }

          hits.forEach((hit) => {
            const li = document.createElement('li');
            li.className = 'sdf-result-item';
            li.style.cssText =
              'padding:6px 10px;cursor:pointer;border-bottom:1px solid var(--gray-200)';

            const title = (hit.title || hit.name || hit.objectID) as string;
            const uri = (hit.uri || '') as string;
            li.innerHTML =
              `<strong>${Craft.escapeHtml(title)}</strong>` +
              (uri ? `<br><small class="light">${Craft.escapeHtml(uri)}</small>` : '');

            li.addEventListener('click', () => {
              selectDocument(hit.objectID as string, title, uri);
            });

            resultsList.appendChild(li);
          });

          resultsContainer.style.display = '';
        })
        .catch(() => {
          Craft.cp.displayError('Search failed.');
        });
    }

    function selectDocument(docId: string, title: string, uri: string): void {
      documentIdInput.value = docId;
      selectedTitle.textContent = `${title + (uri ? ` — ${uri}` : '')} (ID: ${docId})`;
      selectedContainer.style.display = '';
      searchContainer.style.display = 'none';
      resultsContainer.style.display = 'none';
      queryInput.value = '';
      resultsList.innerHTML = '';
    }

    // Clear selection
    clearBtn.addEventListener('click', () => {
      documentIdInput.value = '';
      selectedContainer.style.display = 'none';
      searchContainer.style.display = '';
      selectedTitle.textContent = '';
    });

    // On load with existing value: fetch document details
    if (documentIdInput.value) {
      Craft.sendActionRequest('POST', 'search-index/search/get-document', {
        data: {
          indexHandle: indexHandleInput.value,
          documentId: documentIdInput.value,
        },
      })
        .then((response) => {
          const data = response.data as Record<string, unknown>;
          if (data.success && data.document) {
            const doc = data.document as Record<string, unknown>;
            const title = (doc.title || doc.name || documentIdInput.value) as string;
            const uri = (doc.uri || '') as string;
            selectedTitle.textContent = `${title + (uri ? ` — ${uri}` : '')} (ID: ${documentIdInput.value})`;
          } else {
            selectedTitle.textContent = `Document ${documentIdInput.value} (not found)`;
          }
        })
        .catch(() => {
          selectedTitle.textContent = `Document ${documentIdInput.value}`;
        });
    }
  }
})();
