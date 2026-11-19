/**
 * com_zabbix portal, host details: filter the latest data and draw an item's chart
 * (task=data.history).
 *
 * @license GNU General Public License version 2 or later
 */
(() => {
  'use strict';

  const P = window.ZabbixPortal;
  const C = window.ZabbixCharts;

  const start = () => {
    const configNode = document.querySelector('[data-zbx-config]');

    if (!configNode) {
      return;
    }

    const cfg = JSON.parse(configNode.textContent);
    const fmt = P.makeFormatter(cfg.format);
    const box = document.querySelector('[data-zbx-host-chart]');
    const body = box?.querySelector('[data-zbx-chart-body]');
    const title = box?.querySelector('[data-zbx-chart-title]');
    const period = box?.querySelector('[data-zbx-host-period]');
    let itemId = null;

    const draw = async () => {
      if (!itemId || !body) {
        return;
      }

      body.replaceChildren(P.el('p', { class: 'text-body-secondary mb-0', text: P.t('COM_ZABBIX_JS_LOADING', 'Loading…') }));

      try {
        const url = `${cfg.endpoint}&host=${encodeURIComponent(cfg.host)}&item=${encodeURIComponent(itemId)}&period=${encodeURIComponent(period.value)}`;
        const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': cfg.token } });
        const json = await res.json();

        if (!res.ok || json.error) {
          throw new Error(json.error || 'error');
        }

        C.line(body, json.data.series, { formatter: fmt });
      } catch (e) {
        body.replaceChildren(P.el('p', { class: 'text-danger mb-0', role: 'alert', text: P.t('COM_ZABBIX_JS_ERROR', 'The data could not be loaded.') }));
      }
    };

    document.addEventListener('click', (event) => {
      const button = event.target.closest('[data-zbx-graph]');

      if (button && box) {
        itemId = button.dataset.zbxGraph;
        title.textContent = button.dataset.zbxGraphTitle || '';
        box.hidden = false;
        box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        draw();
      }

      if (event.target.closest('[data-zbx-chart-close]') && box) {
        box.hidden = true;
        itemId = null;
      }
    });

    period?.addEventListener('change', draw);

    const search = document.querySelector('[data-zbx-item-search]');

    search?.addEventListener('input', () => {
      const q = search.value.trim().toLowerCase();
      document.querySelectorAll('[data-zbx-item-row]').forEach((row) => {
        row.hidden = q !== '' && !row.dataset.name.includes(q);
      });
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
