<?php

namespace App\Services;

/**
 * Pure-PHP OpenXML Word (.docx) Executive Report Generator.
 * Produces clean, boardroom-ready Word documents with FitSpace corporate header banner,
 * metadata summary, KPI highlights, styled bordered tables, and official footer —
 * zero external dependency on ext-zip or ext-gd.
 */
class DocxExporter
{
    public function __construct(
        private readonly XlsxExporter $zipHelper,
    ) {}

    /**
     * Builds an executive landscape or portrait .docx tabular report.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<string, mixed>  $meta
     */
    public function buildTableReport(string $title, array $headers, array $rows, array $meta = []): string
    {
        $docCode = (string) ($meta['doc_code'] ?? ('FS-DOC-'.date('Ymd')));
        $generatedAt = (string) ($meta['generated_at'] ?? (now()->format('d M Y H:i').' WIB'));
        $generatedBy = (string) ($meta['generated_by'] ?? 'FitSpace');
        $subtitle = (string) ($meta['subtitle'] ?? 'Laporan Resmi Operasional & Kinerja Studio FitSpace');
        $kpis = is_array($meta['kpis'] ?? null) ? $meta['kpis'] : [];
        $totals = is_array($meta['totals'] ?? null) ? $meta['totals'] : [];
        $landscape = count($headers) >= 6;

        $body = '';
        $body .= $this->brandBanner($title, $subtitle, $docCode, $generatedAt, $generatedBy);

        if (count($kpis) > 0) {
            $body .= $this->sectionHeading('Ringkasan Eksekutif');
            $body .= $this->kpiTable($kpis);
        }

        $body .= $this->sectionHeading('Rincian Data Laporan ('.count($rows).' Baris)');
        $body .= $this->styledTable($headers, $rows, $totals);
        $body .= $this->closingNote();

        return $this->packageDocx($body, $landscape);
    }

    /**
     * Builds a detailed executive Child Progress Report in Word (.docx) format.
     *
     * @param  array<int, object>  $reports
     */
    public function buildChildProgressDocx(
        object $child,
        int $childAge,
        array $reports,
        ?object $attendanceSummary,
        string $generatedAt
    ): string {
        $docCode = 'FS-RAPOR-'.date('Ymd').'-'.$child->id;
        $attended = (int) ($attendanceSummary->attended ?? 0);
        $totalAtt = (int) ($attendanceSummary->total ?? 0);
        $avgAll = count($reports) > 0
            ? number_format(array_sum(array_map(static fn ($r) => (float) $r->average_score, $reports)) / count($reports), 2)
            : '0.00';

        $body = '';
        $body .= $this->brandBanner(
            'Rapor Perkembangan Latihan Anak',
            'Laporan Resmi Capaian Latihan Peserta — FitSpace',
            $docCode,
            $generatedAt,
            (string) $child->guardian_name.' ('.$child->relationship.')'
        );

        $body .= $this->sectionHeading('Identitas Peserta');
        $body .= $this->styledTable(
            ['Informasi', 'Detail Peserta', 'Informasi', 'Detail'],
            [
                ['Nama Lengkap', (string) $child->full_name, 'Usia', $childAge.' Tahun'],
                ['Tanggal Lahir', (string) $child->birth_date, 'Orang Tua / Wali', (string) $child->guardian_name.' ('.$child->relationship.')'],
                ['Catatan Medis', (string) ($child->medical_notes ?: 'Tidak ada catatan khusus'), 'Kehadiran', "{$attended} hadir dari {$totalAtt} sesi"],
            ]
        );

        $body .= $this->sectionHeading('Ringkasan Capaian');
        $body .= $this->kpiTable([
            ['label' => 'Total Evaluasi', 'value' => count($reports).' Sesi'],
            ['label' => 'Skor Rata-Rata', 'value' => $avgAll.' / 4.00'],
            ['label' => 'Kehadiran Tercatat', 'value' => "{$attended} / {$totalAtt}"],
        ]);

        $body .= $this->sectionHeading('Detail Evaluasi Per Sesi');
        if (count($reports) === 0) {
            $body .= $this->paragraph('Belum ada catatan evaluasi perkembangan dari Coach untuk peserta ini.', '475569');
        } else {
            foreach ($reports as $rep) {
                $body .= $this->subHeading(
                    $rep->class_name.' — Skor Rata-Rata '.number_format((float) $rep->average_score, 2).' / 4.00'
                );
                $body .= $this->paragraph(
                    "Coach: {$rep->coach_name}  |  Tanggal: {$rep->evaluated_at}  |  Materi: ".($rep->material_studied ?: '-'),
                    '475569',
                    19
                );

                $details = is_array($rep->details ?? null) ? $rep->details : [];
                if (count($details) > 0) {
                    $detailRows = array_map(static fn ($d) => [
                        (string) $d->indicator_name,
                        $d->score.' / 4',
                        (string) $d->score_label,
                    ], $details);
                    $body .= $this->styledTable(['Indikator Evaluasi', 'Skor', 'Capaian'], $detailRows);
                }

                $body .= $this->paragraph('Catatan Coach: '.($rep->progress_notes ?: '-'), '0F172A', 20);
                if (! empty($rep->improvement_areas)) {
                    $body .= $this->paragraph('Area Peningkatan: '.$rep->improvement_areas, '334155', 20);
                }
                $body .= $this->paragraph('Rekomendasi Latihan Berikutnya: '.($rep->next_recommendation ?: '-'), '1D4ED8', 20, true);
            }
        }

        $body .= $this->paragraph(
            'Keterangan Skala Penilaian: 1 = Belum Menguasai  •  2 = Mulai Berkembang  •  3 = Berkembang Sesuai Target  •  4 = Sangat Baik.',
            '475569',
            18
        );
        $body .= $this->closingNote();

        return $this->packageDocx($body, false);
    }

