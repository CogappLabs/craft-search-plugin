/// <reference path="../../types/craft.d.ts" />
import './search-page.css';

interface SearchHit {
  objectID?: string;
  title?: string;
  name?: string;
  uri?: string;
  _score?: number | null;
  [key: string]: unknown;
}

interface SearchResponse {
  success: boolean;
  message?: string;
  hits?: SearchHit[];
  totalHits?: number;
  page?: number;
  totalPages?: number;
  perPage?: number;
  processingTimeMs?: number;
  raw?: Record<string, unknown>;
}

(() => {
  // Mode toggle
  const modeButtons = document.querySelectorAll<HTMLButtonElement>('#search-page .btngroup .btn');
  const singleMode = document.getElementById('single-mode');
  const compareMode = document.getElementById('compare-mode');

  if (singleMode && compareMode) {
    modeButtons.forEach((btn) => {
      btn.addEventListener('click', function () {
        for (const b of modeButtons) b.classList.remove('active');
        this.classList.add('active');
        if (this.dataset.mode === 'single') {
          singleMode.classList.remove('hidden');
          compareMode.classList.add('hidden');
        } else {
          singleMode.classList.add('hidden');
          compareMode.classList.remove('hidden');
        }
      });
    });
  }

  // Single mode search
  const singleBtn = document.getElementById('single-search-btn') as HTMLButtonElement | null;
  const singleIndexSelect = document.getElementById('single-index') as HTMLSelectElement | null;
  const singleQueryInput = document.getElementById('single-query') as HTMLInputElement | null;
  const singlePerPageInput = document.getElementById('single-perpage') as HTMLInputElement | null;
  const singleResultsContainer = document.getElementById('single-results');

  if (singleBtn && singleIndexSelect && singleQueryInput && singlePerPageInput) {
    singleBtn.addEventListener('click', () => {
      const indexHandle = singleIndexSelect.value;
      const query = singleQueryInput.value.trim();
      const perPage = parseInt(singlePerPageInput.value, 10) || 20;

      if (!query) {
        Craft.cp.displayError('Please enter a search query.');
        return;
      }

      singleBtn.classList.add('loading');

      Craft.sendActionRequest<SearchResponse>('POST', 'search-index/search/search', {
        data: { indexHandle, query, perPage },
      })
        .then((response) => {
          singleBtn.classList.remove('loading');
          renderSingleResults(response.data);
        })
        .catch(() => {
          singleBtn.classList.remove('loading');
          Craft.cp.displayError('Search failed.');
        });
    });

    // Allow Enter key on single query
    singleQueryInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        singleBtn.click();
      }
    });
  }

  function renderSingleResults(data: SearchResponse): void {
    if (!singleResultsContainer) return;

    if (!data.success) {
      singleResultsContainer.innerHTML = `<p class="error">${Craft.escapeHtml(data.message || 'Search failed.')}</p>`;
      return;
    }

    if (!data.hits || data.hits.length === 0) {
      singleResultsContainer.innerHTML = '<p class="zilch">No results found.</p>';
      return;
    }

    let html =
      `<p class="light mb-s">` +
      `${data.totalHits} results in ${data.processingTimeMs}ms ` +
      `(page ${data.page} of ${data.totalPages})</p>`;

    html +=
      '<table class="data fullwidth"><thead><tr>' +
      '<th>Title</th><th>URI</th><th>Score</th><th>Object ID</th><th></th>' +
      '</tr></thead><tbody>';

    data.hits.forEach((hit, i) => {
      const title = hit.title || hit.name || '-';
      const uri = hit.uri || '-';
      const score = hit._score !== null && hit._score !== undefined ? String(hit._score) : '-';
      const objectID = hit.objectID || '-';

      html +=
        '<tr>' +
        `<td>${Craft.escapeHtml(title)}</td>` +
        `<td>${Craft.escapeHtml(uri)}</td>` +
        `<td>${Craft.escapeHtml(score)}</td>` +
        `<td><code>${Craft.escapeHtml(objectID)}</code></td>` +
        `<td class="thin"><button type="button" class="btn small toggle-raw" data-index="${i}">JSON</button></td>` +
        '</tr>' +
        `<tr class="raw-row hidden" data-index="${i}">` +
        `<td colspan="5"><pre class="code si-code-block si-raw-json">${Craft.escapeHtml(JSON.stringify(hit, null, 2))}</pre></td></tr>`;
    });

    html += '</tbody></table>';

    if (data.raw) {
      html +=
        '<details class="mt-s">' +
        '<summary>Raw engine response</summary>' +
        `<pre class="code si-code-block si-raw-response">${Craft.escapeHtml(JSON.stringify(data.raw, null, 2))}</pre></details>`;
    }
    singleResultsContainer.innerHTML = html;

    // Toggle raw JSON rows
    singleResultsContainer.querySelectorAll<HTMLButtonElement>('.toggle-raw').forEach((btn) => {
      btn.addEventListener('click', function () {
        const idx = this.dataset.index;
        if (!idx) return;
        const row = singleResultsContainer.querySelector<HTMLElement>(
          `.raw-row[data-index="${idx}"]`,
        );
        if (row) {
          row.classList.toggle('hidden');
        }
      });
    });
  }

  // Compare mode search
  const compareBtn = document.getElementById('compare-search-btn') as HTMLButtonElement | null;
  const compareQueryInput = document.getElementById('compare-query') as HTMLInputElement | null;
  const comparePerPageInput = document.getElementById('compare-perpage') as HTMLInputElement | null;
  const compareResultsContainer = document.getElementById('compare-results');

  if (compareBtn && compareQueryInput && comparePerPageInput) {
    compareBtn.addEventListener('click', () => {
      const checkboxes = document.querySelectorAll<HTMLInputElement>(
        '#compare-indexes input[type="checkbox"]:checked',
      );
      const query = compareQueryInput.value.trim();
      const perPage = parseInt(comparePerPageInput.value, 10) || 20;

      if (checkboxes.length === 0) {
        Craft.cp.displayError('Select at least one index to compare.');
        return;
      }
      if (!query) {
        Craft.cp.displayError('Please enter a search query.');
        return;
      }
      if (!compareResultsContainer) return;

      compareBtn.classList.add('loading');
      compareResultsContainer.innerHTML = '';

      const panels: { handle: string; panel: HTMLElement }[] = [];

      checkboxes.forEach((cb) => {
        const indexHandle = cb.value;
        const panel = document.createElement('div');
        panel.className = 'si-compare-panel';
        panel.innerHTML = `<h3>${Craft.escapeHtml(indexHandle)}</h3><p class="spinner"></p>`;
        compareResultsContainer.appendChild(panel);
        panels.push({ handle: indexHandle, panel });
      });

      const searchPromises = panels.map(({ handle, panel }) =>
        Craft.sendActionRequest<SearchResponse>('POST', 'search-index/search/search', {
          data: { indexHandle: handle, query, perPage },
        })
          .then((response) => {
            renderComparePanel(panel, handle, response.data);
          })
          .catch(() => {
            panel.innerHTML = `<h3>${Craft.escapeHtml(handle)}</h3><p class="error">Search failed.</p>`;
          }),
      );

      Promise.allSettled(searchPromises).then(() => {
        compareBtn.classList.remove('loading');
      });
    });

    // Allow Enter key on compare query
    compareQueryInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        compareBtn.click();
      }
    });
  }

  function renderComparePanel(panel: HTMLElement, indexHandle: string, data: SearchResponse): void {
    if (!data.success) {
      panel.innerHTML = `<h3>${Craft.escapeHtml(indexHandle)}</h3><p class="error">${Craft.escapeHtml(data.message || 'Failed.')}</p>`;
      return;
    }

    let html =
      `<h3>${Craft.escapeHtml(indexHandle)}</h3>` +
      `<p class="light mb-xs">${data.totalHits} results in ${data.processingTimeMs}ms</p>`;

    if (!data.hits || data.hits.length === 0) {
      html += '<p class="zilch">No results.</p>';
    } else {
      html +=
        '<table class="data fullwidth"><thead><tr>' +
        '<th>Title</th><th>Score</th><th>ID</th>' +
        '</tr></thead><tbody>';

      data.hits.forEach((hit) => {
        const title = hit.title || hit.name || '-';
        const score = hit._score !== null && hit._score !== undefined ? String(hit._score) : '-';
        html +=
          `<tr><td>${Craft.escapeHtml(title)}</td>` +
          `<td>${Craft.escapeHtml(score)}</td>` +
          `<td><code>${Craft.escapeHtml(hit.objectID || '-')}</code></td></tr>`;
      });

      html += '</tbody></table>';
    }

    if (data.raw) {
      html +=
        '<details class="mt-xs">' +
        '<summary>Raw engine response</summary>' +
        `<pre class="code si-code-block si-raw-json">${Craft.escapeHtml(JSON.stringify(data.raw, null, 2))}</pre></details>`;
    }

    panel.innerHTML = html;
  }
})();
