<?php
require_once 'config.php';
require_once 'auth.php';
require_once 'brand_mark.php';
require_once 'policy_lib.php';

$urls = policy_page_urls();
$version = LUEPIN_POLICY_VERSION;
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ข้อตกลงการใช้งาน — LUEPiN</title>
  <script src="https://cdn.tailwindcss.com/3.4.17"></script>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Noto+Sans+Thai:wght@400;500;700&display=swap" rel="stylesheet">
  <link href="<?php echo htmlspecialchars(brand_mark_font_link()); ?>" rel="stylesheet">
  <style>body { font-family: 'DM Sans', 'Noto Sans Thai', sans-serif; }</style>
</head>
<body class="bg-gray-50 text-gray-900 min-h-screen">
  <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
    <div class="max-w-2xl mx-auto px-4 py-4 flex items-center justify-between gap-3">
      <a href="javascript:history.back()" class="text-sm text-gray-600 hover:text-gray-900">← กลับ</a>
      <?php render_luepin_mark('sm', 'light'); ?>
    </div>
  </header>

  <main class="max-w-2xl mx-auto px-4 py-8 pb-16 text-sm leading-relaxed text-gray-700 space-y-6">
    <p class="text-xs text-gray-500">ฉบับ <?php echo htmlspecialchars($version); ?></p>
    <h1 class="text-2xl font-bold text-gray-900">ข้อตกลงการใช้งาน (Terms of Service)</h1>

    <section>
      <h2 class="font-bold text-gray-900 mb-2">1. บริการ</h2>
      <p>LUEPiN (เลขา AI) ให้บริการ CRM และผู้ช่วย AI ผ่าน LINE และ Dashboard สำหรับงานขาย ตามสาขาสายงานที่คุณเลือกตอนลงทะเบียน</p>
    </section>
    <section>
      <h2 class="font-bold text-gray-900 mb-2">2. บัญชี</h2>
      <p>บัญชีผูกกับ LINE ID ของคุณ คุณรับผิดชอบการใช้งานภายใต้บัญชีของตนเอง</p>
    </section>
    <section>
      <h2 class="font-bold text-gray-900 mb-2">3. ข้อมูลและทรัพย์สินทางปัญญา</h2>
      <p>เนื้อหาที่คุณบันทึก (Lead, งาน, รายงาน ฯลฯ) เป็นของคุณ เราไม่มีสิทธิ์อ้างความเป็นเจ้าของเนื้อหานั้น โค้ดและแบรนด์ LUEPiN เป็นของผู้ให้บริการ</p>
    </section>
    <section>
      <h2 class="font-bold text-gray-900 mb-2">4. การใช้งานที่ยอมรับได้</h2>
      <p>ห้ามใช้บริการในทางที่ผิดกฎหมาย ส่งสแปม หรือพยายามเข้าถึงข้อมูลผู้ใช้อื่น</p>
    </section>
    <section>
      <h2 class="font-bold text-gray-900 mb-2">5. แพ็กเกจและการต่ออายุ</h2>
      <p>มีช่วงทดลองและแพ็กเกจตามที่แจ้งในแอป หากไม่ต่ออายุ การใช้งานหลักจะถูกจำกัดตาม<a href="<?php echo htmlspecialchars($urls['privacy']); ?>" class="underline text-gray-900">นโยบายข้อมูล</a></p>
    </section>
    <section>
      <h2 class="font-bold text-gray-900 mb-2">6. การเปลี่ยนแปลง</h2>
      <p>เราอาจปรับข้อตกลงนี้ โดยแจ้งผ่านแอปหรือ LINE การใช้งานต่อหลังแจ้งเปลี่ยนแปลงถือว่ายอมรับในกรณีที่กฎหมายอนุญาต</p>
    </section>

    <p class="text-xs text-gray-400 pt-4">
      อ่านร่วมกับ <a href="<?php echo htmlspecialchars($urls['privacy']); ?>" class="underline">นโยบายข้อมูลและความเป็นส่วนตัว</a>
    </p>
  </main>
</body>
</html>
