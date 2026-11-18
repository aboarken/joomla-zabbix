<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Settings;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Reads and writes #__zabbix_settings (name/value rows). Unknown names are ignored.
 */
final class SettingsRepository
{
    private const TABLE = '#__zabbix_settings';

    private ?Settings $cache = null;

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function load(): Settings
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $query = $this->db->createQuery()
            ->select($this->db->quoteName(['name', 'value']))
            ->from($this->db->quoteName(self::TABLE));

        $values = [];

        foreach ($this->db->setQuery($query)->loadObjectList() as $row) {
            if (\array_key_exists($row->name, Settings::DEFAULTS)) {
                $values[$row->name] = (string) $row->value;
            }
        }

        return $this->cache = new Settings($values);
    }

    /**
     * Upsert the given settings in one transaction.
     *
     * @param  array<string, string>  $values
     */
    public function save(array $values): void
    {
        $values = array_intersect_key($values, Settings::DEFAULTS);

        if ($values === []) {
            return;
        }

        $this->db->transactionStart();

        try {
            $delete = $this->db->createQuery()
                ->delete($this->db->quoteName(self::TABLE))
                ->whereIn($this->db->quoteName('name'), array_keys($values), ParameterType::STRING);
            $this->db->setQuery($delete)->execute();

            foreach ($values as $name => $value) {
                $row = (object) ['name' => $name, 'value' => (string) $value];
                $this->db->insertObject(self::TABLE, $row);
            }

            $this->db->transactionCommit();
        } catch (\Throwable $e) {
            $this->db->transactionRollback();

            throw $e;
        }

        $this->cache = null;
    }
}
