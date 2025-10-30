<?php

// ai_reports.php — AI chat to build custom SQL reports (read‑only)
// ----------------------------------------------------------------------------
// Features
// - Natural language → SQL via OpenAI Responses API (tool calling)
// - Enforces SELECT/WITH only; wraps with LIMIT/OFFSET caps
// - Schema-aware: shares DB table/column metadata with the model
// - Safety: no DML/DDL, single statement, capped row counts, CSV preview link
// - Simple chat UI (cards/buttons consistent with InventoryApp)
// Requirements
// - PHP 7.4+ (polyfills provided), ext-json, ext-curl, mysqli
// - Set OPENAI_API_KEY in environment (not hardcoded)
// ----------------------------------------------------------------------------

declare(strict_types=1);
session_start();

require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/utils/inventory_ops.php';
require_once __DIR__ . '/utils/helpers.php';

$preview = ['columns'=>[], 'rows'=>[], 'row_count'=>0, 'sql'=>''];
// $_SESSION['ai_preview'] = $preview;


$OPENAI_KEY = (defined('OPENAI_API_KEY') && OPENAI_API_KEY !== '')
  ? OPENAI_API_KEY
  : (getenv('OPENAI_API_KEY') ?: '');

if (!isset($_SESSION['username'])) { header('Location: login.php'); exit; }
$user_id  = (int)($_SESSION['user_id'] ?? 0);
$username = $_SESSION['username'] ?? 'User';
$role_id  = (int)($_SESSION['role_id'] ?? 0);

require_once __DIR__ . '/templates/access_control.php';

// Restrict to admins/managers/analysts
$can_use_ai = in_array($role_id, [1,2,3], true);
if (!$can_use_ai) { http_response_code(403); echo 'Access denied.'; exit; }

// ------------- config -------------
$title = 'AI Report Builder';
$MODEL = getenv('OPENAI_MODEL') ?: 'gpt-4.1-mini';
$ROW_CAP_PAGE = 500;
$ROW_CAP_CSV  = 50000;
$SCHEMA_CACHE_SECS = 600;

// ------------- helpers -------------
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
if (!function_exists('str_starts_with')) {
  function str_starts_with(string $hay, string $nee): bool { return $nee === '' || strncmp($hay, $nee, strlen($nee)) === 0; }
}

function json_read(string $s, bool $assoc=true) { $d = json_decode($s, $assoc); return $d === null ? [] : $d; }
function json_write($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }

function ai_http(string $url, array $headers, array $payload): array {
  $ch = curl_init($url);
  $body = json_write($payload);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
  ]);
  $res = curl_exec($ch);
  $err = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$code, (string)$res, (string)$err, $body];
}

// --- SQL guards ---
function validate_select_only(string $sql, array &$err): bool {
  $trim = trim($sql);
  if ($trim === '') { $err[] = 'Empty SQL.'; return false; }
  if (preg_match('/;\s*\S/s', $trim)) { $err[]='Multiple statements not allowed.'; return false; }
  if (!preg_match('/^(SELECT|WITH)\b/i', $trim)) { $err[]='Only SELECT/CTE queries allowed.'; return false; }
  $blocked = ['INSERT','UPDATE','DELETE','REPLACE','ALTER','DROP','TRUNCATE','CREATE','ATTACH','MERGE','GRANT','REVOKE','SHOW','DESCRIBE','EXPLAIN','HANDLER','LOAD','OUTFILE','DUMPFILE','LOCK','UNLOCK','KILL','CALL','SET','USE','PREPARE','EXECUTE','DEALLOCATE'];
  $up = strtoupper($trim);
  foreach ($blocked as $kw) if (preg_match('/\b'.$kw.'\b/i', $up)) { $err[] = 'Forbidden keyword: '.$kw; return false; }
  return true;
}
function wrap_with_limit(string $sql, int $limit, int $offset=0): string {
  $sqlNoSemi = rtrim($sql, "; \t\n\r\0\x0B");
  $lim = max(1,$limit); $off=max(0,$offset);
  $wrap = 'SELECT * FROM (' . $sqlNoSemi . ') AS sub_ai_reports LIMIT ' . $lim;
  if ($off>0) $wrap .= ' OFFSET '.$off;
  return $wrap;
}

// --- schema capture ---
function cache_path(string $key): string { return sys_get_temp_dir() . '/invapp_' . md5($key) . '.json'; }
function get_db_schema(mysqli $conn, int $ttl): array {
  $ckey = 'schema_v1'; $f = cache_path($ckey);
  if (@is_file($f) && (time()-filemtime($f) < $ttl)) { return json_read((string)file_get_contents($f)); }
  $schema = [];
  $tables = [];
  $res = $conn->query("SHOW TABLES");
  while ($row = $res->fetch_array()) { $tables[] = $row[0]; }
  foreach ($tables as $t) {
    $cols = [];
    $r = $conn->query("SHOW COLUMNS FROM `".$conn->real_escape_string($t)."`");
    while ($c = $r->fetch_assoc()) $cols[] = ['name'=>$c['Field'], 'type'=>$c['Type'], 'null'=>$c['Null'], 'key'=>$c['Key']];
    $schema[$t] = $cols;
  }
  file_put_contents($f, json_write($schema));
  return $schema;
}

