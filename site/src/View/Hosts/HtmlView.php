<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Site\View\Hosts;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Pagination\Pagination;
use ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException;
use ZabbixPortal\Component\Zabbix\Site\Model\HostsModel;
use ZabbixPortal\Component\Zabbix\Site\View\PortalViewTrait;

/**
 * Zabbix Hosts: the hosts the user may see, with availability and current problems.
 */
final class HtmlView extends BaseHtmlView
{
    use PortalViewTrait;

    public const PER_PAGE = 50;

    /** @var array{group: string, search: string, status: string, availability: string, problems: string} */
    public array $filters = ['group' => '', 'search' => '', 'status' => '', 'availability' => '', 'problems' => ''];

    /** @var array<string, string> */
    public array $groups = [];

    /** @var list<array<string, mixed>> */
    public array $hosts = [];

    /** @var array<string, int> */
    public array $cards = [];

    public ?Pagination $pagination = null;

    public ?ZabbixException $error = null;

    public function display($tpl = null): void
    {
        /** @var HostsModel $model */
        $model = $this->getModel();
        $this->preparePage($model, 'hosts', 'COM_ZABBIX_HOSTS_TITLE');

        $input = Factory::getApplication()->getInput();
        $pick  = static fn (string $value, array $allowed): string => \in_array($value, $allowed, true) ? $value : '';

        $this->filters = [
            'group'        => (string) ($input->getUint('group', 0) ?: ''),
            'search'       => mb_substr(trim($input->getString('search', '')), 0, 100),
            'status'       => $pick($input->getCmd('status', ''), HostsModel::STATUSES),
            'availability' => $pick($input->getCmd('availability', ''), HostsModel::AVAILABILITIES),
            'problems'     => $pick($input->getCmd('problems', ''), HostsModel::PROBLEMS),
        ];

        try {
            $this->groups = $model->groups();

            if ($this->filters['group'] !== '' && !isset($this->groups[$this->filters['group']])) {
                $this->filters['group'] = '';
            }

            $result      = $model->hosts($this->filters);
            $this->cards = $result['cards'];
            $start       = max(0, $input->getUint('limitstart', 0));
            $total       = \count($result['hosts']);
            $start       = $start >= $total ? 0 : $start;
            $this->hosts = \array_slice($result['hosts'], $start, self::PER_PAGE);

            $this->pagination = new Pagination($total, $start, self::PER_PAGE);

            foreach (array_filter($this->filters, static fn (string $v): bool => $v !== '') as $name => $value) {
                $this->pagination->setAdditionalUrlParam($name, $value);
            }
        } catch (ZabbixException $e) {
            $this->error = $e;
        }

        parent::display($tpl);
    }

    public function hostUrl(string $hostId): string
    {
        return $this->url('view=host&hostid=' . rawurlencode($hostId));
    }
}
