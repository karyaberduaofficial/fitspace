<?php

namespace App\Services;

/**
 * Executive Pure-PHP OpenXML (.xlsx) Generator with OWASP Formula Injection sanitization.
 * Produces clean, boardroom-ready spreadsheets without freeze-split duplication glitches:
 *  - Navy title banner + subtitle + complete metadata strip (merged, wrapped, zero clipping)
 *  - Full-width symmetrically distributed KPI card grid spanning A..lastCol
 *  - Royal blue table headers with smart per-column alignment & autoFilter on data rows
 *  - Dynamic multi-line row height calculation + zebra striping + semantic status pills
 *  - True numeric, Rupiah currency ("Rp "#,##0), and 2-decimal score/rating formats
 *  - Merged left-aligned Totals / Ringkasan summary row (never truncated)
 *  - Landscape A4 Fit-to-1-Page-Wide print setup & zero ext-zip / ext-gd dependency
 */
class XlsxExporter
{
    /**
     * Neutralizes spreadsheet formula injection (=, +, -, @, tab, carriage return).
     */
    public function sanitizeCell(mixed $value): string
    {
        $str = (string) ($value ?? '');
        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $str) ?? '';
        $trimmed = ltrim($str, ' ');

        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$str;
        }

        return $str;
    }

    /**
     * Generates valid binary .xlsx file bytes with executive corporate formatting.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<string, mixed>  $meta
     */
    public function build(string $sheetTitle, array $headers, array $rows, array $meta = []): string
    {
        $safeTitle = trim(substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $sheetTitle) ?: 'Laporan', 0, 31));
        if ($safeTitle === '') {
            $safeTitle = 'Laporan';
        }
        $xmlEscape = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $headersList = array_values($headers);
        $colCount = max(1, count($headersList));
        $lastCol = $this->colLetter($colCount - 1);

        $docCode = (string) ($meta['doc_code'] ?? ('FS-RPT-'.date('Ymd')));
        $subtitle = (string) ($meta['subtitle'] ?? 'Laporan Resmi Operasional & Analisis Kinerja Studio FitSpace');
        $generatedAt = (string) ($meta['generated_at'] ?? (now()->format('d M Y H:i').' WIB'));
        $generatedBy = (string) ($meta['generated_by'] ?? 'FitSpace System');
        $kpis = is_array($meta['kpis'] ?? null) ? array_values($meta['kpis']) : [];
        $totals = is_array($meta['totals'] ?? null) ? $meta['totals'] : [];

        // 1. Classify every column for smart alignment, width bounds & Excel number format
        $colKinds = [];
        foreach ($headersList as $cIdx => $h) {
            $colKinds[$cIdx] = $this->classifyColumn((string) $h);
        }

        // 2. Auto-compute generous column widths from headers, rows, totals & KPI labels
        $colWidths = [];
        foreach ($headersList as $cIdx => $h) {
            $kind = $colKinds[$cIdx];
            [$minW, $maxW] = match ($kind) {
                'currency' => [20.0, 25.0],
                'decimal' => [15.0, 19.0],
                'integer' => [14.0, 18.0],
                'date' => [19.0, 24.0],
                'code' => [16.0, 22.0],
                'status' => [14.0, 19.0],
                'longtext' => [34.0, 52.0],
                default => [18.0, 32.0],
            };
            $w = max($minW, mb_strlen((string) $h) + 6.0);
            $colWidths[$cIdx] = min($maxW, $w);
        }

        foreach ($rows as $row) {
            foreach (array_values($row) as $cIdx => $val) {
                if ($cIdx >= $colCount) {
                    continue;
                }
                $kind = $colKinds[$cIdx];
                $maxW = $kind === 'longtext' ? 52.0 : ($kind === 'text' ? 34.0 : 25.0);
                $displayLen = is_numeric($val)
                    ? ($kind === 'currency' ? mb_strlen('Rp '.number_format((float) $val, 0, ',', '.')) + 4 : mb_strlen((string) $val) + 5)
                    : mb_strlen((string) ($val ?? '')) + 4;
                $colWidths[$cIdx] = max($colWidths[$cIdx], min($maxW, (float) $displayLen));
            }
        }

        foreach ($totals as $cIdx => $tv) {
            if (! is_int($cIdx) || $cIdx < 0 || $cIdx >= $colCount) {
                continue;
            }
            if (is_numeric($tv)) {
                $len = $colKinds[$cIdx] === 'currency'
                    ? mb_strlen('Rp '.number_format((float) $tv, 0, ',', '.')) + 5
                    : mb_strlen((string) $tv) + 6;
                $colWidths[$cIdx] = max($colWidths[$cIdx], min(28.0, (float) $len));
            }
        }

        // Guarantee minimum total horizontal width (>= 124 chars) so banners & metadata never clip
        $totalWidth = array_sum($colWidths);
        if ($totalWidth < 124.0) {
            $deficit = 124.0 - $totalWidth;
            $textCols = [];
            foreach ($colKinds as $cIdx => $kind) {
                if (in_array($kind, ['longtext', 'text'], true)) {
                    $textCols[] = $cIdx;
                }
            }
            if (count($textCols) === 0) {
                $textCols = range(0, $colCount - 1);
            }
            $extra = $deficit / count($textCols);
            foreach ($textCols as $cIdx) {
                $colWidths[$cIdx] = min(56.0, $colWidths[$cIdx] + $extra);
            }
            $totalWidth = array_sum($colWidths);
        }

        $colsXml = '<cols>';
        foreach ($colWidths as $cIdx => $w) {
            $n = $cIdx + 1;
            $colsXml .= '<col min="'.$n.'" max="'.$n.'" width="'.number_format($w, 1, '.', '').'" customWidth="1"/>';
        }
        $colsXml .= '</cols>';

        $merges = [];
        $sheetRowsXml = '';
        $rowNum = 1;

        // Row 1: Executive Deep Navy Title Banner
        $titleText = 'FITSPACE  —  '.mb_strtoupper($sheetTitle);
        $sheetRowsXml .= $this->buildMergedBannerRow($rowNum, $colCount, $titleText, 1, 34, $xmlEscape);
        if ($colCount > 1) {
            $merges[] = "A{$rowNum}:{$lastCol}{$rowNum}";
        }
        $rowNum++;

        // Row 2: Report Subtitle Strip (Wrapped, never truncated)
        $subHeight = mb_strlen($subtitle) > ($totalWidth * 0.9) ? 32 : 22;
        $sheetRowsXml .= $this->buildMergedBannerRow($rowNum, $colCount, $subtitle, 2, $subHeight, $xmlEscape);
        if ($colCount > 1) {
            $merges[] = "A{$rowNum}:{$lastCol}{$rowNum}";
        }
        $rowNum++;

        // Row 3: Document Metadata Strip (Doc Code | Timestamp | Printed By | Row Count)
        $metaLine = "No. Dokumen: {$docCode}   |   Dicetak: {$generatedAt}   |   Oleh: {$generatedBy}   |   Total Data: ".count($rows).' baris   |   FitSpace Official';
        $metaHeight = mb_strlen($metaLine) > ($totalWidth * 0.92) ? 30 : 21;
        $sheetRowsXml .= $this->buildMergedBannerRow($rowNum, $colCount, $metaLine, 12, $metaHeight, $xmlEscape);
        if ($colCount > 1) {
            $merges[] = "A{$rowNum}:{$lastCol}{$rowNum}";
        }
        $rowNum++;

        // Row 4: Clean visual breathing spacer
        $sheetRowsXml .= '<row r="'.$rowNum.'" ht="10" customHeight="1"/>';
        $rowNum++;

        // Optional KPI Executive Summary Grid — distributed evenly across A..lastCol
        if (count($kpis) > 0) {
            $kpiItems = array_slice($kpis, 0, min(count($kpis), $colCount));
            $kpiCount = count($kpiItems);
            $baseSpan = intdiv($colCount, $kpiCount);
            $remainder = $colCount % $kpiCount;

            $labelRowNum = $rowNum;
            $valueRowNum = $rowNum + 1;
            $labelCells = '';
            $valueCells = '';
            $cursor = 0;

            foreach ($kpiItems as $kIdx => $kpi) {
                $span = $baseSpan + ($kIdx < $remainder ? 1 : 0);
                $startCol = $cursor;
                $endCol = $cursor + $span - 1;
                $lbl = mb_strtoupper($this->sanitizeCell($kpi['label'] ?? ''));
                $val = $this->sanitizeCell($kpi['value'] ?? '-');

                $startL = $this->colLetter($startCol);
                $endL = $this->colLetter($endCol);

                $labelCells .= '<c r="'.$startL.$labelRowNum.'" t="inlineStr" s="3"><is><t xml:space="preserve">'.$xmlEscape($lbl).'</t></is></c>';
                $valueCells .= '<c r="'.$startL.$valueRowNum.'" t="inlineStr" s="8"><is><t xml:space="preserve">'.$xmlEscape($val).'</t></is></c>';

                for ($c = $startCol + 1; $c <= $endCol; $c++) {
                    $cl = $this->colLetter($c);
                    $labelCells .= '<c r="'.$cl.$labelRowNum.'" s="3"/>';
                    $valueCells .= '<c r="'.$cl.$valueRowNum.'" s="8"/>';
                }

                if ($span > 1) {
                    $merges[] = "{$startL}{$labelRowNum}:{$endL}{$labelRowNum}";
                    $merges[] = "{$startL}{$valueRowNum}:{$endL}{$valueRowNum}";
                }

                $cursor = $endCol + 1;
            }

            $sheetRowsXml .= '<row r="'.$labelRowNum.'" ht="20" customHeight="1">'.$labelCells.'</row>';
            $sheetRowsXml .= '<row r="'.$valueRowNum.'" ht="28" customHeight="1">'.$valueCells.'</row>';
            $rowNum += 2;

            // Breathing spacer before table header
            $sheetRowsXml .= '<row r="'.$rowNum.'" ht="10" customHeight="1"/>';
            $rowNum++;
        }

        // Table Header Row
        $headerRowNum = $rowNum;
        $headerCells = '';
        foreach ($headersList as $cIdx => $h) {
            $colRef = $this->colLetter($cIdx).$rowNum;
            $safe = $this->sanitizeCell($h);
            $kind = $colKinds[$cIdx];
            $hStyle = match ($kind) {
                'currency', 'integer' => 13, // Header right-aligned
                'decimal', 'status', 'code', 'date' => 14, // Header centered
                default => 4, // Header left-aligned
            };
            $headerCells .= '<c r="'.$colRef.'" t="inlineStr" s="'.$hStyle.'"><is><t xml:space="preserve">'.$xmlEscape($safe).'</t></is></c>';
        }
        $sheetRowsXml .= '<row r="'.$rowNum.'" ht="28" customHeight="1">'.$headerCells.'</row>';
        $rowNum++;

        // Data Rows with Zebra Striping, Smart Number/Currency/Decimal Formats & Dynamic Multi-Line Heights
        if (count($rows) === 0) {
            $sheetRowsXml .= $this->buildMergedBannerRow($rowNum, $colCount, 'Belum ada data tercatat pada periode ini.', 5, 24, $xmlEscape);
            if ($colCount > 1) {
                $merges[] = "A{$rowNum}:{$lastCol}{$rowNum}";
            }
            $rowNum++;
        } else {
            foreach ($rows as $rIdx => $row) {
                $isEven = ($rIdx % 2) === 1;
                $cellsXml = '';
                $maxLines = 1;
                $vals = array_values($row);

                for ($cIdx = 0; $cIdx < $colCount; $cIdx++) {
                    $val = $vals[$cIdx] ?? '';
                    $colRef = $this->colLetter($cIdx).$rowNum;
                    $kind = $colKinds[$cIdx];

                    if (is_int($val) || is_float($val)) {
                        $isFractional = is_float($val) && floor($val) != $val;
                        $numStyle = match (true) {
                            $kind === 'currency' => $isEven ? 16 : 15,
                            $kind === 'decimal' || $isFractional => $isEven ? 18 : 17,
                            default => $isEven ? 9 : 6,
                        };
                        $cellsXml .= '<c r="'.$colRef.'" t="n" s="'.$numStyle.'"><v>'.$val.'</v></c>';
                    } else {
                        $safe = $this->sanitizeCell($val);
                        $styleId = $this->resolveTextCellStyle($kind, $safe, $isEven);
                        $cellsXml .= '<c r="'.$colRef.'" t="inlineStr" s="'.$styleId.'"><is><t xml:space="preserve">'.$xmlEscape($safe).'</t></is></c>';

                        // Estimate wrapped lines so long notes/reviews never clip
                        $availChars = max(10.0, $colWidths[$cIdx] - 3.0);
                        $explicitLines = explode("\n", $safe);
                        $lineTotal = 0;
                        foreach ($explicitLines as $seg) {
                            $lineTotal += max(1, (int) ceil(mb_strlen($seg) / $availChars));
                        }
                        if ($lineTotal > $maxLines) {
                            $maxLines = $lineTotal;
                        }
                    }
                }

                $rowHeight = max(22, min(88, ($maxLines * 15) + 8));
                $sheetRowsXml .= '<row r="'.$rowNum.'" ht="'.$rowHeight.'" customHeight="1">'.$cellsXml.'</row>';
                $rowNum++;
            }
        }

        $lastDataRow = max($headerRowNum, $rowNum - 1);

        // Optional Summary / Total Footer Row — Merge left label columns so text NEVER truncates!
        if (count($totals) > 0) {
            $firstMetricCol = $colCount;
            foreach ($totals as $idx => $tv) {
                if (is_int($idx) && $idx > 0 && $tv !== '' && $tv !== null) {
                    $firstMetricCol = min($firstMetricCol, $idx);
                }
            }
            $labelEndCol = max(0, min($colCount - 1, $firstMetricCol - 1));
            $labelText = (string) ($totals[0] ?? 'TOTAL / RINGKASAN');

            $totalCells = '';
            for ($cIdx = 0; $cIdx < $colCount; $cIdx++) {
                $colRef = $this->colLetter($cIdx).$rowNum;
                if ($cIdx === 0) {
                    $safe = $this->sanitizeCell($labelText);
                    $totalCells .= '<c r="'.$colRef.'" t="inlineStr" s="10"><is><t xml:space="preserve">'.$xmlEscape($safe).'</t></is></c>';
                } elseif ($cIdx <= $labelEndCol) {
                    $totalCells .= '<c r="'.$colRef.'" s="10"/>';
                } else {
                    $val = $totals[$cIdx] ?? '';
                    $kind = $colKinds[$cIdx];
                    if (is_int($val) || is_float($val)) {
                        $isFractional = is_float($val) && floor($val) != $val;
                        $totStyle = match (true) {
                            $kind === 'currency' => 19,
                            $kind === 'decimal' || $isFractional => 20,
                            default => 7,
                        };
                        $totalCells .= '<c r="'.$colRef.'" t="n" s="'.$totStyle.'"><v>'.$val.'</v></c>';
                    } else {
                        $safe = $this->sanitizeCell($val);
                        $totalCells .= '<c r="'.$colRef.'" t="inlineStr" s="10"><is><t xml:space="preserve">'.$xmlEscape($safe).'</t></is></c>';
                    }
                }
            }

            if ($labelEndCol > 0) {
                $merges[] = "A{$rowNum}:".$this->colLetter($labelEndCol).$rowNum;
            }

            $sheetRowsXml .= '<row r="'.$rowNum.'" ht="26" customHeight="1">'.$totalCells.'</row>';
            $rowNum++;
        }

        // Blank spacer + Official Footer Credit Row
        $sheetRowsXml .= '<row r="'.$rowNum.'" ht="10" customHeight="1"/>';
        $rowNum++;

        $sheetRowsXml .= $this->buildMergedBannerRow(
            $rowNum,
            $colCount,
            'Dokumen resmi FitSpace (Jakarta Selatan & Bandung  •  hello@fitspace.id  •  @fitspace.id)  —  Powered by Karya Berdua Official',
            11,
            20,
            $xmlEscape
        );
        if ($colCount > 1) {
            $merges[] = "A{$rowNum}:{$lastCol}{$rowNum}";
        }
        $finalRowNum = $rowNum;

        $mergeXml = '';
        if (count($merges) > 0) {
            $mergeXml = '<mergeCells count="'.count($merges).'">';
            foreach ($merges as $ref) {
                $mergeXml .= '<mergeCell ref="'.$ref.'"/>';
            }
            $mergeXml .= '</mergeCells>';
        }

        $autoFilterXml = '<autoFilter ref="A'.$headerRowNum.':'.$lastCol.$lastDataRow.'"/>';

        // Clean single-pane SheetView (eliminates Excel split-pane row duplication across all screen resolutions)
        $sheetViewsXml = '<sheetViews><sheetView tabSelected="1" workbookViewId="0" showGridLines="1">'
            .'<selection activeCell="A1" sqref="A1"/>'
            .'</sheetView></sheetViews>';

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'</Types>',

            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',

            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="'.$xmlEscape($safeTitle).'" sheetId="1" r:id="rId1"/></sheets>'
                .'<definedNames><definedName name="_xlnm.Print_Titles" localSheetId="0">\''.$xmlEscape(str_replace("'", "''", $safeTitle)).'\'!$'.$headerRowNum.':$'.$headerRowNum.'</definedName></definedNames>'
                .'</workbook>',

            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',

            'xl/styles.xml' => $this->buildStylesXml(),

            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
                .'<dimension ref="A1:'.$lastCol.$finalRowNum.'"/>'
                .$sheetViewsXml
                .'<sheetFormatPr defaultRowHeight="20"/>'
                .$colsXml
                .'<sheetData>'.$sheetRowsXml.'</sheetData>'
                .$autoFilterXml
                .$mergeXml
                .'<pageMargins left="0.45" right="0.45" top="0.55" bottom="0.55" header="0.25" footer="0.25"/>'
                .'<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
                .'</worksheet>',
        ];

        return $this->packZip($files);
    }

    private function classifyColumn(string $header): string
    {
        $h = mb_strtolower($header);
        if (str_contains($h, '(rp)') || str_contains($h, 'nominal') || str_contains($h, 'harga')) {
            return 'currency';
        }
        if (str_contains($h, 'skor') || str_contains($h, 'rating')) {
            return 'decimal';
        }
        if (str_contains($h, 'terisi') || str_contains($h, 'kapasitas') || str_contains($h, 'kuota') || str_contains($h, 'terpakai') || str_contains($h, 'diskon')) {
            return 'integer';
        }
        if (str_contains($h, 'tanggal') || str_contains($h, 'waktu') || str_contains($h, 'jadwal') || str_contains($h, 'berlaku')) {
            return 'date';
        }
        if (str_contains($h, 'kode') || str_contains($h, 'order id') || str_contains($h, 'gateway') || str_contains($h, 'tipe')) {
            return 'code';
        }
        if (str_contains($h, 'status') || str_contains($h, 'arah')) {
            return 'status';
        }
        if (str_contains($h, 'ulasan') || str_contains($h, 'catatan') || str_contains($h, 'keterangan') || str_contains($h, 'rekomendasi') || str_contains($h, 'materi')) {
            return 'longtext';
        }

        return 'text';
    }

    private function resolveTextCellStyle(string $kind, string $value, bool $isEven): int
    {
        if ($kind === 'status') {
            $upper = strtoupper(trim($value));
            if (in_array($upper, ['PAID', 'CONFIRMED', 'ATTENDED', 'PRESENT', 'ACTIVE', 'AKTIF', 'INCOME', 'COMPLETED', 'SCHEDULED'], true)) {
                return 21; // Soft emerald badge
            }
            if (in_array($upper, ['PENDING', 'PENDING_PAYMENT', 'LATE', 'EXCUSED', 'IN_PROGRESS'], true)) {
                return 22; // Soft amber badge
            }
            if (in_array($upper, ['CANCELLED', 'REFUND', 'NONAKTIF', 'REJECTED', 'EXPIRED', 'FAILED'], true)) {
                return 23; // Soft rose badge
            }

            return $isEven ? 25 : 24;
        }

        if (in_array($kind, ['code', 'date'], true)) {
            return $isEven ? 25 : 24; // Centered text
        }

        return $isEven ? 5 : 0; // Left-aligned wrapped text
    }

    private function buildMergedBannerRow(
        int $rowNum,
        int $colCount,
        string $text,
        int $styleId,
        int $height,
        callable $xmlEscape
    ): string {
        $safe = $this->sanitizeCell($text);
        $cells = '<c r="A'.$rowNum.'" t="inlineStr" s="'.$styleId.'"><is><t xml:space="preserve">'.$xmlEscape($safe).'</t></is></c>';
        for ($i = 1; $i < $colCount; $i++) {
            $cells .= '<c r="'.$this->colLetter($i).$rowNum.'" s="'.$styleId.'"/>';
        }

        return '<row r="'.$rowNum.'" ht="'.$height.'" customHeight="1">'.$cells.'</row>';
    }

    private function buildStylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1">'
            .'<numFmt numFmtId="164" formatCode="&quot;Rp &quot;#,##0"/>'
            .'</numFmts>'
            .'<fonts count="10">'
            .'<font><sz val="10"/><color rgb="FF0F172A"/><name val="Calibri"/></font>'                      // 0: Body
            .'<font><b/><sz val="14"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'                  // 1: Banner Title
            .'<font><i/><sz val="10"/><color rgb="FF1E3A8A"/><name val="Calibri"/></font>'                  // 2: Subtitle
            .'<font><b/><sz val="8.5"/><color rgb="FF1E3A8A"/><name val="Calibri"/></font>'                 // 3: KPI Label
            .'<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'                  // 4: Table Header
            .'<font><b/><sz val="11.5"/><color rgb="FF0B1120"/><name val="Calibri"/></font>'                // 5: KPI Value
            .'<font><sz val="9"/><color rgb="FF334155"/><name val="Calibri"/></font>'                       // 6: Meta / Footer
            .'<font><b/><sz val="10"/><color rgb="FF0B1120"/><name val="Calibri"/></font>'                  // 7: Totals Bold
            .'<font><b/><sz val="9.5"/><color rgb="FF065F46"/><name val="Calibri"/></font>'                 // 8: Status Green
            .'<font><b/><sz val="9.5"/><color rgb="FF92400E"/><name val="Calibri"/></font>'                 // 9: Status Amber
            .'</fonts>'
            .'<fills count="12">'
            .'<fill><patternFill patternType="none"/></fill>'                                               // 0: White
            .'<fill><patternFill patternType="gray125"/></fill>'                                            // 1
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF0B1120"/></patternFill></fill>'        // 2: Navy Banner
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFEFF6FF"/></patternFill></fill>'        // 3: Soft Blue
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFDBEAFE"/></patternFill></fill>'        // 4: KPI Label Blue
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF1D4ED8"/></patternFill></fill>'        // 5: Royal Table Header
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/></patternFill></fill>'        // 6: Zebra Light
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/></patternFill></fill>'        // 7: Totals Fill
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFECFDF5"/></patternFill></fill>'        // 8: Emerald Tint
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFEF3C7"/></patternFill></fill>'        // 9: Amber Tint
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFEE2E2"/></patternFill></fill>'        // 10: Rose Tint
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/></patternFill></fill>'        // 11: Meta Strip
            .'</fills>'
            .'<borders count="6">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'                                   // 0: None
            .'<border>'                                                                                     // 1: Thin Slate
            .'<left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right>'
            .'<top style="thin"><color rgb="FFCBD5E1"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom><diagonal/></border>'
            .'<border>'                                                                                     // 2: KPI Value Accent Bottom
            .'<left style="thin"><color rgb="FF93C5FD"/></left><right style="thin"><color rgb="FF93C5FD"/></right>'
            .'<top style="thin"><color rgb="FFBFDBFE"/></top><bottom style="medium"><color rgb="FF1D4ED8"/></bottom><diagonal/></border>'
            .'<border>'                                                                                     // 3: Totals Top Medium + Bottom Double
            .'<left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right>'
            .'<top style="medium"><color rgb="FF0B1120"/></top><bottom style="double"><color rgb="FF0B1120"/></bottom><diagonal/></border>'
            .'<border>'                                                                                     // 4: Meta Strip Bottom Border
            .'<left/><right/><top/><bottom style="thin"><color rgb="FFCBD5E1"/></bottom><diagonal/></border>'
            .'<border>'                                                                                     // 5: Header Blue Border
            .'<left style="thin"><color rgb="FF1E40AF"/></left><right style="thin"><color rgb="FF1E40AF"/></right>'
            .'<top style="thin"><color rgb="FF1E40AF"/></top><bottom style="medium"><color rgb="FF0B1120"/></bottom><diagonal/></border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="26">'
            // 0: Body white left
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
            // 1: Navy Title Banner
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>'
            // 2: Subtitle Blue Strip
            .'<xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1" wrapText="1"/></xf>'
            // 3: KPI Label
            .'<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>'
            // 4: Table Column Header Left
            .'<xf numFmtId="0" fontId="4" fillId="5" borderId="5" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
            // 5: Body Zebra Left
            .'<xf numFmtId="0" fontId="0" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
            // 6: Integer white (#,##0)
            .'<xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            // 7: Totals Integer bold (#,##0)
            .'<xf numFmtId="3" fontId="7" fillId="7" borderId="3" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            // 8: KPI Value
            .'<xf numFmtId="0" fontId="5" fillId="0" borderId="2" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>'
            // 9: Integer zebra (#,##0)
            .'<xf numFmtId="3" fontId="0" fillId="6" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            // 10: Totals Label Left-Aligned (Merged, never clipped!)
            .'<xf numFmtId="0" fontId="7" fillId="7" borderId="3" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1" wrapText="1"/></xf>'
            // 11: Official Footer
            .'<xf numFmtId="0" fontId="6" fillId="11" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>'
            // 12: Metadata Strip
            .'<xf numFmtId="0" fontId="6" fillId="11" borderId="4" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1" wrapText="1"/></xf>'
            // 13: Table Column Header Right
            .'<xf numFmtId="0" fontId="4" fillId="5" borderId="5" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf>'
            // 14: Table Column Header Center
            .'<xf numFmtId="0" fontId="4" fillId="5" borderId="5" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            // 15: Currency white ("Rp "#,##0)
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            // 16: Currency zebra ("Rp "#,##0)
            .'<xf numFmtId="164" fontId="0" fillId="6" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            // 17: Decimal white (0.00)
            .'<xf numFmtId="2" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            // 18: Decimal zebra (0.00)
            .'<xf numFmtId="2" fontId="0" fillId="6" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            // 19: Totals Currency bold ("Rp "#,##0)
            .'<xf numFmtId="164" fontId="7" fillId="7" borderId="3" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            // 20: Totals Decimal bold (0.00)
            .'<xf numFmtId="2" fontId="7" fillId="7" borderId="3" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            // 21: Status Positive (Green)
            .'<xf numFmtId="0" fontId="8" fillId="8" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            // 22: Status Warning (Amber)
            .'<xf numFmtId="0" fontId="9" fillId="9" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            // 23: Status Negative (Rose)
            .'<xf numFmtId="0" fontId="7" fillId="10" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            // 24: Centered Body White
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            // 25: Centered Body Zebra
            .'<xf numFmtId="0" fontId="0" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'</cellXfs>'
            .'</styleSheet>';
    }

    private function colLetter(int $index): string
    {
        $letter = '';
        $n = $index + 1;
        while ($n > 0) {
            $rem = ($n - 1) % 26;
            $letter = chr(65 + $rem).$letter;
            $n = intdiv($n - 1, 26);
        }

        return $letter;
    }

    /**
     * Builds a standard PKZIP archive in memory using built-in zlib gzdeflate.
     *
     * @param  array<string, string>  $entries
     */
    public function packZip(array $entries): string
    {
        $localData = '';
        $centralDir = '';
        $offset = 0;
        $count = 0;

        foreach ($entries as $name => $content) {
            $uncompressedLen = strlen($content);
            $crc = crc32($content) & 0xFFFFFFFF;
            $compressed = gzdeflate($content, 6);
            $compressedLen = strlen($compressed);
            $nameLen = strlen($name);

            $localHeader = pack(
                'VvvvvvVVVvv',
                0x04034B50,
                20,
                0,
                8,
                0,
                0,
                $crc,
                $compressedLen,
                $uncompressedLen,
                $nameLen,
                0
            );

            $localData .= $localHeader.$name.$compressed;

            $centralHeader = pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014B50,
                20,
                20,
                0,
                8,
                0,
                0,
                $crc,
                $compressedLen,
                $uncompressedLen,
                $nameLen,
                0,
                0,
                0,
                0,
                0,
                $offset
            );

            $centralDir .= $centralHeader.$name;
            $offset += strlen($localHeader) + $nameLen + $compressedLen;
            $count++;
        }

        $eocd = pack(
            'VvvvvVVv',
            0x06054B50,
            0,
            0,
            $count,
            $count,
            strlen($centralDir),
            $offset,
            0
        );

        return $localData.$centralDir.$eocd;
    }
}
