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
  <title>นโยบายข้อมูล — LUEPiN</title>
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

  <main class="max-w-2xl mx-auto px-4 py-8 pb-16 prose prose-sm prose-gray">
    <p class="text-xs text-gray-500 not-prose">ฉบับ <?php echo htmlspecialchars($version); ?> · เลขา AI / LUEPiN</p>
    <h1 class="text-2xl font-bold not-prose mb-2">นโยบายข้อมูลและความเป็นส่วนตัว</h1>
    <p class="text-gray-600 not-prose text-sm leading-relaxed mb-8">
      เอกสารนี้อธิบายว่าเราเก็บ ใช้ และปกป้องข้อมูลของคุณอย่างไร ภายใต้ PDPA
    </p>

    <section class="space-y-6 text-sm leading-relaxed text-gray-700">
      <div>
        <h2 class="text-base font-bold text-gray-900 mb-2">1. ข้อมูลเป็นของใคร</h2>
        <p>Lead · Stock · Project · Task รายงานงาน และข้อมูลที่<strong>คุณ</strong>บันทึก เป็น<strong>ของคุณ</strong> — ทุกสาขาสายงาน LUEPiN เป็นผู้ให้บริการแพลตฟอร์ม (ผู้ประมวลผล) ไม่ใช่เจ้าของเนื้อหาธุรกิจของคุณ</p>
      </div>
      <div>
        <h2 class="text-base font-bold text-gray-900 mb-2">2. แยกตามบัญชี</h2>
        <p>ข้อมูลถูกแยกตาม <strong>LINE ID</strong> ของคุณ ผู้ใช้อื่นในระบบ<strong>ไม่เห็น</strong>ข้อมูลของคุณ สาขาสายงาน (เช่น อสังหาริมทรัพย์ / เมทัลชีท) เปลี่ยนแค่เมนูและฟีเจอร์ ไม่ได้แชร์ข้อมูลข้ามคน หากองค์กรต้องการใช้งานร่วมกันในระดับบริษัท ติดต่อแอดมินผ่าน LINE ของเลขา AI</p>
      </div>
      <div>
        <h2 class="text-base font-bold text-gray-900 mb-2">3. ทีมพัฒนาเห็นอะไร</h2>
        <p>ในกระบวนการปกติ ทีม LUEPiN <strong>ไม่เปิดดูข้อมูลธุรกิจที่ถอดรหัสแล้ว</strong> (ชื่อลูกค้า เบอร์ ที่อยู่ ฯลฯ) การพัฒนาระบบใช้เฉพาะข้อมูลที่<strong>ปิดบังหรือสังเคราะห์</strong> เช่น รหัส Lead แทนชื่อจริง</p>
      </div>
      <div>
        <h2 class="text-base font-bold text-gray-900 mb-2">4. การเข้ารหัสและความปลอดภัย</h2>
        <p>ฟิลด์สำคัญถูกเข้ารหัสด้วยคีย์เฉพาะต่อบัญชี การเชื่อมต่อเว็บใช้ HTTPS คุณจัดการข้อมูลผ่าน Dashboard — ไม่ต้องเข้า Backend หรือฐานข้อมูลโดยตรง</p>
      </div>
      <div>
        <h2 class="text-base font-bold text-gray-900 mb-2">5. ระยะเวลาเก็บข้อมูล</h2>
        <ul class="list-disc pl-5 space-y-1">
          <li>ขณะใช้งานหรือสมาชิกที่ต่ออายุ — ดูย้อนหลังได้ <strong>1 ปี</strong></li>
          <li>ต้องการเก็บนานกว่านี้ — ติดต่อแอดมินผ่าน LINE</li>
          <li>ไม่ต่ออายุ — ระบบปิด แต่ยังดูและส่งออกข้อมูลได้ <strong>3 เดือน</strong> ก่อนลบจากเซิร์ฟเวอร์ของเรา</li>
        </ul>
      </div>
      <div>
        <h2 class="text-base font-bold text-gray-900 mb-2">6. ความยินยอม</h2>
        <p><strong>บังคับ:</strong> เก็บและประมวลผลข้อมูลเพื่อให้บริการแอปส่วนตัวของคุณ</p>
        <p class="mt-2"><strong>ทางเลือก:</strong> ส่งข้อความที่ตัดข้อมูลระบุตัวตนไป AI ภายนอก (เช่น Gemini) — ปิดได้ในการตั้งค่า</p>
      </div>
      <div>
        <h2 class="text-base font-bold text-gray-900 mb-2">7. สิทธิของคุณ (PDPA)</h2>
        <p>เข้าถึง · แก้ไข · ส่งออก · ขอลบ · ถอนความยินยอม — ติดต่อเราผ่านช่องทางที่ระบุในแอป (กำลังจัดทำ)</p>
      </div>
      <div>
        <h2 class="text-base font-bold text-gray-900 mb-2">8. ความรับผิดชอบของคุณ</h2>
        <p>เมื่อบันทึกข้อมูลลูกค้า คุณต้องมีฐานทางกฎหมายในการเก็บข้อมูลนั้น (เช่น ความยินยอมจากลูกค้า) คุณเป็น Data Controller ต่อข้อมูลลูกค้าของคุณ</p>
      </div>
    </section>

    <p class="text-xs text-gray-400 mt-10 not-prose">
      เอกสารฉบับเต็มสำหรับทีมงาน: docs/data-policy.md ·
      <a href="<?php echo htmlspecialchars($urls['terms']); ?>" class="underline">ข้อตกลงการใช้งาน</a>
    </p>
  </main>
</body>
</html>
