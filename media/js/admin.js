/**
 * com_zabbix administrator pages: "Download report" on Find Endpoints saves the diagnostics report
 * shown on the page as a text file. It is made in the browser from the page; nothing is sent.
 *
 * @license GNU General Public License version 2 or later
 */
(() => {
  'use strict';

  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-zbx-download]');

    if (!button) {
      return;
    }

    const source = document.querySelector(button.dataset.zbxDownload);

    if (!source) {
      return;
    }

    // Windows line endings, so the file reads well in Notepad too.
    const text = source.value.replace(/\r?\n/g, '\r\n');
    const url = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }));
    const link = document.createElement('a');

    link.href = url;
    link.download = button.dataset.zbxFilename || 'zabbix-diagnostics.txt';
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  });
})();