// --- OpenAI tool schemas ---
$tools = [[
  'type' => 'function',
  'name' => 'run_sql',
  'description' => 'Execute a read-only SQL query (SELECT/WITH only).',
  'parameters' => [
    'type' => 'object',
    'properties' => [
      'sql'       => ['type'=>'string','description'=>'Single SELECT or WITH statement.'],
      'purpose'   => ['type'=>'string','description'=>'Why this query answers the question.'],
      'row_limit' => ['type'=>'integer','description'=>'Preview row limit (<=500).'],
    ],
    'required' => ['sql'],
  ],
]];




// --- system prompt ---
function system_rules(array $schema): string {
  $schema_text = '';
  foreach ($schema as $t=>$cols) {
    $colparts = array_map(function($c){ return $c['name'].':'.$c['type']; }, $cols);
    $schema_text .= "\n- $t(" . implode(', ', $colparts) . ")";
  }
  return <<<SYS
Role: You are a helpful data analyst that writes **safe, efficient, read-only** MySQL queries.
Rules:
1) Only one statement; it must start with SELECT or WITH.
2) NEVER modify data (no INSERT/UPDATE/DELETE/DDL).
3) Prefer explicit column lists; avoid SELECT * unless needed.
4) Use valid tables/columns from the schema below.
5) Keep results under 500 rows by default (the tool will cap anyway).
6) For date filters, use `DATE(col)` if time is present.
7) For balances from movements, compute `IN - OUT + ADJUSTMENT` patterns as needed.

Warehouse schema (read-only):{$schema_text}
SYS;
}


// --- state (very light chat history kept in session) ---
$_SESSION['ai_chat'] = $_SESSION['ai_chat'] ?? [];
$history = &$_SESSION['ai_chat']; // each: ['role'=>'user|assistant','content'=>string]

// ------------- routing -------------
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$error  = '';
$assistant_output = '';
$preview = ['columns'=>[], 'rows'=>[], 'row_count'=>0, 'sql'=>''];

if ($action === 'reset') { $history = []; header('Location: ai_reports.php'); exit; }

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='ask') {
  if (!$OPENAI_KEY) { $error = 'OPENAI_API_KEY is not set on the server.'; }
  else {
    $user_text = trim((string)($_POST['message'] ?? ''));
    if ($user_text==='') { $error = 'Please type a question.'; }
    else {
      $history[] = ['role'=>'user','content'=>$user_text];

      // Build messages
      $schema = get_db_schema($conn, $SCHEMA_CACHE_SECS);
      $messages = [
        ['role'=>'system','content'=>system_rules($schema)],
      ];
      $tail = array_slice($history, -6);
      foreach ($tail as $m) $messages[] = $m;

      // Call Responses API (force tool call)
      $req = [
        'model'       => $MODEL,
        'input'       => $messages,
        'tools'       => $tools,
        'tool_choice' => ['type'=>'function','name'=>'run_sql'],
      ];
      list($code, $raw, $err, $payload) = ai_http(
        'https://api.openai.com/v1/responses',
        ['Authorization: Bearer '.$OPENAI_KEY],
        $req
      );

      // --- parse & execute ---
      $assistant_output = '';
      $tool_calls = [];
      $debug_note = '';

      if ($code >= 400) {
        $error = 'OpenAI error ' . $code . ': ' . substr((string)$raw, 0, 800);
      } else {
  $resp = json_read($raw);
  if (!is_array($resp)) $resp = [];
  $out  = isset($resp['output']) && is_array($resp['output']) ? $resp['output'] : [];

  foreach ($out as $item) {
    $itype = isset($item['type']) ? $item['type'] : '';

    if ($itype === 'message') {
      $content = isset($item['content']) && is_array($item['content']) ? $item['content'] : [];
      foreach ($content as $c) {
        if (is_array($c)) {
          if (isset($c['text']) && is_string($c['text'])) $assistant_output .= $c['text'];
          elseif (isset($c['type']) && $c['type']==='output_text' && isset($c['text'])) $assistant_output .= (string)$c['text'];
        }
      }
      if (isset($item['tool_calls']) && is_array($item['tool_calls'])) {
        foreach ($item['tool_calls'] as $call) if (is_array($call)) $tool_calls[] = $call;
      }
      if (isset($item['function_calls']) && is_array($item['function_calls'])) {
        foreach ($item['function_calls'] as $call) if (is_array($call)) $tool_calls[] = $call;
      }
    }

    if ($itype === 'tool_call' || $itype === 'function_call') {
      if (is_array($item)) $tool_calls[] = $item;
    }
  }

  if (!$tool_calls && $assistant_output === '') {
    $debug_note = 'No tool call found in response. Raw snippet: ' . substr((string)$raw, 0, 600);
  }

  foreach ($tool_calls as $tc) {
    // Name may be missing on function_call → default to run_sql
    $fname = '';
    if (isset($tc['name']))                     $fname = (string)$tc['name'];
    elseif (isset($tc['function']['name']))     $fname = (string)$tc['function']['name'];
    elseif (isset($tc['tool_name']))            $fname = (string)$tc['tool_name'];
    if ($fname === '' && (($tc['type'] ?? '') === 'function_call')) $fname = 'run_sql';

    // Arguments may be array or JSON string
    $targs = '{}';
    if (isset($tc['arguments']))                $targs = $tc['arguments'];
    elseif (isset($tc['function']['arguments']))$targs = $tc['function']['arguments'];
    $args = is_array($targs) ? $targs : (json_decode((string)$targs, true) ?: []);

    if ($fname !== 'run_sql') continue;

    $sql  = isset($args['sql']) ? (string)$args['sql'] : '';
    $errs = [];

    if (validate_select_only($sql, $errs)) {
      $sql_run = wrap_with_limit($sql, $ROW_CAP_PAGE, 0);
      $res = $conn->query($sql_run);
      if ($res instanceof mysqli_result) {
        $fields = $res->fetch_fields();
        $cols = [];
        foreach ($fields as $f) $cols[] = $f->name;

        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;

        $preview = [
          'columns'   => $cols,
          'rows'      => $rows,
          'row_count' => count($rows),
          'sql'       => $sql,
        ];
        $res->free();
      } else {
        $errs[] = 'SQL error: ' . $conn->error;
      }
    }

    if (!empty($errs)) {
      $assistant_output .= "\n\n(SQL validation failed: " . implode('; ', $errs) . ')';
    }
  }

  if ($assistant_output !== '') {
    $history[] = ['role' => 'assistant', 'content' => $assistant_output];
  }
  $_SESSION['ai_preview'] = $preview;
}

      // Optionally surface $debug_note in the UI (you already render it)
      // if (!empty($debug_note)) {
      //   $history[] = ['role'=>'assistant','content'=>'[debug] '.$debug_note];
      // }
    }
  }
}



