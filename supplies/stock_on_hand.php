<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';

require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/lib/stock_levels.php';

if (!isset($_SESSION['username'])) {
    header('Location: ../login.php');
    exit;
}

$username = $_SESSION['username'];
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$role_id = $_SESSION['role_id'] ?? 0;

require_once __DIR__ . '/templates/access_control.php';

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$title = 'Supplies - Stock on Hand';
$page_class = 'page-supplies page-stock-on-hand';

$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$stock = soh_load_stock_levels($conn);
$groups = $stock['groups'];
$summary = $stock['summary'];
$usage_live = $stock['usage_live'];
$load_error = $stock['error'];
$last_requests = soh_load_last_requests($conn);

// Newest request overall (includes "Other" items that aren't in the catalog)
$latest_request = null;
try {
    $latest_request = $conn->query("
        SELECT id, created_at FROM supply_requests ORDER BY id DESC LIMIT 1
    ")->fetch_assoc();
} catch (\Throwable $e) {
    error_log('[stock_on_hand latest request] ' . $e->getMessage());
}

$not_tracked = 0;
foreach ($groups as $items) {
    foreach ($items as $item) {
        if ($item['on_hand'] === null) {
            $not_tracked++;
        }
    }
}

// Anchor id per category for the left section nav
$section_ids = [];
foreach (array_keys($groups) as $category) {
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $category)), '-') ?: 'section';
    $id = 'cat-' . $slug;
    for ($n = 2; in_array($id, $section_ids, true); $n++) {
        $id = 'cat-' . $slug . '-' . $n;
    }
    $section_ids[$category] = $id;
}

ob_start();
?>

<div class="supplies-dashboard">
  <h2>Stock on Hand</h2>

  <section class="soh-stats" id="soh-overview">
    <article class="card soh-stat">
      <span class="soh-stat__label">Total SKUs</span>
      <strong class="soh-stat__value"><?= (int) $summary['skus'] ?></strong>
    </article>
    <article class="card soh-stat">
      <span class="soh-stat__label">Not tracked</span>
      <strong class="soh-stat__value"><?= (int) $not_tracked ?></strong>
    </article>
    <article class="card soh-stat">
      <span class="soh-stat__label">Last invoice posted</span>
      <strong class="soh-stat__value"><?= $h($summary['last_invoice'] ? substr($summary['last_invoice'], 0, 10) : '—') ?></strong>
    </article>
    <article class="card soh-stat">
      <span class="soh-stat__label">Last request</span>
      <?php if ($latest_request): ?>
        <strong class="soh-stat__value">#<?= (int) $latest_request['id'] ?></strong>
        <span class="muted soh-used"><?= $h(substr((string) $latest_request['created_at'], 0, 10)) ?></span>
      <?php else: ?>
        <strong class="soh-stat__value">—</strong>
      <?php endif; ?>
    </article>
  </section>

  <?php if ($load_error): ?>
    <div class="alert alert--error mb-3"><?= $h($load_error) ?></div>
  <?php endif; ?>

  <?php if ($usage_live): ?>
    <div class="note mb-3 muted">On hand = last invoice quantity minus boxes logged as used since that invoice was received.</div>
  <?php endif; ?>

  <div class="soh-toolbar">
    <input type="search" id="sohSearch" placeholder="Search model, item or size…" autocomplete="off"
      aria-label="Search stock">
    <label class="soh-toolbar__toggle"><input type="checkbox" id="sohHideUntracked"> Hide not tracked</label>
    <span class="muted soh-toolbar__count" id="sohResultCount" aria-live="polite"></span>
  </div>

  <div class="soh-layout">
    <nav class="soh-nav" aria-label="Stock sections">
      <span class="soh-nav__title">Sections</span>
      <ul class="soh-nav__list">
        <li><a class="soh-nav__link" href="#soh-overview">Overview</a></li>
        <?php foreach ($groups as $category => $items): ?>
          <li>
            <a class="soh-nav__link" href="#<?= $h($section_ids[$category]) ?>">
              <span class="soh-nav__label"><?= $h((string) $category) ?></span>
              <span class="soh-nav__count" data-nav-count><?= count($items) ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </nav>

    <div class="soh-sections">
      <div class="note" id="sohNoMatch" hidden>No items match your search.</div>
  <?php foreach ($groups as $category => $items): ?>
    <section class="card card--wide supplies-card soh-section" id="<?= $h($section_ids[$category]) ?>">
      <h3><?= $h(soh_category_label((string) $category)) ?> <span class="muted">(<span data-section-count><?= count($items) ?></span>)</span></h3>
      <div class="table-wrap">
        <table class="table soh-table spaced-table js-sortable">
          <thead>
            <tr>
              <th data-sort="text">Model</th>
              <th data-sort="text">Item</th>
              <th data-sort="text">Dimensions</th>
              <th class="num" data-sort="number">On hand</th>
              <th data-sort="number">Last request</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item): ?>
              <tr class="soh-row" data-tracked="<?= $item['on_hand'] === null ? '0' : '1' ?>"
                data-search="<?= $h(strtolower($item['model_no'] . ' ' . $item['name'] . ' ' . $item['dims'])) ?>">
                <td><?= $h($item['model_no']) ?></td>
                <td><?= $h($item['name']) ?></td>
                <td><?= $h($item['dims']) ?></td>
                <td class="num" data-sort-value="<?= $item['on_hand'] === null ? '' : (int) $item['on_hand'] ?>">
                  <?php if ($item['on_hand'] === null): ?>
                    <span class="muted">—</span>
                  <?php else: ?>
                    <?= (int) $item['on_hand'] ?>
                    <?php if ($item['used'] > 0): ?>
                      <div class="muted soh-used"><?= (int) $item['used'] ?> used since invoice</div>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
                <?php $last = $last_requests[$item['supply_id']] ?? null; ?>
                <td class="soh-last-request" data-sort-value="<?= $last ? (int) $last['request_id'] : '' ?>">
                  <?php if ($last): ?>
                    <strong>#<?= (int) $last['request_id'] ?></strong> · qty <?= (int) $last['qty'] ?>
                    <div class="muted soh-used"><?= $h($last['requested_at']) ?><?= $last['requested_by'] !== '' ? ' · ' . $h($last['requested_by']) : '' ?></div>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php $req_qty = in_array($item['status'], ['low', 'zero'], true) ? $item['request_qty'] : 1; ?>
                  <a class="btn btn-sm btn-primary"
                    href="dashboard.php?supply_id=<?= (int) $item['supply_id'] ?>&amp;qty=<?= (int) $req_qty ?>">Request</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endforeach; ?>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();

