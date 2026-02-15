/// <reference path="../../types/craft.d.ts" />

(() => {
  const table = document.querySelector<HTMLTableElement>('table.data.fullwidth');
  const t = {
    confirmDelete: table?.dataset.tConfirmDelete ?? 'Are you sure you want to delete this index?',
    confirmFlush:
      table?.dataset.tConfirmFlush ??
      'Are you sure you want to flush all documents from this index?',
    syncNotice: table?.dataset.tSyncNotice ?? 'Sync jobs queued.',
    flushNotice: table?.dataset.tFlushNotice ?? 'Index flushed.',
    actionError: table?.dataset.tActionError ?? 'Action failed.',
  };

  // Handle disclosure menu actions (Sync, Flush, Delete)
  document.querySelectorAll<HTMLButtonElement>('.menu [data-action]').forEach((btn) => {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      const action = this.dataset.action;
      const id = this.dataset.id;
      if (!action || !id) return;

      if (action === 'delete' && !confirm(t.confirmDelete)) {
        return;
      }
      if (action === 'flush' && !confirm(t.confirmFlush)) {
        return;
      }

      Craft.sendActionRequest('POST', `search-index/indexes/${action}`, {
        data: { id },
      })
        .then((response: { data?: { success?: boolean; message?: string } }) => {
          if (response.data?.success === false) {
            Craft.cp.displayError(response.data.message || t.actionError);
            return;
          }

          if (action === 'delete') {
            location.reload();
          } else {
            Craft.cp.displayNotice(action === 'sync' ? t.syncNotice : t.flushNotice);
          }
        })
        .catch(() => {
          Craft.cp.displayError(t.actionError);
        });
    });
  });
})();
