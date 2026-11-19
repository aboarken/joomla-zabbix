<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\DirectoryUser;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\IdentityResolver;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\MappingManager;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\UserMapping;
use ZabbixPortal\Component\Zabbix\Administrator\Settings\Settings;
use ZabbixPortal\Component\Zabbix\Administrator\Settings\SettingsValidator;
use ZabbixPortal\Component\Zabbix\Tests\Support\ArrayDirectory;
use ZabbixPortal\Component\Zabbix\Tests\Support\ArrayLogger;
use ZabbixPortal\Component\Zabbix\Tests\Support\MemoryAudit;
use ZabbixPortal\Component\Zabbix\Tests\Support\MemoryUserMapRepository;

/**
 * Integration Settings → New users: Approve automatically / Wait for administrator approval.
 */
final class ApprovalTest extends TestCase
{
    private MemoryUserMapRepository $repo;

    private ArrayDirectory $directory;

    private MemoryAudit $audit;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->repo      = new MemoryUserMapRepository();
        $this->audit     = new MemoryAudit();
        $this->now       = new \DateTimeImmutable('2026-11-19 10:00:00', new \DateTimeZone('UTC'));
        $this->directory = new ArrayDirectory([new DirectoryUser('2', 'alice', emails: ['alice@example.org'])]);
    }

    private function resolver(bool $autoApprove): IdentityResolver
    {
        return new IdentityResolver($this->repo, fn () => $this->directory, $this->audit, new ArrayLogger(), true, 'username_email', fn () => $this->now, $autoApprove);
    }

    private function manager(bool $autoApprove): MappingManager
    {
        return new MappingManager($this->repo, fn () => $this->directory, $this->audit, 'username_email', fn () => $this->now, $autoApprove);
    }

    public function testWaitForApprovalHoldsANewMatch(): void
    {
        $identity = $this->resolver(false)->resolve(10, 'alice', 'alice@example.org');

        self::assertFalse($identity->isMatched());
        self::assertNull($identity->zabbixUser, 'Nothing of the Zabbix user until approved');
        self::assertSame('pending', $identity->status());
        self::assertSame(UserMapping::STATUS_PENDING, $this->repo->rows[10]->status);
        self::assertSame('2', $this->repo->rows[10]->zabbixUserId, 'The match found is kept for the administrator');
        self::assertNull($this->repo->rows[10]->matchedAt);
        self::assertSame(['identity.pending'], $this->audit->actions());
    }

    public function testWaitingUserCausesNoZabbixCallsUntilApproved(): void
    {
        $resolver = $this->resolver(false);
        $resolver->resolve(10, 'alice', 'alice@example.org');
        $reads = $this->directory->reads;

        $this->now = $this->now->modify('+1 day');
        self::assertSame('pending', $resolver->resolve(10, 'alice', 'alice@example.org')->status());
        self::assertSame($reads, $this->directory->reads);
    }

    public function testAdministratorConfirmApprovesAWaitingUser(): void
    {
        $this->resolver(false)->resolve(10, 'alice', 'alice@example.org');
        $this->manager(false)->confirm(10, 99);

        self::assertSame(UserMapping::STATUS_ACTIVE, $this->repo->rows[10]->status);
        self::assertSame(UserMapping::SOURCE_ADMIN, $this->repo->rows[10]->source);
        self::assertTrue($this->resolver(false)->resolve(10, 'alice', 'alice@example.org')->isMatched(), 'Confirmed mappings are never held');
    }

    public function testWaitingUserWithAChangedEmailIsSuspended(): void
    {
        $this->resolver(false)->resolve(10, 'someone', 'alice@example.org');
        $identity = $this->resolver(false)->resolve(10, 'someone', 'other@example.org');

        self::assertSame('suspended', $identity->status());
        self::assertContains('identity.suspended', $this->audit->actions());
    }

    public function testWaitingUsersAreApprovedOnTheirNextVisitOnceAutomaticApprovalIsBack(): void
    {
        $this->resolver(false)->resolve(10, 'alice', 'alice@example.org');

        $identity = $this->resolver(true)->resolve(10, 'alice', 'alice@example.org');

        self::assertTrue($identity->isMatched());
        self::assertSame(UserMapping::STATUS_ACTIVE, $this->repo->rows[10]->status);
    }

    public function testApproveAutomaticallyIsUnchanged(): void
    {
        self::assertTrue($this->resolver(true)->resolve(10, 'alice', 'alice@example.org')->isMatched());
    }

    public function testAutoMatchAllAndRematchRespectTheSetting(): void
    {
        $counts = $this->manager(false)->autoMatchAll([
            ['id' => 10, 'username' => 'alice', 'email' => ''],
            ['id' => 11, 'username' => 'nobody', 'email' => ''],
        ], 99);

        self::assertSame(['matched' => 0, 'pending' => 1, 'ambiguous' => 0, 'not_found' => 1, 'skipped' => 0], $counts);
        self::assertSame(UserMapping::STATUS_PENDING, $this->repo->rows[10]->status);

        // Users already waiting are left for the administrator.
        self::assertSame(1, $this->manager(false)->autoMatchAll([['id' => 10, 'username' => 'alice', 'email' => '']], 99)['skipped']);

        self::assertSame('pending', $this->manager(false)->rematch(12, 'alice', '', 99));
        self::assertSame('matched', $this->manager(true)->rematch(12, 'alice', '', 99));
    }

    public function testSetting(): void
    {
        self::assertTrue((new Settings())->autoApprove(), 'Default: approve automatically, as before');
        self::assertFalse((new Settings(['auto_approve' => '0']))->autoApprove());

        $validator = new SettingsValidator(static fn (): string => 'enc', static fn (): bool => true);
        $base      = ['zabbix_url' => 'https://z.example.org', 'connect_timeout' => '5', 'timeout' => '20', 'refresh_seconds' => '60', 'max_hosts' => '2000', 'cache_minutes' => '5'];

        self::assertSame('0', $validator->validate($base + ['auto_approve' => '0'], new Settings())['auto_approve']);
        self::assertSame('1', $validator->validate($base + ['auto_approve' => '1'], new Settings())['auto_approve']);
    }

    public function testPendingMappingNeedsAZabbixUser(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UserMapping(10, null, null, '', UserMapping::STATUS_PENDING, UserMapping::SOURCE_AUTO, null, $this->now);
    }
}
