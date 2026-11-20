/**
 * com_zabbix portal: shared helpers (window.ZabbixPortal) and filters that apply as soon as an
 * option is chosen.
 *
 * @license GNU General Public License version 2 or later
 */
(() => {
  'use strict';

  const t = (key, fallback) => {
    const text = window.Joomla && Joomla.Text ? Joomla.Text._(key, '') : '';

    return text || fallback || key;
  };

  /** Create an element: el('td', {class: 'x', text: 'y', style: {color: 'red'}}, [children]). */
  const el = (tag, attrs = {}, children = []) => {
    const node = document.createElement(tag);

    Object.entries(attrs).forEach(([name, value]) => {
      if (value === null || value === undefined || value === false) {
        return;
      }

      if (name === 'text') {
        node.textContent = value;
      } else if (name === 'style') {
        // As properties, not as a style attribute: allowed by a strict Content Security Policy.
        Object.entries(value).forEach(([property, v]) => node.style.setProperty(property, String(v)));
      } else if (name === 'class') {
        node.className = value;
      } else {
        node.setAttribute(name, value === true ? '' : value);
      }
    });

    (Array.isArray(children) ? children : [children]).forEach((child) => {
      if (child !== null && child !== undefined && child !== false) {
        node.append(child instanceof Node ? child : document.createTextNode(String(child)));
      }
    });

    return node;
  };

  const pad = (n) => String(n).padStart(2, '0');
  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

  /** Date parts of a Unix time in a time zone. */
  const parts = (unix, zone) => {
    const out = {};

    try {
      new Intl.DateTimeFormat('en-GB', {
        timeZone: zone || undefined,
        year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: 'numeric', second: 'numeric', hourCycle: 'h23',
      }).formatToParts(new Date(unix * 1000)).forEach((p) => { out[p.type] = p.value; });
    } catch (e) {
      const d = new Date(unix * 1000);
      Object.assign(out, { year: d.getFullYear(), month: d.getMonth() + 1, day: d.getDate(), hour: d.getHours(), minute: d.getMinutes(), second: d.getSeconds() });
    }

    return { Y: +out.year, m: +out.month, d: +out.day, H: +out.hour % 24, i: +out.minute, s: +out.second };
  };

  /** Format with a PHP-style pattern: d m Y M H h i s A. */
  const format = (unix, pattern, zone) => {
    const p = parts(unix, zone);

    return pattern.replace(/[dmYMHhisA]/g, (c) => ({
      d: pad(p.d), m: pad(p.m), Y: p.Y, M: MONTHS[p.m - 1], H: pad(p.H), h: pad(((p.H + 11) % 12) + 1), i: pad(p.i), s: pad(p.s), A: p.H < 12 ? 'AM' : 'PM',
    }[c]));
  };

  const makeFormatter = (cfg = {}) => {
    const time = cfg.hour12 ? (cfg.secs ? 'h:i:s A' : 'h:i A') : (cfg.secs ? 'H:i:s' : 'H:i');
    const date = cfg.date || 'd/m/Y';

    return {
      dateTime: (unix) => (unix > 0 ? format(unix, `${date} ${time}`, cfg.zone) : '—'),
      time: (unix) => format(unix, time, cfg.zone),
      short: (unix, span) => (span > 172800 ? format(unix, date.replace(/[\/.\- ]?Y/, ''), cfg.zone) : format(unix, cfg.hour12 ? 'h:i A' : 'H:i', cfg.zone)),
    };
  };

  /** The 3 largest units, as Zabbix's convertUnitsS(): "1h 38m 51s", "21d 3h 42m". */
  const duration = (seconds) => {
    let s = Math.floor(Math.max(0, seconds));
    const out = [];
    let first = null;

    [['y', 31536000], ['M', 2592000], ['d', 86400], ['h', 3600], ['m', 60], ['s', 1]].some(([unit, len], level) => {
      const count = Math.floor(s / len);

      if (count > 0) {
        out.push(`${count}${unit}`);
        s -= count * len;
        first = first === null ? level : first;
      }

      return first !== null && level - first >= 2;
    });

    return out.length ? out.join(' ') : '0';
  };

  /** "64 days, 01:54:51", as Zabbix's convertUnitsUptime(). */
  const uptime = (seconds) => {
    const s = Math.max(0, Math.round(seconds));
    const days = Math.floor(s / 86400);
    const rest = s - days * 86400;
    const pad = (n) => String(n).padStart(2, '0');
    const time = `${pad(Math.floor(rest / 3600))}:${pad(Math.floor((rest % 3600) / 60))}:${pad(rest % 60)}`;

    return days ? `${days} ${days === 1 ? 'day' : 'days'}, ${time}` : time;
  };

  /**
   * Number with units as Zabbix shows it (convertUnits()): units after a space, K/M/G (1024 for
   * B and Bps), no prefix for %, ms, rpm and units starting with "!" (not shown).
   */
  const number = (value, units = '') => {
    if (value === null || value === undefined || Number.isNaN(value)) {
      return '';
    }

    if (units === 's') {
      return duration(value);
    }

    if (units === 'uptime') {
      return uptime(value);
    }

    let unit = units || '';
    const noPrefix = ['%', 'ms', 'rpm', 'RPM'];

    if (unit.startsWith('!')) {
      unit = unit.slice(1);
      noPrefix.push(unit);
    }

    if (unit === '' || noPrefix.includes(unit) || Math.abs(value) < 1) {
      return unit ? `${trim(value)} ${unit}` : trim(value);
    }

    const base = unit === 'B' || unit === 'Bps' ? 1024 : 1000;
    const prefixes = ['', 'K', 'M', 'G', 'T', 'P'];
    let v = value;
    let i = 0;

    while (Math.abs(v) >= base && i < prefixes.length - 1) {
      v /= base;
      i += 1;
    }

    return `${trim(v)} ${prefixes[i]}${unit}`;
  };

  const trim = (v) => {
    const digits = Math.abs(v) >= 100 ? 0 : (Math.abs(v) >= 10 ? 1 : 2);

    return String(Number(v.toFixed(digits)));
  };

  const severityName = (s) => t(`COM_ZABBIX_SEVERITY_${s}`, ['Not classified', 'Information', 'Warning', 'Average', 'High', 'Disaster'][s]);

  /**
   * A Zabbix colour made matte: same hue and lightness, 60% of the saturation (less glare).
   * '#E45959' → '#C87575'.
   */
  function matte(hex) {
    const m = /^#?([0-9a-f]{6})$/i.exec(hex || '');

    if (!m) {
      return hex;
    }

    const n = parseInt(m[1], 16);
    const [r, g, b] = [(n >> 16) & 255, (n >> 8) & 255, n & 255].map((c) => c / 255);
    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const l = (max + min) / 2;
    const d = max - min;

    if (d === 0) {
      return `#${m[1].toUpperCase()}`;
    }

    const s = (d / (1 - Math.abs(2 * l - 1))) * 0.6;
    let h = max === r ? ((g - b) / d) % 6 : (max === g ? (b - r) / d + 2 : (r - g) / d + 4);
    h = (h * 60 + 360) % 360;

    const c = (1 - Math.abs(2 * l - 1)) * s;
    const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
    const o = l - c / 2;
    const [r1, g1, b1] = h < 60 ? [c, x, 0] : h < 120 ? [x, c, 0] : h < 180 ? [0, c, x] : h < 240 ? [0, x, c] : h < 300 ? [x, 0, c] : [c, 0, x];

    return `#${[r1, g1, b1].map((v) => Math.round((v + o) * 255).toString(16).padStart(2, '0')).join('').toUpperCase()}`;
  }

  // Severity colours as set in Zabbix (Administration → General → Trigger displaying options),
  // sent by the page; Zabbix's defaults otherwise.
  const SEVERITY_COLOURS = (() => {
    const defaults = ['#97AAB3', '#7499FF', '#FFC859', '#FFA059', '#E97659', '#E45959'];
    const options = window.Joomla && Joomla.getOptions ? Joomla.getOptions('com_zabbix.colours', {}) : {};
    const sent = Array.isArray(options.severity) ? options.severity : [];

    return defaults.map((d, i) => matte(/^#[0-9a-f]{6}$/i.test(sent[i] || '') ? sent[i] : d));
  })();
  const severityColour = (s) => SEVERITY_COLOURS[Math.max(0, Math.min(5, Number(s) || 0))];

  // The page's severity badges, cards and boxes use these colours (CSS variables, set as
  // properties: allowed by a strict Content Security Policy).
  document.querySelectorAll('[data-zbx-root]').forEach((root) => {
    SEVERITY_COLOURS.forEach((colour, i) => root.style.setProperty(`--zbx-sev-${i}`, colour));
  });

  window.ZabbixPortal = { t, el, format, parts, makeFormatter, duration, number, severityName, severityColour, matte };

  // Filters that apply as soon as an option is chosen.
  document.addEventListener('change', (event) => {
    const field = event.target.closest('[data-zbx-autosubmit]');

    if (field && field.form) {
      field.form.requestSubmit ? field.form.requestSubmit() : field.form.submit();
    }
  });
})();
