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
    let busy = 0;
    const refreshButton = document.querySelector('[data-zbx-refresh]');
    const setBusy = () => {
      if (refreshButton) {
        refreshButton.disabled = busy > 0;
        refreshButton.setAttribute('aria-busy', String(busy > 0));
      }
    };

    const hostLink = (hostid, name) => (cfg.hostUrl && hostid
      ? el('a', { href: cfg.hostUrl.replace('__ID__', encodeURIComponent(hostid)), text: name })
      : document.createTextNode(name));

    const zabbixLink = () => (cfg.zabbix && cfg.dashboard
      ? el('a', { href: `${cfg.zabbix}/zabbix.php?action=dashboard.view&dashboardid=${encodeURIComponent(cfg.dashboard)}`, target: '_blank', rel: 'noopener noreferrer', text: t('COM_ZABBIX_JS_OPEN_IN_ZABBIX', 'Open in Zabbix') })
      : null);

    const sevBadge = (s) => el('span', { class: `zbx-sev zbx-sev-${s}`, text: P.severityName(s) });

    const table = (head, rows) => el('div', { class: 'zbx-table-wrap' }, el('table', { class: 'zbx-table zbx-table--compact' }, [
      el('thead', {}, el('tr', {}, head.map((h) => (h && h.nodeType ? h : el('th', { scope: 'col', text: h }))))),
      el('tbody', {}, rows.map((cells) => el('tr', {}, cells.map((c) => (c && c.nodeType && c.tagName === 'TD' ? c : el('td', {}, c)))))),
    ]));

    const empty = () => el('p', { class: 'zbx-empty', text: t('COM_ZABBIX_JS_NO_DATA', 'No data') });

    // Zabbix's severity colours, for backgrounds drawn from data.
    const SEV = ['#97AAB3', '#7499FF', '#FFC859', '#FFA059', '#E97659', '#E45959'];
    const AVAIL = {
      available: ['COM_ZABBIX_JS_AVAILABLE', 'Available', '#59DB8F'],
      unavailable: ['COM_ZABBIX_JS_UNAVAILABLE', 'Not available', '#E45959'],
      mixed: ['COM_ZABBIX_JS_MIXED', 'Mixed', '#FFA059'],
      unknown: ['COM_ZABBIX_JS_UNKNOWN', 'Unknown', '#97AAB3'],
    };
    const IFACE = {
      agent_active: ['COM_ZABBIX_JS_AGENT_ACTIVE', 'Zabbix agent (active checks)'],
      agent: ['COM_ZABBIX_JS_AGENT', 'Zabbix agent (passive checks)'],
      snmp: ['COM_ZABBIX_JS_SNMP', 'SNMP'],
      jmx: ['COM_ZABBIX_JS_JMX', 'JMX'],
      ipmi: ['COM_ZABBIX_JS_IPMI', 'IPMI'],
    };

    /** Text colour that stays readable on a background colour. */
    const ink = (hex) => {
      const m = /^#?([0-9a-f]{6})$/i.exec(hex || '');

      if (!m) {
        return '';
      }

      const n = parseInt(m[1], 16);

      return ((n >> 16) * 299 + ((n >> 8) & 255) * 587 + (n & 255) * 114) / 1000 > 150 ? '#1f2937' : '#ffffff';
    };

    const today = (unix) => P.format(unix, 'Y-m-d', cfg.format.zone) === P.format(Math.floor(Date.now() / 1000), 'Y-m-d', cfg.format.zone);

    /**
     * A list in a dialog (what is behind a count), as Zabbix's pop-up: native <dialog>, closed
     * with Esc, the Close button or a click outside.
     */
    const showList = (title, head, rows, total) => {
      const root = document.querySelector('[data-zbx-root]') || document.body;
      const close = el('button', { type: 'button', class: 'zbx-btn zbx-btn--ghost zbx-btn--sm', text: t('COM_ZABBIX_JS_CLOSE', 'Close') });
      const dialog = el('dialog', { class: 'zbx-dialog', 'aria-label': title }, [
        el('div', { class: 'zbx-dialog__head' }, [el('h2', { class: 'zbx-dialog__title', text: title }), close]),
        el('div', { class: 'zbx-dialog__body' }, [
          rows.length ? table(head, rows) : empty(),
          total > rows.length ? el('p', { class: 'zbx-muted zbx-small', text: `${t('COM_ZABBIX_JS_SHOWING_FIRST', 'Showing the first')} ${rows.length} / ${total}` }) : null,
        ]),
      ]);
      close.addEventListener('click', () => dialog.close());
      dialog.addEventListener('click', (event) => { if (event.target === dialog) { dialog.close(); } });
      dialog.addEventListener('close', () => dialog.remove());
      root.append(dialog);
      dialog.showModal();
      close.focus();
    };

    const problemRows = (list) => list.map((r) => [
      el('span', { class: 'zbx-nowrap', text: today(r.clock) ? fmt.time(r.clock) : fmt.dateTime(r.clock) }),
      el('span', {}, r.hosts.flatMap((h, i) => (i ? [', ', hostLink(h.hostid, h.name)] : [hostLink(h.hostid, h.name)]))),
      r.name,
      el('span', { class: 'zbx-nowrap', text: P.duration(r.duration) }),
      r.ack ? t('COM_ZABBIX_JS_YES', 'Yes') : t('COM_ZABBIX_JS_NO', 'No'),
    ]);
    const problemHead = () => [t('COM_ZABBIX_JS_TIME', 'Time'), t('COM_ZABBIX_JS_HOST', 'Host'), t('COM_ZABBIX_JS_PROBLEM', 'Problem'), t('COM_ZABBIX_JS_DURATION', 'Duration'), t('COM_ZABBIX_JS_ACK', 'Ack')];
    const hostRows = (list) => list.map((h) => [hostLink(h.hostid, h.name)]);

    /**
     * Coloured count boxes (Problems by severity, Host availability), as in Zabbix. A box with
     * something behind it is a button that lists it (b.open).
     */
    const boxes = (items, vertical) => el('div', { class: `zbx-boxes${vertical ? ' zbx-boxes--vertical' : ''}` }, items.map((b) => {
      const clickable = b.open && Number(b.count) > 0;
      const node = el(clickable ? 'button' : 'div', {
        class: `zbx-box${clickable ? ' zbx-box--button' : ''}`, type: clickable ? 'button' : null, style: { background: b.color, color: ink(b.color) },
        title: clickable ? `${b.label}: ${t('COM_ZABBIX_JS_SHOW_LIST', 'show the list')}` : null,
      }, [el('span', { class: 'zbx-box__n', text: b.count }), el('span', { class: 'zbx-box__l', text: b.label })]);

      if (clickable) {
        node.addEventListener('click', b.open);
      }

      return node;
    }));

    /** A horizontal bar (Top hosts "Bar" and "Indicators"), with threshold colours. */
    const bar = (b) => {
      const ratio = Math.max(0, Math.min(1, (b.value - b.min) / (b.max - b.min)));
      const node = el('span', { class: `zbx-bar${b.indicators ? ' zbx-bar--indicators' : ''}`, role: 'img', 'aria-label': String(b.value) });

      if (b.indicators) {
        // Segments up to the value, each in the colour of the threshold it reaches.
        const cells = 20;

        for (let i = 0; i < cells; i += 1) {
          const at = b.min + ((i + 0.5) / cells) * (b.max - b.min);
          let colour = '#97AAB3';
          b.thresholds.forEach((th) => { if (at >= th.threshold) { colour = th.color; } });
          node.append(el('span', { class: (i + 0.5) / cells <= ratio ? 'is-on' : '', style: { background: b.thresholds.length ? colour : b.fill } }));
        }
      } else {
        node.append(el('span', { class: 'zbx-bar__fill', style: { width: `${(ratio * 100).toFixed(1)}%`, background: b.fill } }));
      }

      return node;
    };

    /* ---- Renderers, one per "kind" sent by WidgetDataService ---- */
    const render = {
      problems(body, d) {
        if (!d.rows.length) { body.append(el('p', { class: 'zbx-empty zbx-ok', text: t('COM_ZABBIX_JS_NONE', 'No problems') })); return; }
        const withTags = d.rows.some((r) => r.tags && r.tags.length);
        body.append(table(
          [t('COM_ZABBIX_JS_TIME', 'Time'), t('COM_ZABBIX_JS_HOST', 'Host'), `${t('COM_ZABBIX_JS_PROBLEM', 'Problem')} • ${t('COM_ZABBIX_SEVERITY', 'Severity')}`, t('COM_ZABBIX_JS_DURATION', 'Duration'), t('COM_ZABBIX_JS_ACK', 'Ack'), ...(withTags ? [t('COM_ZABBIX_JS_TAGS', 'Tags')] : [])],
          d.rows.map((r) => [
            el('span', { class: 'zbx-nowrap', text: today(r.clock) ? fmt.time(r.clock) : fmt.dateTime(r.clock) }),
            el('span', {}, r.hosts.flatMap((h, i) => (i ? [', ', hostLink(h.hostid, h.name)] : [hostLink(h.hostid, h.name)]))),
            // As Zabbix: the problem cell in the severity's colour.
            el('td', { class: 'zbx-problem-cell', style: { background: SEV[r.severity], color: ink(SEV[r.severity]) }, title: P.severityName(r.severity) }, r.name),
            el('span', { class: 'zbx-nowrap', text: P.duration(r.duration) }),
            r.acknowledged ? t('COM_ZABBIX_JS_YES', 'Yes') : t('COM_ZABBIX_JS_NO', 'No'),
            ...(withTags ? [el('span', {}, (r.tags || []).map((tag) => el('span', { class: 'zbx-tag', text: tag })))] : []),
          ]),
        ));
      },

      severity(body, d) {
        const sevs = d.severities.slice().sort((a, b) => b - a);
        // Every severity in its colour, zero or not, as Zabbix.
        body.append(boxes(sevs.map((s) => ({
          count: d.totals[s],
          label: P.severityName(s),
          color: SEV[s],
          // Clicking a severity lists its problems and their hosts.
          open: () => showList(`${P.severityName(s)} (${d.totals[s]})`, problemHead(), problemRows((d.lists || {})[s] || []), d.totals[s]),
        })), d.vertical));
        if (d.showGroups && d.groups.length) {
          body.append(table([t('COM_ZABBIX_JS_HOSTGROUP', 'Host group'), ...sevs.map((s) => P.severityName(s))], d.groups.map((g) => [
            g.name, ...sevs.map((s) => (g.counts[s] ? el('span', { class: `zbx-sev zbx-sev-${s}`, text: g.counts[s] }) : '')),
          ])));
        }
      },

      availability(body, d) {
        const states = ['available', 'unavailable', 'mixed', 'unknown'];
        const label = (s) => t(AVAIL[s][0], AVAIL[s][1]);

        // Clicking a count lists those hosts (e.g. Not available: the devices that are down).
        const lists = d.lists || {};
        const open = (key, state, count) => () => {
          const list = state === 'total'
            ? states.flatMap((s) => (lists[key] || {})[s] || [])
            : ((lists[key] || {})[state] || []);
          const title = `${state === 'total' ? t('COM_ZABBIX_JS_TOTAL', 'Total') : label(state)}${key !== 'total' && IFACE[key] ? ` – ${t(IFACE[key][0], IFACE[key][1])}` : ''} (${count})`;
          showList(title, [t('COM_ZABBIX_JS_HOST', 'Host')], hostRows(list), count);
        };

        if (d.boxes) {
          const key = d.single || 'total';
          const counts = d.single ? d.types[d.single] : d.totals;
          body.append(boxes([
            ...states.map((s) => ({ count: counts[s] || 0, label: label(s), color: AVAIL[s][2], open: open(key, s, counts[s] || 0) })),
            { count: counts.total || 0, label: t('COM_ZABBIX_JS_TOTAL', 'Total'), color: '', open: open(key, 'total', counts.total || 0) },
          ], d.vertical));
          return;
        }

        const countButton = (key, state, count) => {
          const button = el('button', { type: 'button', class: 'zbx-count-link', text: String(count) });
          button.addEventListener('click', open(key, state, count));
          return button;
        };

        body.append(table(['', ...states.map(label), t('COM_ZABBIX_JS_TOTAL', 'Total')], Object.entries(d.types).map(([type, c]) => [
          t(IFACE[type] ? IFACE[type][0] : '', IFACE[type] ? IFACE[type][1] : type),
          ...states.map((s) => (c[s] ? el('td', { class: 'zbx-num', style: { background: AVAIL[s][2], color: ink(AVAIL[s][2]) } }, countButton(type, s, c[s])) : el('td', { class: 'zbx-num' }, '0'))),
          el('td', { class: 'zbx-num' }, String(c.total)),
        ])));
      },

      value(body, d) {
        // Zabbix's layout: each part in its own place (left/centre/right, top/middle/bottom), sized
        // as a share of the widget's height.
        const box = el('div', { class: 'zbx-value', style: d.bg ? { background: d.bg, color: ink(d.bg) } : {} });
        const height = Math.max(60, body.clientHeight || 120);
        const font = (st) => ({ 'font-size': `${Math.max(10, (st.size / 100) * height * 0.9)}px`, 'font-weight': st.bold ? '700' : '400', ...(st.color ? { color: st.color } : {}) });
        const grid = [0, 1, 2].map(() => [0, 1, 2].map(() => []));
        const place = (st, node) => grid[st.v][st.h].push(node);

        if (d.show.includes(1) && d.description) {
          place(d.desc, el('div', { class: 'zbx-value__desc', style: font(d.desc), text: d.description }));
        }

        if (d.show.includes(2)) {
          const [whole, decimals] = String(d.number).split(/(?=\.\d+$)/);
          const number = el('span', { class: 'zbx-value__number', style: font(d.value) }, [
            whole, decimals ? el('span', { style: { 'font-size': `${Math.max(9, (d.value.decimalSize / 100) * height * 0.9)}px` }, text: decimals }) : null,
          ]);
          const units = d.units ? el('span', { class: 'zbx-value__units', style: font(d.unitStyle), text: d.units }) : null;
          const arrow = d.show.includes(4) && d.trend
            ? el('span', { class: 'zbx-value__trend', 'aria-hidden': 'true', style: { color: d.trend > 0 ? d.up : d.down }, text: d.trend > 0 ? '▲' : '▼' })
            : null;
          const parts = units && d.unitsPos === 0 ? [units, number] : (units && d.unitsPos === 1 ? [units, el('br'), number] : (units && d.unitsPos === 3 ? [number, el('br'), units] : [number, units]));
          place(d.value, el('div', { class: 'zbx-value__main' }, [...parts.filter(Boolean), arrow]));
        }

        if (d.show.includes(3) && d.clock) {
          place(d.time, el('div', { class: 'zbx-value__time', style: font(d.time), text: fmt.dateTime(d.clock) }));
        }

        ['top', 'middle', 'bottom'].forEach((row, v) => {
          if (grid[v].some((cell) => cell.length)) {
            box.append(el('div', { class: `zbx-value__row zbx-value__row--${row}` }, grid[v].map((cell, h) => el('div', { class: `zbx-value__cell zbx-value__cell--${['left', 'center', 'right'][h]}` }, cell))));
          }
        });

        if (d.sparkline && d.sparkline.points.length > 1) {
          box.append(C.sparkline(d.sparkline));
        }

        body.classList.add('zbx-flush');
        body.append(box);
      },

      gauge(body, d) {
        body.classList.add('zbx-flush');
        const box = el('div', { class: 'zbx-gauge', style: d.bg ? { background: d.bg } : {} });
        body.append(box);
        C.gauge(box, d);
      },

      systeminfo(body, d) {
        const names = {
          version: ['COM_ZABBIX_JS_SI_VERSION', 'Zabbix frontend version'],
          hosts: ['COM_ZABBIX_JS_SI_HOSTS', 'Number of hosts (enabled/disabled)'],
          templates: ['COM_ZABBIX_JS_SI_TEMPLATES', 'Number of templates'],
          items: ['COM_ZABBIX_JS_SI_ITEMS', 'Number of items (enabled/disabled/not supported)'],
          triggers: ['COM_ZABBIX_JS_SI_TRIGGERS', 'Number of triggers (enabled/disabled [problem/ok])'],
          users: ['COM_ZABBIX_JS_SI_USERS', 'Number of users'],
        };
        const value = (row) => {
          const v = row.value;
          if (!Array.isArray(v)) { return String(v); }
          if (row.name === 'hosts') { return el('span', {}, [el('span', { class: 'zbx-good', text: v[0] }), ' / ', el('span', { class: 'zbx-bad', text: v[1] })]); }
          if (row.name === 'items') { return el('span', {}, [el('span', { class: 'zbx-good', text: v[0] }), ' / ', el('span', { class: 'zbx-bad', text: v[1] }), ' / ', el('span', { class: 'zbx-muted', text: v[2] })]); }
          return el('span', {}, [el('span', { class: 'zbx-good', text: v[0] }), ' / ', el('span', { class: 'zbx-bad', text: v[1] }), ' [', el('span', { class: 'zbx-bad', text: v[2] }), ' / ', el('span', { class: 'zbx-good', text: v[3] }), ']']);
        };
        if (!d.rows.length) { body.append(empty()); return; }
        body.append(table([t('COM_ZABBIX_JS_SI_PARAMETER', 'Parameter'), t('COM_ZABBIX_JS_VALUE', 'Value')], d.rows.map((row) => [
          t(names[row.name][0], names[row.name][1]), value(row),
        ])));
      },

      history(body, d) {
        if (!d.rows.length) { body.append(empty()); return; }
        body.append(table([t('COM_ZABBIX_JS_TIME', 'Time'), t('COM_ZABBIX_JS_HOST', 'Host'), t('COM_ZABBIX_JS_ITEM', 'Item'), t('COM_ZABBIX_JS_VALUE', 'Value')],
          d.rows.map((r) => [el('span', { class: 'zbx-nowrap', text: fmt.dateTime(r.clock) }), r.host, r.name, r.value])));
      },

      chart(body, d) {
        const box = el('div', { class: 'zbx-chart' });
        body.append(box);
        C.line(box, d.series, { formatter: fmt });
      },

      pie(body, d) { C.pie(body, d.slices, d.doughnut); },

      table(body, d) {
        if (!d.rows.length) { body.append(empty()); return; }
        // As Zabbix: a column shown as a bar gets two cells, the bar and the value.
        const head = d.columns.flatMap((c) => (c.bar ? [el('th', { scope: 'col', colspan: '2', text: c.name })] : [el('th', { scope: 'col', text: c.name })]));
        const rows = d.rows.map((cells) => cells.flatMap((c, i) => {
          const content = c.hostid ? hostLink(c.hostid, c.display) : c.display;

          if (d.columns[i] && d.columns[i].bar) {
            return [el('td', { class: 'zbx-bar-cell' }, c.bar ? bar(c.bar) : ''), el('td', { class: 'zbx-nowrap' }, content)];
          }

          return [el('td', { class: 'zbx-nowrap', style: c.bg ? { background: c.bg, color: ink(c.bg) } : {} }, content)];
        }));
        body.append(table(head, rows));
      },

      hosts(body, d) {
        if (!d.rows.length) { body.append(empty()); return; }
        body.append(el('ul', { class: 'zbx-hostlist' }, d.rows.map((h) => el('li', {}, [
          el('span', { class: `zbx-dot zbx-avail-${h.availability}`, title: h.availability }),
          hostLink(h.hostid, h.name),
          h.maintenance ? el('span', { class: 'zbx-pill zbx-tone-purple', text: t('COM_ZABBIX_JS_MAINTENANCE', 'Maintenance') }) : null,
          el('span', { class: 'zbx-hostlist__sev' }, [5, 4, 3, 2, 1, 0].filter((s) => h.problems[s]).map((s) => el('span', { class: `zbx-sev zbx-sev-${s}`, title: P.severityName(s), text: h.problems[s] }))),
        ]))));
      },

      clock(body, d) {
        body.classList.add('zbx-flush');
        const face = el('div', { class: d.digital ? 'zbx-clock zbx-clock--digital' : 'zbx-clock', style: d.bg ? { background: d.bg, color: ink(d.bg) } : {} });
        // The widget's time zone; "local" = the viewer's browser; none = the portal's.
        const zone = d.timezone === 'local' ? undefined : (d.timezone || cfg.format.zone);
        const show = d.show || [2];
        const part = (key, text) => el('div', { class: `zbx-clock__${key}`, style: { ...(d.colors && d.colors[key] ? { color: d.colors[key] } : {}), ...(d.bold && d.bold[key] ? { 'font-weight': '700' } : {}) }, text });
        const tzLabel = () => {
          const name = zone || Intl.DateTimeFormat().resolvedOptions().timeZone;
          try {
            const offset = new Intl.DateTimeFormat('en-US', { timeZone: name, timeZoneName: 'longOffset' }).formatToParts(new Date()).find((p) => p.type === 'timeZoneName').value.replace('GMT', 'UTC');
            return d.tzFull ? `(${offset === 'UTC' ? 'UTC+00:00' : offset}) ${name}` : `(${offset === 'UTC' ? 'UTC+00:00' : offset})`;
          } catch (e) {
            return name;
          }
        };
        const tick = () => {
          const now = Math.floor(Date.now() / 1000);
          const time = d.hour12 ? (d.seconds ? 'h:i:s A' : 'h:i A') : (d.seconds ? 'H:i:s' : 'H:i');

          if (!d.digital) {
            face.replaceChildren(C.analogClock(P.parts(now, zone)));
            return;
          }

          face.replaceChildren(
            ...(show.includes(1) ? [part('date', P.format(now, 'Y-m-d', zone))] : []),
            ...(show.includes(2) ? [part('time', P.format(now, time, zone))] : []),
            ...(show.includes(3) ? [part('tzone', tzLabel())] : []),
          );
        };
        tick();
        const id = setInterval(() => (face.isConnected ? tick() : clearInterval(id)), 1000);
        body.append(face);
      },

      empty(body) { body.append(empty()); },

      unsupported(body) {
        body.append(el('div', { class: 'zbx-placeholder' }, [el('p', { text: t('COM_ZABBIX_JS_NOT_DRAWN', 'This widget is not shown in the portal.') }), zabbixLink()]));
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
        const body = el('div', { class: 'zbx-widget__body' });
        const node = el('section', {
          class: `zbx-widget zbx-widget--${w.type}${w.supported ? '' : ' zbx-widget--unsupported'}`,
          style: { '--x': w.x, '--y': w.y, '--w': w.width, '--h': w.height },
          'aria-label': w.title,
        }, [w.hideHeader ? null : el('h2', { class: 'zbx-widget__title', text: w.title }), body]);

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

      // Only the newest request of a widget is drawn: an older answer that arrives late (after a
      // Refresh or a period change) is dropped.
      item.seq = (item.seq || 0) + 1;
      const seq = item.seq;

      item.node.classList.add('is-loading');

      if (!body.childElementCount) {
        body.append(el('p', { class: 'zbx-loading', text: t('COM_ZABBIX_JS_LOADING', 'Loading…') }));
      }

      const period = periodSelect && periodSelect.value ? `&period=${encodeURIComponent(periodSelect.value)}` : '';
      const draw = (fill) => {
        if (seq !== item.seq) {
          return;
        }

        // Keep the reader's place in a scrolled widget (auto-refresh every minute or so).
        const { scrollTop, scrollLeft } = body;
        body.replaceChildren();
        body.classList.remove('zbx-flush');
        fill();
        body.scrollTop = scrollTop;
        body.scrollLeft = scrollLeft;
      };

      try {
        const res = await fetch(`${cfg.endpoint}&dashboard=${encodeURIComponent(cfg.dashboard)}&widget=${encodeURIComponent(w.id)}${period}`, {
          credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': cfg.token },
        });
        const json = await res.json().catch(() => ({ error: 'invalid' }));

        draw(() => {
          if (!res.ok || json.error) {
            body.append(el('p', { class: 'zbx-error-text', role: 'alert', text: errorText(json.error) }));
          } else {
            (render[json.data.kind] || render.unsupported)(body, json.data);
          }
        });
      } catch (e) {
        draw(() => body.append(el('p', { class: 'zbx-error-text', role: 'alert', text: errorText('') })));
      } finally {
        if (seq === item.seq) {
          item.node.classList.remove('is-loading');
        }
      }
    };

    const loadPage = (index) => {
      const page = pages[index];

      if (!page) {
        return;
      }

      page.loaded = true;
      busy += 1;
      setBusy();
      // A few at a time, so a big dashboard does not open dozens of requests at once.
      const queue = page.widgets.slice();
      const worker = async () => { while (queue.length) { await load(queue.shift()); } };

      return Promise.all([worker(), worker(), worker(), worker()]).then(() => {
        if (updated) { updated.textContent = `· ${t('COM_ZABBIX_JS_UPDATED', 'Updated')} ${fmt.time(Math.floor(Date.now() / 1000))}`; }
      }).finally(() => {
        busy -= 1;
        setBusy();
      });
    };

    const show = (index) => {
      current = index;
      pages.forEach((p, i) => { p.grid.hidden = i !== index; });
      document.querySelectorAll('[data-zbx-tab]').forEach((tab) => tab.setAttribute('aria-selected', String(Number(tab.dataset.zbxTab) === index)));
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

    // Other pages are loaded again when shown; the visible one now.
    const refresh = () => {
      pages.forEach((p, i) => { p.loaded = i === current ? p.loaded : false; });
      loadPage(current);
    };

    // Refresh: shows that it is working, and is not run again until it has finished (a period
    // change always runs, and its answers replace the older ones).
    refreshButton?.addEventListener('click', () => { if (!busy) { refresh(); } });
    periodSelect?.addEventListener('change', refresh);

    if (cfg.refresh > 0) {
      timer = setInterval(() => { if (!document.hidden && !busy) { refresh(); } }, cfg.refresh * 1000);
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
