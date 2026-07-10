<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/liff_project_search.php';

$liffId = liff_project_search_id();

$apiBase = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$apiBase = preg_replace('#/liff$#', '', str_replace('\\', '/', $apiBase));
$searchApiUrl = $apiBase . '/api_project_name_search.php';
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>ค้นหาชื่อโครงการ</title>
  <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
  <style>
    :root {
      --green-dark: #1B4332;
      --green-mid: #2D6A4F;
      --green-light: #E8F4EC;
      --text: #1A1A1A;
      --muted: #5C5C5C;
      --border: #E5E5E5;
      --bg: #FFFFFF;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
      background: var(--bg);
      color: var(--text);
      line-height: 1.5;
      padding: 16px 16px calc(24px + env(safe-area-inset-bottom));
    }
    h1 {
      font-size: 1.25rem;
      color: var(--green-dark);
      margin: 0 0 6px;
    }
    .sub {
      color: var(--muted);
      font-size: 0.875rem;
      margin: 0 0 20px;
    }
    .search-row {
      display: flex;
      gap: 8px;
      margin-bottom: 16px;
    }
    input[type="search"], input[type="text"] {
      width: 100%;
      font-size: 1rem;
      padding: 12px 14px;
      border: 1px solid var(--border);
      border-radius: 12px;
      background: #FAFAFA;
    }
    input:focus {
      outline: 2px solid var(--green-mid);
      border-color: var(--green-mid);
      background: #fff;
    }
    button {
      font-size: 0.9375rem;
      font-weight: 600;
      border: none;
      border-radius: 12px;
      padding: 12px 16px;
      cursor: pointer;
    }
    .btn-primary {
      background: var(--green-dark);
      color: #fff;
      white-space: nowrap;
    }
    .btn-primary:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
    .btn-secondary {
      background: var(--green-light);
      color: var(--green-dark);
      width: 100%;
      margin-top: 8px;
    }
    .btn-outline {
      background: #fff;
      color: var(--green-dark);
      border: 1px solid var(--green-dark);
      width: 100%;
      margin-top: 8px;
    }
    .results {
      list-style: none;
      padding: 0;
      margin: 0 0 20px;
    }
    .results li {
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 14px;
      margin-bottom: 10px;
      cursor: pointer;
      transition: background 0.15s;
    }
    .results li:hover, .results li.selected {
      background: var(--green-light);
      border-color: var(--green-mid);
    }
    .results .name {
      font-weight: 600;
      font-size: 0.9375rem;
    }
    .results .addr {
      font-size: 0.75rem;
      color: var(--muted);
      margin-top: 4px;
    }
    .panel {
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 16px;
      margin-top: 8px;
      background: #FAFAFA;
    }
    .panel h2 {
      font-size: 0.875rem;
      color: var(--green-dark);
      margin: 0 0 12px;
    }
    label {
      display: block;
      font-size: 0.75rem;
      color: var(--muted);
      margin: 12px 0 6px;
    }
    label:first-of-type { margin-top: 0; }
    .preview {
      margin-top: 14px;
      padding: 14px;
      background: var(--green-dark);
      color: #fff;
      border-radius: 12px;
      font-weight: 600;
      font-size: 0.9375rem;
      word-break: break-word;
    }
    .preview small {
      display: block;
      font-weight: 400;
      font-size: 0.6875rem;
      opacity: 0.85;
      margin-bottom: 6px;
    }
    .hint {
      font-size: 0.75rem;
      color: var(--muted);
      margin-top: 16px;
    }
    .status {
      font-size: 0.8125rem;
      color: var(--muted);
      text-align: center;
      padding: 20px 0;
    }
    .toast {
      position: fixed;
      left: 50%;
      bottom: calc(20px + env(safe-area-inset-bottom));
      transform: translateX(-50%);
      background: var(--green-dark);
      color: #fff;
      padding: 10px 18px;
      border-radius: 999px;
      font-size: 0.875rem;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.2s;
      z-index: 99;
    }
    .toast.show { opacity: 1; }
    #loading-overlay {
      position: fixed;
      inset: 0;
      background: rgba(255,255,255,0.9);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 100;
    }
    #loading-overlay.hidden { display: none; }
  </style>
