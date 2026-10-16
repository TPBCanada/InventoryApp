<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/security.php';

if (!isset($_SESSION['username'])) { header('Location: login.php'); exit; }

require_once __DIR__ . '/templates/access_control.php';
require_once __DIR__ . '/utils/geofence_store.php';
require_once __DIR__ . '/utils/config_init.php'; // loads /home2/xserqhmy/.env (GOOGLE_MAPS_API_KEY)
require_once __DIR__ . '/dbusers.php';           // $conn -> xserqhmy_users (timeclock_sites)
require_once __DIR__ . '/utils/timeclock.php';

if (!in_array((int) ($_SESSION['role_id'] ?? 0), GEOFENCE_EDITOR_ROLES, true)) {
  http_response_code(403);
  die("<p style='color:red; text-align:center;'>Access denied. Only admins and managers can edit the geofence.</p>");
}

// --- Save (PRG) ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  app_require_csrf();
  $error = geofence_save(
    $conn,
    $_POST['lat'] ?? null,
    $_POST['lng'] ?? null,
    $_POST['radius'] ?? null,
    (string) ($_POST['label'] ?? ''),
    tc_session_user_id($conn)
  );
  $_SESSION['geofence_flash'] = $error ? ['type' => 'error', 'text' => $error] : ['type' => 'ok', 'text' => 'Geofence saved. Punch-ins now use this location.'];
  header('Location: geofence.php', true, 303);
  exit;
}

$flash = $_SESSION['geofence_flash'] ?? null;
unset($_SESSION['geofence_flash']);

$fence = geofence_get($conn);
$maps_key = (string) (getenv('GOOGLE_MAPS_API_KEY') ?: ($_SERVER['GOOGLE_MAPS_API_KEY'] ?? $_ENV['GOOGLE_MAPS_API_KEY'] ?? ''));

// Google Maps needs its own hosts allowed; replaces the site-wide CSP for this page only.
// Use our own nonce rather than relying on lib/security.php's variables, and hand it to the
// layout so its inline scripts carry the same one.
$cspNonce = base64_encode(random_bytes(16));
$cspNonceAttr = htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8');
header(
  "Content-Security-Policy: " .
  "default-src 'self'; " .
  "script-src 'self' 'nonce-{$cspNonce}' https://cdn.jsdelivr.net https://maps.googleapis.com https://maps.gstatic.com; " .
  "connect-src 'self' https://cdn.jsdelivr.net https://maps.googleapis.com https://maps.gstatic.com; " .
  "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
  "font-src 'self' https://fonts.gstatic.com; " .
  "img-src 'self' data: blob: https://cdn.shopify.com https://maps.googleapis.com https://maps.gstatic.com https://*.googleapis.com https://*.gstatic.com; " .
  "worker-src blob:; " .
  "object-src 'none'; " .
  "frame-ancestors 'self'; " .
  "base-uri 'self';",
  true
);

$title = 'Geofence';
$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$ROOT_ASSET_BASE = $BASE_URL;
$css = ['/refactor_css/geofence.css'];
$js = ['/js/geofence-admin.js'];
$page_class = 'page-welcome';
$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

ob_start();
?>
<a alt="back to main" href="<?= $BASE_URL ?>/dashboard.php" class="btn btn-ghost back-to-main">
    ↩ Back to Main
</a>

<section class="card card--wide gf">
  <div class="gf__head">
    <h2>Punch-in geofence</h2>
    <p class="gf__sub">Staff can only punch in from inside this circle. Drag the circle to move it, drag its edge to resize, or click the map to move the centre.</p>
  </div>

  <?php if ($flash): ?>
    <p class="gf__flash gf__flash--<?= $h($flash['type']) ?>" role="status"><?= $h($flash['text']) ?></p>
  <?php endif; ?>

  <?php if ($fence['lat'] === null): ?>
    <p class="gf__flash gf__flash--warn">No geofence is set yet, so every punch-in is refused. Set the location below and save.</p>
  <?php endif; ?>

  <?php if ($maps_key === ''): ?>
    <p class="gf__flash gf__flash--warn">
      The map is off because <code>GOOGLE_MAPS_API_KEY</code> isn't set on the server. You can still enter coordinates below.
    </p>
  <?php else: ?>
    <form class="gf__search" id="gfSearch" onsubmit="return false;">
      <input id="gfAddress" type="search" placeholder="Search an address, e.g. warehouse street address" autocomplete="off">
      <button id="gfFind" type="submit" class="btn">Find</button>
    </form>
    <div id="gfMap" class="gf__map" aria-label="Geofence map"></div>
    <p id="gfMapMsg" class="gf__map-msg" role="status" hidden></p>
  <?php endif; ?>

  <form method="post" action="geofence.php" class="gf__form" id="gfForm">
    <input type="hidden" name="csrf_token" value="<?= $h(app_csrf_token()) ?>">

    <label class="gf__wide">Name (optional)
      <input name="label" type="text" maxlength="120" value="<?= $h($fence['label']) ?>" placeholder="e.g. Main warehouse">
    </label>
    <label>Latitude
      <input id="gfLat" name="lat" type="number" step="0.000001" min="-90" max="90" required value="<?= $h($fence['lat'] ?? '') ?>">
    </label>
    <label>Longitude
      <input id="gfLng" name="lng" type="number" step="0.000001" min="-180" max="180" required value="<?= $h($fence['lng'] ?? '') ?>">
    </label>
    <label>Radius (metres)
      <input id="gfRadius" name="radius" type="number" step="10" min="<?= GEOFENCE_MIN_RADIUS ?>" max="<?= GEOFENCE_MAX_RADIUS ?>" required value="<?= $h($fence['radius']) ?>">
    </label>

    <div class="gf__actions gf__wide">
      <button id="gfHere" type="button" class="btn">Use my current location</button>
      <span class="gf__spacer"></span>
      <button type="submit" class="btn btn--primary">Save geofence</button>
    </div>

    <?php if ($fence['updatedAt'] !== ''): ?>
      <p class="gf__meta gf__wide">Last saved <?= $h($fence['updatedAt']) ?> by <?= $h($fence['updatedBy']) ?></p>
    <?php endif; ?>
  </form>
</section>
<?php
$content = ob_get_clean();
$footer_js = '<script nonce="' . $cspNonceAttr . '">window.geofenceConfig = ' . json_encode([
  'mapsKey' => $maps_key,
  'nonce'   => $cspNonce,
  'minRadius' => GEOFENCE_MIN_RADIUS,
  'maxRadius' => GEOFENCE_MAX_RADIUS,
], JSON_HEX_TAG) . ';</script>';

include __DIR__ . '/templates/layout.php';
