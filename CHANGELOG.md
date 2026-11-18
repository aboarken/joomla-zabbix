# Changelog

All notable changes to `com_zabbix` are documented in this file.
Versions follow the component manifest (`zabbix.xml`) and use [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-11-18

### Added
- **Integration Settings**: Zabbix 7.4 address and API token (encrypted at rest with AES-256-GCM), TLS verification and CA bundle, timeouts, Open in Zabbix links, user matching, dashboard refresh, cache, date and time formats, time zone; Test Connection and Clear Cache.
- **User Mappings**: Joomla users linked to Zabbix users, automatically by username and/or e-mail or by an administrator; Auto-match All, Confirm, Suspend, Rematch and Remove; each user's effective Zabbix access (role, host groups, dashboards).
- **Find Endpoints**: API discovery and diagnostics (server version, token user and role, API rules, each method the portal uses, what the token can see, dashboards and their widget types) with Copy diagnostics.
- **Zabbix Dashboard** menu item: Zabbix dashboards drawn in Joomla on Zabbix's 72-column grid, with page tabs, period selector and auto-refresh; Problems, Problems by severity, Host availability, Item value, Gauge, Item history, Plain text, Graph, Graph (classic), Pie chart, Top hosts, Host navigator and Clock widgets.
- **Zabbix Hosts** menu item: host list with summary cards, filters, availability and problem counts; host details with interfaces, inventory, current problems, latest data and item charts.
- Per-user access: each user sees only the hosts, problems, items and dashboards their Zabbix user may see (host group permissions, Deny wins, dashboard sharing).
- Read-only API client (Bearer token, read methods only), per-user cache, audit log, rate limits, log file.
