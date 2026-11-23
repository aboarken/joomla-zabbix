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
use ZabbixPortal\Component\Zabbix\Administrator\Identity\ZabbixUserDirectory;
use ZabbixPortal\Component\Zabbix\Administrator\Cache\MemoryCache;
use ZabbixPortal\Component\Zabbix\Tests\Support\FakeTransport;
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

    public function testSuperAdminsAreNeverMatchedAutomatically(): void
    {
        // Someone registers the Joomla username "admin" (Zabbix's default Super admin).
        $identity = $this->resolver()->resolve(50, 'admin', 'attacker@example.net');

        self::assertNull($identity->zabbixUser, 'no Super admin view from a chosen username');
        self::assertSame(UserMapping::STATUS_NOT_FOUND, $this->repo->find(50)?->status);
        self::assertNull($this->repo->find(50)?->zabbixUserId);
        self::assertContains('identity.protected', $this->audit->actions());

        // Also by e-mail, and with Rematch.
        $manager = new MappingManager($this->repo, fn () => $this->directory, $this->audit, 'username_email', fn () => $this->now);
        self::assertSame(UserMatcher::PROTECTED, $manager->rematch(51, 'nobody', 'admin@example.org', 9));
        self::assertNull($this->repo->find(51)?->zabbixUserId);
    }

    public function testAZabbixUserLinkedToSomeoneIsNotTakenOverByChangingAnEmail(): void
    {
        // Alice's Joomla account (10) is linked to Zabbix user alice.
        self::assertSame('2', $this->resolver()->resolve(10, 'alice', 'alice@example.org')->zabbixUser?->userid);

        // Mallory (11), not found before, sets her Joomla e-mail to Alice's address.
        $identity = $this->resolver()->resolve(11, 'mallory', 'alice@example.org');

        self::assertNull($identity->zabbixUser, 'a linked Zabbix user is not matched to a second Joomla user');
        self::assertSame(UserMapping::STATUS_NOT_FOUND, $this->repo->find(11)?->status);

        // Alice herself still resolves to her Zabbix user.
        self::assertSame('2', $this->resolver()->resolve(10, 'alice', 'alice@example.org')->zabbixUser?->userid);

        // An administrator can still link Mallory's account by hand if that is right.
        $matcher = new UserMatcher();
        self::assertSame(UserMatcher::PROTECTED, $matcher->matchFor(11, 'mallory', 'alice@example.org', $this->directory->users, 'username_email', $this->repo)[0]);
        self::assertSame(UserMatcher::MATCHED, $matcher->matchFor(10, 'alice', 'alice@example.org', $this->directory->users, 'username_email', $this->repo)[0], 'not protected against its own link');
    }

    public function testEmailsWithADisplayNameAreMatched(): void
    {
        // Zabbix accepts "Name <address>" in an e-mail media's Send to.
        $user = DirectoryUser::fromApi([
            'userid' => '7', 'username' => 'amoussa',
            'medias' => [
                ['mediatypeid' => '1', 'sendto' => ['Ahmed Moussa <A.Moussa@Example.edu.sa>', ' second@example.com ']],
                ['mediatypeid' => '3', 'sendto' => '+966500000000'],
            ],
        ]);

        self::assertSame(['a.moussa@example.edu.sa', 'second@example.com'], $user->emails);
        self::assertSame([UserMatcher::MATCHED, $user], (new UserMatcher())->match('ahmed', 'a.moussa@example.edu.sa', [$user], 'username_email'));
        self::assertSame('a.moussa@example.edu.sa', UserMatcher::normaliseEmail('  "Moussa, Ahmed" <a.moussa@example.edu.sa> '));
        self::assertSame('dépôt@exemple.fr', UserMatcher::normaliseEmail('Dépôt@Exemple.fr'));
        self::assertSame('', UserMatcher::normaliseEmail('not an address'));
    }

    public function testAZabbixUsernameThatIsAnEmailIsMatchedByEmail(): void
    {
        // LDAP / SAML users: the username is the e-mail address, and there is no media.
        $sso   = DirectoryUser::fromApi(['userid' => '9', 'username' => 'A.Moussa@Example.edu.sa']);
        $other = DirectoryUser::fromApi(['userid' => '10', 'username' => 'someone']);

        self::assertSame([UserMatcher::MATCHED, $sso], (new UserMatcher())->match('amoussa', 'a.moussa@example.edu.sa', [$other, $sso], 'username_email'));
        self::assertSame([UserMatcher::MATCHED, $sso], (new UserMatcher())->match('amoussa', 'a.moussa@example.edu.sa', [$other, $sso], 'email'));
        self::assertSame([UserMatcher::NOT_FOUND, null], (new UserMatcher())->match('amoussa', 'a.moussa@example.edu.sa', [$other, $sso], 'username'));

        // Media and username naming the same Zabbix user: one candidate, not ambiguous.
        $both = DirectoryUser::fromApi(['userid' => '11', 'username' => 'b@example.com', 'medias' => [['sendto' => ['b@example.com']]]]);
        self::assertSame([UserMatcher::MATCHED, $both], (new UserMatcher())->match('bee', 'b@example.com', [$both], 'email'));
    }

    public function testDirectoryIsReadAgainAfterRefresh(): void
    {
        $transport = new FakeTransport(['user.get' => [['userid' => '1', 'username' => 'Admin']]]);
        $cache     = new MemoryCache();

        (new ZabbixUserDirectory($transport->client(), $cache))->all();
        (new ZabbixUserDirectory($transport->client(), $cache))->all();
        self::assertSame(['user.get'], $transport->methods(), 'cached');

        $transport->routes['user.get'] = [['userid' => '1', 'username' => 'Admin'], ['userid' => '2', 'username' => 'new.user']];
        $directory = new ZabbixUserDirectory($transport->client(), $cache);
        $directory->refresh();

        self::assertNotNull($directory->find('2'), 'a user just added in Zabbix is found after a refresh (Rematch)');
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
