/* หน้า Map — แผนที่บน + แผงรายละเอียดล่าง + ค้นหา CRM + ฟิลเตอร์ตามชั้น */
(function () {
  const boot = window.MAP_BOOT || { data: { owner_groups: [], lead_groups: [], projects: [], center: { lat: 13.7563, lng: 100.5018 } }, apiKey: '', mapId: '' };

  let map = null;
  let clusterer = null;
  let ownerMarkers = [];
  let leadMarkers = [];
  let projectMarkers = [];
  let mapsLoading = false;
  let inited = false;

  let selected = null;
  let leadFilter = { mode: 'preset', days: 30 };
  let filteredLeadGroups = [];
  let filteredOwnerGroups = [];
  let filteredProjects = [];
  let dragSplit = null;
  let userLocationMarker = null;
  let userLocationCircle = null;
  let geoHintTimer = null;
  let bboxFetchTimer = null;
  let bboxFetching = false;
  let bboxFetchGen = 0;
  let skipNextIdleFetch = false;
  let mapResizing = false;
  let lastBboxKey = '';
  let lastDataSig = '';
  let lastMarkerSig = '';
  const allProjects = (boot.data.projects || []).slice();
  let viewportProjects = null;

  let mapFilters = {
    search: '',
    ownerGrade: '',
    projectSegment: '',
    minBed: 0,
    minBath: 0,
  };

  function ownerIcon(sel) {
    return { path: google.maps.SymbolPath.CIRCLE, scale: sel ? 13 : 11, fillColor: '#E2E800', fillOpacity: 1, strokeColor: '#141414', strokeWeight: sel ? 3 : 2 };
  }
  function leadIcon(sel) {
    return { path: google.maps.SymbolPath.CIRCLE, scale: sel ? 11 : 9, fillColor: '#2563eb', fillOpacity: 1, strokeColor: sel ? '#E2E800' : '#ffffff', strokeWeight: sel ? 3 : 2 };
  }
  function projectIcon(sel) {
    return { path: 'M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z', fillColor: '#f43f5e', fillOpacity: 1, strokeColor: sel ? '#E2E800' : '#ffffff', strokeWeight: sel ? 2.5 : 1.5, scale: sel ? 1.35 : 1.2, anchor: new google.maps.Point(12, 22) };
  }

  function leadCodeLabel(code) {
    if (typeof leadCodeForDisplay === 'function') return leadCodeForDisplay(code);
    return String(code ?? '').replace(/-R\d+$/i, '');
  }

  function esc(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function norm(s) {
    return String(s ?? '').toLowerCase().trim();
  }

  function todayStr() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  function parseYmd(s) {
    if (!s || s === '0000-00-00') return null;
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(s));
    if (!m) return null;
    return new Date(+m[1], +m[2] - 1, +m[3]);
  }

  function addDays(ymd, delta) {
    const d = parseYmd(ymd);
    if (!d) return ymd;
    d.setDate(d.getDate() + delta);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  function fmtNum(n) {
    const x = Number(n);
    if (!Number.isFinite(x)) return '-';
    return x.toLocaleString('th-TH');
  }

  function fmtPriceDisplay(raw) {
    const s = String(raw ?? '').trim();
    if (!s || s === '-') return s;
    const digits = s.replace(/[^\d.]/g, '');
    if (digits && /^\d+(\.\d+)?$/.test(digits)) {
      const n = Math.round(parseFloat(digits));
      if (n >= 10000) return fmtNum(n);
    }
    return s;
  }

  function getLeadFilterWindow() {
    if (leadFilter.mode === 'all') return null;
    if (leadFilter.mode === 'range') {
      const from = leadFilter.from || '';
      const to = leadFilter.to || todayStr();
      if (!from) return null;
      return { from, to };
    }
    const days = leadFilter.days || 30;
    const to = todayStr();
    return { from: addDays(to, -(days - 1)), to };
  }

  function filterLeadGroups(groups) {
    const win = getLeadFilterWindow();
    const q = norm(mapFilters.search);
    const out = [];
    groups.forEach(g => {
      const items = (g.items || []).filter(it => {
        if (win) {
          const cd = it.contact_date;
          if (!cd || cd < win.from || cd > win.to) return false;
        }
        if (!q) return true;
        const blob = norm([g.project, leadCodeLabel(it.lead_code), it.name, it.unit, it.status].join(' '));
        return blob.includes(q);
      });
      if (items.length) out.push({ ...g, items });
    });
    return out;
  }

  function filterOwnerGroups(groups) {
    const q = norm(mapFilters.search);
    const grade = mapFilters.ownerGrade;
    const out = [];
    groups.forEach(g => {
      const items = (g.items || []).filter(it => {
        if (grade && (it.grade || 'B') !== grade) return false;
        if (!q) return true;
        const blob = norm([g.project, it.code, it.name, it.unit, it.price, it.property_type, it.zone].join(' '));
        return blob.includes(q);
      });
      if (items.length) out.push({ ...g, items });
    });
    return out;
  }

  function filterProjects(list) {
    const src = list ?? viewportProjects ?? allProjects;
    const q = norm(mapFilters.search);
    const seg = norm(mapFilters.projectSegment);
    const minBed = mapFilters.minBed || 0;
    const minBath = mapFilters.minBath || 0;
    return (src || []).filter(p => {
      if (seg && norm(p.segment) !== seg) return false;
      if (minBed > 0 && (p.max_bed || 0) < minBed) return false;
      if (minBath > 0 && (p.max_bath || 0) < minBath) return false;
      if (!q) return true;
      const blob = norm([p.name, p.name_en, p.developer, p.segment].join(' '));
      return blob.includes(q);
    });
  }

  function sliderFilterLabel(v) {
    const n = parseInt(v, 10) || 0;
    return n > 0 ? n + '+' : 'ทั้งหมด';
  }

  function updateProjSliderLabels() {
    const bedEl = document.getElementById('map-proj-bed-val');
    const bathEl = document.getElementById('map-proj-bath-val');
    if (bedEl) bedEl.textContent = sliderFilterLabel(mapFilters.minBed);
    if (bathEl) bathEl.textContent = sliderFilterLabel(mapFilters.minBath);
  }

  function countLeadItems(groups) {
    return groups.reduce((n, g) => n + (g.items?.length || 0), 0);
  }

  function countOwnerItems(groups) {
    return groups.reduce((n, g) => n + (g.items?.length || 0), 0);
  }

  function activeLayer() {
    if (document.getElementById('map-chip-owner')?.dataset.on === '1') return 'owner';
    if (document.getElementById('map-chip-lead')?.dataset.on === '1') return 'lead';
    if (document.getElementById('map-chip-project')?.dataset.on === '1') return 'project';
    return 'owner';
  }

  function showMapError(msg) {
    document.getElementById('map-loading')?.remove();
    const err = document.getElementById('map-load-err');
    if (err) { err.textContent = msg; err.classList.remove('hidden'); }
    document.getElementById('map-api-hint')?.classList.remove('hidden');
  }

  function hideMapError() {
    document.getElementById('map-load-err')?.classList.add('hidden');
    document.getElementById('map-api-hint')?.classList.add('hidden');
  }

  function nudgeMapResize() {
    if (!map) return;
    mapResizing = true;
    const center = map.getCenter();
    const zoom = map.getZoom();
    google.maps.event.trigger(map, 'resize');
    if (center) map.setCenter(center);
    if (zoom != null) map.setZoom(zoom);
    setTimeout(() => { mapResizing = false; }, 400);
  }

  function scheduleMapResize() {
    clearTimeout(scheduleMapResize._t);
    scheduleMapResize._t = setTimeout(nudgeMapResize, 120);
  }

  function setMapPane(pct) {
    const page = document.getElementById('page-map');
    const sheet = document.getElementById('map-detail-sheet');
    if (!page) return;
    const v = Math.min(72, Math.max(40, pct));
    page.style.setProperty('--map-flex', String(v / (100 - v)));
    if (sheet) sheet.style.flex = '1 1 0%';
    scheduleMapResize();
  }

  function isMapDesktop() {
    return window.matchMedia('(min-width: 1024px)').matches;
  }

  function applySheetLayout(state) {
    const page = document.getElementById('page-map');
    const sheet = document.getElementById('map-detail-sheet');
    if (!page) return;
    if (isMapDesktop()) {
      page.style.removeProperty('--map-flex');
      if (sheet) sheet.style.flex = '';
      scheduleMapResize();
      return;
    }
    if (state === 'idle') {
      page.style.setProperty('--map-flex', '1');
      if (sheet) sheet.style.flex = '0 0 auto';
      scheduleMapResize();
      return;
    }
    setMapPane(state === 'expanded' ? 48 : 58);
  }

  function initMapPane() {
    applySheetLayout('idle');
  }

  function chipStyle(btn, on) {
    btn.dataset.on = on ? '1' : '0';
    btn.classList.toggle('border-[#E2E800]', on);
    btn.classList.toggle('bg-[#E2E800]/15', on);
    btn.classList.toggle('text-[var(--accent-text)]', on);
    btn.classList.toggle('border-[var(--border)]', !on);
    btn.classList.toggle('bg-[var(--card)]', !on);
    btn.classList.toggle('text-[var(--muted)]', !on);
  }

  function presetStyle(btn, on) {
    btn.dataset.on = on ? '1' : '0';
    btn.classList.toggle('border-[#E2E800]', on);
    btn.classList.toggle('bg-[#E2E800]/15', on);
    btn.classList.toggle('text-[var(--accent-text)]', on);
    btn.classList.toggle('border-[var(--border)]', !on);
    btn.classList.toggle('bg-[var(--card)]', !on);
    btn.classList.toggle('text-[var(--muted)]', !on);
  }

  function filterChipStyle(btn, on) {
    btn.dataset.on = on ? '1' : '0';
    btn.classList.toggle('border-[#E2E800]', on);
    btn.classList.toggle('bg-[#E2E800]/15', on);
    btn.classList.toggle('text-[var(--accent-text)]', on);
    btn.classList.toggle('border-[var(--border)]', !on);
    btn.classList.toggle('bg-[var(--card)]', !on);
    btn.classList.toggle('text-[var(--muted)]', !on);
  }

  function updateLeadFilterSummary() {
    const el = document.getElementById('map-lead-filter-summary');
    if (!el) return;
    const win = getLeadFilterWindow();
    const total = boot.data.lead_total ?? countLeadItems(boot.data.lead_groups || []);
    const shown = countLeadItems(filteredLeadGroups);
    if (!win) {
      el.textContent = 'แสดง Lead ทั้งหมด ' + shown + ' รายการ';
      return;
    }
    el.textContent = 'ช่วง ' + win.from + ' ถึง ' + win.to + ' · แสดง ' + shown + ' จาก ' + total + ' Lead';
  }

  function updateFilterSummary() {
    const el = document.getElementById('map-filter-summary');
    if (!el) return;
    const layer = activeLayer();
    const q = mapFilters.search.trim();
    let text = '';
    if (layer === 'owner') {
      const total = boot.data.owner_total ?? countOwnerItems(boot.data.owner_groups || []);
      const shown = countOwnerItems(filteredOwnerGroups);
      text = 'Owner ' + shown + ' หลัง';
      if (shown !== total) text += ' (จาก ' + total + ')';
    } else if (layer === 'lead') {
      const total = boot.data.lead_total ?? countLeadItems(boot.data.lead_groups || []);
      const shown = countLeadItems(filteredLeadGroups);
      text = 'Lead ' + shown + ' รายการ';
      if (shown !== total) text += ' (จาก ' + total + ')';
    } else {
      const total = allProjects.length;
      const shown = filteredProjects.length;
      text = 'Project ' + shown + ' จุด';
      if (shown !== total) text += ' (จาก ' + total + ')';
    }
    if (q) text += ' · ค้นหา "' + q + '"';
    if (layer === 'project') {
      if (mapFilters.minBed > 0) text += ' · นอน ' + mapFilters.minBed + '+';
      if (mapFilters.minBath > 0) text += ' · น้ำ ' + mapFilters.minBath + '+';
    }
    el.textContent = text;
    el.classList.toggle('hidden', !q && layer === 'owner' && !mapFilters.ownerGrade && layer !== 'project');
    if (layer === 'project' && (mapFilters.projectSegment || mapFilters.minBed || mapFilters.minBath || q)) el.classList.remove('hidden');
    if (layer === 'owner' && (mapFilters.ownerGrade || q)) el.classList.remove('hidden');
    if (layer === 'lead' && q) el.classList.remove('hidden');
  }

  function updateLeadCountBadge() {
    const el = document.getElementById('map-lead-count');
    if (el) el.textContent = '(' + (boot.data.lead_total ?? countLeadItems(filteredLeadGroups)) + ')';
  }

  function toggleLayerFilters(layer) {
    document.getElementById('map-lead-filter')?.classList.toggle('hidden', layer !== 'lead');
    document.getElementById('map-owner-filter')?.classList.toggle('hidden', layer !== 'owner');
    document.getElementById('map-project-filter')?.classList.toggle('hidden', layer !== 'project');
  }

  function refreshIcons(root) {
    if (window.lucide && root) lucide.createIcons({ nodes: [root] });
  }

  function setSheetState(state) {
    document.getElementById('map-detail-sheet')?.setAttribute('data-state', state);
    applySheetLayout(state);
  }

  function coordKey(lat, lng) {
    return Number(lat).toFixed(4) + '|' + Number(lng).toFixed(4);
  }

  function projectMergeKey(project) {
    const pk = norm(project || '');
    if (!pk || pk === norm('ไม่ระบุโครงการ')) return '';
    return pk;
  }

  function stableProjectId(project) {
    const pk = projectMergeKey(project);
    if (!pk) return 'pin_unknown';
    let h = 0;
    for (let i = 0; i < pk.length; i++) h = ((h << 5) - h + pk.charCodeAt(i)) | 0;
    return 'mp_' + Math.abs(h).toString(36);
  }

  /** รวมกลุ่มที่ lat/lng ใกล้กัน (4 ทศนิยม) → หมุดเดียว */
  function mergeGroupsByCoords(groups) {
    const byCoord = new Map();
    (groups || []).forEach(g => {
      const key = coordKey(g.lat, g.lng);
      if (!byCoord.has(key)) {
        byCoord.set(key, {
          id: 'pin_' + key.replace(/\./g, 'd').replace('|', '_'),
          lat: Number(g.lat),
          lng: Number(g.lng),
          project: g.project || '',
          projects: g.project ? [g.project] : [],
          items: [...(g.items || [])],
        });
        return;
      }
      const merged = byCoord.get(key);
      merged.items.push(...(g.items || []));
      if (g.project && !merged.projects.includes(g.project)) {
        merged.projects.push(g.project);
      }
    });
    return Array.from(byCoord.values()).map(m => {
      if (m.projects.length > 1) {
        m.project = m.projects[0] + ' · +' + (m.projects.length - 1) + ' โครงการ';
      } else if (m.projects.length === 1) {
        m.project = m.projects[0];
      }
      return m;
    });
  }

  /** รวมโครงการชื่อเดียวกัน → หมุดเดียว (เฉลี่ยพิกัด) */
  function mergeGroupsByProject(groups) {
    const loose = [];
    const byProject = new Map();
    (groups || []).forEach(g => {
      const pk = projectMergeKey(g.project);
      if (!pk) {
        loose.push(g);
        return;
      }
      if (!byProject.has(pk)) {
        byProject.set(pk, {
          id: stableProjectId(g.project),
          lat: Number(g.lat),
          lng: Number(g.lng),
          project: g.project || '',
          projects: g.projects?.length ? [...g.projects] : (g.project ? [g.project] : []),
          items: [...(g.items || [])],
        });
        return;
      }
      const merged = byProject.get(pk);
      const prevCount = merged.items.length;
      merged.items.push(...(g.items || []));
      const newCount = (g.items || []).length;
      if (newCount >= prevCount) {
        merged.lat = Number(g.lat);
        merged.lng = Number(g.lng);
      }
      const projs = g.projects?.length ? g.projects : (g.project ? [g.project] : []);
      projs.forEach(p => {
        if (p && !merged.projects.includes(p)) merged.projects.push(p);
      });
    });
    const mergedList = Array.from(byProject.values()).map(m => {
      if (m.projects.length > 1) {
        m.project = m.projects[0] + ' · +' + (m.projects.length - 1) + ' โครงการ';
      } else if (m.projects.length === 1) {
        m.project = m.projects[0];
      }
      return m;
    });
    return loose.concat(mergedList);
  }

  function mergeGroupsForMap(groups) {
    return mergeGroupsByProject(mergeGroupsByCoords(groups));
  }

  function groupsSignature(groups) {
    return (groups || []).map(g =>
      g.id + '@' + coordKey(g.lat, g.lng) + '#' + (g.items?.length || 0)
    ).join(';');
  }

  function projectsSignature(list) {
    return (list || []).map(p => p.id + '@' + coordKey(p.lat, p.lng)).join(';');
  }

  function fetchBboxKey(layer) {
    if (!map) return '';
    const bounds = map.getBounds();
    if (!bounds) return '';
    const ne = bounds.getNorthEast();
    const sw = bounds.getSouthWest();
    const r = n => Number(n).toFixed(4);
    let key = layer + '|' + r(ne.lat()) + '|' + r(sw.lat()) + '|' + r(ne.lng()) + '|' + r(sw.lng());
    if (layer === 'lead') key += '|' + JSON.stringify(leadFilter);
    return key;
  }

  function apiDataSignature(layer, json) {
    if (layer === 'owner') return 'o:' + groupsSignature(json.owner_groups || []);
    if (layer === 'lead') return 'l:' + groupsSignature(json.lead_groups || []);
    return 'p:' + projectsSignature(json.projects || []);
  }

  function computeFilteredGroups() {
    filteredOwnerGroups = mergeGroupsForMap(filterOwnerGroups(boot.data.owner_groups || []));
    filteredLeadGroups = mergeGroupsForMap(filterLeadGroups(boot.data.lead_groups || []));
    filteredProjects = mergeProjectsByCoords(filterProjects());
  }

  function activeGroupsSignature() {
    const layer = activeLayer();
    if (layer === 'owner') return 'o:' + groupsSignature(filteredOwnerGroups);
    if (layer === 'lead') return 'l:' + groupsSignature(filteredLeadGroups);
    return 'p:' + projectsSignature(filteredProjects);
  }

  function resetMarkerIcon(sel) {
    if (!sel?.marker) return;
    if (sel.layer === 'owner') sel.marker.setIcon(ownerIcon(false));
    else if (sel.layer === 'lead') sel.marker.setIcon(leadIcon(false));
    else sel.marker.setIcon(projectIcon(false));
  }

  function setMarkerSelected(marker, layer, on) {
    if (layer === 'owner') marker.setIcon(ownerIcon(on));
    else if (layer === 'lead') marker.setIcon(leadIcon(on));
    else marker.setIcon(projectIcon(on));
  }

  function mergeProjectsByCoords(list) {
    const byCoord = new Map();
    (list || []).forEach(p => {
      const key = coordKey(p.lat, p.lng);
      if (!byCoord.has(key)) {
        byCoord.set(key, {
          id: 'pp_' + key.replace(/\./g, 'd').replace('|', '_'),
          lat: Number(p.lat),
          lng: Number(p.lng),
          entries: [p],
        });
        return;
      }
      byCoord.get(key).entries.push(p);
    });
    return Array.from(byCoord.values()).map(m => {
      if (m.entries.length === 1) {
        return m.entries[0];
      }
      return {
        ...m.entries[0],
        id: m.id,
        lat: m.lat,
        lng: m.lng,
        name: m.entries.map(p => p.name).join(' · '),
        _mergedProjects: m.entries,
      };
    });
  }

  function clearDetail(resetSheet = true) {
    resetMarkerIcon(selected);
    selected = null;
    document.getElementById('map-detail-empty')?.classList.remove('hidden');
    const panel = document.getElementById('map-detail-panel');
    if (panel) { panel.classList.add('hidden'); panel.innerHTML = ''; }
    if (resetSheet) setSheetState('idle');
  }

  function syncSelectedMarker() {
    if (!selected) return;
    let marker = null;
    if (selected.layer === 'owner') marker = ownerMarkers.find(m => m._gid === selected.id);
    else if (selected.layer === 'lead') marker = leadMarkers.find(m => m._lgid === selected.id);
    else marker = projectMarkers.find(m => m._pid === selected.id);
    if (!marker) {
      clearDetail();
      return;
    }
    selected.marker = marker;
    setMarkerSelected(marker, selected.layer, true);
  }

  function renderOwnerPanel(g) {
    const projSub = (g.projects && g.projects.length > 1)
      ? '<p class="text-[10px] text-[var(--muted)]">' + esc(g.projects.join(' · ')) + '</p>'
      : '';
    const rows = (g.items || []).map(it =>
      '<li class="p-3 rounded-xl bg-[var(--bg)] border border-[var(--border)] text-xs leading-relaxed">'
      + '<p class="font-bold text-[var(--text)]"><span class="font-mono">' + esc(it.code) + '</span> · ห้อง ' + esc(it.unit)
      + ' · <span class="inline-flex items-center gap-0.5"><i data-lucide="' + (it.grade === 'A' ? 'flame' : it.grade === 'C' ? 'snowflake' : 'thermometer') + '" class="w-3 h-3"></i>' + esc(it.grade || 'B') + '</span></p>'
      + '<p class="text-[var(--muted)] mt-1">' + esc(it.name) + '</p>'
      + (it.price && it.price !== '-' ? '<p class="font-bold mt-1 text-[var(--text-2)]">' + esc(fmtPriceDisplay(it.price)) + '</p>' : '')
      + '</li>'
    ).join('');
    return ''
      + '<p class="text-[10px] font-bold text-[var(--faint)] flex items-center gap-1"><i data-lucide="building-2" class="w-3 h-3"></i> Owner</p>'
      + '<h2 class="text-base font-bold text-[var(--text)]">' + esc(g.project) + '</h2>'
      + projSub
      + '<p class="text-xs text-[var(--muted)]">' + (g.items || []).length + ' หลังในระบบ</p>'
      + '<ul class="space-y-1.5 mt-1.5">' + rows + '</ul>';
  }

  function renderLeadPanel(g) {
    const projSub = (g.projects && g.projects.length > 1)
      ? '<p class="text-[10px] text-[var(--muted)]">' + esc(g.projects.join(' · ')) + '</p>'
      : '';
    const rows = (g.items || []).map(it =>
      '<li class="p-3 rounded-xl bg-[var(--bg)] border border-[var(--border)] text-xs leading-relaxed">'
      + '<p class="font-bold text-[var(--text)]"><span class="font-mono">' + esc(leadCodeLabel(it.lead_code)) + '</span> · <span>' + esc(it.status || '-') + '</span>'
      + (it.unit && it.unit !== '-' ? ' · ห้อง ' + esc(it.unit) : '') + '</p>'
      + '<p class="text-[var(--text-2)] mt-1 font-semibold">' + esc(it.name) + '</p>'
      + '<p class="text-[10px] text-[var(--faint)] mt-1 flex items-center gap-1"><i data-lucide="calendar" class="w-3 h-3"></i> ติดต่อเข้า ' + esc(it.contact_label || '-') + '</p>'
      + '</li>'
    ).join('');
    return ''
      + '<p class="text-[10px] font-bold text-[var(--faint)] flex items-center gap-1"><i data-lucide="users" class="w-3 h-3"></i> Lead</p>'
      + '<h2 class="text-base font-bold text-[var(--text)]">' + esc(g.project) + '</h2>'
      + projSub
      + '<p class="text-xs text-[var(--muted)]">' + (g.items || []).length + ' Lead ในพิกัดนี้</p>'
      + '<ul class="space-y-1.5 mt-1.5">' + rows + '</ul>';
  }

  function renderProjectPanel(p) {
    if (p._mergedProjects && p._mergedProjects.length > 1) {
      const rows = p._mergedProjects.map(entry =>
        '<li class="p-3 rounded-xl bg-[var(--bg)] border border-[var(--border)] text-xs">'
        + '<p class="font-bold text-[var(--text)]">' + esc(entry.name) + '</p>'
        + (entry.segment ? '<p class="text-[var(--muted)] mt-1">' + esc(entry.segment) + '</p>' : '')
        + '</li>'
      ).join('');
      return ''
        + '<p class="text-[10px] font-bold text-[var(--faint)] flex items-center gap-1"><i data-lucide="landmark" class="w-3 h-3"></i> Project · ' + p._mergedProjects.length + ' จุด</p>'
        + '<ul class="space-y-1.5">' + rows + '</ul>';
    }
    const feePeriod = (p.fee_period || 'yearly') === 'yearly' ? 'รายปี' : (p.fee_period || '');
    const feeTxt = p.common_fee != null ? fmtNum(p.common_fee) + ' บ.' : '-';
    const badges = []
      .concat(p.segment ? '<span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-1 rounded-full bg-pink-500/15 text-pink-300"><i data-lucide="gem" class="w-3 h-3"></i>' + esc(p.segment) + '</span>' : [])
      .concat(p.developer ? '<span class="text-[10px] px-2 py-1 rounded-full bg-[var(--bg)] border border-[var(--border)] text-[var(--muted)]">Dev: ' + esc(p.developer) + '</span>' : [])
      .concat(p.property_type ? '<span class="text-[10px] px-2 py-1 rounded-full bg-[var(--bg)] border border-[var(--border)] text-[var(--muted)]">' + esc(p.property_type) + '</span>' : [])
      .join('');

    const cover = p.cover
      ? '<div class="rounded-xl overflow-hidden border border-[var(--border)] aspect-video bg-[var(--bg)]"><img src="' + esc(p.cover) + '" alt="" class="w-full h-full object-cover" loading="lazy"></div>'
      : '';

    const stats = ''
      + '<div class="grid grid-cols-2 gap-2 bg-[var(--bg)] border border-[var(--border)] rounded-xl p-3 text-xs">'
      + '<div><p class="text-[10px] text-[var(--faint)]">ยูนิตทั้งหมด</p><p class="font-bold">' + esc(p.total_units || 0) + '</p></div>'
      + '<div><p class="text-[10px] text-[var(--faint)]">จำนวนเฟส</p><p class="font-bold">' + esc(p.phases || 0) + '</p></div>'
      + '<div><p class="text-[10px] text-[var(--faint)]">ปีเปิดตัว</p><p class="font-bold">' + esc(p.launch_year || '-') + '</p></div>'
      + '<div><p class="text-[10px] text-[var(--faint)]">ปีสร้างเสร็จ</p><p class="font-bold">' + esc(p.built_year || '-') + '</p></div>'
      + '<div class="col-span-2"><p class="text-[10px] text-[var(--faint)]">ค่าส่วนกลาง</p><p class="font-bold">' + esc(feeTxt) + ' <span class="text-[10px] font-normal text-[var(--muted)]">' + esc(feePeriod) + '</span></p></div>'
      + '</div>';

    const amenities = (p.amenities || []).map(a => {
      const name = typeof a === 'object' ? (a.name || '') : a;
      return '<span class="text-[10px] px-2 py-1 rounded-full border border-[var(--border)] bg-[var(--bg)] text-[var(--text-2)]">' + esc(name) + '</span>';
    }).join('');
    const amenitiesBlock = amenities
      ? '<section><h3 class="text-[10px] font-bold text-[var(--faint)] mb-1.5 flex items-center gap-1"><i data-lucide="sparkles" class="w-3 h-3"></i>สิ่งอำนวยความสะดวก</h3><div class="flex flex-wrap gap-1.5">' + amenities + '</div></section>'
      : '';

    const nearby = (p.nearby || []).map(n =>
      '<li class="flex justify-between text-xs py-1 border-b border-dashed border-[var(--border)] last:border-0"><span class="text-[var(--text-2)]">' + esc(n.name) + '</span><span class="text-[var(--muted)]">' + esc(n.distance) + '</span></li>'
    ).join('');
    const nearbyBlock = nearby
      ? '<section class="bg-[var(--bg)] border border-[var(--border)] rounded-xl p-3"><h3 class="text-[10px] font-bold text-[var(--faint)] mb-1.5 flex items-center gap-1"><i data-lucide="map-pin" class="w-3 h-3"></i>สถานที่ใกล้เคียง</h3><ul>' + nearby + '</ul></section>'
      : '';

    const units = (p.units || []).map(u => {
      const bits = [];
      if (u.land) bits.push('ที่ดิน ' + esc(u.land));
      if (u.living) bits.push('ใช้สอย ' + esc(u.living));
      if (u.bed) bits.push(esc(u.bed) + ' นอน');
      if (u.bath) bits.push(esc(u.bath) + ' น้ำ');
      const price = u.price_open ? '<p class="text-emerald-400 font-bold text-xs mt-1">ราคาเปิด ' + fmtNum(u.price_open) + '</p>' : '';
      return '<div class="bg-[var(--bg)] border border-[var(--border)] rounded-xl p-3 text-xs space-y-1">'
        + '<p class="font-bold">' + esc(u.name || 'ยูนิต') + '</p>'
        + (bits.length ? '<p class="text-[var(--muted)]">' + bits.join(' · ') + '</p>' : '')
        + price + '</div>';
    }).join('');
    const unitsBlock = units
      ? '<section class="space-y-2"><h3 class="text-[10px] font-bold text-[var(--faint)] flex items-center gap-1"><i data-lucide="home" class="w-3 h-3"></i>แบบบ้าน / ยูนิต</h3>' + units + '</section>'
      : '';

    return ''
      + '<p class="text-[10px] font-bold text-[var(--faint)] flex items-center gap-1"><i data-lucide="landmark" class="w-3 h-3"></i> Project · Survey</p>'
      + cover
      + '<h2 class="text-base font-bold text-[var(--text)]">' + esc(p.name) + '</h2>'
      + (p.name_en ? '<p class="text-xs text-[var(--muted)]">' + esc(p.name_en) + '</p>' : '')
      + (badges ? '<div class="flex flex-wrap gap-1.5">' + badges + '</div>' : '')
      + stats
      + amenitiesBlock
      + nearbyBlock
      + unitsBlock
      + '<p class="text-[10px] text-[var(--faint)] text-center pt-1">ข้อมูล Survey — แยกจาก Owner/Lead CRM</p>';
  }

  function findOwnerGroup(id) {
    return filteredOwnerGroups.find(g => g.id === id);
  }

  function findLeadGroup(id) {
    return filteredLeadGroups.find(g => g.id === id);
  }

  function findProject(id) {
    return filteredProjects.find(p => p.id === id);
  }

  function showDetail(layer, data, marker) {
    const empty = document.getElementById('map-detail-empty');
    const panel = document.getElementById('map-detail-panel');
    if (!panel || !data?.id) return;

    if (selected?.id === data.id && selected?.layer === layer) return;

    resetMarkerIcon(selected);
    selected = { layer, id: data.id, marker };
    setMarkerSelected(marker, layer, true);

    if (layer === 'owner') panel.innerHTML = renderOwnerPanel(data);
    else if (layer === 'lead') panel.innerHTML = renderLeadPanel(data);
    else panel.innerHTML = renderProjectPanel(data);

    empty?.classList.add('hidden');
    panel.classList.remove('hidden');
    refreshIcons(panel);
    const tall = layer === 'project' || (data.items?.length || 0) > 2 || layer === 'lead';
    setSheetState(tall ? 'expanded' : 'peek');
    document.getElementById('map-detail-scroll')?.scrollTo({ top: 0 });
  }

  function rebuildOwnerMarkers() {
    ownerMarkers.forEach(m => m.setMap(null));
    ownerMarkers = filteredOwnerGroups.map(g => {
      const n = (g.items || []).length;
      const m = new google.maps.Marker({
        position: { lat: g.lat, lng: g.lng },
        title: g.project + ' (Owner · ' + n + ' หลัง)',
        icon: ownerIcon(false),
      });
      m._gid = g.id;
      m._layer = 'owner';
      m.addListener('click', (e) => {
        if (e?.domEvent?.stopPropagation) e.domEvent.stopPropagation();
        showDetail('owner', g, m);
      });
      return m;
    });
  }

  function rebuildLeadMarkers() {
    leadMarkers.forEach(m => m.setMap(null));
    leadMarkers = filteredLeadGroups.map(g => {
      const n = (g.items || []).length;
      const m = new google.maps.Marker({
        position: { lat: g.lat, lng: g.lng },
        title: g.project + ' (Lead · ' + n + ' รายการ)',
        icon: leadIcon(false),
      });
      m._lgid = g.id;
      m._layer = 'lead';
      m.addListener('click', (e) => {
        if (e?.domEvent?.stopPropagation) e.domEvent.stopPropagation();
        showDetail('lead', g, m);
      });
      return m;
    });
    updateLeadCountBadge();
    updateLeadFilterSummary();
  }

  function rebuildProjectMarkers() {
    projectMarkers.forEach(m => m.setMap(null));
    projectMarkers = filteredProjects.map(p => {
      const m = new google.maps.Marker({
        position: { lat: p.lat, lng: p.lng },
        title: p.name + ' (Project)',
        icon: projectIcon(false),
      });
      m._pid = p.id;
      m._layer = 'project';
      m.addListener('click', (e) => {
        if (e?.domEvent?.stopPropagation) e.domEvent.stopPropagation();
        showDetail('project', p, m);
      });
      return m;
    });
  }

  function rebuildActiveLayerMarkersIfNeeded() {
    const sig = activeGroupsSignature();
    if (sig === lastMarkerSig) return false;
    lastMarkerSig = sig;
    const layer = activeLayer();
    if (layer === 'owner') rebuildOwnerMarkers();
    else if (layer === 'lead') rebuildLeadMarkers();
    else rebuildProjectMarkers();
    return true;
  }

  function applyAllFilters(opts = {}) {
    computeFilteredGroups();

    if (selected) {
      let still = false;
      if (selected.layer === 'owner') still = !!findOwnerGroup(selected.id);
      else if (selected.layer === 'lead') still = !!findLeadGroup(selected.id);
      else still = !!findProject(selected.id);
      if (!still) clearDetail();
    }

    const rebuilt = rebuildActiveLayerMarkersIfNeeded();
    syncSelectedMarker();
    updateFilterSummary();

    if (map && !document.getElementById('page-map')?.classList.contains('hidden') && (rebuilt || opts.forceLayers)) {
      applyLayers();
    }
  }

  function applyLeadFilter() {
    lastBboxKey = '';
    applyAllFilters({ forceLayers: true });
    if (activeLayer() === 'lead') scheduleBboxFetch(300);
  }

  function buildMarkers() {
    computeFilteredGroups();
    lastMarkerSig = '';
    rebuildActiveLayerMarkersIfNeeded();
    updateFilterSummary();
  }

  function getVisibleMarkers() {
    const layer = activeLayer();
    if (layer === 'owner') return ownerMarkers;
    if (layer === 'lead') return leadMarkers;
    if (layer === 'project') return projectMarkers;
    return [];
  }

  function scheduleBboxFetch(delayMs = 350) {
    if (!map || selected) return;
    clearTimeout(bboxFetchTimer);
    bboxFetchTimer = setTimeout(fetchMapData, delayMs);
  }

  async function fetchMapData() {
    if (!map || bboxFetching || selected || mapResizing) return;
    const layer = activeLayer();
    const bboxKey = fetchBboxKey(layer);
    if (!bboxKey || bboxKey === lastBboxKey) return;

    const bounds = map.getBounds();
    if (!bounds) return;

    const ne = bounds.getNorthEast();
    const sw = bounds.getSouthWest();
    const gen = ++bboxFetchGen;
    bboxFetching = true;

    const fd = new FormData();
    fd.append('ajax', 'map_data');
    fd.append('layer', layer);
    fd.append('north', String(ne.lat()));
    fd.append('south', String(sw.lat()));
    fd.append('east', String(ne.lng()));
    fd.append('west', String(sw.lng()));
    if (layer === 'lead') {
      fd.append('lead_mode', leadFilter.mode || 'preset');
      if (leadFilter.mode === 'preset') fd.append('lead_days', String(leadFilter.days || 30));
      if (leadFilter.mode === 'range') {
        fd.append('lead_from', leadFilter.from || '');
        fd.append('lead_to', leadFilter.to || todayStr());
      }
    }

    try {
      const res = await fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' });
      const json = await res.json();
      if (gen !== bboxFetchGen || !json?.success) return;

      const dataSig = apiDataSignature(layer, json);
      lastBboxKey = bboxKey;
      if (dataSig === lastDataSig) return;
      lastDataSig = dataSig;

      if (layer === 'owner') boot.data.owner_groups = json.owner_groups || [];
      else if (layer === 'lead') boot.data.lead_groups = json.lead_groups || [];
      else viewportProjects = json.projects || [];
      applyAllFilters({ forceLayers: true });
    } catch (e) {
      console.warn('map_data fetch failed:', e);
    } finally {
      bboxFetching = false;
    }
  }

  function applyLayers() {
    const visible = getVisibleMarkers();
    if (clusterer) {
      clusterer.clearMarkers();
      if (visible.length) clusterer.addMarkers(visible);
      return;
    }
    [...ownerMarkers, ...leadMarkers, ...projectMarkers].forEach(m => m.setMap(null));
    visible.forEach(m => m.setMap(map));
  }

  function selectMapChip(activeBtn) {
    document.querySelectorAll('.map-chip').forEach(btn => chipStyle(btn, btn === activeBtn));
    const layer = activeBtn.id === 'map-chip-lead' ? 'lead' : activeBtn.id === 'map-chip-project' ? 'project' : 'owner';
    toggleLayerFilters(layer);
    clearDetail();
    lastBboxKey = '';
    lastDataSig = '';
    lastMarkerSig = '';
    skipNextIdleFetch = true;
    applyAllFilters({ forceLayers: true });
    updateFilterSummary();
    if (layer === 'lead') {
      updateLeadFilterSummary();
      updateLeadCountBadge();
    }
    scheduleBboxFetch(300);
  }

  function bindChips() {
    document.querySelectorAll('.map-chip').forEach(btn => {
      if (btn._bound) return;
      btn._bound = true;
      btn.addEventListener('click', () => {
        if (btn.dataset.on === '1') return;
        selectMapChip(btn);
      });
    });
  }

  function resetMapSearch() {
    mapFilters.search = '';
    const s = document.getElementById('map-search');
    if (s) s.value = '';
  }

  function resetOwnerFilters() {
    mapFilters.ownerGrade = '';
    document.querySelectorAll('.map-owner-grade').forEach(b => filterChipStyle(b, (b.dataset.grade || '') === ''));
    resetMapSearch();
    applyAllFilters({ forceLayers: true });
  }

  function resetLeadFilters() {
    leadFilter = { mode: 'preset', days: 30 };
    document.querySelectorAll('.map-lead-date-preset').forEach(b => presetStyle(b, b.dataset.preset === '30'));
    const from = document.getElementById('map-lead-date-from');
    const to = document.getElementById('map-lead-date-to');
    if (from) from.value = '';
    if (to) to.value = '';
    resetMapSearch();
    applyLeadFilter();
  }

  function resetProjectFilters() {
    mapFilters.projectSegment = '';
    mapFilters.minBed = 0;
    mapFilters.minBath = 0;
    document.querySelectorAll('.map-proj-segment').forEach(b => filterChipStyle(b, (b.dataset.segment || '') === ''));
    const bed = document.getElementById('map-proj-bed');
    const bath = document.getElementById('map-proj-bath');
    if (bed) bed.value = '0';
    if (bath) bath.value = '0';
    updateProjSliderLabels();
    resetMapSearch();
    applyAllFilters({ forceLayers: true });
  }

  function bindSearchAndFilters() {
    const search = document.getElementById('map-search');
    if (search && !search._bound) {
      search._bound = true;
      let t = null;
      search.addEventListener('input', () => {
        clearTimeout(t);
        t = setTimeout(() => {
          mapFilters.search = search.value.trim();
          applyAllFilters({ forceLayers: true });
        }, 200);
      });
    }

    document.querySelectorAll('.map-owner-grade').forEach(btn => {
      if (btn._bound) return;
      btn._bound = true;
      btn.addEventListener('click', () => {
        document.querySelectorAll('.map-owner-grade').forEach(b => filterChipStyle(b, b === btn));
        mapFilters.ownerGrade = btn.dataset.grade || '';
        applyAllFilters({ forceLayers: true });
      });
    });

    document.querySelectorAll('.map-proj-segment').forEach(btn => {
      if (btn._bound) return;
      btn._bound = true;
      btn.addEventListener('click', () => {
        document.querySelectorAll('.map-proj-segment').forEach(b => filterChipStyle(b, b === btn));
        mapFilters.projectSegment = btn.dataset.segment || '';
        applyAllFilters({ forceLayers: true });
      });
    });

    const bedSlider = document.getElementById('map-proj-bed');
    const bathSlider = document.getElementById('map-proj-bath');
    if (bedSlider && !bedSlider._bound) {
      bedSlider._bound = true;
      bedSlider.addEventListener('input', () => {
        mapFilters.minBed = parseInt(bedSlider.value, 10) || 0;
        updateProjSliderLabels();
        applyAllFilters({ forceLayers: true });
      });
    }
    if (bathSlider && !bathSlider._bound) {
      bathSlider._bound = true;
      bathSlider.addEventListener('input', () => {
        mapFilters.minBath = parseInt(bathSlider.value, 10) || 0;
        updateProjSliderLabels();
        applyAllFilters({ forceLayers: true });
      });
    }
    updateProjSliderLabels();

    document.getElementById('map-owner-reset')?.addEventListener('click', resetOwnerFilters);
    document.getElementById('map-lead-reset')?.addEventListener('click', resetLeadFilters);
    document.getElementById('map-project-reset')?.addEventListener('click', resetProjectFilters);
  }

  function bindLeadDateFilter() {
    document.querySelectorAll('.map-lead-date-preset').forEach(btn => {
      if (btn._bound) return;
      btn._bound = true;
      btn.addEventListener('click', () => {
        document.querySelectorAll('.map-lead-date-preset').forEach(b => presetStyle(b, b === btn));
        const p = btn.dataset.preset;
        if (p === 'all') leadFilter = { mode: 'all' };
        else leadFilter = { mode: 'preset', days: parseInt(p, 10) || 30 };
        applyLeadFilter();
      });
    });

    document.getElementById('map-lead-date-apply')?.addEventListener('click', () => {
      const from = document.getElementById('map-lead-date-from')?.value || '';
      const to = document.getElementById('map-lead-date-to')?.value || todayStr();
      if (!from) return;
      document.querySelectorAll('.map-lead-date-preset').forEach(b => presetStyle(b, false));
      leadFilter = { mode: 'range', from, to };
      applyLeadFilter();
    });
  }

  function bindSheetDrag() {
    const handle = document.getElementById('map-sheet-handle');
    const stage = document.getElementById('map-stage');
    if (!handle || !stage || handle._bound) return;
    handle._bound = true;

    const start = (clientY) => {
      if (isMapDesktop()) return;
      const page = document.getElementById('page-map');
      const mapFlex = parseFloat(getComputedStyle(page).getPropertyValue('--map-flex')) || 1.38;
      const pct = (mapFlex / (mapFlex + 1)) * 100;
      dragSplit = { startY: clientY, startPct: pct };
    };
    const move = (clientY) => {
      if (!dragSplit) return;
      const dy = clientY - dragSplit.startY;
      const stageH = stage.offsetHeight || 1;
      const deltaPct = (dy / stageH) * 100;
      setMapPane(dragSplit.startPct + deltaPct);
    };
    const end = () => { dragSplit = null; };

    handle.addEventListener('mousedown', e => { start(e.clientY); e.preventDefault(); });
    window.addEventListener('mousemove', e => move(e.clientY));
    window.addEventListener('mouseup', end);
    handle.addEventListener('touchstart', e => { start(e.touches[0].clientY); }, { passive: true });
    window.addEventListener('touchmove', e => { if (dragSplit) move(e.touches[0].clientY); }, { passive: true });
    window.addEventListener('touchend', end);

    handle.addEventListener('click', () => {
      if (isMapDesktop()) return;
      const sheet = document.getElementById('map-detail-sheet');
      const st = sheet?.getAttribute('data-state') || 'idle';
      if (st === 'idle') return;
      setSheetState(st === 'peek' ? 'expanded' : 'peek');
    });
  }

  function showGeoHint(msg) {
    const el = document.getElementById('map-geo-hint');
    if (!el) return;
    el.textContent = msg;
    el.classList.remove('hidden');
    clearTimeout(geoHintTimer);
    geoHintTimer = setTimeout(() => el.classList.add('hidden'), 4000);
  }

  function setMyLocationLoading(on) {
    const btn = document.getElementById('map-my-location');
    if (!btn) return;
    btn.disabled = on;
    btn.classList.toggle('opacity-60', on);
    const icon = btn.querySelector('i');
    if (icon) icon.classList.toggle('animate-spin', on);
  }

  function placeUserLocation(lat, lng, accuracy) {
    if (!map) return;
    const pos = { lat, lng };

    if (userLocationMarker) userLocationMarker.setMap(null);
    if (userLocationCircle) userLocationCircle.setMap(null);

    userLocationMarker = new google.maps.Marker({
      position: pos,
      map,
      title: 'ตำแหน่งของคุณ',
      zIndex: 999,
      icon: {
        path: google.maps.SymbolPath.CIRCLE,
        scale: 9,
        fillColor: '#2563eb',
        fillOpacity: 1,
        strokeColor: '#ffffff',
        strokeWeight: 3,
      },
    });

    const radius = Math.max(accuracy || 0, 25);
    userLocationCircle = new google.maps.Circle({
      map,
      center: pos,
      radius,
      strokeColor: '#2563eb',
      strokeOpacity: 0.45,
      strokeWeight: 1,
      fillColor: '#2563eb',
      fillOpacity: 0.12,
      clickable: false,
    });

    map.panTo(pos);
    const z = map.getZoom();
    if (!z || z < 14) map.setZoom(15);
  }

  function goToMyLocation() {
    if (!map) return;
    if (!navigator.geolocation) {
      showGeoHint('เบราว์เซอร์ไม่รองรับตำแหน่งปัจจุบัน');
      return;
    }

    setMyLocationLoading(true);
    showGeoHint('กำลังหาตำแหน่งของคุณ…');

    navigator.geolocation.getCurrentPosition(
      (pos) => {
        setMyLocationLoading(false);
        document.getElementById('map-geo-hint')?.classList.add('hidden');
        placeUserLocation(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy);
      },
      (err) => {
        setMyLocationLoading(false);
        const msgs = {
          1: 'ปฏิเสธการเข้าถึงตำแหน่ง — อนุญาต Location ในเบราว์เซอร์',
          2: 'หาตำแหน่งไม่ได้ชั่วคราว ลองใหม่อีกครั้ง',
          3: 'หมดเวลาในการหาตำแหน่ง',
        };
        showGeoHint(msgs[err.code] || 'ไม่สามารถระบุตำแหน่งได้');
      },
      { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 }
    );
  }

  function bindMyLocation() {
    const btn = document.getElementById('map-my-location');
    if (!btn || btn._bound) return;
    btn._bound = true;
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      goToMyLocation();
    });
  }

  function bindResize() {
    const el = document.getElementById('map-canvas-wrap');
    if (!el || el._resizeBound) return;
    el._resizeBound = true;
    if (window.ResizeObserver) {
      new ResizeObserver(() => nudgeMapResize()).observe(el);
    }
    if (!window._mapLayoutMqBound) {
      window._mapLayoutMqBound = true;
      window.matchMedia('(min-width: 1024px)').addEventListener('change', () => {
        const page = document.getElementById('page-map');
        if (!page || page.classList.contains('hidden')) return;
        const st = document.getElementById('map-detail-sheet')?.getAttribute('data-state') || 'idle';
        applySheetLayout(st);
      });
    }
  }

  async function initClusterer() {
    try {
      const lib = await loadClustererScript();
      if (!lib?.MarkerClusterer) return;
      clusterer = new lib.MarkerClusterer({
        map,
        markers: [],
        algorithm: new lib.SuperClusterAlgorithm({ maxZoom: 16, radius: 72 }),
      });
    } catch (e) {
      console.warn('MarkerClusterer unavailable:', e);
      clusterer = null;
    }
  }

  function loadClustererScript() {
    return new Promise((resolve, reject) => {
      if (window.markerClusterer?.MarkerClusterer) {
        resolve(window.markerClusterer);
        return;
      }
      const timer = setTimeout(() => reject(new Error('MarkerClusterer load timeout')), 12000);
      const s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/@googlemaps/markerclusterer@2.5.3/dist/index.umd.js';
      s.async = true;
      s.onload = () => { clearTimeout(timer); resolve(window.markerClusterer); };
      s.onerror = () => { clearTimeout(timer); reject(new Error('MarkerClusterer script error')); };
      document.head.appendChild(s);
    });
  }

  async function initMap() {
    const el = document.getElementById('map-canvas');
    if (!el) return;

    if (map) {
      scheduleMapResize();
      return;
    }

    try {
      hideMapError();
      const c = boot.data.center || { lat: 13.7563, lng: 100.5018 };
      map = new google.maps.Map(el, {
        center: c,
        zoom: 11,
        disableDefaultUI: false,
        zoomControl: true,
        mapTypeControl: false,
        streetViewControl: false,
        fullscreenControl: false,
      });
      document.getElementById('map-loading')?.remove();

      map.addListener('click', () => clearDetail());
      map.addListener('idle', () => {
        if (skipNextIdleFetch) {
          skipNextIdleFetch = false;
          return;
        }
        if (mapResizing || bboxFetching || selected) return;
        scheduleBboxFetch(350);
      });

      buildMarkers();
      await initClusterer();
      bindChips();
      bindSearchAndFilters();
      bindLeadDateFilter();
      bindSheetDrag();
      bindResize();
      bindMyLocation();
      toggleLayerFilters('owner');
      initMapPane();
      applyLayers();
      updateLeadFilterSummary();
      updateFilterSummary();
      refreshIcons(document.getElementById('page-map'));
      inited = true;
      skipNextIdleFetch = true;
      scheduleBboxFetch(400);

      let tilesOk = false;
      const tilesTimer = setTimeout(() => {
        if (!tilesOk) showMapError('แผนที่ว่าง — ตรวจสอบ Maps JavaScript API, Billing และ Referrer ของ API Key (localhost / ngrok)');
      }, 5000);
      google.maps.event.addListenerOnce(map, 'tilesloaded', () => {
        tilesOk = true;
        clearTimeout(tilesTimer);
        hideMapError();
        el.classList.remove('bg-[var(--surface)]');
      });

      scheduleMapResize();
    } catch (e) {
      console.error('initMap failed:', e);
      document.getElementById('map-loading')?.remove();
      showMapError('โหลดแผนที่ไม่สำเร็จ — ตรวจสอบ Google Maps API Key และเปิด Maps JavaScript API');
    }
  }

  function loadMapsScript() {
    if (mapsLoading) return;
    if (window.google?.maps) { initMap(); return; }
    if (!boot.apiKey) {
      showMapError('ยังไม่ได้ตั้งค่า GOOGLE_MAPS_API_KEY ใน config.php');
      return;
    }

    mapsLoading = true;
    const loadTimer = setTimeout(() => {
      if (!map && mapsLoading) {
        mapsLoading = false;
        showMapError('โหลด Google Maps ช้าเกินไป — ตรวจสอบเน็ต, ngrok หรือ API Key');
      }
    }, 20000);
    window.__mapPageReady = () => {
      clearTimeout(loadTimer);
      mapsLoading = false;
      initMap();
    };
    window.gm_authFailure = () => {
      clearTimeout(loadTimer);
      mapsLoading = false;
      showMapError('Maps API Key ไม่ผ่าน — เปิด Maps JavaScript API และอนุญาตโดเมน (localhost / ngrok) ใน Google Cloud Console');
    };

    const s = document.createElement('script');
    s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(boot.apiKey) + '&v=weekly&callback=__mapPageReady';
    s.async = true;
    s.onerror = () => {
      clearTimeout(loadTimer);
      mapsLoading = false;
      showMapError('โหลด Google Maps ไม่สำเร็จ — ตรวจสอบเน็ตหรือ API Key');
    };
    document.head.appendChild(s);
  }

  window.mapPageInit = function () {
    const page = document.getElementById('page-map');
    if (page?.classList.contains('hidden')) return;
    loadMapsScript();
    if (inited && map) scheduleMapResize();
  };

  // switchTab(#map) รันก่อน map-page.js โหลด — ต้อง init ซ้ำเมื่อสคริปต์พร้อม
  (function bootMapIfVisible() {
    const page = document.getElementById('page-map');
    if (!page || page.classList.contains('hidden')) return;
    requestAnimationFrame(() => setTimeout(() => window.mapPageInit(), 0));
  })();
})();
