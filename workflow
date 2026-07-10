# Workflow Notes

## Future Feature: Upload Listing Photos to Google Drive

Goal:
- Let users upload property photos and store them in Google Drive.
- Organize photos under a main folder named `House pic`.
- Auto-create a subfolder per property/listing code, e.g. `House pic/NING001`.

Option A: Upload Photos Through LINE Chat
- User sends a setup message such as `อัปโหลดรูป รหัส NING001`.
- Webhook stores the current upload context for that LINE user.
- User sends image messages in LINE.
- Webhook receives `message.type = image`.
- Backend downloads the image from LINE Content API using the LINE `message.id`.
- Backend finds or creates Google Drive folder `House pic`.
- Backend finds or creates subfolder `NING001`.
- Backend uploads the image into `House pic/NING001`.
- Backend stores metadata in DB, such as `user_id`, `owner_code`, `drive_file_id`, `drive_url`, `created_at`.

Pros:
- User can upload directly from LINE.
- Good for MVP and quick testing.

Cons / Risks:
- LINE may compress images, so original photo quality may not be preserved.
- Need careful logic to bind images to the correct property code.
- Google Drive auth/upload still needs to be implemented.

Option B: Upload Photos Through LIFF / Webapp
- User opens a LIFF or web upload page.
- User selects property code, e.g. `NING001`.
- User selects one or many photos from the device.
- Backend creates or finds `House pic/NING001`.
- Backend uploads selected files to Google Drive.
- Backend stores Drive metadata in DB.

Pros:
- Better control over UX.
- Easier to bind photos to the correct property.
- Better for multi-image upload.
- Better chance to preserve image quality because files are uploaded directly from browser/backend, not through LINE chat compression.
- Keeps `webhook.php` simpler.

Cons:
- Requires building an upload page.
- If photos must be uploaded into each user's own Google Drive account, Google OAuth is still required.

Google Drive Auth Choices:
- True user-owned upload: use Google OAuth per user and store refresh/access tokens securely.
- Easier MVP: ask user to share a Drive folder with a system/service account, then backend uploads into that shared folder. This is simpler, but the uploader/owner may be the system account, not the user's account.

Recommendation:
- For real production usage, prefer LIFF/Webapp upload.
- For quick MVP, LINE image upload can work, but accept possible image compression and build a clear property-code context step first.

---

## LINE: เพิ่มโครงการ Survey (ทีละข้อความ/รูป/พิกัด)

**สถานะ:** ใช้งานแล้ว (MVP)  
**โค้ด:** `project_survey_flow.php` + `webhook.php`  
**ผลลัพธ์:** บันทึกลง `project_surveys` → แสดงจุดบนแผนที่ (แท็บ โครงการ) + รายละเอียดเต็มใน bottom sheet

### เริ่ม / ยกเลิก

| คำสั่ง | ผล |
|--------|-----|
| `เพิ่มโครงการ`, `โครงการใหม่`, `เพิ่ม survey`, `add project` | เริ่ม flow (ถ้ามี draft ค้างจะเริ่มใหม่) |
| `ยกเลิก`, `cancel` | ลบ draft แล้วหยุด |

Draft เก็บใน `project_survey_drafts` (หนึ่ง user หนึ่ง draft)

### Flowchart

```mermaid
flowchart TD
  A["พิมพ์ เพิ่มโครงการ"] --> B["1 ชื่อไทย (text)"]
  B --> C["2 ชื่อ EN (text, ข้ามได้)"]
  C --> D["3 Developer (text)"]
  D --> E["4 Segment (ปุ่ม Economy … Super Luxury class)"]
  E --> F["5 ประเภท (ปุ่ม Condo / House)"]
  F --> G["6 ตำแหน่ง (ส่ง Location 📍)"]
  G --> H["7 รูปปก (image หรือข้าม)"]
  H --> I["8 จำนวนยูนิต (ตัวเลข)"]
  I --> J["9 เฟส (ตัวเลข, ข้ามได้)"]
  J --> K["10 ปีเปิดตัว (ตัวเลข, ข้ามได้)"]
  K --> L["11 ค่าส่วนกลาง บ./ปี (ตัวเลข, ข้ามได้)"]
  L --> M["12 สิ่งอำนวยความสะดวก (คั่นจุลภาค → พอแล้ว)"]
  M --> N["+ ใกล้เคียง ชื่อ|ระยะทาง (ข้ามได้)"]
  N --> O["+ แบบบ้าน/ยูนิตหลัก (ข้ามได้)"]
  O --> P["สรุป → ปุ่ม บันทึก / ยกเลิก"]
  P --> Q["INSERT project_surveys"]
  Q --> R["ดูบน dashboard.php#map แท็บ โครงการ"]

  X["ยกเลิก ทุกขั้น"] -.-> Z["ลบ draft"]
```

### ขั้นตอนละเอียด

| # | Step key | รับอะไร | หมายเหตุ |
|---|----------|---------|----------|
| 1 | `name_th` | ข้อความ | บังคับ ≥ 2 ตัวอักษร |
| 2 | `name_en` | ข้อความ | `ข้าม` ได้ |
| 3 | `developer` | ข้อความ | |
| 4 | `segment` | Postback / Quick Reply | Economy class, Main class, Upper class, High class, Luxury class, Super Luxury class |
| 5 | `property_type` | Postback / Quick Reply | Condo, House |
| 6 | `location` | LINE Location message | บังคับ lat/lng |
| 7 | `cover` | LINE image หรือ `ข้าม` | ดาวน์โหลดเก็บ `uploads/projects/` |
| 8 | `total_units` | ตัวเลข | |
| 9 | `phases` | ตัวเลข | `ข้าม` ได้ |
| 10 | `launch_year` | ตัวเลข | `ข้าม` ได้ |
| 11 | `common_fee` | ตัวเลข | `ข้าม` ได้ |
| 12 | `amenities` | ข้อความคั่นจุลภาค | พิมพ์ `พอแล้ว` เมื่อครบ |
| + | `nearby` | `ชื่อ\|ระยะทาง` คั่นจุลภาค | `ข้าม` ได้ |
| + | `unit_hint` | ข้อความสั้นๆ | `ข้าม` ได้ — parse ราคา/ตร.ม./นอน |
| ✓ | `confirm` | Postback บันทึก / ยกเลิก | สรุปก่อน INSERT |

### หลังบันทึก

- สร้าง `project_slug` อัตโนมัติจากชื่อไทย + user_id
- ตอบกลับ LINE พร้อมลิงก์ `dashboard.php#map`
- แผนที่: ชั้น **โครงการ** + ค้นหา CRM + ฟิลเตอร์ Segment + สไลเดอร์ห้องนอน/น้ำ
- รายละเอียดเต็มแสดงใน **bottom sheet** (ไม่เปิด `project.php`)

### ข้อกำหนดระบบ

- Webhook URL ต้องเข้าถึงได้จาก LINE
- `LINE_ACCESS_TOKEN` ใน `config.php`
- โฟลเดอร์ `uploads/projects/` ต้องเขียนไฟล์ได้ (รูปปกจาก LINE)
- ขณะมี draft อยู่ ข้อความ text/image/location จะถูก route เข้า flow ก่อน AI ลีด

