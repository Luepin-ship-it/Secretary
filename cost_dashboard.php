<?php
// cost_dashboard.php — สรุปต้นทุน + แผน Cloud สำหรับเจ้าของระบบ
$rates = [
    'usd_thb'          => 35.0,
    'openai_in'        => 0.15,
    'openai_out'       => 0.60,
    'updated'          => 'มิ.ย. 2026',
    // ค่าเริ่มต้นสำหรับสถานการณ์ 50 user (ปรับในเครื่องคำนวณได้)
    'users_default'    => 50,
    'msgs_per_user'    => 250,
    'reject_pct'       => 5,
    'vps_month'        => 490,    // VPS 2GB (Hetzner/DO ประมาณ €4–$12)
    'domain_month'     => 50,     // ~600/ปี
    'backup_month'     => 50,     // Cloudflare R2 / Backblaze สำรอง DB
    'sql_extra'        => 0,      // MariaDB บน VPS เดียวกัน = 0
    'line_plan'        => 1780,   // แพ็ก Pro ไทย ~15k ข้อความ (50 user ควรไม่ใช้แพ็กฟรี)
    'line_quota'       => 15000,  // โควต้าข้อความในแพ็ก
    'line_extra_fee'   => 0.45,   // บาท/ข้อความเกินโควต้า (ประมาณ)
    'line_msgs_total'  => 14000,  // 50 user × ~280 ข้อความ LINE รวม (ส่ง+ตอบ+Flex)
    'verify_onetime'   => 0,      // ยื่น Verified ผ่าน LINE โดยตรง = ฟรี (เอเจนซี่ ~1,000 ครั้งเดียว)
];

