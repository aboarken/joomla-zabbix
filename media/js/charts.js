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
        ...rows.map(({ s, p }) => P.el('div', {}, [P.el('span', { class: 'zbx-swatch', style: { background: s.color } }), `${s.name}: ${P.number(p[1], s.units)}`])),
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
    P.el('span', { class: 'zbx-swatch', style: { background: e.color } }),
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

  /**
   * Gauge as Zabbix's: 180° or 270° arc, value arc in the colour of the threshold reached,
   * optional threshold arc and labels, needle and scale (min/max), description and value.
   * g: {show, angle, value, number, units, min, max, minLabel, maxLabel, description, descTop,
   *     descSize, valueSize, arcSize, thresholds[{threshold, color, label}], thArc, thArcSize,
   *     thLabels, arcColor, emptyColor, needleColor}
   */
  const gauge = (box, g) => {
    box.replaceChildren();
    const show = g.show || [1, 2, 4, 5];
    const span = g.angle === 270 ? 270 : 180;
    const start = 180 + (180 - span) / 2; // degrees, clockwise from the positive x axis
    const R = 100;
    const ratio = (v) => Math.max(0, Math.min(1, (v - g.min) / (g.max - g.min)));
    const at = (r, f) => {
      const a = ((start + span * f) * Math.PI) / 180;
      return [Math.cos(a) * r, Math.sin(a) * r];
    };
    const arc = (r, f1, f2) => {
      const [x1, y1] = at(r, f1);
      const [x2, y2] = at(r, f2);
      return `M${x1.toFixed(2)},${y1.toFixed(2)}A${r},${r} 0 ${span * (f2 - f1) > 180 ? 1 : 0} 1 ${x2.toFixed(2)},${y2.toFixed(2)}`;
    };
    const arcWidth = Math.max(4, ((g.arcSize || 20) / 100) * R);
    const rValue = R - (g.thArc ? ((g.thArcSize || 5) / 100) * R + 4 : 0) - arcWidth / 2;
    // Room below the arc for the value (under the needle's pivot, as Zabbix draws it).
    const valueSize = Math.max(14, (g.valueSize || 25) * 1.1);
    const valueY = g.angle === 270 ? 40 : (show.includes(2) ? 8 + valueSize : 0);
    const bottom = g.angle === 270 ? 80 : Math.max(16, valueY + 6);
    const top = g.thLabels || show.includes(4) ? -R - 18 : -R - 4;
    const root = svg('svg', { viewBox: `-150 ${top} 300 ${bottom - top}`, class: 'zbx-svg zbx-svg--gauge', role: 'img', 'aria-label': `${g.description}: ${g.number} ${g.units}` });
    const value = ratio(g.value);

    // Threshold arc: each band in its threshold's colour.
    if (g.thArc && g.thresholds.length) {
      const w = Math.max(2, ((g.thArcSize || 5) / 100) * R);
      g.thresholds.forEach((th, i) => {
        const f1 = ratio(th.threshold);
        const f2 = i + 1 < g.thresholds.length ? ratio(g.thresholds[i + 1].threshold) : 1;
        if (f2 > f1) {
          root.append(svg('path', { d: arc(R - w / 2, f1, f2), stroke: th.color, 'stroke-width': w, fill: 'none' }));
        }
      });
    }

    // Value arc on its background.
    if (show.includes(5)) {
      root.append(svg('path', { d: arc(rValue, 0, 1), class: 'zbx-gauge-track', fill: 'none', 'stroke-width': arcWidth, ...(g.emptyColor ? { stroke: g.emptyColor } : {}) }));
      if (value > 0) {
        root.append(svg('path', { d: arc(rValue, 0, value), stroke: g.arcColor || '#42A5F5', fill: 'none', 'stroke-width': arcWidth }));
      }
    }

    // Scale (min, max) and threshold labels around the arc.
    const label = (f, text) => {
      const [x, y] = at(R + 12, f);
      root.append(svg('text', { x: x.toFixed(1), y: (y + 4).toFixed(1), 'text-anchor': Math.abs(x) < 8 ? 'middle' : (x < 0 ? 'end' : 'start'), class: 'zbx-axis' }, text));
    };
    if (show.includes(4)) {
      label(0, g.minLabel);
      label(1, g.maxLabel);
    }
    if (g.thLabels) {
      g.thresholds.forEach((th) => { const f = ratio(th.threshold); if (f > 0.02 && f < 0.98) { label(f, th.label); } });
    }

    // Needle.
    if (show.includes(3)) {
      const [x, y] = at(rValue, value);
      const colour = g.needleColor || 'currentColor';
      root.append(svg('line', { x1: 0, y1: 0, x2: x.toFixed(2), y2: y.toFixed(2), stroke: colour, 'stroke-width': 3, 'stroke-linecap': 'round' }));
      root.append(svg('circle', { r: 5, fill: colour }));
    }

    if (show.includes(2)) {
      const text = svg('text', { x: 0, y: valueY, 'text-anchor': 'middle', class: 'zbx-gauge-value' });
      // A property, not a style attribute (strict Content Security Policy).
      text.style.fontSize = `${valueSize}px`;
      text.textContent = g.units ? `${g.number} ${g.units}` : g.number;
      root.append(text);
    }

    const parts = [root];

    if (show.includes(1) && g.description) {
      const desc = P.el('div', { class: 'zbx-gauge-desc', text: g.description, style: { 'font-size': `${Math.max(11, (g.descSize || 15) * 0.9)}px` } });
      if (g.descTop) { parts.unshift(desc); } else { parts.push(desc); }
    }

    box.append(...parts);
  };

  /** Sparkline at the bottom of an Item value widget. s: {points, color, width, fill} */
  const sparkline = (s) => {
    const W = 300;
    const H = 60;
    const xs = s.points.map((p) => p[0]);
    const ys = s.points.map((p) => p[1]);
    const x0 = Math.min(...xs); const x1 = Math.max(...xs);
    const y0 = Math.min(...ys); const y1 = Math.max(...ys);
    const px = (x) => ((x - x0) / Math.max(1, x1 - x0)) * W;
    const py = (y) => H - 2 - ((y - y0) / Math.max(1e-12, y1 - y0)) * (H - 4);
    const line = s.points.map(([x, y], i) => `${i ? 'L' : 'M'}${px(x).toFixed(1)},${py(y).toFixed(1)}`).join('');
    const root = svg('svg', { viewBox: `0 0 ${W} ${H}`, preserveAspectRatio: 'none', class: 'zbx-sparkline', 'aria-hidden': 'true' });

    if (s.fill > 0) {
      root.append(svg('path', { d: `${line}L${W},${H}L0,${H}Z`, fill: s.color, 'fill-opacity': (s.fill / 10).toFixed(2), stroke: 'none' }));
    }

    if (s.width > 0) {
      root.append(svg('path', { d: line, fill: 'none', stroke: s.color, 'stroke-width': s.width, 'vector-effect': 'non-scaling-stroke' }));
    }

    return root;
  };

  /** Analog clock face. t: {H, i, s} */
  const analogClock = (t) => {
    const root = svg('svg', { viewBox: '-55 -55 110 110', class: 'zbx-svg zbx-analog', role: 'img', 'aria-label': `${String(t.H).padStart(2, '0')}:${String(t.i).padStart(2, '0')}` });
    root.append(svg('circle', { r: 50, class: 'zbx-analog__face' }));

    for (let i = 0; i < 12; i += 1) {
      const a = (i * Math.PI) / 6;
      root.append(svg('line', { x1: Math.sin(a) * 42, y1: -Math.cos(a) * 42, x2: Math.sin(a) * 47, y2: -Math.cos(a) * 47, class: 'zbx-analog__tick' }));
    }

    const hand = (angle, length, cls) => root.append(svg('line', { x1: 0, y1: 0, x2: (Math.sin(angle) * length).toFixed(2), y2: (-Math.cos(angle) * length).toFixed(2), class: cls }));
    hand((((t.H % 12) + t.i / 60) * Math.PI) / 6, 26, 'zbx-analog__hour');
    hand(((t.i + t.s / 60) * Math.PI) / 30, 38, 'zbx-analog__minute');
    hand((t.s * Math.PI) / 30, 42, 'zbx-analog__second');
    root.append(svg('circle', { r: 2.5, class: 'zbx-analog__pin' }));

    return root;
  };

  window.ZabbixCharts = { line, pie, gauge, legend, sparkline, analogClock };
})();
