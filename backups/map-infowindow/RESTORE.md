# Backup: แผนที่แบบ Info Window (ก่อน Bottom Sheet)

สำรองเมื่อ: 2026-06-14

## ไฟล์ในโฟลเดอร์นี้

| ไฟล์ | คำอธิบาย |
|------|----------|
| `map-page.js` | JS แผนที่เวอร์ชัน info window เต็ม |
| `map-section.php` | HTML ส่วน `#page-map` ใน dashboard (เวอร์ชันเก่า) |

## วิธีกู้คืน

1. คัดลอก `map-page.js` ไปทับ `map-page.js` ที่ root โปรเจกต์
2. เปิด `map-section.php` แล้วแทนที่บล็อก `<!-- ===== หน้า Map` ถึง `</section>` ก่อน Price Report ใน `dashboard.php`
3. ใน `map_build_payload()` ลบฟิลด์ `contact_date` / `contact_label` ออกจาก lead items (ถ้ามี)
4. รีเฟรชหน้า dashboard แท็บแผนที่

## หมายเหตุ

เวอร์ชันใหม่ใช้แผงรายละเอียดล่างแผนที่ + กรองวันที่ Lead (contact_date) แทน Google InfoWindow
