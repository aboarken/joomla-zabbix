# Audit report: com_zabbix 1.3.16 → 1.4.0

Date: 2026-11-23. Scope: the whole repository at 1.3.16 (193 files, about 16,000 lines: PHP 8.3,
JavaScript, SQL for MySQL and PostgreSQL, XML manifests, build scripts, GitHub workflow).

## 1. Executive summary

`com_zabbix` is a read-only Joomla 6 portal for Zabbix: each Joomla user is linked to a Zabbix
user and sees that user's dashboards, hosts and problems, drawn in Joomla from the Zabbix API with
one API token. The code base is in good shape: a clear service structure, pure classes with unit
tests, consistent output escaping, bound SQL, CSRF tokens on every write and on every data
request, and an API client that cannot call write methods.

The audit found **one High**, **four Medium** and several **Low** issues. All of them were
fixed, except the items in section 10. The most important ones:

- **High**: automatic matching could link a self-registered Joomla user to a Zabbix Super admin,
  or to a colleague's Zabbix user via a changed e-mail address.
- **Medium**:
  - the stored API token kept being sent after an administrator changed the Zabbix address;
  - Zabbix problem tag filters were not applied;
  - a trigger spanning several hosts was shown when only one was readable;
  - two client-side races could show wrong or stale data.

No Critical issue was found. This does not mean the application is free of vulnerabilities:
see section 10.

## 2. Architecture assessment

See [ARCHITECTURE.md](ARCHITECTURE.md).

**Joomla 6 MVC.** The component follows Joomla 6 MVC:
- a service provider with a DI container;
- `MVCComponent` with a router;
- a dispatcher with an access check;
- the Web Asset Manager;
- language files, `access.xml`, and SQL install and update files for MySQL and PostgreSQL.

**Domain logic** lives in small services:
- `Api`, `Access`, `Identity`, `Monitoring`, `Dashboard`, `Settings`, `Security`;
- the rules (validation, matching, rights, value formatting, colours, layout) are pure classes,
  covered by unit tests that run without Joomla or Zabbix.

**Strengths:**
- every request from the browser is re-checked against the user's scope on the server;
- the token is encrypted with AES-256-GCM and never leaves the server;
- the API client refuses write methods locally;
- there are no third-party runtime dependencies.

**Weaknesses:**
- `WidgetDataService` is large (about 1000 lines, one method per widget type). It is cohesive,
  so it was left as is.
- `DataController::send()` writes JSON and closes the application instead of using Joomla's
  `JsonResponse`/`JsonDocument`. It works, sets explicit headers, and was left unchanged. Moving
  to `format=json` with `JsonDocument` is a possible clean-up.
- `access.xml` declares `core.manage`, but every back-end page requires `core.admin`. A user
  with Manage only sees the menu entry and gets a 403. This is intended: the pages are
  configuration.

## 3. Documentation and standards checked

