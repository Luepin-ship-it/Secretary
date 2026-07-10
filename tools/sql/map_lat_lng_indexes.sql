-- Index พิกัด lat/lng สำหรับหน้า Map (bounding box query)
-- รันใน phpMyAdmin หรือ:
--   mysql -u root antigravity_db < tools/sql/map_lat_lng_indexes.sql
--
-- ถ้า index มีอยู่แล้ว MySQL จะ error #1061 — ข้าม statement นั้นได้

USE antigravity_db;

ALTER TABLE owners
  ADD INDEX idx_owners_user_lat_lng (user_id, lat, lng);

ALTER TABLE leads
  ADD INDEX idx_leads_user_lat_lng (user_id, lat, lng);

ALTER TABLE leads
  ADD INDEX idx_leads_user_contact (user_id, contact_date);

-- project_surveys (ถ้ามีตารางและใช้ bbox ในอนาคต)
-- ALTER TABLE project_surveys ADD INDEX idx_ps_user_lat_lng (user_id, lat, lng);