$footer_js = soh_table_sort_script($cspNonceAttr ?? null);
$nonce = ($cspNonceAttr ?? '') !== '' ? ' nonce="' . $cspNonceAttr . '"' : '';
$footer_js .= <<<HTML
<script{$nonce}>
  // Search + "Hide not tracked": filter rows in every section, keep counts and the nav in sync
  (function () {
    const search = document.getElementById('sohSearch');
    const hideUntracked = document.getElementById('sohHideUntracked');
    const resultCount = document.getElementById('sohResultCount');
    const noMatch = document.getElementById('sohNoMatch');
    const sections = Array.from(document.querySelectorAll('.soh-section'));
    if (!search || !hideUntracked || !sections.length) return;

    try { hideUntracked.checked = localStorage.getItem('soh:hideUntracked') === '1'; } catch (_) { }

    function apply() {
      const term = search.value.trim().toLowerCase();
      const hide = hideUntracked.checked;
      let total = 0;
      sections.forEach((section) => {
        let shown = 0;
        section.querySelectorAll('.soh-row').forEach((row) => {
          const visible = (!term || row.dataset.search.includes(term))
            && (!hide || row.dataset.tracked === '1');
          row.hidden = !visible;
          if (visible) shown++;
        });
        section.hidden = shown === 0;
        const count = section.querySelector('[data-section-count]');
        if (count) count.textContent = shown;
        const navLink = document.querySelector('.soh-nav__link[href="#' + section.id + '"]');
        if (navLink) {
          navLink.parentElement.hidden = shown === 0;
          const navCount = navLink.querySelector('[data-nav-count]');
          if (navCount) navCount.textContent = shown;
        }
        total += shown;
      });
      noMatch.hidden = total > 0;
      resultCount.textContent = (term || hide) ? total + ' item' + (total === 1 ? '' : 's') : '';
      window.dispatchEvent(new Event('scroll')); // refresh the active section in the nav
    }

    search.addEventListener('input', apply);
    hideUntracked.addEventListener('change', () => {
      try { localStorage.setItem('soh:hideUntracked', hideUntracked.checked ? '1' : '0'); } catch (_) { }
      apply();
    });
    apply();
  })();
</script>
<script{$nonce}>
  // Left section nav: smooth-scroll on click, highlight the section in view
  (function () {
    const links = Array.from(document.querySelectorAll('.soh-nav__link'));
    const targets = links
      .map((a) => document.getElementById(a.getAttribute('href').slice(1)))
      .filter(Boolean);
    if (!links.length || !targets.length) return;

    function setActive(id) {
      links.forEach((a) => {
        const on = a.getAttribute('href') === '#' + id;
        a.classList.toggle('is-active', on);
        if (on) {
          a.setAttribute('aria-current', 'true');
          // keep the active link visible in the (horizontally scrolling) mobile nav
          a.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        } else {
          a.removeAttribute('aria-current');
        }
      });
    }

    links.forEach((a) => {
      a.addEventListener('click', (e) => {
        const target = document.getElementById(a.getAttribute('href').slice(1));
        if (!target) return;
        e.preventDefault();
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
        history.replaceState(null, '', a.getAttribute('href'));
        setActive(target.id);
      });
    });

    // The active section is the last one whose top has scrolled past ~1/4 of the viewport
    let ticking = false;
    function onScroll() {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(() => {
        ticking = false;
        const line = window.innerHeight * 0.25;
        const visible = targets.filter((t) => !t.hidden);
        if (!visible.length) return;
        let current = visible[0];
        visible.forEach((t) => {
          if (t.getBoundingClientRect().top <= line) current = t;
        });
        setActive(current.id);
      });
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  })();
</script>
HTML;

include __DIR__ . '/templates/layout.php';