// คำนวณสถานการณ์ 50 user ล่วงหน้า (PHP)
$u = $rates['users_default'];
$m = $rates['msgs_per_user'];
$rp = $rates['reject_pct'] / 100;
$fx = $rates['usd_thb'];
$cost_n_usd = 800 / 1e6 * $rates['openai_in'] + 200 / 1e6 * $rates['openai_out'];
$cost_r_usd = 1600 / 1e6 * $rates['openai_in'] + 500 / 1e6 * $rates['openai_out'];
$total_ai_calls = $u * $m;
$ai_thb = (($total_ai_calls * (1 - $rp) * $cost_n_usd) + ($total_ai_calls * $rp * $cost_r_usd)) * $fx;
$line_extra_cnt = max(0, $rates['line_msgs_total'] - $rates['line_quota']);
$line_thb = $rates['line_plan'] + $line_extra_cnt * $rates['line_extra_fee'];
$cloud_thb = $rates['vps_month'] + $rates['domain_month'] + $rates['backup_month'] + $rates['sql_extra'];
$fixed_thb = $cloud_thb + $rates['line_plan'];
$total_thb = $ai_thb + $line_thb + $cloud_thb;
$rev_starter = 0.6; $rev_pro = 0.4;
$revenue_50 = round($u * ($rev_starter * 399 + $rev_pro * 599));
$margin_50 = $revenue_50 - $total_thb;
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ต้นทุนระบบ — เลขา AI</title>
  <script src="https://cdn.tailwindcss.com/3.4.17"></script>
  <script src="https://cdn.jsdelivr.net/npm/lucide@0.263.0/dist/umd/lucide.min.js"></script>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Noto+Sans+Thai:wght@400;500;700&display=swap" rel="stylesheet">
  <script>if ((localStorage.getItem('theme') || 'dark') === 'light') document.documentElement.classList.add('light');</script>
  <style>
    :root {
      --bg:#141414; --card:#1C1C1C; --surface:#232323; --border:#2A2A2A;
      --text:#F0F0F0; --text-2:#B5B5B5; --muted:#979797; --faint:#6B6B6B; --accent-text:#E2E800; --ok:#6ee7b7;
    }
    html.light {
      --bg:#F2F2EE; --card:#FFF; --surface:#ECECE8; --border:#D4D4D0;
      --text:#141414; --text-2:#4A4A4A; --muted:#6B6B6B; --faint:#8F8F8A; --accent-text:#7A7E00; --ok:#059669;
    }
    body { font-family:'Noto Sans Thai','DM Sans',sans-serif; }
    .num-inp { width:100%; padding:.5rem .65rem; border-radius:.5rem; border:1px solid var(--border); background:var(--surface); color:var(--text); font-size:.875rem; }
    .cost-table th,.cost-table td { padding:.65rem .75rem; text-align:left; border-bottom:1px solid var(--border); font-size:.8rem; }
    .cost-table th { font-size:.7rem; color:var(--muted); font-weight:700; }
    .plan-btn { font-size:.65rem; padding:.35rem .6rem; border-radius:.5rem; border:1px solid var(--border); background:var(--surface); color:var(--text-2); }
    .plan-btn.active { border-color:#E2E800; background:#E2E800/10; color:var(--accent-text); font-weight:700; }
    details summary { list-style:none; cursor:pointer; }
    details summary::-webkit-details-marker { display:none; }
  </style>
</head>
<body class="min-h-screen bg-[var(--bg)] text-[var(--text)]">

<div class="max-w-3xl mx-auto px-5 py-8 pb-20">

  <header class="mb-6">
    <p class="text-[10px] font-bold text-[var(--faint)] uppercase tracking-wider flex items-center gap-1"><i data-lucide="lock" class="w-3 h-3"></i> หน้าภายใน</p>
    <h1 class="text-2xl font-bold mt-1">Dashboard ต้นทุน</h1>
    <p class="text-sm text-[var(--muted)] mt-1">สรุปครบ · สมมติฐาน <?php echo $u; ?> user · gpt-4o-mini · <?php echo $rates['updated']; ?></p>
    <a href="dashboard.php" class="inline-flex items-center gap-1 text-xs text-[var(--accent-text)] mt-2 hover:underline"><i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> กลับแอปหลัก</a>
  </header>

  <!-- สรุป 50 User ใหญ่ -->
  <section class="bg-[#E2E800]/10 border-2 border-[#E2E800]/40 rounded-2xl p-5 mb-6">
    <h2 class="text-sm font-bold flex items-center gap-2 mb-3">
      <i data-lucide="users" class="w-4 h-4"></i> สรุปต้นทุนรายเดือน · <?php echo $u; ?> User
    </h2>
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm mb-4">
      <div><p class="text-[10px] text-[var(--muted)]">☁️ Cloud + SQL</p><p class="font-bold text-lg"><?php echo number_format($cloud_thb); ?> ฿</p></div>
      <div><p class="text-[10px] text-[var(--muted)]">💬 LINE OA</p><p class="font-bold text-lg"><?php echo number_format(round($line_thb)); ?> ฿</p></div>
      <div><p class="text-[10px] text-[var(--muted)]">🤖 AI Token</p><p class="font-bold text-lg"><?php echo number_format(round($ai_thb)); ?> ฿</p></div>
    </div>
    <div class="border-t border-[#E2E800]/30 pt-3 flex flex-wrap justify-between gap-2 items-end">
      <div>
        <p class="text-xs text-[var(--muted)]">ต้นทุนรวม / เดือน</p>
        <p class="text-2xl font-bold text-[var(--accent-text)]"><?php echo number_format(round($total_thb)); ?> ฿</p>
      </div>
      <div class="text-right">
        <p class="text-xs text-[var(--muted)]">รายได้ประมาณ (60% Starter / 40% Pro)</p>
        <p class="text-lg font-bold"><?php echo number_format($revenue_50); ?> ฿</p>
        <p class="text-xs text-[var(--ok)] flex items-center justify-end gap-1"><i data-lucide="trending-up" class="w-3 h-3"></i> กำไรขั้นต้น ~<?php echo number_format(round($margin_50)); ?> ฿ (<?php echo $revenue_50 > 0 ? round($margin_50/$revenue_50*100,1) : 0; ?>%)</p>
      </div>
    </div>
    <p class="text-[10px] text-[var(--faint)] mt-3">* ยังไม่รวมค่า Payment Gateway ~3% · ตัวเลข AI จากโค้ดจริง · LINE ตรวจราคาแพ็กล่าสุดก่อนจ่ายจริง</p>
  </section>

  <!-- การ์ดย่อย -->
  <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-6">
    <div class="bg-[var(--card)] border border-[var(--border)] rounded-xl p-3">
      <p class="text-[10px] text-[var(--muted)] flex items-center gap-1"><i data-lucide="cloud" class="w-3 h-3"></i> Cloud</p>
      <p class="text-lg font-bold mt-0.5" id="sum-cloud">—</p>
    </div>
    <div class="bg-[var(--card)] border border-[var(--border)] rounded-xl p-3">
      <p class="text-[10px] text-[var(--muted)] flex items-center gap-1"><i data-lucide="database" class="w-3 h-3"></i> SQL</p>
      <p class="text-lg font-bold mt-0.5" id="sum-sql">—</p>
    </div>
    <div class="bg-[var(--card)] border border-[var(--border)] rounded-xl p-3">
      <p class="text-[10px] text-[var(--muted)] flex items-center gap-1"><i data-lucide="cpu" class="w-3 h-3"></i> AI</p>
      <p class="text-lg font-bold mt-0.5" id="sum-ai">—</p>
    </div>
    <div class="bg-[var(--card)] border border-[var(--border)] rounded-xl p-3">
      <p class="text-[10px] text-[var(--muted)] flex items-center gap-1"><i data-lucide="message-circle" class="w-3 h-3"></i> LINE</p>
      <p class="text-lg font-bold mt-0.5" id="sum-line">—</p>
    </div>
    <div class="bg-[var(--card)] border border-[var(--border)] rounded-xl p-3 col-span-2 sm:col-span-1">
      <p class="text-[10px] text-[var(--muted)] flex items-center gap-1"><i data-lucide="wallet" class="w-3 h-3"></i> รวม</p>
      <p class="text-lg font-bold mt-0.5 text-[var(--accent-text)]" id="sum-total">—</p>
    </div>
  </div>

  <!-- 1. AI Token -->
  <section class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-5 mb-5">
    <h2 class="text-xs font-bold text-[var(--muted)] mb-3 flex items-center gap-1.5"><i data-lucide="bot" class="w-3.5 h-3.5"></i> 1. ต้นทุน AI Token (OpenAI)</h2>
    <table class="cost-table w-full mb-3">
      <tr><td class="text-[var(--muted)]">โมเดลในโค้ด</td><td><code>gpt-4o-mini</code> · <code>openai_agent.php</code></td></tr>
      <tr><td class="text-[var(--muted)]">ราคา input</td><td>$<?php echo $rates['openai_in']; ?> / 1M token (~<?php echo number_format($rates['openai_in']*$fx,2); ?> ฿)</td></tr>
      <tr><td class="text-[var(--muted)]">ราคา output</td><td>$<?php echo $rates['openai_out']; ?> / 1M token (~<?php echo number_format($rates['openai_out']*$fx,2); ?> ฿)</td></tr>
      <tr><td class="text-[var(--muted)]">อัปเดตลีดปกติ</td><td>1 API call · ~800 in + 200 out ≈ <strong id="cost-normal">—</strong></td></tr>
      <tr><td class="text-[var(--muted)]">Reject + Defensive</td><td>2 API calls · ≈ <strong id="cost-reject">—</strong></td></tr>
      <tr><td class="text-[var(--muted)]"><?php echo $u; ?> user × <?php echo $m; ?> ข้อความ/เดือน</td><td><strong><?php echo number_format($total_ai_calls); ?></strong> calls → ~<strong><?php echo number_format(round($ai_thb)); ?> ฿/เดือน</strong></td></tr>
    </table>
    <p class="text-[10px] text-[var(--faint)] flex gap-1"><i data-lucide="info" class="w-3 h-3 shrink-0"></i> AI กินน้อยมากเทียบรายได้ — แต่ต้อง log <code>usage</code> จริงเพื่อกัน user ใช้หนักผิดปกติ</p>
  </section>

  <!-- 2. Cloud -->
  <section class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-5 mb-5">
    <h2 class="text-xs font-bold text-[var(--muted)] mb-3 flex items-center gap-1.5"><i data-lucide="cloud" class="w-3.5 h-3.5"></i> 2. Cloud — อัปขึ้นแล้วฝากที่ไหน</h2>
    <p class="text-xs text-[var(--text-2)] mb-3 leading-relaxed">โปรเจกต์นี้เป็น <strong>PHP + MySQL + webhook</strong> ต้องการ HTTPS ตลอดเวลา (LINE บังคับ) — แนะนำ VPS ตัวเดียวรัน stack ครบ</p>

    <div class="bg-[var(--surface)] rounded-xl p-4 border border-[var(--border)] mb-4 text-xs space-y-3">
      <p class="font-bold flex items-center gap-1"><i data-lucide="server" class="w-3.5 h-3.5"></i> แนะนำสำหรับ 50 user</p>
      <table class="cost-table w-full">
        <thead><tr><th>รายการ</th><th>ตัวเลือก</th><th>ราคาโดยประมาณ</th></tr></thead>
        <tbody>
          <tr><td>VPS 2GB RAM</td><td>Hetzner CX22 / DigitalOcean $12 / Thai VPS</td><td><strong>350–600 ฿/เดือน</strong></td></tr>
          <tr><td>โดเมน .com/.th</td><td>Namecheap / THNIC</td><td><strong>~50 ฿/เดือน</strong> (เฉลี่ย)</td></tr>
          <tr><td>SSL</td><td>Let's Encrypt + Certbot</td><td><strong>ฟรี</strong></td></tr>
          <tr><td>DNS + CDN</td><td>Cloudflare Free</td><td><strong>ฟรี</strong></td></tr>
          <tr><td>Backup DB</td><td>Cloudflare R2 / Backblaze B2</td><td><strong>~30–80 ฿/เดือน</strong></td></tr>
        </tbody>
      </table>
    </div>

    <details class="bg-[var(--surface)] rounded-xl border border-[var(--border)] mb-2">
      <summary class="px-4 py-3 text-xs font-bold flex items-center gap-2">
        <i data-lucide="git-branch" class="w-3.5 h-3.5"></i> ขั้นตอน Deploy (โครงสร้าง)
        <i data-lucide="chevron-down" class="w-3.5 h-3.5 ml-auto opacity-50"></i>
      </summary>
      <ol class="px-4 pb-4 text-[11px] text-[var(--muted)] space-y-2 list-decimal list-inside leading-relaxed">
        <li>เช่า VPS Ubuntu 22.04 → ติดตั้ง Nginx + PHP 8.2-FPM + MariaDB</li>
        <li>ชี้โดเมน <code>api.yourbrand.com</code> ไป VPS (Cloudflare proxy เปิด)</li>
        <li>อัปโหลดโค้ดไป <code>/var/www/luepin/</code> (git pull หรือ rsync)</li>
        <li>ย้าย <code>config.php</code> secrets ออกจาก git → ตั้งบน server อย่างเดียว</li>
        <li>Import <code>DB setup.sql</code> → MariaDB database <code>antigravity_db</code></li>
        <li>ตั้ง Nginx SSL (Certbot) → ทดสอบ <code>https://โดเมน/webhook.php</code></li>
        <li>LINE Developers → Webhook URL = <code>https://โดเมน/webhook.php</code></li>
        <li>LINE Login → Callback = <code>https://โดเมน/auth_callback.php</code></li>
        <li>Cron สำรอง DB รายวัน → push ไป R2</li>
      </ol>
    </details>

    <p class="text-[10px] text-[var(--faint)]">ไม่แนะนำ InfinityFree สำหรับ production (จำกัด + webhook ไม่เสถียร) · ไม่แนะนำ Vercel สำหรับ PHP แบบนี้</p>
  </section>

  <!-- 3. SQL -->
  <section class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-5 mb-5">
    <h2 class="text-xs font-bold text-[var(--muted)] mb-3 flex items-center gap-1.5"><i data-lucide="database" class="w-3.5 h-3.5"></i> 3. SQL (MySQL / MariaDB)</h2>
    <table class="cost-table w-full">
      <tr><td class="text-[var(--muted)]">50 user ใช้เท่าไร</td><td>ข้อมูลเข้ารหัสใน DB · ประมาณ <strong>500MB–2GB</strong> รวม logs</td></tr>
      <tr><td class="text-[var(--muted)]">แนะนำ</td><td><strong>MariaDB บน VPS เดียวกับ PHP</strong> — ไม่ต้องจ่ายเพิ่ม</td></tr>
      <tr><td class="text-[var(--muted)]">ทางเลือกแยก (ทีหลัง)</td><td>DigitalOcean Managed MySQL ~$15/เดือน (~525 ฿) เมื่อ user &gt; 200</td></tr>
      <tr><td class="text-[var(--muted)]">ต้นทุน <?php echo $u; ?> user</td><td><strong id="sql-display">~0 ฿/เดือน</strong> (รวมใน VPS) + backup ~<?php echo $rates['backup_month']; ?> ฿</td></tr>
    </table>
  </section>

  <!-- 4. LINE OA -->
  <section class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-5 mb-5">
    <h2 class="text-xs font-bold text-[var(--muted)] mb-3 flex items-center gap-1.5"><i data-lucide="message-circle" class="w-3.5 h-3.5"></i> 4. LINE Official Account</h2>
    <p class="text-xs text-[var(--text-2)] mb-3">ระบบนี้ใช้ <strong>LINE OA กลาง 1 ตัว</strong> (token ใน config) — ทุก user คุยกับบอทตัวเดียวกัน · ข้อความรวมทุก user</p>

    <div class="flex flex-wrap gap-2 mb-3">
      <button type="button" class="plan-btn" data-plan="free">ฟรี ~0฿</button>
      <button type="button" class="plan-btn" data-plan="basic">Basic ~1,280฿</button>
      <button type="button" class="plan-btn active" data-plan="pro_th">Pro TH ~1,780฿</button>
      <button type="button" class="plan-btn" data-plan="light">Light $50 ~1,750฿</button>
      <button type="button" class="plan-btn" data-plan="standard">Standard $150</button>
    </div>

    <table class="cost-table w-full mb-3">
      <tr><td class="text-[var(--muted)]">ข้อความ LINE รวม/เดือน (ประมาณ)</td><td>user ส่งเข้า + บอทตอบ + Flex/Push · สมมติ <strong id="line-msgs-display">—</strong></td></tr>
      <tr><td class="text-[var(--muted)]">โควต้าในแพ็ก</td><td><strong id="line-quota-display">—</strong></td></tr>
      <tr><td class="text-[var(--muted)]">เกินโควต้า</td><td><strong id="line-extra-display">—</strong></td></tr>
      <tr><td class="text-[var(--muted)]">ต้นทุน LINE รวม</td><td><strong id="line-total-display">—</strong></td></tr>
    </table>

    <div class="bg-[var(--surface)] rounded-xl p-4 border border-[var(--border)] text-xs space-y-2">
      <p class="font-bold flex items-center gap-1"><i data-lucide="check-circle" class="w-3.5 h-3.5"></i> Verified (ติ๊กน้ำเงิน / เขียว)</p>
      <ul class="text-[var(--muted)] space-y-1.5">
        <li class="flex gap-2"><i data-lucide="check" class="w-3.5 h-3.5 shrink-0 text-[var(--ok)]"></i> <span><strong class="text-[var(--text)]">ยื่นผ่าน LINE OA Manager โดยตรง = ฟรี</strong> (ไทยรองรับ) — ต้องมีเอกสารนิติบุคคล/ธุรกิจ</span></li>
        <li class="flex gap-2"><i data-lucide="shield" class="w-3.5 h-3.5 shrink-0"></i> <span>ติ๊กน้ำเงิน = Verified · ค้นหาในแอป LINE ได้ · น่าเชื่อถือกว่า</span></li>
        <li class="flex gap-2"><i data-lucide="crown" class="w-3.5 h-3.5 shrink-0"></i> <span>ติ๊กเขียว = Premium tier แยกต่างหาก (ไม่ใช่แค่ verify)</span></li>
        <li class="flex gap-2"><i data-lucide="banknote" class="w-3.5 h-3.5 shrink-0"></i> <span>ผ่านเอเจนซี่ช่วยยื่น ~1,000 ฿ ครั้งเดียว (ถ้าไม่อยากยุ่งเอง)</span></li>
      </ul>
      <p class="text-[10px] text-[var(--faint)] pt-1">สร้าง OA ฟรีได้ก่อน → ใช้งานได้ → ค่อยซื้อแพ็ก + ยื่น verify เมื่อพร้อมเปิดรับลูกค้าจริง</p>
    </div>
  </section>

  <!-- เครื่องคำนวณ -->
  <section class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-5 mb-5">
    <h2 class="text-xs font-bold text-[var(--muted)] mb-4 flex items-center gap-1.5"><i data-lucide="calculator" class="w-3.5 h-3.5"></i> ปรับตัวเลขเอง</h2>
    <div class="grid sm:grid-cols-2 gap-3 mb-4 text-xs">
      <label><span class="text-[var(--muted)]">จำนวน user</span><input type="number" id="in-users" class="num-inp mt-1" value="<?php echo $rates['users_default']; ?>"></label>
      <label><span class="text-[var(--muted)]">ข้อความ AI / user / เดือน</span><input type="number" id="in-msgs" class="num-inp mt-1" value="<?php echo $rates['msgs_per_user']; ?>"></label>
      <label><span class="text-[var(--muted)]">% Reject (2 calls)</span><input type="number" id="in-reject-pct" class="num-inp mt-1" value="<?php echo $rates['reject_pct']; ?>"></label>
      <label><span class="text-[var(--muted)]">อัตราแลก USD→THB</span><input type="number" id="in-fx" class="num-inp mt-1" value="<?php echo $rates['usd_thb']; ?>" step="0.1"></label>
      <label><span class="text-[var(--muted)]">VPS บาท/เดือน</span><input type="number" id="in-vps" class="num-inp mt-1" value="<?php echo $rates['vps_month']; ?>"></label>
      <label><span class="text-[var(--muted)]">โดเมน บาท/เดือน</span><input type="number" id="in-domain" class="num-inp mt-1" value="<?php echo $rates['domain_month']; ?>"></label>
      <label><span class="text-[var(--muted)]">Backup บาท/เดือน</span><input type="number" id="in-backup" class="num-inp mt-1" value="<?php echo $rates['backup_month']; ?>"></label>
      <label><span class="text-[var(--muted)]">SQL แยก บาท/เดือน</span><input type="number" id="in-sql" class="num-inp mt-1" value="<?php echo $rates['sql_extra']; ?>"></label>
      <label><span class="text-[var(--muted)]">แผน LINE บาท/เดือน</span><input type="number" id="in-line-plan" class="num-inp mt-1" value="<?php echo $rates['line_plan']; ?>"></label>
      <label><span class="text-[var(--muted)]">โควต้า LINE ข้อความ</span><input type="number" id="in-line-quota" class="num-inp mt-1" value="<?php echo $rates['line_quota']; ?>"></label>
      <label><span class="text-[var(--muted)]">ข้อความ LINE รวม/เดือน</span><input type="number" id="in-line-msgs" class="num-inp mt-1" value="<?php echo $rates['line_msgs_total']; ?>"></label>
      <label><span class="text-[var(--muted)]">ค่าข้อความเกินโควต้า (฿)</span><input type="number" id="in-line-extra-fee" class="num-inp mt-1" value="<?php echo $rates['line_extra_fee']; ?>" step="0.01"></label>
    </div>
    <div class="bg-[var(--surface)] rounded-xl p-4 border border-[var(--border)] space-y-1.5 text-sm" id="calc-breakdown"></div>
  </section>

  <!-- กำไร -->
  <section class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-5 mb-5">
    <h2 class="text-xs font-bold text-[var(--muted)] mb-3 flex items-center gap-1.5"><i data-lucide="trending-up" class="w-3.5 h-3.5"></i> กำไรขั้นต้น</h2>
    <div class="grid sm:grid-cols-3 gap-3 mb-3 text-xs">
      <label><span class="text-[var(--muted)]">% Starter</span><input type="number" id="in-pct-starter" class="num-inp mt-1" value="60"></label>
      <label><span class="text-[var(--muted)]">ราคา Starter</span><input type="number" id="in-price-starter" class="num-inp mt-1" value="399"></label>
      <label><span class="text-[var(--muted)]">ราคา Pro</span><input type="number" id="in-price-pro" class="num-inp mt-1" value="599"></label>
    </div>
    <div class="bg-[var(--surface)] rounded-xl p-4 border border-[var(--border)] space-y-2 text-sm">
      <div class="flex justify-between"><span class="text-[var(--muted)]">รายได้รวม</span><span id="out-revenue" class="font-bold">—</span></div>
      <div class="flex justify-between"><span class="text-[var(--muted)]">ต้นทุนรวม</span><span id="out-cost" class="font-bold">—</span></div>
      <div class="flex justify-between border-t border-[var(--border)] pt-2"><span class="font-bold">กำไรขั้นต้น</span><span id="out-margin" class="font-bold text-lg">—</span></div>
      <div class="flex justify-between text-xs"><span class="text-[var(--faint)]">% margin</span><span id="out-margin-pct">—</span></div>
    </div>
  </section>

  <!-- ครั้งเดียว -->
  <section class="bg-[var(--surface)] border border-[var(--border)] rounded-2xl p-5">
    <h2 class="text-xs font-bold text-[var(--muted)] mb-2 flex items-center gap-1.5"><i data-lucide="receipt" class="w-3.5 h-3.5"></i> ค่าใช้จ่ายครั้งเดียว (ประมาณ)</h2>
    <ul class="text-xs text-[var(--muted)] space-y-1">
      <li>โดเมนปีแรก ~600–900 ฿</li>
      <li>ยื่น Verified OA ผ่าน LINE โดยตรง — ฟรี (ใช้เวลารอ review)</li>
      <li>เอเจนซี่ช่วย verify ~1,000 ฿ (ถ้าจ้าง)</li>
      <li>Premium ID (@custom) — ราคาแยก ตาม LINE</li>
      <li>Google Cloud / OpenAI — ไม่มีค่าเปิดบัญชี · เติมเงินตามใช้</li>
    </ul>
  </section>

</div>

<script>
const PLANS = {
  free:    { plan: 0,    quota: 500,   label: 'ฟรี' },
  basic:   { plan: 1280, quota: 5000,  label: 'Basic TH' },
  pro_th:  { plan: 1780, quota: 15000, label: 'Pro TH' },
  light:   { plan: 1750, quota: 10000, label: 'Light $50' },
  standard:{ plan: 5250, quota: 40000, label: 'Standard $150' },
};
const RATES = {
  openaiIn: <?php echo $rates['openai_in']; ?>,
  openaiOut: <?php echo $rates['openai_out']; ?>,
  tokensNormal: { in: 800, out: 200 },
  tokensReject: { in: 1600, out: 500 },
};

function fmt(n, dec=0) {
  if (n > 0 && n < 1) return '< ฿1';
  return '฿' + n.toLocaleString('th-TH', { minimumFractionDigits: dec, maximumFractionDigits: dec });
}
function num(id) { return Math.max(0, parseFloat(document.getElementById(id)?.value) || 0); }

function tokenCostThb(tIn, tOut, fx) {
  return (tIn/1e6*RATES.openaiIn + tOut/1e6*RATES.openaiOut) * fx;
}

function recalc() {
  const fx = num('in-fx');
  const users = num('in-users');
  const msgs = num('in-msgs');
  const rejectPct = num('in-reject-pct') / 100;
  const vps = num('in-vps'), domain = num('in-domain'), backup = num('in-backup'), sql = num('in-sql');
  const linePlan = num('in-line-plan'), lineQuota = num('in-line-quota');
  const lineMsgs = num('in-line-msgs'), lineExtraFee = num('in-line-extra-fee');

  const cN = tokenCostThb(RATES.tokensNormal.in, RATES.tokensNormal.out, fx);
  const cR = tokenCostThb(RATES.tokensReject.in, RATES.tokensReject.out, fx);
  document.getElementById('cost-normal').textContent = fmt(cN, 2) + ' / ข้อความ';
  document.getElementById('cost-reject').textContent = fmt(cR, 2) + ' / เคส';

  const totalCalls = users * msgs;
  const ai = (totalCalls * (1-rejectPct) * cN) + (totalCalls * rejectPct * cR);
  const cloud = vps + domain + backup;
  const sqlCost = sql;
  const lineExtraCnt = Math.max(0, lineMsgs - lineQuota);
  const line = linePlan + lineExtraCnt * lineExtraFee;
  const total = ai + cloud + sqlCost + line;

  document.getElementById('sum-cloud').textContent = fmt(cloud);
  document.getElementById('sum-sql').textContent = fmt(sqlCost);
  document.getElementById('sum-ai').textContent = fmt(ai);
  document.getElementById('sum-line').textContent = fmt(line);
  document.getElementById('sum-total').textContent = fmt(total);

  document.getElementById('line-msgs-display').textContent = lineMsgs.toLocaleString() + ' ข้อความ';
  document.getElementById('line-quota-display').textContent = lineQuota.toLocaleString() + ' ข้อความ';
  document.getElementById('line-extra-display').textContent = lineExtraCnt > 0
    ? lineExtraCnt.toLocaleString() + ' ข้อความ × ฿' + lineExtraFee + ' ≈ ' + fmt(lineExtraCnt * lineExtraFee)
    : 'ไม่เกินโควต้า';
  document.getElementById('line-total-display').textContent = fmt(line) + '/เดือน';
  document.getElementById('sql-display').textContent = fmt(sqlCost) + '/เดือน (backup ' + fmt(backup) + ')';

  document.getElementById('calc-breakdown').innerHTML = `
    <div class="flex justify-between"><span class="text-[var(--muted)]">☁️ Cloud (VPS+โดเมน+backup)</span><span class="font-bold">${fmt(cloud)}</span></div>
    <div class="flex justify-between"><span class="text-[var(--muted)]">🗄️ SQL แยก</span><span class="font-bold">${fmt(sqlCost)}</span></div>
    <div class="flex justify-between"><span class="text-[var(--muted)]">🤖 AI (${totalCalls.toLocaleString()} calls)</span><span class="font-bold">${fmt(ai)}</span></div>
    <div class="flex justify-between"><span class="text-[var(--muted)]">💬 LINE (แพ็ก ${fmt(linePlan)} + เกินโควต้า)</span><span class="font-bold">${fmt(line)}</span></div>
    <div class="flex justify-between border-t border-[var(--border)] pt-2 mt-1"><span class="font-bold">รวม</span><span class="font-bold text-lg">${fmt(total)}</span></div>`;

  const pctS = num('in-pct-starter')/100;
  const rev = users * (pctS*num('in-price-starter') + (1-pctS)*num('in-price-pro'));
  const margin = rev - total;
  document.getElementById('out-revenue').textContent = fmt(rev);
  document.getElementById('out-cost').textContent = fmt(total);
  document.getElementById('out-margin').textContent = fmt(margin);
  document.getElementById('out-margin-pct').textContent = rev > 0 ? (margin/rev*100).toFixed(1)+'%' : '—';
}

document.querySelectorAll('.plan-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.plan-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const p = PLANS[btn.dataset.plan];
    if (p) {
      document.getElementById('in-line-plan').value = p.plan;
      document.getElementById('in-line-quota').value = p.quota;
      recalc();
    }
  });
});

document.querySelectorAll('input').forEach(el => el.addEventListener('input', recalc));
recalc();
if (window.lucide) lucide.createIcons();
</script>
</body>
</html>
