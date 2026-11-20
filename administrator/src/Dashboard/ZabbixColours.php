<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Dashboard;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The colours Zabbix 7.4 gives the lines of a Graph and the sectors of a Pie chart when a data set
 * matches several items: shades of the data set's colour (CColorPicker::getColorVariations()) or
 * colours from one of Zabbix's palettes (CColorPicker::getPaletteColors()). Pure.
 */
final class ZabbixColours
{
    /** CColorPicker::PALETTE_COLORS (Zabbix 7.4). */
    public const PALETTES = [
        ['F48485', 'CA767B', 'FF78D9', 'CA76BE', '8867B9', '3B97FF', '4876B1', '6DBCCD', '7AD9CC', '619F3A', '92E79A', 'BBBC39', 'FCC95A', 'F69F89', 'E37E23', '7E574D', 'B89B93', '7E7E7E'],
        ['C75566', 'BF7FCB', 'B34FB7', '91497D', '7F3FA1', '8775BB', '646AD0', '68AFE4', '5188B9', '598530', '486C4E', '75CAC8', '67B08A', 'BCC239', 'A1A441', 'F0B338', 'EB9A39', 'DB6D47'],
        ['DD530E', 'F64C68', 'ED5AAD', 'DB4FEE', '9E7DF7', '6A45FC', '6A71F6', '3370FF', '3290FB', '0AABF0', '05ACD1', '66A22A', '62C51B', '15BC9E', '14B86B', 'EDB007', 'F16E22', 'A19AA2'],
        ['EFECFE', 'E6E1FE', 'D9D2FE', 'CFC3FE', 'C7B9FE', 'AF97FC', '9F83FB', '946CF9', '895DF8', '8247F0', '7534EF', '6D2CDD', '6724DB', '5320AC', '471B93', '411985', '2C115A', '220D45'],
        ['3975EC', '4173ED', '4571ED', '4A70EE', '4E6FEE', '546DEF', '596BEF', '5D6AEF', '6268EF', '6767F0', '6C66F1', '7064F1', '7563F1', '7A62F2', '7F60F2', '845FF3', '895EF3', '8C5CF3'],
        ['B8DFFF', '8ACAFF', '58B0FE', '4198FB', '3290FB', '2778F1', '196FF0', '1D64E7', '175BD9', '1A4DBC', '1848AF', '1C4797', '19418A', '19428F', '163A7E', '153675', '102A5B', '0C2045'],
        ['CCE7F0', 'A9D6E5', '89C2D9', '61A5C2', '59A1BF', '468FAF', '4489A7', '2C7DA0', '2A7798', '2A6F97', '266488', '014F86', '014B7F', '01497C', '01416F', '013A63', '012A4A', '00060A'],
        ['3D77EA', '557FD6', '6E88C1', '8992AB', 'A59C94', 'C5A77A', 'D1AC6F', 'E7B45D', 'FCBC4C', 'FCB54A', 'FCAA47', 'FC9F43', 'FC923F', 'FC893C', 'FD7C39', 'FC7436', 'FC6C33', 'FC6030'],
        ['FEF3CD', 'FEE38F', 'FFCB52', 'FEB939', 'FEB225', 'F99B1F', 'F9920B', 'F77402', 'E36B02', 'D95408', 'BB4907', 'AF410E', '97380C', '8F350F', '81300E', '6E280C', '531E09', '451908'],
        ['FFE8D1', 'FFD8B3', 'FE9F6C', 'FE7F4D', 'FE6D34', 'FE541A', 'FE480B', 'F22F03', 'E22C03', 'CC1C05', 'B81A05', 'A1180C', '93160B', '811B0E', '73180C', '65160B', '5C140A', '531209'],
        ['24C56B', '45C55D', '6BC64C', '83C53E', '9BC42F', 'B4C222', 'C7BE1E', 'DDBA19', 'EAB317', 'F4A917', 'FA9D14', 'F8930D', 'F48302', 'F17501', 'EE6601', 'EC5A01', 'E84A01', 'E43403'],
        ['D7F9E2', 'ACF1C7', '77E4A8', '75DBA7', '40CE85', '17C571', '15B769', '0A9F59', '099554', '09814C', '087847', '0A7044', '09623B', '095837', '084F31', '074B2F', '053320', '031C11'],
    ];

    /**
     * Shades of a colour, from darker to lighter, one per item.
     *
     * @param  string  $colour  '#RRGGBB'
     *
     * @return list<string>
     */
    public static function variations(string $colour, int $count): array
    {
        if ($count <= 1) {
            return [$colour];
        }

        $rgb   = [hexdec(substr($colour, 1, 2)), hexdec(substr($colour, 3, 2)), hexdec(substr($colour, 5, 2))];
        $max   = 50;
        $range = range(-1 * $max, $max, $max * 2 / $count);

        while (\count($range) > $count) {
            (\count($range) % 2) ? array_shift($range) : array_pop($range);
        }

        $out = [];

        foreach ($range as $var) {
            // Zabbix adds or takes the same amount (255 / 100 * $var) from each channel.
            $out[] = '#' . implode('', array_map(
                static fn (int $c): string => \sprintf('%02X', max(0, min(255, (int) ($c + 255 / 100 * $var)))),
                $rgb
            ));
        }

        return $out;
    }

    /**
     * Colours from a palette row: every third unused colour of the row, then the next rows.
     *
     * @return list<string>
     */
    public static function palette(int $row, int $count): array
    {
        $rows  = \count(self::PALETTES);
        $cols  = \count(self::PALETTES[0]);
        $row   = max(0, min($rows - 1, $row));
        $start = $row;
        $used  = array_fill(0, $rows, array_fill(0, $cols, false));
        $col   = 0;
        $total = $rows * $cols;
        $out   = [];

        while (\count($out) < $count) {
            if (!$used[$row][$col]) {
                $out[]            = '#' . self::PALETTES[$row][$col];
                $used[$row][$col] = true;
            }

            if (\count($out) === $count) {
                break;
            }

            if (\count(array_filter($used[$row])) < $cols) {
                $steps = 3;

                while ($steps > 0) {
                    $col = ($col + 1) % $cols;

                    if (!$used[$row][$col]) {
                        $steps--;
                    }
                }
            } else {
                $row = ($row + 1) % $rows;
                $col = 0;

                if (array_sum(array_map('array_sum', $used)) === $total) {
                    $used = array_fill(0, $rows, array_fill(0, $cols, false));
                    $row  = $start;
                }
            }
        }

        return $out;
    }
}