    private function esc(mixed $v): string
    {
        return htmlspecialchars((string) ($v ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function brandBanner(string $title, string $subtitle, string $docCode, string $generatedAt, string $generatedBy): string
    {
        $titleEsc = $this->esc($title);
        $subEsc = $this->esc($subtitle);
        $metaEsc = $this->esc("No. Dokumen: {$docCode}   |   Tanggal Cetak: {$generatedAt}   |   Oleh: {$generatedBy}");

        return '<w:tbl>'
            .'<w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>'
            .'<w:top w:val="none"/><w:left w:val="none"/><w:right w:val="none"/>'
            .'<w:bottom w:val="single" w:sz="18" w:space="0" w:color="1D4ED8"/>'
            .'</w:tblBorders></w:tblPr>'
            .'<w:tr><w:tc><w:tcPr><w:shd w:val="clear" w:color="auto" w:fill="0B1120"/>'
            .'<w:tcMar><w:top w:w="180" w:type="dxa"/><w:bottom w:w="180" w:type="dxa"/><w:left w:w="220" w:type="dxa"/><w:right w:w="220" w:type="dxa"/></w:tcMar></w:tcPr>'
            .'<w:p><w:pPr><w:spacing w:after="40"/></w:pPr><w:r><w:rPr><w:b/><w:color w:val="38BDF8"/><w:sz w:val="20"/></w:rPr><w:t>FITSPACE  •  INTEGRATED FITNESS CENTER PLATFORM</w:t></w:r></w:p>'
            .'<w:p><w:pPr><w:spacing w:after="40"/></w:pPr><w:r><w:rPr><w:b/><w:color w:val="FFFFFF"/><w:sz w:val="32"/></w:rPr><w:t>'.$titleEsc.'</w:t></w:r></w:p>'
            .'<w:p><w:pPr><w:spacing w:after="60"/></w:pPr><w:r><w:rPr><w:color w:val="CBD5E1"/><w:sz w:val="19"/></w:rPr><w:t>'.$subEsc.'</w:t></w:r></w:p>'
            .'<w:p><w:pPr><w:spacing w:after="0"/></w:pPr><w:r><w:rPr><w:color w:val="94A3B8"/><w:sz w:val="17"/></w:rPr><w:t>'.$metaEsc.'</w:t></w:r></w:p>'
            .'</w:tc></w:tr></w:tbl>'
            .'<w:p><w:pPr><w:spacing w:after="100"/></w:pPr></w:p>';
    }

    private function sectionHeading(string $text): string
    {
        return '<w:p><w:pPr><w:spacing w:before="140" w:after="80"/><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="3" w:color="DBEAFE"/></w:pBdr></w:pPr>'
            .'<w:r><w:rPr><w:b/><w:color w:val="0B1120"/><w:sz w:val="24"/></w:rPr><w:t>'.$this->esc($text).'</w:t></w:r></w:p>';
    }

    private function subHeading(string $text): string
    {
        return '<w:p><w:pPr><w:spacing w:before="160" w:after="60"/></w:pPr>'
            .'<w:r><w:rPr><w:b/><w:color w:val="1D4ED8"/><w:sz w:val="22"/></w:rPr><w:t>'.$this->esc($text).'</w:t></w:r></w:p>';
    }

    private function paragraph(string $text, string $color = '0F172A', int $sizeHalfPt = 20, bool $bold = false): string
    {
        $b = $bold ? '<w:b/>' : '';

        return '<w:p><w:pPr><w:spacing w:before="40" w:after="80"/></w:pPr>'
            .'<w:r><w:rPr>'.$b.'<w:color w:val="'.$color.'"/><w:sz w:val="'.$sizeHalfPt.'"/></w:rPr><w:t xml:space="preserve">'.$this->esc($text).'</w:t></w:r></w:p>';
    }

    /**
     * @param  array<int, array{label:string, value:string}>  $kpis
     */
    private function kpiTable(array $kpis): string
    {
        $cells = '';
        foreach ($kpis as $kpi) {
            $cells .= '<w:tc><w:tcPr><w:shd w:val="clear" w:color="auto" w:fill="EFF6FF"/>'
                .'<w:tcMar><w:top w:w="100" w:type="dxa"/><w:bottom w:w="100" w:type="dxa"/><w:left w:w="120" w:type="dxa"/><w:right w:w="120" w:type="dxa"/></w:tcMar></w:tcPr>'
                .'<w:p><w:pPr><w:spacing w:after="30"/></w:pPr><w:r><w:rPr><w:b/><w:color w:val="1E3A8A"/><w:sz w:val="16"/></w:rPr><w:t>'.$this->esc(mb_strtoupper($kpi['label'] ?? '')).'</w:t></w:r></w:p>'
                .'<w:p><w:pPr><w:spacing w:after="0"/></w:pPr><w:r><w:rPr><w:b/><w:color w:val="0B1120"/><w:sz w:val="24"/></w:rPr><w:t>'.$this->esc($kpi['value'] ?? '-').'</w:t></w:r></w:p>'
                .'</w:tc>';
        }

        return '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>'
            .'<w:top w:val="single" w:sz="4" w:color="BFDBFE"/><w:bottom w:val="single" w:sz="4" w:color="BFDBFE"/>'
            .'<w:left w:val="single" w:sz="4" w:color="BFDBFE"/><w:right w:val="single" w:sz="4" w:color="BFDBFE"/>'
            .'<w:insideV w:val="single" w:sz="4" w:color="BFDBFE"/>'
            .'</w:tblBorders></w:tblPr><w:tr>'.$cells.'</w:tr></w:tbl>'
            .'<w:p><w:pPr><w:spacing w:after="80"/></w:pPr></w:p>';
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int|string, mixed>  $totals
     */
    private function styledTable(array $headers, array $rows, array $totals = []): string
    {
        $headersList = array_values($headers);
        $colCount = count($headersList);
        $headerCells = '';
        $colIsCurrency = [];
        $colIsDecimal = [];

        foreach ($headersList as $cIdx => $h) {
            $hl = mb_strtolower((string) $h);
            $colIsCurrency[$cIdx] = str_contains($hl, '(rp)') || str_contains($hl, 'nominal') || str_contains($hl, 'harga');
            $colIsDecimal[$cIdx] = str_contains($hl, 'skor') || str_contains($hl, 'rating');
            $hAlign = ($colIsCurrency[$cIdx] || str_contains($hl, 'kuota') || str_contains($hl, 'terisi') || str_contains($hl, 'kapasitas'))
                ? '<w:jc w:val="right"/>'
                : ($colIsDecimal[$cIdx] ? '<w:jc w:val="center"/>' : '');

            $headerCells .= '<w:tc><w:tcPr><w:shd w:val="clear" w:color="auto" w:fill="1D4ED8"/>'
                .'<w:tcMar><w:top w:w="90" w:type="dxa"/><w:bottom w:w="90" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tcMar></w:tcPr>'
                .'<w:p><w:pPr>'.$hAlign.'<w:spacing w:after="0"/></w:pPr><w:r><w:rPr><w:b/><w:color w:val="FFFFFF"/><w:sz w:val="18"/></w:rPr><w:t>'.$this->esc($h).'</w:t></w:r></w:p>'
                .'</w:tc>';
        }

        $formatNum = static function (int|float $val, bool $isCurrency, bool $isDecimal): string {
            if ($isCurrency) {
                return 'Rp '.number_format((float) $val, 0, ',', '.');
            }
            $hasFraction = is_float($val) && floor($val) != $val;
            if ($isDecimal || $hasFraction) {
                return number_format((float) $val, 2, ',', '.');
            }

            return number_format((float) $val, 0, ',', '.');
        };

        $rowsXml = '<w:tr><w:trPr><w:tblHeader/></w:trPr>'.$headerCells.'</w:tr>';

        if (count($rows) === 0) {
            $rowsXml .= '<w:tr><w:tc><w:tcPr><w:gridSpan w:val="'.$colCount.'"/><w:shd w:val="clear" w:color="auto" w:fill="F8FAFC"/>'
                .'<w:tcMar><w:top w:w="100" w:type="dxa"/><w:bottom w:w="100" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tcMar></w:tcPr>'
                .'<w:p><w:r><w:rPr><w:color w:val="64748B"/><w:sz w:val="18"/></w:rPr><w:t>Belum ada data tercatat pada periode ini.</w:t></w:r></w:p>'
                .'</w:tc></w:tr>';
        } else {
            foreach ($rows as $rIdx => $row) {
                $fill = ($rIdx % 2) === 1 ? 'F8FAFC' : 'FFFFFF';
                $cells = '';
                foreach (array_values($row) as $cIdx => $val) {
                    $isNum = is_int($val) || is_float($val);
                    $isCur = $colIsCurrency[$cIdx] ?? false;
                    $isDec = $colIsDecimal[$cIdx] ?? false;
                    $formatted = $isNum ? $formatNum($val, $isCur, $isDec) : (string) ($val ?? '-');
                    $align = $isNum ? ($isDec ? '<w:jc w:val="center"/>' : '<w:jc w:val="right"/>') : '';
                    $cells .= '<w:tc><w:tcPr><w:shd w:val="clear" w:color="auto" w:fill="'.$fill.'"/>'
                        .'<w:tcMar><w:top w:w="75" w:type="dxa"/><w:bottom w:w="75" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tcMar></w:tcPr>'
                        .'<w:p><w:pPr>'.$align.'<w:spacing w:after="0"/></w:pPr><w:r><w:rPr><w:color w:val="0F172A"/><w:sz w:val="18"/></w:rPr><w:t xml:space="preserve">'.$this->esc($formatted).'</w:t></w:r></w:p>'
                        .'</w:tc>';
                }
                $rowsXml .= '<w:tr>'.$cells.'</w:tr>';
            }
        }

        if (count($totals) > 0 && $colCount > 0) {
            $firstMetricCol = $colCount;
            foreach ($totals as $idx => $tv) {
                if (is_int($idx) && $idx > 0 && $tv !== '' && $tv !== null) {
                    $firstMetricCol = min($firstMetricCol, $idx);
                }
            }
            $labelSpan = max(1, min($colCount, $firstMetricCol));
            $totCells = '<w:tc><w:tcPr>'.($labelSpan > 1 ? '<w:gridSpan w:val="'.$labelSpan.'"/>' : '')
                .'<w:shd w:val="clear" w:color="auto" w:fill="E2E8F0"/>'
                .'<w:tcMar><w:top w:w="85" w:type="dxa"/><w:bottom w:w="85" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tcMar></w:tcPr>'
                .'<w:p><w:pPr><w:spacing w:after="0"/></w:pPr><w:r><w:rPr><w:b/><w:color w:val="0B1120"/><w:sz w:val="18"/></w:rPr><w:t xml:space="preserve">'.$this->esc((string) ($totals[0] ?? 'TOTAL / RINGKASAN')).'</w:t></w:r></w:p></w:tc>';

            for ($cIdx = $labelSpan; $cIdx < $colCount; $cIdx++) {
                $tv = $totals[$cIdx] ?? '';
                $isNum = is_int($tv) || is_float($tv);
                $isCur = $colIsCurrency[$cIdx] ?? false;
                $isDec = $colIsDecimal[$cIdx] ?? false;
                $formatted = $isNum ? $formatNum($tv, $isCur, $isDec) : (string) $tv;
                $align = $isNum ? ($isDec ? '<w:jc w:val="center"/>' : '<w:jc w:val="right"/>') : '';
                $totCells .= '<w:tc><w:tcPr><w:shd w:val="clear" w:color="auto" w:fill="E2E8F0"/>'
                    .'<w:tcMar><w:top w:w="85" w:type="dxa"/><w:bottom w:w="85" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tcMar></w:tcPr>'
                    .'<w:p><w:pPr>'.$align.'<w:spacing w:after="0"/></w:pPr><w:r><w:rPr><w:b/><w:color w:val="0B1120"/><w:sz w:val="18"/></w:rPr><w:t xml:space="preserve">'.$this->esc($formatted).'</w:t></w:r></w:p></w:tc>';
            }
            $rowsXml .= '<w:tr>'.$totCells.'</w:tr>';
        }

        return '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>'
            .'<w:top w:val="single" w:sz="4" w:color="CBD5E1"/><w:bottom w:val="single" w:sz="6" w:color="94A3B8"/>'
            .'<w:left w:val="single" w:sz="4" w:color="CBD5E1"/><w:right w:val="single" w:sz="4" w:color="CBD5E1"/>'
            .'<w:insideH w:val="single" w:sz="4" w:color="E2E8F0"/><w:insideV w:val="single" w:sz="4" w:color="E2E8F0"/>'
            .'</w:tblBorders></w:tblPr>'.$rowsXml.'</w:tbl>'
            .'<w:p><w:pPr><w:spacing w:after="100"/></w:pPr></w:p>';
    }

    private function closingNote(): string
    {
        return '<w:p><w:pPr><w:spacing w:before="140" w:after="0"/><w:pBdr><w:top w:val="single" w:sz="4" w:space="6" w:color="CBD5E1"/></w:pBdr></w:pPr>'
            .'<w:r><w:rPr><w:color w:val="64748B"/><w:sz w:val="16"/></w:rPr>'
            .'<w:t>Dokumen resmi FitSpace (hello@fitspace.id  •  @fitspace.id)  •  Powered by Karya Berdua Official</w:t>'
            .'</w:r></w:p>';
    }

    private function packageDocx(string $bodyXml, bool $landscape): string
    {
        $pgSz = $landscape
            ? '<w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/>'
            : '<w:pgSz w:w="11906" w:h="16838"/>';

        $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'
            .$bodyXml
            .'<w:sectPr>'
            .$pgSz
            .'<w:pgMar w:top="850" w:right="850" w:bottom="850" w:left="850" w:header="450" w:footer="450" w:gutter="0"/>'
            .'</w:sectPr>'
            .'</w:body></w:document>';

        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:sz w:val="20"/></w:rPr></w:rPrDefault></w:docDefaults>'
            .'</w:styles>';

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                .'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
                .'</Types>',

            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                .'</Relationships>',

            'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',

            'word/styles.xml' => $stylesXml,
            'word/document.xml' => $documentXml,
        ];

        return $this->zipHelper->packZip($files);
    }
}
