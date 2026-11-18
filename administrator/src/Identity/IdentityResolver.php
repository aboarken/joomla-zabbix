<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Identity;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Psr\Log\LoggerInterface;
use ZabbixPortal\Component\Zabbix\Administrator\Audit\AuditLogInterface;

/**
 * Finds the Zabbix user of the logged-in Joomla user, on every portal page.
 *
 * - An active mapping is used as long as its Zabbix user still exists.
 * - A suspended mapping always blocks the portal.
 * - Without a usable mapping, and with automatic matching on, the user is matched by username
 *   and/or e-mail (UserMatcher) and the result is stored; a failed match is tried again after
 *   RETRY_MINUTES. Mappings an administrator made are never replaced automatically.
 */
final class IdentityResolver
{
    public const RETRY_MINUTES = 10;

    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    /**
     * @param \Closure(): UserDirectoryInterface $directory  opened only when needed
     */
    public function __construct(
        private readonly UserMapRepositoryInterface $repository,
        private readonly \Closure $directory,
        private readonly AuditLogInterface $audit,
        private readonly LoggerInterface $logger,
        private readonly bool $autoMatch = true,
        private readonly string $matchMode = 'username_email',
        ?\Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * @throws \ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException when Zabbix is needed and unavailable
     */
    public function resolve(int $joomlaUserId, string $username, string $email): Identity
    {
        $mapping = $this->repository->find($joomlaUserId);

        if ($mapping !== null && $mapping->status === UserMapping::STATUS_SUSPENDED) {
            return new Identity($joomlaUserId, $mapping);
        }

        if ($mapping !== null && $mapping->isActive()) {
            $user = $this->directory()->find((string) $mapping->zabbixUserId);

            if ($user !== null) {
                return new Identity($joomlaUserId, $mapping, $user);
            }

            // The Zabbix user was deleted.
            $mapping = $mapping->with(['status' => UserMapping::STATUS_NOT_FOUND, 'checkedAt' => ($this->clock)()]);
            $this->repository->save($mapping);
            $this->audit->record($joomlaUserId, 'identity.lost', AuditLogInterface::RESULT_OK, 'Zabbix user ' . $mapping->zabbixUserId . ' no longer exists');
            $this->logger->notice(\sprintf('Joomla user %d: mapped Zabbix user no longer exists', $joomlaUserId));
        }

        if (!$this->autoMatch || ($mapping !== null && $mapping->source === UserMapping::SOURCE_ADMIN)) {
            return new Identity($joomlaUserId, $mapping);
        }

        $now = ($this->clock)();

        if ($mapping !== null && $mapping->checkedAt > $now->modify('-' . self::RETRY_MINUTES . ' minutes')) {
            return new Identity($joomlaUserId, $mapping);
        }

        [$result, $user] = (new UserMatcher())->match($username, $email, $this->directory()->all(), $this->matchMode);

        $mapping = new UserMapping(
            $joomlaUserId,
            $user?->userid,
            $user?->username,
            UserMatcher::normaliseEmail($email),
            match ($result) {
                UserMatcher::MATCHED   => UserMapping::STATUS_ACTIVE,
                UserMatcher::AMBIGUOUS => UserMapping::STATUS_AMBIGUOUS,
                default                => UserMapping::STATUS_NOT_FOUND,
            },
            UserMapping::SOURCE_AUTO,
            $user !== null ? $now : null,
            $now
        );

        $this->repository->save($mapping);
        $this->audit->record($joomlaUserId, 'identity.match', AuditLogInterface::RESULT_OK, $result);

        return new Identity($joomlaUserId, $mapping, $user);
    }

    private function directory(): UserDirectoryInterface
    {
        return ($this->directory)();
    }
}
