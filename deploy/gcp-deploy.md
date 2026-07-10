# Deploy ขึ้น Google Cloud (Compute Engine)

แอปนี้: **PHP + MySQL + LINE Webhook** — ใช้ VM 1 ตัว (ไม่ใช้ Cloud SQL ตอนนี้)

## 1. สมัคร GCP + ตั้ง Budget

1. [Google Cloud Console](https://console.cloud.google.com) → สมัคร → ผูกบัตร → รับ **$300 credit / 90 วัน**
2. **Billing → Budgets & alerts** → ตั้งแจ้งเตือนที่ **$10** และ **$50**

## 2. สร้าง VM

| การตั้งค่า | แนะนำ |
|-----------|--------|
| Region | **asia-southeast1** (Singapore) |
| Machine | **e2-small** (2 vCPU, 2 GB) — พอแฟน + เซลล์ไม่กี่คน |
| OS | **Debian 12** หรือ Ubuntu 22.04 |
| Boot disk | 20–30 GB |
| Firewall | เปิด **HTTP (80)** และ **HTTPS (443)** |

**Startup script:** วางเนื้อหา `deploy/gcp-startup.sh` ในช่อง Startup script ตอนสร้าง VM

หรือ SSH เข้าแล้วรัน:

```bash
sudo bash /var/www/antigravity/deploy/gcp-startup.sh
```

## 3. อัปโหลดโค้ด

จากเครื่อง Windows (PowerShell) — แทน `YOUR_VM_IP`:

```powershell
scp -r "D:\xampp\htdocs\Ai agent Line OA\*" user@YOUR_VM_IP:/tmp/app/
```

บน VM:

```bash
sudo rsync -a --exclude '.chrome-test' --exclude 'line_webhook_debug.log' /tmp/app/ /var/www/antigravity/
sudo chown -R www-data:www-data /var/www/antigravity
```

## 4. ย้าย Database จาก XAMPP

บน Windows:

```powershell
D:\xampp\mysql\bin\mysqldump.exe -u root antigravity_db > antigravity_db.sql
scp antigravity_db.sql user@YOUR_VM_IP:/tmp/
```

บน VM:

```bash
mysql -u antigravity -p antigravity_db < /tmp/antigravity_db.sql
```

## 5. config.local.php

```bash
cd /var/www/antigravity
sudo cp config.local.example.php config.local.php
sudo nano config.local.php
```

ใส่รหัส DB จาก startup script + API keys จาก `config.php` เดิม

## 6. SSL (HTTPS — LINE ต้องการ)

ถ้ามีโดเมน ชี้ A record ไป IP ของ VM:

```bash
sudo certbot --apache -d yourdomain.com
```

ถ้ายังไม่มีโดเมน ใช้ IP + HTTP ได้แค่ทดสอบ — **LINE Webhook ต้อง HTTPS** → ต้องมีโดเมนหรือใช้ nip.io / DuckDNS ฟรี

## 7. อัปเดต LINE + Google

| ที่ | URL ใหม่ |
|-----|----------|
| LINE Messaging API → Webhook | `https://โดเมนคุณ/webhook.php` |
| LINE Login → Callback | `https://โดเมนคุณ/auth_callback.php` |
| Google Maps API → HTTP referrers | `https://โดเมนคุณ/*` |

## 8. ทดสอบ

- `https://โดเมนคุณ/login.php`
- ส่งข้อความทดสอบใน LINE OA
- Dashboard แฟน login ได้

## ค่าใช้จ่ายหลังหมด credit

e2-small Singapore ≈ **$12–15/เดือน** (~400–500 บ.) + ดิสก์เล็กน้อย

ถ้าอยากประหยัดสุด: e2-micro ใน **us-central1** อาจเข้า Always Free แต่ ping ไทยช้ากว่า

## ย้ายออกจาก GCP ทีหลัง

Export DB + rsync ไฟล์ → อัป shared hosting — โครงสร้าง monolith ย้ายได้ ไม่ lock-in
