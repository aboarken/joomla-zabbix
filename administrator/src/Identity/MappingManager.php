<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Identity;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use ZabbixPortal\Component\Zabbix\Administrator\Audit\AuditLogInterface;

/**
 * Administrator actions on User Mappings. Every change is written to the audit log.
 */
final class MappingManager
{
    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    /**
     * @param \Closure(): UserDirectoryInterface $directory
     */
    public function __construct(
        private readonly UserMapRepositoryInterface $repository,
        private readonly \Closure $directory,
        private readonly AuditLogInterface $audit,
        private readonly string $matchMode = 'username_email',
        ?\Closure $clock = null,
        private readonly bool $autoApprove = true
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Map a Joomla user to a Zabbix user chosen by the administrator.
     *
     * @throws MappingException
     */
    public function assign(int $joomlaUserId, string $email, string $zabbixUserId, int $actorId): DirectoryUser
    {
        $user = $this->directory()->find($zabbixUserId);

        if ($user === null) {
            throw new MappingException('COM_ZABBIX_MAPPING_ERR_ZABBIX_USER');
        }

        $now = ($this->clock)();
        $this->repository->save(new UserMapping(
            $joomlaUserId,
            $user->userid,
            $user->username,
            UserMatcher::normaliseEmail($email),
            UserMapping::STATUS_ACTIVE,
            UserMapping::SOURCE_ADMIN,
            $now,
            $now,
            $actorId
        ));
        $this->audit->record($joomlaUserId, 'mapping.assign', AuditLogInterface::RESULT_OK, 'Zabbix user ' . $user->userid, $actorId);

        return $user;
    }

    /** Block the portal for this user (the mapping is kept). */
    public function suspend(int $joomlaUserId, int $actorId): void
    {
        $mapping = $this->existing($joomlaUserId);
        $this->repository->save($mapping->with([
            'status'     => UserMapping::STATUS_SUSPENDED,
            'source'     => UserMapping::SOURCE_ADMIN,
            'checkedAt'  => ($this->clock)(),
            'modifiedBy' => $actorId,
        ]));
        $this->audit->record($joomlaUserId, 'mapping.suspend', AuditLogInterface::RESULT_OK, '', $actorId);
    }

    /**
     * Make the stored Zabbix user active, as an administrator's choice: approves a user waiting
     * for approval, or lifts a suspension.
     *
     * @throws MappingException
     */
    public function confirm(int $joomlaUserId, int $actorId): void
    {
        $mapping = $this->existing($joomlaUserId);

        if ($mapping->zabbixUserId === null || $this->directory()->find($mapping->zabbixUserId) === null) {
            throw new MappingException('COM_ZABBIX_MAPPING_ERR_NOTHING_TO_CONFIRM');
        }

        $now  = ($this->clock)();
        $user = $this->directory()->find($mapping->zabbixUserId);
        $this->repository->save($mapping->with([
            'zabbixUsername' => $user?->username ?? $mapping->zabbixUsername,
            'status'         => UserMapping::STATUS_ACTIVE,
            'source'         => UserMapping::SOURCE_ADMIN,
            'matchedAt'      => $now,
            'checkedAt'      => $now,
            'modifiedBy'     => $actorId,
        ]));
        $this->audit->record($joomlaUserId, 'mapping.confirm', AuditLogInterface::RESULT_OK, '', $actorId);
    }

    /**
     * Match again by username / e-mail; the result becomes an automatic mapping. With "Wait for
     * administrator approval" a match is stored as pending, to be confirmed.
     *
     * @return string UserMatcher::MATCHED, AMBIGUOUS, NOT_FOUND or PROTECTED, or 'pending'
     */
    public function rematch(int $joomlaUserId, string $username, string $email, int $actorId): string
    {
        [$result, $user] = (new UserMatcher())->matchFor($joomlaUserId, $username, $email, $this->directory()->all(), $this->matchMode, $this->repository);

        // A protected Zabbix user (Super admin, or linked to someone else) is linked by hand only.
        if ($result === UserMatcher::PROTECTED) {
            $existing = $this->repository->find($joomlaUserId);

            // Already linked to that very user (by an administrator): nothing to redo.
            if ($existing !== null && $existing->isActive() && $existing->zabbixUserId === $user?->userid) {
                $this->audit->record($joomlaUserId, 'mapping.rematch', AuditLogInterface::RESULT_OK, 'kept', $actorId);

                return UserMatcher::MATCHED;
            }

            $user = null;
        }

        $now             = ($this->clock)();
        $pending         = $result === UserMatcher::MATCHED && !$this->autoApprove;

        $this->repository->save(new UserMapping(
            $joomlaUserId,
            $user?->userid,
            $user?->username,
            UserMatcher::normaliseEmail($email),
            match (true) {
                $pending                           => UserMapping::STATUS_PENDING,
                $result === UserMatcher::MATCHED   => UserMapping::STATUS_ACTIVE,
                $result === UserMatcher::AMBIGUOUS => UserMapping::STATUS_AMBIGUOUS,
                default                            => UserMapping::STATUS_NOT_FOUND,
            },
            UserMapping::SOURCE_AUTO,
            $user !== null && !$pending ? $now : null,
            $now,
            $actorId
        ));
        $this->audit->record($joomlaUserId, 'mapping.rematch', AuditLogInterface::RESULT_OK, $pending ? 'pending' : $result, $actorId);

        return $pending ? UserMapping::STATUS_PENDING : $result;
    }

    public function remove(int $joomlaUserId, int $actorId): void
    {
        $this->existing($joomlaUserId);
        $this->repository->delete($joomlaUserId);
        $this->audit->record($joomlaUserId, 'mapping.remove', AuditLogInterface::RESULT_OK, '', $actorId);
    }

    /** @throws MappingException */
    private function existing(int $joomlaUserId): UserMapping
    {
        return $this->repository->find($joomlaUserId) ?? throw new MappingException('COM_ZABBIX_MAPPING_ERR_NO_MAPPING');
    }

    private function directory(): UserDirectoryInterface
    {
        return ($this->directory)();
    }
}
