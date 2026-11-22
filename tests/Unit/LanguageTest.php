<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every language key the component uses is in its language files, so Joomla never shows a raw
 * key such as COM_ZABBIX_CONFIGURATION.
 */
final class LanguageTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @return array<string, string> */
    private static function ini(string $file): array
    {
        $strings = parse_ini_file(self::ROOT . '/' . $file, false, INI_SCANNER_RAW);
        self::assertIsArray($strings, $file . ' is a valid ini file');

        return $strings;
    }

    /**
     * Literal COM_ZABBIX_ keys in the files of a folder (keys built at runtime end with "_").
     *
     * @return array<string, string> key => first file
     */
    private static function used(string $folder): array
    {
        $keys  = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $folder, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (!\in_array($file->getExtension(), ['php', 'xml', 'js'], true)) {
                continue;
            }

            preg_match_all('/\bCOM_ZABBIX_[A-Z0-9_]*[A-Z0-9]\b/', (string) file_get_contents($file->getPathname()), $m);

            foreach ($m[0] as $key) {
                $keys[$key] ??= substr($file->getPathname(), \strlen(self::ROOT) + 1);
            }
        }

        return $keys;
    }

    public function testBackendKeysExist(): void
    {
        $strings = self::ini('administrator/language/en-GB/com_zabbix.ini') + self::ini('administrator/language/en-GB/com_zabbix.sys.ini');

        // Menu item options (site/tmpl/*.xml) are edited in the backend too.
        $menuItems = array_filter(self::used('site/tmpl'), static fn (string $f): bool => str_ends_with($f, '.xml'));

        foreach (self::used('administrator') + $menuItems as $key => $file) {
            self::assertArrayHasKey($key, $strings, $key . ' (' . $file . ') is in the backend language files');
        }
    }

    public function testSiteKeysExist(): void
    {
        $strings = self::ini('site/language/en-GB/com_zabbix.ini');

        foreach (self::used('site/src') + self::used('media/js') as $key => $file) {
            self::assertArrayHasKey($key, $strings, $key . ' (' . $file . ') is in the site language file');
        }

        foreach (array_filter(self::used('site/tmpl'), static fn (string $f): bool => str_ends_with($f, '.php')) as $key => $file) {
            self::assertArrayHasKey($key, $strings, $key . ' (' . $file . ') is in the site language file');
        }
    }

    public function testKeysJoomlaBuildsFromTheComponentName(): void
    {
        $sys = self::ini('administrator/language/en-GB/com_zabbix.sys.ini');

        foreach (['COM_ZABBIX', 'COM_ZABBIX_XML_DESCRIPTION', 'COM_ZABBIX_CONFIGURATION'] as $key) {
            self::assertArrayHasKey($key, $sys);
        }

        // Options page title (com_config: <option>_CONFIGURATION).
        self::assertArrayHasKey('COM_ZABBIX_CONFIGURATION', self::ini('administrator/language/en-GB/com_zabbix.ini'));
    }
}
