/**
 * com_zabbix portal: draws a Zabbix dashboard on Zabbix's own grid (72 columns, 70 px rows) and
 * loads each widget's data from the portal (task=data.widget), which limits it to what the
 * logged-in user may see. Widgets the portal does not draw show an "Open in Zabbix" placeholder.
 *
 * @license GNU General Public License version 2 or later
 */
(() => {
  'use strict';

  const P = window.ZabbixPortal;
  const C = window.ZabbixCharts;
  const { el, t } = P;

  const start = () => {
    const configNode = document.querySelector('[data-zbx-config]');
    const mount = document.querySelector('[data-zbx-dashboard]');

    if (!configNode || !mount) {
      return;
    }

    const cfg = JSON.parse(configNode.textContent);
    const fmt = P.makeFormatter(cfg.format);
    const periodSelect = document.querySelector('[data-zbx-period]');
    const updated = document.querySelector('[data-zbx-updated]');
    const pages = [];
    let current = 0;
    let timer = null;

    const hostLink = (hostid, name) => (cfg.hostUrl && hostid
      ? el('a', { href: cfg.hostUrl.replace('__ID__', encodeURIComponent(hostid)), text: name })
      : document.createTextNode(name));

    const zabbixLink = () => (cfg.zabbix && cfg.dashboard
      ? el('a', { href: `${cfg.zabbix}/zabbix.php?action=dashboard.view&dashboardid=${encodeURIComponent(cfg.dashboard)}`, target: '_blank', rel: 'noopener noreferrer', text: t('COM_ZABBIX_JS_OPEN_IN_ZABBIX', 'Open in Zabbix') })
      : null);

    // Bootstrap colours of availability states (badges and backgrounds of the site template).
    const AVAIL_BADGE = { available: 'text-bg-success', unavailable: 'text-bg-danger', unknown: 'text-bg-secondary', mixed: 'text-bg-warning' };
    const AVAIL_DOT = { available: 'bg-success', unavailable: 'bg-danger', unknown: 'bg-secondary', mixed: 'bg-warning', none: 'bg-secondary' };
    const AVAIL_BG = { available: 'bg-success-subtle', unavailable: 'bg-danger-subtle', unknown: 'bg-body-tertiary', mixed: 'bg-warning-subtle', none: 'bg-body-tertiary' };

    const sevBadge = (s) => el('span', { class: `zbx-sev zbx-sev-${s}`, text: P.severityName(s) });

    const table = (head, rows) => el('div', { class: 'table-responsive' }, el('table', { class: 'table table-sm table-hover align-middle mb-0' }, [
      el('thead', {}, el('tr', {}, head.map((h) => el('th', { scope: 'col', text: h })))),
      el('tbody', {}, rows.map((cells) => el('tr', {}, cells.map((c) => el('td', {}, c))))),
    ]));

    const empty = () => el('p', { class: 'text-body-secondary mb-0', text: t('COM_ZABBIX_JS_NO_DATA', 'No data') });

    /* ---- Renderers, one per "kind" sent by WidgetDataService ---- */
    const render = {
      problems(body, d) {
        if (!d.rows.length) { body.append(el('p', { class: 'text-success mb-0', text: t('COM_ZABBIX_JS_NONE', 'No problems') })); return; }
        body.append(table(
          [t('COM_ZABBIX_JS_TIME', 'Time'), t('COM_ZABBIX_SEVERITY', 'Severity'), t('COM_ZABBIX_JS_HOST', 'Host'), t('COM_ZABBIX_JS_PROBLEM', 'Problem'), t('COM_ZABBIX_JS_DURATION', 'Duration'), t('COM_ZABBIX_JS_ACK', 'Ack')],
          d.rows.map((r) => [
            el('span', { class: 'text-nowrap', text: fmt.dateTime(r.clock) }),
            sevBadge(r.severity),
            el('span', {}, r.hosts.flatMap((h, i) => (i ? [', ', hostLink(h.hostid, h.name)] : [hostLink(h.hostid, h.name)]))),
            r.name,
            el('span', { class: 'text-nowrap', text: P.duration(r.duration) }),
            r.acknowledged ? t('COM_ZABBIX_JS_YES', 'Yes') : t('COM_ZABBIX_JS_NO', 'No'),
          ]),
        ));
        if (d.total > d.rows.length) { body.append(el('p', { class: 'small text-body-secondary', text: `${t('COM_ZABBIX_JS_MORE', 'More')}: ${d.total - d.rows.length}` })); }
      },

      severity(body, d) {
        const sevs = d.severities.slice().sort((a, b) => b - a);
        body.append(el('div', { class: 'zbx-sevboxes' }, sevs.map((s) => el('div', { class: `zbx-sevbox zbx-sev-${s}${d.totals[s] ? '' : ' is-zero'}` }, [
          el('span', { class: 'zbx-sevbox__n', text: d.totals[s] }), el('span', { class: 'zbx-sevbox__l', text: P.severityName(s) }),
        ]))));
        if (d.showGroups && d.groups.length) {
          body.append(table([t('COM_ZABBIX_JS_HOSTGROUP', 'Host group'), ...sevs.map((s) => P.severityName(s))], d.groups.map((g) => [
            g.name, ...sevs.map((s) => (g.counts[s] ? el('span', { class: `zbx-sev zbx-sev-${s}`, text: g.counts[s] }) : '')),
          ])));
        }
      },

      availability(body, d) {
        const label = { available: 'COM_ZABBIX_JS_AVAILABLE', unavailable: 'COM_ZABBIX_JS_UNAVAILABLE', unknown: 'COM_ZABBIX_JS_UNKNOWN', mixed: 'COM_ZABBIX_JS_MIXED' };
        const states = ['available', 'unavailable', 'unknown'];
        if (d.onlyTotals || Object.keys(d.types).length === 0) {
          body.append(el('div', { class: 'zbx-sevboxes' }, [...states, 'mixed'].map((s) => el('div', { class: `zbx-sevbox ${AVAIL_BG[s]}` }, [
            el('span', { class: 'zbx-sevbox__n', text: d.totals[s] || 0 }), el('span', { class: 'zbx-sevbox__l', text: t(label[s], s) }),
          ]))));
          return;
        }
        body.append(table(['', ...states.map((s) => t(label[s], s)), t('COM_ZABBIX_JS_TOTAL', 'Total')], Object.entries(d.types).map(([type, c]) => [
          t(`COM_ZABBIX_JS_${type.toUpperCase()}`, type),
          ...states.map((s) => el('span', { class: c[s] ? `badge ${AVAIL_BADGE[s]}` : 'text-body-secondary', text: c[s] })),
          String(c.total),
        ])));
      },

      value(body, d) {
        const arrow = d.trend > 0 ? '▲' : (d.trend < 0 ? '▼' : '');
        body.append(el('div', { class: 'zbx-bigvalue' }, [
          el('div', { class: 'zbx-bigvalue__v' }, [d.value || '—', arrow ? el('span', { class: 'fs-6 text-body-secondary', 'aria-hidden': 'true', text: ` ${arrow}` }) : null]),
          el('div', { class: 'small text-body-secondary', text: `${d.host ? `${d.host}: ` : ''}${d.name}` }),
          d.clock ? el('div', { class: 'small text-body-secondary', text: fmt.dateTime(d.clock) }) : null,
        ]));
      },

      gauge(body, d) {
        const box = el('div', { class: 'zbx-gauge' });
        body.append(box, el('div', { class: 'small text-body-secondary text-center', text: `${d.host ? `${d.host}: ` : ''}${d.name}` }));
        C.gauge(box, d);
      },

      history(body, d) {
        if (!d.rows.length) { body.append(empty()); return; }
        body.append(table([t('COM_ZABBIX_JS_TIME', 'Time'), t('COM_ZABBIX_JS_HOST', 'Host'), t('COM_ZABBIX_JS_ITEM', 'Item'), t('COM_ZABBIX_JS_VALUE', 'Value')],
          d.rows.map((r) => [el('span', { class: 'text-nowrap', text: fmt.dateTime(r.clock) }), r.host, r.name, r.value])));
      },

      chart(body, d) {
        const box = el('div', { class: 'zbx-chart' });
        body.append(box);
        C.line(box, d.series, { formatter: fmt });
      },

      pie(body, d) { C.pie(body, d.slices, d.doughnut); },

      table(body, d) {
        if (!d.rows.length) { body.append(empty()); return; }
        body.append(table(d.columns, d.rows.map((cells) => cells.map((c) => (c.hostid ? hostLink(c.hostid, c.display) : c.display)))));
      },

      hosts(body, d) {
        if (!d.rows.length) { body.append(empty()); return; }
        body.append(el('ul', { class: 'list-group list-group-flush zbx-hostlist' }, d.rows.map((h) => el('li', { class: 'list-group-item px-0 py-1 small' }, [
          el('span', { class: `zbx-dot ${AVAIL_DOT[h.availability] || AVAIL_DOT.unknown}`, title: h.availability }),
          hostLink(h.hostid, h.name),
          h.maintenance ? el('span', { class: 'badge text-bg-info', text: t('COM_ZABBIX_JS_MAINTENANCE', 'Maintenance') }) : null,
          el('span', { class: 'zbx-hostlist__sev' }, [5, 4, 3, 2, 1, 0].filter((s) => h.problems[s]).map((s) => el('span', { class: `zbx-sev zbx-sev-${s}`, title: P.severityName(s), text: h.problems[s] }))),
        ]))));
      },

      clock(body, d) {
        const face = el('div', { class: d.digital ? 'zbx-clock zbx-clock--digital' : 'zbx-clock' });
        const tick = () => {
          const now = Math.floor(Date.now() / 1000);
          face.replaceChildren(el('div', { class: 'zbx-clock__time', text: P.format(now, cfg.format.hour12 ? 'h:i:s A' : 'H:i:s', cfg.format.zone) }),
            el('div', { class: 'small text-body-secondary', text: P.format(now, cfg.format.date || 'd/m/Y', cfg.format.zone) }));
        };
        tick();
        const id = setInterval(() => (face.isConnected ? tick() : clearInterval(id)), 1000);
        body.append(face);
      },

      empty(body) { body.append(empty()); },

      unsupported(body) {
        body.append(el('div', { class: 'text-body-secondary' }, [el('p', { class: 'mb-2', text: t('COM_ZABBIX_JS_NOT_DRAWN', 'This widget is not shown in the portal.') }), zabbixLink()]));
      },
    };

    const errorText = (code) => ({
      not_linked: t('COM_ZABBIX_JS_ERROR_NOT_LINKED', 'Your account is not linked to Zabbix.'),
      login: t('COM_ZABBIX_JS_ERROR_LOGIN', 'Please log in again.'),
    }[code] || t('COM_ZABBIX_JS_ERROR', 'The data could not be loaded.'));

    /* ---- Layout ---- */
    cfg.layout.pages.forEach((page, index) => {
      const grid = el('div', { class: 'zbx-grid', id: `zbx-page-${index}`, role: cfg.layout.pages.length > 1 ? 'tabpanel' : null, 'aria-labelledby': cfg.layout.pages.length > 1 ? `zbx-tab-${index}` : null, hidden: index !== 0 });
      const widgets = page.widgets.map((w) => {
        const body = el('div', { class: 'card-body' });
        const node = el('section', {
          class: `card zbx-widget zbx-widget--${w.type}${w.supported ? '' : ' zbx-widget--unsupported'}`,
          style: { '--x': w.x, '--y': w.y, '--w': w.width, '--h': w.height },
          'aria-label': w.title,
        }, [w.hideHeader ? null : el('h2', { class: 'card-header h6 mb-0', text: w.title }), body]);

        grid.append(node);

        return { w, body, node };
      });

      mount.append(grid);
      pages.push({ grid, widgets, loaded: false });
    });

    const load = async (item) => {
      const { w, body } = item;

      if (!w.supported) {
        body.replaceChildren();
        render.unsupported(body);
        return;
      }

      if (w.type === 'clock') {
        if (!body.childElementCount) { render.clock(body, w.clock || {}); }
        return;
      }

      item.node.classList.add('is-loading');

      if (!body.childElementCount) {
        body.append(el('p', { class: 'text-body-secondary mb-0', text: t('COM_ZABBIX_JS_LOADING', 'Loading…') }));
      }

      const period = periodSelect && periodSelect.value ? `&period=${encodeURIComponent(periodSelect.value)}` : '';

      try {
        const res = await fetch(`${cfg.endpoint}&dashboard=${encodeURIComponent(cfg.dashboard)}&widget=${encodeURIComponent(w.id)}${period}`, {
          credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': cfg.token },
        });
        const json = await res.json().catch(() => ({ error: 'invalid' }));
        body.replaceChildren();

        if (!res.ok || json.error) {
          body.append(el('p', { class: 'text-danger mb-0', role: 'alert', text: errorText(json.error) }));
        } else {
          (render[json.data.kind] || render.unsupported)(body, json.data);
        }
      } catch (e) {
        body.replaceChildren(el('p', { class: 'text-danger mb-0', role: 'alert', text: errorText('') }));
      } finally {
        item.node.classList.remove('is-loading');
      }
    };

    const loadPage = (index) => {
      const page = pages[index];

      if (!page) {
        return;
      }

      page.loaded = true;
      // A few at a time, so a big dashboard does not open dozens of requests at once.
      const queue = page.widgets.slice();
      const worker = async () => { while (queue.length) { await load(queue.shift()); } };
      Promise.all([worker(), worker(), worker(), worker()]).then(() => {
        if (updated) { updated.textContent = `· ${t('COM_ZABBIX_JS_UPDATED', 'Updated')} ${fmt.time(Math.floor(Date.now() / 1000))}`; }
      });
    };

    const show = (index) => {
      current = index;
      pages.forEach((p, i) => { p.grid.hidden = i !== index; });
      document.querySelectorAll('[data-zbx-tab]').forEach((tab) => {
        const active = Number(tab.dataset.zbxTab) === index;
        tab.setAttribute('aria-selected', String(active));
        tab.classList.toggle('active', active);
      });
      if (!pages[index].loaded) { loadPage(index); }
    };

    document.querySelectorAll('[data-zbx-tab]').forEach((tab) => {
      tab.addEventListener('click', () => show(Number(tab.dataset.zbxTab)));
      tab.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') { return; }
        const next = (current + (event.key === 'ArrowRight' ? 1 : pages.length - 1)) % pages.length;
        show(next);
        document.querySelector(`[data-zbx-tab="${next}"]`).focus();
      });
    });

    const refresh = () => {
      pages.forEach((p, i) => { p.loaded = i === current ? p.loaded : false; });
      loadPage(current);
    };

    document.querySelector('[data-zbx-refresh]')?.addEventListener('click', refresh);
    periodSelect?.addEventListener('change', refresh);

    if (cfg.refresh > 0) {
      timer = setInterval(() => { if (!document.hidden) { refresh(); } }, cfg.refresh * 1000);
      window.addEventListener('pagehide', () => clearInterval(timer));
    }

    loadPage(0);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