// ------------- view -------------
ob_start();
?>

<h2 class="title">AI Report Builder</h2>

<div class="card card--pad">
  <form class="form" method="post" action="">
    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
      <input type="hidden" name="action" value="ask" />
      <input class="input" type="text" name="message" placeholder="e.g. show on-hand totals by location for SKU 202400009 last 7 days" style="flex:1" />
      <button class="btn btn--primary" type="submit">Send</button>
      <a class="btn btn--ghost" href="?reset=1" onclick="event.preventDefault(); location.href='ai_reports.php?action=reset'">Reset</a>
    </div>
    <?php if ($error): ?><p class="error" style="margin-top:8px; color:#b00020;"><?= h($error) ?></p><?php endif; ?>
  </form>
</div>

<div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:8px;">
  <a class="btn" href="utils/export_csv.php?mode=preview">Export CSV (<?= (int)$preview['row_count'] ?> rows)</a>
  <a class="btn btn--ghost" href="utils/import.php">Import (CSV / Excel)</a>
</div>

<div class="card card--pad" style="max-height:50vh; overflow:auto;">
  <?php if (!$history): ?>
    <div class="empty">Ask a question about your data. I’ll propose a query and run a safe preview.</div>
  <?php else: ?>
    <?php foreach ($history as $m): ?>
      <div style="margin:10px 0;">
        <div style="font-weight:600; color:var(--text-muted);"><?= $m['role']==='user'?'You':'Assistant' ?></div>
        <div><?= nl2br(h($m['content'])) ?></div>
      </div>
      <div class="divider" style="height:1px;background:var(--border);"></div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php if ($preview['columns']): ?>
  <div class="card card--pad">
    <div class="meta" style="margin-bottom:8px;">
      <strong>Preview (<?= (int)$preview['row_count'] ?> rows)</strong>
      <pre style="white-space:pre-wrap; margin:6px 0 0 0; font-size:.85rem; color:var(--text-muted);">SQL: <?= h($preview['sql']) ?></pre>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <?php foreach ($preview['columns'] as $c): ?><th><?= h($c) ?></th><?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($preview['rows'] as $r): ?>
            <tr>
              <?php foreach ($preview['columns'] as $c): ?><td><?= h((string)($r[$c] ?? '')) ?></td><?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php if (!empty($debug_note)): ?>
  <div class="card card--pad" style="background:#fff8e1;border:1px solid #ffe29a;">
    <strong>AI Debug</strong>
    <div style="white-space:pre-wrap"><?= h($debug_note) ?></div>
  </div>
<?php endif; ?>


<?php
$content = ob_get_clean();
include __DIR__ . '/templates/layout.php';

?>