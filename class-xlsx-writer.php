<?php
/**
 * Simple XLSX Writer for Formidable Flat API
 * Version: 2.0.0
 *
 * Lightweight XLSX generator without external dependencies.
 * Outputs data as a native Excel Table with auto-filter and stripe styling.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Formidable_Flat_XLSX_Writer {

    private $data    = [];
    private $headers = [];
    private $highlight_fn = null;

    /**
     * @param callable|null $highlight_fn Optional callback: (array $row): array — returns
     *        the column header names (from $headers) to fill red for that row. Used e.g.
     *        by the DMR exception report to flag a row's "Missing Fields" column and the
     *        specific blank cells it lists.
     */
    public function __construct( array $headers, array $data, ?callable $highlight_fn = null ) {
        $this->headers      = array_values( $headers );
        $this->data         = $data;
        $this->highlight_fn = $highlight_fn;
    }

    // ── Public ────────────────────────────────────────────────────────────

    public function output( $filename = 'export.xlsx' ) {
        $temp_file = tempnam( sys_get_temp_dir(), 'xlsx_' );
        $zip = new ZipArchive();

        if ( $zip->open( $temp_file, ZipArchive::OVERWRITE ) !== true ) {
            return false;
        }

        $zip->addFromString( '[Content_Types].xml',                    $this->get_content_types() );
        $zip->addFromString( '_rels/.rels',                            $this->get_rels() );
        $zip->addFromString( 'xl/_rels/workbook.xml.rels',             $this->get_workbook_rels() );
        $zip->addFromString( 'xl/worksheets/_rels/sheet1.xml.rels',    $this->get_sheet_rels() );
        $zip->addFromString( 'xl/workbook.xml',                        $this->get_workbook() );
        $zip->addFromString( 'xl/styles.xml',                          $this->get_styles() );
        $zip->addFromString( 'xl/worksheets/sheet1.xml',               $this->get_worksheet() );
        $zip->addFromString( 'xl/tables/table1.xml',                   $this->get_table() );

        $zip->close();

        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $temp_file ) );
        header( 'Cache-Control: max-age=0' );

        readfile( $temp_file );
        unlink( $temp_file );

        return true;
    }

    // ── Private helpers ───────────────────────────────────────────────────

    /**
     * Calculate best-fit column widths based on header + data content.
     * Returns array of widths indexed by zero-based column position.
     */
    private function calc_col_widths() {
        $widths = [];
        foreach ( $this->headers as $i => $header ) {
            $max = mb_strlen( (string) $header );
            foreach ( $this->data as $row ) {
                $len = mb_strlen( (string) ( $row[ $header ] ?? '' ) );
                if ( $len > $max ) $max = $len;
            }
            // Excel width unit ≈ 1 character; add padding, cap between 8 and 60
            $widths[ $i ] = max( 8, min( 60, $max + 3 ) );
        }
        return $widths;
    }

    /** Convert zero-based column index to Excel letter(s): 0→A, 25→Z, 26→AA */
    private function col_letter( $index ) {
        $letter = '';
        while ( $index >= 0 ) {
            $letter   = chr( 65 + ( $index % 26 ) ) . $letter;
            $index    = intval( $index / 26 ) - 1;
        }
        return $letter;
    }

    /** Return cell reference e.g. A1 from zero-based col/row */
    private function cell_ref( $col, $row ) {
        return $this->col_letter( $col ) . ( $row + 1 );
    }

    /** Full table range e.g. A1:G42 */
    private function table_ref() {
        $last_col = max( 0, count( $this->headers ) - 1 );
        $last_row = count( $this->data );                    // +1 for header, row index is 0-based
        return 'A1:' . $this->col_letter( $last_col ) . ( $last_row + 1 );
    }

    private function xml_escape( $string ) {
        return htmlspecialchars( (string) $string, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
    }

    // ── XML generators ────────────────────────────────────────────────────

    private function get_content_types() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml"           ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml"  ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml"             ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/xl/tables/table1.xml"      ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>
</Types>';
    }

    private function get_rels() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
    }

    private function get_workbook_rels() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"    Target="styles.xml"/>
</Relationships>';
    }

    /** Worksheet → table relationship */
    private function get_sheet_rels() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table1.xml"/>
</Relationships>';
    }

    private function get_workbook() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Data" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>';
    }

    private function get_styles() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="3">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/></font>
    <font><sz val="11"/><name val="Calibri"/><color rgb="FF9C0006"/></font>
  </fonts>
  <fills count="3">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFFC7CE"/><bgColor indexed="64"/></patternFill></fill>
  </fills>
  <borders count="1">
    <border><left/><right/><top/><bottom/><diagonal/></border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="3">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>
    <xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
  </cellXfs>
</styleSheet>';
    }

    private function get_worksheet() {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // Column widths
        $widths = $this->calc_col_widths();
        $xml .= '<cols>';
        foreach ( $widths as $ci => $width ) {
            $col = $ci + 1;
            $xml .= '<col min="' . $col . '" max="' . $col . '" width="' . $width . '" customWidth="1"/>';
        }
        $xml .= '</cols>';

        $xml .= '<sheetData>';

        // Header row (style index 1 = bold)
        $xml .= '<row r="1">';
        foreach ( $this->headers as $ci => $header ) {
            $ref  = $this->cell_ref( $ci, 0 );
            $xml .= '<c r="' . $ref . '" t="inlineStr" s="1">';
            $xml .= '<is><t>' . $this->xml_escape( $header ) . '</t></is>';
            $xml .= '</c>';
        }
        $xml .= '</row>';

        // Data rows
        foreach ( $this->data as $ri => $row ) {
            $xml .= '<row r="' . ( $ri + 2 ) . '">';
            $highlighted = $this->highlight_fn ? array_flip( (array) call_user_func( $this->highlight_fn, $row ) ) : [];
            foreach ( $this->headers as $ci => $header ) {
                $value = $row[ $header ] ?? '';
                $ref   = $this->cell_ref( $ci, $ri + 1 );
                $style = isset( $highlighted[ $header ] ) ? ' s="2"' : '';
                // Output as number if the value is numeric (including string-typed numbers)
                if ( is_numeric( $value ) && $value !== '' ) {
                    $xml .= '<c r="' . $ref . '"' . $style . '><v>' . ( $value + 0 ) . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '" t="inlineStr"' . $style . '>';
                    $xml .= '<is><t>' . $this->xml_escape( $value ) . '</t></is>';
                    $xml .= '</c>';
                }
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData>';

        // Register the table part
        $xml .= '<tableParts count="1"><tablePart r:id="rId1" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/></tableParts>';

        $xml .= '</worksheet>';
        return $xml;
    }

    /** Excel Table definition with TableStyleMedium2 (clean blue) */
    private function get_table() {
        $ref        = $this->table_ref();
        $col_count  = count( $this->headers );

        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $xml .= ' id="1" name="ExportData" displayName="ExportData"';
        $xml .= ' ref="' . $ref . '" totalsRowShown="0">';
        $xml .= '<autoFilter ref="' . $ref . '"/>';
        $xml .= '<tableColumns count="' . $col_count . '">';
        foreach ( $this->headers as $i => $header ) {
            $xml .= '<tableColumn id="' . ( $i + 1 ) . '" name="' . $this->xml_escape( $header ) . '"/>';
        }
        $xml .= '</tableColumns>';
        $xml .= '<tableStyleInfo name="TableStyleMedium2" showFirstColumn="0" showLastColumn="0" showRowStripes="1" showColumnStripes="0"/>';
        $xml .= '</table>';

        return $xml;
    }
}
