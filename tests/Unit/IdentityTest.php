<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\DirectoryUser;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\IdentityResolver;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\MappingException;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\MappingManager;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\UserMapping;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\UserMatcher;
use ZabbixPortal\Component\Zabbix\Tests\Support\ArrayDirectory;
use ZabbixPortal\Component\Zabbix\Tests\Support\ArrayLogger;
use ZabbixPortal\Component\Zabbix\Tests\Support\MemoryAudit;
use ZabbixPortal\Component\Zabbix\Tests\Support\MemoryUserMapRepository;

final class IdentityTest extends TestCase
{
    private MemoryUserMapRepository $repo;

    private ArrayDirectory $directory;

    private MemoryAudit $audit;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->repo      = new MemoryUserMapRepository();
        $this->audit     = new MemoryAudit();
        $this->now       = new \DateTimeImmutable('2026-11-18 10:00:00', new \DateTimeZone('UTC'));
        $this->directory = new ArrayDirectory([
            new DirectoryUser('1', 'Admin', emails: ['admin@example.org'], roleType: 3),
            new DirectoryUser('2', 'alice', emails: ['alice@example.org']),
            new DirectoryUser('3', 'bob', emails: ['shared@example.org']),
            new DirectoryUser('4', 'carol', emails: ['shared@example.org', 'carol@example.org']),
        ]);
    }

    private function resolver(bool $auto = true, string $mode = 'username_email'): IdentityResolver
    {
        return new IdentityResolver($this->repo, fn () => $this->directory, $this->audit, new ArrayLogger(), $auto, $mode, fn () => $this->now);
    }

    public function testMatcher(): void
    {
        $m     = new UserMatcher();
        $users = $this->directory->users;

        self::assertSame('2', $m->match('ALICE', '', $users)[1]?->userid, 'Username, case-insensitive');
        self::assertSame('2', $m->match('someone', 'Alice@Example.org', $users)[1]?->userid, 'Then e-mail');
        self::assertSame(UserMatcher::AMBIGUOUS, $m->match('someone', 'shared@example.org', $users)[0]);
        self::assertSame(UserMatcher::NOT_FOUND, $m->match('someone', 'alice@example.org', $users, 'username')[0]);
        self::assertSame(UserMatcher::NOT_FOUND, $m->match('alice', 'nobody@example.org', $users, 'email')[0]);
        self::assertSame('4', $m->match('x', 'carol@example.org', $users, 'email')[1]?->userid);
    }

    public function testFirstVisitMatchesAndStoresAnActiveMapping(): void
    {
        $identity = $this->resolver()->resolve(10, 'alice', 'alice@example.org');

        self::assertTrue($identity->isMatched());
        self::assertSame('2', $identity->zabbixUser?->userid);
        self::assertSame(UserMapping::STATUS_ACTIVE, $this->repo->rows[10]->status);
        self::assertSame(UserMapping::SOURCE_AUTO, $this->repo->rows[10]->source);
        self::assertSame(['identity.match'], $this->audit->actions());
    }

    public function testAmbiguousIsNotMappedAndRetriedOnlyAfterAWhile(): void
    {
        $resolver = $this->resolver();

        self::assertFalse($resolver->resolve(10, 'x', 'shared@example.org')->isMatched());
        self::assertSame(UserMapping::STATUS_AMBIGUOUS, $this->repo->rows[10]->status);

        $reads = $this->directory->reads;
        $resolver->resolve(10, 'x', 'shared@example.org');
        self::assertSame($reads, $this->directory->reads, 'No new match within RETRY_MINUTES');

        $this->now = $this->now->modify('+11 minutes');
        $resolver->resolve(10, 'x', 'shared@example.org');
        self::assertGreaterThan($reads, $this->directory->reads);
    }

    public function testSuspendedMappingBlocks(): void
    {
        $this->repo->save(new UserMapping(10, '2', 'alice', 'alice@example.org', UserMapping::STATUS_SUSPENDED, UserMapping::SOURCE_ADMIN, null, $this->now));

        self::assertFalse($this->resolver()->resolve(10, 'alice', 'alice@example.org')->isMatched());
    }

    public function testAdministratorMappingIsNeverReplacedAutomatically(): void
    {
        $this->repo->save(new UserMapping(10, '9', 'gone', '', UserMapping::STATUS_ACTIVE, UserMapping::SOURCE_ADMIN, $this->now, $this->now->modify('-1 day')));

        $identity = $this->resolver()->resolve(10, 'alice', 'alice@example.org');

        self::assertFalse($identity->isMatched(), 'Zabbix user 9 does not exist');
        self::assertSame(UserMapping::STATUS_NOT_FOUND, $this->repo->rows[10]->status);
        self::assertSame('9', $this->repo->rows[10]->zabbixUserId, 'Not replaced by alice');
    }

    public function testWithoutAutoMatchNothingIsStored(): void
    {
        self::assertFalse($this->resolver(false)->resolve(10, 'alice', 'alice@example.org')->isMatched());
        self::assertSame([], $this->repo->rows);
    }

    public function testManagerAssignSuspendConfirmRemove(): void
    {
        $manager = new MappingManager($this->repo, fn () => $this->directory, $this->audit, 'username_email', fn () => $this->now);

        $manager->assign(10, 'x@example.org', '3', 99);
        self::assertSame('bob', $this->repo->rows[10]->zabbixUsername);
        self::assertSame(UserMapping::SOURCE_ADMIN, $this->repo->rows[10]->source);

        $manager->suspend(10, 99);
        self::assertSame(UserMapping::STATUS_SUSPENDED, $this->repo->rows[10]->status);

        $manager->confirm(10, 99);
        self::assertSame(UserMapping::STATUS_ACTIVE, $this->repo->rows[10]->status);

        $manager->remove(10, 99);
        self::assertArrayNotHasKey(10, $this->repo->rows);
        self::assertSame(['mapping.assign', 'mapping.suspend', 'mapping.confirm', 'mapping.remove'], $this->audit->actions());
        self::assertSame(99, $this->audit->records[0]['actor']);

        $this->expectException(MappingException::class);
        $manager->assign(10, '', '777', 99);
    }

    public function testAutoMatchAllLeavesActiveAndAdminMappingsAlone(): void
    {
        $manager = new MappingManager($this->repo, fn () => $this->directory, $this->audit, 'username_email', fn () => $this->now);
        $this->repo->save(new UserMapping(11, '3', 'bob', '', UserMapping::STATUS_ACTIVE, UserMapping::SOURCE_ADMIN, $this->now, $this->now));

        $counts = $manager->autoMatchAll([
            ['id' => 10, 'username' => 'alice', 'email' => ''],
            ['id' => 11, 'username' => 'alice', 'email' => ''],
            ['id' => 12, 'username' => 'nobody', 'email' => 'shared@example.org'],
            ['id' => 13, 'username' => 'nobody', 'email' => 'nobody@example.org'],
        ], 99);

        self::assertSame(['matched' => 1, 'pending' => 0, 'ambiguous' => 1, 'not_found' => 1, 'skipped' => 1], $counts);
        self::assertSame('bob', $this->repo->rows[11]->zabbixUsername);
    }

    public function testDirectoryUserFromApi(): void
    {
        $user = DirectoryUser::fromApi([
            'userid' => '5', 'username' => 'dave', 'name' => 'Dave', 'surname' => 'D',
            'medias'  => [['mediatypeid' => '1', 'sendto' => ['Dave@Example.org', 'not-an-email']], ['mediatypeid' => '3', 'sendto' => '+966500000000']],
            'usrgrps' => [['usrgrpid' => '7', 'users_status' => '0'], ['usrgrpid' => '9', 'users_status' => '1']],
            'role'    => ['roleid' => '2', 'name' => 'Admin role', 'type' => '2'],
        ]);

        self::assertSame(['dave@example.org'], $user->emails);
        self::assertSame(['7', '9'], $user->groupIds);
        self::assertTrue($user->disabled);
        self::assertSame(2, $user->roleType);
        self::assertEquals($user, DirectoryUser::fromArray($user->toArray()));
    }

    public function testMappingValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UserMapping(1, null, null, '', UserMapping::STATUS_ACTIVE, UserMapping::SOURCE_AUTO, null, $this->now);
    }
}
