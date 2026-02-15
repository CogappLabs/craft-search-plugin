/// <reference path="../../types/craft.d.ts" />

(() => {
  const form = document.getElementById('search-index-edit-form') as HTMLFormElement | null;
  if (!form) return;

  const isNew = form.dataset.isNew === 'true';

  // Toggle engine config fields and disable hidden inputs so they don't submit
  const engineSelect = document.getElementById('engineType') as HTMLSelectElement | null;

  function syncEngineConfigFields(selectedEngine: string): void {
    document.querySelectorAll<HTMLElement>('.engine-config-fields').forEach((el) => {
      const isSelected = el.dataset.engine === selectedEngine;
      el.classList.toggle('hidden', !isSelected);
      el.querySelectorAll<HTMLInputElement>('input').forEach((input) => {
        input.disabled = !isSelected;
      });
    });
  }

  if (engineSelect) {
    // Disable hidden engine inputs on initial load
    syncEngineConfigFields(engineSelect.value);

    engineSelect.addEventListener('change', function () {
      syncEngineConfigFields(this.value);
      const selected = document.querySelector<HTMLElement>(
        `.engine-config-fields[data-engine="${CSS.escape(this.value)}"]`,
      );
      if (selected) {
        Craft.initUiElements(selected);
      }
    });
  }

  // Handle name -> handle auto-generation
  const nameInput = document.getElementById('name') as HTMLInputElement | null;
  const handleInput = document.getElementById('handle') as HTMLInputElement | null;
  let handleManuallySet = !isNew;

  if (nameInput && handleInput) {
    nameInput.addEventListener('input', function () {
      if (!handleManuallySet) {
        handleInput.value = this.value
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, '_')
          .replace(/^_|_$/g, '');
      }
    });

    handleInput.addEventListener('input', () => {
      handleManuallySet = true;
    });
  }

  // Test Connection button
  const testBtn = document.getElementById('test-connection-btn') as HTMLButtonElement | null;
  const testConnectionContainer = document.getElementById('test-connection');
  const testT = {
    selectEngine:
      testConnectionContainer?.dataset.tSelectEngine || 'Please select an engine first.',
    timeout: testConnectionContainer?.dataset.tTimeout || 'Connection timed out after 5 seconds.',
    requestFailed: testConnectionContainer?.dataset.tRequestFailed || 'Request failed.',
  };

  if (testBtn && engineSelect) {
    testBtn.addEventListener('click', function () {
      const resultEl = document.getElementById('test-connection-result');
      if (!resultEl) return;
      this.classList.add('loading');
      resultEl.textContent = '';
      resultEl.className = 'ml-s';

      const engineType = engineSelect.value;
      if (!engineType) {
        this.classList.remove('loading');
        resultEl.textContent = testT.selectEngine;
        resultEl.classList.add('error');
        return;
      }

      const configInputs = document.querySelectorAll<HTMLInputElement>(
        `.engine-config-fields[data-engine="${CSS.escape(engineType)}"] input`,
      );
      const engineConfig: Record<string, string> = {};
      configInputs.forEach((input) => {
        const match = input.name.match(/engineConfig\[(.+)\]/);
        if (match) engineConfig[match[1]] = input.value;
      });

      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 5000);

      Craft.sendActionRequest<{ success: boolean; message: string }>(
        'POST',
        'search-index/indexes/test-connection',
        {
          data: { engineType, engineConfig },
          signal: controller.signal,
        },
      )
        .then((response) => {
          clearTimeout(timeout);
          testBtn.classList.remove('loading');
          resultEl.textContent = response.data.message;
          if (response.data.success) {
            resultEl.classList.add('success');
          } else {
            resultEl.classList.add('error');
          }
        })
        .catch(() => {
          clearTimeout(timeout);
          testBtn.classList.remove('loading');
          resultEl.textContent = controller.signal.aborted ? testT.timeout : testT.requestFailed;
          resultEl.classList.add('error');
        });
    });
  }

  // Toggle entry type checkboxes based on selected sections
  document
    .querySelectorAll<HTMLInputElement>('#sectionIds input[type="checkbox"]')
    .forEach((cb) => {
      cb.addEventListener('change', function () {
        document
          .querySelectorAll<HTMLElement>(`.entry-type-checkbox[data-section="${this.value}"]`)
          .forEach((el) => {
            el.classList.toggle('hidden', !cb.checked);
          });
      });
    });

  // Toggle sources section based on mode
  const modeSelect = document.getElementById('mode') as HTMLSelectElement | null;
  const sourcesSection = document.getElementById('synced-sources') as HTMLElement | null;

  if (modeSelect && sourcesSection) {
    function toggleSources(): void {
      sourcesSection!.classList.toggle('hidden', modeSelect!.value === 'readonly');
    }

    modeSelect.addEventListener('change', toggleSources);
    toggleSources();
  }
})();
