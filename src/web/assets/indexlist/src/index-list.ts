/// <reference path="../../types/craft.d.ts" />

(() => {
  // Handle disclosure menu actions (Sync, Flush, Delete)
  document.querySelectorAll<HTMLButtonElement>('.menu [data-action]').forEach((btn) => {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      const action = this.dataset.action!;
      const id = this.dataset.id!;

      if (action === 'delete' && !confirm('Are you sure you want to delete this index?')) {
        return;
      }
      if (
        action === 'flush' &&
        !confirm('Are you sure you want to flush all documents from this index?')
      ) {
        return;
      }

      Craft.sendActionRequest('POST', `search-index/indexes/${action}`, {
        data: { id },
      })
        .then(() => {
          if (action === 'delete') {
            location.reload();
          } else {
            Craft.cp.displayNotice(action === 'sync' ? 'Sync jobs queued.' : 'Index flushed.');
          }
        })
        .catch(() => {
          Craft.cp.displayError('Action failed.');
        });
    });
  });
})();
