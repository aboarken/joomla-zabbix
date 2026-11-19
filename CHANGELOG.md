# Changelog

All notable changes to `com_zabbix` are documented in this file.
Versions follow the component manifest (`zabbix.xml`) and use [Semantic Versioning](https://semver.org/).

## [1.3.3] - 2026-11-19

### Fixed
- **Problem counts now match Zabbix.** Like Zabbix's own Problems and Problems by severity widgets, the portal leaves out problems whose trigger depends on another trigger in a problem state (e.g. *Ethernet has changed to lower speed* depending on *Link down*) and problems of disabled hosts, items or triggers. Problems by severity showed e.g. 168 Information where Zabbix shows 68, and those problems were listed in Current problems.
- **Widget decimal places apply to integer items too**, as in Zabbix (*available (1.00)* in the Agent column; a column set to 0 decimal places still shows *214*).
- **Refresh**: an answer that arrives after a newer one (after a Refresh or a period change) no longer replaces it; widgets keep their scroll position when they reload (also with auto-refresh); the button shows that it is working and does not start a second refresh meanwhile.
- **Dashboard and Hosts filters without SEF URLs**: choosing a dashboard or filtering hosts no longer leads to the home page when Joomla's *Search Engine Friendly URLs* are off.

## [1.3.2] - 2026-11-19

### Changed
- The extension is called **Zabbix** in Joomla's update list (System → Update → Extensions), in the update server's name and in the installer messages (was *Zabbix Portal*).

## [1.3.1] - 2026-11-19

### Added
- **Click a count to see what is behind it.** In *Problems by severity*, clicking a severity (e.g. *High*) lists its problems with their devices, time, duration and acknowledgement. In *Host availability*, clicking a count (e.g. *Not available*) lists those devices. The list opens in a dialog (Esc or Close to close); devices link to their host page.

### Changed
- **Page titles** are the size of the ServiceDesk Plus and ZKBioTime portals' (`<h1 class="h2">`, 2rem on wide screens), whatever size the template gives `h1`.
- **Dashboard toolbar in one row**: Refresh, Dashboard, Period (and Open in Zabbix), each at its own width, also with templates that make selects 100% wide; it wraps on phones.

### Fixed
- **Scrolling in widgets**: a table's header row now stays at the very top while its rows scroll; before, rows showed above the header (e.g. in System information). Thinner scrollbars inside widgets.

## [1.3.0] - 2026-11-19

### Changed
- **The portal's own design is back**, the same as the ServiceDesk Plus and ZKBioTime portals (cards, panels, tables, pills). 1.2.0 used the template's Bootstrap classes, which some templates (e.g. Helix Ultimate with an older Bootstrap) do not have: some badges were invisible. Fonts and link colours still come from the template; the component still loads no Bootstrap or jQuery.
- **Space on the left and right** of the portal pages, also in a full-width template row.
- **Values formatted as in Zabbix 7.4**: units after a space (*58.19 %*), the widget's decimal places kept (*42.00 °C*, *available (1.00)*), *uptime* as *64 days, 01:54:51*, seconds as the 3 largest units (*1h 38m 51s*), no unit prefix for units starting with "!".
- **Problems** and **Problems by severity** leave out symptom problems, as Zabbix does (the counts were too high); a Problems widget with *Show symptoms* still shows them. The problem cell is coloured by severity, today's problems show the time only, and tags are shown when the widget asks for them.
- **Problems by severity**: every severity in its colour, also at zero, filling the widget.
- **Host availability**: Zabbix's coloured boxes (Available, Not available, Mixed, Unknown, Total) for one interface type or *Show only totals*, else a table; hosts with some interfaces up and some down count as *Mixed*; active agent checks are counted; disabled hosts and (unless asked for) hosts in maintenance are left out.

### Added
- **Top hosts**: bars and indicators with threshold colours, min/max, the column's decimal places and base colour, the value next to each bar, thresholds colouring *as is* cells, hosts without a value listed last, hosts in maintenance left out unless asked for. Column headers stay visible while scrolling.
- **Item value**: description (with {ITEM.NAME}, {HOST.NAME} and other macros), value, time, change indicator and sparkline as chosen in the widget, with their positions, sizes, colours, units position, decimal places, background colour and thresholds.
- **Gauge**: 180° or 270°, value arc in the colour of the threshold reached, threshold arc and labels, needle, scale, description position, decimal places and colours.
- **Clock**: date, time and time zone as chosen in the widget, the widget's time zone, seconds, 12/24 hours, colours; analog clocks are drawn too.
- **System information** widget (System stats): frontend version and the numbers of hosts, templates, items, triggers and users; hosts, items and triggers counted in the user's host groups, templates and users only for Super admins.

## [1.2.1] - 2026-11-19

### Removed
- The **Page width** menu item option (Zabbix Dashboard and Zabbix Hosts), added in 1.2.0. A full-width page is set in the template instead; the README explains how for Helix Ultimate. Menu items saved with it keep working; the stored value is ignored.

## [1.2.0] - 2026-11-19

### Changed
- **The portal pages use the site template's Bootstrap 5** (cards, tables, forms, buttons, badges, alerts, tabs) instead of their own design, so they look like the rest of the site (e.g. Helix Ultimate) and follow its colours and dark mode. The component still loads no Bootstrap or jQuery of its own; its stylesheet now only adds the dashboard grid, Zabbix's severity colours and the charts.

### Added
- **Menu item option Page width** (Zabbix Dashboard and Zabbix Hosts): *As the template* or *Full width*, to spread the portal over the whole browser width. README: how to make a full-width page in Helix Ultimate.

### Removed
- **Auto-match All** in User Mappings. Users are matched on their first visit to the portal (or with Rematch), and an administrator can add a mapping in advance with New.

## [1.1.0] - 2026-11-19

### Added
- **Integration Settings → User mappings → New users**: *Approve automatically* (default, as before) or *Wait for administrator approval*, as in the ServiceDesk Plus component. With *Wait for administrator approval*, a user matched automatically (first visit, Auto-match All or Rematch) is stored as **Waiting for approval** and sees a waiting page, with nothing from Zabbix, until an administrator selects them in User Mappings and clicks **Confirm**.
- **User Mappings**: a notice with the number of users waiting for approval and a link to them, the status filter *Waiting for approval*, and the waiting count in the Auto-match All result.
- A waiting user whose Joomla e-mail changes is suspended. Waiting users are matched again, and approved, on their next visit once the setting is back to *Approve automatically*. Each step is written to the audit log.

## [1.0.2] - 2026-11-18

Security and best-practice audit of the component.

### Security
- **Portal pages could be opened without their Joomla permission.** A logged-in user linked to a Zabbix user could open a portal page through the data controller's display task (`task=data.display&view=hosts`) without the *View Zabbix Hosts* or *View Zabbix Dashboard* permission. They saw only what their Zabbix user may see, but the Joomla permission was skipped. The data controller no longer shows pages, and every portal model now checks login and the page's permission itself.
- **Names of host groups a user cannot read were shown** on hosts and problems when a visible host was also in such a group. Only readable host groups are named now (Super admins still see all).
- **Widget colours are validated.** Colours set in Zabbix dashboards went into the page unchecked; anything but a hex colour is now replaced by the default palette.
- The session token of the portal's data requests is sent in the `X-CSRF-Token` header instead of the URL, so it no longer appears in web server logs or the browser history.
- Data requests answer unexpected errors with a plain JSON error (no HTML error page) and send `X-Content-Type-Options: nosniff`.
- Host details read a host's items only after the host was checked against the user's rights.
- Integration Settings and Find Endpoints warn when TLS certificate verification is switched off.

### Changed
- **Cache (minutes)** now does what it says: it is how long Zabbix users, their rights, host group names and dashboard definitions are kept (default 5, was a fixed 5 to 10 minutes whatever the setting). A change of rights in Zabbix reaches the portal within this time, or at once with Clear Cache. Problems, values and hosts are always read live.
- The portal pages work with a strict Content Security Policy: no inline style attributes.
- Audit log entries older than 365 days are deleted automatically.
- The menu item options of Zabbix Dashboard are in the standard options fieldset.
- Release workflow: GitHub Actions are pinned to commits, and the checkout keeps no credentials.

## [1.0.1] - 2026-11-18

### Added
- **Integration Settings → Port (optional)**: for a Zabbix front end published on another port than 443 or 80 (e.g. 8443). It is put into the Zabbix address, and the Open in Zabbix links use it too.
- **Integration Settings → Connect via (optional)**: for a Zabbix server hosted locally and reached from outside through another address (host, host:port or IP:port, e.g. a public IP with port forwarding). Joomla opens the connection to that address but keeps the Zabbix host name for the certificate check and the Host header, so the existing certificate stays valid. Shown in the status box and in Find Endpoints.
- Test Connection explains what to check when Zabbix cannot be reached.

### Fixed
- A connection error (wrong address, firewall, timeout, certificate) made pages fail with a PHP error instead of the message *Zabbix could not be reached*.

## [1.0.0] - 2026-11-18

### Added
- **Integration Settings**: Zabbix 7.4 address and API token (encrypted at rest with AES-256-GCM), TLS verification and CA bundle, timeouts, Open in Zabbix links, user matching, dashboard refresh, cache, date and time formats, time zone; Test Connection and Clear Cache.
- **User Mappings**: Joomla users linked to Zabbix users, automatically by username and/or e-mail or by an administrator; Auto-match All, Confirm, Suspend, Rematch and Remove; each user's effective Zabbix access (role, host groups, dashboards).
- **Find Endpoints**: API discovery and diagnostics (server version, token user and role, API rules, each method the portal uses, what the token can see, dashboards and their widget types) with Copy diagnostics.
- **Zabbix Dashboard** menu item: Zabbix dashboards drawn in Joomla on Zabbix's 72-column grid, with page tabs, period selector and auto-refresh; Problems, Problems by severity, Host availability, Item value, Gauge, Item history, Plain text, Graph, Graph (classic), Pie chart, Top hosts, Host navigator and Clock widgets.
- **Zabbix Hosts** menu item: host list with summary cards, filters, availability and problem counts; host details with interfaces, inventory, current problems, latest data and item charts.
- Per-user access: each user sees only the hosts, problems, items and dashboards their Zabbix user may see (host group permissions, Deny wins, dashboard sharing).
- Read-only API client (Bearer token, read methods only), per-user cache, audit log, rate limits, log file.
