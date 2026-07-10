(function () {
  'use strict';

  const STORAGE_KEY = 'ms_workflow_internal_v1';

  const STAGES = [
    { id: 'survey', title: 'ฝ่ายขาย (Sales)', desc: 'สำรวจหน้างาน · ถ่ายรูป · วัดขนาด', icon: '📷' },
    { id: 'form', title: 'Google Form', desc: 'กรอกข้อมูลหน้างาน · แนบรูป', icon: '📝' },
    { id: 'sheet', title: 'Google Sheet', desc: 'ฐานข้อมูลกลาง JOB-26xxx', icon: '📊' },
    { id: 'departments', title: '3 ฝ่ายงาน', desc: 'ออกแบบ 3D · บัญชี · โฟร์แมน', icon: '👥', split: true },
    { id: 'drive', title: 'Google Drive', desc: 'แบบ 3D · ใบเสนอราคา · รูปก่อน/ระหว่าง/หลัง', icon: '📁' },
    { id: 'line_group', title: 'LINE GROUP', desc: 'แจ้งเปิดงาน · ติดตั้ง · ส่งมอบ', icon: '💬' },
    { id: 'install', title: 'ทีมช่างติดตั้ง', desc: 'Checklist · ถ่ายรูปส่งงาน', icon: '🔧' },
    { id: 'handover', title: 'ส่งมอบลูกค้า', desc: 'เซ็นรับงาน · ใบรับประกัน', icon: '✅' },
  ];

  const STAGE_ORDER = ['survey', 'form', 'sheet', 'departments', 'drive', 'line_group', 'install', 'handover'];

  const STAGE_LABELS = Object.fromEntries(STAGES.map(s => [s.id, s.title]));

  const DEPT_KEYS = ['design', 'accounting', 'foreman'];
  const DEPT_LABELS = { design: 'ออกแบบ 3D', accounting: 'บัญชี', foreman: 'โฟร์แมน' };

  const DRIVE_TEMPLATES = [
    'แบบ 3D',
    'ใบเสนอราคา',
    'รูปก่อนทำ',
    'รูประหว่างทำ',
    'รูปหลังทำ',
  ];

  const INSTALL_CHECKLIST = [
    'ตรวจวัสดุครบตามแบบ',
    'ติดตั้งตามมาตรฐาน',
    'ถ่ายรูประหว่างทำ',
    'ถ่ายรูปหลังทำ',
    'ทำความสะอาดหน้างาน',
  ];

  const LINE_TEMPLATES = [
    { type: 'open', label: 'แจ้งเปิดงาน', text: '🔔 เปิดงาน {code} — {customer} · {location}' },
    { type: 'install', label: 'แจ้งติดตั้ง', text: '🚧 เริ่มติดตั้ง {code} วันนี้' },
    { type: 'handover', label: 'แจ้งส่งมอบ', text: '✅ ส่งมอบ {code} เรียบร้อย' },
  ];

  let state = loadState();
  let selectedJobId = state.jobs[0]?.id || null;
  let selectedFlowStage = null;
  let currentRole = 'sales';

  const $ = (sel) => document.querySelector(sel);
  const $$ = (sel) => document.querySelectorAll(sel);

  function nowIso() {
    return new Date().toISOString();
  }

  function fmtTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return d.toLocaleString('th-TH', { dateStyle: 'short', timeStyle: 'short' });
  }

  function loadState() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (raw) return JSON.parse(raw);
    } catch (e) { /* ignore */ }
    return { jobs: seedJobs(), seq: 26004 };
  }

  function saveState() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
  }

  function seedJobs() {
    return [
      makeJob('JOB-26001', 'คุณสมชาย หลังคาโรงงาน', 'นิคมอุตสาหกรรม ปทุมธานี', 'install', {
        design: 'done', accounting: 'done', foreman: 'working',
      }),
      makeJob('JOB-26002', 'บ. สยามโครงสร้าง', 'ระยอง', 'departments', {
        design: 'working', accounting: 'pending', foreman: 'pending',
      }),
      makeJob('JOB-26003', 'คุณวิไล บ้านพัก', 'ชลบุรี', 'survey', {
        design: 'pending', accounting: 'pending', foreman: 'pending',
      }),
    ];
  }

  function makeJob(id, customer, location, stage, depts) {
    const checklist = INSTALL_CHECKLIST.map((label, i) => ({
      id: 'c' + i,
      label,
      done: stage === 'handover' || (stage === 'install' && i < 2),
    }));
    return {
      id,
      customer,
      location,
      stage,
      note: '',
      departments: depts || { design: 'pending', accounting: 'pending', foreman: 'pending' },
      documents: stage !== 'survey' && stage !== 'form'
        ? DRIVE_TEMPLATES.slice(0, stage === 'departments' ? 1 : 3).map((name, i) => ({ id: 'd' + i, name, addedAt: nowIso() }))
        : [],
      lineMessages: stage === 'install' || stage === 'handover'
        ? [{ at: nowIso(), text: `🔔 เปิดงาน ${id} — ${customer}` }]
        : [],
      checklist,
      history: [{ at: nowIso(), stage: 'survey', note: 'สร้าง JOB จาก Sheet (demo)' }],
      createdAt: nowIso(),
    };
  }

  function getJob(id) {
    return state.jobs.find(j => j.id === id) || null;
  }

  function stageIndex(stage) {
    return STAGE_ORDER.indexOf(stage);
  }

  function pushHistory(job, stage, note) {
    job.history.push({ at: nowIso(), stage, note });
  }

  function toast(msg) {
    const el = $('#toast');
    el.textContent = msg;
    el.classList.remove('hidden');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => el.classList.add('hidden'), 2800);
  }

  function renderFlowMap() {
    const wrap = $('#flow-map');
    wrap.innerHTML = '';
    const job = selectedJobId ? getJob(selectedJobId) : null;
    const jobIdx = job ? stageIndex(job.stage) : -1;

    STAGES.forEach((step, idx) => {
      if (step.split) {
        const split = document.createElement('div');
        split.className = 'flow-split';
        DEPT_KEYS.forEach(key => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'flow-node';
          btn.dataset.stage = 'departments';
          btn.dataset.dept = key;
          btn.innerHTML = `<span class="flow-node__title"><span class="flow-node__icon">${key === 'design' ? '📐' : key === 'accounting' ? '🧾' : '👷'}</span>${DEPT_LABELS[key]}</span>`;
          if (job && job.stage === 'departments') btn.classList.add('is-active-job');
          if (jobIdx > stageIndex('departments')) btn.classList.add('is-done');
          btn.addEventListener('click', () => selectFlowStage('departments'));
          split.appendChild(btn);
        });
        wrap.appendChild(split);
        if (idx < STAGES.length - 1) {
          const arr = document.createElement('div');
          arr.className = 'flow-arrow';
          arr.textContent = '▼';
          wrap.appendChild(arr);
        }
        return;
      }

      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'flow-node';
      btn.dataset.stage = step.id;
      const done = jobIdx > idx;
      const current = job && job.stage === step.id;
      if (done) btn.classList.add('is-done');
      if (current) btn.classList.add('is-active-job');
      if (selectedFlowStage === step.id) btn.setAttribute('aria-current', 'step');

      btn.innerHTML = `
        <span class="flow-node__title"><span class="flow-node__icon">${step.icon}</span>${step.title}</span>
        <span class="flow-node__desc">${step.desc}</span>`;
      btn.addEventListener('click', () => selectFlowStage(step.id));
      wrap.appendChild(btn);

      if (idx < STAGES.length - 1 && !STAGES[idx + 1]?.split) {
        const arr = document.createElement('div');
        arr.className = 'flow-arrow';
        arr.textContent = '▼';
        wrap.appendChild(arr);
      }
    });
  }

  function selectFlowStage(stageId) {
    selectedFlowStage = stageId;
    renderFlowMap();
    if (selectedJobId) {
      activateTab(stageId === 'departments' ? 'departments'
        : stageId === 'drive' ? 'drive'
        : stageId === 'line_group' ? 'line'
        : stageId === 'install' ? 'checklist'
        : 'progress');
    }
  }

  function renderJobList() {
    const list = $('#job-list');
    list.innerHTML = '';
    state.jobs.forEach(job => {
      const li = document.createElement('li');
      li.className = 'job-item';
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', job.id === selectedJobId ? 'true' : 'false');
      li.innerHTML = `
        <div>
          <div class="job-item__code">${job.id}</div>
          <div class="job-item__meta">${job.customer} · ${job.location}</div>
        </div>
        <span class="job-item__pill">${STAGE_LABELS[job.stage] || job.stage}</span>`;
      li.addEventListener('click', () => selectJob(job.id));
      list.appendChild(li);
    });
  }

  function selectJob(id) {
    selectedJobId = id;
    renderJobList();
    renderFlowMap();
    renderJobDetail();
    $('#job-detail').classList.remove('hidden');
    $('#job-empty').classList.add('hidden');
  }

  function renderJobDetail() {
    const job = getJob(selectedJobId);
    if (!job) return;

    $('#detail-code').textContent = job.id;
    $('#detail-customer').textContent = job.customer;
    $('#detail-location').textContent = job.location;
    $('#detail-stage-label').textContent = 'ขั้นตอนปัจจุบัน';
    $('#detail-stage-pill').textContent = STAGE_LABELS[job.stage] || job.stage;

    renderTimeline(job);
    renderProgressActions(job);
    renderDepartments(job);
    renderDrive(job);
    renderLineLog(job);
    renderChecklist(job);
  }

  function renderTimeline(job) {
    const ol = $('#timeline');
    ol.innerHTML = '';
    STAGE_ORDER.forEach(stageId => {
      if (stageId === 'departments') {
        DEPT_KEYS.forEach(key => {
          const li = document.createElement('li');
          const st = job.departments[key];
          const done = st === 'done' || stageIndex(job.stage) > stageIndex('departments');
          const current = job.stage === 'departments' && st !== 'done';
          if (done) li.classList.add('done');
          if (current) li.classList.add('current');
          const hist = job.history.filter(h => h.note && h.note.includes(DEPT_LABELS[key])).pop();
          li.innerHTML = `
            <span class="timeline__dot">${done ? '✓' : ''}</span>
            <div>
              <strong>${DEPT_LABELS[key]}</strong>
              <span class="dept-status dept-status--${st}"> ${statusLabel(st)}</span>
              <div class="timeline__time">${hist ? fmtTime(hist.at) : '—'}</div>
            </div>`;
          ol.appendChild(li);
        });
        return;
      }
      const li = document.createElement('li');
      const idx = stageIndex(stageId);
      const done = stageIndex(job.stage) > idx;
      const current = job.stage === stageId;
      if (done) li.classList.add('done');
      if (current) li.classList.add('current');
      const hist = job.history.filter(h => h.stage === stageId).pop();
      li.innerHTML = `
        <span class="timeline__dot">${done ? '✓' : ''}</span>
        <div>
          <strong>${STAGE_LABELS[stageId]}</strong>
          <div class="timeline__time">${hist ? fmtTime(hist.at) : current ? 'กำลังดำเนินการ' : '—'}</div>
        </div>`;
      ol.appendChild(li);
    });
  }

  function statusLabel(st) {
    return { pending: 'รอดำเนินการ', working: 'กำลังทำ', done: 'เสร็จแล้ว' }[st] || st;
  }

  function canRole(action) {
    const map = {
      sales: ['advance', 'survey'],
      design: ['dept_design'],
      accounting: ['dept_accounting'],
      foreman: ['dept_foreman'],
      install: ['checklist', 'advance_install'],
      admin: ['advance', 'survey', 'dept_design', 'dept_accounting', 'dept_foreman', 'checklist', 'advance_install', 'line', 'doc'],
    };
    return (map[currentRole] || []).includes(action);
  }

  function renderProgressActions(job) {
    const bar = $('#progress-actions');
    bar.innerHTML = '';

    const nextStage = () => {
      const idx = stageIndex(job.stage);
      if (idx < 0 || idx >= STAGE_ORDER.length - 1) return null;
      return STAGE_ORDER[idx + 1];
    };

    const addBtn = (label, action, handler) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn--accent';
      btn.textContent = label;
      btn.disabled = !canRole(action);
      btn.addEventListener('click', handler);
      bar.appendChild(btn);
    };

    if (job.stage === 'survey') {
      addBtn('ส่งเข้า Google Form →', 'advance', () => advanceJob(job, 'form', 'Sales ส่งฟอร์มหน้างาน'));
    } else if (job.stage === 'form') {
      addBtn('Sync เข้า Sheet (JOB)', 'advance', () => advanceJob(job, 'sheet', 'บันทึกลง Google Sheet อัตโนมัติ'));
    } else if (job.stage === 'sheet') {
      addBtn('แจกงาน 3 ฝ่าย →', 'advance', () => advanceJob(job, 'departments', 'แจกงาน ออกแบบ · บัญชี · โฟร์แมน'));
    } else if (job.stage === 'departments') {
      const allDone = DEPT_KEYS.every(k => job.departments[k] === 'done');
      if (allDone) {
        addBtn('รวบรวมเอกสาร → Drive', 'advance', () => advanceJob(job, 'drive', 'อัปโหลดเอกสารครบ'));
      } else {
        const hint = document.createElement('p');
        hint.style.fontSize = '0.75rem';
        hint.style.color = 'var(--muted)';
        hint.textContent = 'รอ 3 ฝ่ายทำเสร็จ — ไปแท็บ「ฝ่ายงาน」อัปเดตสถานะ';
        bar.appendChild(hint);
      }
    } else if (job.stage === 'drive') {
      addBtn('แจ้ง LINE Group →', 'line', () => advanceJob(job, 'line_group', 'แจ้งเปิดงานใน LINE Group'));
    } else if (job.stage === 'line_group') {
      addBtn('มอบให้ทีมช่าง →', 'advance_install', () => advanceJob(job, 'install', 'ทีมช่างรับงานติดตั้ง'));
    } else if (job.stage === 'install') {
      const allChecked = job.checklist.every(c => c.done);
      if (allChecked) {
        addBtn('ส่งมอบลูกค้า ✓', 'advance', () => advanceJob(job, 'handover', 'เซ็นรับงาน · ออกใบรับประกัน'));
      } else {
        const hint = document.createElement('p');
        hint.style.fontSize = '0.75rem';
        hint.style.color = 'var(--muted)';
        hint.textContent = 'ทำ Checklist ช่างให้ครบก่อนส่งมอบ';
        bar.appendChild(hint);
      }
    } else if (job.stage === 'handover') {
      const done = document.createElement('p');
      done.style.fontSize = '0.85rem';
      done.style.color = 'var(--ok)';
      done.textContent = '✓ งานปิดแล้ว — ส่งมอบลูกค้าเรียบร้อย';
      bar.appendChild(done);
    }

    const ns = nextStage();
    if (ns && job.stage !== 'departments' && job.stage !== 'install' && job.stage !== 'handover') {
      /* actions above handle advance */
    }
  }

  function advanceJob(job, next, note) {
    job.stage = next;
    pushHistory(job, next, note);
    if (next === 'drive' && job.documents.length < DRIVE_TEMPLATES.length) {
      job.documents = DRIVE_TEMPLATES.map((name, i) => ({ id: 'd' + i + job.id, name, addedAt: nowIso() }));
    }
    if (next === 'line_group') {
      const tpl = LINE_TEMPLATES[0];
      job.lineMessages.push({
        at: nowIso(),
        text: tpl.text.replace('{code}', job.id).replace('{customer}', job.customer).replace('{location}', job.location),
      });
    }
    saveState();
    renderAll();
    toast(`${job.id} → ${STAGE_LABELS[next]}`);
  }

  function renderDepartments(job) {
    const grid = $('#dept-grid');
    grid.innerHTML = '';
    DEPT_KEYS.forEach(key => {
      const card = document.createElement('div');
      card.className = 'dept-card';
      const st = job.departments[key];
      card.innerHTML = `
        <h4>${DEPT_LABELS[key]}</h4>
        <span class="dept-status dept-status--${st}">${statusLabel(st)}</span>`;
      const actions = document.createElement('div');
      actions.style.marginTop = '0.5rem';
      actions.style.display = 'flex';
      actions.style.flexWrap = 'wrap';
      actions.style.gap = '0.35rem';

      if (st === 'pending') {
        const b = mkSmallBtn('เริ่มงาน', () => setDept(job, key, 'working'));
        b.disabled = !canRole('dept_' + key) && !canRole('advance');
        actions.appendChild(b);
      }
      if (st === 'working') {
        const b = mkSmallBtn('เสร็จแล้ว', () => setDept(job, key, 'done'));
        b.disabled = !canRole('dept_' + key) && !canRole('advance');
        actions.appendChild(b);
      }
      card.appendChild(actions);
      grid.appendChild(card);
    });
  }

  function mkSmallBtn(label, fn) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'btn btn--ghost btn--sm';
    b.textContent = label;
    b.addEventListener('click', fn);
    return b;
  }

  function setDept(job, key, status) {
    job.departments[key] = status;
    pushHistory(job, 'departments', `${DEPT_LABELS[key]} — ${statusLabel(status)}`);
    saveState();
    renderAll();
    toast(`${DEPT_LABELS[key]}: ${statusLabel(status)}`);
  }

  function renderDrive(job) {
    const ul = $('#drive-list');
    ul.innerHTML = '';
    if (!job.documents.length) {
      ul.innerHTML = '<li style="color:var(--muted)">ยังไม่มีเอกสาร — รอฝ่ายงานอัปโหลด</li>';
      return;
    }
    job.documents.forEach(doc => {
      const li = document.createElement('li');
      li.textContent = `📄 ${doc.name} · ${fmtTime(doc.addedAt)}`;
      ul.appendChild(li);
    });
  }

  function renderLineLog(job) {
    const ul = $('#line-log');
    ul.innerHTML = '';
    if (!job.lineMessages.length) {
      ul.innerHTML = '<li style="color:var(--muted)">ยังไม่มีการแจ้งใน LINE Group</li>';
      return;
    }
    job.lineMessages.forEach(msg => {
      const li = document.createElement('li');
      li.innerHTML = `<strong>${fmtTime(msg.at)}</strong><br>${msg.text}`;
      ul.appendChild(li);
    });
  }

  function renderChecklist(job) {
    const ul = $('#checklist');
    ul.innerHTML = '';
    job.checklist.forEach(item => {
      const li = document.createElement('li');
      if (item.done) li.classList.add('done');
      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.checked = item.done;
      cb.disabled = !canRole('checklist') && currentRole !== 'admin';
      cb.addEventListener('change', () => {
        item.done = cb.checked;
        if (item.done) pushHistory(job, 'install', `Checklist: ${item.label}`);
        saveState();
        renderTimeline(job);
        renderProgressActions(job);
        li.classList.toggle('done', item.done);
      });
      const span = document.createElement('span');
      span.textContent = item.label;
      li.appendChild(cb);
      li.appendChild(span);
      ul.appendChild(li);
    });
  }

  function activateTab(name) {
    $$('.tab').forEach(t => {
      const on = t.dataset.tab === name;
      t.classList.toggle('active', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    $$('.tab-panel').forEach(p => p.classList.add('hidden'));
    const panel = $('#tab-' + name);
    if (panel) {
      panel.classList.remove('hidden');
      panel.classList.add('active');
    }
  }

  function renderAll() {
    renderFlowMap();
    renderJobList();
    if (selectedJobId) renderJobDetail();
  }

  function nextJobCode() {
    const n = state.seq++;
    return 'JOB-' + n;
  }

  function openNewJobModal() {
    const modal = $('#modal-new');
    modal.showModal();
  }

  $('#btn-new-job').addEventListener('click', openNewJobModal);
  $('#modal-cancel').addEventListener('click', () => $('#modal-new').close());

  $('#form-new-job').addEventListener('submit', (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const id = nextJobCode();
    const job = makeJob(
      id,
      fd.get('customer').trim(),
      fd.get('location').trim(),
      'survey',
      { design: 'pending', accounting: 'pending', foreman: 'pending' }
    );
    job.note = fd.get('note').trim();
    job.history = [{ at: nowIso(), stage: 'survey', note: job.note || 'Sales สำรวจหน้างาน · ถ่ายรูป / วัดขนาด' }];
    state.jobs.unshift(job);
    saveState();
    $('#modal-new').close();
    e.target.reset();
    selectJob(id);
    toast(`สร้าง ${id} แล้ว`);
  });

  $$('.tab').forEach(tab => {
    tab.addEventListener('click', () => activateTab(tab.dataset.tab));
  });

  $('#btn-add-doc').addEventListener('click', () => {
    const job = getJob(selectedJobId);
    if (!job || !canRole('doc')) {
      toast('ไม่มีสิทธิ์เพิ่มเอกสารในมุมมองนี้');
      return;
    }
    const remaining = DRIVE_TEMPLATES.find(n => !job.documents.some(d => d.name === n));
    const name = remaining || 'เอกสารเพิ่มเติม';
    job.documents.push({ id: 'dx' + Date.now(), name, addedAt: nowIso() });
    pushHistory(job, 'drive', `เพิ่ม ${name}`);
    saveState();
    renderDrive(job);
    toast(`เพิ่ม ${name} ใน Drive (demo)`);
  });

  $('#btn-line-notify').addEventListener('click', () => {
    const job = getJob(selectedJobId);
    if (!job) return;
    if (!canRole('line')) {
      toast('สลับมุมมองเป็น Admin หรือ Sales');
      return;
    }
    let tpl = LINE_TEMPLATES[1];
    if (job.stage === 'handover') tpl = LINE_TEMPLATES[2];
    else if (job.stage === 'line_group' || job.stage === 'install') tpl = LINE_TEMPLATES[1];
    const text = tpl.text.replace('{code}', job.id).replace('{customer}', job.customer).replace('{location}', job.location);
    job.lineMessages.push({ at: nowIso(), text });
    pushHistory(job, 'line_group', tpl.label);
    saveState();
    renderLineLog(job);
    toast('ส่งแจ้ง LINE Group (demo)');
  });

  $('#role-select').addEventListener('change', (e) => {
    currentRole = e.target.value;
    renderJobDetail();
    toast('มุมมอง: ' + e.target.selectedOptions[0].textContent);
  });

  $('#btn-reset-demo').addEventListener('click', () => {
    if (!confirm('รีเซ็ตข้อมูล demo ทั้งหมด?')) return;
    localStorage.removeItem(STORAGE_KEY);
    state = loadState();
    selectedJobId = state.jobs[0]?.id || null;
    renderAll();
    if (selectedJobId) selectJob(selectedJobId);
    else {
      $('#job-detail').classList.add('hidden');
      $('#job-empty').classList.remove('hidden');
    }
    toast('รีเซ็ต demo แล้ว');
  });

  activateTab('progress');
  renderAll();
  if (selectedJobId) selectJob(selectedJobId);
})();