</head>
<body>
  <div id="loading-overlay"><span class="status">กำลังเปิด LIFF…</span></div>
  <div id="toast" class="toast"></div>

  <h1>ค้นหาชื่อโครงการ</h1>
  <p class="sub">ค้นหา → เลือก → แก้ชื่อไทย/อังกฤษได้ → คัดลอกหรือส่งกลับแชท</p>

  <div class="search-row">
    <input type="search" id="q" placeholder="เช่น เพฟ รามอินทรา" enterkeyhint="search" autocomplete="off">
    <button type="button" class="btn-primary" id="btn-search">ค้นหา</button>
  </div>

  <p class="status" id="search-status" hidden></p>
  <ul class="results" id="results"></ul>

  <div class="panel" id="edit-panel" hidden>
    <h2>ชื่อเต็มสำหรับวางใน Project</h2>
    <label for="name-th">ชื่อภาษาไทย</label>
    <input type="text" id="name-th" placeholder="เพฟ รามอินทรา - วงแหวน">
    <label for="name-en">ชื่อภาษาอังกฤษ</label>
    <input type="text" id="name-en" placeholder="Pave Ramintra - Wongwean">
    <div class="preview" id="preview">
      <small>ตัวอย่างที่จะคัดลอก</small>
      <span id="preview-text">—</span>
    </div>
    <button type="button" class="btn-secondary" id="btn-copy">📋 คัดลอกชื่อเต็ม</button>
    <button type="button" class="btn-primary" id="btn-send" style="width:100%;margin-top:8px;">↩ ส่งกลับแชท LINE</button>
    <button type="button" class="btn-outline" id="btn-google">🔍 เปิด Google เพิ่มเติม</button>
  </div>

  <p class="hint">รูปแบบแนะนำ: ชื่อไทย / ชื่ออังกฤษ<br>เช่น เพฟ รามอินทรา - วงแหวน / Pave Ramintra - Wongwean</p>

  <script>
    const LIFF_ID = <?php echo json_encode($liffId, JSON_UNESCAPED_UNICODE); ?>;
    const API_URL = <?php echo json_encode($searchApiUrl, JSON_UNESCAPED_UNICODE); ?>;

    let accessToken = '';
    let canSendMessages = false;

    const $ = (id) => document.getElementById(id);

    function toast(msg) {
      const el = $('toast');
      el.textContent = msg;
      el.classList.add('show');
      setTimeout(() => el.classList.remove('show'), 2000);
    }

    function buildFullName() {
      const th = $('name-th').value.trim();
      const en = $('name-en').value.trim();
      if (!th && !en) return '';
      if (!en || th.toLowerCase() === en.toLowerCase()) return th;
      if (!th) return en;
      return th + ' / ' + en;
    }

    function updatePreview() {
      const full = buildFullName();
      $('preview-text').textContent = full || '—';
    }

    async function runSearch() {
      const q = $('q').value.trim();
      if (q.length < 2) {
        toast('พิมพ์อย่างน้อย 2 ตัวอักษร');
        return;
      }
      const status = $('search-status');
      const list = $('results');
      list.innerHTML = '';
      $('edit-panel').hidden = true;
      status.hidden = false;
      status.textContent = 'กำลังค้นหา…';
      $('btn-search').disabled = true;

      try {
        const url = API_URL + '?q=' + encodeURIComponent(q) + (accessToken ? '&access_token=' + encodeURIComponent(accessToken) : '');
        const res = await fetch(url);
        const data = await res.json();
        status.hidden = true;

        if (!data.ok) {
          toast(data.error === 'maps_key_missing' ? 'ยังไม่ตั้งค่า Google Maps API' : 'ค้นหาไม่สำเร็จ');
          return;
        }
        if (!data.results || !data.results.length) {
          status.hidden = false;
          status.textContent = 'ไม่พบผลลัพธ์ — ลองคำอื่น หรือพิมพ์ชื่อเองด้านล่าง';
          showManualPanel(q, '');
          return;
        }

        data.results.forEach((row, i) => {
          const li = document.createElement('li');
          li.innerHTML = '<div class="name">' + escapeHtml(row.formatted || row.name_th) + '</div>'
            + (row.address ? '<div class="addr">' + escapeHtml(row.address) + '</div>' : '');
          li.addEventListener('click', () => selectResult(row, li));
          list.appendChild(li);
          if (i === 0) selectResult(row, li);
        });
      } catch (e) {
        status.hidden = false;
        status.textContent = 'เชื่อมต่อไม่ได้ ลองใหม่';
      } finally {
        $('btn-search').disabled = false;
      }
    }

    function escapeHtml(s) {
      return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function selectResult(row, li) {
      document.querySelectorAll('.results li').forEach((el) => el.classList.remove('selected'));
      if (li) li.classList.add('selected');
      showManualPanel(row.name_th || '', row.name_en || '');
    }

    function showManualPanel(th, en) {
      $('edit-panel').hidden = false;
      $('name-th').value = th;
      $('name-en').value = en;
      updatePreview();
    }

    async function copyName() {
      const full = buildFullName();
      if (!full) {
        toast('กรอกชื่อก่อน');
        return;
      }
      try {
        await navigator.clipboard.writeText(full);
        toast('คัดลอกแล้ว');
      } catch (e) {
        const ta = document.createElement('textarea');
        ta.value = full;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
        toast('คัดลอกแล้ว');
      }
    }

    async function sendToChat() {
      const full = buildFullName();
      if (!full) {
        toast('กรอกชื่อก่อน');
        return;
      }
      if (!canSendMessages || !liff.isApiAvailable('sendMessages')) {
        toast('ส่งกลับแชทได้เมื่อเปิดจาก LINE เท่านั้น — ใช้คัดลอกแทน');
        return;
      }
      try {
        await liff.sendMessages([{ type: 'text', text: full }]);
        toast('ส่งแล้ว');
        setTimeout(() => liff.closeWindow(), 600);
      } catch (e) {
        toast('ส่งไม่สำเร็จ — ใช้คัดลอกแทน');
      }
    }

    function openGoogle() {
      const q = $('q').value.trim() || buildFullName();
      const url = 'https://www.google.com/search?q=' + encodeURIComponent(q + ' โครงการ');
      if (liff.isInClient()) {
        liff.openWindow({ url, external: true });
      } else {
        window.open(url, '_blank');
      }
    }

    async function initLiff() {
      if (!LIFF_ID) {
        $('loading-overlay').classList.add('hidden');
        toast('ยังไม่ตั้งค่า LINE_LIFF_PROJECT_SEARCH_ID');
        return;
      }
      try {
        await liff.init({ liffId: LIFF_ID });
        if (!liff.isLoggedIn()) {
          liff.login();
          return;
        }
        const ctx = liff.getContext();
        canSendMessages = liff.isApiAvailable('sendMessages');
        try {
          const tok = liff.getAccessToken();
          if (tok) accessToken = tok;
        } catch (e) { /* ignore */ }
      } catch (e) {
        console.warn('LIFF init:', e);
      }
      $('loading-overlay').classList.add('hidden');
    }

    $('btn-search').addEventListener('click', runSearch);
    $('q').addEventListener('keydown', (e) => { if (e.key === 'Enter') runSearch(); });
    $('name-th').addEventListener('input', updatePreview);
    $('name-en').addEventListener('input', updatePreview);
    $('btn-copy').addEventListener('click', copyName);
    $('btn-send').addEventListener('click', sendToChat);
    $('btn-google').addEventListener('click', openGoogle);

    document.addEventListener('DOMContentLoaded', initLiff);
  </script>
</body>
</html>
