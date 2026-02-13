/// <reference path="../../types/craft.d.ts" />
import './field-mappings.css';

interface FieldResult {
  indexFieldName: string;
  indexFieldType: string;
  entryId: number | null;
  entryTitle: string;
  phpType: string;
  value: unknown;
  status: 'ok' | 'warning' | 'error' | 'null';
  warning?: string;
}

interface ValidationData {
  success: boolean;
  message?: string;
  indexName: string;
  indexHandle: string;
  entryTypeNames?: string[];
  results: FieldResult[];
}

const STATUS_ROW_CLASS: Record<string, string> = {
  error: 'si-row-error',
  warning: 'si-row-warning',
  null: 'si-row-null',
};

(() => {
  const container = document.getElementById('field-mappings-container') as HTMLElement | null;
  if (!container) return;

  // ── Role chip sync + uniqueness ──
  const table = document.getElementById('si-field-mappings');
  if (table) {
    const roleSelects = table.querySelectorAll<HTMLSelectElement>('select.si-role-input');

    roleSelects.forEach((select) => {
      select.addEventListener('change', () => {
        const newRole = select.value;

        // Enforce one-role-per-index: clear any other select with the same role
        if (newRole) {
          roleSelects.forEach((other) => {
            if (other !== select && other.value === newRole) {
              other.value = '';
              syncChipForSelect(other);
            }
          });
        }

        syncChipForSelect(select);
      });
    });
  }

  function syncChipForSelect(select: HTMLSelectElement): void {
    const row = select.closest('tr');
    if (!row) return;
    const chip = row.querySelector<HTMLElement>('.si-role-chip');
    if (!chip) return;
    chip.dataset.role = select.value;
    chip.textContent = select.value;
    if (select.value) {
      chip.setAttribute('aria-label', `Role: ${select.value}`);
    } else {
      chip.removeAttribute('aria-label');
    }
  }

  const indexId = container.dataset.indexId;
  if (!indexId) return;

  const btn = document.getElementById('validate-fields-btn') as HTMLButtonElement | null;
  const copyBtn = document.getElementById('copy-markdown-btn') as HTMLButtonElement | null;
  const copyWarningsBtn = document.getElementById(
    'copy-markdown-warnings-btn',
  ) as HTMLButtonElement | null;
  let lastData: ValidationData | null = null;

  if (!btn || !copyBtn || !copyWarningsBtn) return;

  copyBtn.addEventListener('click', () => {
    if (!lastData) return;
    const md = buildMarkdown(lastData, null, '');
    copyMarkdown(md);
  });

  copyWarningsBtn.addEventListener('click', () => {
    if (!lastData) return;
    const md = buildMarkdown(
      lastData,
      (f: FieldResult) => f.status === 'warning' || f.status === 'null' || f.status === 'error',
      ' (Warnings, Errors & Nulls)',
    );
    copyMarkdown(md);
  });

  btn.addEventListener('click', () => {
    btn.classList.add('loading');
    btn.disabled = true;

    const resultsEl = document.getElementById('validate-results');

    Craft.sendActionRequest('POST', 'search-index/field-mappings/validate', {
      data: { indexId },
    })
      .then((response) => {
        const data = response.data as unknown as ValidationData;
        if (!data.success) {
          Craft.cp.displayError(data.message || 'Validation failed.');
          resultsEl?.classList.add('hidden');
          return;
        }

        lastData = data;
        renderResults(data);
      })
      .catch(() => {
        Craft.cp.displayError('Validation request failed.');
        resultsEl?.classList.add('hidden');
      })
      .finally(() => {
        btn.classList.remove('loading');
        btn.disabled = false;
      });
  });

  function renderResults(data: ValidationData): void {
    const resultsContainer = document.getElementById('validate-results');
    const summary = document.getElementById('validate-summary');
    const content = document.getElementById('validate-entries');
    if (!resultsContainer || !summary || !content) return;

    resultsContainer.classList.remove('hidden');
    content.innerHTML = '';

    let totalOk = 0;
    let totalWarnings = 0;
    let totalErrors = 0;
    let totalNull = 0;
    data.results.forEach((f) => {
      if (f.status === 'ok') totalOk++;
      else if (f.status === 'error') totalErrors++;
      else if (f.status === 'null') totalNull++;
      else totalWarnings++;
    });

    const parts = [`${data.results.length} fields validated.`];
    if (totalOk > 0) parts.push(`${totalOk} OK`);
    if (totalWarnings > 0) parts.push(`${totalWarnings} warning(s)`);
    if (totalErrors > 0) parts.push(`${totalErrors} error(s)`);
    if (totalNull > 0) parts.push(`${totalNull} with no data`);
    summary.textContent = parts.join(' — ');

    const resultTable = document.createElement('table');
    resultTable.className = 'data fullwidth';

    const thead = document.createElement('thead');
    thead.innerHTML =
      '<tr><th>Index Field</th><th>Index Type</th><th>Source Entry</th><th>PHP Type</th><th>Value</th><th>Status</th></tr>';
    resultTable.appendChild(thead);

    const tbody = document.createElement('tbody');
    data.results.forEach((field) => {
      const tr = document.createElement('tr');

      const rowClass = STATUS_ROW_CLASS[field.status];
      if (rowClass) {
        tr.className = rowClass;
      }

      // Index Field
      const tdName = document.createElement('td');
      const code = document.createElement('code');
      code.textContent = field.indexFieldName;
      tdName.appendChild(code);
      tr.appendChild(tdName);

      // Index Type
      const tdType = document.createElement('td');
      tdType.textContent = field.indexFieldType;
      tr.appendChild(tdType);

      // Source Entry
      const tdEntry = document.createElement('td');
      if (field.entryId) {
        tdEntry.textContent = `${field.entryTitle} `;
        const idSpan = document.createElement('span');
        idSpan.className = 'light';
        idSpan.textContent = `#${field.entryId}`;
        tdEntry.appendChild(idSpan);
      } else {
        const noData = document.createElement('span');
        noData.className = 'light';
        noData.textContent = 'no data found';
        tdEntry.appendChild(noData);
      }
      tr.appendChild(tdEntry);

      // PHP Type
      const tdPhp = document.createElement('td');
      const phpCode = document.createElement('code');
      phpCode.textContent = field.phpType;
      phpCode.className = 'light';
      tdPhp.appendChild(phpCode);
      tr.appendChild(tdPhp);

      // Value
      const tdValue = document.createElement('td');
      tdValue.className = 'si-value-cell';
      if (field.value === null) {
        const nullSpan = document.createElement('span');
        nullSpan.className = 'light';
        nullSpan.textContent = 'null';
        tdValue.appendChild(nullSpan);
      } else if (typeof field.value === 'object') {
        const pre = document.createElement('code');
        pre.textContent = JSON.stringify(field.value);
        pre.title = JSON.stringify(field.value, null, 2);
        tdValue.appendChild(pre);
      } else {
        tdValue.textContent = String(field.value);
        tdValue.title = String(field.value);
      }
      tr.appendChild(tdValue);

      // Status
      const tdStatus = document.createElement('td');
      if (field.status === 'ok') {
        tdStatus.innerHTML = '<span class="status green"></span>';
      } else if (field.status === 'error') {
        tdStatus.innerHTML = '<span class="status red"></span>';
        appendWarning(tdStatus, field.warning);
      } else if (field.status === 'null') {
        tdStatus.innerHTML = '<span class="status"></span>';
        appendWarning(tdStatus, field.warning);
      } else {
        tdStatus.innerHTML = '<span class="status orange"></span>';
        appendWarning(tdStatus, field.warning);
      }
      tr.appendChild(tdStatus);

      tbody.appendChild(tr);
    });

    resultTable.appendChild(tbody);
    content.appendChild(resultTable);

    resultsContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function appendWarning(td: HTMLElement, warning?: string): void {
    if (!warning) return;
    const span = document.createElement('span');
    span.className = 'light small';
    span.textContent = ` ${warning}`;
    td.appendChild(span);
  }

  function copyMarkdown(md: string): void {
    navigator.clipboard.writeText(md).then(
      () => {
        Craft.cp.displayNotice('Copied to clipboard.');
      },
      () => {
        // Fallback for browsers without clipboard API
        const ta = document.createElement('textarea');
        ta.value = md;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        const success = document.execCommand('copy');
        document.body.removeChild(ta);
        if (success) {
          Craft.cp.displayNotice('Copied to clipboard.');
        } else {
          Craft.cp.displayError('Failed to copy to clipboard.');
        }
      },
    );
  }

  function buildMarkdown(
    data: ValidationData,
    filterFn: ((f: FieldResult) => boolean) | null,
    titleSuffix: string,
  ): string {
    const lines: string[] = [];
    lines.push(
      `# Field Mapping Validation: ${data.indexName} (\`${data.indexHandle}\`)${titleSuffix}`,
    );
    lines.push('');
    if (data.entryTypeNames?.length) {
      lines.push(`**Entry types:** ${data.entryTypeNames.join(', ')}`);
    }
    lines.push('');
    lines.push('| Index Field | Index Type | Source Entry | PHP Type | Value | Status |');
    lines.push('|---|---|---|---|---|---|');

    data.results.forEach((f) => {
      if (filterFn && !filterFn(f)) return;
      let entry = f.entryId ? `${f.entryTitle} (#${f.entryId})` : '_no data_';
      let val =
        f.value === null
          ? '_null_'
          : typeof f.value === 'object'
            ? `\`${JSON.stringify(f.value)}\``
            : String(f.value).length > 60
              ? `${String(f.value).substring(0, 60)}...`
              : String(f.value);
      val = val.replace(/\|/g, '\\|');
      entry = entry.replace(/\|/g, '\\|');
      const statusIcon =
        f.status === 'ok'
          ? 'OK'
          : f.status === 'error'
            ? 'ERROR'
            : f.status === 'null'
              ? '--'
              : 'WARN';
      let statusText = statusIcon;
      if (f.warning) {
        statusText += ` ${f.warning.replace(/\|/g, '\\|')}`;
      }
      lines.push(
        `| \`${f.indexFieldName}\` | ${f.indexFieldType} | ${entry} | \`${f.phpType}\` | ${val} | ${statusText} |`,
      );
    });

    lines.push('');
    return lines.join('\n');
  }
})();
