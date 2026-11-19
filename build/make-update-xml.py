#!/usr/bin/env python3
"""Write the Joomla update server file (updates.xml) for one release.

Usage: build/make-update-xml.py <package.zip> <version> <output.xml>

Joomla reads this file from the update server named in zabbix.xml
(https://github.com/aboarken/joomla-zabbix/releases/latest/download/updates.xml),
so it is attached to every GitHub release next to com_zabbix.zip. It names the
version, the download URL of that release's package and the package's SHA-256 checksum,
which Joomla checks before installing.
"""
import hashlib
import re
import sys
from xml.sax.saxutils import escape

REPO = 'https://github.com/aboarken/joomla-zabbix'
PACKAGE = 'com_zabbix.zip'


def main() -> int:
    if len(sys.argv) != 4:
        print(__doc__)
        return 2

    package, version, output = sys.argv[1:]

    if not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+', version):
        print(f'Invalid version: {version}')
        return 1

    with open(package, 'rb') as f:
        sha256 = hashlib.sha256(f.read()).hexdigest()

    tag = 'v' + version
    xml = f"""<?xml version="1.0" encoding="utf-8"?>
<updates>
    <update>
        <name>Zabbix</name>
        <description>{escape('Integrate Zabbix with Joomla to provide a centralized IT monitoring portal for users.')}</description>
        <element>com_zabbix</element>
        <type>component</type>
        <client>administrator</client>
        <version>{version}</version>
        <infourl title="com_zabbix {version}">{REPO}/releases/tag/{tag}</infourl>
        <downloads>
            <downloadurl type="full" format="zip">{REPO}/releases/download/{tag}/{PACKAGE}</downloadurl>
        </downloads>
        <sha256>{sha256}</sha256>
        <changelogurl>{REPO}/releases/latest/download/changelog.xml</changelogurl>
        <tags>
            <tag>stable</tag>
        </tags>
        <maintainer>Zabbix Portal</maintainer>
        <maintainerurl>{REPO}</maintainerurl>
        <targetplatform name="joomla" version="6\\.[0-9]+"/>
        <php_minimum>8.3</php_minimum>
    </update>
</updates>
"""

    with open(output, 'w', encoding='utf-8') as f:
        f.write(xml)

    print(f'{output}: {version}, sha256 {sha256}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
