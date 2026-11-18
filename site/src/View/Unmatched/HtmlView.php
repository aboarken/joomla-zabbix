<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Site\View\Unmatched;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

/**
 * Shown instead of a portal page when the user is not linked to a Zabbix user (default layout)
 * or Zabbix cannot be reached (unavailable layout).
 */
final class HtmlView extends BaseHtmlView
{
    public string $status = 'none';

    public string $kind = '';

    public function display($tpl = null): void
    {
        $input        = Factory::getApplication()->getInput();
        $this->status = $input->getCmd('status', 'none');
        $this->kind   = $input->getCmd('kind', '');

        $document = $this->getDocument();
        $document->setTitle(Text::_('COM_ZABBIX_UNMATCHED_TITLE'));
        $document->setMetaData('robots', 'noindex, nofollow');
        $document->getWebAssetManager()->getRegistry()->addExtensionRegistryFile('com_zabbix');
        $document->getWebAssetManager()->useStyle('com_zabbix.portal');

        parent::display($tpl);
    }
}
