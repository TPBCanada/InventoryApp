<?php
// Punch clock FAB, punch modal and Set/Change PIN modal, shown on every page for signed-in users.
// Included once by each module's templates/layout.php just before </body>.
// Styles: refactor_css/punch_clock.css. Behaviour: js/punch-clock.js (punching) and js/punch-pin.js (PIN modal).
// Config is passed as data-* attributes (no inline script), so it works under every module's CSP.

if (defined('PUNCH_CLOCK_RENDERED') || empty($_SESSION['username'])) {
    return;
}
define('PUNCH_CLOCK_RENDERED', true);

// App root URL (e.g. /dev or ''), worked out from disk: modules define APP_ROOT_URL inconsistently.
$punch_root = (static function (): string {
    $app = realpath(dirname(__DIR__));
    $doc = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($app === false || $doc === false || strpos($app, $doc) !== 0) {
        return '';
    }
    return rtrim(str_replace('\\', '/', substr($app, strlen($doc))), '/');
})();
$punch_asset = static function (string $path) use ($punch_root): string {
    $file = dirname(__DIR__) . $path;
    return htmlspecialchars($punch_root . $path . (is_file($file) ? '?v=' . filemtime($file) : ''), ENT_QUOTES, 'UTF-8');
};
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$punch_csrf = htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
?>
<link rel="stylesheet" href="<?= $punch_asset('/refactor_css/punch_clock.css') ?>" />

<button id="punchFab" class="punch-fab" type="button" title="Punch In" aria-haspopup="dialog" aria-controls="punchModal"
  data-endpoint="<?= htmlspecialchars($punch_root . '/utils/punch_api.php', ENT_QUOTES, 'UTF-8') ?>"
  data-csrf="<?= $punch_csrf ?>">
  <span class="punch-fab__dot" aria-hidden="true"></span>
  <span class="punch-fab__label">Punch In</span>
  <span class="punch-fab__timer"></span>
</button>

<!-- Forgot-to-punch-out banner: shown by js/punch-clock.js when the open punch is past its alert time. -->
<div id="punchLateBanner" class="punch-late" role="alert" hidden>
  <p class="punch-late__text">
    <strong>Still clocked in?</strong>
    <span id="punchLateDetail"></span>
  </p>
  <div class="punch-late__actions">
    <button type="button" class="punch-late__btn punch-late__btn--primary" data-punch-late-out>Punch Out</button>
    <button type="button" class="punch-late__btn" data-punch-late-snooze>Still working</button>
  </div>
</div>

<div id="punchModal" class="punch-modal" aria-hidden="true">
  <div class="punch-modal__backdrop" data-punch-close></div>
  <div class="punch-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="punchModalTitle">
    <div class="punch-modal__header">
      <div>
        <h3 id="punchModalTitle">Time clock</h3>
        <p><?= htmlspecialchars((string) ($_SESSION['username'] ?? '')) ?></p>
      </div>
      <button type="button" class="punch-modal__close" data-punch-close aria-label="Close time clock">&times;</button>
    </div>

    <div class="punch-modal__body">
      <div id="punchStatusView">
        <div class="punch-status">
          <span id="punchStateLabel" class="punch-status__state">Clocked Out</span>
          <p id="punchClock" class="punch-status__clock">--:--</p>
          <p id="punchSince" class="punch-status__since">&nbsp;</p>
        </div>

        <button id="punchAction" type="button" class="punch-action">Punch In</button>

        <div class="punch-log">
          <h4>Today</h4>
          <ul id="punchLogList">
            <li class="punch-log__empty">No punches recorded today.</li>
          </ul>
        </div>
      </div>

      <div id="punchPinView" hidden>
        <p id="punchPinHint" class="punch-pin__hint">Enter your 4-digit PIN.</p>
        <div class="punch-pin__inputs">
          <?php for ($i = 1; $i <= 4; $i++): ?>
            <input class="punch-pin__digit" type="password" inputmode="numeric" maxlength="1" autocomplete="off"
              aria-label="PIN digit <?= $i ?>">
          <?php endfor; ?>
        </div>
        <p id="punchPinError" class="punch-pin__error" role="alert" hidden></p>
        <div class="punch-pin__actions">
          <button id="punchPinBack" type="button" class="punch-action punch-action--secondary">Back</button>
          <button id="punchPinConfirm" type="button" class="punch-action" disabled>Confirm</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Set / Change PIN modal -> change_pin.php. Opened by [data-pin-modal-open] buttons or the punch clock when no PIN is set. -->
<div id="pinModal" class="punch-modal" aria-hidden="true">
  <div class="punch-modal__backdrop" data-pin-modal-close></div>
  <div class="punch-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="pinModalTitle">
    <div class="punch-modal__header">
      <div>
        <h3 id="pinModalTitle">Change PIN</h3>
        <p id="pinModalIntro">Your 4-digit PIN for punching in and out.</p>
      </div>
      <button type="button" class="punch-modal__close" data-pin-modal-close aria-label="Close dialog">&times;</button>
    </div>

    <form id="pinChangeFormModal" class="punch-modal__body" method="post"
      action="<?= htmlspecialchars($punch_root . '/change_pin.php', ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="csrf_token" value="<?= $punch_csrf ?>">

      <div class="punch-pin__field">
        <label class="punch-pin__label" for="pinCurrent" id="pinCurrentLabel">Current PIN</label>
        <input id="pinCurrent" class="punch-pin__text" type="password" name="current_pin" autocomplete="off" required>
        <p class="punch-pin__hint" id="pinCurrentHint" hidden>The password you use to sign in.</p>
      </div>

      <?php foreach (['new_pin' => 'New PIN', 'confirm_pin' => 'Confirm new PIN'] as $pin_name => $pin_label): ?>
        <div class="punch-pin__field">
          <span class="punch-pin__label" id="<?= $pin_name ?>Label"><?= $pin_label ?></span>
          <div class="punch-pin__inputs" data-pin-group="<?= $pin_name ?>" role="group" aria-labelledby="<?= $pin_name ?>Label">
            <?php for ($i = 1; $i <= 4; $i++): ?>
              <input class="punch-pin__digit" type="password" inputmode="numeric" maxlength="1" autocomplete="off"
                aria-label="<?= $pin_label ?> digit <?= $i ?>">
            <?php endfor; ?>
          </div>
          <input type="hidden" name="<?= $pin_name ?>">
        </div>
      <?php endforeach; ?>

      <p id="pinChangeAlertModal" class="punch-msg" role="status" aria-live="polite"></p>

      <button type="submit" class="punch-action" disabled>Update PIN</button>
    </form>
  </div>
</div>

<script defer src="<?= $punch_asset('/js/punch-pin.js') ?>"></script>
<script defer src="<?= $punch_asset('/js/punch-clock.js') ?>"></script>
