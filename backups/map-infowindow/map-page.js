/* หน้า Map — Owner / Lead / โครงการ (Survey preview) — BACKUP info window */
(function () {
  const boot = window.MAP_BOOT || { data: { owner_groups: [], lead_groups: [], projects: [], center: { lat: 13.7563, lng: 100.5018 } }, apiKey: '', mapId: '' };
  let map = null;
  let clusterer = null;
  let infoWindow = null;
  let ownerMarkers = [];
  let leadMarkers = [];
  let projectMarkers = [];
  let mapsLoading = false;
  let inited = false;

  function esc(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function showMapError(msg) {
    const err = document.getElementById('map-load-err');
    if (err) {
      err.textContent = msg;
      err.classList.remove('hidden');
    }
    document.getElementById('map-api-hint')?.classList.remove('hidden');
  }

  function hideMapError() {
    document.getElementById('map-load-err')?.classList.add('hidden');
    document.getElementById('map-api-hint')?.classList.add('hidden');
  }

  function nudgeMapResize() {
    if (!map) return;
    google.maps.event.trigger(map, 'resize');
    fitVisibleMarkers();
  }

  function scheduleMapResize() {
    [80, 280, 600].forEach(ms => setTimeout(nudgeMapResize, ms));
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

  function showOwnerInfo(g) {
    if (!infoWindow) infoWindow = new google.maps.InfoWindow({ maxWidth: 300 });
    const rows = (g.items || []).map(it =>
      '<li style="margin:6px 0;padding:6px 8px;background:#f4f4f5;border-radius:8px;font-size:12px;line-height:1.4">'
      + '<span style="font-weight:700">' + esc(it.code) + '</span> · ห้อง ' + esc(it.unit)
      + '<br><span style="color:#666">' + esc(it.name) + '</span>'
      + (it.price && it.price !== '-' ? '<br><span style="font-weight:600">' + esc(it.price) + '</span>' : '')
      + '</li>'
    ).join('');
    infoWindow.setContent(
      '<div style="font-family:system-ui,sans-serif;padding:4px 2px 8px;max-height:220px;overflow:auto">'
      + '<p style="margin:0 0 4px;font-size:10px;font-weight:700;color:#888">Owner</p>'
      + '<p style="margin:0 0 8px;font-size:14px;font-weight:700">' + esc(g.project) + '</p>'
      + '<p style="margin:0 0 8px;font-size:11px;color:#666">' + (g.items || []).length + ' หลังในระบบ</p>'
      + '<ul style="list-style:none;margin:0;padding:0">' + rows + '</ul></div>'
    );
    infoWindow.setPosition({ lat: g.lat, lng: g.lng });
    const anchor = ownerMarkers.find(m => m._gid === g.id);
    infoWindow.open(anchor ? { map, anchor } : map);
  }

  function showLeadInfo(g) {
    if (!infoWindow) infoWindow = new google.maps.InfoWindow({ maxWidth: 300 });
    const rows = (g.items || []).map(it =>
      '<li style="margin:6px 0;padding:6px 8px;background:#f4f4f5;border-radius:8px;font-size:12px;line-height:1.4">'
      + '<span style="font-weight:700">' + esc(it.lead_code) + '</span> · ' + esc(it.status || '-')
      + (it.unit && it.unit !== '-' ? ' · ห้อง ' + esc(it.unit) : '')
      + '<br><span style="color:#444">' + esc(it.name) + '</span>'
      + '</li>'
    ).join('');
    const n = (g.items || []).length;
    infoWindow.setContent(
      '<div style="font-family:system-ui,sans-serif;padding:4px 2px 8px;max-height:220px;overflow:auto">'
      + '<p style="margin:0 0 4px;font-size:10px;font-weight:700;color:#888">Lead</p>'
      + '<p style="margin:0 0 8px;font-size:14px;font-weight:700">' + esc(g.project) + '</p>'
      + '<p style="margin:0 0 8px;font-size:11px;color:#666">' + n + ' Lead ในโครงการนี้</p>'
      + '<ul style="list-style:none;margin:0;padding:0">' + rows + '</ul></div>'
    );
    infoWindow.setPosition({ lat: g.lat, lng: g.lng });
    const anchor = leadMarkers.find(m => m._lgid === g.id);
    infoWindow.open(anchor ? { map, anchor } : map);
  }

  function showProjectInfo(p) {
    if (!infoWindow) infoWindow = new google.maps.InfoWindow({ maxWidth: 300 });
    const nearby = (p.nearby || []).map(n =>
      '<li style="font-size:11px;margin:4px 0;display:flex;justify-content:space-between"><span>' + esc(n.name) + '</span><span style="color:#666">' + esc(n.distance) + '</span></li>'
    ).join('');
    const detailUrl = p.detail_url || ('project.php?slug=' + encodeURIComponent(p.slug || ''));
    infoWindow.setContent(
      '<div style="font-family:system-ui,sans-serif;padding:4px 2px 8px">'
      + '<p style="margin:0 0 4px;font-size:10px;font-weight:700;color:#888">โครงการ · Survey</p>'
      + '<p style="margin:0 0 6px;font-size:14px;font-weight:700">' + esc(p.name) + '</p>'
      + '<p style="margin:0;font-size:12px;color:#444">' + esc(p.total_units) + ' ยูนิต'
      + (p.common_fee != null ? ' · ค่าส่วนกลาง ' + esc(p.common_fee) + ' บ.' : '') + '</p>'
      + (nearby ? '<ul style="list-style:none;margin:8px 0 0;padding:0;border-top:1px dashed #ddd;padding-top:6px">' + nearby + '</ul>' : '')
      + '<p style="margin:10px 0 0"><a href="' + esc(detailUrl) + '" style="display:inline-block;padding:8px 12px;background:#E2E800;color:#141414;font-size:12px;font-weight:700;border-radius:8px;text-decoration:none">ดูข้อมูลเต็ม</a></p>'
      + '</div>'
    );
    infoWindow.setPosition({ lat: p.lat, lng: p.lng });
    const anchor = projectMarkers.find(m => m._pid === p.id);
    infoWindow.open(anchor ? { map, anchor } : map);
  }

  function buildMarkers() {
    ownerMarkers = (boot.data.owner_groups || []).map(g => {
      const m = new google.maps.Marker({
        position: { lat: g.lat, lng: g.lng },
        title: g.project + ' (Owner)',
        icon: {
          path: google.maps.SymbolPath.CIRCLE,
          scale: 11,
          fillColor: '#E2E800',
          fillOpacity: 1,
          strokeColor: '#141414',
          strokeWeight: 2,
        },
      });
      m._gid = g.id;
      m._layer = 'owner';
      m.addListener('click', () => showOwnerInfo(g));
      return m;
    });

    leadMarkers = (boot.data.lead_groups || []).map(g => {
      const m = new google.maps.Marker({
        position: { lat: g.lat, lng: g.lng },
        title: g.project + ' (Lead)',
        icon: {
          path: google.maps.SymbolPath.CIRCLE,
          scale: 9,
          fillColor: '#2563eb',
          fillOpacity: 1,
          strokeColor: '#ffffff',
          strokeWeight: 2,
        },
      });
      m._lgid = g.id;
      m._layer = 'lead';
      m.addListener('click', () => showLeadInfo(g));
      return m;
    });

    projectMarkers = (boot.data.projects || []).map(p => {
      const m = new google.maps.Marker({
        position: { lat: p.lat, lng: p.lng },
        title: p.name + ' (โครงการ)',
        icon: {
          path: 'M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z',
          fillColor: '#f43f5e',
          fillOpacity: 1,
          strokeColor: '#ffffff',
          strokeWeight: 1.5,
          scale: 1.2,
          anchor: new google.maps.Point(12, 22),
        },
      });
      m._pid = p.id;
      m._layer = 'project';
      m.addListener('click', () => showProjectInfo(p));
      return m;
    });
  }

  function getVisibleMarkers() {
    const showO = document.getElementById('map-chip-owner')?.dataset.on === '1';
    const showL = document.getElementById('map-chip-lead')?.dataset.on === '1';
    const showP = document.getElementById('map-chip-project')?.dataset.on === '1';
    const visible = [];
    if (showO) visible.push(...ownerMarkers);
    if (showL) visible.push(...leadMarkers);
    if (showP) visible.push(...projectMarkers);
    return visible;
  }

  function applyLayers() {
    const visible = getVisibleMarkers();
    const all = [...ownerMarkers, ...leadMarkers, ...projectMarkers];
    if (infoWindow) infoWindow.close();

    if (clusterer) {
      clusterer.clearMarkers();
      if (visible.length) clusterer.addMarkers(visible);
    } else {
      all.forEach(m => m.setMap(null));
      visible.forEach(m => m.setMap(map));
    }
  }

  function fitVisibleMarkers() {
    if (!map) return;
    const visible = getVisibleMarkers();
    if (!visible.length) {
      const c = boot.data.center || { lat: 13.7563, lng: 100.5018 };
      map.setCenter(c);
      map.setZoom(11);
      return;
    }
    const bounds = new google.maps.LatLngBounds();
    visible.forEach(m => bounds.extend(m.getPosition()));
    if (visible.length > 1) map.fitBounds(bounds, 48);
    else {
      map.setCenter(visible[0].getPosition());
      map.setZoom(14);
    }
  }

  function selectMapChip(activeBtn) {
    document.querySelectorAll('.map-chip').forEach(btn => chipStyle(btn, btn === activeBtn));
    applyLayers();
    fitVisibleMarkers();
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

  function bindResize() {
    const el = document.getElementById('map-canvas');
    if (!el || el._resizeBound) return;
    el._resizeBound = true;
    if (window.ResizeObserver) {
      new ResizeObserver(() => {
        if (map) google.maps.event.trigger(map, 'resize');
      }).observe(el);
    }
  }

  async function initClusterer() {
    try {
      const mod = await import('https://unpkg.com/@googlemaps/markerclusterer@2.5.3/dist/index.min.js');
      const MarkerClusterer = mod.MarkerClusterer || mod.default?.MarkerClusterer;
      if (MarkerClusterer) {
        clusterer = new MarkerClusterer({ map, markers: [] });
        return;
      }
    } catch (e) {
      console.warn('MarkerClusterer fallback:', e);
    }
    clusterer = null;
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
      const mapOpts = {
        center: c,
        zoom: 11,
        disableDefaultUI: false,
        zoomControl: true,
        mapTypeControl: false,
        streetViewControl: false,
        fullscreenControl: false,
      };
      map = new google.maps.Map(el, mapOpts);
      buildMarkers();
      await initClusterer();
      bindChips();
      bindResize();
      applyLayers();
      inited = true;

      let tilesOk = false;
      const tilesTimer = setTimeout(() => {
        if (!tilesOk) {
          showMapError('แผนที่ว่าง — ตรวจสอบ Maps JavaScript API, Billing และ Referrer ของ API Key (localhost / ngrok)');
        }
      }, 5000);
      google.maps.event.addListenerOnce(map, 'tilesloaded', () => {
        tilesOk = true;
        clearTimeout(tilesTimer);
        hideMapError();
        el.classList.remove('bg-[var(--surface)]');
        document.getElementById('map-loading')?.remove();
      });

      scheduleMapResize();
    } catch (e) {
      console.error('initMap failed:', e);
      showMapError('โหลดแผนที่ไม่สำเร็จ — ตรวจสอบ Google Maps API Key และเปิด Maps JavaScript API');
    }
  }

  function loadMapsScript() {
    if (mapsLoading) return;
    if (window.google?.maps) {
      initMap();
      return;
    }
    if (!boot.apiKey) {
      showMapError('ยังไม่ได้ตั้งค่า GOOGLE_MAPS_API_KEY ใน config.php');
      return;
    }

    mapsLoading = true;
    window.__mapPageReady = () => {
      mapsLoading = false;
      initMap();
    };
    window.gm_authFailure = () => {
      mapsLoading = false;
      showMapError('Maps API Key ไม่ผ่าน — เปิด Maps JavaScript API และอนุญาตโดเมน (localhost / ngrok) ใน Google Cloud Console');
    };

    const s = document.createElement('script');
    s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(boot.apiKey) + '&v=weekly&callback=__mapPageReady';
    s.async = true;
    s.onerror = () => {
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
})();
