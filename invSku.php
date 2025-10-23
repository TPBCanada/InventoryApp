<?php
// invSku.php — SKU → all locations (non-zero only), view-first with fallback to movements declare(strict_types=1); session_start(); require_once __DIR__ . '/dbinv.php'; if (!isset($_SESSION['username'])) { header('Location: login.php'); exit; } $DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1'; mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); try { $conn->set_charset('utf8mb4'); } catch (\Throwable $_) {} $q = trim($_GET['q'] ?? ''); // detect if location PK is loc_id or id function detect_location_pk(mysqli $conn): string { foreach (['loc_id','id'] as $cand) { try { $r = $conn->query("SHOW COLUMNS FROM location LIKE '{$cand}'"); if ($r && $r->num_rows) return $cand; } catch (\Throwable $_) {} } return 'loc_id'; } $loc_pk = detect_location_pk($conn); $rows = []; $totals_by_sku = []; $path_used = 'view'; // -------- helper to run and harvest rows into $rows / $totals -------- $harvest = function(mysqli_result $res) use (&$rows, &$totals_by_sku) { while ($r = $res->fetch_assoc()) { $r['on_hand'] = (int)$r['on_hand']; $r['location_code'] = "{$r['row_code']}-{$r['bay_num']}-{$r['level_code']}-{$r['side']}"; $rows[] = $r; $k = $r['sku_num']; if (!isset($totals_by_sku[$k])) $totals_by_sku[$k] = ['desc'=>$r['sku_desc'], 'sum'=>0]; $totals_by_sku[$k]['sum'] += $r['on_hand']; } }; if ($q !== '') { // 1) Try the NON-ZERO VIEW (exact sku OR partial desc) $sql_view = " SELECT s.sku_num, s.desc AS sku_desc, l.row_code, l.bay_num, l.level_code, l.side, l.{$loc_pk} AS loc_id, v.on_hand, ( SELECT MAX(m.created_at) FROM inventory_movements m WHERE m.sku_id = s.id AND m.loc_id = l.{$loc_pk} ) AS last_moved_at FROM v_inventory_nonzero v JOIN sku s ON s.id = v.sku_id JOIN location l ON l.{$loc_pk} = v.loc_id WHERE s.sku_num LIKE CONCAT('%', ?, '%') OR s.desc LIKE CONCAT('%', ?, '%') ORDER BY s.sku_num, l.row_code, CAST(l.bay_num AS UNSIGNED), l.level_code, l.side "; try { $stmt = $conn->prepare($sql_view); $stmt->bind_param('ss', $q, $q); $stmt->execute(); $res = $stmt->get_result(); if ($res->num_rows > 0) { $harvest($res); } else { // 2) Fallback: aggregate directly from inventory_movements (non-zero only) $path_used = 'movements_fallback'; $stmt->close(); $sql_fb = " SELECT s.sku_num, s.desc AS sku_desc, l.row_code, l.bay_num, l.level_code, l.side, l.{$loc_pk} AS loc_id, SUM(im.quantity_change) AS on_hand, MAX(im.created_at) AS last_moved_at FROM inventory_movements im JOIN sku s ON s.id = im.sku_id JOIN location l ON l.{$loc_pk} = im.loc_id WHERE (s.sku_num LIKE CONCAT('%', ?, '%') OR s.desc LIKE CONCAT('%', ?, '%')) GROUP BY s.id, l.{$loc_pk} HAVING on_hand <> 0 ORDER BY s.sku_num, l.row_code, CAST(l.bay_num AS UNSIGNED), l.level_code, l.side "; $stmt = $conn->prepare($sql_fb); $stmt->bind_param('ss', $q, $q); $stmt->execute(); $res = $stmt->get_result(); $harvest($res); } $stmt->close(); } catch (\Throwable $e) { if ($DEBUG) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); echo "SQL error: ", $e->getMessage(), "\n\nPath: {$path_used}\nPK: {$loc_pk}\n"; echo "SQL(view):\n{$sql_view}\n\n"; exit; } throw $e; } } $title = 'SKU → All Locations'; $page_class = 'page-inv-sku'; ob_start();
?> <h2 class="title">SKU → All Locations</h2> <section class="card card--pad"> <form method="get" action=""> <div class="row" style="gap:8px; align-items:flex-end;"> <div style="flex:1 1 360px; min-width:260px;"> <label for="q" class="label">Search SKU # or description</label> <input type="text" id="q" name="q" value="<?= htmlspecialchars(
     $q
 ) ?>" placeholder="e.g. 20240007 or “hinge”" class="input" autofocus /> </div> <div> <button class="btn btn--primary" type="submit">Search</button> <?php if (
    $q !== ""
): ?> <a class="btn btn-outline" href="?">Clear</a> <?php endif; ?> </div> </div> <?php if (
     $q !== ""
 ): ?> <p class="text-muted" style="margin:8px 0 0;"> Showing non-zero inventory for <strong><?= htmlspecialchars(
     $q
 ) ?></strong> <?php if (
    $DEBUG
): ?> <span class="badge">DEBUG path=<?= htmlspecialchars(
     $path_used
 ) ?> pk=<?= htmlspecialchars(
     $loc_pk
 ) ?></span> <?php endif; ?>. </p> <?php endif; ?> </form> </section> <?php if (
     $q !== ""
 ): ?> <section class="card card--pad"> <?php if (
     !$rows
 ): ?> <p class="text-muted">No matching locations with on-hand &gt; 0.</p> <?php else: ?> <div class="table-wrap"> <table class="table"> <thead> <tr> <th>SKU</th> <th>Description</th> <th>Location Code</th> <th style="text-align:right;">On-Hand</th> <th>Last Movement</th> </tr> </thead> <tbody> <?php foreach (
     $rows
     as $r
 ):
     $href =
         "place.php?" .
         http_build_query([
             "row_code" => $r["row_code"],
             "bay_num" => $r["bay_num"],
             "level_code" => $r["level_code"],
             "side" => $r["side"],
             "return" => "invSku.php?" . http_build_query(["q" => $q]),
         ]); ?> <tr> <td><?= htmlspecialchars(
     $r["sku_num"]
 ) ?></td> <td><?= htmlspecialchars(
    $r["sku_desc"]
) ?></td> <td><a class="link" href="<?= htmlspecialchars(
    $href
) ?>"><?= htmlspecialchars(
    $r["location_code"]
) ?></a></td> <td style="text-align:right;"><?= (int) $r[
    "on_hand"
] ?></td> <td><?= htmlspecialchars(
    $r["last_moved_at"] ?? ""
) ?></td> </tr> <?php
 endforeach; ?> </tbody> </table> </div> <h3 style="margin:20px 0 8px;">Totals</h3> <div class="table-wrap"> <table class="table table--compact"> <thead> <tr> <th>SKU</th> <th>Description</th> <th style="text-align:right;">Total On-Hand</th> </tr> </thead> <tbody> <?php foreach (
     $totals_by_sku
     as $sku => $info
 ): ?> <tr> <td><?= htmlspecialchars($sku) ?></td> <td><?= htmlspecialchars(
    $info["desc"]
) ?></td> <td style="text-align:right;"><?= (int) $info[
    "sum"
] ?></td> </tr> <?php endforeach; ?> </tbody> </table> </div> <?php endif; ?> </section> <?php endif; ?> <?php
 $content = ob_get_clean();
 include __DIR__ . "/templates/layout.php";

