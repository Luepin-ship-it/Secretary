<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/liff_project_photos.php';

$liffId = liff_project_photos_id();
$code = trim((string)($_GET['code'] ?? ''));
$folder = trim((string)($_GET['folder'] ?? ''));
$driveUrl = trim((string)($_GET['drive'] ?? ''));
if ($folder === '' && $code !== '') {
    $folder = $code;
}
$folderCopy = $code !== '' ? $code : $folder;
$apiBase = preg_replace('#/liff$#', '', str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')));
$photosApi = $apiBase . '/api_owner_photos.php';
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>อัปรูปทรัพย์</title>
  <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
  <style>
    :root {
      --green: #10B981;
      --green-dark: #059669;
      --green-light: #ECFDF5;
      --text: #2B2B2B;
      --muted: #71717a;
      --border: #D4D4D4;
      --brown: #5C4E4E;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background: #fff;
      color: var(--text);
      padding: 16px 16px calc(28px + env(safe-area-inset-bottom));
    }
    h1 { font-size: 1.2rem; color: var(--green-dark); margin: 0 0 4px; }
    .sub { color: var(--muted); font-size: 0.875rem; margin: 0 0 16px; }
    .card {
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 14px;
      margin-bottom: 14px;
      background: #F8F9FA;
    }
    .card.hidden { display: none; }
    .card h2 { font-size: 0.8125rem; color: var(--green-dark); margin: 0 0 10px; }
    button, label.btn {
      display: block;
      width: 100%;
      font-size: 0.9375rem;
      font-weight: 600;
      border: none;
      border-radius: 12px;
      padding: 12px 16px;
      cursor: pointer;
      text-align: center;
      margin-top: 8px;
    }
    .btn-primary { background: var(--green); color: #fff; }
    .btn-secondary { background: var(--green-light); color: var(--green-dark); }
    .btn-brown { background: var(--brown); color: #fff; }
    .btn-outline {
      background: #fff;
      color: var(--green-dark);
      border: 1px solid var(--green-dark);
    }
    button:disabled { opacity: 0.45; cursor: not-allowed; }
    input[type="file"] { display: none; }
    #photo-list { list-style: none; padding: 0; margin: 0; }
    #photo-list li {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 10px;
      border: 1px solid var(--border);
      border-radius: 12px;
      margin-bottom: 8px;
      background: #fff;
    }
    #photo-list li.dragging { opacity: 0.55; background: var(--green-light); box-shadow: 0 4px 12px rgba(0,0,0,0.12); }
    #photo-list .drag-handle {
      width: 34px;
      min-height: 44px;
      flex-shrink: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--muted);
      font-size: 1rem;
      letter-spacing: -2px;
      cursor: grab;
      touch-action: none;
      user-select: none;
      border: 1px solid var(--border);
      border-radius: 8px;
      background: #f4f4f5;
      padding: 0;
      margin: 0;
    }
    #photo-list .drag-handle:active { cursor: grabbing; background: var(--green-light); color: var(--green-dark); }
    #photo-list img {
      width: 56px;
      height: 56px;
      object-fit: cover;
      border-radius: 8px;
      flex-shrink: 0;
    }
    #photo-list .meta { flex: 1; min-width: 0; }
    #photo-list .meta strong { display: block; font-size: 0.875rem; }
    #photo-list .meta span { font-size: 0.75rem; color: var(--muted); }
    #photo-list .del {
      background: #fff;
      border: 1px solid var(--border);
      color: var(--brown);
      width: 36px;
      height: 36px;
      border-radius: 8px;
      padding: 0;
      margin: 0;
      flex-shrink: 0;
    }
    #photo-list .reorder-btns {
      display: flex;
      flex-direction: column;
      gap: 4px;
      flex-shrink: 0;
    }
    #photo-list .reorder-btns button {
      width: 36px;
      height: 30px;
      margin: 0;
      padding: 0;
      font-size: 0.8125rem;
      font-weight: 700;
      border: 1px solid var(--border);
      background: #fff;
      border-radius: 6px;
      color: var(--text);
    }
    #photo-list .reorder-btns button:disabled { opacity: 0.3; }
    .empty { text-align: center; color: var(--muted); font-size: 0.875rem; padding: 20px 8px; }
    .folder-box {
      background: var(--green-dark);
      color: #F0F0F0;
      border-radius: 12px;
      padding: 12px;
      word-break: break-word;
      font-weight: 600;
      font-size: 1rem;
      text-align: center;
    }
    .folder-sub { font-size: 0.75rem; color: var(--muted); margin: 6px 0 0; text-align: center; }
    ol.steps { margin: 0; padding-left: 1.2rem; font-size: 0.8125rem; line-height: 1.55; }
    ol.steps li { margin-bottom: 6px; }
    #download-list { list-style: none; padding: 0; margin: 8px 0 0; }
    #download-list li {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      padding: 10px 12px;
      border: 1px solid var(--border);
      border-radius: 10px;
      margin-bottom: 6px;
      background: #fff;
      font-size: 0.875rem;
    }
    #download-list button {
      width: auto;
      margin: 0;
      padding: 8px 12px;
      font-size: 0.8125rem;
    }
    #loading {
      position: fixed; inset: 0; background: rgba(255,255,255,0.92);
      display: flex; align-items: center; justify-content: center; z-index: 9;
    }
    #loading.hidden { display: none; }
    .toast {
      position: fixed; left: 50%; bottom: calc(20px + env(safe-area-inset-bottom));
      transform: translateX(-50%); background: var(--green-dark); color: #fff;
      padding: 10px 18px; border-radius: 999px; font-size: 0.875rem;
      opacity: 0; transition: opacity 0.2s; z-index: 99;
    }
    .toast.show { opacity: 1; }
    .note { font-size: 0.75rem; color: var(--muted); margin-top: 10px; line-height: 1.5; }
  </style>
