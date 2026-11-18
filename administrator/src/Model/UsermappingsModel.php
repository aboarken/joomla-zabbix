<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\UserMapping;

final class UsermappingsModel extends ListModel
{
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        $config['filter_fields'] ??= [
            'status', 'm.status', 'source', 'm.source',
            'u.name', 'm.zabbix_username', 'm.matched_at', 'm.checked_at',
        ];

        parent::__construct($config, $factory);

        $this->filterFormName = 'filter_usermappings';
    }

    protected function populateState($ordering = 'm.checked_at', $direction = 'DESC')
    {
        parent::populateState($ordering, $direction);
    }

    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.search') . ':' . $this->getState('filter.status') . ':' . $this->getState('filter.source');

        return parent::getStoreId($id);
    }

    /**
     * Mappings joined with their Joomla users. The status filter "none" lists Joomla users that have
     * no mapping yet (never logged in to the portal, or not matched), to report unmatched users.
     */
    protected function getListQuery(): QueryInterface
    {
        $db     = $this->getDatabase();
        $status = (string) $this->getState('filter.status');
        $query  = $db->createQuery();

        if ($status === 'none') {
            $query->select([
                $db->quoteName('u.id', 'joomla_user_id'),
                'NULL AS ' . $db->quoteName('zabbix_userid'),
                'NULL AS ' . $db->quoteName('zabbix_username'),
                $db->quoteName('u.email', 'email'),
                "'' AS " . $db->quoteName('status'),
                "'' AS " . $db->quoteName('source'),
                'NULL AS ' . $db->quoteName('matched_at'),
                'NULL AS ' . $db->quoteName('checked_at'),
                $db->quoteName('u.name'),
                $db->quoteName('u.username'),
                $db->quoteName('u.email', 'joomla_email'),
            ])
                ->from($db->quoteName('#__users', 'u'))
                ->join('LEFT', $db->quoteName('#__zabbix_usermap', 'm'), $db->quoteName('m.joomla_user_id') . ' = ' . $db->quoteName('u.id'))
                ->where($db->quoteName('m.joomla_user_id') . ' IS NULL')
                ->where($db->quoteName('u.block') . ' = 0');
        } else {
            $query->select([
                'm.*',
                $db->quoteName('u.name'),
                $db->quoteName('u.username'),
                $db->quoteName('u.email', 'joomla_email'),
            ])
                ->from($db->quoteName('#__zabbix_usermap', 'm'))
                ->join('LEFT', $db->quoteName('#__users', 'u'), $db->quoteName('u.id') . ' = ' . $db->quoteName('m.joomla_user_id'));

            if (\in_array($status, UserMapping::STATUSES, true)) {
                $query->where($db->quoteName('m.status') . ' = :status')->bind(':status', $status);
            }

            $source = (string) $this->getState('filter.source');

            if (\in_array($source, UserMapping::SOURCES, true)) {
                $query->where($db->quoteName('m.source') . ' = :source')->bind(':source', $source);
            }
        }

        $search = trim((string) $this->getState('filter.search'));

        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $or   = [
                $db->quoteName('u.name') . ' LIKE :s1',
                $db->quoteName('u.username') . ' LIKE :s2',
                $db->quoteName('u.email') . ' LIKE :s3',
            ];

            $query->bind(':s1', $like)->bind(':s2', $like)->bind(':s3', $like);

            if ($status !== 'none') {
                $or[] = $db->quoteName('m.zabbix_username') . ' LIKE :s4';
                $query->bind(':s4', $like);
            }

            $query->where('(' . implode(' OR ', $or) . ')');
        }

        $ordering  = (string) $this->getState('list.ordering', 'm.checked_at');
        $direction = strtoupper((string) $this->getState('list.direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        if ($status === 'none') {
            $ordering = 'u.name';
        } elseif (!\in_array($ordering, ['u.name', 'm.zabbix_username', 'm.matched_at', 'm.checked_at', 'm.status'], true)) {
            $ordering = 'm.checked_at';
        }

        $query->order($db->quoteName($ordering) . ' ' . $direction);

        return $query;
    }
}
