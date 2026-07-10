# Secretary - AI Agent LINE OA

ระบบ AI Assistant ผ่าน LINE Official Account พร้อมระบบเชื่อมต่อฐานข้อมูล การจัดการรูปภาพผ่าน Google Drive และการค้นหาตำแหน่งด้วย Google Maps API

## ฟีเจอร์หลัก
* **AI Agent (OpenAI)**: ระบบตอบกลับอัตโนมัติที่ฉลาดขึ้นด้วย AI
* **LINE LIFF Integration**: รองรับการเปิดหน้าเว็บซ้อนใน LINE (เช่น ค้นหาโครงการ, อัปโหลดรูป)
* **Google Maps API**: ค้นหาพิกัดและแสดงแผนที่
* **Google Drive API**: สำหรับอัปโหลดและจัดการไฟล์รูปภาพ

## การตั้งค่า (Setup)
เพื่อให้ระบบทำงานได้สมบูรณ์ จำเป็นต้องตั้งค่าตัวแปรในไฟล์ `config.local.php` (ไฟล์นี้ไม่ถูกบันทึกบน Git เพื่อความปลอดภัย)

1. คัดลอกไฟล์ `config.local.example.php` แล้วเปลี่ยนชื่อเป็น `config.local.php` (หรือสร้างไฟล์ใหม่)
2. กรอกข้อมูล API Keys ของคุณ:
   * `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`
   * `LINE_ACCESS_TOKEN`
   * `LINE_LOGIN_CHANNEL_ID`, `LINE_LOGIN_CHANNEL_SECRET`
   * `LINE_LIFF_ID` และ ID ต่างๆ
   * `OPENAI_API_KEY`
   * `GOOGLE_MAPS_API_KEY`
   * `GOOGLE_DRIVE_API_KEY`

## โครงสร้างระบบ
* ใช้ PHP และ MySQL
* เชื่อมต่อ Webhook เพื่อรับข้อความจากผู้ใช้งานผ่านแอปพลิเคชัน LINE
