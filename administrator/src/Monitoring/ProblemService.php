<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Monitoring;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use ZabbixPortal\Component\Zabbix\Administrator\Access\AccessScope;
use ZabbixPortal\Component\Zabbix\Administrator\Api\JsonRpcClient;

/**
 * Current (unresolved) trigger problems a portal user may see.
 *
 * problem.get returns no hosts, so the problems' triggers are read in one batched trigger.get
 * (selectHosts, selectHostGroups); problems on hosts outside the user's scope are dropped.
 *
 * Symptom problems (Zabbix 7 cause and symptom events) are left out unless asked for, as in
 * Zabbix's own Problems by severity and Problems widgets.
 */
final class ProblemService
{
    public const SEVERITIES = [0, 1, 2, 3, 4, 5];

    /** Most problems read for one page or widget. */
    public const MAX = 2000;

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(private readonly JsonRpcClient $client, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param  list<string>  $groupIds    widget / filter host groups ([] = all the user may see)
     * @param  list<string>  $hostIds     [] = any
     * @param  list<int>     $severities  [] = all
     * @param  int           $ack         0 = all, 1 = unacknowledged only, 2 = acknowledged only
     * @param  bool          $symptoms    also symptom problems (widget option Show symptoms)
     * @param  bool          $tags        read the problems' tags
     *
     * @return list<array<string, mixed>>  newest first
     */
    public function problems(
        AccessScope $scope,
        array $groupIds = [],
        array $hostIds = [],
        array $severities = [],
        int $limit = 100,
        int $ack = 0,
        bool $showSuppressed = false,
        bool $symptoms = false,
        bool $tags = false
    ): array {
        $groups = $scope->groupFilter($groupIds);

        if ($groups === []) {
            return [];
        }

        $params = [
            'output'    => ['eventid', 'objectid', 'clock', 'name', 'severity', 'acknowledged', 'suppressed'],
            'source'    => 0,
            'object'    => 0,
            'recent'    => false,
            'sortfield' => ['eventid'],
            'sortorder' => 'DESC',
            'limit'     => max(1, min(self::MAX, $limit * 2)),
        ];

        if ($groups !== null) {
            $params['groupids'] = $groups;
        }

        if ($hostIds !== []) {
            $params['hostids'] = array_values(array_map('strval', $hostIds));
        }

        $severities = array_values(array_intersect(array_map('intval', $severities), self::SEVERITIES));

        if ($severities !== [] && \count($severities) < 6) {
            $params['severities'] = $severities;
        }

        if ($ack === 1 || $ack === 2) {
            $params['acknowledged'] = $ack === 2;
        }

        if (!$showSuppressed) {
            $params['suppressed'] = false;
        }

        if (!$symptoms) {
            $params['symptom'] = false;
        }

        if ($tags) {
            $params['selectTags'] = ['tag', 'value'];
        }

        $problems = $this->client->call('problem.get', $params);
        $problems = \is_array($problems) ? $problems : [];

        if ($problems === []) {
            return [];
        }

        $triggers = $this->client->call('trigger.get', [
            'output'           => ['triggerid'],
            'triggerids'       => array_values(array_unique(array_map(static fn ($p): string => (string) ($p['objectid'] ?? ''), $problems))),
            'selectHosts'      => ['hostid', 'name'],
            'selectHostGroups' => ['groupid', 'name'],
            'preservekeys'     => true,
        ]);
        $triggers = \is_array($triggers) ? $triggers : [];
        $now      = ($this->clock)();
        $rows     = [];

        foreach ($problems as $problem) {
            $trigger = $triggers[(string) ($problem['objectid'] ?? '')] ?? null;

            if (!\is_array($trigger)) {
                continue;
            }

            $groupsOfTrigger = array_map(static fn ($g): string => (string) ($g['groupid'] ?? ''), (array) ($trigger['hostgroups'] ?? []));

            if (!$scope->allowsHostGroups($groupsOfTrigger)) {
                continue;
            }

            $clock  = (int) ($problem['clock'] ?? 0);
            $rows[] = [
                'eventid'      => (string) $problem['eventid'],
                'clock'        => $clock,
                'duration'     => max(0, $now - $clock),
                'severity'     => (int) ($problem['severity'] ?? 0),
                'name'         => (string) ($problem['name'] ?? ''),
                'acknowledged' => (string) ($problem['acknowledged'] ?? '0') === '1',
                'suppressed'   => (string) ($problem['suppressed'] ?? '0') === '1',
                'tags'         => array_values(array_map(
                    static fn ($t): array => ['tag' => (string) ($t['tag'] ?? ''), 'value' => (string) ($t['value'] ?? '')],
                    \is_array($problem['tags'] ?? null) ? $problem['tags'] : []
                )),
                'hosts'        => array_values(array_map(
                    static fn ($h): array => ['hostid' => (string) ($h['hostid'] ?? ''), 'name' => (string) ($h['name'] ?? '')],
                    (array) ($trigger['hosts'] ?? [])
                )),
                'groups'       => $scope->namedGroups(array_values(array_map(
                    static fn ($g): array => ['groupid' => (string) ($g['groupid'] ?? ''), 'name' => (string) ($g['name'] ?? '')],
                    (array) ($trigger['hostgroups'] ?? [])
                ))),
            ];

            if (\count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Problem counts per severity, in total and per host group.
     *
     * @param  list<array<string, mixed>>  $problems  rows from problems()
     *
     * @return array{totals: array<int, int>, groups: list<array{groupid: string, name: string, counts: array<int, int>}>}
     */
    public static function countBySeverity(array $problems, ?array $onlyGroups = null): array
    {
        $totals = array_fill(0, 6, 0);
        $groups = [];

        foreach ($problems as $problem) {
            $severity = max(0, min(5, (int) $problem['severity']));
            $totals[$severity]++;

            foreach ($problem['groups'] as $group) {
                if ($onlyGroups !== null && !\in_array($group['groupid'], $onlyGroups, true)) {
                    continue;
                }

                $groups[$group['groupid']] ??= ['groupid' => $group['groupid'], 'name' => $group['name'], 'counts' => array_fill(0, 6, 0)];
                $groups[$group['groupid']]['counts'][$severity]++;
            }
        }

        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return ['totals' => $totals, 'groups' => $groups];
    }
}
