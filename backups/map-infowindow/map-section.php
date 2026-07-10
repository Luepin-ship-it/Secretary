  <!-- ===== หน้า Map: Owner / Lead + Clustering (BACKUP — info window) ===== -->
  <section id="page-map" class="page hidden flex flex-col min-h-0" style="height: calc(100dvh - 6.5rem);">
    <div class="px-4 pt-3 pb-2 shrink-0 space-y-2">
      <div class="flex items-center justify-between">
        <h1 class="text-lg font-bold">แผนที่</h1>
        <p class="text-[10px] text-[var(--faint)] flex items-center gap-1">
          <i data-lucide="layers" class="w-3 h-3"></i>
          ซูมเข้าเพื่อดูรายละเอียด
        </p>
      </div>
      <div class="flex gap-2 flex-wrap">
        <button type="button" id="map-chip-owner" data-on="1"
                class="map-chip flex-1 min-w-[30%] flex items-center justify-center gap-1.5 py-2 rounded-xl border text-xs font-bold transition border-[#E2E800] bg-[#E2E800]/15 text-[var(--accent-text)]">
          <i data-lucide="building-2" class="w-3.5 h-3.5"></i>
          Owner <span class="text-[10px] opacity-80">(<?php echo count($map_payload['owner_groups']); ?>)</span>
        </button>
        <button type="button" id="map-chip-lead" data-on="0"
                class="map-chip flex-1 min-w-[30%] flex items-center justify-center gap-1.5 py-2 rounded-xl border text-xs font-bold transition border-[var(--border)] bg-[var(--card)] text-[var(--muted)]">
          <i data-lucide="users" class="w-3.5 h-3.5"></i>
          Lead <span class="text-[10px] opacity-80">(<?php echo (int)($map_payload['lead_total'] ?? 0); ?>)</span>
        </button>
        <button type="button" id="map-chip-project" data-on="0"
                class="map-chip flex-1 min-w-[30%] flex items-center justify-center gap-1.5 py-2 rounded-xl border text-xs font-bold transition border-[var(--border)] bg-[var(--card)] text-[var(--muted)]">
          <i data-lucide="landmark" class="w-3.5 h-3.5"></i>
          โครงการ <span class="text-[10px] opacity-80">(<?php echo count($map_payload['projects']); ?>)</span>
        </button>
      </div>
      <p class="text-[10px] text-[var(--faint)] flex items-center gap-1">
        <i data-lucide="info" class="w-3 h-3 shrink-0"></i>
        เลือกชั้นข้อมูลทีละ 1 · Owner/Lead = CRM · โครงการ = Survey
      </p>
    </div>
    <div id="map-canvas" class="relative flex-1 w-full min-h-[240px] bg-[var(--surface)] border-y border-[var(--border)]">
      <p id="map-loading" class="absolute inset-0 z-10 flex items-center justify-center text-xs text-[var(--muted)] gap-2 pointer-events-none">
        <i data-lucide="loader-circle" class="w-4 h-4 animate-spin"></i>
        กำลังโหลดแผนที่…
      </p>
    </div>
    <div id="map-load-err" class="hidden px-4 py-3 text-xs text-red-400 text-center leading-relaxed"></div>
    <p id="map-api-hint" class="hidden px-4 pb-2 text-[10px] text-[var(--faint)] text-center leading-relaxed">
      ถ้าแผนที่ว่าง: เปิด <b class="text-[var(--text-2)]">Maps JavaScript API</b> ใน Google Cloud แล้วเพิ่ม Referrer
      <span class="font-mono">localhost/*</span> และ <span class="font-mono">*.ngrok-free.dev/*</span>
    </p>
  </section>
