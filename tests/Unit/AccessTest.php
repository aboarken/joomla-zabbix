<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZabbixPortal\Component\Zabbix\Administrator\Access\AccessResolver;
use ZabbixPortal\Component\Zabbix\Administrator\Access\AccessScope;
use ZabbixPortal\Component\Zabbix\Administrator\Access\AccessService;
use ZabbixPortal\Component\Zabbix\Administrator\Cache\MemoryCache;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\DirectoryUser;
use ZabbixPortal\Component\Zabbix\Tests\Support\FakeTransport;

final class AccessTest extends TestCase
{
    private static function user(int $role = 1, array $groups = ['7', '8'], bool $disabled = false): DirectoryUser
    {
        return new DirectoryUser('42', 'alice', 'Alice', 'A', ['alice@example.org'], $role, 'User role', $groups, $disabled);
    }

    public function testHighestPermissionCountsAndDenyWins(): void
    {
        $scope = (new AccessResolver())->resolve(self::user(), [
            ['usrgrpid' => '7', 'users_status' => '0', 'hostgroup_rights' => [['id' => '10', 'permission' => '2'], ['id' => '11', 'permission' => '3'], ['id' => '12', 'permission' => '2']]],
            ['usrgrpid' => '8', 'users_status' => '0', 'hostgroup_rights' => [['id' => '12', 'permission' => '0'], ['id' => '13', 'permission' => '3']]],
            // Not one of the user's groups: ignored.
            ['usrgrpid' => '9', 'users_status' => '0', 'hostgroup_rights' => [['id' => '99', 'permission' => '3']]],
        ]);

        self::assertSame(['10', '11', '13'], $scope->readGroupIds);
        self::assertSame(['12'], $scope->deniedGroupIds);
        self::assertFalse($scope->all());
        self::assertTrue($scope->allowsHostGroups(['10']));
        self::assertFalse($scope->allowsHostGroups(['10', '12']), 'A host also in a denied group is hidden');
        self::assertFalse($scope->allowsHostGroups(['99']));
        self::assertFalse($scope->allowsHostGroups([]));
    }

    public function testSuperAdminSeesEverything(): void
    {
        $scope = (new AccessResolver())->resolve(self::user(AccessScope::ROLE_SUPER_ADMIN), []);

        self::assertTrue($scope->all());
        self::assertNull($scope->groupFilter());
        self::assertSame(['5'], $scope->groupFilter(['5']));
        self::assertTrue($scope->allowsHostGroups(['123']));
    }

    public function testDisabledUserGroupDisablesTheUser(): void
    {
        $scope = (new AccessResolver())->resolve(self::user(), [
            ['usrgrpid' => '7', 'users_status' => '1', 'hostgroup_rights' => [['id' => '10', 'permission' => '3']]],
        ]);

        self::assertTrue($scope->disabled);
        self::assertSame([], $scope->groupFilter());
        self::assertFalse($scope->canSeeAnything());
        self::assertFalse($scope->allowsHostGroups(['10']));
        self::assertFalse($scope->canSeeDashboard(['private' => '0']));
    }

    public function testGroupFilterIntersectsWidgetGroupsWithRights(): void
    {
        $scope = new AccessScope('42', 'alice', 1, ['10', '11']);

        self::assertSame(['10', '11'], $scope->groupFilter());
        self::assertSame(['11'], $scope->groupFilter(['11', '12']));
        self::assertSame([], $scope->groupFilter(['12']), 'Nothing, not everything');
    }

    public function testDashboardVisibility(): void
    {
        $scope = new AccessScope('42', 'alice', 1, ['10'], [], ['7']);

        self::assertTrue($scope->canSeeDashboard(['private' => '0', 'userid' => '1']));
        self::assertTrue($scope->canSeeDashboard(['private' => '1', 'userid' => '42']));
        self::assertTrue($scope->canSeeDashboard(['private' => '1', 'userid' => '1', 'users' => [['userid' => '42', 'permission' => '2']]]));
        self::assertTrue($scope->canSeeDashboard(['private' => '1', 'userid' => '1', 'userGroups' => [['usrgrpid' => '7', 'permission' => '2']]]));
        self::assertFalse($scope->canSeeDashboard(['private' => '1', 'userid' => '1', 'users' => [['userid' => '43']], 'userGroups' => [['usrgrpid' => '8']]]));
    }

    public function testScopeSurvivesTheCache(): void
    {
        $scope = new AccessScope('42', 'alice', 2, ['10'], ['12'], ['7'], false);

        self::assertEquals($scope, AccessScope::fromArray($scope->toArray()));
    }

    public function testAccessServiceReadsTheUsersGroupsOnceAndCaches(): void
    {
        $fake = new FakeTransport(['usergroup.get' => [
            ['usrgrpid' => '7', 'users_status' => '0', 'hostgroup_rights' => [['id' => '10', 'permission' => '2']]],
        ]]);
        $service = new AccessService($fake->client(), new MemoryCache());

        $first  = $service->scopeFor(self::user(1, ['7']));
        $second = $service->scopeFor(self::user(1, ['7']));

        self::assertSame(['10'], $first->readGroupIds);
        self::assertEquals($first, $second);
        self::assertSame(['usergroup.get'], $fake->methods());
        self::assertSame(['7'], $fake->lastParams('usergroup.get')['usrgrpids']);
    }
}
