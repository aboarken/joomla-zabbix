# Architecture

`com_zabbix` is a Joomla 6 MVC component (namespace `ZabbixPortal\Component\Zabbix`). It reads
Zabbix through its JSON-RPC API with one API token and shows each Joomla user what their linked
Zabbix user may see. It never changes anything in Zabbix.

## Components

| Layer | Where | What |
|---|---|---|
| Extension | `administrator/services/provider.php`, `src/Extension/ZabbixComponent.php` | Joomla DI container: every service below is a shared service; controllers get them through the component. |
| API | `src/Api/` | `JsonRpcClient` (JSON-RPC 2.0, `Authorization: Bearer`, read methods only, refuses anything else before sending), `CurlTransport` (TLS verified, no redirects, HTTP(S) only, size and time limits, `CURLOPT_CONNECT_TO` for *Connect via*), `ZabbixException` (error kinds, scrubbed details). |
| Settings | `src/Settings/` | `Settings` (typed accessors, defaults), `SettingsValidator` (pure: every rule, token re-entry on a new destination, CA file check), `SettingsRepository` (`#__zabbix_settings`). |
| Secrets | `src/Security/` | `KeyVault` (AES-256-GCM, HKDF-SHA256 key from `ZABBIX_PORTAL_SECRET` or Joomla's secret, random nonce, context as AAD), `SecretProvider`, `RateLimiter` (from the audit table). |
| Identity | `src/Identity/` | `IdentityResolver` (the logged-in user's mapping, automatic matching with retry), `UserMatcher` (pure: username / e-mail rules, `matchFor()` protections), `MappingManager` (administrator actions), `ZabbixUserDirectory` (`user.get`, cached), `DbUserMapRepository` (`#__zabbix_usermap`). |
| Access | `src/Access/` | `AccessResolver` (user groups → readable and denied host groups, tag filters; Deny wins), `AccessScope` (the rules, serialisable for the cache), `AccessService` (`usergroup.get`, cached). |
| Monitoring | `src/Monitoring/` | `HostService`, `ProblemService`, `HistoryService` (history / trends, downsampled), `AccessGuard` (checks host and item ids from requests), `Availability`, `ValueFormatter` (as Zabbix's `convertUnits()`), `SeverityColours` (`settings.get`). |
| Dashboards | `src/Dashboard/` | `DashboardService` (`dashboard.get`, sharing), `DashboardLayout` (72-column grid), `WidgetFields`, `WidgetTypes`, `WidgetDataService` (one method per widget type), `ZabbixColours` (Zabbix's palettes). |
| Discovery | `src/Discovery/` | `EndpointProbe` (Find Endpoints), `ApiRules` (the token role's API access). |
| Back end | `src/Controller`, `src/Model`, `src/View`, `tmpl/` | Integration Settings, User Mappings, Find Endpoints. The dispatcher requires `core.admin`; every task checks the CSRF token and `core.admin` again. |
| Site | `site/src/`, `site/tmpl/` | `DisplayController` (pages), `DataController` (JSON for widgets and charts), models for Dashboard, Hosts, Host, Unmatched. |
| Browser | `media/js/` | `portal.js` (helpers), `charts.js` (SVG charts), `dashboard.js`, `host.js`, `admin.js`. No libraries, no inline scripts or styles (works with a strict CSP). |

## Request flows

**Portal page** (`view=dashboard|hosts|host`):
1. `DisplayController` checks login, the view whitelist and the Joomla permission (`zabbix.dashboard.view` / `zabbix.hosts.view`).
2. `IdentityResolver::resolve()` finds or makes the mapping. An unlinked, waiting or suspended user gets the *unmatched* page.
3. The model asks for `AccessService::scopeFor()` and reads Zabbix through the services, all filtered by the scope.
4. The view renders escaped HTML. Configuration for the scripts goes in a `type="application/json"` node.

**Widget / chart data** (`task=data.widget|data.history`, `format=json`):
1. `DataController` checks login, the session token (`X-CSRF-Token` header), the permission and the mapping.
2. It re-checks the dashboard against the scope (`canSeeDashboard`), and finds the widget in that dashboard's layout. A host or item id from the request is checked by `AccessGuard`.
3. `WidgetDataService` returns the widget's data. Errors are returned as JSON codes only, with `nosniff` and `no-store`.

**Back end**: POST, CSRF token and `core.admin` on every task. The results of Test Connection and Find Endpoints are kept in the administrator's session only.

## Permission model

The API token is usually a Super admin, so Zabbix itself does not filter for the portal user. The
portal applies the user's Zabbix rights itself, as Zabbix does:

- **Hosts**: readable if one of their host groups is readable and none is denied.
- **Triggers and problems**: only if every host the trigger uses is readable (as `trigger.get`
  does). With problem tag filters on the user's groups, only problems whose host groups have full
  access, or a matching tag filter (as `problem.get` does).
- **Items, history, graphs**: through the host.
- **Dashboards**: public, owned, or shared with the user or one of their user groups.
- **Disabled** user groups disable the user; Super admins see everything.
- **Automatic matching** never links Zabbix Super admins, or a Zabbix user linked to someone else.

## Caching

`#__zabbix_cache` holds keyed JSON with an expiry, per Joomla user or shared (user 0). Only
slowly changing data is cached, for *Cache (minutes)*: the Zabbix user directory, each user's
scope, host group names, dashboard definitions and severity colours. Problems, values, hosts and
history are always read live. *Clear Cache* empties it; saving settings does too.

## Zabbix API methods

Read only: `apiinfo.version`, `user.checkAuthentication`, `user.get`, `usergroup.get`, `role.get`,
`hostgroup.get`, `host.get`, `item.get`, `history.get`, `trend.get`, `problem.get`,
`trigger.get`, `dashboard.get`, `graph.get`, `template.get`, `settings.get`. Find Endpoints checks
each of them and links to its page in the Zabbix API documentation.

## Data stored

| Table | Contents |
|---|---|
| `#__zabbix_settings` | Settings; the API token only encrypted. |
| `#__zabbix_usermap` | Joomla user → Zabbix user, status, source, e-mail at matching time, times. |
| `#__zabbix_cache` | The cache above (includes Zabbix usernames, names and e-mail addresses). |
| `#__zabbix_audit` | Who did what and when (settings, mappings, tests, automatic matches), 365 days. |

## Tests

`tests/` runs without Joomla, a database or Zabbix: `FakeTransport` answers the API calls, and
in-memory stand-ins replace the mapping table, cache and audit log (`tests/Support/Fakes.php`).
The database classes, controllers, views and templates are not covered by unit tests. Run
`vendor/bin/phpunit`.