**Joomla:**
- [Joomla Manual](https://manual.joomla.org/): component structure, dispatcher, MVC, forms,
  Web Asset Manager, language files, update servers;
- the Joomla 5/6 database API (`createQuery()`, `bind()`, `whereIn()`).

**Zabbix**, checked against the release/7.4 source as well as the documentation:
- the [API manual](https://www.zabbix.com/documentation/current/en/manual/api) and the methods
  the component uses;
- `CDashboard` (accepted sort fields);
- `CTrigger` (a trigger needs read permission on all its hosts);
- `CProblem::addTagFilterSqlParts()` and `CEvent::getTagFilters()` (tag-based permissions);
- `uniqTagFilters()`;
- `CUser` (media and `sendto`);
- `CEmailValidator` (addresses with a display name).

**OWASP:**
- [Top 10 (2021)](https://owasp.org/Top10/): A01, A02, A03, A05, A07, A08, A09;
- [ASVS 4.0.3](https://owasp.org/www-project-application-security-verification-standard/):
  V2–V5, V7, V8, V13, V14.

**Browser standards:**
- [WAI-ARIA Authoring Practices: Tabs](https://www.w3.org/WAI/ARIA/apg/patterns/tabs/);
- [`AbortSignal.timeout()`](https://developer.mozilla.org/docs/Web/API/AbortSignal/timeout_static);
- [PHP `#[\SensitiveParameter]`](https://www.php.net/manual/en/class.sensitiveparameter.php).

## 4. Design and implementation findings

| # | Finding | Status |
|---|---|---|
| D1 | Charts: `history.get` read the *oldest* rows when the limit was reached, so the newest data was missing. | Fixed (newest first, sorted for drawing). |
| D2 | Graph colours were matched by position after non-numeric items had been removed, so lines took the wrong colours. | Fixed (matched by item). |
| D3 | Host availability and Top hosts read problems (`problem.get` + `trigger.get`) they never show. | Fixed. |
| D4 | Top hosts: with several items of the same name on a host, the value shown was whichever Zabbix listed last. | Fixed (oldest item). |
| D5 | Widget height could reach past the grid. | Fixed. |
| D6 | `EndpointsController` used the deprecated `getQuery(true)`. | Fixed (`createQuery()`). |
| D7 | A reviewer suspected `dashboard.get` rejects `sortfield: name`. | Not an issue: `CDashboard::$sortColumns` is `['dashboardid', 'name']`. |
| D8 | `unixtime` values are shown in UTC; Zabbix uses the user's time zone. | Open (Low), documented. |
| D9 | Problem counts come from at most 2000 recent problems. | Open (Low), documented. |

## 5. Security assessment

| # | Severity | Finding | Affected | Status |
|---|---|---|---|---|
| S1 | **High** | **Identity takeover through automatic matching.** Matching compared the Joomla username and e-mail (both chosen by the user) with Zabbix and approved the match at once by default. With open registration, `admin` matched Zabbix's Super admin (full view). A user could also change their e-mail to a colleague's address and be matched to the colleague 10 minutes later. | `Identity/IdentityResolver.php`, `MappingManager.php`, `UserMatcher.php` | **Fixed.** Super admins, and Zabbix users already linked to another Joomla user, are never matched automatically (`UserMatcher::matchFor()`, `UserMapRepositoryInterface::linkedTo()`). Warnings when registration is open with automatic approval. Tests: `IdentityTest`. |
| S2 | Medium | **Stored token sent to a new destination.** An administrator with `core.admin` on this component (not necessarily a Super User) could change the address, Connect via, TLS check or CA file, keep the token field empty, and receive the token. | `Settings/SettingsValidator.php`, `Controller/SettingsController.php` | **Fixed.** The token must be entered again when the destination changes. The audit log records the changed fields and hosts. Tests: `SettingsTest`. |
| S3 | Medium | **Problem tag filters not applied.** A Zabbix user limited to some problem tags saw all problems of the host group. | `Access/*`, `Monitoring/ProblemService.php` | **Fixed**, with the same rule as Zabbix's `CProblem`. Tests: `ProblemPermissionTest`. |
| S4 | Medium | **Multi-host triggers.** A problem was shown when any of the trigger's hosts was readable, which leaked other hosts' names and problems. | `Monitoring/ProblemService.php` | **Fixed** (every host must be readable, as `CTrigger`). Tests: `ProblemPermissionTest`. |
| S5 | Low | System information counted hosts in denied groups. | `Dashboard/WidgetDataService.php` | Fixed. Test: `MonitoringTest`. |
| S6 | Low | The dashboard menu-item field listed private dashboard names to users who may only edit menu items. | `Field/ZabbixdashboardField.php` | Fixed (public only without `core.admin`). |
| S7 | Low | The CA bundle field accepted any readable file, which allowed probing whether files exist. | `SettingsValidator.php` | Fixed (PEM certificate file required). Test: `SettingsTest`. |
| S8 | Low | The token could appear in stack-trace arguments where `zend.exception_ignore_args` is off. | `Api/*`, `IntegrationsettingsModel.php`, `SettingsValidator.php` | Fixed (`#[\SensitiveParameter]`, checked on PHP 8.3). |
| S9 | Low | Unexpected errors in the data controller's scope check could reach Joomla's error page. | `site/src/Controller/DataController.php` | Fixed (JSON error). |
| S10 | Low | JSON robustness: no depth limit, and an array-valued `error.data` raised a warning. | `Api/JsonRpcClient.php` | Fixed. |
| S11 | Low | `http://` and TLS-off are allowed. | Settings | Open by design: warnings are shown, TLS is on by default. |
| S12 | Low | The rate limit for Test Connection and Find Endpoints counts finished attempts, so parallel requests can exceed it. | `Security/RateLimiter.php` | Open (administrators only). |
| S13 | Info | The KeyVault format has no key id; changing Joomla's secret makes the token unreadable. | `Security/KeyVault.php` | Open, documented (enter the token again). |
| S14 | Info | `cache_key` uses a case-insensitive collation on MySQL. Current keys cannot collide. | SQL | Open. |
| S15 | Info | Mappings of deleted Joomla users stay until removed. | Identity | Open, documented. |

**Checked and found sound:**
- **Access control:**
  - back-end `core.admin` with CSRF on every task;
  - site login, view whitelist, ACL and mapping checks;
  - every host, item and dashboard id from a request re-checked (no IDOR found);
  - Deny wins;
  - disabled user groups.
- **Injection and XSS:**
  - SQL only with `bind()`/`quoteName()` and whitelisted ordering;
  - all template output escaped;
  - no `innerHTML`, `eval` or `document.write`;
  - config in `application/json` with `JSON_HEX_*`;
  - colours validated as `#RRGGBB`.
- **Redirects and transport:**
  - internal redirects only;
  - no followed redirects;
  - HTTP(S) only;
  - size and time limits.
- **Secrets:**
  - token encrypted with AES-256-GCM, a random nonce, HKDF and AAD;
  - never sent back to the form;
  - scrubbed from logs, errors and the audit log.
- **Browser:**
  - strict-CSP compatible;
  - `nosniff` and `no-store` on data.
- **Dependencies:** `composer audit` finds no advisories. There are no runtime dependencies.
- **CI:**
  - tests and the build run read-only;
  - publishing runs only from `main`;
  - actions are pinned to commits.

## 6. Performance and code quality

**Fixed:**
- D1 (history limit);
- D3 (two unneeded API calls per refresh for host widgets).

**Front end (fixed):**
- **Request timeout:** a hung request no longer stops refresh until the page is reloaded.
- **Stale refresh queues:** an older queue now stops when a newer refresh starts.
- **Host chart race:** fixed, with an abort of the previous request.
- **Back/forward cache:** auto-refresh restarts after the browser restores the page.

**Open (performance):**
- live data (problems, hosts) is not memoised across the widgets of one dashboard: each widget
  is its own request;
- graph data sets fetch and check items per set;
- Top hosts makes one `item.get` per column;
- System information makes about 10 count calls.

These are correct but repeat work. Grouping them (or a 15–30 s shared cache) would reduce load
on large dashboards.

## 7. Files

**Modified:**
- **Settings:**
  - `administrator/src/Settings/SettingsValidator.php`;
  - `administrator/src/Controller/SettingsController.php`;
  - `administrator/src/Model/IntegrationsettingsModel.php`;
  - `administrator/src/View/Integrationsettings/HtmlView.php`;
  - `administrator/tmpl/integrationsettings/default.php`.
- **Access:**
  - `administrator/src/Access/AccessScope.php`;
  - `AccessResolver.php`;
  - `AccessService.php`.
- **Identity:**
  - `administrator/src/Identity/UserMatcher.php`;
  - `IdentityResolver.php`;
  - `MappingManager.php`;
  - `UserMapRepositoryInterface.php`;
  - `DbUserMapRepository.php`.
- **Monitoring:**
  - `administrator/src/Monitoring/ProblemService.php`;
  - `HostService.php`;
  - `HistoryService.php`.
- **Dashboard:**
  - `administrator/src/Dashboard/WidgetDataService.php`;
  - `DashboardLayout.php`.
- **API:**
  - `administrator/src/Api/JsonRpcClient.php`;
  - `CurlTransport.php`;
  - `TransportInterface.php`.
- **Other back end:**
  - `administrator/src/Controller/EndpointsController.php`;
  - `UsermapController.php`;
  - `administrator/src/Field/ZabbixdashboardField.php`.
- **Language:** `administrator/language/en-GB/com_zabbix.ini`.
- **Site:**
  - `site/src/Controller/DataController.php`;
  - `site/tmpl/dashboard/default.php`;
  - `site/tmpl/host/default.php`.
- **Browser:**
  - `media/js/portal.js`;
  - `dashboard.js`;
  - `host.js`.
- **Tests:**
  - `tests/Support/Fakes.php`;
  - `tests/Unit/SettingsTest.php`;
  - `IdentityTest.php`;
  - `MonitoringTest.php`.
- **Release files:**
  - `zabbix.xml`;
  - `media/joomla.asset.json`;
  - `README.md`;
  - `CHANGELOG.md`.

**Created:**
- `tests/Unit/ProblemPermissionTest.php`;
- `docs/ARCHITECTURE.md`;
- `docs/AUDIT.md`;
- `administrator/sql/updates/{mysql,postgresql}/1.4.0.sql` (no schema change).

**Removed:** none.

## 8. Improvements explained

See the CHANGELOG entry for 1.4.0. The design choices:

- **S1:** the fix blocks the two takeover paths, without forcing every site to approve users by
  hand. The default *Approve automatically* still suits sites where Joomla accounts are created
  by administrators or come from a trusted directory. Sites with open registration get a warning.
- **S2:** the token must be entered again, instead of being cleared silently. An administrator
  who changes the address on purpose sees why, and nothing is sent until the token is there.
- **S3/S4:** the rules mirror Zabbix's SQL in `CProblem` and `CTrigger`, so the portal shows
  exactly what Zabbix shows. The scope cache key changed (`scope2:`), so the new rules apply at
  once after the update.

## 9. Tests performed

All of these were actually run, in this environment:

- **PHPUnit:** 119 tests, 861 assertions, all passing. New tests cover:
  - token re-entry;
  - the CA file;
  - Super admin and already-linked protection;
  - tag filters;
  - multi-host triggers;
  - System information with Deny;
  - newest history;
  - colours per item;
  - host widgets without problem calls.
- **Front end in Chromium (Playwright):**
  - the host-chart race: the old code drew the wrong item, the new code doesn't;
  - a hanging dashboard request: Refresh comes back after the timeout, the error is shown, and
    *Updated* is not set;
  - the Find Endpoints template, rendered in four states.
- **Static checks:**
  - `php -l` on every PHP file;
  - `node --check` on every script;
  - a pattern scan for dangerous functions, unescaped output and raw SQL;
  - language files parse, and every key used is defined (`LanguageTest`).
- **Dependencies and build:**
  - `composer audit`: no advisories;
  - the package built and verified (`verify-package.py`);
  - the Joomla `changelog.xml` generated.
- **PHP 8.3 check:** `#[\SensitiveParameter]` removes the value from stack traces.

**Not performed:**
- **PHPStan:** not available. Running a downloaded copy was not permitted in this environment.
- **Live tests:** none against a real Joomla 6 site or Zabbix server. The controllers, views and
  database classes are checked by reading and by `php -l` only.
- **Database engines:** the SQL was not run on MySQL or PostgreSQL.

## 10. Remaining risks

- **Tests:** controllers, views, templates and the database layer have no automated tests.
- **Open registration:** with automatic approval, someone can still claim the identity of a
  Zabbix user who is not a Super admin and has never used the portal, by registering that user's
  username or e-mail. The warnings say so; *Wait for administrator approval* removes it.
- **Not reproduced from Zabbix:**
  - Zabbix UI role restrictions (documented);
  - widget references.
- **Open findings:** D8, D9 and S11–S15.

## 11. Recommendations

1. Add integration tests on a Joomla 6 test site (controllers, ACL, SQL on MySQL and PostgreSQL).
2. Add PHPStan (level 6+) with Joomla stubs to CI.
3. A `user` plugin `onUserAfterDelete` that removes the deleted user's mapping.
4. Group the API calls of one dashboard (or a short shared cache for live data).
5. Record an *attempt* row before Test Connection and Find Endpoints call Zabbix (rate limit).
6. Show `unixtime` values in the user's time zone. Show "2000+" when problem counts are capped.
