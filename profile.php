<?php
// profile.php — หน้าโปรไฟล & สถานะ Subscription (เชื่อมจาก Dashboard)
require_once 'config.php';
require_once 'auth.php';
require_once __DIR__ . '/lib/subscription.php';

auth_require_login();

subscription_ensure_schema($conn);

$user = auth_current_user($conn);
if (!$user) {
    header('Location: login.php');
    exit();
}

$display_name = $_SESSION['line_display_name'] ?: ($user['user_name'] ?? 'ผู้ใช้งาน');
$picture_url  = $_SESSION['line_picture_url'] ?? '';
$full_name    = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if ($full_name === '') $full_name = $user['user_name'] ?? $display_name;

$is_pro = !empty($user['is_subscribed']);
$is_lifetime_free = !empty($user['is_lifetime_free']);
$days_left = 0;
$trial_expired = false;
$trial_end_fmt = '-';
if (!$is_pro && !$is_lifetime_free && !empty($user['trial_ends_at'])) {
    $trial_ts = strtotime($user['trial_ends_at']);
    $days_left = max(0, (int)ceil(($trial_ts - time()) / 86400));
    $trial_expired = $trial_ts < time();
    $months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $trial_end_fmt = (int)date('j', $trial_ts) . ' ' . $months[(int)date('n', $trial_ts) - 1] . ' ' . date('Y', $trial_ts);
}

$has_sheet = trim($user['google_sheet_id'] ?? '') !== '';
$has_drive = trim($user['google_drive_id'] ?? '') !== '';
$line_id   = $user['line_user_id'] ?? '';
$edit_url  = 'register.php?from=dashboard';
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>โปรไฟล — เลขา AI</title>
  <script src="https://cdn.tailwindcss.com/3.4.17"></script>
  <script src="https://cdn.jsdelivr.net/npm/lucide@0.263.0/dist/umd/lucide.min.js"></script>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Noto+Sans+Thai:wght@400;500;700&display=swap" rel="stylesheet">
  <script>
    if ((localStorage.getItem('theme') || 'dark') === 'light') document.documentElement.classList.add('light');
  </script>
  <style>
    :root {
      --bg: #141414; --card: #1C1C1C; --surface: #232323; --chip: #2E2E2E;
      --border: #2A2A2A; --text: #F0F0F0; --text-2: #B5B5B5; --muted: #979797;
      --faint: #6B6B6B; --accent-text: #E2E800;
    }
    html.light {
      --bg: #F2F2EE; --card: #FFFFFF; --surface: #ECECE8; --chip: #E4E4E0;
      --border: #D4D4D0; --text: #141414; --text-2: #4A4A4A; --muted: #6B6B6B;
      --faint: #8F8F8A; --accent-text: #7A7E00;
    }
    body { font-family: 'Noto Sans Thai', 'DM Sans', sans-serif; }
  </style>
</head>
<body class="min-h-screen bg-[var(--bg)] text-[var(--text)]">

