/**
 * com_zabbix administrator pages: "Copy diagnostics" on Find Endpoints.
 *
 * @license GNU General Public License version 2 or later
 */
(() => {
  'use strict';

  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-zbx-copy]');

    if (!button) {
      return;
    }

    const source = document.querySelector(button.dataset.zbxCopy);

    if (!source) {
      return;
    }

    const done = () => {
      const label = button.innerHTML;
      button.textContent = button.dataset.zbxCopied || 'Copied';
      setTimeout(() => { button.innerHTML = label; }, 2000);
    };

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(source.value).then(done, () => { source.select(); });
    } else {
      source.select();
      // eslint-disable-next-line no-restricted-globals
      if (document.execCommand('copy')) {
        done();
      }
    }
  });
})();
