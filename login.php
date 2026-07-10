<?php
// login.php
// หน้าเข้าสู่ระบบด้วยบัญชี LINE (LINE Login OAuth 2.1) สำหรับเข้าใช้งาน Dashboard
require_once 'config.php';
require_once 'auth.php';
require_once 'brand_mark.php';
require_once 'branch_config.php';

$default_nav_items = branch_nav_items(['sales_branch' => 'real_estate']);
$structure_demo = branch_intro_preview_data('real_estate');
$structure_preview_url = defined('INTRO_STRUCTURE_PREVIEW_URL') ? trim(INTRO_STRUCTURE_PREVIEW_URL) : '';
$initial_slide = isset($_GET['slide']) ? max(0, min(5, (int)$_GET['slide'])) : 0;

// ถ้าล็อกอินอยู่แล้ว — ไป register ถ้ายังไม่ครบ ไม่งั้น dashboard
if (auth_is_logged_in()) {
    auth_redirect_after_login($conn);
}

$config_ready = auth_is_configured();
$login_url = '#';

if ($config_ready) {
    $state = bin2hex(random_bytes(16));
    $nonce = bin2hex(random_bytes(16));
    $_SESSION['line_login_state'] = $state;
    $_SESSION['line_login_nonce'] = $nonce;

    $login_url = 'https://access.line.me/oauth2/v2.1/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => LINE_LOGIN_CHANNEL_ID,
        'redirect_uri' => auth_callback_url(),
        'state' => $state,
        'scope' => 'profile openid',
        'nonce' => $nonce,
    ]);
}

