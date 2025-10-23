<?php
// scan.php — Camera barcode scanner + SKU whereabouts lookup
session_start();
require_once __DIR__ . '/dbinv.php';

// OPTIONAL auth gate:
// if (!isset($_SESSION['username'])) { header('Location: /login.php'); exit; }

// ---------- AJAX endpoint: GET ?ajax=1&code=123456 ---------- //
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
  header('Content-Type: application/json; charset=utf-8');
  $code = trim($_GET['code'] ?? '');

  if ($code === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing code']); exit;
  }

  // 1) Find SKU by sku_num (adjust to also check UPC if you have it)
  $skuSql = "
    SELECT id, sku_num, `desc`, `status`, COALESCE(quantity, 0) AS sku_quantity
    FROM sku
    WHERE sku_num = ?
      -- OR upc_code = ?   -- (uncomment + bind second param if you have this column)
    LIMIT 1
  ";
  $skuStmt = $conn->prepare($skuSql);
  if (!$skuStmt) {
    echo json_encode(['ok'=>false,'error'=>'SQL prepare failed (sku)']); exit;
  }
  $skuStmt->bind_param('s', $code);
  $skuStmt->execute();
  $sku = $skuStmt->get_result()->fetch_assoc();
  $skuStmt->close();

  if (!$sku) {
    echo json_encode(['ok'=>false,'error'=>'SKU not found for code: '.$code]); exit;
  }

  // 2) Location breakdown from inventory_movements (current on-hand per location)
  //    Sum movements per loc; hide zero/negative.
  $locSql = "
    SELECT 
      l.id                  AS loc_id,
      l.row_code, l.bay_num, l.level_code, l.side,
      CONCAT(l.row_code,'-',l.bay_num,'-',l.level_code,'-',l.side) AS location_label,
      SUM(m.quantity_change) AS on_hand,
      MAX(m.created_at)      AS last_movement
    FROM inventory_movements m
    JOIN location l   ON l.id = m.loc_id
    WHERE m.sku_id = ?
    GROUP BY l.id, l.row_code, l.bay_num, l.level_code, l.side
    HAVING on_hand > 0
    ORDER BY l.row_code, l.bay_num, l.level_code, l.side
  ";
  $locStmt = $conn->prepare($locSql);
  if (!$locStmt) {
    echo json_encode(['ok'=>false,'error'=>'SQL prepare failed (locations)']); exit;
  }
  $locStmt->bind_param('i', $sku['id']);
  $locStmt->execute();
  $locRes = $locStmt->get_result();
  $locations = [];
  $total_on_hand = 0;
  while ($row = $locRes->fetch_assoc()) {
    $row['on_hand'] = (int)$row['on_hand'];
    $total_on_hand += $row['on_hand'];
    $locations[] = $row;
  }
  $locStmt->close();

  echo json_encode([
    'ok' => true,
    'sku' => [
      'id'          => (int)$sku['id'],
      'sku_num'     => $sku['sku_num'],
      'desc'        => $sku['desc'],
      'status'      => $sku['status'],
      'sku_quantity'=> (int)$sku['sku_quantity'],
      'scanned_code'=> $code,
    ],
    'total_on_hand' => (int)$total_on_hand,
    'locations'     => $locations,
  ]);
  exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Scan & Locate SKU</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="color-scheme" content="light dark" />
  <style>
    :root{
      --accent: #0dcaf0;
      --card-bg: #fff;
      --text: #222;
      --muted: #6c757d;
      --ok: #198754;
      --bad:#dc3545;
      --ring: rgba(13,202,240,.6);
      --bg: #f7f9fc;
    }
    @media (prefers-color-scheme: dark){
      :root{ --card-bg:#1f2327; --text:#e9ecef; --bg:#0f1114; --muted:#9aa4af; }
    }
    *{ box-sizing:border-box; }
    body{
      margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      background: var(--bg); color: var(--text);
    }
    .wrap{ max-width: 980px; margin: 0 auto; padding: 16px; }
    h1{ font-size: 1.25rem; margin: 0 0 12px; }
    .card{
      background: var(--card-bg);
      border-radius: 14px;
      box-shadow: 0 8px 30px rgba(0,0,0,.08);
      padding: 14px; margin-bottom: 16px;
    }
    .scan-box{
      position: relative; overflow: hidden; border-radius: 12px;
      aspect-ratio: 4/3; background: #000;
    }
    video{ width:100%; height:100%; object-fit: cover; }
    .overlay{
      position:absolute; inset:0; pointer-events:none; display:grid; place-items:center;
    }
    .bracket{
      width: min(80%, 440px); height: min(48%, 240px);
      border: 3px solid var(--ring);
      border-radius: 12px;
      box-shadow: 0 0 0 9999px rgba(0,0,0,.15) inset;
      outline: 2px dashed transparent;
      transition: border-color .15s ease;
    }
    .controls{
      display:flex; gap:10px; flex-wrap: wrap; align-items:center; margin-top:10px;
    }
    .btn{
      appearance:none; border:0; padding:10px 14px; border-radius: 999px;
      font-weight:600; cursor:pointer; background:#222; color:#fff;
    }
    .btn.secondary{ background:#3b3f44; }
    .btn:disabled{ opacity:.6; cursor:not-allowed; }
    input[type="text"]{
      padding:10px 12px; border-radius:10px; border:1px solid #ced4da; min-width: 220px;
      background: transparent; color: inherit;
    }
    .row{ display:grid; grid-template-columns: 1fr; gap:12px; }
    @media (min-width: 720px){
      .row{ grid-template-columns: 1.2fr 1fr; }
    }
    .sku{
      display:grid; grid-template-columns: 120px 1fr; gap:8px; font-size: 0.95rem;
    }
    .sku div strong{ display:block; font-size:.8rem; color: var(--muted); font-weight:600; }
    .badge{ display:inline-block; padding:2px 8px; border-radius:999px; font-size:.75rem; }
    .badge.ok{ background: rgba(25,135,84,.15); color: #28a745; }
    .badge.bad{ background: rgba(220,53,69,.15); color: #dc3545; }
    table{ width:100%; border-collapse: collapse; }
    th, td{ text-align:left; padding:8px 10px; border-bottom:1px solid rgba(0,0,0,.06); }
    th{ font-size:.8rem; color: var(--muted); font-weight:600; }
    .muted{ color: var(--muted); font-size:.9rem; }
    .hint{ font-size:.85rem; color: var(--muted); }
    .toast{
      position: fixed; left:50%; transform: translateX(-50%);
      bottom: 16px; background: var(--card-bg); color: var(--text);
      border:1px solid rgba(0,0,0,.08); padding:10px 14px; border-radius:10px; box-shadow: 0 8px 30px rgba(0,0,0,.2);
      display:none;
    }
  </style>
</head>
<body>
  <div class="wrap">
    <h1>Scan & Locate SKU</h1>

    <div class="card">
      <div class="scan-box" id="scanBox">
        <video id="video" autoplay playsinline muted></video>
        <div class="overlay"><div class="bracket" id="bracket"></div></div>
      </div>
      <div class="controls">
        <button class="btn" id="startBtn">Start Camera</button>
        <button class="btn secondary" id="stopBtn" disabled>Stop</button>
        <input id="manual" type="text" placeholder="Or type/paste a code…" inputmode="numeric" />
        <button class="btn" id="lookupBtn">Lookup</button>
        <span class="hint">Tip: hold steady over the barcode.</span>
      </div>
    </div>

    <div class="row">
      <div class="card" id="skuCard" hidden>
        <div class="sku" id="skuGrid"></div>
      </div>

      <div class="card" id="locCard" hidden>
        <div class="muted" id="summary"></div>
        <div style="overflow:auto; margin-top:8px;">
          <table id="locTable">
            <thead>
              <tr>
                <th>Location</th>
                <th>On-Hand</th>
                <th>Last Movement</th>
              </tr>
            </thead>
            <tbody id="locBody"></tbody>
          </table>
        </div>
      </div>
    </div>

    <p class="hint">
      This page uses the browser’s <code>BarcodeDetector</code> API when available. On older devices,
      use the manual box above. You can also add a library like QuaggaJS later if needed.
    </p>
  </div>

  <div class="toast" id="toast"></div>

  <script>
    // ------- UI helpers ------- //
    const $ = s => document.querySelector(s);
    const toast = (msg, ms=2200) => {
      const el = $('#toast');
      el.textContent = msg;
      el.style.display = 'block';
      clearTimeout(el._t);
      el._t = setTimeout(() => el.style.display = 'none', ms);
    };

    // ------- Camera + scanning ------- //
    const video     = $('#video');
    const startBtn  = $('#startBtn');
    const stopBtn   = $('#stopBtn');
    const manual    = $('#manual');
    const lookupBtn = $('#lookupBtn');
    const bracket   = $('#bracket');

    let stream = null;
    let scanning = false;
    let raf = null;
    let detector = null;
    let lastValue = '';
    let cooldown = 0;

    async function startCamera(){
      try {
        stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: { ideal: 'environment' } , width: { ideal: 1280 }, height: { ideal: 720 } },
          audio: false
        });
        video.srcObject = stream;
        startBtn.disabled = true;
        stopBtn.disabled = false;
        scanning = true;

        if ('BarcodeDetector' in window) {
          const types = ['ean_13','ean_8','upc_a','upc_e','code_128','code_39','qr_code','itf'];
          try { detector = new BarcodeDetector({ formats: types }); }
          catch { detector = new BarcodeDetector(); }
          scanLoop();
        } else {
          toast('BarcodeDetector not supported. Use manual entry.');
        }
      } catch (e) {
        console.error(e);
        toast('Camera access failed. Check permissions.');
      }
    }

    function stopCamera(){
      scanning = false;
      if (raf) cancelAnimationFrame(raf);
      if (stream) {
        for (const t of stream.getTracks()) t.stop();
      }
      stream = null;
      startBtn.disabled = false;
      stopBtn.disabled  = true;
    }

    async function scanLoop(){
      if (!scanning || !detector) return;
      try {
        // throttle decode a bit
        if (cooldown > 0) { cooldown--; raf = requestAnimationFrame(scanLoop); return; }
        const barcodes = await detector.detect(video);
        if (barcodes && barcodes.length) {
          const value = (barcodes[0].rawValue || '').trim();
          if (value && value !== lastValue) {
            lastValue = value;
            bracket.style.borderColor = '#28a745';
            manual.value = value;
            lookup();
            cooldown = 10; // small pause
            setTimeout(() => bracket.style.borderColor = 'var(--ring)', 800);
          }
        }
      } catch (e) {
        // Silent errors are common while camera warms up
      }
      raf = requestAnimationFrame(scanLoop);
    }

    startBtn.addEventListener('click', startCamera);
    stopBtn.addEventListener('click', stopCamera);
    lookupBtn.addEventListener('click', () => lookup());

    manual.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') lookup();
    });

    // ------- Lookup ------- //
    async function lookup(){
      const code = manual.value.trim();
      if (!code) { toast('Enter or scan a code.'); return; }

      try {
        const url = new URL(window.location.href);
        url.searchParams.set('ajax','1');
        url.searchParams.set('code', code);
        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' }});
        const data = await res.json();
        if (!data.ok) {
          showSku(null);
          showLocations([], 0);
          toast(data.error || 'Not found.');
          return;
        }
        showSku(data.sku);
        showLocations(data.locations || [], data.total_on_hand || 0);
      } catch (e) {
        console.error(e);
        toast('Lookup failed. Network error?');
      }
    }

    function showSku(sku){
      const card = $('#skuCard');
      const grid = $('#skuGrid');
      if (!sku) { card.hidden = true; grid.innerHTML = ''; return; }
      const active = (sku.status || '').toUpperCase() === 'ACTIVE';
      grid.innerHTML = `
        <div><strong>Scanned Code</strong>${escapeHtml(sku.scanned_code || '')}</div>
        <div></div>
        <div><strong>SKU #</strong>${escapeHtml(sku.sku_num || '')}</div>
        <div><strong>Status</strong>
          <span class="badge ${active?'ok':'bad'}">${escapeHtml(sku.status || 'UNKNOWN')}</span>
        </div>
        <div><strong>Description</strong>${escapeHtml(sku.desc || '')}</div>
        <div><strong>SKU Qty (global)</strong>${Number(sku.sku_quantity ?? 0)}</div>
      `;
      card.hidden = false;
    }

    function showLocations(rows, total){
      const card = $('#locCard');
      const body = $('#locBody');
      const sum  = $('#summary');
      body.innerHTML = '';
      if (!rows || !rows.length){
        sum.textContent = 'No stock on hand across locations.';
        card.hidden = false;
        return;
      }
      sum.textContent = `Locations with stock (Total on-hand: ${Number(total)})`;
      for (const r of rows){
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${escapeHtml(r.location_label)}</td>
          <td>${Number(r.on_hand)}</td>
          <td class="muted">${escapeHtml(r.last_movement || '')}</td>
        `;
        body.appendChild(tr);
      }
      card.hidden = false;
    }

    function escapeHtml(s){
      return String(s ?? '').replace(/[&<>"']/g, m => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
      }[m]));
    }

    // Auto-start camera on load if permissions were previously granted
    document.addEventListener('DOMContentLoaded', async () => {
      try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const hasCam = devices.some(d => d.kind === 'videoinput');
        if (hasCam) {
          // Don’t auto-start on desktop browsers that might prompt—leave it to the user
          // If you prefer auto-start on mobile, uncomment:
          // startCamera();
        }
      } catch {}
    });
  </script>
</body>
</html>
