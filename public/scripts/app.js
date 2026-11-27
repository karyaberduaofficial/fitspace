/* ============================================================================
 * FitSpace PWA — Interactive Multi-Role, Multi-Hardware & Smart Glasses Runtime
 * 100% CSP script-src 'self' compliant (zero inline handlers, strict escaping)
 * ============================================================================ */

(() => {
  'use strict';

  const API_BASE = '/api/v1';
  const STORAGE_TOKEN_KEY = 'fitspace_auth_token';

  const state = {
    token: sessionStorage.getItem(STORAGE_TOKEN_KEY) || '',
    user: null,
    demoMode: false,
    googleOauthEnabled: false,
    branches: [],
    categories: [],
    levels: [],
    classes: [],
    schedules: [],
    plans: [],
    selectedBranchId: '',
    selectedCategoryId: '',
    activeView: 'portal', // 'portal' | 'workspace'
    activeTab: 'overview',
    activity: 'portal',
    hardware: 'auto',
    hudIndex: 0,
    workspaceData: {},
    apiCache: {},
    lastAuthSyncAt: 0,
    pendingPollTimer: null,
    deferredInstallPrompt: null,
  };

  function clearApiCache(prefix = '') {
    if (!prefix) {
      state.apiCache = {};
      return;
    }
    for (const k of Object.keys(state.apiCache)) {
      if (k.startsWith(prefix)) delete state.apiCache[k];
    }
  }

  async function apiCached(cacheKey, path, opts = {}) {
    const ttlMs = opts.ttlMs ?? 12000;
    const hit = state.apiCache[cacheKey];
    if (!opts.force && hit && Date.now() - hit.ts < ttlMs) {
      return hit.data;
    }
    const data = await api(path);
    state.apiCache[cacheKey] = { ts: Date.now(), data };
    return data;
  }

  function applyFreshUser(freshUser) {
    if (!freshUser) return;
    const prevStatus = state.user?.approval_status;
    state.user = freshUser;
    updateAuthNav();
    syncPendingApprovalWatcher();
    if (prevStatus && prevStatus !== 'approved' && freshUser.approval_status === 'approved') {
      clearApiCache();
      showToast('Akun Anda telah disetujui! Semua fitur booking, membership, QR & laporan kini langsung aktif.');
      if (state.activeView === 'workspace') {
        loadWorkspaceTab(state.activeTab, { force: true });
      }
    }
  }

  async function syncAuthStatus(force = false) {
    if (!state.token || !state.user || state.user.is_guest) return state.user;
    if (!force && Date.now() - state.lastAuthSyncAt < 2500) return state.user;
    state.lastAuthSyncAt = Date.now();
    try {
      const data = await api('/auth/me');
      applyFreshUser(data.user);
      return state.user;
    } catch {
      return state.user;
    }
  }

  function syncPendingApprovalWatcher() {
    if (state.pendingPollTimer) {
      clearInterval(state.pendingPollTimer);
      state.pendingPollTimer = null;
    }
    if (state.token && state.user && !state.user.is_guest && state.user.role !== 'admin' && state.user.approval_status === 'pending') {
      state.pendingPollTimer = setInterval(() => {
        if (document.visibilityState === 'visible') {
          syncAuthStatus(true);
        }
      }, 5000);
    }
  }

  const ACTIVITY_META = {
    portal: {
      badge: 'INTEGRATED FITNESS CENTER PLATFORM',
      cta: 'Jelajahi & Booking Kelas',
      summary: 'Temukan program latihan sesuai level dan tujuan kebugaran Anda — dari pemula hingga intensif.',
    },
    'field-ops': {
      badge: 'OPERASIONAL QR LAPANGAN · SATU TANGAN',
      cta: 'Buka Scanner QR Kehadiran',
      summary: 'Mode check-in studio cepat dengan validasi tiket QR sekali pakai dan kontrol kapasitas ruangan real-time.',
    },
    'teaching-stage': {
      badge: 'MODE MENGAJAR COACH · TELEPROMPTER STUDIO',
      cta: 'Lihat Materi & Progres Latihan',
      summary: 'Panduan materi latihan berurutan, durasi sesi, peralatan studio, dan pencatatan perkembangan peserta.',
    },
    'kiosk-display': {
      badge: 'PAPAN JADWAL LOBI · SMART TV KIOSK',
      cta: 'Lihat Jadwal Live Studio',
      summary: 'Tampilan jadwal kelas berhuruf besar kontras tinggi untuk layar lobi fitness center dan ruang tunggu.',
    },
    'service-desk': {
      badge: 'LAYANAN PENDAFTARAN & BOOKING CEPAT',
      cta: 'Daftar & Booking Sekarang',
      summary: 'Alur tervalidasi untuk pendaftaran member, pembelian membership, dan pembayaran digital.',
    },
    'handsfree-hud': {
      badge: 'ASISTEN HANDS-FREE · KACAMATA PINTAR HUD',
      cta: 'Aktifkan Optical HUD (G)',
      summary: 'Kartu tunggal Optical Black (#000000) berkontras tinggi dengan navigasi D-Pad, TTS Bacakan, dan Perintah Suara.',
    },
  };

  const CLASS_PHOTO_MAP = {
    'reformer-pilates': '/images/classes/reformer-pilates.jpg',
    'zumba-regular': '/images/classes/zumba-cardio.jpg',
    'boxing-intermediate': '/images/classes/boxing-conditioning.jpg',
    'swimming-kids': '/images/classes/swimming-kids.jpg',
    'taekwondo-kids': '/images/classes/taekwondo-kids.jpg',
    'vinyasa-yoga': '/images/classes/vinyasa-yoga.jpg',
    pilates: '/images/classes/reformer-pilates.jpg',
    zumba: '/images/classes/zumba-cardio.jpg',
    boxing: '/images/classes/boxing-conditioning.jpg',
    swimming: '/images/classes/swimming-kids.jpg',
    martial: '/images/classes/taekwondo-kids.jpg',
    'martial-arts': '/images/classes/taekwondo-kids.jpg',
    yoga: '/images/classes/vinyasa-yoga.jpg',
  };

  const PLAN_META = {
    'paket-starter-5': {
      image: '/images/plans/starter-pass.jpg',
      badge: 'STARTER PASS',
      tagline: 'Ideal untuk mulai rutin berolahraga',
      perks: ['5 kuota sesi untuk semua kategori kelas', 'Masa berlaku fleksibel 30 hari', 'Reschedule mudah hingga H-8 jam'],
    },
    'paket-10-sesi': {
      image: '/images/plans/flex-10.jpg',
      badge: 'HEMAT 15%',
      tagline: 'Fleksibel tanpa komitmen bulanan',
      perks: ['10 kuota sesi berlaku 60 hari', 'Bebas pilih studio di semua cabang', 'Pemotongan kuota otomatis saat booking'],
    },
    'membership-bulanan-pro': {
      image: '/images/plans/monthly-pro.jpg',
      badge: 'PALING POPULER',
      tagline: 'Pilihan favorit member aktif FitSpace',
      featured: true,
      perks: ['20 kuota sesi aktif selama 30 hari', 'Akses penuh seluruh cabang Jakarta & Bandung', 'Prioritas booking kelas & pantau progres'],
    },
    'unlimited-all-access': {
      image: '/images/plans/unlimited-access.jpg',
      badge: 'ALL-ACCESS',
      tagline: 'Latihan tanpa batas hingga 2 kelas/hari',
      perks: ['Hingga 45 sesi selama 30 hari penuh', 'Akses semua kelas Pilates, Boxing, Yoga & Cardio', 'Gratis loker premium & handuk studio'],
    },
    'paket-quarterly-90': {
      image: '/images/plans/quarterly-90.jpg',
      badge: 'HEMAT 20%',
      tagline: 'Program transformasi kebugaran 3 bulan',
      perks: ['60 kuota sesi berlaku 90 hari', 'Gratis 2x evaluasi kebugaran bersama Coach', 'Akses lintas cabang & prioritas waitlist'],
    },
    'vip-annual-pass': {
      image: '/images/plans/vip-annual.jpg',
      badge: 'VIP ANNUAL · HEMAT 30%',
      tagline: 'Keanggotaan eksklusif 1 tahun penuh',
      perks: ['240 kuota sesi berlaku 365 hari', 'Akses VIP semua cabang & kelas eksklusif', 'Konsultasi program latihan personal berkala'],
    },
  };

  const REPORT_TYPES = [
    ['finance_daily', 'Keuangan Harian'],
    ['finance_weekly', 'Keuangan Mingguan'],
    ['finance_monthly', 'Keuangan Bulanan'],
    ['bookings', 'Booking Kelas'],
    ['coach_activity', 'Aktivitas Coach'],
    ['schedules', 'Jadwal Kelas'],
    ['attendance', 'Kehadiran Peserta'],
    ['memberships', 'Membership'],
    ['progress', 'Perkembangan Peserta'],
    ['reviews', 'Rating & Ulasan'],
    ['promotions', 'Promo & Voucher'],
  ];

  // ---------- Safe Utilities ----------
  const $ = (id) => document.getElementById(id);

  function esc(val) {
    return String(val ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function formatRp(amount) {
    const n = Number(amount || 0);
    return 'Rp ' + Math.round(n).toLocaleString('id-ID');
  }

  function formatDateTime(iso) {
    if (!iso) return '-';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return String(iso);
    return d.toLocaleString('id-ID', {
      weekday: 'short',
      day: '2-digit',
      month: 'short',
      hour: '2-digit',
      minute: '2-digit',
    }) + ' WIB';
  }

  let toastTimer = null;
  function showToast(msg, isError = false) {
    const el = $('alert-toast');
    if (!el) return;
    el.textContent = msg;
    el.classList.toggle('is-error', Boolean(isError));
    el.classList.remove('is-hidden');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.add('is-hidden'), 4200);
  }

  async function api(path, opts = {}) {
    const headers = Object.assign({ Accept: 'application/json' }, opts.headers || {});
    if (opts.body && !(opts.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
    }
    if (state.token) {
      headers.Authorization = `Bearer ${state.token}`;
    }
    const res = await fetch(`${API_BASE}${path}`, Object.assign({}, opts, { headers }));
    if (opts.rawResponse) return res;
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      const firstErr = data?.errors ? Object.values(data.errors).flat()[0] : null;
      throw new Error(firstErr || data?.message || `Permintaan gagal (${res.status})`);
    }
    return data;
  }

  async function downloadAuthenticatedFile(path, fallbackFilename) {
    try {
      const res = await api(path, { rawResponse: true });
      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message || `Gagal mengunduh berkas (${res.status})`);
      }
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = fallbackFilename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
      showToast(`Laporan ${fallbackFilename} berhasil diunduh.`);
    } catch (err) {
      showToast(err.message, true);
    }
  }

  function makeQrSvg(codeText) {
    const seed = Array.from(String(codeText || 'FITSPACE')).reduce((a, c) => (a * 31 + c.charCodeAt(0)) % 9973, 17);
    let cells = '';
    for (let r = 0; r < 5; r++) {
      for (let c = 0; c < 5; c++) {
        if ((seed + r * 7 + c * 13) % 2 === 0) {
          cells += `<rect x="${42 + c * 8}" y="${42 + r * 8}" width="6" height="6" fill="#0B1120"/>`;
        }
      }
    }
    return (
      `<svg viewBox="0 0 100 100" class="qr-code-visual" role="img" aria-label="QR Code ${esc(codeText)}">` +
      `<rect x="4" y="4" width="26" height="26" rx="4" fill="none" stroke="#0B1120" stroke-width="5"/>` +
      `<rect x="11" y="11" width="12" height="12" fill="#1D4ED8"/>` +
      `<rect x="70" y="4" width="26" height="26" rx="4" fill="none" stroke="#0B1120" stroke-width="5"/>` +
      `<rect x="77" y="11" width="12" height="12" fill="#1D4ED8"/>` +
      `<rect x="4" y="70" width="26" height="26" rx="4" fill="none" stroke="#0B1120" stroke-width="5"/>` +
      `<rect x="11" y="77" width="12" height="12" fill="#1D4ED8"/>` +
      cells +
      `<rect x="72" y="76" width="20" height="14" rx="2" fill="#15803D"/>` +
      `</svg>`
    );
  }

  const DONUT_COLORS = ['#1D4ED8', '#0EA5E9', '#15803D', '#D97706', '#6366F1', '#E11D48'];

  function makeDonutChartSvg(items = [], centerBig = '100%', centerSub = 'Total') {
    const r = 64;
    const c = 2 * Math.PI * r;
    const valid = (items || []).filter((it) => Number(it.count || 0) > 0);
    const total = valid.reduce((acc, it) => acc + Number(it.count || 0), 0);
    let offset = 0;
    const arcs = total > 0
      ? valid.map((it, idx) => {
          const frac = Number(it.count || 0) / total;
          const seg = Math.max(4, frac * c - 2.5);
          const color = DONUT_COLORS[idx % DONUT_COLORS.length];
          const dashOffset = -offset;
          offset += frac * c;
          return `<circle cx="90" cy="90" r="${r}" fill="none" stroke="${color}" stroke-width="22" stroke-dasharray="${seg.toFixed(2)} ${(c - seg).toFixed(2)}" stroke-dashoffset="${dashOffset.toFixed(2)}" transform="rotate(-90 90 90)"/>`;
        }).join('')
      : `<circle cx="90" cy="90" r="${r}" fill="none" stroke="#CBD5E1" stroke-width="22"/>`;

    const legend = valid.length
      ? valid.map((it, idx) => `
          <div class="donut-legend-row">
            <span class="donut-legend-left"><span class="donut-swatch donut-swatch-${idx % 6}"></span>${esc(it.name)}</span>
            <strong>${esc(it.count)} (${esc(it.pct)}%)</strong>
          </div>`).join('')
      : '<p>Belum ada distribusi booking kelas.</p>';

    return `
      <div class="donut-layout">
        <svg viewBox="0 0 180 180" class="donut-svg" role="img" aria-label="Grafik lingkaran distribusi kelas ${esc(centerBig)}">
          <circle cx="90" cy="90" r="${r}" fill="none" stroke="#E2E8F0" stroke-width="22"/>
          ${arcs}
          <circle cx="90" cy="90" r="46" fill="#FFFFFF"/>
          <text x="90" y="86" text-anchor="middle" font-size="21" font-weight="800" fill="#0B1120">${esc(centerBig)}</text>
          <text x="90" y="106" text-anchor="middle" font-size="11" font-weight="700" fill="#475569">${esc(centerSub)}</text>
        </svg>
        <div class="donut-legend">${legend}</div>
      </div>`;
  }

  function makeRingChartSvg(percent = 0, subLabel = 'Progres', onDark = false) {
    const pct = Math.max(0, Math.min(100, Math.round(Number(percent || 0))));
    const r = 42;
    const c = 2 * Math.PI * r;
    const filled = (pct / 100) * c;
    const track = onDark ? '#1E293B' : '#E2E8F0';
    const stroke = pct >= 70 ? (onDark ? '#38BDF8' : '#15803D') : pct >= 35 ? '#1D4ED8' : '#D97706';
    const textMain = onDark ? '#FFFFFF' : '#0B1120';
    const textSub = onDark ? '#94A3B8' : '#475569';
    return `
      <svg viewBox="0 0 112 112" class="ring-svg" role="img" aria-label="${esc(subLabel)} ${pct}%">
        <circle cx="56" cy="56" r="${r}" fill="none" stroke="${track}" stroke-width="11"/>
        <circle cx="56" cy="56" r="${r}" fill="none" stroke="${stroke}" stroke-width="11" stroke-linecap="round" stroke-dasharray="${filled.toFixed(2)} ${(c - filled).toFixed(2)}" transform="rotate(-90 56 56)"/>
        <text x="56" y="54" text-anchor="middle" font-size="20" font-weight="800" fill="${textMain}">${pct}%</text>
        <text x="56" y="70" text-anchor="middle" font-size="10" font-weight="700" fill="${textSub}">${esc(subLabel)}</text>
      </svg>`;
  }

  function renderScheduleChangeNotifications(notifications = []) {
    const relevant = (notifications || []).filter((n) => n.type === 'coach_schedule_rescheduled').slice(0, 3);
    if (!relevant.length) return '';
    return `
      <div class="schedule-notif-banner" role="status" aria-live="polite">
        <div class="section-head">
          <strong>Pemberitahuan Perubahan Jadwal (Disetujui Admin)</strong>
          <span class="pill pill-info">${relevant.length} INFO TERBARU</span>
        </div>
        ${relevant.map((n) => `
          <div class="schedule-notif-item">
            <div>
              <strong>${esc(n.title)}</strong>
              <div>${esc(n.body)}</div>
            </div>
            <small>${esc(formatDateTime(n.created_at))}</small>
          </div>`).join('')}
      </div>`;
  }

  // ---------- Modal Helpers ----------
  function openModal(title, htmlContent) {
    $('modal-title').textContent = title;
    $('modal-body').innerHTML = htmlContent;
    $('app-modal').classList.remove('is-hidden');
  }

  function closeModal() {
    $('app-modal').classList.add('is-hidden');
    $('modal-body').innerHTML = '';
  }

  // ---------- Public Data Bootstrap & Rendering ----------
  async function loadPublicBootstrap() {
    const q = state.selectedBranchId ? `?branch_id=${encodeURIComponent(state.selectedBranchId)}` : '';
    const data = await api(`/public/bootstrap${q}`);
    state.demoMode = Boolean(data.demo_mode);
    state.googleOauthEnabled = Boolean(data.google_oauth_enabled);
    state.branches = data.branches || [];
    state.categories = data.categories || [];
    state.levels = data.levels || [];
    state.classes = data.classes || [];
    state.schedules = data.schedules || [];
    state.plans = data.membership_plans || [];

    renderBranchSelector();
    renderCategoryFilters();
    renderHeroSchedules();
    renderPublicClasses();
    renderMembershipPlans();
    renderHudCard();
  }

  function renderBranchSelector() {
    const sel = $('branch-filter');
    if (!sel) return;
    const current = state.selectedBranchId;
    sel.innerHTML = `<option value="">Semua Cabang (${state.branches.length})</option>` +
      state.branches.map((b) => `<option value="${esc(b.id)}"${String(b.id) === String(current) ? ' selected' : ''}>${esc(b.name)}</option>`).join('');
  }

  function renderCategoryFilters() {
    const bar = $('category-filter-bar');
    if (!bar) return;
    const pills = [
      `<button type="button" class="filter-btn${state.selectedCategoryId === '' ? ' is-active' : ''}" data-action="filter-cat" data-id="">Semua (${state.classes.length})</button>`,
      ...state.categories.map(
        (c) => `<button type="button" class="filter-btn${String(state.selectedCategoryId) === String(c.id) ? ' is-active' : ''}" data-action="filter-cat" data-id="${esc(c.id)}">${esc(c.name)}</button>`
      ),
    ];
    bar.innerHTML = pills.join('');
    const statCount = $('stat-classes-count');
    if (statCount) statCount.textContent = `${state.categories.length} Kategori · ${state.classes.length} Kelas`;
  }

  function renderHeroSchedules() {
    const box = $('hero-live-schedules');
    if (!box) return;
    const top = state.schedules.slice(0, 4);
    if (!top.length) {
      box.innerHTML = `<div class="live-row"><div><strong>Belum ada jadwal aktif</strong><span>Silakan pilih cabang lain.</span></div></div>`;
      return;
    }
    box.innerHTML = top
      .map((s) => {
        const rem = Math.max(0, Number(s.capacity) - Number(s.booked_count));
        const pillCls = rem === 0 ? 'pill-danger' : rem <= 3 ? 'pill-warning' : 'pill-info';
        const pillTxt = rem === 0 ? 'Penuh' : `Sisa ${rem} Slot`;
        return `
          <div class="live-row">
            <div>
              <strong>${esc(formatDateTime(s.starts_at))} · ${esc(s.class_name)}</strong>
              <span>${esc(s.coach_name)} · ${esc(s.room_name)} (${esc(s.branch_name)})</span>
            </div>
            <button type="button" class="pill ${pillCls}" data-action="open-book-schedule" data-id="${esc(s.id)}">${esc(pillTxt)}</button>
          </div>`;
      })
      .join('');
  }

  function renderPublicClasses() {
    const grid = $('public-classes-grid');
    if (!grid) return;
    const filtered = state.selectedCategoryId
      ? state.classes.filter((c) => String(c.category_id) === String(state.selectedCategoryId))
      : state.classes;

    if (!filtered.length) {
      grid.innerHTML = `<article class="card"><h3>Belum ada kelas di kategori ini</h3><p>Admin dapat menambahkan kelas baru kapan saja melalui Dynamic Class Management.</p></article>`;
      return;
    }

    grid.innerHTML = filtered
      .map((c) => {
        const photo =
          c.photo_url ||
          CLASS_PHOTO_MAP[c.slug] ||
          CLASS_PHOTO_MAP[c.category_slug] ||
          CLASS_PHOTO_MAP[c.color_token] ||
          '/images/classes/reformer-pilates.jpg';
        return `
          <article class="card class-card">
            <div class="class-media">
              <img src="${esc(photo)}" alt="${esc(c.name)}" class="class-photo" loading="lazy" />
              <div class="class-media-overlay">
                <span class="media-badge">${esc(c.category_name)}</span>
                <span class="media-meta">${esc(c.level_name)} · ${esc(c.duration_minutes)} mnt</span>
              </div>
            </div>
            <div class="class-card-body">
              <h3>${esc(c.name)}</h3>
              <p class="class-desc">${esc(c.description || '')}</p>
              <div class="class-specs">
                <span class="spec-chip">Usia ${esc(c.min_age)}–${esc(c.max_age)} Thn</span>
                <span class="spec-chip">Maks ${esc(c.max_capacity)} Peserta</span>
              </div>
              <div class="card-foot">
                <span class="price-tag">${esc(formatRp(c.price_per_session))}<small> / sesi</small></span>
                <div class="card-actions">
                  <button type="button" class="btn btn-outline btn-sm" data-action="view-class-detail" data-id="${esc(c.id)}">Detail</button>
                  <button type="button" class="btn btn-primary btn-sm" data-action="quick-book-class" data-id="${esc(c.id)}">Booking</button>
                </div>
              </div>
            </div>
          </article>`;
      })
      .join('');
  }

  function renderMembershipPlans() {
    const grid = $('public-membership-grid');
    if (!grid) return;
    grid.innerHTML = state.plans
      .map((p) => {
        const meta = PLAN_META[p.slug] || {
          image: '/images/plans/monthly-pro.jpg',
          badge: String(p.plan_type || 'MEMBERSHIP').toUpperCase(),
          tagline: 'Akses fleksibel ke seluruh kelas FitSpace',
          perks: [`${p.session_quota} kuota sesi kelas`, `Masa berlaku ${p.duration_days} hari`, 'Pemotongan kuota otomatis'],
        };
        const featured = Boolean(meta.featured);
        const perSession = p.session_quota > 0 ? Math.round(Number(p.price) / Number(p.session_quota)) : Number(p.price);
        return `
          <article class="card plan-card${featured ? ' plan-card-featured' : ''}">
            <div class="plan-media">
              <img src="${esc(meta.image)}" alt="${esc(p.name)}" class="plan-photo" loading="lazy" />
              <div class="plan-media-scrim">
                <span class="plan-badge${featured ? ' plan-badge-featured' : ''}">${esc(meta.badge)}</span>
                <span class="plan-duration">${esc(p.duration_days)} Hari</span>
              </div>
            </div>
            <div class="plan-card-body">
              <div class="plan-head">
                <h3>${esc(p.name)}</h3>
                <p class="plan-tagline">${esc(meta.tagline)}</p>
              </div>
              <div class="plan-pricing">
                <span class="plan-price">${esc(formatRp(p.price))}</span>
                <span class="plan-per-session">&approx; ${esc(formatRp(perSession))} / sesi &bull; ${esc(p.session_quota)} Sesi</span>
              </div>
              <ul class="plan-perks">
                ${meta.perks.map((perk) => `<li>${esc(perk)}</li>`).join('')}
              </ul>
              <div class="card-foot">
                <button type="button" class="btn ${featured ? 'btn-primary' : 'btn-dark'} btn-block" data-action="buy-membership" data-id="${esc(p.id)}">Pilih Paket Ini</button>
              </div>
            </div>
          </article>`;
      })
      .join('');
  }

  // ---------- Auth Flow (Google Direct, Guest Account, Email Login & Register — Demo Roles Hidden) ----------
  const GOOGLE_ICON_SVG =
    '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">' +
    '<path fill="#4285F4" d="M23.49 12.27c0-.79-.07-1.54-.2-2.27H12v4.51h6.47c-.29 1.48-1.14 2.73-2.4 3.58v3h3.86c2.26-2.09 3.56-5.17 3.56-8.82z"/>' +
    '<path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.86-3c-1.08.72-2.45 1.16-4.07 1.16-3.13 0-5.78-2.11-6.73-4.96H1.29v3.09C3.26 21.3 7.31 24 12 24z"/>' +
    '<path fill="#FBBC05" d="M5.27 14.29c-.25-.72-.38-1.49-.38-2.29s.14-1.57.38-2.29V6.62H1.29C.47 8.24 0 10.06 0 12s.47 3.76 1.29 5.38l3.98-3.09z"/>' +
    '<path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.31 0 3.26 2.7 1.29 6.62l3.98 3.09c.95-2.85 3.6-4.96 6.73-4.96z"/>' +
    '</svg>';

  const EYE_OPEN_SVG =
    '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/>' +
    '<circle cx="12" cy="12" r="3"/>' +
    '</svg>';

  const EYE_OFF_SVG =
    '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/>' +
    '<path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c6.4 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/>' +
    '<path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3.6 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/>' +
    '<line x1="2" y1="2" x2="22" y2="22"/>' +
    '</svg>';

  function passwordFieldHtml(id, name, label, placeholder, autocomplete, extraAttrs = '') {
    return `
      <div class="field">
        <label for="${esc(id)}">${esc(label)}</label>
        <div class="password-field-wrap">
          <input id="${esc(id)}" name="${esc(name)}" type="password" required autocomplete="${esc(autocomplete)}" placeholder="${esc(placeholder)}" ${extraAttrs} />
          <button
            type="button"
            class="password-toggle-btn"
            data-action="toggle-password"
            data-target="${esc(id)}"
            aria-label="Tampilkan kata sandi"
            aria-pressed="false"
            title="Tampilkan / sembunyikan kata sandi"
          >${EYE_OPEN_SVG}</button>
        </div>
      </div>`;
  }

  function showLoginModal(mode = 'user') {
    const isAdmin = mode === 'admin';
    openModal(
      isAdmin ? 'Portal Login Admin Khusus FitSpace' : 'Masuk ke FitSpace',
      `
      <div class="auth-stack">
        <div class="auth-mode-tabs" role="tablist" aria-label="Pilih jalur login">
          <button type="button" class="auth-tab-btn${!isAdmin ? ' is-active' : ''}" data-action="switch-login-mode" data-mode="user">Member · Orang Tua · Coach</button>
          <button type="button" class="auth-tab-btn${isAdmin ? ' is-active' : ''}" data-action="switch-login-mode" data-mode="admin">Portal Admin Khusus</button>
        </div>

        ${isAdmin ? `
          <div class="admin-portal-notice">
            <strong>Akses Eksklusif Administrator FitSpace</strong>
            Hanya akun Admin resmi yang dapat masuk di sini untuk menyetujui akun Coach, menyinkronkan jadwal pelatih &amp; member, serta menangani masalah operasional.
          </div>
          <form id="admin-login-form" class="form-grid">
            <div class="field">
              <label for="admin-login-email">Email Administrator</label>
              <input id="admin-login-email" name="email" type="email" required autocomplete="email" placeholder="admin@domain-anda.com" />
            </div>
            ${passwordFieldHtml('admin-login-password', 'password', 'Kata Sandi Admin', 'Masukkan kata sandi Admin', 'current-password')}
            <div class="field">
              <button type="submit" class="btn btn-dark btn-block">Masuk ke Portal Admin</button>
            </div>
          </form>
        ` : `
          <button type="button" class="btn btn-google btn-block" data-action="google-login">
            ${GOOGLE_ICON_SVG}
            <span>Lanjutkan dengan Google</span>
          </button>

          <div class="auth-divider"><span>atau masuk dengan email (Member 18+ · Orang Tua · Coach)</span></div>

          <form id="login-form" class="form-grid">
            <div class="field">
              <label for="login-email">Email</label>
              <input id="login-email" name="email" type="email" required autocomplete="email" placeholder="nama@email.com" />
            </div>
            ${passwordFieldHtml('login-password', 'password', 'Kata Sandi', 'Masukkan kata sandi', 'current-password')}
            <div class="field">
              <button type="submit" class="btn btn-primary btn-block">Masuk</button>
            </div>
          </form>

          <div class="auth-divider"><span>hanya ingin melihat-lihat dulu?</span></div>

          <button type="button" class="btn btn-outline btn-block" data-action="guest-login">
            Masuk sebagai Akun Tamu (Akses Terbatas)
          </button>

          <p class="auth-switch-row">
            Belum punya akun FitSpace?
            <button type="button" class="link-btn" data-action="open-register">Daftar Akun Baru</button>
          </p>
        `}
      </div>`
    );
  }

  function showRegisterModal(defaultRole = 'member') {
    const role = ['member', 'guardian', 'coach'].includes(defaultRole) ? defaultRole : 'member';
    const max18Date = new Date();
    max18Date.setFullYear(max18Date.getFullYear() - 18);
    const max18Str = max18Date.toISOString().slice(0, 10);

    openModal(
      'Daftar Akun Baru FitSpace',
      `
      <div class="auth-stack">
        <button type="button" class="btn btn-google btn-block" data-action="google-login">
          ${GOOGLE_ICON_SVG}
          <span>Daftar Cepat dengan Google</span>
        </button>

        <div class="auth-divider"><span>atau daftar sesuai peran Anda</span></div>

        <form id="register-form" class="form-grid">
          <div class="field">
            <label for="reg-role">Jenis Akun</label>
            <select id="reg-role" name="role">
              <option value="member"${role === 'member' ? ' selected' : ''}>Member — Khusus Usia 18 Tahun ke Atas (Disetujui Admin)</option>
              <option value="guardian"${role === 'guardian' ? ' selected' : ''}>Orang Tua / Wali — Mewakili Anak &lt; 18 Thn (Disetujui Admin)</option>
              <option value="coach"${role === 'coach' ? ' selected' : ''}>Coach / Pelatih — Wajib Persetujuan Admin</option>
            </select>
          </div>
          <div class="field">
            <label for="reg-name">Nama Lengkap</label>
            <input id="reg-name" name="name" type="text" required placeholder="Nama lengkap sesuai identitas" />
          </div>
          <div class="field">
            <label for="reg-email">Email</label>
            <input id="reg-email" name="email" type="email" required placeholder="nama@email.com" />
          </div>
          <div class="field">
            <label for="reg-phone">Nomor Telepon / WhatsApp</label>
            <input id="reg-phone" name="phone" type="tel" required placeholder="0812xxxxxxxx" />
          </div>
          <div class="field" id="reg-dob-wrap">
            <label for="reg-birth-date" id="reg-dob-label">Tanggal Lahir (Wajib &ge; 18 Tahun untuk Member)</label>
            <input id="reg-birth-date" name="birth_date" type="date" max="${esc(max18Str)}" value="1998-05-10" />
          </div>

          <div id="reg-guardian-fields" class="form-grid${role === 'guardian' ? '' : ' is-hidden'}">
            <div class="field">
              <label for="reg-child-name">Nama Anak yang Diwakili (Opsional saat daftar)</label>
              <input id="reg-child-name" name="child_name" type="text" placeholder="Contoh: Alya Putri" />
            </div>
            <div class="field">
              <label for="reg-child-dob">Tanggal Lahir Anak (Wajib &lt; 18 Tahun)</label>
              <input id="reg-child-dob" name="child_birth_date" type="date" />
            </div>
          </div>

          <div id="reg-coach-fields" class="form-grid${role === 'coach' ? '' : ' is-hidden'}">
            <div class="field">
              <label for="reg-coach-spec">Spesialisasi Utama</label>
              <select id="reg-coach-spec" name="specialization_slug">
                <option value="pilates">Pilates</option>
                <option value="zumba">Zumba</option>
                <option value="boxing">Boxing</option>
                <option value="swimming">Swimming</option>
                <option value="martial-arts">Martial Arts</option>
                <option value="yoga">Yoga</option>
              </select>
            </div>
            <div class="field">
              <label for="reg-coach-cert">Sertifikasi Pelatih</label>
              <input id="reg-coach-cert" name="certifications" type="text" placeholder="Contoh: STOTT Pilates / RYT-200 / FINA" />
            </div>
            <div class="field">
              <label for="reg-coach-exp">Pengalaman Mengajar (Tahun)</label>
              <input id="reg-coach-exp" name="experience_years" type="number" min="0" max="40" value="3" />
            </div>
          </div>

          ${passwordFieldHtml('reg-password', 'password', 'Kata Sandi (min. 8 karakter)', 'Minimal 8 karakter', 'new-password', 'minlength="8"')}
          ${passwordFieldHtml('reg-password-confirm', 'password_confirmation', 'Konfirmasi Kata Sandi', 'Ulangi kata sandi', 'new-password', 'minlength="8"')}
          <div class="field">
            <button type="submit" class="btn btn-primary btn-block" id="reg-submit-btn">Daftar &amp; Ajukan Verifikasi</button>
          </div>
        </form>

        <p class="auth-switch-row">
          Sudah punya akun?
          <button type="button" class="link-btn" data-action="open-login">Masuk di sini</button>
          &bull;
          <button type="button" class="link-btn" data-action="guest-login">Jelajah Akun Tamu</button>
        </p>
      </div>`
    );
    syncRegisterRoleFields();
  }

  function syncRegisterRoleFields() {
    const sel = $('reg-role');
    if (!sel) return;
    const role = sel.value;
    $('reg-guardian-fields')?.classList.toggle('is-hidden', role !== 'guardian');
    $('reg-coach-fields')?.classList.toggle('is-hidden', role !== 'coach');
    const dobInput = $('reg-birth-date');
    const dobLabel = $('reg-dob-label');
    const btn = $('reg-submit-btn');
    if (dobInput) dobInput.required = role === 'member';
    if (dobLabel) {
      dobLabel.textContent = role === 'member'
        ? 'Tanggal Lahir (Wajib — Khusus Member Usia 18+)'
        : role === 'guardian'
        ? 'Tanggal Lahir Orang Tua / Wali (18+)'
        : 'Tanggal Lahir Calon Coach';
    }
    if (btn) {
      btn.textContent = role === 'coach'
        ? 'Kirim Pengajuan Akun Coach ke Admin'
        : role === 'guardian'
        ? 'Daftar Orang Tua / Wali (Verifikasi Admin)'
        : 'Daftar Member 18+ (Verifikasi Admin)';
    }
  }

  function showGuestUpgradeModal(featureLabel = 'fitur lengkap FitSpace') {
    openModal(
      'Login Diperlukan — Akses Terbatas Akun Tamu',
      `
      <div class="auth-stack">
        <div class="approval-banner">
          <div>
            <strong>Anda sedang menggunakan Akun Tamu (Guest)</strong>
            <div>Untuk mengakses <strong>${esc(featureLabel)}</strong>, silakan login atau daftar menggunakan <strong>Akun Member (usia 18+)</strong> atau <strong>Akun Orang Tua / Wali</strong>.</div>
          </div>
          <span class="pill pill-warning">AKSES TERBATAS</span>
        </div>
        <div class="guest-scope-grid">
          <div class="card">
            <span class="pill pill-success">TERBUKA UNTUK TAMU</span>
            <ul class="scope-list">
              <li>Jelajahi katalog 6+ kelas</li>
              <li>Lihat jadwal live studio</li>
              <li>Bandingkan 6 paket membership</li>
            </ul>
          </div>
          <div class="card">
            <span class="pill pill-danger">WAJIB MEMBER / ORANG TUA</span>
            <ul class="scope-list">
              <li>Booking &amp; penyesuaian jadwal</li>
              <li>Pemilihan &amp; aktivasi membership</li>
              <li>Tiket QR, absensi &amp; unduh laporan</li>
            </ul>
          </div>
        </div>
        <div class="format-group">
          <button type="button" class="btn btn-primary" data-action="open-register-role" data-role="member">Daftar Member (18+)</button>
          <button type="button" class="btn btn-dark" data-action="open-register-role" data-role="guardian">Daftar Orang Tua / Wali</button>
          <button type="button" class="btn btn-outline" data-action="open-login">Login Akun Terdaftar</button>
        </div>
      </div>`
    );
  }

  async function restoreSession() {
    if (!state.token) {
      updateAuthNav();
      return;
    }
    try {
      const data = await api('/auth/me');
      state.user = data.user;
      updateAuthNav();
      syncPendingApprovalWatcher();
    } catch {
      state.token = '';
      sessionStorage.removeItem(STORAGE_TOKEN_KEY);
      state.user = null;
      updateAuthNav();
      syncPendingApprovalWatcher();
    }
  }

  function updateAuthNav() {
    const loggedIn = Boolean(state.user);
    $('nav-auth-actions').classList.toggle('is-hidden', loggedIn);
    $('nav-user-actions').classList.toggle('is-hidden', !loggedIn);
    $('nav-workspace-link').classList.toggle('is-hidden', !loggedIn);
    if (loggedIn) {
      const isGuest = Boolean(state.user.is_guest || state.user.role === 'guest');
      const roleLabels = {
        admin: 'ADMIN',
        coach: 'COACH',
        member: 'MEMBER 18+',
        guardian: 'ORANG TUA / WALI',
        guest: 'AKUN TAMU',
      };
      const roleLabel = isGuest ? 'TAMU (TERBATAS)' : (roleLabels[state.user.role] || state.user.role.toUpperCase());
      $('nav-user-badge').textContent = `${state.user.name} (${roleLabel})`;
      $('sidebar-user-name').textContent = state.user.name;
      $('sidebar-role-badge').textContent = isGuest ? 'AKUN TAMU (PRATINJAU)' : `PERAN: ${roleLabel}`;
      const roleTitles = {
        admin: ['PORTAL ADMIN KHUSUS FITSPACE', `Selamat Datang, ${state.user.name}`, 'Setujui akun, pemilihan kelas & membership Member/Orang Tua, ACC reschedule jadwal Coach untuk diteruskan ke peserta, dan kelola laporan PDF/Excel/Word.'],
        coach: ['WORKSPACE COACH / PELATIH', `Halo, ${state.user.name}`, 'Terima jadwal mengajar resmi dari Admin, validasi QR absensi, munculkan nilai evaluasi (1–4) & laporan, atau ajukan reschedule jadwal ke Admin jika berhalangan.'],
        guardian: ['WORKSPACE ORANG TUA / WALI (<18 THN)', `Halo, ${state.user.name}`, 'Kelola profil & pemilihan kelas/membership anak (ACC Admin), terima notifikasi perubahan jadwal resmi, pantau absensi QR, serta unduh rapor PDF/Word.'],
        member: ['MEMBER WORKSPACE (USIA 18+)', `Halo, ${state.user.name}`, 'Pilih membership & kelas (disetujui Admin), terima update perubahan jadwal resmi, tampilkan tiket QR absensi, pantau nilai Coach, dan unduh laporan.'],
        guest: ['MODE AKUN TAMU (AKSES TERBATAS)', `Halo, ${state.user.name}`, 'Anda dapat melihat katalog kelas, jadwal live, dan 6 paket membership. Login sebagai Member (18+) atau Orang Tua / Wali untuk akses penuh.'],
      };
      const [rBadge, rTitle, rSub] = roleTitles[isGuest ? 'guest' : state.user.role] || roleTitles.member;
      if ($('workspace-banner-role')) $('workspace-banner-role').textContent = rBadge;
      if ($('workspace-banner-title')) $('workspace-banner-title').textContent = rTitle;
      if ($('workspace-banner-sub')) $('workspace-banner-sub').textContent = rSub;
    }
  }

  function updateTopNavHighlight(activeSelector = null) {
    document.querySelectorAll('#primary-navigation a').forEach((a) => {
      const isWsLink = a.id === 'nav-workspace-link';
      if (state.activeView === 'workspace') {
        a.classList.toggle('is-active', isWsLink);
      } else {
        a.classList.toggle('is-active', Boolean(activeSelector && a.getAttribute('href') === activeSelector));
      }
    });
  }

  function setView(view, scrollTarget = null) {
    state.activeView = view;
    const isWs = view === 'workspace' && state.user;
    $('public-portal-view').classList.toggle('is-hidden', isWs);
    $('workspace-section').classList.toggle('is-hidden', !isWs);
    if ($('switch-view-btn')) {
      $('switch-view-btn').textContent = isWs ? 'Beranda Portal' : 'Dashboard Saya';
    }
    updateTopNavHighlight(scrollTarget);
    $('primary-navigation')?.classList.remove('is-open');
    $('nav-toggle-btn')?.setAttribute('aria-expanded', 'false');

    if (isWs) {
      renderWorkspaceSidebar();
      loadWorkspaceTab(state.activeTab);
      window.scrollTo({ top: 0, behavior: 'auto' });
    } else if (scrollTarget) {
      const targetEl = document.querySelector(scrollTarget);
      if (targetEl) {
        requestAnimationFrame(() => targetEl.scrollIntoView({ behavior: 'smooth', block: 'start' }));
      }
    } else {
      window.scrollTo({ top: 0, behavior: 'auto' });
    }
  }

  // ---------- Booking & Class Detail Modals ----------
  function showClassDetailModal(classId) {
    const cls = state.classes.find((c) => String(c.id) === String(classId));
    if (!cls) return;
    const scheds = state.schedules.filter((s) => String(s.class_id) === String(classId));
    const mats = (cls.materials || [])
      .map((m) => `<li><strong>${esc(m.title)}</strong> (${esc(m.duration_minutes)} mnt) — ${esc(m.description || '')}</li>`)
      .join('');
    const inds = (cls.indicators || []).map((i) => `<span class="pill pill-info">${esc(i.name)}</span>`).join(' ');
    const schedRows = scheds.length
      ? scheds
          .map((s) => {
            const rem = Math.max(0, Number(s.capacity) - Number(s.booked_count));
            return `
              <div class="card">
                <strong>${esc(formatDateTime(s.starts_at))}</strong>
                <p>${esc(s.coach_name)} · ${esc(s.room_name)} (${esc(s.branch_name)}) · Sisa ${rem} slot</p>
                <button type="button" class="btn btn-primary btn-sm" data-action="open-book-schedule" data-id="${esc(s.id)}">Booking Jadwal Ini</button>
              </div>`;
          })
          .join('')
      : '<p>Belum ada jadwal mendatang untuk kelas ini.</p>';

    openModal(
      cls.name,
      `
      <p><span class="pill pill-info">${esc(cls.category_name)}</span> <span class="pill pill-success">${esc(cls.level_name)}</span></p>
      <p>${esc(cls.description || '')}</p>
      <p><strong>Usia:</strong> ${esc(cls.min_age)}–${esc(cls.max_age)} tahun &bull; <strong>Durasi:</strong> ${esc(cls.duration_minutes)} mnt &bull; <strong>Harga:</strong> ${esc(formatRp(cls.price_per_session))}</p>
      <h4>Indikator Evaluasi Dinamis (Skala 1–4)</h4>
      <div class="adaptive-controls">${inds || '<span class="pill pill-info">Standar</span>'}</div>
      <h4>Materi Pembelajaran</h4>
      <ul>${mats || '<li>Disiapkan langsung oleh Coach pengajar.</li>'}</ul>
      <h4>Jadwal Tersedia</h4>
      <div class="workspace-main">${schedRows}</div>`
    );
  }

  async function showBookScheduleModal(scheduleId) {
    if (!state.user) {
      showToast('Silakan masuk atau daftar terlebih dahulu untuk melakukan booking.');
      showLoginModal();
      return;
    }
    if (state.user.is_guest || state.user.role === 'guest') {
      showGuestUpgradeModal('Booking & Penyesuaian Jadwal Kelas');
      return;
    }
    // Live approval check BEFORE blocking — so accounts JUST approved by Admin work on the very first click!
    if (state.user.role !== 'admin' && state.user.approval_status !== 'approved') {
      await syncAuthStatus(true);
    }
    if (state.user.role !== 'admin' && state.user.approval_status !== 'approved') {
      showToast('Akun Anda masih menunggu persetujuan Admin FitSpace sebelum dapat melakukan booking.', true);
      return;
    }
    const sched = state.schedules.find((s) => String(s.id) === String(scheduleId));
    if (!sched) {
      showToast('Jadwal kelas tidak ditemukan atau sudah penuh. Memuat ulang jadwal...', true);
      loadPublicBootstrap().catch(() => {});
      return;
    }

    let childrenOptions = '';
    if (state.user.role === 'guardian') {
      let kids = state.apiCache.guardian_children?.data?.children || state.apiCache.guardian_dash?.data?.children || null;
      if (!kids) {
        try {
          const gData = await apiCached('guardian_children', '/guardian/children');
          kids = gData.children || [];
        } catch {
          kids = [];
        }
      }
      if (kids.length > 0) {
        childrenOptions = `
          <div class="field">
            <label for="book-child-id">Pilih Profil Anak (Wajib untuk Orang Tua/Wali)</label>
            <select id="book-child-id" name="child_id" required>
              ${kids.map((k) => `<option value="${esc(k.id)}">${esc(k.full_name)} (${esc(k.age)} Thn)</option>`).join('')}
            </select>
          </div>`;
      } else {
        childrenOptions = `
          <div class="approval-banner">
            <div>
              <strong>Daftarkan Profil Anak Sekaligus Booking</strong>
              <div>Anda belum memiliki profil anak. Isi nama &amp; tanggal lahir anak (&lt; 18 thn) di bawah untuk langsung mendaftar kelas.</div>
            </div>
          </div>
          <div class="field">
            <label for="quick-child-name">Nama Lengkap Anak</label>
            <input id="quick-child-name" name="quick_child_name" type="text" required placeholder="Contoh: Alya Putri" />
          </div>
          <div class="field">
            <label for="quick-child-dob">Tanggal Lahir Anak (Sesuai Rentang Usia Kelas)</label>
            <input id="quick-child-dob" name="quick_child_birth_date" type="date" required value="2018-05-12" />
          </div>`;
      }
    }

    openModal(
      `Booking: ${sched.class_name}`,
      `
      <p><strong>Jadwal:</strong> ${esc(formatDateTime(sched.starts_at))}<br/>
         <strong>Coach:</strong> ${esc(sched.coach_name)} · <strong>Ruangan:</strong> ${esc(sched.room_name)} (${esc(sched.branch_name)})<br/>
         <strong>Harga Per Sesi:</strong> ${esc(formatRp(sched.price_per_session))}</p>
      <form id="booking-form" class="form-grid" data-schedule-id="${esc(sched.id)}">
        ${childrenOptions}
        <div class="field">
          <label for="book-type">Metode Booking</label>
          <select id="book-type" name="booking_type">
            <option value="per_session">Booking Per Sesi (Payment Gateway)</option>
            <option value="membership">Gunakan Kuota Membership Aktif</option>
          </select>
        </div>
        <div class="field">
          <label for="book-gateway">Payment Gateway (jika per sesi)</label>
          <select id="book-gateway" name="gateway">
            <option value="midtrans">Midtrans (QRIS / VA / e-Wallet)</option>
            <option value="xendit">Xendit (Virtual Account / Invoice)</option>
          </select>
        </div>
        <div class="field">
          <label for="book-coupon">Kode Voucher / Promo (Opsional)</label>
          <input id="book-coupon" name="coupon_code" type="text" placeholder="Contoh: FITSPACE15" />
        </div>
        <div class="field">
          <button type="submit" class="btn btn-primary btn-block">Konfirmasi &amp; Daftarkan Kelas</button>
        </div>
      </form>`
    );
  }

  // ---------- 4-Role + Guest Dashboards ----------
  function getSidebarTabs(role, isGuest = false) {
    if (isGuest || role === 'guest') {
      return [
        ['overview', 'Pratinjau Akun Tamu'],
        ['upgrade', 'Upgrade ke Member / Orang Tua'],
      ];
    }
    if (role === 'admin') {
      return [
        ['overview', 'Dashboard & Keuangan'],
        ['approvals', 'Persetujuan Akun, Kelas & Membership'],
        ['schedules', 'Jadwal & Reschedule Coach'],
        ['issues', 'Pusat Penanganan Masalah'],
        ['classes', 'Kelas Dinamis & Kategori'],
        ['bookings', 'Booking & Pembayaran'],
        ['finance', 'Pengeluaran & Rekap'],
        ['reports', 'Reporting (PDF, Excel & Word)'],
        ['audit', 'Audit Log & Pengaturan'],
      ];
    }
    if (role === 'coach') {
      return [
        ['overview', 'Jadwal & Scan QR'],
        ['reschedule', 'Ajukan Reschedule Jadwal'],
        ['evaluations', 'Nilai (1–4) & Publikasi Laporan'],
        ['materials', 'Materi Pembelajaran'],
        ['issues', 'Lapor Masalah ke Admin'],
      ];
    }
    if (role === 'guardian') {
      return [
        ['overview', 'Profil Anak (<18) & Jadwal'],
        ['progress', 'Nilai & Rapor Anak (PDF/Word)'],
        ['bookings', 'Booking & Penyesuaian Jadwal'],
        ['memberships', 'Membership & Pembayaran'],
        ['issues', 'Bantuan & Lapor Masalah'],
      ];
    }
    return [
      ['overview', 'Dashboard & Tiket QR'],
      ['bookings', 'Booking & Reschedule H-8'],
      ['memberships', 'Membership & Pembayaran'],
      ['progress', 'Nilai & Laporan (PDF/Excel/Word)'],
      ['issues', 'Bantuan & Lapor Masalah'],
    ];
  }

  function renderApprovalBanner() {
    if (!state.user || state.user.role === 'admin' || state.user.is_guest || state.user.approval_status === 'approved') {
      return '';
    }
    const rejected = state.user.approval_status === 'rejected';
    const approver = 'Admin FitSpace';
    return `
      <div class="approval-banner${rejected ? ' is-rejected' : ''}">
        <div>
          <strong>${rejected ? 'Pengajuan Akun Ditolak' : `Menunggu Persetujuan ${approver}`}</strong>
          <div>${
            rejected
              ? esc(state.user.approval_notes || 'Hubungi Admin melalui menu Bantuan & Lapor Masalah untuk verifikasi ulang.')
              : `Akun Anda telah terdaftar. Setelah disetujui oleh ${approver}, fitur pemilihan membership, booking kelas, absensi QR, dan unduh laporan akan otomatis aktif.`
          }</div>
        </div>
        <span class="pill ${rejected ? 'pill-danger' : 'pill-warning'}">${rejected ? 'DITOLAK' : 'MENUNGGU PERSETUJUAN'}</span>
      </div>`;
  }

  function renderWorkspaceSidebar() {
    if (!state.user) return;
    const isGuest = Boolean(state.user.is_guest || state.user.role === 'guest');
    const tabs = getSidebarTabs(state.user.role, isGuest);
    if (!tabs.some(([id]) => id === state.activeTab)) {
      state.activeTab = tabs[0][0];
    }
    $('sidebar-nav-list').innerHTML = tabs
      .map(
        ([id, label]) =>
          `<li><button type="button" class="sidebar-btn${state.activeTab === id ? ' is-active' : ''}" data-action="ws-tab" data-tab="${esc(id)}">${esc(label)}</button></li>`
      )
      .join('');
  }

  async function loadWorkspaceTab(tab, opts = {}) {
    state.activeTab = tab;
    renderWorkspaceSidebar();
    const main = $('workspace-main');
    const isGuest = Boolean(state.user.is_guest || state.user.role === 'guest');
    const role = state.user.role;

    // Show loading card ONLY when no cached data is ready for instant zero-flicker rendering
    const hasWarmCache =
      (role === 'member' && Boolean(state.apiCache.member_dash)) ||
      (role === 'guardian' && (tab === 'bookings' || tab === 'memberships' ? Boolean(state.apiCache.member_dash) : Boolean(state.apiCache.guardian_dash))) ||
      (role === 'coach' && (tab === 'materials' || Boolean(state.apiCache.coach_dash))) ||
      (role === 'admin' && (tab === 'reports' || tab === 'classes' || Boolean(state.apiCache[`admin_${tab}`])));

    if (!hasWarmCache || opts.showLoader) {
      main.innerHTML = `<div class="card"><h3>Memuat data...</h3><p>Menyiapkan modul ${esc(tab)} secara cepat.</p></div>`;
    }

    try {
      if (isGuest) await renderGuestWorkspace(tab, main, opts);
      else if (role === 'admin') await renderAdminWorkspace(tab, main, opts);
      else if (role === 'coach') await renderCoachWorkspace(tab, main, opts);
      else if (role === 'guardian') await renderGuardianWorkspace(tab, main, opts);
      else await renderMemberWorkspace(tab, main, opts);
    } catch (err) {
      main.innerHTML = `<div class="card"><h3>Gagal memuat modul</h3><p>${esc(err.message)}</p></div>`;
    }
  }

  // --- GUEST LIMITED PREVIEW WORKSPACE ---
  async function renderGuestWorkspace(tab, main, opts = {}) {
    const data = await apiCached('guest_preview', '/guest/preview', { force: opts.force, ttlMs: 30000 });
    const stats = data.stats || {};
    main.innerHTML = `
      <div class="approval-banner">
        <div>
          <strong>Mode Akun Tamu — Akses Terbatas</strong>
          <div>Anda dapat melihat katalog kelas, jadwal live, dan 6 paket membership. Untuk booking kelas, memilih membership, absensi QR, dan mengakses laporan, silakan login sebagai <strong>Member (18+)</strong> atau <strong>Orang Tua / Wali</strong>.</div>
        </div>
        <span class="pill pill-warning">GUEST PREVIEW</span>
      </div>
      <div class="kpi-grid">
        <div class="kpi-card"><div class="kpi-label">Katalog Kelas Aktif</div><div class="kpi-value">${esc(stats.classes_count || 6)}</div><div class="kpi-delta">Dapat dijelajahi tamu</div></div>
        <div class="kpi-card"><div class="kpi-label">Pilihan Membership</div><div class="kpi-value">${esc(stats.plans_count || 6)}</div><div class="kpi-delta">Dapat dibandingkan tamu</div></div>
        <div class="kpi-card"><div class="kpi-label">Jadwal Mendatang</div><div class="kpi-value">${esc(stats.upcoming_schedules || 0)}</div><div class="kpi-delta">Slot live studio</div></div>
        <div class="kpi-card"><div class="kpi-label">Cabang Studio</div><div class="kpi-value">${esc(stats.branches_count || 2)}</div><div class="kpi-delta">Jakarta Selatan &amp; Bandung</div></div>
      </div>
      <div class="guest-scope-grid">
        <article class="card">
          <span class="pill pill-success">DAPAT DIAKSES AKUN TAMU</span>
          <h3>Fitur Pratinjau Terbuka</h3>
          <ul class="scope-list">${(data.allowed_features || []).map((f) => `<li>${esc(f)}</li>`).join('')}</ul>
        </article>
        <article class="card">
          <span class="pill pill-danger">TERKUNCI UNTUK AKUN TAMU</span>
          <h3>Membutuhkan Login Member (18+) / Orang Tua</h3>
          <ul class="scope-list">${(data.locked_features || []).map((f) => `<li>${esc(f)}</li>`).join('')}</ul>
        </article>
      </div>
      <article class="card card-dark">
        <h3>Buka Akses Penuh FitSpace Sekarang</h3>
        <p>Pilih peran sesuai kebutuhan Anda — pendaftaran Member khusus usia 18 tahun ke atas, sedangkan Orang Tua / Wali mewakili peserta anak di bawah 18 tahun.</p>
        <div class="format-group">
          <button type="button" class="btn btn-primary" data-action="open-register-role" data-role="member">Daftar Member (18+)</button>
          <button type="button" class="btn btn-outline" data-action="open-register-role" data-role="guardian">Daftar Orang Tua / Wali</button>
          <button type="button" class="btn btn-outline" data-action="open-login">Login Akun Terdaftar</button>
        </div>
      </article>`;
  }

  // --- SHARED USER ISSUE REPORTING TAB (Member, Guardian, Coach) ---
  async function renderUserIssuesTab(main) {
    const res = await api('/issues');
    const items = res.issues || [];
    main.innerHTML = `
      ${renderApprovalBanner()}
      <article class="card">
        <h3>Kirim Laporan Masalah / Bantuan ke Admin FitSpace</h3>
        <p>Laporkan kendala jadwal, verifikasi akun, pembayaran, atau absensi QR agar segera disinkronkan &amp; diselesaikan oleh Admin.</p>
        <form id="user-issue-form" class="form-grid">
          <div class="field">
            <label for="iss-cat">Kategori Masalah</label>
            <select id="iss-cat" name="category">
              <option value="schedule_sync">Sinkronisasi Jadwal Pelatih &amp; Member</option>
              <option value="account_approval">Verifikasi / Persetujuan Akun</option>
              <option value="attendance_qr">Kendala Absensi / QR Code</option>
              <option value="payment">Kendala Pembayaran / Membership</option>
              <option value="facility">Fasilitas Studio</option>
              <option value="other">Lainnya</option>
            </select>
          </div>
          <div class="field">
            <label for="iss-prio">Prioritas</label>
            <select id="iss-prio" name="priority">
              <option value="medium">Menengah</option>
              <option value="high">Tinggi / Mendesak</option>
              <option value="low">Rendah</option>
            </select>
          </div>
          <div class="field"><label for="iss-subj">Judul Singkat</label><input id="iss-subj" name="subject" required placeholder="Contoh: Penyesuaian jadwal kelas dengan Coach" /></div>
          <div class="field"><label for="iss-desc">Detail Masalah</label><input id="iss-desc" name="description" required placeholder="Jelaskan kendala Anda secara singkat dan jelas" /></div>
          <div class="field"><label>&nbsp;</label><button type="submit" class="btn btn-primary">Kirim Tiket ke Admin</button></div>
        </form>
      </article>
      <article class="card">
        <h3>Riwayat Tiket Bantuan Saya (${items.length})</h3>
        ${items.length ? items.map((i) => `
          <div class="card issue-card${i.status === 'resolved' ? ' is-resolved' : i.priority === 'high' ? ' is-high' : ''}">
            <div class="section-head">
              <div><strong>${esc(i.ticket_code)} — ${esc(i.subject)}</strong><p>${esc(i.description)}</p></div>
              <span class="pill ${i.status === 'resolved' ? 'pill-success' : i.status === 'in_progress' ? 'pill-info' : 'pill-warning'}">${esc(i.status)}</span>
            </div>
            ${i.resolution_notes ? `<p><strong>Solusi Admin:</strong> ${esc(i.resolution_notes)}</p>` : ''}
          </div>`).join('') : '<p>Belum ada tiket masalah yang dikirim.</p>'}
      </article>`;
  }

  // --- ADMIN WORKSPACE (1 targeted API call per tab — ZERO redundant dashboard fetches!) ---
  async function renderAdminWorkspace(tab, main, opts = {}) {
    if (tab === 'overview') {
      const dash = await apiCached('admin_overview', '/admin/dashboard', { force: opts.force });
      state.workspaceData.admin = dash;
      const fin = dash.finance || {};
      const series = fin.monthly_series || [];
      const maxVal = Math.max(1, ...series.map((s) => Math.max(Number(s.income || 0), Number(s.expense || 0))));
      const bars = series
        .map((s) => {
          const iStep = Math.max(1, Math.min(10, Math.round((Number(s.income || 0) / maxVal) * 10)));
          const eStep = Math.max(1, Math.min(10, Math.round((Number(s.expense || 0) / maxVal) * 10)));
          return `
            <div class="bar-col">
              <div class="bar-pair">
                <div class="bar-inc bar-h-${iStep}" title="Pemasukan: ${esc(formatRp(s.income))}"></div>
                <div class="bar-exp bar-h-${eStep}" title="Pengeluaran: ${esc(formatRp(s.expense))}"></div>
              </div>
              <span class="bar-label">${esc(s.label)}</span>
            </div>`;
        })
        .join('');

      main.innerHTML = `
        <div class="kpi-grid">
          <div class="kpi-card">
            <div class="kpi-label">Pendapatan Bersih (Paid - Refund - Pengeluaran)</div>
            <div class="kpi-value">${esc(formatRp(fin.net_income))}</div>
            <div class="kpi-delta">Transaksi pending/failed dikecualikan</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-label">Total Booking Terkonfirmasi</div>
            <div class="kpi-value">${esc(dash.stats.confirmed_bookings)}</div>
            <div class="kpi-delta">Okupansi rata-rata ${esc(dash.stats.occupancy_rate)}%</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-label">Antrean Persetujuan Admin</div>
            <div class="kpi-value">${esc((dash.stats.pending_coaches || 0) + (dash.stats.pending_participants || 0) + (dash.stats.pending_bookings || 0) + (dash.stats.pending_memberships || 0))}</div>
            <div class="kpi-delta">${esc(dash.stats.pending_bookings || 0)} kelas · ${esc(dash.stats.pending_memberships || 0)} membership · ${esc((dash.stats.pending_coaches || 0) + (dash.stats.pending_participants || 0))} akun</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-label">Reschedule Coach &amp; Masalah</div>
            <div class="kpi-value">${esc(dash.stats.pending_coach_reschedules || 0)} / ${esc(dash.stats.open_issues || 0)}</div>
            <div class="kpi-delta">Ajuan pindah jadwal Coach · Tiket terbuka</div>
          </div>
        </div>
        <div class="panel-grid-2">
          <article class="card">
            <h3>Rekap Keuangan 6 Bulan (Biru: Pemasukan Paid · Amber: Pengeluaran)</h3>
            <div class="finance-bars">${bars}</div>
            <p>Aturan Bisnis #14: Transaksi pending &amp; gagal tidak dihitung sebagai pemasukan; refund dikurangkan otomatis.</p>
          </article>
          <article class="card">
            <h3>Distribusi Kelas &amp; Okupansi Studio (Grafik Lingkaran)</h3>
            ${makeDonutChartSvg(dash.category_breakdown || [], `${dash.stats.occupancy_rate || 0}%`, 'Okupansi')}
          </article>
        </div>
        <article class="card">
          <h3>Transaksi Pembayaran Terbaru</h3>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Order</th><th>Pengguna</th><th>Nominal</th><th>Status</th></tr></thead>
              <tbody>
                ${(dash.recent_payments || [])
                  .map(
                    (p) => `<tr><td>${esc(p.order_id)}</td><td>${esc(p.user_name)}</td><td>${esc(formatRp(p.amount))}</td><td><span class="pill ${p.status === 'paid' ? 'pill-success' : p.status === 'refunded' ? 'pill-danger' : 'pill-warning'}">${esc(p.status)}</span></td></tr>`
                  )
                  .join('')}
              </tbody>
            </table>
          </div>
        </article>`;
      return;
    }

    if (tab === 'approvals') {
      const appData = await apiCached('admin_approvals', '/admin/approvals', { force: opts.force });
      const coaches = appData.coaches || [];
      const participants = appData.participants || [];
      const pendingBookings = appData.pending_bookings || [];
      const pendingMemberships = appData.pending_memberships || [];
      main.innerHTML = `
        <article class="card">
          <h3>1. Persetujuan Pemilihan Kelas Member &amp; Orang Tua (${pendingBookings.length})</h3>
          <p>Seluruh pengajuan pemilihan kelas Member (18+) &amp; Orang Tua disetujui oleh Admin agar status booking &amp; tiket QR Code langsung aktif di akun peserta.</p>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Kode Booking</th><th>Peserta &amp; Peran</th><th>Kelas &amp; Jadwal</th><th>Nominal</th><th>Aksi Admin</th></tr></thead>
              <tbody>
                ${pendingBookings.length ? pendingBookings.map((b) => `
                  <tr>
                    <td><code>${esc(b.booking_code)}</code></td>
                    <td><strong>${esc(b.child_name || b.user_name)}</strong><br/><small>${b.user_role === 'guardian' ? `Wali: ${esc(b.user_name)}` : 'Member 18+'}</small></td>
                    <td>${esc(b.class_name)}<br/><small>${esc(formatDateTime(b.starts_at))}</small></td>
                    <td>${esc(formatRp(b.final_amount))}</td>
                    <td><button type="button" class="btn btn-success btn-sm" data-action="admin-approve-booking" data-id="${esc(b.id)}">Setujui Kelas &amp; Terbitkan QR</button></td>
                  </tr>`).join('') : '<tr><td colspan="5">Semua pemilihan kelas sudah disetujui &amp; terkonfirmasi.</td></tr>'}
              </tbody>
            </table>
          </div>
        </article>
        <article class="card">
          <h3>2. Persetujuan Pemilihan Paket Membership (${pendingMemberships.length})</h3>
          <p>Setujui pengajuan paket membership Member &amp; Orang Tua agar kuota sesi dan masa berlaku langsung aktif di dashboard peserta.</p>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Pengguna &amp; Peran</th><th>Paket Membership</th><th>Kuota &amp; Harga</th><th>Status</th><th>Aksi Admin</th></tr></thead>
              <tbody>
                ${pendingMemberships.length ? pendingMemberships.map((m) => `
                  <tr>
                    <td><strong>${esc(m.user_name)}</strong><br/><small>${esc(m.user_email)} · ${m.user_role === 'guardian' ? 'ORANG TUA' : 'MEMBER 18+'}</small></td>
                    <td><strong>${esc(m.plan_name)}</strong><br/><small>${esc(m.duration_days)} hari</small></td>
                    <td>${esc(m.total_quota)} Sesi · ${esc(formatRp(m.price))}</td>
                    <td><span class="pill pill-warning">${esc(m.status)}</span></td>
                    <td><button type="button" class="btn btn-success btn-sm" data-action="admin-approve-membership" data-id="${esc(m.id)}">Setujui &amp; Aktifkan</button></td>
                  </tr>`).join('') : '<tr><td colspan="5">Tidak ada pengajuan paket membership yang menunggu ACC.</td></tr>'}
              </tbody>
            </table>
          </div>
        </article>
        <article class="card">
          <h3>3. Persetujuan Akun Member (18+) &amp; Orang Tua / Wali</h3>
          <p>Admin menyetujui akun Member (18+) dan Orang Tua / Wali secara terpusat sebelum peserta dapat mengajukan pemilihan kelas dan membership.</p>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Pengguna</th><th>Peran, Usia &amp; Anak</th><th>Status</th><th>Keputusan Admin</th></tr></thead>
              <tbody>
                ${participants.map((p) => `
                  <tr>
                    <td><strong>${esc(p.name)}</strong><br/><small>${esc(p.email)} · ${esc(p.phone || '-')}</small></td>
                    <td>
                      <span class="pill ${p.role === 'guardian' ? 'pill-success' : 'pill-info'}">${esc(p.role === 'guardian' ? 'ORANG TUA / WALI' : 'MEMBER 18+')}</span>
                      ${p.age ? `<small>(${esc(p.age)} thn)</small>` : ''}
                      ${(p.children || []).length ? `<br/><small>Anak: ${esc(p.children.map((ch) => `${ch.full_name} (${ch.age} thn)`).join(', '))}</small>` : ''}
                    </td>
                    <td><span class="pill ${p.approval_status === 'approved' ? 'pill-success' : p.approval_status === 'rejected' ? 'pill-danger' : 'pill-warning'}">${esc(p.approval_status)}</span></td>
                    <td>
                      <div class="format-group">
                        ${p.approval_status !== 'approved' ? `<button type="button" class="btn btn-success btn-sm" data-action="admin-decide-user" data-id="${esc(p.id)}" data-decision="approved">Setujui Akun</button>` : ''}
                        ${p.approval_status !== 'rejected' ? `<button type="button" class="btn btn-outline btn-sm" data-action="admin-decide-user" data-id="${esc(p.id)}" data-decision="rejected">Tolak</button>` : ''}
                      </div>
                    </td>
                  </tr>`).join('')}
              </tbody>
            </table>
          </div>
        </article>
        <article class="card">
          <h3>4. Persetujuan Akun Coach / Pelatih</h3>
          <p>Hanya Admin yang dapat menyetujui atau menolak akun Coach sebelum pelatih diizinkan login dan menerima jadwal mengajar resmi.</p>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Nama Coach &amp; Email</th><th>Sertifikasi &amp; Spesialisasi</th><th>Status</th><th>Aksi Admin</th></tr></thead>
              <tbody>
                ${coaches.map((c) => `
                  <tr>
                    <td><strong>${esc(c.name)}</strong><br/><small>${esc(c.email)} · ${esc(c.phone || '-')}</small></td>
                    <td>${esc(c.certifications || '-')}<br/><small>${esc((c.specializations || []).join(', ') || 'Umum')} · ${esc(c.experience_years || 0)} thn</small></td>
                    <td><span class="pill ${c.approval_status === 'approved' ? 'pill-success' : c.approval_status === 'rejected' ? 'pill-danger' : 'pill-warning'}">${esc(c.approval_status)}</span></td>
                    <td>
                      <div class="format-group">
                        ${c.approval_status !== 'approved' ? `<button type="button" class="btn btn-success btn-sm" data-action="admin-decide-coach" data-id="${esc(c.id)}" data-decision="approved">Setujui Coach</button>` : ''}
                        ${c.approval_status !== 'rejected' ? `<button type="button" class="btn btn-danger btn-sm" data-action="admin-decide-coach" data-id="${esc(c.id)}" data-decision="rejected">Tolak</button>` : ''}
                      </div>
                    </td>
                  </tr>`).join('')}
              </tbody>
            </table>
          </div>
        </article>`;
      return;
    }

    if (tab === 'issues') {
      const issData = await apiCached('admin_issues', '/admin/issues', { force: opts.force });
      const issues = issData.issues || [];
      main.innerHTML = `
        <article class="card">
          <h3>Pusat Penanganan Masalah &amp; Resolusi Admin (${issues.length} Tiket)</h3>
          <p>Tangani kendala sinkronisasi jadwal pelatih-member, verifikasi akun, pembayaran, maupun absensi QR secara terpusat.</p>
          ${issues.length ? issues.map((i) => `
            <div class="card issue-card${i.status === 'resolved' ? ' is-resolved' : i.priority === 'high' ? ' is-high' : ''}">
              <div class="section-head">
                <div>
                  <span class="pill ${i.priority === 'high' ? 'pill-danger' : 'pill-info'}">${esc(i.priority)} · ${esc(i.category)}</span>
                  <h4>${esc(i.ticket_code)} — ${esc(i.subject)}</h4>
                  <p>Pelapor: <strong>${esc(i.reporter_name)}</strong> (${esc(i.reporter_role)}) · ${esc(i.reporter_email)}</p>
                  <p>${esc(i.description)}</p>
                  ${i.resolution_notes ? `<p><strong>Catatan Solusi:</strong> ${esc(i.resolution_notes)}</p>` : ''}
                </div>
                <div class="format-group">
                  <span class="pill ${i.status === 'resolved' ? 'pill-success' : i.status === 'in_progress' ? 'pill-info' : 'pill-warning'}">${esc(i.status)}</span>
                  ${i.status !== 'resolved' ? `<button type="button" class="btn btn-primary btn-sm" data-action="open-resolve-issue" data-id="${esc(i.id)}" data-code="${esc(i.ticket_code)}">Tangani / Selesaikan</button>` : ''}
                </div>
              </div>
            </div>`).join('') : '<p>Tidak ada tiket masalah terbuka. Seluruh operasional studio berjalan lancar.</p>'}
        </article>`;
      return;
    }

    if (tab === 'classes') {
      main.innerHTML = `
        <article class="card">
          <h3>Tambah Kategori Olahraga Baru (Dynamic Class Management)</h3>
          <form id="admin-category-form" class="form-grid">
            <div class="field"><label for="cat-name">Nama Kategori</label><input id="cat-name" name="name" required placeholder="Contoh: CrossFit / Muay Thai" /></div>
            <div class="field"><label for="cat-desc">Deskripsi</label><input id="cat-desc" name="description" placeholder="Deskripsi singkat olahraga" /></div>
            <div class="field"><label>&nbsp;</label><button type="submit" class="btn btn-dark">Tambah Kategori</button></div>
          </form>
        </article>
        <article class="card">
          <h3>Tambah Jenis Kelas Baru &amp; Indikator Evaluasi Dinamis</h3>
          <form id="admin-class-form" class="form-grid">
            <div class="field"><label for="cls-name">Nama Kelas</label><input id="cls-name" name="name" required placeholder="Contoh: Reformer Pilates Pro" /></div>
            <div class="field">
              <label for="cls-cat">Kategori</label>
              <select id="cls-cat" name="category_id" required>${state.categories.map((c) => `<option value="${esc(c.id)}">${esc(c.name)}</option>`).join('')}</select>
            </div>
            <div class="field">
              <label for="cls-lvl">Level</label>
              <select id="cls-lvl" name="level_id" required>${state.levels.map((l) => `<option value="${esc(l.id)}">${esc(l.name)}</option>`).join('')}</select>
            </div>
            <div class="field"><label for="cls-price">Harga Per Sesi (Rp)</label><input id="cls-price" name="price_per_session" type="number" min="0" value="140000" required /></div>
            <div class="field"><label for="cls-cap">Kapasitas Maks</label><input id="cls-cap" name="max_capacity" type="number" min="1" value="14" required /></div>
            <div class="field"><label for="cls-dur">Durasi (Menit)</label><input id="cls-dur" name="duration_minutes" type="number" min="15" value="60" required /></div>
            <div class="field"><label for="cls-min-age">Usia Min</label><input id="cls-min-age" name="min_age" type="number" min="4" value="15" required /></div>
            <div class="field"><label for="cls-max-age">Usia Maks</label><input id="cls-max-age" name="max_age" type="number" min="6" value="65" required /></div>
            <div class="field"><label for="cls-inds">Indikator Evaluasi (pisahkan koma)</label><input id="cls-inds" name="indicators" value="Teknik Dasar, Stamina, Koordinasi" required /></div>
            <div class="field"><label>&nbsp;</label><button type="submit" class="btn btn-primary">Simpan Kelas Dinamis</button></div>
          </form>
        </article>`;
      return;
    }

    if (tab === 'schedules') {
      const [meta, syncData] = await Promise.all([
        apiCached('admin_schedule_meta', '/admin/schedule-meta', { ttlMs: 30000 }),
        apiCached('admin_schedules', '/admin/schedule-sync', { force: opts.force }),
      ]);
      const avails = syncData.availabilities || [];
      const reschedules = syncData.schedule_reschedules || [];
      const dayNames = ['', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
      main.innerHTML = `
        <article class="card">
          <h3>Pengajuan Reschedule Jadwal Coach (ACC → Otomatis Diteruskan ke Member &amp; Orang Tua)</h3>
          <p>Saat Coach berhalangan mengajar, ajuan perubahan waktu masuk ke sini. Begitu Admin klik <strong>Setujui &amp; Teruskan</strong>, jadwal diperbarui tanpa bentrok dan notifikasi langsung dikirim ke seluruh Member &amp; Orang Tua terdaftar.</p>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Coach &amp; Kelas</th><th>Perubahan Jadwal</th><th>Alasan Coach</th><th>Status</th><th>Keputusan Admin</th></tr></thead>
              <tbody>
                ${reschedules.length ? reschedules.map((r) => `
                  <tr>
                    <td><strong>${esc(r.coach_name)}</strong><br/><small>${esc(r.class_name)} · ${esc(r.room_name)} (${esc(r.booked_count || 0)} peserta)</small></td>
                    <td>
                      <div class="reschedule-diff">
                        <span class="reschedule-old">${esc(formatDateTime(r.original_starts_at))}</span>
                        <span class="reschedule-new">&rarr; ${esc(formatDateTime(r.proposed_starts_at))}</span>
                      </div>
                    </td>
                    <td>${esc(r.reason)}</td>
                    <td><span class="pill ${r.status === 'approved' ? 'pill-success' : r.status === 'rejected' ? 'pill-danger' : 'pill-warning'}">${r.status === 'approved' ? `ACC (${esc(r.affected_participants_count || 0)} peserta)` : esc(r.status)}</span></td>
                    <td>
                      ${r.status === 'pending' ? `
                        <div class="format-group">
                          <button type="button" class="btn btn-success btn-sm" data-action="admin-decide-reschedule" data-id="${esc(r.id)}" data-decision="approved">Setujui &amp; Teruskan</button>
                          <button type="button" class="btn btn-danger btn-sm" data-action="admin-decide-reschedule" data-id="${esc(r.id)}" data-decision="rejected">Tolak</button>
                        </div>` : `<small>${esc(r.reviewed_by_name || 'Admin')}</small>`}
                    </td>
                  </tr>`).join('') : '<tr><td colspan="5">Belum ada pengajuan reschedule jadwal dari Coach.</td></tr>'}
              </tbody>
            </table>
          </div>
        </article>
        <article class="card">
          <h3>Sinkronisasi Ajuan Ketersediaan Rutin Coach ↔ Jadwal Studio</h3>
          <p>Setujui usulan jam mengajar dari Coach agar jadwal antara pelatih dan peserta selalu sinkron tanpa bentrok ruangan.</p>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Coach</th><th>Cabang &amp; Waktu Usulan</th><th>Catatan</th><th>Status</th><th>Aksi Sinkronisasi</th></tr></thead>
              <tbody>
                ${avails.length ? avails.map((a) => `
                  <tr>
                    <td><strong>${esc(a.coach_name)}</strong></td>
                    <td>${esc(a.branch_name)}<br/><small>${esc(dayNames[a.day_of_week] || a.day_of_week)} · ${esc(a.start_time)}–${esc(a.end_time)}</small></td>
                    <td>${esc(a.request_notes || '-')}</td>
                    <td><span class="pill ${a.status === 'approved' ? 'pill-success' : a.status === 'rejected' ? 'pill-danger' : 'pill-warning'}">${esc(a.status)}</span></td>
                    <td>
                      ${a.status === 'pending' ? `
                        <div class="format-group">
                          <button type="button" class="btn btn-success btn-sm" data-action="admin-decide-avail" data-id="${esc(a.id)}" data-decision="approved">Setujui &amp; Sinkronkan</button>
                          <button type="button" class="btn btn-danger btn-sm" data-action="admin-decide-avail" data-id="${esc(a.id)}" data-decision="rejected">Tolak</button>
                        </div>` : '<small>Ditinjau</small>'}
                    </td>
                  </tr>`).join('') : '<tr><td colspan="5">Belum ada pengajuan perubahan jadwal dari Coach.</td></tr>'}
              </tbody>
            </table>
          </div>
        </article>
        <article class="card">
          <h3>Buat Jadwal Resmi (Validasi Anti-Bentrok Coach, Ruangan &amp; Spesialisasi)</h3>
          <form id="admin-schedule-form" class="form-grid">
            <div class="field"><label for="sch-cls">Kelas</label><select id="sch-cls" name="class_id">${state.classes.map((c) => `<option value="${esc(c.id)}">${esc(c.name)} (${esc(c.category_name)})</option>`).join('')}</select></div>
            <div class="field"><label for="sch-coach">Coach</label><select id="sch-coach" name="coach_id">${(meta.coaches || []).map((c) => `<option value="${esc(c.id)}">${esc(c.name)} — ${esc(c.specializations.join(', '))}</option>`).join('')}</select></div>
            <div class="field"><label for="sch-room">Ruangan</label><select id="sch-room" name="room_id">${(meta.rooms || []).map((r) => `<option value="${esc(r.id)}">${esc(r.name)} (${esc(r.branch_name)} · maks ${esc(r.max_capacity)})</option>`).join('')}</select></div>
            <div class="field"><label for="sch-start">Waktu Mulai</label><input id="sch-start" name="starts_at" type="datetime-local" required /></div>
            <div class="field"><label for="sch-cap">Kapasitas</label><input id="sch-cap" name="capacity" type="number" min="1" value="12" required /></div>
            <div class="field"><label>&nbsp;</label><button type="submit" class="btn btn-primary">Simpan Jadwal Anti-Bentrok</button></div>
          </form>
        </article>
        <article class="card">
          <h3>Jadwal Aktif</h3>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Waktu</th><th>Kelas</th><th>Coach</th><th>Ruangan</th><th>Terisi</th></tr></thead>
              <tbody>
                ${state.schedules.map((s) => `<tr><td>${esc(formatDateTime(s.starts_at))}</td><td>${esc(s.class_name)}</td><td>${esc(s.coach_name)}</td><td>${esc(s.room_name)}</td><td>${esc(s.booked_count)}/${esc(s.capacity)}</td></tr>`).join('')}
              </tbody>
            </table>
          </div>
        </article>`;
      return;
    }

    if (tab === 'bookings') {
      const list = await apiCached('admin_bookings', '/admin/bookings', { force: opts.force });
      main.innerHTML = `
        <article class="card">
          <h3>Semua Booking &amp; Simulasi Verifikasi Pembayaran / Refund</h3>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Kode</th><th>Peserta</th><th>Kelas &amp; Jadwal</th><th>Nominal</th><th>Status</th><th>Aksi</th></tr></thead>
              <tbody>
                ${(list.bookings || [])
                  .map(
                    (b) => `
                    <tr>
                      <td>${esc(b.booking_code)}</td>
                      <td>${esc(b.participant_name)}</td>
                      <td>${esc(b.class_name)}<br/><small>${esc(formatDateTime(b.starts_at))}</small></td>
                      <td>${esc(formatRp(b.final_amount))}</td>
                      <td><span class="pill ${b.status === 'confirmed' || b.status === 'attended' ? 'pill-success' : b.status === 'cancelled' ? 'pill-danger' : 'pill-warning'}">${esc(b.status)}</span></td>
                      <td>
                        ${b.payment_id && b.payment_status === 'paid' ? `<button type="button" class="btn btn-danger btn-sm" data-action="admin-refund" data-id="${esc(b.payment_id)}">Refund</button>` : '-'}
                      </td>
                    </tr>`
                  )
                  .join('')}
              </tbody>
            </table>
          </div>
        </article>`;
      return;
    }

    if (tab === 'finance') {
      const fData = await apiCached('admin_finance', '/admin/finance', { force: opts.force });
      main.innerHTML = `
        <article class="card">
          <h3>Catat Pengeluaran Operasional</h3>
          <form id="admin-expense-form" class="form-grid">
            <div class="field"><label for="exp-branch">Cabang</label><select id="exp-branch" name="branch_id">${state.branches.map((b) => `<option value="${esc(b.id)}">${esc(b.name)}</option>`).join('')}</select></div>
            <div class="field"><label for="exp-cat">Kategori Pengeluaran</label><select id="exp-cat" name="expense_category_id">${(fData.expense_categories || []).map((c) => `<option value="${esc(c.id)}">${esc(c.name)}</option>`).join('')}</select></div>
            <div class="field"><label for="exp-amt">Nominal (Rp)</label><input id="exp-amt" name="amount" type="number" min="1000" required value="500000" /></div>
            <div class="field"><label for="exp-desc">Keterangan</label><input id="exp-desc" name="description" required placeholder="Contoh: Honor Coach / Perawatan Matras" /></div>
            <div class="field"><label for="exp-date">Tanggal</label><input id="exp-date" name="incurred_on" type="date" required value="${new Date().toISOString().slice(0, 10)}" /></div>
            <div class="field"><label>&nbsp;</label><button type="submit" class="btn btn-primary">Catat Pengeluaran</button></div>
          </form>
        </article>
        <article class="card">
          <h3>Ringkasan Keuangan</h3>
          <p><strong>Pemasukan Paid:</strong> ${esc(formatRp(fData.summary.total_income))} &bull; <strong>Refund:</strong> -${esc(formatRp(fData.summary.total_refunds))} &bull; <strong>Pengeluaran:</strong> -${esc(formatRp(fData.summary.total_expenses))} &bull; <strong>Pendapatan Bersih:</strong> ${esc(formatRp(fData.summary.net_income))}</p>
        </article>`;
      return;
    }

    if (tab === 'reports') {
      main.innerHTML = `
        <article class="card">
          <h3>Reporting Center — 11 Laporan Eksekutif (PDF, Excel &amp; Word)</h3>
          <p>Pilih format dokumen yang Anda butuhkan: <strong>PDF berlogo FitSpace</strong>, <strong>Excel (.xlsx) terformat lengkap</strong>, atau <strong>Word (.docx)</strong> siap cetak &amp; edit.</p>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Jenis Laporan</th><th>Dokumen PDF</th><th>Spreadsheet Excel</th><th>Dokumen Word</th></tr></thead>
              <tbody>
                ${REPORT_TYPES.map(
                  ([key, label]) => `
                  <tr>
                    <td><strong>${esc(label)}</strong></td>
                    <td><button type="button" class="btn btn-dark btn-sm" data-action="export-report" data-type="${esc(key)}" data-format="pdf">Unduh PDF</button></td>
                    <td><button type="button" class="btn btn-success btn-sm" data-action="export-report" data-type="${esc(key)}" data-format="excel">Unduh Excel</button></td>
                    <td><button type="button" class="btn btn-word btn-sm" data-action="export-report" data-type="${esc(key)}" data-format="word">Unduh Word</button></td>
                  </tr>`
                ).join('')}
              </tbody>
            </table>
          </div>
        </article>`;
      return;
    }

    const audit = await apiCached('admin_audit', '/admin/audit-logs', { force: opts.force });
    main.innerHTML = `
      <article class="card">
        <h3>Audit Log Aktivitas Penting &amp; Pengaturan Kebijakan Sistem</h3>
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Waktu</th><th>Aktor</th><th>Aksi</th><th>Entitas</th></tr></thead>
            <tbody>
              ${(audit.logs || [])
                .map((l) => `<tr><td>${esc(formatDateTime(l.created_at))}</td><td>${esc(l.user_name || 'Sistem')}</td><td><span class="pill pill-info">${esc(l.action)}</span></td><td>${esc(l.entity_type)} #${esc(l.entity_id || '-')}</td></tr>`)
                .join('')}
            </tbody>
          </table>
        </div>
      </article>`;
  }

  // --- COACH WORKSPACE (Receives Official Admin Schedules + Requests Reschedule via Admin!) ---
  async function renderCoachWorkspace(tab, main, opts = {}) {
    if (tab === 'issues') {
      await renderUserIssuesTab(main);
      return;
    }

    if (tab === 'materials') {
      main.innerHTML = `
        <article class="card">
          <h3>Tambah Materi Pembelajaran Baru</h3>
          <form id="coach-material-form" class="form-grid">
            <div class="field"><label for="mat-class">Kelas</label><select id="mat-class" name="class_id">${state.classes.map((c) => `<option value="${esc(c.id)}">${esc(c.name)}</option>`).join('')}</select></div>
            <div class="field"><label for="mat-title">Judul Materi</label><input id="mat-title" name="title" required placeholder="Contoh: Core Stability Level 2" /></div>
            <div class="field"><label for="mat-dur">Durasi (Mnt)</label><input id="mat-dur" name="duration_minutes" type="number" min="5" value="20" required /></div>
            <div class="field"><label for="mat-eq">Peralatan</label><input id="mat-eq" name="equipment_needed" placeholder="Matras, Reformer" /></div>
            <div class="field"><label for="mat-obj">Tujuan &amp; Instruksi</label><input id="mat-obj" name="learning_objective" required placeholder="Aktivasi otot inti dan kontrol napas" /></div>
            <div class="field"><label>&nbsp;</label><button type="submit" class="btn btn-primary">Simpan Materi</button></div>
          </form>
        </article>`;
      return;
    }

    const data = await apiCached('coach_dash', '/coach/dashboard', { force: opts.force });
    state.workspaceData.coach = data;

    if (tab === 'overview') {
      const scheds = data.schedules || [];
      const att = data.attendance_stats || { total: 0, present: 0, rate: 100 };
      const pendResch = Number(data.coach?.pending_reschedules_count || 0);
      main.innerHTML = `
        ${pendResch > 0 ? `
          <div class="approval-banner">
            <div>
              <strong>${esc(pendResch)} Pengajuan Reschedule Jadwal Menunggu Persetujuan Admin</strong>
              <div>Jika disetujui Admin, perubahan jadwal otomatis diteruskan ke seluruh Member &amp; Orang Tua terkait.</div>
            </div>
            <button type="button" class="btn btn-primary btn-sm" data-action="ws-tab" data-tab="reschedule">Pantau Status Reschedule</button>
          </div>` : ''}
        <div class="panel-grid-2">
          <article class="card card-dark">
            <span class="pill pill-info">SINGLE-USE HMAC QR VALIDATOR</span>
            <h3>Pemindai / Validasi QR Code Kehadiran Peserta</h3>
            <form id="coach-qr-form" class="form-grid">
              <div class="field"><label for="qr-token-input" class="label-on-dark">Token / Kode QR Booking</label><input id="qr-token-input" name="qr_token" required placeholder="Tempel token QR atau kode FS-..." /></div>
              <div class="field">
                <label for="qr-status-input" class="label-on-dark">Status Kehadiran</label>
                <select id="qr-status-input" name="status"><option value="present">Present (Hadir)</option><option value="late">Late (Terlambat)</option><option value="excused">Excused (Izin)</option></select>
              </div>
              <div class="field"><label>&nbsp;</label><button type="submit" class="btn btn-primary">Validasi QR Sekali Pakai</button></div>
            </form>
          </article>
          <article class="card">
            <h3>Tingkat Kehadiran Studio (QR Tervalidasi)</h3>
            <div class="ring-metric-row">
              ${makeRingChartSvg(att.rate, 'Hadir QR')}
              <div>
                <strong>${esc(att.present)} dari ${esc(att.total)} sesi tervalidasi hadir</strong>
                <p>Jadwal resmi ditetapkan oleh Admin. Jika berhalangan mengajar, ajukan reschedule agar Admin dapat menyetujui &amp; meneruskan info ke peserta.</p>
                <button type="button" class="btn btn-outline btn-sm" data-action="ws-tab" data-tab="reschedule">Ajukan Reschedule Jadwal</button>
              </div>
            </div>
          </article>
        </div>
        <article class="card">
          <h3>Jadwal Mengajar Resmi Coach (${esc(data.coach.name)})</h3>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Waktu</th><th>Kelas</th><th>Ruangan</th><th>Peserta</th><th>Aksi</th></tr></thead>
              <tbody>
                ${scheds.map((s) => `
                  <tr>
                    <td>${esc(formatDateTime(s.starts_at))}</td>
                    <td><strong>${esc(s.class_name)}</strong></td>
                    <td>${esc(s.room_name)} (${esc(s.branch_name)})</td>
                    <td>${esc(s.booked_count)}/${esc(s.capacity)}</td>
                    <td><button type="button" class="btn btn-outline btn-sm" data-action="coach-quick-reschedule" data-id="${esc(s.id)}">Ajukan Reschedule</button></td>
                  </tr>`).join('')}
              </tbody>
            </table>
          </div>
        </article>`;
      return;
    }

    if (tab === 'evaluations') {
      const bookings = data.evaluable_bookings || [];
      const recentReports = data.recent_reports || [];
      const coachReportTypes = [
        ['progress', 'Laporan Perkembangan Peserta'],
        ['attendance', 'Laporan Kehadiran Studio'],
        ['schedules', 'Laporan Jadwal Kelas'],
        ['coach_activity', 'Laporan Aktivitas Coach'],
        ['reviews', 'Laporan Rating & Ulasan Coach'],
      ];
      main.innerHTML = `
        <article class="card">
          <h3>Input Nilai Evaluasi (Skala 1–4) &amp; Publikasikan ke Peserta</h3>
          <p>Skala: 1 = Belum Menguasai · 2 = Mulai Berkembang · 3 = Berkembang Sesuai Target · 4 = Sangat Baik</p>
          ${bookings.length ? `
            <form id="coach-eval-form" class="form-grid">
              <div class="field">
                <label for="ev-booking">Peserta &amp; Sesi</label>
                <select id="ev-booking" name="booking_id" required>
                  ${bookings.map((b) => `<option value="${esc(b.id)}">${esc(b.participant_name)} — ${esc(b.class_name)}</option>`).join('')}
                </select>
              </div>
              <div class="field"><label for="ev-mat">Materi Dipelajari</label><input id="ev-mat" name="material_studied" required value="Breathing & Core Stability" /></div>
              <div class="field"><label for="ev-s1">Skor Indikator 1 (1–4)</label><input id="ev-s1" name="score_1" type="number" min="1" max="4" value="4" required /></div>
              <div class="field"><label for="ev-s2">Skor Indikator 2 (1–4)</label><input id="ev-s2" name="score_2" type="number" min="1" max="4" value="3" required /></div>
              <div class="field"><label for="ev-notes">Catatan Perkembangan</label><input id="ev-notes" name="progress_notes" required value="Kontrol postur dan pernapasan meningkat pesat." /></div>
              <div class="field"><label for="ev-rec">Rekomendasi Latihan Berikutnya</label><input id="ev-rec" name="next_recommendation" required value="Lanjutkan progresi beban pegas menengah." /></div>
              <div class="field">
                <label for="ev-pub">Visibilitas Nilai</label>
                <select id="ev-pub" name="is_published">
                  <option value="1">Langsung Munculkan ke Member / Orang Tua</option>
                  <option value="0">Simpan sebagai Draft (Sembunyikan dulu)</option>
                </select>
              </div>
              <div class="field"><label>&nbsp;</label><button type="submit" class="btn btn-primary">Simpan &amp; Publikasikan Nilai</button></div>
            </form>` : '<p>Belum ada peserta terkonfirmasi untuk dievaluasi pada jadwal Anda.</p>'}
        </article>
        <article class="card">
          <h3>Daftar Nilai Evaluasi &amp; Kontrol Publikasi (${recentReports.length})</h3>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Peserta &amp; Kelas</th><th>Rata-rata</th><th>Status Tampil</th><th>Aksi</th></tr></thead>
              <tbody>
                ${recentReports.length ? recentReports.map((r) => `
                  <tr>
                    <td><strong>${esc(r.participant_name)}</strong><br/><small>${esc(r.class_name)} · ${esc(r.evaluated_at)}</small></td>
                    <td><strong>${esc(r.average_score)} / 4.0</strong></td>
                    <td><span class="pill ${r.is_published ? 'pill-success' : 'pill-warning'}">${r.is_published ? 'TAMPIL KE PESERTA' : 'DRAFT'}</span></td>
                    <td>
                      <button type="button" class="btn btn-outline btn-sm" data-action="coach-toggle-publish" data-id="${esc(r.id)}" data-pub="${r.is_published ? '0' : '1'}">${r.is_published ? 'Sembunyikan' : 'Munculkan Nilai'}</button>
                    </td>
                  </tr>`).join('') : '<tr><td colspan="4">Belum ada laporan nilai tercatat.</td></tr>'}
              </tbody>
            </table>
          </div>
        </article>
        <article class="card">
          <h3>Unduh Laporan Coach (PDF, Excel &amp; Word)</h3>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Laporan</th><th>PDF</th><th>Excel</th><th>Word</th></tr></thead>
              <tbody>
                ${coachReportTypes.map(([k, lbl]) => `
                  <tr>
                    <td><strong>${esc(lbl)}</strong></td>
                    <td><button type="button" class="btn btn-dark btn-sm" data-action="export-report" data-type="${esc(k)}" data-format="pdf">PDF</button></td>
                    <td><button type="button" class="btn btn-success btn-sm" data-action="export-report" data-type="${esc(k)}" data-format="excel">Excel</button></td>
                    <td><button type="button" class="btn btn-word btn-sm" data-action="export-report" data-type="${esc(k)}" data-format="word">Word</button></td>
                  </tr>`).join('')}
              </tbody>
            </table>
          </div>
        </article>`;
      return;
    }

    const scheds = data.schedules || [];
    const reschedules = data.schedule_reschedules || [];
    const avails = data.availabilities || [];
    const defaultDt = new Date(Date.now() + 72 * 3600 * 1000).toISOString().slice(0, 16);
    main.innerHTML = `
      <article class="card">
        <h3>Ajukan Reschedule Jadwal Mengajar ke Admin</h3>
        <p>Jika Anda berhalangan mengajar pada jadwal yang ditetapkan, ajukan waktu pengganti melalui form ini. Setelah <strong>disetujui (ACC) oleh Admin</strong>, perubahan jadwal akan otomatis diteruskan ke seluruh Member &amp; Orang Tua yang terdaftar.</p>
        ${scheds.length ? `
          <form id="coach-reschedule-form" class="form-grid">
            <div class="field">
              <label for="cr-sched">Pilih Jadwal Mengajar</label>
              <select id="cr-sched" name="schedule_id" required>
                ${scheds.map((s) => `<option value="${esc(s.id)}">${esc(s.class_name)} — ${esc(formatDateTime(s.starts_at))} (${esc(s.booked_count)} peserta)</option>`).join('')}
              </select>
            </div>
            <div class="field">
              <label for="cr-start">Usulan Waktu Pengganti</label>
              <input id="cr-start" name="proposed_starts_at" type="datetime-local" value="${esc(defaultDt)}" required />
            </div>
            <div class="field">
              <label for="cr-reason">Alasan Perubahan Jadwal</label>
              <input id="cr-reason" name="reason" required placeholder="Contoh: Berhalangan hadir karena seminar sertifikasi pelatih" />
            </div>
            <div class="field"><label>&nbsp;</label><button type="submit" class="btn btn-primary">Kirim Ajuan Reschedule ke Admin</button></div>
          </form>` : '<p>Belum ada jadwal mengajar aktif untuk diajukan reschedule.</p>'}
      </article>
      <article class="card">
        <h3>Riwayat Pengajuan Reschedule Jadwal (${reschedules.length})</h3>
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Kelas &amp; Ruangan</th><th>Jadwal Asal &rarr; Usulan</th><th>Alasan</th><th>Status ACC Admin</th></tr></thead>
            <tbody>
              ${reschedules.length ? reschedules.map((r) => `
                <tr>
                  <td><strong>${esc(r.class_name)}</strong><br/><small>${esc(r.room_name)}</small></td>
                  <td>
                    <div class="reschedule-diff">
                      <span class="reschedule-old">${esc(formatDateTime(r.original_starts_at))}</span>
                      <span class="reschedule-new">&rarr; ${esc(formatDateTime(r.proposed_starts_at))}</span>
                    </div>
                  </td>
                  <td>${esc(r.reason)}</td>
                  <td><span class="pill ${r.status === 'approved' ? 'pill-success' : r.status === 'rejected' ? 'pill-danger' : 'pill-warning'}">${r.status === 'approved' ? `ACC · Diteruskan ke ${esc(r.affected_participants_count || 0)} Peserta` : r.status === 'rejected' ? 'DITOLAK ADMIN' : 'MENUNGGU ACC ADMIN'}</span></td>
                </tr>`).join('') : '<tr><td colspan="4">Belum ada pengajuan reschedule jadwal.</td></tr>'}
            </tbody>
          </table>
        </div>
      </article>
      <article class="card">
        <h3>Ajukan Ketersediaan Jam Mengajar Rutin ke Admin (${avails.length})</h3>
        <form id="coach-avail-form" class="form-grid">
          <div class="field"><label for="av-branch">Cabang</label><select id="av-branch" name="branch_id">${state.branches.map((b) => `<option value="${esc(b.id)}">${esc(b.name)}</option>`).join('')}</select></div>
          <div class="field"><label for="av-day">Hari (1=Senin..7=Minggu)</label><input id="av-day" name="day_of_week" type="number" min="1" max="7" value="3" required /></div>
          <div class="field"><label for="av-start">Jam Mulai</label><input id="av-start" name="start_time" type="time" value="09:00" required /></div>
          <div class="field"><label for="av-end">Jam Selesai</label><input id="av-end" name="end_time" type="time" value="15:00" required /></div>
          <div class="field"><label for="av-note">Alasan / Catatan</label><input id="av-note" name="request_notes" required placeholder="Ketersediaan tambahan untuk kelas pagi" /></div>
          <div class="field"><label>&nbsp;</label><button type="submit" class="btn btn-dark">Kirim Ketersediaan Rutin</button></div>
        </form>
      </article>`;
  }

  // --- MEMBER WORKSPACE (Cached + Live Admin Approval Sync + Circular Ring + Schedule Change Notifications) ---
  async function renderMemberWorkspace(tab, main, opts = {}) {
    if (tab === 'issues') {
      await renderUserIssuesTab(main);
      return;
    }

    const data = await apiCached('member_dash', '/member/dashboard', { force: opts.force });
    state.workspaceData.member = data;
    // Live-sync approval_status so newly Admin-approved Member/Orang Tua immediately unlocks!
    if (data.approval_status && state.user) {
      state.user.approval_status = data.approval_status;
      state.user.approval_notes = data.approval_notes ?? state.user.approval_notes;
      state.user.can_transact = data.approval_status === 'approved';
      syncPendingApprovalWatcher();
    }
    const mem = data.active_membership;
    const pendingMems = data.pending_memberships || [];
    const bookings = data.bookings || [];
    const notifications = data.notifications || [];

    if (tab === 'overview') {
      const nextB = bookings.find((b) => b.status === 'confirmed');
      const pct = mem ? Math.round((mem.remaining_quota / Math.max(1, mem.total_quota)) * 100) : 0;
      main.innerHTML = `
        ${renderApprovalBanner()}
        ${renderScheduleChangeNotifications(notifications)}
        <div class="panel-grid-2">
          <article class="card card-dark">
            <span class="pill pill-success">${mem ? 'MEMBERSHIP ACTIVE' : 'BELUM ADA MEMBERSHIP'}</span>
            <h3>${mem ? esc(mem.plan_name) : 'Aktifkan Membership'}</h3>
            <div class="ring-metric-row">
              ${makeRingChartSvg(pct, 'Sisa Kuota', true)}
              <div>
                <div class="price-tag">${mem ? `Sisa ${esc(mem.remaining_quota)} / ${esc(mem.total_quota)} Sesi` : 'Booking Per Sesi'}</div>
                <p>${mem ? `Berlaku hingga ${esc(formatDateTime(mem.expires_at))}` : 'Pilih paket bulanan atau paket 10 sesi untuk diajukan & diaktifkan oleh Admin.'}</p>
              </div>
            </div>
            <progress class="fit-progress is-high" value="${pct}" max="100" aria-label="Sisa kuota membership ${pct}%"></progress>
          </article>
          <article class="card">
            <h3>Tiket QR Code Kehadiran (Sekali Pakai)</h3>
            ${nextB && nextB.qr_code ? `
              <div class="qr-pass-box">
                ${makeQrSvg(nextB.qr_code)}
                <div>
                  <span class="pill pill-success">CONFIRMED</span>
                  <h4>${esc(nextB.class_name)}</h4>
                  <p>${esc(formatDateTime(nextB.starts_at))}<br/>Kode QR: <strong>${esc(nextB.qr_code)}</strong></p>
                </div>
              </div>` : '<p>Belum ada booking terkonfirmasi. Klik Katalog Kelas untuk memilih kelas & menunggu ACC Admin.</p>'}
          </article>
        </div>`;
      return;
    }

    if (tab === 'bookings') {
      main.innerHTML = `
        ${renderApprovalBanner()}
        ${renderScheduleChangeNotifications(notifications)}
        <article class="card">
          <div class="section-head">
            <h3>Daftar Booking (${bookings.length}), Reschedule (Maks H-8 Jam) &amp; Pembatalan</h3>
            <button type="button" class="btn btn-primary btn-sm" data-action="go-portal-section" data-target="#katalog-kelas">+ Pilih &amp; Booking Kelas Baru</button>
          </div>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Kode</th><th>Kelas &amp; Peserta</th><th>Status</th><th>Kode QR</th><th>Aksi</th></tr></thead>
              <tbody>
                ${bookings.length ? bookings
                  .map(
                    (b) => `
                    <tr>
                      <td>${esc(b.booking_code)}</td>
                      <td><strong>${esc(b.class_name)}</strong>${b.child_name ? ` <span class="pill pill-info">${esc(b.child_name)}</span>` : ''}<br/><small>${esc(formatDateTime(b.starts_at))}</small></td>
                      <td><span class="pill ${b.status === 'confirmed' || b.status === 'attended' ? 'pill-success' : b.status === 'cancelled' ? 'pill-danger' : 'pill-warning'}">${b.status === 'pending_payment' ? 'MENUNGGU ACC ADMIN' : esc(b.status)}</span></td>
                      <td><code>${esc(b.qr_code || '-')}</code></td>
                      <td>
                        ${b.status === 'confirmed' ? `
                          <button type="button" class="btn btn-outline btn-sm" data-action="open-reschedule" data-id="${esc(b.id)}" data-class="${esc(b.class_id)}">Reschedule</button>
                          <button type="button" class="btn btn-danger btn-sm" data-action="cancel-booking" data-id="${esc(b.id)}">Batalkan</button>` : ''}
                      </td>
                    </tr>`
                  )
                  .join('') : '<tr><td colspan="5">Belum ada kelas terdaftar. Klik <strong>+ Pilih &amp; Booking Kelas Baru</strong> atau menu <strong>Katalog Kelas</strong> di atas untuk langsung mendaftar.</td></tr>'}
              </tbody>
            </table>
          </div>
        </article>`;
      return;
    }

    if (tab === 'memberships') {
      const payments = data.payments || [];
      main.innerHTML = `
        ${renderApprovalBanner()}
        ${pendingMems.length ? `
          <div class="approval-banner">
            <div>
              <strong>${pendingMems.length} Pengajuan Paket Membership Menunggu Persetujuan Admin</strong>
              <div>${esc(pendingMems.map((pm) => `${pm.plan_name} (${formatRp(pm.price)})`).join(' · '))}. Setelah disetujui Admin, kuota sesi langsung aktif otomatis.</div>
            </div>
            <span class="pill pill-warning">MENUNGGU ACC ADMIN</span>
          </div>` : ''}
        <article class="card">
          <div class="section-head">
            <h3>Membership Aktif &amp; Riwayat Transaksi (${payments.length})</h3>
            <button type="button" class="btn btn-primary btn-sm" data-action="go-portal-section" data-target="#membership">Pilih Paket Membership</button>
          </div>
          ${mem ? `<div class="shield-banner"><div><strong>${esc(mem.plan_name)}</strong> — Sisa ${esc(mem.remaining_quota)} / ${esc(mem.total_quota)} sesi · Aktif s/d ${esc(formatDateTime(mem.expires_at))}</div><span class="pill pill-success">ACTIVE</span></div>` : ''}
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Order ID</th><th>Gateway</th><th>Nominal</th><th>Status</th></tr></thead>
              <tbody>
                ${payments.length ? payments.map((p) => `<tr><td>${esc(p.order_id)}</td><td>${esc(p.gateway)}</td><td>${esc(formatRp(p.amount))}</td><td><span class="pill ${p.status === 'paid' ? 'pill-success' : 'pill-warning'}">${p.status === 'pending' ? 'MENUNGGU ACC ADMIN' : esc(p.status)}</span></td></tr>`).join('') : '<tr><td colspan="4">Belum ada transaksi membership atau kelas. Klik <strong>Pilih Paket Membership</strong> di atas untuk memulai.</td></tr>'}
              </tbody>
            </table>
          </div>
        </article>`;
      return;
    }

    const reports = data.progress_reports || [];
    const memberReportTypes = [
      ['progress', 'Laporan Perkembangan Latihan Saya'],
      ['bookings', 'Laporan Riwayat Booking Kelas'],
      ['attendance', 'Laporan Kehadiran Studio (QR)'],
      ['memberships', 'Laporan Membership & Kuota'],
      ['reviews', 'Laporan Ulasan & Rating Coach'],
    ];
    main.innerHTML = `
      <article class="card">
        <div class="section-head">
          <h3>Laporan Perkembangan Latihan Saya (${reports.length})</h3>
          <div class="format-group">
            <button type="button" class="btn btn-dark btn-sm" data-action="export-report" data-type="progress" data-format="pdf">PDF</button>
            <button type="button" class="btn btn-success btn-sm" data-action="export-report" data-type="progress" data-format="excel">Excel</button>
            <button type="button" class="btn btn-word btn-sm" data-action="export-report" data-type="progress" data-format="word">Word</button>
          </div>
        </div>
        ${reports.length ? reports.map((r) => `
          <div class="card">
            <strong>${esc(r.class_name)} · Rata-rata ${esc(r.average_score)} / 4.0</strong>
            <p>${esc(r.progress_notes || '')}</p>
            <p><em>Rekomendasi: ${esc(r.next_recommendation || '-')}</em></p>
          </div>`).join('') : '<p>Belum ada evaluasi perkembangan dari Coach.</p>'}
      </article>
      <article class="card">
        <h3>Pusat Unduh Laporan Member (PDF, Excel &amp; Word)</h3>
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Jenis Laporan</th><th>PDF</th><th>Excel (.xlsx)</th><th>Word (.docx)</th></tr></thead>
            <tbody>
              ${memberReportTypes.map(([k, lbl]) => `
                <tr>
                  <td><strong>${esc(lbl)}</strong></td>
                  <td><button type="button" class="btn btn-dark btn-sm" data-action="export-report" data-type="${esc(k)}" data-format="pdf">PDF</button></td>
                  <td><button type="button" class="btn btn-success btn-sm" data-action="export-report" data-type="${esc(k)}" data-format="excel">Excel</button></td>
                  <td><button type="button" class="btn btn-word btn-sm" data-action="export-report" data-type="${esc(k)}" data-format="word">Word</button></td>
                </tr>`).join('')}
            </tbody>
          </table>
        </div>
      </article>`;
  }

  // --- GUARDIAN (ORANG TUA/WALI) WORKSPACE (Zero redundant fetches + Live Approval Sync) ---
  async function renderGuardianWorkspace(tab, main, opts = {}) {
    if (tab === 'issues') {
      await renderUserIssuesTab(main);
      return;
    }
    if (tab === 'bookings' || tab === 'memberships') {
      await renderMemberWorkspace(tab, main, opts);
      return;
    }

    const data = await apiCached('guardian_dash', '/guardian/dashboard', { force: opts.force });
    state.workspaceData.guardian = data;
    if (data.approval_status && state.user) {
      state.user.approval_status = data.approval_status;
      state.user.approval_notes = data.approval_notes ?? state.user.approval_notes;
      state.user.can_transact = data.approval_status === 'approved';
      syncPendingApprovalWatcher();
    }
    const kids = data.children || [];

    const shieldHtml = `
      ${renderApprovalBanner()}
      ${renderScheduleChangeNotifications(data.notifications || [])}
      <div class="shield-banner">
        <div>
          <strong>Pemantauan Profil &amp; Latihan Anak</strong>
          <div>Data profil, kehadiran, dan laporan perkembangan anak terhubung eksklusif ke akun Orang Tua/Wali Anda.</div>
        </div>
        <span class="pill pill-success">TERVERIFIKASI</span>
      </div>`;

    if (tab === 'overview') {
      main.innerHTML = `
        ${shieldHtml}
        <article class="card">
          <h3>Profil Anak Terdaftar (${kids.length})</h3>
          <div class="cards-grid">
            ${kids
              .map(
                (k) => `
                <div class="card">
                  <span class="pill pill-info">USIA ${esc(k.age)} TAHUN</span>
                  <h3>${esc(k.full_name)}</h3>
                  <p>Tanggal Lahir: ${esc(k.birth_date)} · Catatan Medis: ${esc(k.medical_notes || 'Tidak ada')}</p>
                  <div class="format-group">
                    <button type="button" class="btn btn-dark btn-sm" data-action="download-child-pdf" data-id="${esc(k.id)}">Unduh PDF</button>
                    <button type="button" class="btn btn-word btn-sm" data-action="download-child-word" data-id="${esc(k.id)}">Unduh Word</button>
                  </div>
                </div>`
              )
              .join('')}
          </div>
        </article>
        <article class="card">
          <h3>Daftarkan Anak Baru</h3>
          <form id="guardian-child-form" class="form-grid">
            <div class="field"><label for="ch-name">Nama Lengkap Anak</label><input id="ch-name" name="full_name" required placeholder="Nama anak" /></div>
            <div class="field"><label for="ch-dob">Tanggal Lahir</label><input id="ch-dob" name="birth_date" type="date" required value="2018-05-12" /></div>
            <div class="field"><label for="ch-gender">Jenis Kelamin</label><select id="ch-gender" name="gender"><option value="Perempuan">Perempuan</option><option value="Laki-laki">Laki-laki</option></select></div>
            <div class="field"><label for="ch-med">Catatan Medis (Opsional)</label><input id="ch-med" name="medical_notes" placeholder="Alergi / catatan khusus" /></div>
            <div class="field"><label>&nbsp;</label><button type="submit" class="btn btn-primary">Tambah Profil Anak</button></div>
          </form>
        </article>`;
      return;
    }

    if (tab === 'progress') {
      const reports = data.progress_reports || [];
      const guardianReportTypes = [
        ['progress', 'Rekap Perkembangan Semua Anak'],
        ['bookings', 'Laporan Riwayat Booking Anak'],
        ['attendance', 'Laporan Kehadiran Anak (QR)'],
        ['memberships', 'Laporan Membership & Kuota Wali'],
        ['reviews', 'Laporan Ulasan Coach'],
      ];
      main.innerHTML = `
        ${shieldHtml}
        <article class="card">
          <h3>Perkembangan Anak &amp; Unduh Rapor PDF / Word (${reports.length})</h3>
          ${reports.length ? reports.map((r) => `
            <div class="card">
              <div class="section-head">
                <div>
                  <strong>${esc(r.child_name)} — ${esc(r.class_name)}</strong>
                  <p>Evaluasi: ${esc(r.evaluated_at)} · Rata-rata ${esc(r.average_score)} / 4.0</p>
                </div>
                <div class="format-group">
                  <button type="button" class="btn btn-dark btn-sm" data-action="download-child-pdf" data-id="${esc(r.child_id)}">PDF</button>
                  <button type="button" class="btn btn-word btn-sm" data-action="download-child-word" data-id="${esc(r.child_id)}">Word</button>
                </div>
              </div>
              ${(r.details || [])
                .map(
                  (d) => `
                  <div>
                    <small><strong>${esc(d.indicator_name)}</strong> — ${esc(d.score)}/4 (${esc(d.score_label)})</small>
                    <progress class="fit-progress ${d.score >= 4 ? 'is-high' : ''}" value="${d.score * 25}" max="100" aria-label="Skor ${esc(d.indicator_name)} ${esc(d.score)} dari 4"></progress>
                  </div>`
                )
                .join('')}
              <p>${esc(r.progress_notes || '')}</p>
            </div>`).join('') : '<p>Belum ada catatan evaluasi anak.</p>'}
        </article>
        <article class="card">
          <h3>Pusat Unduh Laporan Orang Tua / Wali (PDF, Excel &amp; Word)</h3>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Jenis Laporan</th><th>PDF</th><th>Excel (.xlsx)</th><th>Word (.docx)</th></tr></thead>
              <tbody>
                ${guardianReportTypes.map(([k, lbl]) => `
                  <tr>
                    <td><strong>${esc(lbl)}</strong></td>
                    <td><button type="button" class="btn btn-dark btn-sm" data-action="export-report" data-type="${esc(k)}" data-format="pdf">PDF</button></td>
                    <td><button type="button" class="btn btn-success btn-sm" data-action="export-report" data-type="${esc(k)}" data-format="excel">Excel</button></td>
                    <td><button type="button" class="btn btn-word btn-sm" data-action="export-report" data-type="${esc(k)}" data-format="word">Word</button></td>
                  </tr>`).join('')}
              </tbody>
            </table>
          </div>
        </article>`;
      return;
    }

    await renderMemberWorkspace(tab, main);
  }

  // ---------- Multi-Hardware & Smart Glasses Optical HUD ----------
  function getHudCards() {
    const base = state.schedules.slice(0, 4).map((s, idx) => ({
      title: `${s.class_name} (${formatDateTime(s.starts_at)})`,
      body: `Coach ${s.coach_name} · ${s.room_name} (${s.branch_name}) · Peserta ${s.booked_count}/${s.capacity}. Aktivitas aktif: ${state.activity}.`,
      meta: `OPTICAL HUD ${idx + 1} / ${Math.min(4, Math.max(1, state.schedules.length))} · FITSPACE`,
    }));
    if (base.length) return base;
    return [
      {
        title: 'FitSpace Optical HUD Siap',
        body: 'Mode Kacamata Pintar dengan latar True Optical Black (#000000), kontras WCAG AAA > 14:1, navigasi D-Pad, dan perintah suara.',
        meta: 'OPTICAL HUD 1 / 1 · FITSPACE',
      },
    ];
  }

  function renderHudCard() {
    const cards = getHudCards();
    const item = cards[state.hudIndex % cards.length];
    $('hud-counter').textContent = item.meta;
    $('hud-card-title').textContent = item.title;
    $('hud-card-body').textContent = item.body;
  }

  function setHardwareMode(mode) {
    state.hardware = mode || 'auto';
    if (state.hardware === 'auto') {
      document.documentElement.removeAttribute('data-hardware');
      $('hud-overlay').classList.remove('is-active');
      $('hud-overlay').setAttribute('aria-hidden', 'true');
      return;
    }
    document.documentElement.setAttribute('data-hardware', state.hardware);
    const isGlasses = state.hardware === 'smart-glasses';
    $('hud-overlay').classList.toggle('is-active', isGlasses);
    $('hud-overlay').setAttribute('aria-hidden', String(!isGlasses));
    if (isGlasses) {
      state.hudIndex = 0;
      renderHudCard();
      $('hud-next-btn')?.focus();
    }
  }

  function applyActivity(id) {
    state.activity = ACTIVITY_META[id] ? id : 'portal';
    const meta = ACTIVITY_META[state.activity];
    if ($('active-activity-badge')) $('active-activity-badge').textContent = meta.badge;
    if ($('hero-primary-cta')) $('hero-primary-cta').textContent = meta.cta;
    if ($('activity-summary-label')) $('activity-summary-label').textContent = meta.summary;
    if (state.activity === 'handsfree-hud') {
      if ($('hardware-select')) $('hardware-select').value = 'smart-glasses';
      setHardwareMode('smart-glasses');
    } else if (state.activity === 'kiosk-display') {
      if ($('hardware-select')) $('hardware-select').value = 'tv';
      setHardwareMode('tv');
    }
    renderHudCard();
  }

  function speakHud() {
    if (!('speechSynthesis' in window)) {
      $('hud-voice-status').textContent = 'Browser ini tidak mendukung Text-to-Speech.';
      return;
    }
    window.speechSynthesis.cancel();
    const text = `${$('hud-card-title').textContent}. ${$('hud-card-body').textContent}`;
    const utter = new SpeechSynthesisUtterance(text);
    utter.lang = 'id-ID';
    window.speechSynthesis.speak(utter);
    $('hud-voice-status').textContent = 'Membacakan kartu aktif (TTS)...';
  }

  function startVoiceCommand() {
    const Rec = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!Rec) {
      $('hud-voice-status').textContent = 'Perintah suara tidak didukung browser ini; gunakan tombol Panah Kiri/Kanan atau klik.';
      return;
    }
    const rec = new Rec();
    rec.lang = 'id-ID';
    rec.interimResults = false;
    $('hud-voice-status').textContent = 'Mendengarkan perintah: "berikutnya", "sebelumnya", "bacakan", atau "tutup"...';
    rec.onresult = (ev) => {
      const cmd = (ev.results?.[0]?.[0]?.transcript || '').toLowerCase();
      if (cmd.includes('berikut') || cmd.includes('lanjut')) $('hud-next-btn').click();
      else if (cmd.includes('sebelum') || cmd.includes('kembali')) $('hud-prev-btn').click();
      else if (cmd.includes('baca')) speakHud();
      else if (cmd.includes('tutup') || cmd.includes('keluar')) $('hud-exit-btn').click();
      else $('hud-voice-status').textContent = `Perintah diterima: "${cmd}"`;
    };
    rec.onerror = () => {
      $('hud-voice-status').textContent = 'Mikrofon tidak tersedia atau izin ditolak. Gunakan navigasi keyboard.';
    };
    rec.start();
  }

  // ---------- Event Delegation ----------
  document.addEventListener('click', async (ev) => {
    const target = ev.target.closest('[data-action]');
    if (!target) return;
    const action = target.dataset.action;
    const busyActions = new Set([
      'buy-membership', 'guest-login', 'admin-decide-coach', 'admin-decide-user',
      'admin-decide-avail', 'admin-approve-booking', 'admin-approve-membership',
      'admin-decide-reschedule', 'coach-toggle-publish', 'cancel-booking', 'admin-refund',
    ]);
    const markBusy = busyActions.has(action);
    if (markBusy) {
      if (target.disabled) return;
      target.disabled = true;
      target.classList.add('is-busy');
    }

    try {
      if (action === 'go-portal-section') {
        setView('portal', target.dataset.target || '#katalog-kelas');
      } else if (action === 'filter-cat') {
        state.selectedCategoryId = target.dataset.id || '';
        renderCategoryFilters();
        renderPublicClasses();
      } else if (action === 'view-class-detail') {
        showClassDetailModal(target.dataset.id);
      } else if (action === 'quick-book-class') {
        const sched = state.schedules.find((s) => String(s.class_id) === String(target.dataset.id));
        if (sched) await showBookScheduleModal(sched.id);
        else showClassDetailModal(target.dataset.id);
      } else if (action === 'open-book-schedule') {
        await showBookScheduleModal(target.dataset.id);
      } else if (action === 'buy-membership') {
        if (!state.user) {
          showToast('Silakan masuk terlebih dahulu untuk membeli membership.');
          showLoginModal();
          return;
        }
        if (state.user.is_guest || state.user.role === 'guest') {
          showGuestUpgradeModal('Pemilihan & Aktivasi Paket Membership');
          return;
        }
        if (state.user.role !== 'admin' && state.user.approval_status !== 'approved') {
          await syncAuthStatus(true);
        }
        const res = await api('/memberships/purchase', {
          method: 'POST',
          body: JSON.stringify({ plan_id: Number(target.dataset.id), gateway: 'midtrans', auto_confirm_demo: false }),
        });
        clearApiCache();
        showToast(res.message || 'Paket membership berhasil diproses!');
        state.activeTab = 'memberships';
        setView('workspace');
      } else if (action === 'toggle-password') {
        const input = $(target.dataset.target);
        if (input) {
          const showing = input.type === 'text';
          input.type = showing ? 'password' : 'text';
          target.setAttribute('aria-pressed', String(!showing));
          target.setAttribute('aria-label', showing ? 'Tampilkan kata sandi' : 'Sembunyikan kata sandi');
          target.innerHTML = showing ? EYE_OPEN_SVG : EYE_OFF_SVG;
        }
      } else if (action === 'switch-login-mode') {
        showLoginModal(target.dataset.mode === 'admin' ? 'admin' : 'user');
      } else if (action === 'open-admin-login') {
        showLoginModal('admin');
      } else if (action === 'open-register-role') {
        showRegisterModal(target.dataset.role || 'member');
      } else if (action === 'google-login') {
        if (!state.googleOauthEnabled) {
          openModal(
            'Masuk dengan Akun Google',
            `
            <div class="auth-stack">
              <p>Integrasi <strong>Google OAuth 2.0</strong> sudah siap aktif pada server FitSpace. Untuk mengaktifkannya di lingkungan ini, masukkan <code>GOOGLE_CLIENT_ID</code> dan <code>GOOGLE_CLIENT_SECRET</code> dari Google Cloud Console ke file <code>.env</code>.</p>
              <p>Sementara itu, Anda dapat menjelajah dengan <strong>Akun Tamu (terbatas)</strong> atau mendaftar sebagai Member (18+) / Orang Tua:</p>
              <button type="button" class="btn btn-primary btn-block" data-action="open-register-role" data-role="member">Daftar Member (18+) dengan Email</button>
              <button type="button" class="btn btn-outline btn-block" data-action="guest-login">Masuk sebagai Akun Tamu (Terbatas)</button>
            </div>`
          );
          return;
        }
        window.location.href = `${API_BASE}/auth/google/redirect`;
      } else if (action === 'guest-login') {
        const res = await api('/auth/guest', { method: 'POST' });
        state.token = res.token;
        sessionStorage.setItem(STORAGE_TOKEN_KEY, res.token);
        state.user = res.user;
        updateAuthNav();
        closeModal();
        showToast('Masuk sebagai Akun Tamu (akses terbatas). Login Member/Orang Tua untuk fitur penuh.');
        state.activeTab = 'overview';
        setView('workspace');
      } else if (action === 'open-register') {
        showRegisterModal();
      } else if (action === 'open-login') {
        showLoginModal('user');
      } else if (action === 'ws-tab') {
        await loadWorkspaceTab(target.dataset.tab);
      } else if (action === 'admin-decide-coach') {
        const res = await api(`/admin/coaches/${encodeURIComponent(target.dataset.id)}/decision`, {
          method: 'POST',
          body: JSON.stringify({ decision: target.dataset.decision }),
        });
        clearApiCache('admin_');
        showToast(res.message || 'Status Coach diperbarui.');
        await loadWorkspaceTab('approvals', { force: true });
      } else if (action === 'admin-decide-user') {
        const res = await api(`/admin/users/${encodeURIComponent(target.dataset.id)}/decision`, {
          method: 'POST',
          body: JSON.stringify({ decision: target.dataset.decision }),
        });
        clearApiCache('admin_');
        showToast(res.message || 'Status akun diperbarui.');
        await loadWorkspaceTab('approvals', { force: true });
      } else if (action === 'admin-decide-avail') {
        const res = await api(`/admin/availabilities/${encodeURIComponent(target.dataset.id)}/decision`, {
          method: 'POST',
          body: JSON.stringify({ decision: target.dataset.decision }),
        });
        clearApiCache('admin_');
        showToast(res.message || 'Status sinkronisasi jadwal diperbarui.');
        await loadWorkspaceTab('schedules', { force: true });
      } else if (action === 'admin-approve-booking') {
        const res = await api(`/admin/bookings/${encodeURIComponent(target.dataset.id)}/approve`, {
          method: 'POST',
        });
        clearApiCache();
        showToast(res.message || 'Pemilihan kelas disetujui Admin & QR diterbitkan!');
        loadPublicBootstrap().catch(() => {});
        await loadWorkspaceTab('approvals', { force: true });
      } else if (action === 'admin-approve-membership') {
        const res = await api(`/admin/memberships/${encodeURIComponent(target.dataset.id)}/approve`, {
          method: 'POST',
        });
        clearApiCache();
        showToast(res.message || 'Paket membership disetujui & diaktifkan!');
        await loadWorkspaceTab('approvals', { force: true });
      } else if (action === 'admin-decide-reschedule') {
        const res = await api(`/admin/schedule-reschedules/${encodeURIComponent(target.dataset.id)}/decision`, {
          method: 'POST',
          body: JSON.stringify({ decision: target.dataset.decision }),
        });
        clearApiCache();
        showToast(res.message || 'Keputusan reschedule Coach disimpan & diteruskan ke peserta.');
        loadPublicBootstrap().catch(() => {});
        await loadWorkspaceTab('schedules', { force: true });
      } else if (action === 'coach-quick-reschedule') {
        const schedId = target.dataset.id;
        await loadWorkspaceTab('reschedule', { force: true });
        const sel = $('cr-sched');
        if (sel && schedId) sel.value = schedId;
        $('cr-start')?.focus();
      } else if (action === 'open-resolve-issue') {
        openModal(
          `Tangani Tiket ${target.dataset.code || ''}`,
          `
          <form id="admin-resolve-issue-form" class="form-grid" data-issue-id="${esc(target.dataset.id)}">
            <div class="field">
              <label for="res-status">Status Penanganan</label>
              <select id="res-status" name="status">
                <option value="resolved">Selesaikan Masalah (Resolved)</option>
                <option value="in_progress">Sedang Ditangani (In Progress)</option>
              </select>
            </div>
            <div class="field">
              <label for="res-notes">Catatan Solusi Admin</label>
              <input id="res-notes" name="resolution_notes" required placeholder="Jelaskan tindakan penyelesaian yang sudah dilakukan" />
            </div>
            <div class="field"><button type="submit" class="btn btn-primary btn-block">Simpan Keputusan Admin</button></div>
          </form>`
        );
      } else if (action === 'coach-toggle-publish') {
        const res = await api(`/coach/evaluations/${encodeURIComponent(target.dataset.id)}/publish`, {
          method: 'PATCH',
          body: JSON.stringify({ is_published: target.dataset.pub === '1' }),
        });
        clearApiCache('coach_');
        showToast(res.message || 'Visibilitas nilai diperbarui.');
        await loadWorkspaceTab('evaluations', { force: true });
      } else if (action === 'export-report') {
        const type = target.dataset.type;
        const fmt = target.dataset.format;
        const ext = fmt === 'excel' ? 'xlsx' : fmt === 'word' ? 'docx' : 'pdf';
        await downloadAuthenticatedFile(`/reports/export?type=${encodeURIComponent(type)}&format=${encodeURIComponent(fmt)}`, `fitspace-${type}.${ext}`);
      } else if (action === 'download-child-pdf') {
        await downloadAuthenticatedFile(`/guardian/children/${encodeURIComponent(target.dataset.id)}/report-pdf`, `fitspace-rapor-anak-${target.dataset.id}.pdf`);
      } else if (action === 'download-child-word') {
        await downloadAuthenticatedFile(`/guardian/children/${encodeURIComponent(target.dataset.id)}/report-word`, `fitspace-rapor-anak-${target.dataset.id}.docx`);
      } else if (action === 'open-reschedule') {
        const bId = target.dataset.id;
        const clsId = target.dataset.class;
        const alts = state.schedules.filter((s) => String(s.class_id) === String(clsId));
        openModal(
          'Reschedule Booking (Kebijakan Maks H-8 Jam)',
          `
          <form id="reschedule-form" class="form-grid" data-booking-id="${esc(bId)}">
            <div class="field">
              <label for="resch-sched">Pilih Jadwal Pengganti</label>
              <select id="resch-sched" name="new_schedule_id" required>
                ${alts.map((s) => `<option value="${esc(s.id)}">${esc(formatDateTime(s.starts_at))} · ${esc(s.room_name)}</option>`).join('')}
              </select>
            </div>
            <div class="field"><label for="resch-reason">Alasan</label><input id="resch-reason" name="reason" required value="Penyesuaian jadwal pribadi" /></div>
            <div class="field"><button type="submit" class="btn btn-primary btn-block">Ajukan Reschedule</button></div>
          </form>`
        );
      } else if (action === 'cancel-booking') {
        const res = await api(`/bookings/${encodeURIComponent(target.dataset.id)}/cancel`, {
          method: 'POST',
          body: JSON.stringify({ reason: 'Dibatalkan oleh peserta' }),
        });
        clearApiCache();
        showToast(res.message || 'Booking dibatalkan.');
        loadPublicBootstrap().catch(() => {});
        await loadWorkspaceTab('bookings', { force: true });
      } else if (action === 'admin-refund') {
        const res = await api(`/admin/payments/${encodeURIComponent(target.dataset.id)}/refund`, {
          method: 'POST',
          body: JSON.stringify({ reason: 'Refund disetujui Admin sesuai kebijakan pembatalan' }),
        });
        clearApiCache('admin_');
        showToast(res.message || 'Refund berhasil dicatat.');
        await loadWorkspaceTab('bookings', { force: true });
      }
    } catch (err) {
      showToast(err.message, true);
    } finally {
      if (markBusy) {
        target.disabled = false;
        target.classList.remove('is-busy');
      }
    }
  });

  document.addEventListener('submit', async (ev) => {
    const form = ev.target;
    if (!form || !form.id) return;
    ev.preventDefault();
    const submitBtn = form.querySelector('button[type="submit"]');
    const origBtnText = submitBtn ? submitBtn.textContent : '';
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.classList.add('is-busy');
      submitBtn.textContent = 'Memproses...';
    }
    const fd = new FormData(form);
    const payload = Object.fromEntries(fd.entries());

    try {
      if (form.id === 'login-form') {
        const res = await api('/auth/login', { method: 'POST', body: JSON.stringify(payload) });
        clearApiCache();
        state.token = res.token;
        sessionStorage.setItem(STORAGE_TOKEN_KEY, res.token);
        state.user = res.user;
        updateAuthNav();
        syncPendingApprovalWatcher();
        closeModal();
        showToast(`Selamat datang, ${res.user.name}!`);
        state.activeTab = 'overview';
        setView('workspace');
      } else if (form.id === 'admin-login-form') {
        const res = await api('/auth/admin-login', { method: 'POST', body: JSON.stringify(payload) });
        clearApiCache();
        state.token = res.token;
        sessionStorage.setItem(STORAGE_TOKEN_KEY, res.token);
        state.user = res.user;
        updateAuthNav();
        closeModal();
        showToast(`Portal Admin FitSpace aktif — ${res.user.name}`);
        state.activeTab = 'overview';
        setView('workspace');
      } else if (form.id === 'register-form') {
        const res = await api('/auth/register', { method: 'POST', body: JSON.stringify(payload) });
        clearApiCache();
        closeModal();
        if (res.token) {
          state.token = res.token;
          sessionStorage.setItem(STORAGE_TOKEN_KEY, res.token);
          state.user = res.user;
          updateAuthNav();
          syncPendingApprovalWatcher();
          state.activeTab = 'overview';
          setView('workspace');
        }
        showToast(res.message || 'Registrasi berhasil! Menunggu persetujuan.');
      } else if (form.id === 'admin-resolve-issue-form') {
        const res = await api(`/admin/issues/${encodeURIComponent(form.dataset.issueId)}/resolve`, {
          method: 'POST',
          body: JSON.stringify(payload),
        });
        clearApiCache('admin_');
        closeModal();
        showToast(res.message || 'Tiket masalah berhasil diperbarui.');
        await loadWorkspaceTab('issues', { force: true });
      } else if (form.id === 'user-issue-form') {
        const res = await api('/issues', { method: 'POST', body: JSON.stringify(payload) });
        showToast(res.message || 'Laporan masalah dikirim ke Admin.');
        await loadWorkspaceTab('issues', { force: true });
      } else if (form.id === 'booking-form') {
        // Seamless 1-step Quick Child creation if Guardian hasn't registered a child yet
        if (!payload.child_id && payload.quick_child_name && payload.quick_child_birth_date) {
          const chRes = await api('/guardian/children', {
            method: 'POST',
            body: JSON.stringify({
              full_name: payload.quick_child_name,
              birth_date: payload.quick_child_birth_date,
            }),
          });
          payload.child_id = chRes.child?.id;
          delete payload.quick_child_name;
          delete payload.quick_child_birth_date;
        }
        payload.schedule_id = Number(form.dataset.scheduleId);
        if (payload.child_id) payload.child_id = Number(payload.child_id);
        payload.auto_confirm_demo = false;
        const res = await api('/bookings', { method: 'POST', body: JSON.stringify(payload) });
        clearApiCache();
        closeModal();
        showToast(res.message || 'Booking berhasil terdaftar!');
        state.activeTab = 'bookings';
        setView('workspace');
        loadPublicBootstrap().catch(() => {});
      } else if (form.id === 'reschedule-form') {
        payload.new_schedule_id = Number(payload.new_schedule_id);
        const res = await api(`/bookings/${encodeURIComponent(form.dataset.bookingId)}/reschedule`, {
          method: 'POST',
          body: JSON.stringify(payload),
        });
        clearApiCache();
        closeModal();
        showToast(res.message || 'Reschedule berhasil!');
        await loadWorkspaceTab('bookings', { force: true });
        loadPublicBootstrap().catch(() => {});
      } else if (form.id === 'admin-category-form') {
        await api('/admin/categories', { method: 'POST', body: JSON.stringify(payload) });
        clearApiCache('admin_');
        showToast('Kategori olahraga baru berhasil ditambahkan!');
        loadPublicBootstrap().catch(() => {});
        await loadWorkspaceTab('classes', { force: true });
      } else if (form.id === 'admin-class-form') {
        payload.indicators = String(payload.indicators || '').split(',').map((s) => s.trim()).filter(Boolean);
        await api('/admin/classes', { method: 'POST', body: JSON.stringify(payload) });
        clearApiCache('admin_');
        showToast('Kelas dinamis baru berhasil disimpan!');
        loadPublicBootstrap().catch(() => {});
        await loadWorkspaceTab('classes', { force: true });
      } else if (form.id === 'admin-schedule-form') {
        await api('/admin/schedules', { method: 'POST', body: JSON.stringify(payload) });
        clearApiCache('admin_');
        showToast('Jadwal kelas berhasil dibuat (0 bentrok)!');
        loadPublicBootstrap().catch(() => {});
        await loadWorkspaceTab('schedules', { force: true });
      } else if (form.id === 'admin-expense-form') {
        await api('/admin/expenses', { method: 'POST', body: JSON.stringify(payload) });
        clearApiCache('admin_');
        showToast('Pengeluaran operasional dicatat.');
        await loadWorkspaceTab('finance', { force: true });
      } else if (form.id === 'coach-qr-form') {
        const res = await api('/attendance/scan-qr', { method: 'POST', body: JSON.stringify(payload) });
        clearApiCache('coach_');
        showToast(res.message || 'Kehadiran QR berhasil dicatat!');
        form.reset();
      } else if (form.id === 'coach-material-form') {
        await api('/coach/materials', { method: 'POST', body: JSON.stringify(payload) });
        showToast('Materi latihan berhasil ditambahkan.');
        loadPublicBootstrap().catch(() => {});
        form.reset();
      } else if (form.id === 'coach-eval-form') {
        payload.is_published = String(payload.is_published ?? '1') === '1';
        const res = await api('/coach/evaluations', { method: 'POST', body: JSON.stringify(payload) });
        clearApiCache('coach_');
        showToast(res.message || 'Evaluasi perkembangan tersimpan!');
        await loadWorkspaceTab('evaluations', { force: true });
      } else if (form.id === 'coach-reschedule-form') {
        payload.schedule_id = Number(payload.schedule_id);
        const res = await api('/coach/schedule-reschedules', { method: 'POST', body: JSON.stringify(payload) });
        clearApiCache('coach_');
        showToast(res.message || 'Ajuan reschedule jadwal dikirim ke Admin.');
        await loadWorkspaceTab('reschedule', { force: true });
      } else if (form.id === 'coach-avail-form') {
        await api('/coach/availabilities', { method: 'POST', body: JSON.stringify(payload) });
        clearApiCache('coach_');
        showToast('Pengajuan ketersediaan rutin dikirim ke Admin.');
        await loadWorkspaceTab('reschedule', { force: true });
      } else if (form.id === 'guardian-child-form') {
        await api('/guardian/children', { method: 'POST', body: JSON.stringify(payload) });
        clearApiCache('guardian_');
        showToast('Profil anak berhasil didaftarkan secara aman.');
        await loadWorkspaceTab('overview', { force: true });
      }
    } catch (err) {
      showToast(err.message, true);
    } finally {
      if (submitBtn && submitBtn.isConnected) {
        submitBtn.disabled = false;
        submitBtn.classList.remove('is-busy');
        submitBtn.textContent = origBtnText;
      }
    }
  });

  // ---------- Controls & Keyboard Shortcuts ----------
  $('nav-toggle-btn')?.addEventListener('click', () => {
    const nav = $('primary-navigation');
    const expanded = $('nav-toggle-btn').getAttribute('aria-expanded') === 'true';
    $('nav-toggle-btn').setAttribute('aria-expanded', String(!expanded));
    nav.classList.toggle('is-open', !expanded);
  });

  $('activity-select')?.addEventListener('change', (e) => applyActivity(e.target.value));
  $('hardware-select')?.addEventListener('change', (e) => setHardwareMode(e.target.value));
  $('branch-filter')?.addEventListener('change', (e) => {
    state.selectedBranchId = e.target.value;
    loadPublicBootstrap().catch(() => {});
  });

  $('toggle-hud-btn')?.addEventListener('click', () => {
    const next = state.hardware === 'smart-glasses' ? 'auto' : 'smart-glasses';
    if ($('hardware-select')) $('hardware-select').value = next;
    setHardwareMode(next);
  });

  $('hud-prev-btn')?.addEventListener('click', () => {
    const len = getHudCards().length;
    state.hudIndex = (state.hudIndex - 1 + len) % len;
    renderHudCard();
  });
  $('hud-next-btn')?.addEventListener('click', () => {
    state.hudIndex = (state.hudIndex + 1) % getHudCards().length;
    renderHudCard();
  });
  $('hud-speak-btn')?.addEventListener('click', speakHud);
  $('hud-voice-btn')?.addEventListener('click', startVoiceCommand);
  $('hud-exit-btn')?.addEventListener('click', () => {
    if ($('hardware-select')) $('hardware-select').value = 'auto';
    setHardwareMode('auto');
  });

  $('open-login-btn')?.addEventListener('click', () => showLoginModal('user'));
  $('open-register-btn')?.addEventListener('click', () => showRegisterModal('member'));
  document.addEventListener('change', (e) => {
    if (e.target && e.target.id === 'reg-role') syncRegisterRoleFields();
  });
  $('close-modal-btn')?.addEventListener('click', closeModal);
  $('app-modal')?.addEventListener('click', (e) => {
    if (e.target === $('app-modal')) closeModal();
  });

  // Direct 1-click navigation to Katalog Kelas / Jadwal / Membership from ANY Role Dashboard!
  document.querySelectorAll('a[data-nav="portal"], .footer-list a[href^="#"]').forEach((link) => {
    link.addEventListener('click', (e) => {
      const href = link.getAttribute('href') || '';
      if (!href.startsWith('#')) return;
      e.preventDefault();
      setView('portal', href);
    });
  });

  $('switch-view-btn')?.addEventListener('click', () => setView(state.activeView === 'workspace' ? 'portal' : 'workspace'));
  $('nav-workspace-link')?.addEventListener('click', (e) => {
    e.preventDefault();
    setView('workspace');
  });
  $('brand-home-link')?.addEventListener('click', (e) => {
    e.preventDefault();
    setView('portal');
  });

  // Auto-sync approval status & workspace data immediately when returning to tab (e.g. right after Admin ACC!)
  window.addEventListener('focus', () => {
    syncAuthStatus(false);
    if (state.user && state.activeView === 'workspace') {
      clearApiCache();
      loadWorkspaceTab(state.activeTab, { force: true });
    }
  });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      syncAuthStatus(false);
      if (state.user && state.activeView === 'workspace') {
        clearApiCache();
        loadWorkspaceTab(state.activeTab, { force: true });
      }
    }
  });

  $('logout-btn')?.addEventListener('click', async () => {
    if (state.token) {
      try {
        await api('/auth/logout', { method: 'POST' });
      } catch {}
    }
    clearApiCache();
    state.token = '';
    state.user = null;
    sessionStorage.removeItem(STORAGE_TOKEN_KEY);
    syncPendingApprovalWatcher();
    updateAuthNav();
    setView('portal');
    showToast('Anda telah keluar dengan aman.');
  });

  document.addEventListener('keydown', (e) => {
    const tag = e.target?.tagName;
    const typing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
    if (e.key === 'Escape') {
      closeModal();
      if (state.hardware === 'smart-glasses') {
        if ($('hardware-select')) $('hardware-select').value = 'auto';
        setHardwareMode('auto');
      }
      $('primary-navigation')?.classList.remove('is-open');
    } else if (!typing && (e.key === 'g' || e.key === 'G')) {
      const next = state.hardware === 'smart-glasses' ? 'auto' : 'smart-glasses';
      setHardwareMode(next);
    } else if (state.hardware === 'smart-glasses') {
      if (e.key === 'ArrowRight') $('hud-next-btn')?.click();
      if (e.key === 'ArrowLeft') $('hud-prev-btn')?.click();
    }
  });

  // PWA Install Prompt & Online/Offline Indicator
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    state.deferredInstallPrompt = e;
    $('hero-install-btn')?.classList.remove('is-hidden');
  });
  $('hero-install-btn')?.addEventListener('click', async () => {
    if (!state.deferredInstallPrompt) return;
    state.deferredInstallPrompt.prompt();
    await state.deferredInstallPrompt.userChoice;
    state.deferredInstallPrompt = null;
    $('hero-install-btn')?.classList.add('is-hidden');
  });

  function updateOnlineBadge() {
    const pill = $('pwa-status-pill');
    if (!pill) return;
    if (navigator.onLine) {
      pill.textContent = 'PWA Online';
      pill.className = 'pill pill-success';
    } else {
      pill.textContent = 'Mode Offline (Cache Publik)';
      pill.className = 'pill pill-warning';
    }
  }
  window.addEventListener('online', updateOnlineBadge);
  window.addEventListener('offline', updateOnlineBadge);

  // ---------- Init ----------
  async function init() {
    updateOnlineBadge();
    const params = new URLSearchParams(window.location.search);
    if (params.get('activity')) {
      if ($('activity-select')) $('activity-select').value = params.get('activity');
      applyActivity(params.get('activity'));
    }
    if (params.get('device')) {
      if ($('hardware-select')) $('hardware-select').value = params.get('device');
      setHardwareMode(params.get('device'));
    } else if (params.get('hud') === '1') {
      if ($('hardware-select')) $('hardware-select').value = 'smart-glasses';
      setHardwareMode('smart-glasses');
    }

    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('/sw.js').catch(() => {});
    }

    // Complete Google OAuth 2.0 one-time exchange code securely if present
    const oauthCode = params.get('oauth_code');
    if (oauthCode) {
      window.history.replaceState({}, document.title, window.location.pathname);
      try {
        const ex = await api('/auth/oauth-exchange', {
          method: 'POST',
          body: JSON.stringify({ code: oauthCode }),
        });
        state.token = ex.token;
        sessionStorage.setItem(STORAGE_TOKEN_KEY, ex.token);
        state.user = ex.user;
        updateAuthNav();
        showToast(`Selamat datang, ${ex.user.name}!`);
        state.activeTab = 'overview';
        setView('workspace');
      } catch (err) {
        showToast(err.message || 'Autentikasi Google gagal.', true);
      }
    } else if (params.get('google_auth')) {
      const reason = params.get('google_auth');
      window.history.replaceState({}, document.title, window.location.pathname);
      if (reason === 'unconfigured') {
        showToast('Google OAuth menunggu GOOGLE_CLIENT_ID di .env. Anda dapat masuk dengan email atau Akun Tamu.');
      } else {
        showToast('Autentikasi Google dibatalkan atau gagal. Silakan coba kembali.', true);
      }
    }

    await Promise.all([loadPublicBootstrap().catch(() => {}), restoreSession()]);
  }

  init();
})();
