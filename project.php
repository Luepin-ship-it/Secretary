<?php
// project.php — หน้ารายละเอียดโครงการ (Survey) แยกจาก Map CRM
require_once 'config.php';
require_once 'auth.php';
require_once 'task_helpers.php';

auth_require_registration($conn);
$user = auth_current_user($conn);

task_ensure_schema($conn);

$user_id = (int)$user['id'];
$slug = trim($_GET['slug'] ?? '');

if ($slug === '') {
    header('Location: dashboard.php#map');
    exit();
}

$stmt = $conn->prepare("SELECT * FROM project_surveys WHERE user_id = ? AND project_slug = ? LIMIT 1");
$stmt->bind_param("is", $user_id, $slug);
$stmt->execute();
$p = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$p) {
    http_response_code(404);
    $title = 'ไม่พบโครงการ';
} else {
    $title = ($p['name_th'] ?? '') ?: ($p['name_en'] ?? 'โครงการ');
}

$amenities = json_decode($p['amenities_json'] ?? '[]', true) ?: [];
$nearby = json_decode($p['nearby_json'] ?? '[]', true) ?: [];
$units = json_decode($p['units_json'] ?? '[]', true) ?: [];
$fee_txt = $p['common_fee'] !== null ? number_format((float)$p['common_fee'], 2) . ' บ.' : '-';
$fee_period = ($p['fee_period'] ?? 'yearly') === 'yearly' ? 'รายปี' : ($p['fee_period'] ?? '');
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?php echo htmlspecialchars($title); ?> · ข้อมูลโครงการ</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <style>
    :root {
      --bg: #141414; --card: #1e1e1e; --border: #2e2e2e; --text: #f4f4f5;
      --muted: #a1a1aa; --accent: #E2E800;
    }
    body { background: var(--bg); color: var(--text); font-family: system-ui, sans-serif; }
  </style>
