<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZabbixPortal\Component\Zabbix\Administrator\Access\AccessResolver;
use ZabbixPortal\Component\Zabbix\Administrator\Access\AccessScope;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\DirectoryUser;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\ProblemService;
use ZabbixPortal\Component\Zabbix\Tests\Support\FakeTransport;

/**
 * Problems are shown as Zabbix shows them to the user: a trigger only when every host it uses is
 * readable, and only problems the user groups' tag filters allow.
 *
 * Host 1 "web" is in group 10 (readable), host 4 "vault" in group 13 (no rights).
 */
final class ProblemPermissionTest extends TestCase
{
    private FakeTransport $fake;

    protected function setUp(): void
    {
        $hostGroups = ['1' => [['groupid' => '10']], '4' => [['groupid' => '13']]];
        $triggers   = [
            '100' => ['triggerid' => '100', 'hosts' => [['hostid' => '1', 'name' => 'web']], 'hostgroups' => [['groupid' => '10']]],
            '101' => ['triggerid' => '101', 'hosts' => [['hostid' => '1', 'name' => 'web']], 'hostgroups' => [['groupid' => '10']]],
            // Uses items of both hosts (e.g. last(/web/x) > last(/vault/y)).
            '400' => ['triggerid' => '400', 'hosts' => [['hostid' => '1', 'name' => 'web'], ['hostid' => '4', 'name' => 'vault']], 'hostgroups' => [['groupid' => '10'], ['groupid' => '13']]],
        ];

        $this->fake = new FakeTransport([
            'problem.get' => [
                ['eventid' => '3', 'objectid' => '400', 'clock' => '1', 'name' => 'Vault differs from web', 'severity' => '4', 'acknowledged' => '0', 'suppressed' => '0', 'tags' => [['tag' => 'service', 'value' => 'db']]],
                ['eventid' => '2', 'objectid' => '101', 'clock' => '1', 'name' => 'DB slow', 'severity' => '3', 'acknowledged' => '0', 'suppressed' => '0', 'tags' => [['tag' => 'service', 'value' => 'db']]],
                ['eventid' => '1', 'objectid' => '100', 'clock' => '1', 'name' => 'Web slow', 'severity' => '2', 'acknowledged' => '0', 'suppressed' => '0', 'tags' => [['tag' => 'service', 'value' => 'web']]],
            ],
            'trigger.get' => static fn (array $p): array => array_intersect_key($triggers, array_flip($p['triggerids'])),
            'host.get'    => static fn (array $p): array => array_values(array_map(
                static fn (string $id): array => ['hostid' => $id, 'hostgroups' => $hostGroups[$id] ?? []],
                array_values(array_intersect($p['hostids'] ?? [], array_keys($hostGroups)))
            )),
        ]);
    }

    /** @return list<string> */
    private function names(AccessScope $scope): array
    {
        return array_column((new ProblemService($this->fake->client(), static fn (): int => 10))->problems($scope), 'name');
    }

    public function testATriggerOnAHostTheUserCannotReadIsNotShown(): void
    {
        $user = new AccessScope('42', 'alice', AccessScope::ROLE_USER, ['10']);

        self::assertSame(['DB slow', 'Web slow'], $this->names($user), 'needs read access to every host of the trigger, as Zabbix');
        self::assertSame(['Vault differs from web', 'DB slow', 'Web slow'], $this->names(new AccessScope('1', 'Admin', AccessScope::ROLE_SUPER_ADMIN)));
    }

    public function testTagFiltersOfTheUserGroupsAreApplied(): void
    {
        // Zabbix: User groups → Problem tag filter: group 10 only with tag service=db.
        $scope = (new AccessResolver())->resolve(new DirectoryUser('42', 'alice', groupIds: ['7']), [[
            'usrgrpid'         => '7',
            'users_status'     => '0',
            'hostgroup_rights' => [['id' => '10', 'permission' => '2']],
            'tag_filters'      => [['groupid' => '10', 'tag' => 'service', 'value' => 'db']],
        ]]);

        self::assertSame(['DB slow'], $this->names($scope));
        self::assertContains('selectTags', array_keys($this->fake->lastParams('problem.get')), 'tags are read to apply the filter');

        // A filter with an empty value allows every value of the tag; an empty tag allows all.
        self::assertSame(['DB slow', 'Web slow'], $this->names(new AccessScope('42', 'alice', 1, ['10'], tagFilters: ['10' => [['tag' => 'service', 'value' => '']]])));
        self::assertSame(['DB slow', 'Web slow'], $this->names(new AccessScope('42', 'alice', 1, ['10'], tagFilters: ['10' => [['tag' => '', 'value' => '']]])));

        // As Zabbix's problem.get (CProblem::addTagFilterSqlParts): once the user's groups have
        // tag filters, only host groups named in them show problems. A filter on another host
        // group (11) does not open group 10.
        self::assertSame([], $this->names(new AccessScope('42', 'alice', 1, ['10', '11'], tagFilters: ['11' => [['tag' => 'service', 'value' => 'db']]])));

        // One trigger with one host: no extra host.get is needed to check its hosts.
        $this->fake->calls = [];
        $this->names(new AccessScope('42', 'alice', 1, ['10']));
        self::assertSame(1, \count(array_keys($this->fake->methods(), 'host.get', true)), 'only for the trigger that uses two hosts');
        self::assertSame(['1', '4'], $this->fake->lastParams('host.get')['hostids']);

        // The filters survive the scope cache.
        self::assertEquals($scope, AccessScope::fromArray($scope->toArray()));
    }
}
