<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <title>Rapor Perkembangan Anak — {{ $child->full_name }} — FitSpace</title>
  <style>
    @page { margin: 22px 26px 36px 26px; }
    body { font-family: DejaVu Sans, sans-serif; color: #0F172A; font-size: 10px; margin: 0; line-height: 1.45; }
    .hdr { background: #0B1120; color: #FFFFFF; padding: 15px 18px; border-radius: 8px; border-bottom: 4px solid #1D4ED8; margin-bottom: 14px; }
    .hdr-table { width: 100%; border-collapse: collapse; }
    .hdr-table td { border: none; padding: 0; vertical-align: middle; }
    .logo-cell { width: 48px; }
    .logo-img { width: 40px; height: 40px; display: block; }
    .eyebrow { font-size: 8px; color: #38BDF8; font-weight: bold; letter-spacing: 0.9px; text-transform: uppercase; }
    .hdr h1 { margin: 2px 0 3px 0; font-size: 16px; color: #FFFFFF; }
    .hdr p { margin: 0; font-size: 8.8px; color: #CBD5E1; }
    .meta-right { text-align: right; font-size: 8.5px; color: #E2E8F0; }
    .doc-pill { display: inline-block; background: #1D4ED8; color: #FFFFFF; padding: 2px 7px; border-radius: 4px; font-weight: bold; margin-bottom: 4px; }
    .kpi-table { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin: 0 -8px 14px -8px; }
    .kpi-box { background: #EFF6FF; border: 1px solid #BFDBFE; border-left: 4px solid #1D4ED8; border-radius: 6px; padding: 8px 10px; }
    .kpi-lbl { font-size: 7.5px; color: #1E3A8A; font-weight: bold; text-transform: uppercase; }
    .kpi-val { font-size: 14px; font-weight: bold; color: #0B1120; margin-top: 2px; }
    .section-title { font-size: 11px; font-weight: bold; color: #0B1120; border-bottom: 1.5px solid #DBEAFE; padding-bottom: 4px; margin: 12px 0 8px 0; }
    .meta { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    .meta td { padding: 6px 9px; border: 1px solid #E2E8F0; font-size: 9.2px; }
    .meta td.lbl { width: 24%; font-weight: bold; background: #F8FAFC; color: #1E293B; }
    .card { border: 1px solid #CBD5E1; border-radius: 7px; padding: 12px 14px; margin-bottom: 12px; page-break-inside: avoid; }
    .card-head { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    .card-head td { border: none; padding: 0; vertical-align: middle; }
    .card h2 { margin: 0; font-size: 12.5px; color: #1D4ED8; }
    .avg-badge { background: #0B1120; color: #38BDF8; font-weight: bold; font-size: 9.5px; padding: 3px 9px; border-radius: 12px; display: inline-block; }
    .scores { width: 100%; border-collapse: collapse; margin: 8px 0 10px 0; }
    .scores th { background: #1D4ED8; color: #FFFFFF; text-align: left; padding: 6px 8px; font-size: 9px; border: 1px solid #1E40AF; }
    .scores td { padding: 6px 8px; border: 1px solid #E2E8F0; font-size: 9px; vertical-align: middle; }
    .scores tr:nth-child(even) td { background: #F8FAFC; }
    .bar-bg { width: 100%; height: 8px; background: #E2E8F0; border-radius: 4px; overflow: hidden; }
    .bar-fill { height: 8px; background: #1D4ED8; border-radius: 4px; }
    .bar-fill-hi { background: #15803D; }
    .note-box { background: #F8FAFC; border-left: 3px solid #1D4ED8; padding: 7px 10px; border-radius: 4px; margin-top: 6px; font-size: 9.2px; }
    .rubric { background: #F1F5F9; border: 1px solid #CBD5E1; border-radius: 6px; padding: 8px 10px; font-size: 8.5px; color: #334155; margin-top: 10px; }
    .foot { margin-top: 16px; font-size: 8px; color: #64748B; border-top: 1px solid #CBD5E1; padding-top: 8px; }
    .foot-table { width: 100%; border-collapse: collapse; }
    .foot-table td { border: none; padding: 0; }
    .foot-right { text-align: right; color: #1E3A8A; font-weight: bold; }
  </style>
</head>
<body>
  <div class="hdr">
    <table class="hdr-table">
      <tr>
        <td class="logo-cell">
          @if (!empty($logoDataUri))
            <img src="{{ $logoDataUri }}" alt="FitSpace Logo" class="logo-img" />
          @endif
        </td>
        <td>
          <div class="eyebrow">FITSPACE &bull; LAPORAN CAPAIAN LATIHAN RESMI</div>
          <h1>Rapor Perkembangan Latihan Anak</h1>
          <p>Evaluasi Terukur Bersama Coach Tersertifikasi FitSpace</p>
        </td>
        <td class="meta-right">
          <div class="doc-pill">FS-RAPOR-{{ $child->id }}</div><br/>
          Dicetak: {{ $generatedAt }}<br/>
          FitSpace Official
        </td>
      </tr>
    </table>
  </div>

  @php
    $attended = (int) ($attendanceSummary->attended ?? 0);
    $totalAtt = (int) ($attendanceSummary->total ?? 0);
    $attPct = $totalAtt > 0 ? round(($attended / $totalAtt) * 100) : 100;
    $avgOverall = count($reports) > 0
      ? number_format(collect($reports)->avg('average_score'), 2)
      : '0.00';
  @endphp

  <table class="kpi-table">
    <tr>
      <td class="kpi-box">
        <div class="kpi-lbl">Total Evaluasi</div>
        <div class="kpi-val">{{ count($reports) }} Sesi</div>
      </td>
      <td class="kpi-box">
        <div class="kpi-lbl">Skor Rata-Rata Keseluruhan</div>
        <div class="kpi-val">{{ $avgOverall }} / 4.00</div>
      </td>
      <td class="kpi-box">
        <div class="kpi-lbl">Tingkat Kehadiran</div>
        <div class="kpi-val">{{ $attended }}/{{ $totalAtt }} ({{ $attPct }}%)</div>
      </td>
    </tr>
  </table>

  <div class="section-title">Identitas Peserta &amp; Wali</div>
  <table class="meta">
    <tr>
      <td class="lbl">Nama Lengkap Anak</td>
      <td><strong>{{ $child->full_name }}</strong></td>
      <td class="lbl">Usia</td>
      <td>{{ $childAge }} Tahun</td>
    </tr>
    <tr>
      <td class="lbl">Tanggal Lahir</td>
      <td>{{ $child->birth_date }}</td>
      <td class="lbl">Orang Tua / Wali</td>
      <td>{{ $child->guardian_name }} ({{ $child->relationship }})</td>
    </tr>
    <tr>
      <td class="lbl">Catatan Medis</td>
      <td colspan="3">{{ $child->medical_notes ?: 'Tidak ada catatan khusus — siap berlatih aktif' }}</td>
    </tr>
  </table>

  <div class="section-title">Detail Evaluasi Perkembangan Per Sesi</div>

  @forelse ($reports as $rep)
    <div class="card">
      <table class="card-head">
        <tr>
          <td><h2>{{ $rep->class_name }}</h2></td>
          <td style="text-align:right;"><span class="avg-badge">Rata-Rata {{ number_format((float) $rep->average_score, 2) }} / 4.00</span></td>
        </tr>
      </table>
      <p style="margin:0 0 6px 0; color:#475569; font-size:9px;">
        <strong>Coach:</strong> {{ $rep->coach_name }} &bull;
        <strong>Tanggal Evaluasi:</strong> {{ $rep->evaluated_at }} &bull;
        <strong>Materi Latihan:</strong> {{ $rep->material_studied ?: '-' }}
      </p>

      @if (count($rep->details) > 0)
        <table class="scores">
          <thead>
            <tr>
              <th style="width:36%;">Indikator Evaluasi</th>
              <th style="width:14%;">Skor</th>
              <th style="width:24%;">Visual Capaian</th>
              <th style="width:26%;">Predikat</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($rep->details as $d)
              @php $pct = min(100, max(10, ((int) $d->score) * 25)); @endphp
              <tr>
                <td><strong>{{ $d->indicator_name }}</strong></td>
                <td><strong>{{ $d->score }} / 4</strong></td>
                <td>
                  <div class="bar-bg">
                    <div class="bar-fill {{ ((int) $d->score) >= 4 ? 'bar-fill-hi' : '' }}" style="width:{{ $pct }}%;"></div>
                  </div>
                </td>
                <td>{{ $d->score_label }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif

      <div class="note-box">
        <strong>Catatan Coach:</strong> {{ $rep->progress_notes ?: '-' }}<br/>
        @if ($rep->improvement_areas)
          <strong>Fokus Pengembangan:</strong> {{ $rep->improvement_areas }}<br/>
        @endif
        <strong>Rekomendasi Latihan Berikutnya:</strong> {{ $rep->next_recommendation ?: '-' }}
      </div>
    </div>
  @empty
    <div class="card">
      <p style="margin:0;">Belum ada catatan evaluasi perkembangan dari Coach untuk peserta ini.</p>
    </div>
  @endforelse

  <div class="rubric">
    <strong>Panduan Skala Evaluasi FitSpace:</strong>
    1 = Belum Menguasai &bull; 2 = Mulai Berkembang &bull; 3 = Berkembang Sesuai Target &bull; 4 = Sangat Baik.
  </div>

  <div class="foot">
    <table class="foot-table">
      <tr>
        <td>Dokumen Rahasia Khusus Wali Sah &bull; FitSpace Official (hello@fitspace.id &bull; @fitspace.id)</td>
        <td class="foot-right">Powered by Karya Berdua Official</td>
      </tr>
    </table>
  </div>
</body>
</html>