</head>
<body>
  <div id="loading"><span class="sub">กำลังเปิด…</span></div>
  <div id="toast" class="toast"></div>

  <h1>อัปรูปทรัพย์</h1>
  <p class="sub">เรียงลำดับ → ตั้งชื่อ 1, 2, 3… → อัปลง Drive เอง</p>

  <div class="card">
    <h2>รหัสทรัพย์ (ชื่อโฟลเดอร์ใน Drive)</h2>
    <div class="folder-box" id="folder-copy"><?php echo htmlspecialchars($folderCopy ?: '—', ENT_QUOTES, 'UTF-8'); ?></div>
    <?php if ($folder !== '' && $folder !== $code): ?>
    <p class="folder-sub">ชื่อเต็มแนะนำ: <?php echo htmlspecialchars($folder, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <button type="button" class="btn-secondary" id="btn-copy-folder">📋 คัดลอกชื่อโฟลเดอร์</button>
  </div>

  <div class="card">
    <h2>① เลือกและเรียงรูป</h2>
    <label class="btn btn-secondary" for="file-input">📷 เพิ่มรูป (หลายไฟล์)</label>
    <input type="file" id="file-input" accept="image/*" multiple>
    <ul id="photo-list"></ul>
    <p class="empty" id="empty-hint">ยังไม่มีรูป — กดเพิ่มรูปด้านบน</p>
    <p class="note">กดค้างที่ ⋮⋮ แล้วลาก หรือกด ↑ ↓ เพื่อเรียง — รูปแรก = ปก Listing</p>
    <button type="button" class="btn-primary" id="btn-finalize" disabled>ตั้งชื่อ 1, 2, 3…</button>
  </div>

  <div class="card hidden" id="drive-card">
    <h2>② อัปลง Google Drive (ทำเอง)</h2>
    <ol class="steps">
      <li>กด「คัดลอกชื่อโฟลเดอร์」ด้านบน (รหัส <?php echo htmlspecialchars($code ?: 'ทรัพย์', ENT_QUOTES, 'UTF-8'); ?>)</li>
      <li>กด「เปิด Google Drive」→ สร้างโฟลเดอร์ใหม่ → วางชื่อที่คัดลอก</li>
      <li>บันทึกรูป <strong>1.jpg, 2.jpg…</strong> ลงเครื่อง (ปุ่มด้านล่าง)</li>
      <li>ในแอป Drive → เข้าโฟลเดอร์ → อัปรูปที่บันทึกไว้</li>
    </ol>
    <ul id="download-list"></ul>
    <button type="button" class="btn-secondary" id="btn-save-all" disabled>💾 บันทึกรูปทั้งหมดลงเครื่อง</button>
    <button type="button" class="btn-brown" id="btn-drive" <?php echo $driveUrl === '' ? 'disabled' : ''; ?>>เปิด Google Drive</button>
    <?php if ($driveUrl === ''): ?>
    <p class="note">ยังไม่ได้ตั้งโฟลเดอร์ Drive หลักในโปรไฟล — เปิดแอป Drive เองได้เหมือนกัน</p>
    <?php endif; ?>
  </div>

  <p class="note">เมื่ออัปใน Drive เสร็จ → กลับแชท LINE กด「อัปรูปเสร็จแล้ว」</p>

  <script>
    const LIFF_ID = <?php echo json_encode($liffId, JSON_UNESCAPED_UNICODE); ?>;
    const CODE = <?php echo json_encode($code, JSON_UNESCAPED_UNICODE); ?>;
    const FOLDER_COPY = <?php echo json_encode($folderCopy, JSON_UNESCAPED_UNICODE); ?>;
    const API = <?php echo json_encode($photosApi, JSON_UNESCAPED_UNICODE); ?>;
    const DRIVE_URL = <?php echo json_encode($driveUrl, JSON_UNESCAPED_UNICODE); ?>;

    let accessToken = '';
    let photos = [];
    let dragId = null;

    const toast = (msg) => {
      const el = document.getElementById('toast');
      el.textContent = msg;
      el.classList.add('show');
      setTimeout(() => el.classList.remove('show'), 2400);
    };

    const isFinalized = () => photos.length > 0 && photos.every(p => p.finalized);

    async function api(path, opts = {}) {
      const headers = opts.headers || {};
      if (accessToken) headers['Authorization'] = 'Bearer ' + accessToken;
      const res = await fetch(path, { ...opts, headers });
      return res.json();
    }

    async function copyText(text) {
      try {
        await navigator.clipboard.writeText(text);
        toast('คัดลอกแล้ว: ' + text);
      } catch (e) {
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
        toast('คัดลอกแล้ว');
      }
    }

    function updateDriveSection() {
      const card = document.getElementById('drive-card');
      const list = document.getElementById('download-list');
      const saveAll = document.getElementById('btn-save-all');
      if (!isFinalized()) {
        card.classList.add('hidden');
        return;
      }
      card.classList.remove('hidden');
      list.innerHTML = '';
      photos.forEach((p) => {
        const li = document.createElement('li');
        li.innerHTML = `<span>📷 ${p.file_name}</span>`;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-outline';
        btn.textContent = 'บันทึก';
        btn.addEventListener('click', () => savePhotoToDevice(p));
        li.appendChild(btn);
        list.appendChild(li);
      });
      saveAll.disabled = photos.length === 0;
    }

    function syncPhotosFromDom() {
      const list = document.getElementById('photo-list');
      const ids = [...list.querySelectorAll('li')].map(li => parseInt(li.dataset.id, 10));
      photos = ids.map(id => photos.find(p => p.id === id)).filter(Boolean);
    }

    function movePhotoById(id, dir) {
      const idx = photos.findIndex(p => p.id === id);
      if (idx < 0) return;
      const next = dir === 'up' ? idx - 1 : idx + 1;
      if (next < 0 || next >= photos.length) return;
      const [item] = photos.splice(idx, 1);
      photos.splice(next, 0, item);
      saveOrder();
    }

    function attachReorderHandlers(li, photoId) {
      const handle = li.querySelector('.drag-handle');
      if (!handle) return;

      handle.draggable = true;
      handle.addEventListener('dragstart', (e) => {
        dragId = photoId;
        li.classList.add('dragging');
        if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
      });
      handle.addEventListener('dragend', () => {
        dragId = null;
        li.classList.remove('dragging');
      });

      let touchActive = false;
      handle.addEventListener('touchstart', () => {
        touchActive = true;
        dragId = photoId;
        li.classList.add('dragging');
      }, { passive: true });

      handle.addEventListener('touchmove', (e) => {
        if (!touchActive) return;
        e.preventDefault();
        const touch = e.touches[0];
        const list = document.getElementById('photo-list');
        const items = [...list.children];
        for (const other of items) {
          if (other === li) continue;
          const rect = other.getBoundingClientRect();
          const midY = rect.top + rect.height / 2;
          if (touch.clientY < midY && (other.compareDocumentPosition(li) & Node.DOCUMENT_POSITION_FOLLOWING)) {
            list.insertBefore(li, other);
            break;
          }
          if (touch.clientY > midY && (other.compareDocumentPosition(li) & Node.DOCUMENT_POSITION_PRECEDING)) {
            list.insertBefore(li, other.nextSibling);
            break;
          }
        }
      }, { passive: false });

      handle.addEventListener('touchend', () => {
        if (!touchActive) return;
        touchActive = false;
        dragId = null;
        li.classList.remove('dragging');
        syncPhotosFromDom();
        saveOrder();
      });

      li.addEventListener('dragover', (e) => e.preventDefault());
      li.addEventListener('drop', (e) => {
        e.preventDefault();
        if (dragId === null || dragId === photoId) return;
        const from = photos.findIndex(x => x.id === dragId);
        const to = photos.findIndex(x => x.id === photoId);
        if (from < 0 || to < 0) return;
        const [item] = photos.splice(from, 1);
        photos.splice(to, 0, item);
        saveOrder();
      });
    }

    function renderList() {
      const list = document.getElementById('photo-list');
      const empty = document.getElementById('empty-hint');
      const btn = document.getElementById('btn-finalize');
      list.innerHTML = '';
      empty.style.display = photos.length ? 'none' : 'block';
      btn.disabled = photos.length === 0 || isFinalized();
      photos.forEach((p, idx) => {
        const li = document.createElement('li');
        li.dataset.id = p.id;
        const label = p.finalized ? p.file_name : ('รูปที่ ' + (idx + 1));
        const sub = p.finalized ? 'ชื่อไฟล์สำหรับอัป Drive' : 'ลาก ⋮⋮ หรือกด ↑ ↓';
        li.innerHTML = `
          ${p.finalized ? '' : '<button type="button" class="drag-handle" aria-label="ลากเพื่อเรียง" title="ลากเพื่อเรียง">⋮⋮</button>'}
          <img src="${p.url}" alt="" draggable="false">
          <div class="meta">
            <strong>${label}</strong>
            <span>${sub}</span>
          </div>
          ${p.finalized ? '' : `
            <div class="reorder-btns">
              <button type="button" class="move-up" aria-label="เลื่อนขึ้น" title="เลื่อนขึ้น" ${idx === 0 ? 'disabled' : ''}>↑</button>
              <button type="button" class="move-down" aria-label="เลื่อนลง" title="เลื่อนลง" ${idx === photos.length - 1 ? 'disabled' : ''}>↓</button>
            </div>
            <button type="button" class="del" title="ลบ" aria-label="ลบ">✕</button>`}`;
        if (!p.finalized) {
          li.querySelector('.del').addEventListener('click', () => deletePhoto(p.id));
          li.querySelector('.move-up').addEventListener('click', () => movePhotoById(p.id, 'up'));
          li.querySelector('.move-down').addEventListener('click', () => movePhotoById(p.id, 'down'));
          attachReorderHandlers(li, p.id);
        }
        list.appendChild(li);
      });
      updateDriveSection();
    }

    async function loadPhotos() {
      if (!CODE) return;
      const data = await api(API + '?action=list&code=' + encodeURIComponent(CODE));
      if (data.ok) {
        photos = data.photos || [];
        renderList();
      }
    }

    async function uploadFiles(files) {
      for (const file of files) {
        const fd = new FormData();
        fd.append('photo', file);
        fd.append('code', CODE);
        fd.append('action', 'upload');
        const headers = {};
        if (accessToken) headers['Authorization'] = 'Bearer ' + accessToken;
        const res = await fetch(API + '?action=upload&code=' + encodeURIComponent(CODE), {
          method: 'POST',
          headers,
          body: fd,
        });
        const data = await res.json();
        if (!data.ok) {
          toast('อัปโหลดไม่สำเร็จ: ' + (data.message || data.error || ''));
        }
      }
      await loadPhotos();
      toast('เพิ่มรูปแล้ว — ลากเรียงแล้วกดตั้งชื่อ');
    }

    async function saveOrder() {
      renderList();
      const order = photos.map(p => p.id);
      const data = await api(API + '?action=reorder&code=' + encodeURIComponent(CODE), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + accessToken },
        body: JSON.stringify({ order }),
      });
      if (data.ok && data.photos) {
        photos = data.photos;
        renderList();
      }
    }

    async function deletePhoto(id) {
      await api(API + '?action=delete&code=' + encodeURIComponent(CODE), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + accessToken },
        body: JSON.stringify({ id }),
      });
      await loadPhotos();
      toast('ลบแล้ว');
    }

    async function savePhotoToDevice(photo) {
      try {
        const headers = accessToken ? { Authorization: 'Bearer ' + accessToken } : {};
        const res = await fetch(photo.url, { headers });
        const blob = await res.blob();
        const name = photo.file_name || 'photo.jpg';
        const file = new File([blob], name, { type: blob.type || 'image/jpeg' });
        if (navigator.canShare && navigator.canShare({ files: [file] })) {
          await navigator.share({ files: [file], title: name });
          toast('บันทึก ' + name);
          return;
        }
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = name;
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
        toast('บันทึก ' + name);
      } catch (e) {
        toast('บันทึกไม่สำเร็จ — ลองกดค้างที่รูปแล้วบันทึก');
      }
    }

    document.getElementById('btn-copy-folder').addEventListener('click', () => {
      copyText(FOLDER_COPY || CODE);
    });

    document.getElementById('file-input').addEventListener('change', (e) => {
      const files = e.target.files;
      if (!files || !files.length) return;
      uploadFiles(files);
      e.target.value = '';
    });

    document.getElementById('btn-finalize').addEventListener('click', async () => {
      const data = await api(API + '?action=finalize&code=' + encodeURIComponent(CODE), { method: 'POST' });
      if (data.ok) {
        await loadPhotos();
        toast('ตั้งชื่อ 1, 2, 3… แล้ว — ไปขั้นอัป Drive ด้านล่าง');
        document.getElementById('drive-card').scrollIntoView({ behavior: 'smooth' });
      } else {
        toast('ยังตั้งชื่อไม่ได้');
      }
    });

    document.getElementById('btn-save-all').addEventListener('click', async () => {
      for (const p of photos) {
        await savePhotoToDevice(p);
        await new Promise(r => setTimeout(r, 400));
      }
      toast('บันทึกครบแล้ว — ไปอัปใน Drive');
    });

    document.getElementById('btn-drive').addEventListener('click', () => {
      if (!DRIVE_URL) return;
      if (typeof liff !== 'undefined' && liff.openWindow) {
        liff.openWindow({ url: DRIVE_URL, external: true });
      } else {
        window.open(DRIVE_URL, '_blank');
      }
    });

    (async () => {
      try {
        if (!LIFF_ID) throw new Error('no_liff');
        await liff.init({ liffId: LIFF_ID });
        if (!liff.isLoggedIn()) {
          liff.login();
          return;
        }
        accessToken = liff.getAccessToken();
        document.getElementById('loading').classList.add('hidden');
        if (!CODE) {
          toast('ไม่พบรหัสทรัพย์');
          return;
        }
        await loadPhotos();
      } catch (e) {
        document.getElementById('loading').classList.add('hidden');
        toast('เปิด LIFF ไม่สำเร็จ');
      }
    })();
  </script>
</body>
</html>