</head>
<body class="min-h-screen pb-8">
  <header class="sticky top-0 z-10 bg-[#141414]/95 border-b border-[var(--border)] px-4 py-3 flex items-center gap-3">
    <a href="dashboard.php#map" class="w-9 h-9 rounded-full border border-[var(--border)] flex items-center justify-center">
      <i data-lucide="arrow-left" class="w-4 h-4"></i>
    </a>
    <div class="min-w-0 flex-1">
      <h1 class="text-sm font-bold truncate"><?php echo htmlspecialchars($title); ?></h1>
      <p class="text-[11px] text-[var(--muted)]">ข้อมูลโครงการ · Survey</p>
    </div>
  </header>

  <?php if (!$p): ?>
    <div class="px-5 py-20 text-center text-[var(--muted)]">
      <i data-lucide="map-pin-off" class="w-10 h-10 mx-auto mb-3 opacity-40"></i>
      <p>ไม่พบข้อมูลโครงการนี้</p>
      <a href="dashboard.php#map" class="inline-block mt-4 text-sm font-bold text-[var(--accent)]">กลับแผนที่</a>
    </div>
  <?php else: ?>
    <div class="max-w-md mx-auto px-5 pt-4 space-y-4">
      <?php if (!empty($p['cover_image_url'])): ?>
        <div class="rounded-2xl overflow-hidden border border-[var(--border)] aspect-video bg-[var(--card)]">
          <img src="<?php echo htmlspecialchars($p['cover_image_url']); ?>" alt="" class="w-full h-full object-cover">
        </div>
      <?php endif; ?>

      <div class="flex flex-wrap gap-2">
        <?php if (!empty($p['segment'])): ?>
          <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-1 rounded-full bg-pink-500/15 text-pink-300">
            <i data-lucide="gem" class="w-3 h-3"></i><?php echo htmlspecialchars($p['segment']); ?>
          </span>
        <?php endif; ?>
        <?php if (!empty($p['developer'])): ?>
          <span class="text-[10px] px-2 py-1 rounded-full bg-[var(--card)] border border-[var(--border)] text-[var(--muted)]">
            Dev: <?php echo htmlspecialchars($p['developer']); ?>
          </span>
        <?php endif; ?>
      </div>

      <?php if (!empty($p['name_en'])): ?>
        <p class="text-xs text-[var(--muted)]"><?php echo htmlspecialchars($p['name_en']); ?></p>
      <?php endif; ?>

      <div class="grid grid-cols-2 gap-2 bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 text-sm">
        <div><p class="text-[10px] text-[var(--muted)]">ยูนิตทั้งหมด</p><p class="font-bold"><?php echo (int)$p['total_units']; ?></p></div>
        <div><p class="text-[10px] text-[var(--muted)]">จำนวนเฟส</p><p class="font-bold"><?php echo (int)$p['phases']; ?></p></div>
        <div><p class="text-[10px] text-[var(--muted)]">ปีเปิดตัว</p><p class="font-bold"><?php echo $p['launch_year'] ?: '-'; ?></p></div>
        <div><p class="text-[10px] text-[var(--muted)]">ค่าส่วนกลาง</p><p class="font-bold"><?php echo $fee_txt; ?> <span class="text-[10px] font-normal text-[var(--muted)]"><?php echo $fee_period; ?></span></p></div>
        <div class="col-span-2"><p class="text-[10px] text-[var(--muted)]">ประเภท</p><p class="font-bold"><?php echo htmlspecialchars($p['property_type'] ?? '-'); ?></p></div>
      </div>

      <?php if ($amenities): ?>
        <section>
          <h2 class="text-xs font-bold text-[var(--muted)] mb-2 flex items-center gap-1"><i data-lucide="sparkles" class="w-3.5 h-3.5"></i>สิ่งอำนวยความสะดวก</h2>
          <div class="flex flex-wrap gap-2">
            <?php foreach ($amenities as $a): ?>
              <span class="text-[10px] px-2.5 py-1 rounded-full border border-emerald-500/30 bg-emerald-500/10 text-emerald-300"><?php echo htmlspecialchars(is_array($a) ? ($a['name'] ?? '') : $a); ?></span>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($nearby): ?>
        <section class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4">
          <h2 class="text-xs font-bold text-[var(--muted)] mb-2 flex items-center gap-1"><i data-lucide="map-pin" class="w-3.5 h-3.5"></i>สถานที่ใกล้เคียง</h2>
          <ul class="space-y-2 text-sm">
            <?php foreach ($nearby as $n): ?>
              <li class="flex justify-between border-b border-dashed border-[var(--border)] pb-2 last:border-0 last:pb-0">
                <span><?php echo htmlspecialchars($n['name'] ?? ''); ?></span>
                <span class="text-[var(--muted)]"><?php echo htmlspecialchars($n['distance'] ?? ''); ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <?php if ($units): ?>
        <section class="space-y-3">
          <h2 class="text-xs font-bold text-[var(--muted)] flex items-center gap-1"><i data-lucide="home" class="w-3.5 h-3.5"></i>แบบบ้าน / ยูนิต</h2>
          <?php foreach ($units as $u): ?>
            <div class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 text-sm space-y-2">
              <div class="flex justify-between items-start">
                <p class="font-bold"><?php echo htmlspecialchars($u['name'] ?? 'ยูนิต'); ?></p>
                <?php if (!empty($u['date'])): ?><span class="text-[10px] text-[var(--muted)]"><?php echo htmlspecialchars($u['date']); ?></span><?php endif; ?>
              </div>
              <div class="grid grid-cols-2 gap-2 text-xs text-[var(--muted)]">
                <?php if (!empty($u['land'])): ?><span>ที่ดิน <?php echo htmlspecialchars($u['land']); ?></span><?php endif; ?>
                <?php if (!empty($u['living'])): ?><span>ใช้สอย <?php echo htmlspecialchars($u['living']); ?></span><?php endif; ?>
                <?php if (!empty($u['bed'])): ?><span><?php echo (int)$u['bed']; ?> นอน</span><?php endif; ?>
                <?php if (!empty($u['bath'])): ?><span><?php echo (int)$u['bath']; ?> น้ำ</span><?php endif; ?>
              </div>
              <?php if (!empty($u['price_open'])): ?>
                <p class="text-emerald-400 font-bold">ราคาเปิด <?php echo number_format((int)$u['price_open']); ?></p>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>

      <p class="text-[10px] text-[var(--muted)] text-center pt-4">ข้อมูล Survey — แยกจาก Owner/Lead บนแผนที่ CRM</p>
    </div>
  <?php endif; ?>
  <script>lucide.createIcons();</script>
</body>
</html>
