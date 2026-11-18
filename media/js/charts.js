/**
 * com_zabbix portal: small SVG charts (no library). Line charts for item history, pie and
 * doughnut charts, and gauges. Exposed as window.ZabbixCharts.
 *
 * @license GNU General Public License version 2 or later
 */
(() => {
  'use strict';

  const P = window.ZabbixPortal;
  const NS = 'http://www.w3.org/2000/svg';

  const svg = (tag, attrs = {}, text = null) => {
    const node = document.createElementNS(NS, tag);
    Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, v));

    if (text !== null) {
      node.textContent = text;
    }

    return node;
  };

  /** "Nice" axis steps: 1, 2, 2.5, 5 x 10^n. */
  const niceTicks = (min, max, count = 5) => {
    if (min === max) {
      const pad = Math.abs(min) || 1;
      return niceTicks(min - pad, max + pad, count);
    }

    const raw = (max - min) / count;
    const mag = 10 ** Math.floor(Math.log10(raw));
    const step = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((s) => s >= raw) || raw;
    const start = Math.floor(min / step) * step;
    const ticks = [];

    for (let v = start; v <= max + step * 0.5; v += step) {
      ticks.push(Number(v.toFixed(10)));
    }

    return ticks;
  };

  /**
   * Line chart.
   * @param {HTMLElement} box
   * @param {Array<{name, host, units, color, points: Array<[number, number]>}>} series
   * @param {{formatter, from?: number, till?: number}} opts
   */
  const line = (box, series, opts = {}) => {
    box.replaceChildren();
    const withData = series.filter((s) => s.points && s.points.length);

    if (!withData.length) {
      box.append(P.el('p', { class: 'zbx-empty', text: P.t('COM_ZABBIX_JS_NO_DATA', 'No data') }));
      return;
    }

    // Drawn at the box's real width, so the axis text keeps its size on phones.
    const W = Math.max(300, Math.round(box.clientWidth || 640));
    const H = Math.max(160, Math.min(320, Math.round(W * 0.42)));
    const M = { l: 64, r: 12, t: 10, b: 26 };
    const xTicks = W < 480 ? 3 : 5;
    let xMin = Infinity; let xMax = -Infinity; let yMin = Infinity; let yMax = -Infinity;

    withData.forEach((s) => s.points.forEach(([x, y]) => {
      xMin = Math.min(xMin, x); xMax = Math.max(xMax, x); yMin = Math.min(yMin, y); yMax = Math.max(yMax, y);
    }));

    if (opts.from) { xMin = Math.min(xMin, opts.from); }
    if (opts.till) { xMax = Math.max(xMax, opts.till); }
    if (yMin >= 0 && yMin <= yMax) { yMin = 0; }

    const ticks = niceTicks(yMin, yMax);
    const y0 = ticks[0];
    const y1 = ticks[ticks.length - 1];
    const xs = (x) => M.l + ((x - xMin) / Math.max(1, xMax - xMin)) * (W - M.l - M.r);
    const ys = (y) => H - M.b - ((y - y0) / Math.max(1e-12, y1 - y0)) * (H - M.t - M.b);
    const units = withData[0].units || '';
    const span = xMax - xMin;

    const root = svg('svg', { viewBox: `0 0 ${W} ${H}`, class: 'zbx-svg', role: 'img', 'aria-label': withData.map((s) => s.name).join(', ') });

    ticks.forEach((v) => {
      const y = ys(v);
      root.append(svg('line', { x1: M.l, x2: W - M.r, y1: y, y2: y, class: 'zbx-grid-line' }));
      root.append(svg('text', { x: M.l - 6, y: y + 4, 'text-anchor': 'end', class: 'zbx-axis' }, P.number(v, units)));
    });

    for (let i = 0; i <= xTicks; i += 1) {
      const x = xMin + (span * i) / xTicks;
      root.append(svg('text', { x: xs(x), y: H - 6, 'text-anchor': i === 0 ? 'start' : (i === xTicks ? 'end' : 'middle'), class: 'zbx-axis' }, opts.formatter ? opts.formatter.short(x, span) : ''));
    }

    withData.forEach((s) => {
      const d = s.points.map(([x, y], i) => `${i ? 'L' : 'M'}${xs(x).toFixed(1)},${ys(y).toFixed(1)}`).join('');
      const area = `${d}L${xs(s.points[s.points.length - 1][0]).toFixed(1)},${ys(y0)}L${xs(s.points[0][0]).toFixed(1)},${ys(y0)}Z`;

      if (withData.length === 1) {
        root.append(svg('path', { d: area, fill: s.color, 'fill-opacity': '0.12', stroke: 'none' }));
      }

      root.append(svg('path', { d, fill: 'none', stroke: s.color, 'stroke-width': '1.6', 'vector-effect': 'non-scaling-stroke' }));
    });

    // Hover: a vertical line and the nearest value of each series.
    const cursor = svg('line', { y1: M.t, y2: H - M.b, class: 'zbx-cursor', visibility: 'hidden' });
    const overlay = svg('rect', { x: M.l, y: M.t, width: W - M.l - M.r, height: H - M.t - M.b, fill: 'transparent' });
    root.append(cursor, overlay);

    const tip = P.el('div', { class: 'zbx-tip', hidden: true });
    const wrap = P.el('div', { class: 'zbx-chart-wrap' }, [root, tip]);

    const hide = () => { cursor.setAttribute('visibility', 'hidden'); tip.hidden = true; };

    overlay.addEventListener('mousemove', (event) => {
      const rect = root.getBoundingClientRect();
      const x = xMin + (((event.clientX - rect.left) / rect.width) * W - M.l) / (W - M.l - M.r) * span;
      const rows = withData.map((s) => {
        let best = s.points[0];
        s.points.forEach((p) => { if (Math.abs(p[0] - x) < Math.abs(best[0] - x)) { best = p; } });
        return { s, p: best };
      });

      cursor.setAttribute('x1', xs(rows[0].p[0]));
      cursor.setAttribute('x2', xs(rows[0].p[0]));
      cursor.setAttribute('visibility', 'visible');
      tip.replaceChildren(
        P.el('div', { class: 'zbx-tip__time', text: opts.formatter ? opts.formatter.dateTime(rows[0].p[0]) : '' }),
        ...rows.map(({ s, p }) => P.el('div', {}, [P.el('span', { class: 'zbx-swatch', style: `background:${s.color}` }), `${s.name}: ${P.number(p[1], s.units)}`])),
      );
      tip.hidden = false;
      const left = ((event.clientX - rect.left) / rect.width) * 100;
      tip.style.left = left > 60 ? '' : `${left + 2}%`;
      tip.style.right = left > 60 ? `${102 - left}%` : '';
    });
    overlay.addEventListener('mouseleave', hide);

    box.append(wrap, legend(withData.map((s) => {
      const values = s.points.map((p) => p[1]);
      const last = values[values.length - 1];
      const avg = values.reduce((a, b) => a + b, 0) / values.length;

      return { color: s.color, label: `${s.host ? `${s.host}: ` : ''}${s.name}`, value: `${P.number(last, s.units)} (avg ${P.number(avg, s.units)})` };
    })));
  };

  const legend = (entries) => P.el('ul', { class: 'zbx-legend' }, entries.map((e) => P.el('li', {}, [
    P.el('span', { class: 'zbx-swatch', style: `background:${e.color}` }),
    P.el('span', { class: 'zbx-legend__label', text: e.label }),
    e.value ? P.el('span', { class: 'zbx-legend__value', text: e.value }) : null,
  ])));

  /** Pie or doughnut chart. slices: [{name, value, display, color}] */
  const pie = (box, slices, doughnut = false) => {
    box.replaceChildren();
    const total = slices.reduce((a, s) => a + s.value, 0);

    if (!slices.length || total <= 0) {
      box.append(P.el('p', { class: 'zbx-empty', text: P.t('COM_ZABBIX_JS_NO_DATA', 'No data') }));
      return;
    }

    const root = svg('svg', { viewBox: '-110 -110 220 220', class: 'zbx-svg zbx-svg--pie', role: 'img' });
    let angle = -Math.PI / 2;

    slices.forEach((s) => {
      const a = (s.value / total) * Math.PI * 2;
      const title = svg('title', {}, `${s.name}: ${s.display}`);

      if (a >= Math.PI * 2 - 1e-6) {
        const c = svg('circle', { r: 100, fill: s.color });
        c.append(title);
        root.append(c);
      } else {
        const x1 = Math.cos(angle) * 100; const y1 = Math.sin(angle) * 100;
        const x2 = Math.cos(angle + a) * 100; const y2 = Math.sin(angle + a) * 100;
        const path = svg('path', { d: `M0,0L${x1},${y1}A100,100 0 ${a > Math.PI ? 1 : 0} 1 ${x2},${y2}Z`, fill: s.color, stroke: 'var(--zbx-card-bg, #fff)', 'stroke-width': 1 });
        path.append(title);
        root.append(path);
      }

      angle += a;
    });

    if (doughnut) {
      root.append(svg('circle', { r: 55, fill: 'var(--zbx-card-bg, #fff)' }));
    }

    box.append(P.el('div', { class: 'zbx-pie' }, [root, legend(slices.map((s) => ({
      color: s.color, label: s.name, value: `${s.display} (${((s.value / total) * 100).toFixed(1)}%)`,
    })))]));
  };

  /** Half-circle gauge. g: {value, display, min, max, name} */
  const gauge = (box, g) => {
    box.replaceChildren();
    const min = Number(g.min) || 0;
    const max = Number(g.max) > min ? Number(g.max) : min + 100;
    const ratio = Math.max(0, Math.min(1, (g.value - min) / (max - min)));
    const arc = (r, from, to) => {
      const a1 = Math.PI * (1 + from); const a2 = Math.PI * (1 + to);
      return `M${Math.cos(a1) * r},${Math.sin(a1) * r}A${r},${r} 0 0 1 ${Math.cos(a2) * r},${Math.sin(a2) * r}`;
    };
    const color = ratio > 0.9 ? '#e45959' : (ratio > 0.75 ? '#ffa059' : '#43a047');
    const root = svg('svg', { viewBox: '-110 -105 220 125', class: 'zbx-svg zbx-svg--gauge', role: 'img', 'aria-label': `${g.name}: ${g.display}` });

    root.append(svg('path', { d: arc(90, 0, 1), class: 'zbx-gauge-track', fill: 'none', 'stroke-width': 18 }));

    if (ratio > 0) {
      root.append(svg('path', { d: arc(90, 0, ratio), stroke: color, fill: 'none', 'stroke-width': 18 }));
    }

    root.append(svg('text', { x: 0, y: -10, 'text-anchor': 'middle', class: 'zbx-gauge-value' }, g.display));
    root.append(svg('text', { x: -90, y: 16, 'text-anchor': 'middle', class: 'zbx-axis' }, String(min)));
    root.append(svg('text', { x: 90, y: 16, 'text-anchor': 'middle', class: 'zbx-axis' }, String(max)));
    box.append(root);
  };

  window.ZabbixCharts = { line, pie, gauge, legend };
})();
