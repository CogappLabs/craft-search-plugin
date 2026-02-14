/// <reference path="../../types/craft.d.ts" />

import './search-document-field.css';

interface SearchDocumentHit {
  objectID?: string;
  title?: string;
  name?: string;
  uri?: string;
  sectionHandle?: string;
  entryTypeHandle?: string;
  [key: string]: unknown;
}

interface SearchDocumentResponse {
  success: boolean;
  hits?: SearchDocumentHit[];
}

interface GetDocumentResponse {
  success: boolean;
  document?: SearchDocumentHit;
}

const SEARCH_DEBOUNCE_MS = 300;
const DEFAULT_PER_PAGE = 10;

(() => {
  document
    .querySelectorAll<HTMLElement>('.search-document-field[data-field-id]')
    .forEach(initField);

  function initField(container: HTMLElement): void {
    const indexHandleInput = container.querySelector<HTMLInputElement>('.sdf-index-handle');
    const documentIdInput = container.querySelector<HTMLInputElement>('.sdf-document-id');
    const sectionHandleInput = container.querySelector<HTMLInputElement>('.sdf-section-handle');
    const entryTypeHandleInput =
      container.querySelector<HTMLInputElement>('.sdf-entry-type-handle');
    const queryInput = container.querySelector<HTMLInputElement>('.sdf-query');
    const resultsContainer = container.querySelector<HTMLElement>('.sdf-results');
    const resultsList = container.querySelector<HTMLElement>('.sdf-results-list');
    const selectedContainer = container.querySelector<HTMLElement>('.sdf-selected');
    const selectedTitle = container.querySelector<HTMLElement>('.sdf-selected-title');
    const searchContainer = container.querySelector<HTMLElement>('.sdf-search');
    const clearBtn = container.querySelector<HTMLButtonElement>('.sdf-clear');

    if (
      !indexHandleInput ||
      !documentIdInput ||
      !sectionHandleInput ||
      !entryTypeHandleInput ||
      !queryInput ||
      !resultsContainer ||
      !resultsList ||
      !selectedContainer ||
      !selectedTitle ||
      !searchContainer ||
      !clearBtn
    ) {
      return;
    }

    // Re-bind after null check so TS narrows types in closures
    const _indexHandle = indexHandleInput;
    const _docId = documentIdInput;
    const _sectionHandle = sectionHandleInput;
    const _entryTypeHandle = entryTypeHandleInput;
    const _query = queryInput;
    const _results = resultsContainer;
    const _list = resultsList;
    const _selected = selectedContainer;
    const _title = selectedTitle;
    const _search = searchContainer;

    const tSearchFailed = container.dataset.tSearchFailed || 'Search failed.';
    const tNoResults = container.dataset.tNoResults || 'No results found.';
    const tNotFound = container.dataset.tNotFound || 'Document {id} (not found)';

    let debounceTimer: ReturnType<typeof setTimeout>;
    let activeIndex = -1;
    let currentHits: SearchDocumentHit[] = [];
    let searchAbortController: AbortController | null = null;
    const perPage =
      parseInt(container.dataset.perPage || String(DEFAULT_PER_PAGE), 10) || DEFAULT_PER_PAGE;

    // Debounced search
    _query.addEventListener('input', function () {
      clearTimeout(debounceTimer);
      const q = this.value.trim();
      if (q.length < 1) {
        hideResults();
        return;
      }
      debounceTimer = setTimeout(() => doSearch(q), SEARCH_DEBOUNCE_MS);
    });

    // Keyboard navigation
    _query.addEventListener('keydown', (e: KeyboardEvent) => {
      if (!currentHits.length && e.key !== 'Escape') return;

      switch (e.key) {
        case 'ArrowDown':
          e.preventDefault();
          setActiveIndex(activeIndex < currentHits.length - 1 ? activeIndex + 1 : 0);
          break;
        case 'ArrowUp':
          e.preventDefault();
          setActiveIndex(activeIndex > 0 ? activeIndex - 1 : currentHits.length - 1);
          break;
        case 'Enter':
          e.preventDefault();
          if (activeIndex >= 0 && activeIndex < currentHits.length) {
            const hit = currentHits[activeIndex];
            const title = (hit.title || hit.name || hit.objectID || '') as string;
            const uri = (hit.uri || '') as string;
            selectDocument(
              hit.objectID as string,
              title,
              uri,
              (hit.sectionHandle || '') as string,
              (hit.entryTypeHandle || '') as string,
            );
          }
          break;
        case 'Escape':
          e.preventDefault();
          hideResults();
          break;
      }
    });

    function setActiveIndex(index: number): void {
      activeIndex = index;
      const items = _list.querySelectorAll<HTMLElement>('.sdf-result-item');
      items.forEach((item, i) => {
        item.classList.toggle('sdf-active', i === activeIndex);
      });

      // Update aria-activedescendant
      if (activeIndex >= 0 && items[activeIndex]) {
        _query.setAttribute('aria-activedescendant', items[activeIndex].id);
        items[activeIndex].scrollIntoView({ block: 'nearest' });
      } else {
        _query.removeAttribute('aria-activedescendant');
      }
    }

    function showResults(): void {
      _results.classList.remove('hidden');
      _query.setAttribute('aria-expanded', 'true');
    }

    function hideResults(): void {
      _results.classList.add('hidden');
      _query.setAttribute('aria-expanded', 'false');
      _query.removeAttribute('aria-activedescendant');
      activeIndex = -1;
      currentHits = [];
    }

    function doSearch(query: string): void {
      if (searchAbortController) {
        searchAbortController.abort();
      }
      searchAbortController = new AbortController();

      _search.classList.add('sdf-loading');

      Craft.sendActionRequest<SearchDocumentResponse>('POST', 'search-index/search/search', {
        data: {
          indexHandle: _indexHandle.value,
          query,
          perPage,
        },
        signal: searchAbortController.signal,
      })
        .then((response) => {
          const { data } = response;
          _list.innerHTML = '';
          activeIndex = -1;
          currentHits = [];

          if (!data.success || !data.hits || data.hits.length === 0) {
            _list.innerHTML = `<li class="sdf-no-results"><em>${Craft.escapeHtml(tNoResults)}</em></li>`;
            showResults();
            return;
          }

          currentHits = data.hits;
          const listboxId = _list.id;

          data.hits.forEach((hit, i) => {
            const li = document.createElement('li');
            li.className = 'sdf-result-item';
            li.id = `${listboxId}-option-${i}`;
            li.setAttribute('role', 'option');

            const title = (hit.title || hit.name || hit.objectID || '') as string;
            const uri = (hit.uri || '') as string;
            const section = (hit.sectionHandle || '') as string;
            const entryType = (hit.entryTypeHandle || '') as string;
            const meta = [section, entryType].filter(Boolean).join(' / ');
            li.innerHTML =
              `<strong>${Craft.escapeHtml(title)}</strong>` +
              (meta ? ` <span class="light">— ${Craft.escapeHtml(meta)}</span>` : '') +
              (uri ? `<br><small class="light">${Craft.escapeHtml(uri)}</small>` : '');

            li.addEventListener('click', () => {
              selectDocument(hit.objectID as string, title, uri, section, entryType);
            });

            _list.appendChild(li);
          });

          showResults();
        })
        .catch((err: unknown) => {
          if (err instanceof DOMException && err.name === 'AbortError') return;
          Craft.cp.displayError(tSearchFailed);
        })
        .finally(() => {
          _search.classList.remove('sdf-loading');
        });
    }

    function selectDocument(
      docId: string,
      title: string,
      uri: string,
      section: string = '',
      entryType: string = '',
    ): void {
      _docId.value = docId;
      _sectionHandle.value = section;
      _entryTypeHandle.value = entryType;
      const meta = [section, entryType].filter(Boolean).join(' / ');
      _title.innerHTML =
        Craft.escapeHtml(title) +
        (meta ? ` <span class="light">— ${Craft.escapeHtml(meta)}</span>` : '') +
        (uri ? ` <span class="light">— ${Craft.escapeHtml(uri)}</span>` : '') +
        ` <span class="light">(ID: ${Craft.escapeHtml(docId)})</span>`;
      _selected.classList.remove('hidden');
      _search.classList.add('hidden');
      hideResults();
      _query.value = '';
      _list.innerHTML = '';
    }

    // Clear selection
    clearBtn.addEventListener('click', () => {
      _docId.value = '';
      _sectionHandle.value = '';
      _entryTypeHandle.value = '';
      _selected.classList.add('hidden');
      _search.classList.remove('hidden');
      _title.textContent = '';
      _query.focus();
    });

    // On load with existing value: fetch document details
    if (_docId.value) {
      Craft.sendActionRequest<GetDocumentResponse>('POST', 'search-index/search/get-document', {
        data: {
          indexHandle: _indexHandle.value,
          documentId: _docId.value,
        },
      })
        .then((response) => {
          const { data } = response;
          if (data.success && data.document) {
            const title = (data.document.title || data.document.name || _docId.value) as string;
            const uri = (data.document.uri || '') as string;
            const section = (data.document.sectionHandle || '') as string;
            const entryType = (data.document.entryTypeHandle || '') as string;
            selectDocument(_docId.value, title, uri, section, entryType);
            // Re-show selected (selectDocument already does this)
          } else {
            _title.textContent = tNotFound.replace('{id}', _docId.value);
          }
        })
        .catch(() => {
          _title.textContent = tNotFound.replace('{id}', _docId.value);
        });
    }
  }
})();
