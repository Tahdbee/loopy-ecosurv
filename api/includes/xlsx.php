<?php

/**
 * Minimal .xlsx writer with NO extension or Composer dependency.
 *
 * The Docker image only installs mysqli, so ext-zip / PhpSpreadsheet are not available.
 * An .xlsx file is a ZIP of XML parts, and the ZIP "stored" (uncompressed) layout is simple
 * enough to write by hand.
 *
 * Usage:
 *   $binary = SimpleXlsx::build($headers, $rows, [
 *       "sheet_name"      => "Transactions",
 *       "widths"          => [10, 20, 34],      // column widths, in characters
 *       "numeric_columns" => [0 => 0, 2 => 2],  // column index => style id (0 plain, 2 = #,##0)
 *   ]);
 *
 * Every text cell is written as an inline string, never as a formula. As an extra defence
 * against "formula injection", text that starts with = + - @ (or a tab / CR) is prefixed
 * with a single quote so it can never be interpreted as a formula if the sheet is re-saved
 * as CSV or copied into another tool.
 */
final class SimpleXlsx
{
    private const XML_DECLARATION = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

    private const CONTENT_TYPES = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';

    private const ROOT_RELS = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';

    private const WORKBOOK_RELS = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';

    // Style ids: 0 = default, 1 = white bold text on orange (header), 2 = number with thousands separator.
    private const STYLES = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEA580C"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

    /**
     * @param string[]               $headers
     * @param array<int, array>      $rows    each row is a list of scalar values (same order as $headers)
     * @param array<string, mixed>   $options sheet_name, widths, numeric_columns
     */
    public static function build(array $headers, array $rows, array $options = []): string
    {
        $sheetName = self::cleanSheetName((string)($options["sheet_name"] ?? "Sheet1"));
        $widths = $options["widths"] ?? [];
        $numericColumns = $options["numeric_columns"] ?? [];

        $sheet = self::XML_DECLARATION
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';

        if ($widths) {
            $sheet .= "<cols>";
            foreach (array_values($widths) as $i => $width) {
                $n = $i + 1;
                $sheet .= '<col min="' . $n . '" max="' . $n . '" width="' . (int)$width . '" customWidth="1"/>';
            }
            $sheet .= "</cols>";
        }

        $sheet .= "<sheetData>";

        // Header row
        $sheet .= '<row r="1">';
        foreach (array_values($headers) as $c => $header) {
            $sheet .= '<c r="' . self::columnName($c) . '1" s="1" t="inlineStr"><is><t xml:space="preserve">'
                . self::text((string)$header) . "</t></is></c>";
        }
        $sheet .= "</row>";

        // Data rows
        $rowNumber = 1;
        foreach ($rows as $row) {
            $rowNumber++;
            $sheet .= '<row r="' . $rowNumber . '">';
            foreach (array_values($row) as $c => $value) {
                if ($value === null || $value === "") {
                    continue;
                }
                $ref = self::columnName($c) . $rowNumber;
                if (array_key_exists($c, $numericColumns) && is_numeric($value)) {
                    $sheet .= '<c r="' . $ref . '" s="' . (int)$numericColumns[$c] . '"><v>' . (string)($value + 0) . "</v></c>";
                } else {
                    $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                        . self::text((string)$value) . "</t></is></c>";
                }
            }
            $sheet .= "</row>";
        }

        $sheet .= "</sheetData></worksheet>";

        $workbook = self::XML_DECLARATION
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::text($sheetName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>';

        return self::zip([
            "[Content_Types].xml" => self::CONTENT_TYPES,
            "_rels/.rels" => self::ROOT_RELS,
            "xl/workbook.xml" => $workbook,
            "xl/_rels/workbook.xml.rels" => self::WORKBOOK_RELS,
            "xl/styles.xml" => self::STYLES,
            "xl/worksheets/sheet1.xml" => $sheet,
        ]);
    }

    /** Zero-based column index to spreadsheet letters: 0 => A, 25 => Z, 26 => AA. */
    private static function columnName(int $index): string
    {
        $name = "";
        $n = $index + 1;
        while ($n > 0) {
            $mod = ($n - 1) % 26;
            $name = chr(65 + $mod) . $name;
            $n = intdiv($n - 1, 26);
        }
        return $name;
    }

    private static function cleanSheetName(string $name): string
    {
        $name = trim(str_replace(["[", "]", ":", "*", "?", "/", "\\"], " ", $name));
        $name = mb_substr($name, 0, 31);
        return $name === "" ? "Sheet1" : $name;
    }

    /** Escape a value for XML text/attribute content and neutralise spreadsheet formulas. */
    private static function text(string $value): string
    {
        // Drop characters that are illegal in XML 1.0.
        $clean = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', "", $value);
        $value = $clean ?? "";
        // Excel's hard limit is 32,767 characters per cell.
        $value = mb_substr($value, 0, 32000);
        if ($value !== "" && strpos("=+-@\t\r", $value[0]) !== false) {
            $value = "'" . $value;
        }
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, "UTF-8");
    }

    /**
     * Build a ZIP archive that stores every file uncompressed ("stored" method).
     *
     * @param array<string, string> $files path => contents
     */
    private static function zip(array $files): string
    {
        $dosTime = 0;
        $dosDate = (46 << 9) | (11 << 5) | 9; // 2026-11-09
        $body = "";
        $central = "";
        $offset = 0;
        $count = 0;

        foreach ($files as $name => $data) {
            $crc = crc32($data);
            $size = strlen($data);
            $nameLength = strlen($name);

            $local = pack("VvvvvvVVVvv", 0x04034b50, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLength, 0) . $name;
            $body .= $local . $data;

            $central .= pack("VvvvvvvVVVvvvvvVV", 0x02014b50, 20, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLength, 0, 0, 0, 0, 0, $offset) . $name;

            $offset += strlen($local) + $size;
            $count++;
        }

        $end = pack("VvvvvVVv", 0x06054b50, 0, 0, $count, $count, strlen($central), $offset, 0);

        return $body . $central . $end;
    }
}
