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

  const duration = (seconds) => {
    let s = Math.max(0, Math.round(seconds));
    const out = [];

    [['d', 86400], ['h', 3600], ['m', 60]].forEach(([unit, len]) => {
      if (out.length < 2 && s >= len) {
        out.push(`${Math.floor(s / len)}${unit}`);
        s %= len;
      }
    });

    if (out.length < 2 && (out.length === 0 || s > 0)) {
      out.push(`${s}s`);
    }

    return out.slice(0, 2).join(' ');
  };

  /** Number with units as Zabbix shows it (K/M/G; 1024 for B and Bps). */
  const number = (value, units = '') => {
    if (value === null || value === undefined || Number.isNaN(value)) {
      return '';
    }

    if (units === 's' || units === 'uptime') {
      return duration(value);
    }

    if (units === '%' || units === '' || units === 'ms' || units === 'rpm') {
      return `${trim(value)}${units === '%' ? '%' : (units ? ` ${units}` : '')}`;
    }

    const base = units === 'B' || units === 'Bps' ? 1024 : 1000;
    const prefixes = ['', 'K', 'M', 'G', 'T', 'P'];
    let v = value;
    let i = 0;

    while (Math.abs(v) >= base && i < prefixes.length - 1) {
      v /= base;
      i += 1;
    }

    return `${trim(v)} ${prefixes[i]}${units}`;
  };

  const trim = (v) => {
    const digits = Math.abs(v) >= 100 ? 0 : (Math.abs(v) >= 10 ? 1 : 2);

    return String(Number(v.toFixed(digits)));
  };

  const severityName = (s) => t(`COM_ZABBIX_SEVERITY_${s}`, ['Not classified', 'Information', 'Warning', 'Average', 'High', 'Disaster'][s]);

  window.ZabbixPortal = { t, el, format, makeFormatter, duration, number, severityName };

  // Filters that apply as soon as an option is chosen.
  document.addEventListener('change', (event) => {
    const field = event.target.closest('[data-zbx-autosubmit]');

    if (field && field.form) {
      field.form.requestSubmit ? field.form.requestSubmit() : field.form.submit();
    }
  });
})();
