<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <title>{{ $title }} — FitSpace</title>
  <style>
    @page { margin: 22px 24px 36px 24px; }
    body { font-family: DejaVu Sans, sans-serif; color: #0F172A; font-size: 9.5px; margin: 0; line-height: 1.4; }
    .banner { background: #0B1120; color: #FFFFFF; padding: 14px 18px; border-radius: 8px; border-bottom: 4px solid #1D4ED8; margin-bottom: 14px; }
    .banner-table { width: 100%; border-collapse: collapse; }
    .banner-table td { vertical-align: middle; border: none; padding: 0; }
    .logo-cell { width: 48px; }
    .logo-img { width: 40px; height: 40px; display: block; }
    .brand-eyebrow { font-size: 8px; letter-spacing: 1px; color: #38BDF8; font-weight: bold; text-transform: uppercase; }
    .brand-title { font-size: 16px; font-weight: bold; color: #FFFFFF; margin-top: 2px; }
    .brand-sub { font-size: 8.5px; color: #CBD5E1; margin-top: 2px; }
    .doc-meta { text-align: right; font-size: 8.5px; color: #E2E8F0; line-height: 1.5; }
    .doc-code { display: inline-block; background: #1D4ED8; color: #FFFFFF; font-weight: bold; padding: 2px 7px; border-radius: 4px; font-size: 8.5px; margin-bottom: 3px; }
    .kpi-table { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin: 0 -8px 14px -8px; }
    .kpi-card { background: #EFF6FF; border: 1px solid #BFDBFE; border-left: 4px solid #1D4ED8; border-radius: 6px; padding: 8px 10px; vertical-align: top; }
    .kpi-lbl { font-size: 7.5px; text-transform: uppercase; color: #1E3A8A; font-weight: bold; letter-spacing: 0.4px; }
    .kpi-val { font-size: 13px; font-weight: bold; color: #0B1120; margin-top: 3px; }
    .section-label { font-size: 10.5px; font-weight: bold; color: #0B1120; border-bottom: 1.5px solid #DBEAFE; padding-bottom: 4px; margin: 4px 0 8px 0; }
    table.data { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.data th { background: #1D4ED8; color: #FFFFFF; text-align: left; padding: 7px 8px; font-size: 8.5px; font-weight: bold; border: 1px solid #1E40AF; }
    table.data td { border: 1px solid #E2E8F0; padding: 6px 8px; vertical-align: top; font-size: 8.8px; }
    table.data tr:nth-child(even) td { background: #F8FAFC; }
    table.data td.num, table.data th.num { text-align: right; }
    table.data tr.total-row td { background: #E2E8F0; font-weight: bold; color: #0B1120; border-top: 2px solid #0B1120; }
    .foot { margin-top: 16px; border-top: 1px solid #CBD5E1; padding-top: 8px; font-size: 8px; color: #64748B; }
    .foot-table { width: 100%; border-collapse: collapse; }
    .foot-table td { border: none; padding: 0; }
    .foot-right { text-align: right; color: #1E3A8A; font-weight: bold; }
  </style>
</head>
<body>
  <div class="banner">
    <table class="banner-table">
      <tr>
        <td class="logo-cell">
          @if (!empty($logoDataUri))
            <img src="{{ $logoDataUri }}" alt="FitSpace Logo" class="logo-img" />
          @endif
        </td>
        <td>
          <div class="brand-eyebrow">FITSPACE &bull; INTEGRATED FITNESS CENTER PLATFORM</div>
          <div class="brand-title">{{ $title }}</div>
          <div class="brand-sub">{{ $subtitle ?? 'Laporan Operasional & Analisis Kinerja Resmi FitSpace' }}</div>
        </td>
        <td class="doc-meta">
          <div class="doc-code">{{ $docCode ?? 'FS-RPT' }}</div><br/>
          Dicetak: {{ $generatedAt }}<br/>
          Oleh: {{ $generatedBy }}<br/>
          Total Data: {{ count($rows) }} baris
        </td>
      </tr>
    </table>
  </div>

  @if (!empty($kpis))
    <table class="kpi-table">
      <tr>
        @foreach ($kpis as $kpi)
          <td class="kpi-card">
            <div class="kpi-lbl">{{ $kpi['label'] }}</div>
            <div class="kpi-val">{{ $kpi['value'] }}</div>
          </td>
        @endforeach
      </tr>
    </table>
  @endif

  <div class="section-label">Rincian Data Laporan</div>

  @php
    $colMeta = collect($headers)->map(function ($h) {
      $hl = strtolower((string) $h);
      return [
        'currency' => str_contains($hl, '(rp)') || str_contains($hl, 'nominal') || str_contains($hl, 'harga'),
        'decimal' => str_contains($hl, 'skor') || str_contains($hl, 'rating'),
        'num' => str_contains($hl, '(rp)') || str_contains($hl, 'nominal') || str_contains($hl, 'harga') || str_contains($hl, 'skor') || str_contains($hl, 'kuota') || str_contains($hl, 'terisi') || str_contains($hl, 'kapasitas') || str_contains($hl, 'rating') || str_contains($hl, 'terpakai'),
      ];
    })->all();
    $fmtCell = function ($val, int $idx) use ($colMeta) {
      if (! (is_int($val) || is_float($val))) {
        return (string) $val;
      }
      if (! empty($colMeta[$idx]['currency'])) {
        return 'Rp '.number_format((float) $val, 0, ',', '.');
      }
      $hasFrac = is_float($val) && floor($val) != $val;
      if (! empty($colMeta[$idx]['decimal']) || $hasFrac) {
        return number_format((float) $val, 2, ',', '.');
      }
      return number_format((float) $val, 0, ',', '.');
    };
  @endphp

  <table class="data">
    <thead>
      <tr>
        @foreach ($headers as $idx => $h)
          <th class="{{ ! empty($colMeta[$idx]['num']) ? 'num' : '' }}">{{ $h }}</th>
        @endforeach
      </tr>
    </thead>
    <tbody>
      @forelse ($rows as $row)
        <tr>
          @foreach (array_values($row) as $idx => $cell)
            @php $isNum = is_int($cell) || is_float($cell); @endphp
            <td class="{{ $isNum ? 'num' : '' }}">{{ $fmtCell($cell, $idx) }}</td>
          @endforeach
        </tr>
      @empty
        <tr>
          <td colspan="{{ count($headers) }}">Belum ada data tercatat pada periode yang dipilih.</td>
        </tr>
      @endforelse
      @if (!empty($totals))
        @php
          $firstMetric = count($headers);
          foreach ($totals as $tk => $tv) {
            if (is_int($tk) && $tk > 0 && $tv !== '' && $tv !== null) {
              $firstMetric = min($firstMetric, $tk);
            }
          }
          $labelSpan = max(1, min(count($headers), $firstMetric));
        @endphp
        <tr class="total-row">
          <td colspan="{{ $labelSpan }}">{{ $totals[0] ?? 'TOTAL / RINGKASAN' }}</td>
          @for ($i = $labelSpan; $i < count($headers); $i++)
            @php
              $tv = $totals[$i] ?? '';
              $isNum = is_int($tv) || is_float($tv);
            @endphp
            <td class="{{ $isNum ? 'num' : '' }}">{{ $fmtCell($tv, $i) }}</td>
          @endfor
        </tr>
      @endif
    </tbody>
  </table>

  <div class="foot">
    <table class="foot-table">
      <tr>
        <td>FitSpace Official &bull; Jakarta Selatan &amp; Bandung &bull; hello@fitspace.id &bull; @fitspace.id</td>
        <td class="foot-right">Powered by Karya Berdua Official</td>
      </tr>
    </table>
  </div>
</body>
</html>
