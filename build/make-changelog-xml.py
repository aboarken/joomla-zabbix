#!/usr/bin/env python3
"""Write the Joomla changelog file (changelog.xml) from CHANGELOG.md.

Usage: build/make-changelog-xml.py <CHANGELOG.md> <output.xml>

Joomla shows it when the version number is clicked in System > Manage > Extensions, and from the
update view (format: https://manual.joomla.org/docs/building-extensions/install-update/installation/change-log/).
It is attached to every GitHub release; <changelogurl> in zabbix.xml and in updates.xml
points to releases/latest/download/changelog.xml, which holds every version.

Sections map to Joomla change types: Security -> security, Fixed -> fix, Added -> addition,
Changed -> change, Removed -> remove, anything else -> note. Each top-level bullet is one item;
its nested lines are joined to it. Markdown emphasis and code marks are removed.
"""
import re
import sys
from xml.sax.saxutils import escape

ELEMENT = 'com_zabbix'
TYPES = {'security': 'security', 'fixed': 'fix', 'added': 'addition', 'changed': 'change', 'removed': 'remove'}
ORDER = ['security', 'fix', 'addition', 'change', 'remove', 'note']


def plain(text: str) -> str:
    text = re.sub(r'\[([^\]]+)\]\([^)]+\)', r'\1', text)
    text = re.sub(r'(\*\*|__|`)', '', text)
    text = re.sub(r'(?<!\w)\*(?!\s)([^*]+?)\*(?!\w)', r'\1', text)
    return re.sub(r'\s+', ' ', text).strip()


def parse(markdown: str) -> list[tuple[str, dict[str, list[str]]]]:
    versions: list[tuple[str, dict[str, list[str]]]] = []
    current: dict[str, list[str]] | None = None
    kind = 'note'

    for line in markdown.splitlines():
        heading = re.match(r'^## \[(\d+\.\d+\.\d+)\]', line)

        if heading:
            current = {}
            versions.append((heading.group(1), current))
            kind = 'note'
            continue

        if current is None:
            continue

        section = re.match(r'^### (\w+)', line)

        if section:
            kind = TYPES.get(section.group(1).lower(), 'note')
            continue

        if line.startswith('- '):
            current.setdefault(kind, []).append(line[2:])
        elif line.startswith((' ', '\t')) and line.strip() and current.get(kind):
            current[kind][-1] += ' ' + re.sub(r'^[-*]\s+', '', line.strip())

    return versions


def main() -> int:
    if len(sys.argv) != 3:
        print(__doc__)
        return 2

    source, output = sys.argv[1:]

    with open(source, encoding='utf-8') as f:
        versions = parse(f.read())

    if not versions:
        print(f'No versions found in {source}')
        return 1

    out = ['<?xml version="1.0" encoding="utf-8"?>', '<changelogs>']

    for version, changes in versions:
        out += ['    <changelog>', f'        <element>{ELEMENT}</element>', '        <type>component</type>', f'        <version>{version}</version>']

        for kind in ORDER:
            items = [plain(i) for i in changes.get(kind, []) if plain(i)]

            if items:
                out.append(f'        <{kind}>')
                out += [f'            <item>{escape(i)}</item>' for i in items]
                out.append(f'        </{kind}>')

        out.append('    </changelog>')

    out.append('</changelogs>')

    with open(output, 'w', encoding='utf-8') as f:
        f.write('\n'.join(out) + '\n')

    print(f'{output}: {len(versions)} versions')
    return 0


if __name__ == '__main__':
    sys.exit(main())