<div class="max-w-md mx-auto min-h-screen pb-8">

  <header class="px-5 pt-6 pb-4 flex items-center gap-3">
    <a href="dashboard.php" class="w-9 h-9 rounded-full bg-[var(--card)] border border-[var(--border)] flex items-center justify-center active:scale-95 transition" title="กลับ Dashboard">
      <i data-lucide="arrow-left" class="w-4 h-4"></i>
    </a>
    <div class="flex-1 min-w-0">
      <h1 class="text-sm font-bold">โปรไฟล</h1>
      <p class="text-[11px] text-[var(--faint)]">บัญชี &amp; การสมัครใช้งาน</p>
    </div>
  </header>

  <div class="px-5 space-y-4">

    <?php if (isset($_GET['saved'])): ?>
      <div class="flex items-center gap-2 p-3 rounded-xl bg-[#E2E800]/10 border border-[#E2E800]/30 text-sm">
        <i data-lucide="check-circle" class="w-4 h-4 text-[var(--accent-text)] shrink-0"></i>
        <span>บันทึกข้อมูลแล้ว</span>
      </div>
    <?php endif; ?>

    <!-- โปรไฟลหลัก -->
    <div class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-5 flex items-center gap-4">
      <?php if ($picture_url !== ''): ?>
        <img src="<?php echo htmlspecialchars($picture_url); ?>" class="w-16 h-16 rounded-full object-cover border border-[var(--border)]" alt="">
      <?php else: ?>
        <div class="w-16 h-16 rounded-full bg-[var(--surface)] border border-[var(--border)] flex items-center justify-center">
          <i data-lucide="user" class="w-7 h-7 text-[var(--muted)]"></i>
        </div>
      <?php endif; ?>
      <div class="min-w-0 flex-1">
        <p class="font-bold text-lg truncate"><?php echo htmlspecialchars($full_name); ?></p>
        <p class="text-xs text-[var(--muted)] truncate"><?php echo htmlspecialchars($display_name); ?></p>
        <?php if ($line_id !== ''): ?>
          <p class="text-[10px] text-[var(--faint)] font-mono mt-1 truncate" title="LINE User ID"><?php echo htmlspecialchars(substr($line_id, 0, 12)); ?>…</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Subscription -->
    <section class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-5">
      <h2 class="text-xs font-bold text-[var(--muted)] mb-3 flex items-center gap-1.5">
        <i data-lucide="credit-card" class="w-3.5 h-3.5"></i> แพ็กเกจ &amp; การทดลองใช้
      </h2>

      <?php if ($is_pro): ?>
        <div class="flex items-start gap-3 p-3 rounded-xl bg-[#E2E800]/10 border border-[#E2E800]/30">
          <i data-lucide="crown" class="w-5 h-5 text-[var(--accent-text)] shrink-0 mt-0.5"></i>
          <div>
            <p class="font-bold text-[var(--accent-text)]">Pro · ใช้งานเต็มรูปแบบ</p>
            <p class="text-xs text-[var(--muted)] mt-0.5">สมาชิกชำระเงินแล้ว ไม่จำกัดเวลา</p>
          </div>
        </div>
      <?php elseif ($is_lifetime_free): ?>
        <div class="flex items-start gap-3 p-3 rounded-xl bg-[#E2E800]/10 border border-[#E2E800]/30">
          <i data-lucide="infinity" class="w-5 h-5 text-[var(--accent-text)] shrink-0 mt-0.5"></i>
          <div>
            <p class="font-bold text-[var(--accent-text)]">Free · ตลอดชีพ</p>
            <p class="text-xs text-[var(--muted)] mt-0.5">ใช้งานเต็มรูปแบบฟรี ไม่หมดอายุ</p>
          </div>
        </div>
      <?php elseif (!$trial_expired && $days_left > 0): ?>
        <div class="flex items-start gap-3 p-3 rounded-xl bg-[var(--surface)] border border-[var(--border)]">
          <i data-lucide="clock" class="w-5 h-5 text-[var(--text-2)] shrink-0 mt-0.5"></i>
          <div>
            <p class="font-bold">Free Trial · เหลือ <?php echo $days_left; ?> วัน</p>
            <p class="text-xs text-[var(--muted)] mt-0.5">หมดอายุ <?php echo htmlspecialchars($trial_end_fmt); ?></p>
          </div>
        </div>
      <?php else: ?>
        <div class="flex items-start gap-3 p-3 rounded-xl bg-[var(--surface)] border border-[var(--border)]">
          <i data-lucide="alert-circle" class="w-5 h-5 text-[var(--text-2)] shrink-0 mt-0.5"></i>
          <div>
            <p class="font-bold">หมดเวลาทดลองใช้ฟรี</p>
            <p class="text-xs text-[var(--muted)] mt-0.5">หมดอายุเมื่อ <?php echo htmlspecialchars($trial_end_fmt); ?> — ติดต่อทีมงานเพื่ออัปเกรดเป็น Pro</p>
          </div>
        </div>
      <?php endif; ?>
    </section>

    <!-- บันไดราคา (ตัวอย่าง — ยังไม่เชื่อมชำระเงิน) -->
    <section class="space-y-3">
      <div class="flex items-center justify-between gap-2">
        <h2 class="text-xs font-bold text-[var(--muted)] flex items-center gap-1.5">
          <i data-lucide="layers" class="w-3.5 h-3.5"></i> เลือกแพ็กเกจ
        </h2>
        <span class="text-[10px] text-[var(--faint)] border border-[var(--border)] px-2 py-0.5 rounded-full">ตัวอย่าง UI</span>
      </div>
      <p class="text-[11px] text-[var(--muted)] leading-snug">ทดลองฟรี 15 วัน · หลังหมดอายุยังดู Product &amp; Lead ได้ (อ่านอย่างเดียว) · เลขา AI &amp; LINE ต้องต่ออายุ</p>

      <div class="grid grid-cols-1 gap-3">

        <!-- Starter 399 -->
        <div class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 <?php echo (!$is_pro && !$trial_expired) ? 'ring-1 ring-[var(--border)]' : ''; ?>">
          <div class="flex items-start justify-between gap-2 mb-3">
            <div>
              <p class="font-bold flex items-center gap-1.5">
                <i data-lucide="package" class="w-4 h-4 text-[var(--text-2)]"></i> Starter
              </p>
              <p class="text-[11px] text-[var(--muted)] mt-0.5">นายหน้าเริ่มต้น / part-time</p>
            </div>
            <div class="text-right shrink-0">
              <p class="text-xl font-bold">฿399</p>
              <p class="text-[10px] text-[var(--faint)]">/เดือน</p>
            </div>
          </div>
          <ul class="space-y-2 text-xs text-[var(--text-2)] mb-4">
            <li class="flex items-start gap-2"><i data-lucide="check" class="w-3.5 h-3.5 text-[var(--accent-text)] shrink-0 mt-0.5"></i><span><strong class="text-[var(--text)]">เลขา AI</strong> ใน LINE — ตอบ สรุป อัปเดต listing</span></li>
            <li class="flex items-start gap-2"><i data-lucide="check" class="w-3.5 h-3.5 text-[var(--accent-text)] shrink-0 mt-0.5"></i><span>Dashboard: Product · Lead · Tasks</span></li>
            <li class="flex items-start gap-2"><i data-lucide="check" class="w-3.5 h-3.5 text-[var(--accent-text)] shrink-0 mt-0.5"></i><span>แก้ข้อมูลในเว็บ · อัปโหลดรูป Drive</span></li>
            <li class="flex items-start gap-2"><i data-lucide="minus-circle" class="w-3.5 h-3.5 text-[var(--muted)] shrink-0 mt-0.5"></i><span>ทรัพย์สูงสุด <strong class="text-[var(--text)]">50</strong> รายการ · Lead <strong class="text-[var(--text)]">100</strong></span></li>
            <li class="flex items-start gap-2"><i data-lucide="minus-circle" class="w-3.5 h-3.5 text-[var(--muted)] shrink-0 mt-0.5"></i><span>AI ~<strong class="text-[var(--text)]">500</strong> ข้อความ/เดือน</span></li>
            <li class="flex items-start gap-2"><i data-lucide="x" class="w-3.5 h-3.5 text-[var(--faint)] shrink-0 mt-0.5"></i><span class="text-[var(--muted)]">ไม่มี Pipeline &amp; รายงานขั้นสูง</span></li>
          </ul>
          <button type="button" disabled class="w-full py-2.5 rounded-xl border border-[var(--border)] bg-[var(--surface)] text-xs font-bold text-[var(--muted)] cursor-not-allowed">
            <i data-lucide="clock" class="w-3.5 h-3.5 inline-block align-[-2px] mr-1"></i> ชำระเงิน — เร็วๆ นี้
          </button>
          <p class="text-[10px] text-center text-[var(--faint)] mt-2">รายปี ฿3,990 <span class="text-[var(--muted)]">(ประหยัด ~17%)</span></p>
        </div>

        <!-- Pro 599 -->
        <div class="bg-[var(--card)] border-2 border-[#E2E800]/50 rounded-2xl p-4 relative">
          <span class="absolute -top-2.5 right-4 text-[10px] font-bold px-2 py-0.5 rounded-full bg-[#E2E800] text-[#141414] inline-flex items-center gap-1">
            <i data-lucide="star" class="w-3 h-3"></i> แนะนำ
          </span>
          <div class="flex items-start justify-between gap-2 mb-3">
            <div>
              <p class="font-bold flex items-center gap-1.5">
                <i data-lucide="crown" class="w-4 h-4 text-[var(--accent-text)]"></i> Pro
              </p>
              <p class="text-[11px] text-[var(--muted)] mt-0.5">ทำจริงจัง / ยอดขายสูง</p>
            </div>
            <div class="text-right shrink-0">
              <p class="text-xl font-bold text-[var(--accent-text)]">฿599</p>
              <p class="text-[10px] text-[var(--faint)]">/เดือน</p>
            </div>
          </div>
          <ul class="space-y-2 text-xs text-[var(--text-2)] mb-4">
            <li class="flex items-start gap-2"><i data-lucide="check" class="w-3.5 h-3.5 text-[var(--accent-text)] shrink-0 mt-0.5"></i><span><strong class="text-[var(--text)]">ทุกอย่างใน Starter</strong></span></li>
            <li class="flex items-start gap-2"><i data-lucide="check" class="w-3.5 h-3.5 text-[var(--accent-text)] shrink-0 mt-0.5"></i><span><strong class="text-[var(--text)]">ไม่จำกัด</strong> ทรัพย์ &amp; Lead</span></li>
            <li class="flex items-start gap-2"><i data-lucide="check" class="w-3.5 h-3.5 text-[var(--accent-text)] shrink-0 mt-0.5"></i><span>Pipeline เต็ม + กราฟเป้ารายได้</span></li>
            <li class="flex items-start gap-2"><i data-lucide="check" class="w-3.5 h-3.5 text-[var(--accent-text)] shrink-0 mt-0.5"></i><span>AI <strong class="text-[var(--text)]">ไม่จำกัด</strong> (fair use)</span></li>
            <li class="flex items-start gap-2"><i data-lucide="check" class="w-3.5 h-3.5 text-[var(--accent-text)] shrink-0 mt-0.5"></i><span>รายงานสรุป · ลำดับความสำคัญสูง</span></li>
          </ul>
          <?php if ($is_pro): ?>
            <div class="w-full py-2.5 rounded-xl bg-[#E2E800]/15 border border-[#E2E800]/40 text-xs font-bold text-center flex items-center justify-center gap-1.5 text-[var(--accent-text)]">
              <i data-lucide="check-circle" class="w-4 h-4"></i> แพ็กเกจปัจจุบัน
            </div>
          <?php else: ?>
            <button type="button" disabled class="w-full py-2.5 rounded-xl bg-[#E2E800] text-[#141414] text-xs font-bold cursor-not-allowed opacity-80">
              <i data-lucide="zap" class="w-3.5 h-3.5 inline-block align-[-2px] mr-1"></i> อัปเกรด Pro — เร็วๆ นี้
            </button>
          <?php endif; ?>
          <p class="text-[10px] text-center text-[var(--faint)] mt-2">รายปี ฿5,990 <span class="text-[var(--muted)]">(ประหยัด ~17%)</span></p>
        </div>
      </div>

      <!-- โค้ดส่วนลด (ตัวอย่าง) -->
      <div class="bg-[var(--card)] border border-[var(--border)] rounded-2xl overflow-hidden">
        <button type="button" id="promo-toggle" class="w-full px-4 py-3 flex items-center justify-between text-sm font-medium active:bg-[var(--surface)] transition">
          <span class="flex items-center gap-2 text-[var(--text-2)]">
            <i data-lucide="ticket" class="w-4 h-4"></i> มีโค้ดส่วนลด?
          </span>
          <i data-lucide="chevron-down" id="promo-chevron" class="w-4 h-4 text-[var(--muted)] transition-transform"></i>
        </button>
        <div id="promo-panel" class="hidden px-4 pb-4 border-t border-[var(--border)]">
          <p class="text-[11px] text-[var(--muted)] pt-3 mb-2">ตัวอย่าง: <span class="font-mono text-[var(--text-2)]">LUEPIN20</span> ลด 20% 3 เดือนแรก</p>
          <div class="flex gap-2">
            <input type="text" id="promo-input" placeholder="ใส่โค้ด" class="flex-1 px-3 py-2 rounded-lg border border-[var(--border)] bg-[var(--surface)] text-sm uppercase" disabled>
            <button type="button" disabled class="px-4 py-2 rounded-lg bg-[var(--chip)] text-xs font-bold text-[var(--muted)] cursor-not-allowed">ใช้โค้ด</button>
          </div>
          <p id="promo-hint" class="text-[10px] text-[var(--faint)] mt-2 hidden"></p>
        </div>
      </div>

      <!-- หมดอายุแล้วจะเป็นอย่างไร -->
      <details class="bg-[var(--card)] border border-[var(--border)] rounded-2xl group">
        <summary class="px-4 py-3 text-sm font-medium cursor-pointer flex items-center gap-2 text-[var(--text-2)] list-none">
          <i data-lucide="info" class="w-4 h-4 shrink-0"></i>
          ถ้าไม่ต่ออายุจะเป็นอย่างไร?
          <i data-lucide="chevron-down" class="w-4 h-4 ml-auto text-[var(--muted)] group-open:rotate-180 transition-transform"></i>
        </summary>
        <div class="px-4 pb-4 text-xs text-[var(--muted)] space-y-2 border-t border-[var(--border)] pt-3">
          <p class="flex items-start gap-2"><i data-lucide="check" class="w-3.5 h-3.5 shrink-0 mt-0.5"></i><span><strong class="text-[var(--text)]">ดู Product &amp; Lead ได้</strong> — ข้อมูลเป็นของคุณ</span></p>
          <p class="flex items-start gap-2"><i data-lucide="x" class="w-3.5 h-3.5 shrink-0 mt-0.5"></i><span>แก้ไข · เพิ่ม · ลบไม่ได้ (อ่านอย่างเดียว)</span></p>
          <p class="flex items-start gap-2"><i data-lucide="x" class="w-3.5 h-3.5 shrink-0 mt-0.5"></i><span>เลขา AI &amp; LINE ตอบอัตโนมัติปิด — แจ้งให้ต่ออายุ</span></p>
          <p class="flex items-start gap-2"><i data-lucide="eye-off" class="w-3.5 h-3.5 shrink-0 mt-0.5"></i><span>Pipeline · Tasks · สถิติ Home — ซ่อน/เบลอ</span></p>
        </div>
      </details>
    </section>

    <!-- ข้อมูลลงทะเบียน -->
    <section class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-5 space-y-3">
      <h2 class="text-xs font-bold text-[var(--muted)] flex items-center gap-1.5">
        <i data-lucide="clipboard-list" class="w-3.5 h-3.5"></i> ข้อมูลที่ลงทะเบียน
      </h2>
      <?php
      $rows = [
          ['phone', 'เบอร์โทร', $user['phone'] ?? ''],
          ['briefcase', 'อาชีพ', $user['job_title'] ?? ''],
      ];
      foreach ($rows as [$icon, $label, $val]):
          $show = ($val !== '' && $val !== null) ? $val : 'ยังไม่ระบุ';
      ?>
        <div class="flex items-center justify-between gap-3 text-sm">
          <span class="text-[var(--muted)] flex items-center gap-1.5 shrink-0">
            <i data-lucide="<?php echo $icon; ?>" class="w-3.5 h-3.5"></i><?php echo $label; ?>
          </span>
          <span class="font-medium text-right truncate"><?php echo htmlspecialchars($show); ?></span>
        </div>
      <?php endforeach; ?>

      <div class="border-t border-[var(--border)] pt-3 space-y-2">
        <p class="text-[11px] font-bold text-[var(--muted)]">การเชื่อมต่อ Google</p>
        <div class="flex items-center justify-between text-sm">
          <span class="text-[var(--muted)] flex items-center gap-1.5">
            <i data-lucide="table-2" class="w-3.5 h-3.5"></i> Google Sheet
          </span>
          <span class="font-medium flex items-center gap-1 <?php echo $has_sheet ? 'text-[var(--text)]' : 'text-[var(--faint)]'; ?>">
            <i data-lucide="<?php echo $has_sheet ? 'check-circle' : 'circle'; ?>" class="w-3.5 h-3.5"></i>
            <?php echo $has_sheet ? 'ผูกแล้ว' : 'ยังไม่ผูก'; ?>
          </span>
        </div>
        <div class="flex items-center justify-between text-sm">
          <span class="text-[var(--muted)] flex items-center gap-1.5">
            <i data-lucide="hard-drive" class="w-3.5 h-3.5"></i> Google Drive
          </span>
          <span class="font-medium flex items-center gap-1 <?php echo $has_drive ? 'text-[var(--text)]' : 'text-[var(--faint)]'; ?>">
            <i data-lucide="<?php echo $has_drive ? 'check-circle' : 'circle'; ?>" class="w-3.5 h-3.5"></i>
            <?php echo $has_drive ? 'ผูกแล้ว' : 'ยังไม่ผูก'; ?>
          </span>
        </div>
      </div>
    </section>

    <a href="<?php echo htmlspecialchars($edit_url); ?>"
       class="w-full flex items-center justify-center gap-2 py-3.5 rounded-xl bg-[#E2E800] text-[#141414] text-sm font-bold active:scale-[0.98] transition">
      <i data-lucide="pencil" class="w-4 h-4"></i> แก้ไขข้อมูลลงทะเบียน
    </a>
    <p class="text-[10px] text-center text-[var(--faint)] pb-2">แก้ชื่อ เบอร์ อาชีพ และลิงก์ Google Sheet / Drive ได้ที่นี่</p>

    <div class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-[10px] text-[var(--faint)] pb-4">
      <a href="privacy.php" class="underline underline-offset-2 hover:text-[var(--muted)]">นโยบายข้อมูล</a>
      <span aria-hidden="true">·</span>
      <a href="terms.php" class="underline underline-offset-2 hover:text-[var(--muted)]">ข้อตกลงการใช้งาน</a>
    </div>

  </div>
</div>

<script>
  if (window.lucide) lucide.createIcons();

  const promoToggle = document.getElementById('promo-toggle');
  const promoPanel = document.getElementById('promo-panel');
  const promoChevron = document.getElementById('promo-chevron');
  if (promoToggle && promoPanel) {
    promoToggle.addEventListener('click', () => {
      promoPanel.classList.toggle('hidden');
      if (promoChevron) promoChevron.style.transform = promoPanel.classList.contains('hidden') ? '' : 'rotate(180deg)';
    });
  }
</script>
</body>
</html>