$error_msg = isset($_GET['error']) ? trim($_GET['error']) : '';
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>เข้าสู่ระบบ - เลขา AI</title>
  <script src="https://cdn.tailwindcss.com/3.4.17"></script>
  <script src="https://cdn.jsdelivr.net/npm/lucide@0.263.0/dist/umd/lucide.min.js"></script>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Noto+Sans+Thai:wght@400;500;700&display=swap" rel="stylesheet">
  <link href="<?php echo htmlspecialchars(brand_mark_font_link()); ?>" rel="stylesheet">
  <style>
    body { font-family: 'Noto Sans Thai', 'DM Sans', sans-serif; }
    h1, h2 { text-wrap: balance; }
    p { text-wrap: pretty; }
    <?php echo brand_mark_css(); ?>

    #slide-track {
      display: flex;
      transition: transform 0.32s cubic-bezier(0.4, 0, 0.2, 1);
      will-change: transform;
    }
    .intro-slide {
      flex: 0 0 100%;
      min-width: 0;
    }
    .intro-dot.active { background: #E2E800; }
    .intro-dot { transition: background 0.2s, transform 0.2s; }
    .intro-dot.active { transform: scale(1.15); }

    .struct-tab {
      cursor: pointer;
      transition: border-color 0.15s, background 0.15s, color 0.15s;
    }
    .struct-tab[aria-selected="true"] {
      border-color: #E2E800;
      background: rgba(226, 232, 0, 0.12);
      color: #F0F0F0;
    }
    .struct-panel {
      animation: structFade 0.2s ease-out;
    }
    @keyframes structFade {
      from { opacity: 0; transform: translateY(4px); }
      to { opacity: 1; transform: translateY(0); }
    }
  </style>
</head>
<body class="min-h-screen flex flex-col items-center justify-center bg-[#141414] p-4">

  <div class="w-full max-w-md">
    <!-- โลโก้ -->
    <div class="flex justify-center mb-6">
      <?php render_luepin_mark('lg', 'dark'); ?>
    </div>

    <?php if ($error_msg !== ''): ?>
      <div class="mb-4 px-4 py-3 rounded-lg bg-red-500/10 text-red-400 text-sm">
        <?php echo htmlspecialchars($error_msg); ?>
      </div>
    <?php endif; ?>

    <div id="slide-viewport" class="overflow-hidden rounded-2xl">
      <div id="slide-track">

        <!-- ① เราเป็นใคร -->
        <section class="intro-slide px-0.5" data-slide="0">
          <div class="bg-[#1C1C1C] border border-[#2A2A2A] rounded-2xl p-6 sm:p-8 min-h-[320px] flex flex-col">
            <div class="w-12 h-12 rounded-xl bg-[#E2E800]/15 border border-[#E2E800]/30 flex items-center justify-center mb-4">
              <i data-lucide="sparkles" class="w-6 h-6 text-[#E2E800]"></i>
            </div>
            <p class="text-[10px] font-bold text-[#E2E800] mb-1">1 / 6</p>
            <h2 class="text-xl font-bold text-[#F0F0F0] mb-3">เลขา AI คืออะไร?</h2>
            <p class="text-sm text-[#D6D6D6] leading-relaxed">
              ผู้ช่วย AI สำหรับ<strong class="text-[#F0F0F0]">งานขาย</strong> — ช่วยดูแลลูกค้า งานที่ต้องทำ และเป้ายอด ครบวงจรในที่เดียว
              ใช้ได้กับทีมขายหลายสายงาน
            </p>
            <p class="text-sm text-[#D6D6D6] leading-relaxed mt-3">
              คุยผ่าน LINE แล้วเปิด Dashboard บนเว็บเพื่อทำงานแบบเต็มจอ
            </p>
          </div>
        </section>

        <!-- ② การเก็บข้อมูล -->
        <section class="intro-slide px-0.5" data-slide="1">
          <div class="bg-[#1C1C1C] border border-[#2A2A2A] rounded-2xl p-6 sm:p-8 min-h-[320px] flex flex-col">
            <div class="w-12 h-12 rounded-xl bg-[#E2E800]/15 border border-[#E2E800]/30 flex items-center justify-center mb-4">
              <i data-lucide="archive" class="w-6 h-6 text-[#E2E800]"></i>
            </div>
            <p class="text-[10px] font-bold text-[#E2E800] mb-1">2 / 6</p>
            <h2 class="text-xl font-bold text-[#F0F0F0] mb-3">การเก็บข้อมูล</h2>
            <p class="text-sm text-[#D6D6D6] leading-relaxed">
              ขณะใช้งานหรือสมาชิกที่ต่ออายุ ดูข้อมูลย้อนหลังได้ <b class="text-[#F0F0F0]">1 ปี</b><br>
              ต้องการเก็บนานกว่านี้ — ดูเงื่อนไขในนโยบายข้อมูล
            </p>
            <p class="text-sm text-[#D6D6D6] leading-relaxed mt-3 flex-1">
              หากไม่ต่ออายุ ระบบจะปิดการใช้งาน<br>
              ข้อมูลที่คุณบันทึกเป็นของคุณ<br>
              ยังเข้าดูและส่งออกได้อีก <b class="text-[#F0F0F0]">3 เดือน</b> ก่อนลบจากเซิร์ฟเวอร์ของเรา
            </p>
          </div>
        </section>

        <!-- ③ ข้อมูลส่วนตัว -->
        <section class="intro-slide px-0.5" data-slide="2">
          <div class="bg-[#1C1C1C] border border-[#2A2A2A] rounded-2xl p-6 sm:p-8 min-h-[360px] flex flex-col">
            <div class="w-12 h-12 rounded-xl bg-[#E2E800]/15 border border-[#E2E800]/30 flex items-center justify-center mb-4">
              <i data-lucide="shield-check" class="w-6 h-6 text-[#E2E800]"></i>
            </div>
            <p class="text-[10px] font-bold text-[#E2E800] mb-1">3 / 6</p>
            <h2 class="text-xl font-bold text-[#F0F0F0] mb-3">ข้อมูลของคุณ</h2>
            <p class="text-sm text-[#D6D6D6] leading-relaxed">
              ข้อมูล <b class="text-[#F0F0F0]">Lead · Stock · Project · Task</b> และงานที่คุณบันทึก<br>
              เป็นของคุณคนเดียว — ทุกสาขาสายงาน<br>
              ทีมพัฒนา<strong class="text-[#F0F0F0]">ไม่เห็นข้อมูลจริง</strong><br>
              เห็นเฉพาะบางส่วนที่ปิดบังแล้ว เพื่อนำไปฝึก AI ให้ฉลาดขึ้น
            </p>
            <p class="text-sm mt-3">
              <a href="privacy.php" target="_blank" rel="noopener" class="text-[#E2E800] underline underline-offset-2 text-xs font-medium">อ่านนโยบายข้อมูลฉบับเต็ม →</a>
            </p>
            <!-- พื้นที่สำหรับภาพตัวอย่าง (ใส่รูปภายหลัง) -->
            <div id="intro-privacy-preview" class="mt-4 rounded-xl border border-dashed border-[#3A3A3A] bg-[#232323] aspect-[4/3] flex flex-col items-center justify-center text-center p-4">
              <i data-lucide="image" class="w-8 h-8 text-[#979797] mb-2"></i>
              <p class="text-[10px] text-[#979797] leading-relaxed">ภาพตัวอย่างข้อมูลที่ทีมพัฒนาเห็น<br>จะเพิ่มภายหลัง</p>
            </div>
          </div>
        </section>

        <!-- ④ ข้อมูลแยกตามบัญชี -->
        <section class="intro-slide px-0.5" data-slide="3">
          <div class="bg-[#1C1C1C] border border-[#2A2A2A] rounded-2xl p-6 sm:p-8 min-h-[360px] flex flex-col">
            <div class="w-12 h-12 rounded-xl bg-[#E2E800]/15 border border-[#E2E800]/30 flex items-center justify-center mb-4">
              <i data-lucide="database" class="w-6 h-6 text-[#E2E800]"></i>
            </div>
            <p class="text-[10px] font-bold text-[#E2E800] mb-1">4 / 6</p>
            <h2 class="text-xl font-bold text-[#F0F0F0] mb-3">ข้อมูลแยกตามบัญชี</h2>
            <p class="text-sm text-[#D6D6D6] leading-relaxed flex-1">
              ข้อมูลถูกเก็บ<strong class="text-[#F0F0F0]">แยกตาม LINE ID</strong> ของคุณ — แต่ละบัญชีไม่ปนกับผู้ใช้อื่น<br>
              คุณเห็นและจัดการได้เฉพาะข้อมูลของตัวเอง ผ่าน Dashboard และ LINE ของคุณเท่านั้น
            </p>
          </div>
        </section>

        <!-- ⑤ โครงสร้างเริ่มต้น -->
        <section class="intro-slide px-0.5" data-slide="4">
          <div class="bg-[#1C1C1C] border border-[#2A2A2A] rounded-2xl p-6 sm:p-8 min-h-[480px] flex flex-col">
            <div class="w-12 h-12 rounded-xl bg-[#E2E800]/15 border border-[#E2E800]/30 flex items-center justify-center mb-4">
              <i data-lucide="layout-grid" class="w-6 h-6 text-[#E2E800]"></i>
            </div>
            <p class="text-[10px] font-bold text-[#E2E800] mb-1">5 / 6</p>
            <h2 class="text-xl font-bold text-[#F0F0F0] mb-3">โครงสร้างเริ่มต้น</h2>
            <p class="text-sm text-[#D6D6D6] leading-relaxed">
              โครงสร้างและเครื่องมือนี้ เริ่มจากงาน<strong class="text-[#F0F0F0]">ที่ปรึกษาอสังหาริมทรัพย์</strong>
            </p>

            <div id="struct-demo" class="mt-3 rounded-xl border border-[#2A2A2A] bg-[#232323] p-4">
              <div class="flex items-center justify-between gap-2 mb-2">
                <p class="text-[10px] font-bold text-[#979797] flex items-center gap-1.5">
                  <i data-lucide="layers" class="w-3.5 h-3.5"></i>
                  โครงสร้างมาตรฐาน
                </p>
                <span class="text-[9px] text-[#979797] shrink-0">กดเมนูเพื่อลองดู</span>
              </div>
              <div class="flex flex-wrap gap-1.5" role="tablist" aria-label="เมนู Dashboard ตัวอย่าง">
                <?php $first_nav = true; foreach ($default_nav_items as $nav_key => $nav_meta): ?>
                <button type="button"
                        class="struct-tab inline-flex items-center gap-1 text-[10px] px-2 py-1 rounded-md bg-[#1C1C1C] border border-[#3A3A3A] text-[#D6D6D6]"
                        role="tab"
                        aria-selected="<?php echo $first_nav ? 'true' : 'false'; ?>"
                        data-struct-key="<?php echo htmlspecialchars($nav_key); ?>"
                        id="struct-tab-<?php echo htmlspecialchars($nav_key); ?>">
                  <i data-lucide="<?php echo htmlspecialchars($nav_meta['icon']); ?>" class="w-3 h-3 text-[#E2E800]"></i>
                  <?php echo htmlspecialchars($nav_meta['label']); ?>
                </button>
                <?php $first_nav = false; endforeach; ?>
              </div>
              <div id="struct-panel" class="struct-panel mt-2 rounded-lg bg-[#1C1C1C] border border-[#3A3A3A] p-3 min-h-[92px]" role="tabpanel" aria-live="polite">
                <p id="struct-panel-title" class="text-xs font-bold text-[#F0F0F0] flex items-center gap-1.5">
                  <i id="struct-panel-icon" data-lucide="layout-dashboard" class="w-3.5 h-3.5 text-[#E2E800]"></i>
                  <span>หลัก</span>
                </p>
                <p id="struct-panel-desc" class="text-[10px] text-[#979797] mt-1 leading-relaxed"></p>
                <ul id="struct-panel-rows" class="mt-2 space-y-1"></ul>
              </div>
              <p class="text-[9px] text-[#979797] mt-2 leading-relaxed">* ตัวเลขและชื่อเป็นตัวอย่าง — ไม่ใช่ข้อมูลจริง</p>
              <?php if ($structure_preview_url !== ''): ?>
              <a href="<?php echo htmlspecialchars($structure_preview_url); ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1 mt-2 text-[10px] text-[#E2E800] underline underline-offset-2 font-medium">
                <i data-lucide="external-link" class="w-3 h-3"></i> ดูภาพโครงสร้างเต็ม →
              </a>
              <?php endif; ?>
            </div>
          </div>
        </section>

        <!-- ⑥ เข้าสู่ระบบ -->
        <section class="intro-slide px-0.5" data-slide="5">
          <div class="bg-[#1C1C1C] border border-[#2A2A2A] rounded-2xl p-6 sm:p-8 text-center">
            <p class="text-[10px] font-bold text-[#E2E800] mb-3">6 / 6</p>
            <h1 class="text-xl font-bold text-[#F0F0F0] mb-1">ยินดีต้อนรับสู่ เลขา AI</h1>
            <p class="text-xs text-[#979797] mb-5">พร้อมแล้วเข้าใช้งานได้เลย</p>

            <?php if ($config_ready): ?>
              <p class="text-[#D6D6D6] text-sm mb-6 leading-relaxed">ระบบใช้ LINE ID เป็นบัญชีของคุณ<br>เว็บกับเลขา AI ใน LINE จะต้องเป็นคนเดียวกัน<br><span class="text-[#979797] text-xs">ครั้งแรกจะพาไปลงทะเบียนก่อนเข้า Dashboard</span></p>
              <a href="<?php echo htmlspecialchars($login_url); ?>"
                 id="line-login-btn"
                 class="block w-full py-3.5 rounded-lg bg-[#06C755] hover:bg-[#05b34c] text-white font-bold transition">
                เข้าสู่ระบบด้วย LINE
              </a>
            <?php else: ?>
              <div class="text-left text-sm text-[#D6D6D6] space-y-3">
                <p class="font-bold text-[#E2E800]">⚠️ ยังไม่ได้ตั้งค่า LINE Login Channel</p>
                <p>ให้ทำตามขั้นตอนนี้ก่อน:</p>
                <ol class="list-decimal list-inside space-y-1.5 text-[#979797]">
                  <li>เข้า <a class="text-[#E2E800] underline" href="https://developers.line.biz/console/" target="_blank">LINE Developers Console</a></li>
                  <li>เลือก Provider <b>เดียวกับ</b> Messaging API ที่ใช้อยู่</li>
                  <li>สร้าง Channel ใหม่ ประเภท <b>LINE Login</b></li>
                  <li>ที่แท็บ LINE Login ใส่ Callback URL:<br>
                    <code class="block mt-1 px-2 py-1.5 bg-[#232323] text-[#D6D6D6] rounded text-xs break-all"><?php echo htmlspecialchars(auth_callback_url()); ?></code>
                  </li>
                  <li>คัดลอก <b>Channel ID</b> และ <b>Channel Secret</b> ไปใส่ใน <code class="bg-[#232323] px-1 rounded">config.php</code></li>
                </ol>
              </div>
            <?php endif; ?>
          </div>
        </section>

      </div>
    </div>

    <!-- นำทาง -->
    <div class="mt-5 flex items-center justify-between gap-3 px-1">
      <button type="button" id="intro-prev" class="w-10 h-10 rounded-xl border border-[#2A2A2A] bg-[#1C1C1C] flex items-center justify-center text-[#979797] hover:text-[#F0F0F0] active:scale-95 transition disabled:opacity-30" aria-label="ก่อนหน้า">
        <i data-lucide="chevron-left" class="w-5 h-5"></i>
      </button>

      <div id="intro-dots" class="flex items-center gap-2" aria-hidden="true">
        <?php for ($i = 0; $i < 6; $i++): ?>
          <span class="intro-dot w-2 h-2 rounded-full bg-[#2A2A2A]<?php echo $i === 0 ? ' active' : ''; ?>"></span>
        <?php endfor; ?>
      </div>

      <button type="button" id="intro-next" class="min-w-[5.5rem] h-10 px-4 rounded-xl bg-[#E2E800] text-[#141414] text-sm font-bold active:scale-95 transition">
        ถัดไป
      </button>
    </div>
  </div>

  <script>
    const TOTAL = 6;
    const LOGIN_INDEX = 5;
    const STRUCT_DEMO = <?php echo json_encode([
        'nav' => $default_nav_items,
        'panels' => $structure_demo,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    const INITIAL_SLIDE = <?php echo (int)$initial_slide; ?>;

    let index = 0;
    const track = document.getElementById('slide-track');
    const prevBtn = document.getElementById('intro-prev');
    const nextBtn = document.getElementById('intro-next');
    const dots = document.querySelectorAll('.intro-dot');

    function goTo(i, force) {
      const next = Math.max(0, Math.min(TOTAL - 1, i));
      if (!force && next > index + 1) return;
      index = next;
      track.style.transform = 'translateX(-' + (index * 100) + '%)';
      dots.forEach((d, di) => d.classList.toggle('active', di === index));
      prevBtn.disabled = index === 0;
      nextBtn.textContent = index === LOGIN_INDEX ? 'เข้าใช้งาน' : 'ถัดไป';
      if (window.lucide) lucide.createIcons();
    }

    prevBtn.addEventListener('click', () => goTo(index - 1));
    nextBtn.addEventListener('click', () => {
      if (index < LOGIN_INDEX) goTo(index + 1);
      else document.getElementById('line-login-btn')?.focus();
    });

    const viewport = document.getElementById('slide-viewport');
    let touchX = 0;
    viewport.addEventListener('touchstart', e => { touchX = e.touches[0].clientX; }, { passive: true });
    viewport.addEventListener('touchend', e => {
      const dx = e.changedTouches[0].clientX - touchX;
      if (dx < -40) goTo(index + 1);
      else if (dx > 40) goTo(index - 1);
    }, { passive: true });

    function renderStructPanel(key) {
      const nav = STRUCT_DEMO.nav[key];
      const panel = STRUCT_DEMO.panels[key];
      if (!nav || !panel) return;

      document.querySelectorAll('.struct-tab').forEach(btn => {
        const active = btn.dataset.structKey === key;
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
      });

      const panelEl = document.getElementById('struct-panel');
      const titleEl = document.getElementById('struct-panel-title');
      const descEl = document.getElementById('struct-panel-desc');
      const rowsEl = document.getElementById('struct-panel-rows');
      const iconEl = document.getElementById('struct-panel-icon');
      if (!panelEl || !titleEl || !descEl || !rowsEl || !iconEl) return;

      panelEl.classList.remove('struct-panel');
      void panelEl.offsetWidth;
      panelEl.classList.add('struct-panel');

      iconEl.setAttribute('data-lucide', nav.icon);
      titleEl.querySelector('span').textContent = nav.label;
      descEl.textContent = panel.desc;
      rowsEl.innerHTML = (panel.rows || []).map(row =>
        `<li class="flex items-start justify-between gap-2 text-[10px]">
          <span class="text-[#D6D6D6]">${row.label}</span>
          <span class="text-[#979797] shrink-0">${row.value}</span>
        </li>`
      ).join('');

      if (window.lucide) lucide.createIcons();
    }

    document.querySelectorAll('.struct-tab').forEach(btn => {
      btn.addEventListener('click', () => renderStructPanel(btn.dataset.structKey));
    });
    if (STRUCT_DEMO.nav && Object.keys(STRUCT_DEMO.nav).length) {
      renderStructPanel(Object.keys(STRUCT_DEMO.nav)[0]);
    }

    goTo(INITIAL_SLIDE, true);
    if (window.lucide) lucide.createIcons();
  </script>
</body>
</html>
