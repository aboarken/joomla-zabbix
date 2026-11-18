# Joomla Zabbix

`com_zabbix` is a Joomla 6 component that brings **Zabbix 7.4** into your Joomla site through the
Zabbix JSON-RPC API. Users log in to Joomla and see Zabbix **dashboards** and **hosts**, drawn by
Joomla with live data. Each user sees only what their own Zabbix user may see.

Zabbix stays the single source of truth. Joomla only reads and displays: the component calls
read methods (`*.get`) only and never changes anything in Zabbix.

## Features

### Administrator: three menus (Components → Zabbix)

| Menu | What it does |
|---|---|
| **Integration Settings** | The Zabbix address and **API token**. The token is encrypted at rest (AES-256-GCM) and never shown again. Also: TLS verification and a custom CA bundle, timeouts, *Open in Zabbix* links, user-matching rules, dashboard refresh, cache, date/time format and time zone. **Test Connection** checks the Zabbix version and the token's user. **Clear Cache** empties the cache. |
| **User Mappings** | Links each Joomla user to one Zabbix user. Users are matched **automatically** by username and/or e-mail (from the Zabbix user's media) on their first visit, or by **Auto-match All**. Mappings can also be set by hand. Actions: Confirm, Suspend, Rematch, Remove. A user's edit page shows **what that user will see**: role, readable and denied host groups, and dashboards. |
| **Find Endpoints** | API discovery and diagnostics. It shows the server version and response time, the token's user, role and the role's API rules (warning if the role allows write methods), and each API method the portal uses with its result: *allowed*, *denied* or *error*. It also shows what the token can see (host groups, hosts, templates, users, dashboards, problems) and every dashboard with the widget types it uses, marking those the portal does not draw. Ends with a **Copy diagnostics** button (no secrets). |

### Site: two menu item types (Menus → Add New Menu Item → Zabbix)

| Menu item type | What the user sees |
|---|---|
| **Zabbix Dashboard** | A Zabbix dashboard drawn in Joomla on Zabbix's own grid (72 columns, 70 px rows; stacked on phones), with live data. It has page tabs, a period selector, auto-refresh and an *Open in Zabbix* link. Menu options: a fixed dashboard (or the first the user may see), whether users may switch between the dashboards shared with them, and the refresh interval. |
| **Zabbix Hosts** | Summary cards (hosts, available, not available, with problems, in maintenance). Filters: search, host group, status, availability and problems. A table with groups, availability per interface type (ZBX/SNMP/IPMI/JMX) and problem counts per severity. Each host opens a **details page** with interfaces, groups, tags, templates, inventory, current problems and latest data. Every numeric item has a **chart** for 1 h to 30 days. |

### Dashboard widgets drawn by the portal

| Zabbix widget | Shown as |
|---|---|
| Problems | Table: time, severity, host, problem, duration, acknowledged |
| Problems by severity | Totals per severity, optionally per host group |
| Host availability | Available / not available / unknown per interface type, or totals |
| Item value, Gauge | Big value with trend arrow; half-circle gauge |
| Item history (and Plain text) | Latest values table |
| Graph, Graph (classic) | Line chart (history up to 2 days, hourly trends beyond); item lists and host/item name patterns |
| Pie chart | Pie or doughnut with legend |
| Top hosts | Table of item values per host, sorted, top or bottom N |
| Host navigator | Host list with availability and problems |
| Clock | Analog or digital clock in the portal time zone |

Other widgets (Map, Geomap, Honeycomb, URL, SLA report, Trigger overview and so on) show a tidy
**Open in Zabbix** placeholder. **Find Endpoints** lists which widget types your dashboards use.

## How access works

The Zabbix API always answers as the token's user, and it **cannot act as another user**. So the
portal applies each user's Zabbix rights itself, on every page and every widget:

1. The Joomla user must be logged in and have the Joomla permission for the page
   (*View Zabbix Dashboard* / *View Zabbix Hosts*).
2. The Joomla user must be linked to an **active** Zabbix user (User Mappings). Otherwise the user
   sees a "not linked" page.
3. That Zabbix user's rights are read from Zabbix (`usergroup.get` with host group rights):
   - **Super admin** role: everything the token sees.
   - Otherwise: hosts in host groups with **Read** or **Read-write** permission. A host that is
     also in a **Deny** group is hidden, because Deny wins, as in Zabbix. A user in a disabled user
     group sees nothing.
   - **Dashboards**: public ones, the user's own, and those shared with the user or one of their
     user groups.
4. Every host and item a widget or URL names is checked against those rights **before** any of its
   data is read. Widget settings stay on the server and are never sent to the browser.

Because the token has to read all users and their rights, its user must be a **Super admin**.
Give it a role that allows only read methods (below).

## Requirements

- Joomla **6.0 or later** (developed for 6.2), PHP **8.3+** with `curl` and `openssl`
- Zabbix **7.4** (Find Endpoints warns about other versions)
- The Joomla server must reach the Zabbix front end over HTTPS (or HTTP on a trusted network)

## Zabbix setup (once)

1. **User role**: *Users → User roles → Create user role*
   - Name: `Joomla portal (read-only)`, User type: **Super admin**
   - *API access*: Enabled. *API methods*: **Allow list** with `*.get`
   - The UI and module options don't matter for API use.
2. **User**: *Users → Users → Create user*, e.g. `joomla-portal`, with that role, in a user group
   with frontend access *Disabled* (the user only uses the API).
3. **API token**: *Users → API tokens → Create API token* for that user. Optionally set an expiry
   date. Copy the 64-character token. Zabbix shows it only once.

The portal never calls write methods, even if the role would allow them. The read-only role limits
the damage if the token ever leaks.

## Installation and configuration

1. Download `com_zabbix.zip` from the latest
   [release](https://github.com/aboarken/joomla-zabbix/releases) (or build it, see below) and
   install it with **System → Install → Extensions**. Joomla finds updates of the component
   through its update server.
2. **Components → Zabbix → Integration Settings**: enter the Zabbix address, e.g.
   `https://zabbix.example.org`, and the API token. **Save**, then **Test Connection**.
3. **Find Endpoints**: press *Find Endpoints*. Every required method should be *Allowed* and the
   token user a *Super admin*.
4. **User Mappings**: press **Auto-match All**, then fix *Ambiguous* or *Not found* users by
   editing them. Open a user to see exactly what they will see.
5. **Options → Permissions**: set *View Zabbix Dashboard* and/or *View Zabbix Hosts* to *Allowed*
   for the groups that should use the portal (e.g. *Registered*). Nothing is allowed by default.
6. **Menus**: add menu items of type **Zabbix → Zabbix Dashboard** and **Zabbix → Zabbix Hosts**.
   Give them the access level *Registered*, so only logged-in users see them in the menu.

### Encryption secret

The API token is encrypted with a key derived from:

1. the environment variable `ZABBIX_PORTAL_SECRET` (at least 32 characters, recommended), or
2. the `secret` in Joomla's `configuration.php`.

If you change the secret, the stored token can no longer be decrypted. Enter the token again.

## Caching

Zabbix answers are cached in the component's own table `#__zabbix_cache`. Widget and host data are
kept for *Cache (minutes)*, 2 by default, and 0 means no cache between page loads. The user list,
user rights and dashboard definitions are kept for 5 to 10 minutes. **Clear Cache** and saving the
settings empty the cache. The cache is keyed per user, so one user never sees another user's
cached data.

## Security

- Every administrator page and task needs **Super User** level (`core.admin`). Every POST needs a
  CSRF token. Portal data requests need the session token.
- The API token is encrypted at rest. It is never shown, logged or sent to the browser. Error
  messages and the audit log are scrubbed of anything that looks like a token.
- Only read methods: the client refuses any other method before sending anything.
- Redirects are not followed, so the token can't be sent to another host. TLS is verified by
  default.
- Rate limits apply to Test Connection and Find Endpoints. An audit log (`#__zabbix_audit`)
  records setting changes, mapping changes, tests and automatic matches.
- Portal pages are sent with `no-store` and `noindex`.
- Log file: `administrator/logs/com_zabbix.php` (warnings and errors; notices too with *Detailed
  log* on).

## Limitations

- **Tag-based permissions** of Zabbix user groups (problem tag filters) are not applied. Only host
  group permissions are.
- Widgets that take their hosts or items from another widget (Zabbix "widget references") show
  their own configured hosts or items, or all the user may see.
- Map-type and some other widgets are not drawn (see above).
- Value mappings are applied for *equals* rules only. Graph options such as stacking, axes,
  thresholds and overrides are not reproduced: graphs are simple line charts.
- The portal is read-only: no acknowledging, closing or commenting on problems.

## Development

```bash
composer install          # PHPUnit and test dependencies (not part of the package)
vendor/bin/phpunit        # unit tests: no Joomla and no Zabbix server needed
build/build-package.sh    # dist/com_zabbix-<version>.zip
python3 build/verify-package.py dist/com_zabbix-<version>.zip <version>
```

The GitHub workflow (`.github/workflows/release.yml`) lints, tests, builds and verifies the
package on every push and pull request. When a release `v<version>` is published, it attaches
`com_zabbix.zip`, `updates.xml` and `changelog.xml` to it.

### Layout

```
zabbix.xml, script.php                     manifest and installer script
administrator/
  services/provider.php                    service container
  src/Api/                                 JSON-RPC client (Bearer token), cURL transport, errors
  src/Access/                              per-user rights (AccessScope, AccessResolver, AccessService)
  src/Identity/                            user mappings, matching, Zabbix user directory
  src/Monitoring/                          hosts, problems, history/trends, access guard, value formatting
  src/Dashboard/                           dashboard layout, widget fields, widget data
  src/Discovery/                           Find Endpoints (EndpointProbe, ApiRules)
  src/Settings/, src/Security/             settings, validation, KeyVault encryption, rate limiter
  src/Controller|Model|View/, tmpl/        Integration Settings, User Mappings, Find Endpoints
site/
  src/Controller/                          pages and JSON data (task=data.widget, data.history)
  src/Model|View/, tmpl/                   Zabbix Dashboard, Zabbix Hosts, host details
media/                                     CSS and JavaScript (SVG charts, no external libraries)
tests/                                     PHPUnit tests with a fake Zabbix API
```

## License

GNU General Public License version 2 or later, see [LICENSE](LICENSE).
