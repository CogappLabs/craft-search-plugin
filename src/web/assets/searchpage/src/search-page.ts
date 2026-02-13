/// <reference path="../../types/craft.d.ts" />

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
  const singleMode = document.getElementById('single-mode') as HTMLElement;
  const compareMode = document.getElementById('compare-mode') as HTMLElement;

  modeButtons.forEach((btn) => {
    btn.addEventListener('click', function () {
      for (const b of modeButtons) b.classList.remove('active');
      this.classList.add('active');
      if (this.dataset.mode === 'single') {
        singleMode.style.display = '';
        compareMode.style.display = 'none';
      } else {
        singleMode.style.display = 'none';
        compareMode.style.display = '';
      }
    });
  });

  // Single mode search
  const singleBtn = document.getElementById('single-search-btn') as HTMLButtonElement;
  singleBtn.addEventListener('click', () => {
    const indexHandle = (document.getElementById('single-index') as HTMLSelectElement).value;
    const query = (document.getElementById('single-query') as HTMLInputElement).value.trim();
    const perPage =
      parseInt((document.getElementById('single-perpage') as HTMLInputElement).value, 10) || 20;

    if (!query) {
      Craft.cp.displayError('Please enter a search query.');
      return;
    }

    singleBtn.classList.add('loading');

    Craft.sendActionRequest('POST', 'search-index/search/search', {
      data: { indexHandle, query, perPage },
    })
      .then((response) => {
        singleBtn.classList.remove('loading');
        renderSingleResults(response.data as unknown as SearchResponse);
      })
      .catch(() => {
        singleBtn.classList.remove('loading');
        Craft.cp.displayError('Search failed.');
      });
  });

  // Allow Enter key on single query
  (document.getElementById('single-query') as HTMLInputElement).addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      singleBtn.click();
    }
  });

  function renderSingleResults(data: SearchResponse): void {
    const container = document.getElementById('single-results') as HTMLElement;

    if (!data.success) {
      container.innerHTML = `<p class="error">${Craft.escapeHtml(data.message || 'Search failed.')}</p>`;
      return;
    }

    if (!data.hits || data.hits.length === 0) {
      container.innerHTML = '<p class="zilch">No results found.</p>';
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
        `<tr class="raw-row" data-index="${i}" style="display:none">` +
        `<td colspan="5"><pre class="code" style="max-height:200px;overflow:auto;background:var(--gray-050);padding:8px;border-radius:4px">${Craft.escapeHtml(JSON.stringify(hit, null, 2))}</pre></td></tr>`;
    });

    html += '</tbody></table>';

    if (data.raw) {
      html +=
        '<details class="mt-s">' +
        '<summary>Raw engine response</summary>' +
        `<pre class="code" style="max-height:260px;overflow:auto;background:var(--gray-050);padding:8px;border-radius:4px">${Craft.escapeHtml(JSON.stringify(data.raw, null, 2))}</pre></details>`;
    }
    container.innerHTML = html;

    // Toggle raw JSON rows
    container.querySelectorAll<HTMLButtonElement>('.toggle-raw').forEach((btn) => {
      btn.addEventListener('click', function () {
        const idx = this.dataset.index!;
        const row = container.querySelector<HTMLElement>(`.raw-row[data-index="${idx}"]`);
        if (row) {
          row.style.display = row.style.display === 'none' ? '' : 'none';
        }
      });
    });
  }

  // Compare mode search
  const compareBtn = document.getElementById('compare-search-btn') as HTMLButtonElement;
  compareBtn.addEventListener('click', () => {
    // checkboxSelectField renders checkboxes inside #compare-indexes
    const checkboxes = document.querySelectorAll<HTMLInputElement>(
      '#compare-indexes input[type="checkbox"]:checked',
    );
    const query = (document.getElementById('compare-query') as HTMLInputElement).value.trim();
    const perPage =
      parseInt((document.getElementById('compare-perpage') as HTMLInputElement).value, 10) || 20;

    if (checkboxes.length === 0) {
      Craft.cp.displayError('Select at least one index to compare.');
      return;
    }
    if (!query) {
      Craft.cp.displayError('Please enter a search query.');
      return;
    }

    compareBtn.classList.add('loading');
    const resultsContainer = document.getElementById('compare-results') as HTMLElement;
    resultsContainer.innerHTML = '';

    let pending = checkboxes.length;

    checkboxes.forEach((cb) => {
      const indexHandle = cb.value;
      const panel = document.createElement('div');
      panel.style.cssText = 'flex:1;min-width:300px;max-width:50%';
      panel.innerHTML = `<h3>${Craft.escapeHtml(indexHandle)}</h3><p class="spinner"></p>`;
      resultsContainer.appendChild(panel);

      Craft.sendActionRequest('POST', 'search-index/search/search', {
        data: { indexHandle, query, perPage },
      })
        .then((response) => {
          renderComparePanel(panel, indexHandle, response.data as unknown as SearchResponse);
          pending--;
          if (pending === 0) compareBtn.classList.remove('loading');
        })
        .catch(() => {
          panel.innerHTML = `<h3>${Craft.escapeHtml(indexHandle)}</h3><p class="error">Search failed.</p>`;
          pending--;
          if (pending === 0) compareBtn.classList.remove('loading');
        });
    });
  });

  // Allow Enter key on compare query
  (document.getElementById('compare-query') as HTMLInputElement).addEventListener(
    'keydown',
    (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        compareBtn.click();
      }
    },
  );

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
        `<pre class="code" style="max-height:200px;overflow:auto;background:var(--gray-050);padding:8px;border-radius:4px">${Craft.escapeHtml(JSON.stringify(data.raw, null, 2))}</pre></details>`;
    }

    panel.innerHTML = html;
  }
})();
