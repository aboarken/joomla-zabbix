<?php

/**
 * Test bootstrap. The tests need no Joomla installation and no Zabbix server: only classes
 * without Joomla CMS dependencies are tested, and Zabbix is replaced by FakeTransport.
 *
 * @license GNU General Public License version 2 or later
 */

\define('_JEXEC', 1);

require __DIR__ . '/../vendor/autoload.php';

require __DIR__ . '/Support/Fakes.php';
