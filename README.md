# Joomla Zabbix (com_zabbix)

`com_zabbix` is a Joomla 6 component that brings **Zabbix 7.4** into your Joomla site through the
Zabbix JSON-RPC API. Users log in to Joomla and see Zabbix **dashboards** and **hosts**, drawn by
Joomla with live data. Each user sees only what their own Zabbix user may see.

Zabbix stays the single source of truth. Joomla only reads and displays: the component calls
read methods (`*.get`) only and never changes anything in Zabbix.

## Features

### Administrator: three menus (Components → Zabbix)

| Menu | What it does |
|---|---|
| **Integration Settings** | The Zabbix address, an optional **Port** and **Connect via**, and the **API token**. The token is encrypted at rest (AES-256-GCM) and never shown again. Also: TLS verification and a custom CA bundle, timeouts, *Open in Zabbix* links, user-matching rules, dashboard refresh, cache, date/time format and time zone. **Test Connection** checks the Zabbix version and the token's user. **Clear Cache** empties the cache. |
| **User Mappings** | Links each Joomla user to one Zabbix user. Users are matched **automatically** by username and/or e-mail (from the Zabbix user's media) on their first visit (or with **Rematch**). Matches are approved automatically or **wait for your approval** (Integration Settings → *New users*); a notice counts the users waiting. Mappings can also be set by hand. Actions: Confirm (approve), Suspend, Rematch, Remove. A user's edit page shows **what that user will see**: role, readable and denied host groups, and dashboards. |
| **Find Endpoints** | API discovery and diagnostics. It shows the server version and response time, the token's user, role and the role's API rules (warning if the role allows write methods), and each API method the portal uses with its result: *allowed*, *denied* or *error*. It also shows what the token can see (host groups, hosts, templates, users, dashboards, problems) and every dashboard with the widget types it uses, marking those the portal does not draw. Ends with a **Copy diagnostics** button (no secrets). |

### Site: two menu item types (Menus → Add New Menu Item → Zabbix)

| Menu item type | What the user sees |
|---|---|
| **Zabbix Dashboard** | A Zabbix dashboard drawn in Joomla on Zabbix's own grid (72 columns, 70 px rows; stacked on phones), with live data. It has page tabs, a period selector, auto-refresh and an *Open in Zabbix* link. Menu options: a fixed dashboard (or the first the user may see), whether users may switch between the dashboards shared with them, and the refresh interval. |
| **Zabbix Hosts** | Summary cards (hosts, available, not available, with problems, in maintenance). Filters: search, host group, status, availability and problems. A table with groups, availability per interface type (ZBX/SNMP/IPMI/JMX) and problem counts per severity. Each host opens a **details page** with interfaces, groups, tags, templates, inventory, current problems and latest data. Every numeric item has a **chart** for 1 h to 30 days. |

### Dashboard widgets drawn by the portal

| Zabbix widget | Shown as |
|---|---|
| Problems | Table: time, host, problem (in its severity's colour), duration, acknowledged, tags |
| Problems by severity | Coloured totals per severity, optionally per host group; click a severity to list its problems and devices |
| Host availability | Coloured boxes or a table of available / not available / mixed / unknown hosts; click a count to list those devices |
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
   sees a "not linked" page, or a "waiting for approval" page (see *New users* below).
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
4. **User Mappings**: users are matched on their first visit to the portal. Fix *Ambiguous* or
   *Not found* users by editing them, or add a mapping in advance with **New**. Open a user to see
   exactly what they will see. With *Wait for administrator approval*, select the users *Waiting
   for approval* and click **Confirm**.
5. **Options → Permissions**: set *View Zabbix Dashboard* and/or *View Zabbix Hosts* to *Allowed*
   for the groups that should use the portal (e.g. *Registered*). Nothing is allowed by default.
6. **Menus**: add menu items of type **Zabbix → Zabbix Dashboard** and **Zabbix → Zabbix Hosts**.
   Give them the access level *Registered*, so only logged-in users see them in the menu.

### New users: approve automatically or wait for approval

*Integration Settings → User mappings → New users* decides what happens when a Joomla user is
matched to a Zabbix user automatically (on their first visit, or with
**Rematch**):

- **Approve automatically** (default): the match works at once.
- **Wait for administrator approval**: the match is stored as *Waiting for approval*. The user
  sees a page saying their account is waiting, and nothing from Zabbix, until you approve it:
  **User Mappings** shows how many users are waiting, with a link to them; select them and click
  **Confirm**. Open a user first to see what they will be able to see.

Choose *Wait for administrator approval* where users can set their own Joomla username or e-mail,
since someone could enter a colleague's before that colleague's first visit. While a user is
waiting:

- if their Joomla e-mail changes, the waiting match is suspended;
- no Zabbix calls are made for them;
- if you switch back to *Approve automatically*, they are matched again and approved on their
  next visit.

Mappings you make or confirm yourself are never held.

### Look and full-width pages (Helix Ultimate and other templates)

The portal pages have their own design, the same as the ServiceDesk Plus and ZKBioTime portals
(cards, panels, tables, pills), so they look the same on any template and any Bootstrap version.
Fonts and link colours come from the template. The component loads no Bootstrap and no jQuery.
The pages keep a little space on the left and right, also in a full-width template row.

Colours are Zabbix 7.4's own: problems and severities use the severity colours set in your Zabbix
(*Administration → General → Trigger displaying options*, read with `settings.get` and kept for
the cache time; Zabbix's defaults if they cannot be read) with Zabbix's dark text; host
availability, gauges, clocks, change indicators and graph and pie chart colours (shades of a data
set's colour or Zabbix's palettes) are those Zabbix uses.

Dashboard widgets are drawn as Zabbix 7.4 draws them, with each widget's own settings: Top hosts
bars and indicators with threshold colours and decimal places, Item value positions, sizes,
colours, thresholds and sparkline, Gauge thresholds, labels and needle, Clock time zone and
parts, coloured count boxes, and values formatted as in Zabbix (*58.19 %*, *42.00 °C*,
*64 days, 01:54:51*, *1h 38m 51s*, *available (1.00)*). Symptom problems are left out unless a
Problems widget shows them, as in Zabbix. *System information* shows what the API can tell
(frontend version and the numbers of hosts, templates, items, triggers and users); whether the
Zabbix server is running and its version are not available through the API.

To show the portal over the **whole page width** in Helix Ultimate:

1. *System → Site Template Styles*: duplicate your Helix style and open the copy (e.g. *Helix -
   Full width*).
2. *Template Options → Layout*: open the settings of the **section** (row) that holds the
   component, usually *Main Body* (its own settings icon, not the column's), and switch on
   **Full Width** (called *Fluid* in some Helix versions). Make sure no module column sits next to
   the component there. Save.
3. Open the Zabbix Dashboard (and Zabbix Hosts) menu item and choose that style under **Template
   Style**. Only those pages change.

Setting the component column to 12 is not enough: the row itself stays in Helix's fixed-width
container until the section is full width.

### Port and Connect via (Zabbix hosted locally)

Both are optional and can be used together:

- **Port**: use it when Zabbix is published on another port than 443 (https) or 80 (http). For
  example, with the address `https://zabbix.example.org` and Port `8443`, the portal calls
  `https://zabbix.example.org:8443/api_jsonrpc.php`. The *Open in Zabbix* links use the same
  port.
- **Connect via**: use it when Zabbix runs on your local network and the Joomla server reaches it
  from outside through another address, for example a public IP with port forwarding. Enter
  `host`, `host:port` or `IP:port`, e.g. `203.0.113.10:8443`. Without a port, the port of the
  Zabbix address is used. Joomla opens the connection to that address but keeps the host name of
  the Zabbix address for the TLS certificate check and the HTTP Host header, so the certificate
  issued for that name stays valid.

Example: Zabbix is `https://zabbix.school.local` on your network, and your router forwards
public port 8443 to it. Set the Zabbix address to `https://zabbix.school.local` and Connect via
to `<public IP>:8443`. The status box and Find Endpoints show the Connect via address in use.

If the users' browsers reach Zabbix at a different address than the Joomla server does, set
*Zabbix address for links* as well.

### Encryption secret

The API token is encrypted with a key derived from:

1. the environment variable `ZABBIX_PORTAL_SECRET` (at least 32 characters, recommended), or
2. the `secret` in Joomla's `configuration.php`.

If you change the secret, the stored token can no longer be decrypted. Enter the token again.

## Caching

The portal keeps a few slow-changing Zabbix answers in the component's own table
`#__zabbix_cache`, for *Cache (minutes)* (5 by default; 0 = read on every page): the Zabbix user
list, each user's rights, host group names and dashboard definitions. A change of rights or
dashboard sharing in Zabbix therefore reaches the portal within that time, or at once with
**Clear Cache**. Saving the settings also empties the cache.

Live data (problems, item values, hosts, charts) is never cached: it is read from Zabbix on every
page load and widget refresh.

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
- Portal pages are sent with `no-store` and `noindex`. They work with a strict Content Security
  Policy (no inline scripts that run, no inline styles).
- Portal models check login and the page's permission themselves, as well as the controller.
- Values that come from Zabbix (names, widget settings, colours) are escaped or validated before
  they reach the page.
- Audit log entries are kept for 365 days.
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
- **Zabbix user role restrictions on the UI** (e.g. a role that hides *Monitoring → Hosts* or
  dashboards) are not applied. Use the two Joomla permissions to decide who may open which page.
- There is no rate limit on the portal's own data requests: each widget refresh is a few Zabbix
  API calls made for a logged-in, permitted user.
- **Personal data**: the cache table holds Zabbix usernames, names and e-mail addresses (from
  user media), and the mapping table holds each mapped user's e-mail address. Mappings of deleted
  Joomla users stay until removed under User Mappings. There is no Joomla privacy plugin for
  export or removal requests.

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
