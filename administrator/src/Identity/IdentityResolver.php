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
 * - New users (Integration Settings → New users): with "Approve automatically" a match works at
 *   once. With "Wait for administrator approval" it is stored as "pending": the user sees nothing
 *   until an administrator confirms it in User Mappings. A pending user whose Joomla e-mail has
 *   changed since is suspended. Pending users are matched again, and approved, on their next
 *   visit once the setting is back to "Approve automatically".
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
        ?\Closure $clock = null,
        private readonly bool $autoApprove = true
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

        // Found automatically, waiting for an administrator.
        if ($mapping !== null && $mapping->status === UserMapping::STATUS_PENDING) {
            // Joomla users can change their own e-mail without proving it; a pending match made
            // with the old address is not kept for the new one.
            if ($mapping->email !== UserMatcher::normaliseEmail($email)) {
                $mapping = $mapping->with(['status' => UserMapping::STATUS_SUSPENDED, 'checkedAt' => ($this->clock)()]);
                $this->repository->save($mapping);
                $this->audit->record($joomlaUserId, 'identity.suspended', AuditLogInterface::RESULT_OK, 'joomla_email_changed');

                return new Identity($joomlaUserId, $mapping);
            }

            // Still waiting: no Zabbix call until an administrator confirms it, or the setting
            // is back to "Approve automatically" (then it is matched again below).
            if (!$this->autoApprove || !$this->autoMatch) {
                return new Identity($joomlaUserId, $mapping);
            }

            $mapping = null;
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
        $pending         = $result === UserMatcher::MATCHED && !$this->autoApprove;

        $mapping = new UserMapping(
            $joomlaUserId,
            $user?->userid,
            $user?->username,
            UserMatcher::normaliseEmail($email),
            match (true) {
                $pending                            => UserMapping::STATUS_PENDING,
                $result === UserMatcher::MATCHED    => UserMapping::STATUS_ACTIVE,
                $result === UserMatcher::AMBIGUOUS  => UserMapping::STATUS_AMBIGUOUS,
                default                             => UserMapping::STATUS_NOT_FOUND,
            },
            UserMapping::SOURCE_AUTO,
            $user !== null && !$pending ? $now : null,
            $now
        );

        $this->repository->save($mapping);
        $this->audit->record($joomlaUserId, $pending ? 'identity.pending' : 'identity.match', AuditLogInterface::RESULT_OK, $result . ($user !== null ? ' zabbix_user:' . $user->userid : ''));

        // A pending user is not linked yet: no Zabbix user until an administrator confirms it.
        return new Identity($joomlaUserId, $mapping, $pending ? null : $user);
    }

    private function directory(): UserDirectoryInterface
    {
        return ($this->directory)();
    }
}
