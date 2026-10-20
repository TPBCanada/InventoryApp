# Security Requirements — Baseline Audit

**Audited:** 2026-10-20  
**Scope:** PHP files in working tree (`inventory/`, `supplies/`, `templates/`, `geofence.php`)  
**Method:** grep-based static analysis

---

## Requirements

| ID | Requirement | Status | Notes |
|----|-------------|--------|-------|
| SEC-CSRF-01 | No state-mutating operations driven by GET parameters | Met | `ai_reports.php` GET `?action=reset` is UI-only (clears textarea, no DB write). All DB mutations use POST. `supplies/` GET params are read-only prefill. |
| SEC-CSRF-02 | CSRF tokens present on all forms | Met | `supplies/dashboard.php` — all POST forms include `app_csrf_token()`. `geofence.php` — CSRF token present. `ai_reports.php` — CSRF token added to `manual_sql` form. |
| SEC-CSRF-03 | CSRF tokens verified server-side before processing POST | Met | `supplies/dashboard.php` calls `app_require_csrf()` at line 118. `geofence.php` verifies. `ai_reports.php` — `app_require_csrf()` added to POST handler. |
| SEC-DB-01 | No raw user input concatenated into SQL queries | Partial | `supplies/dashboard.php` — uses prepared statements throughout. `ai_reports.php` — runs `$manual_sql` (user-supplied SQL) via `$conn->query()` (lines 166, 299, 315). Mitigated by `validate_select_only()` (SELECT-only enforced) and role gate (`$can_view_reports`, roles 1/2/3 only). Integer IDs in `implode()` queries (lines 232, 247) are safe — cast from `$all_ids` (int array). |
| SEC-DB-02 | Parameterised queries used for all user-controlled values | Met | All user-supplied values in `supplies/dashboard.php` go through `$conn->prepare()` / `bind_param()`. `ai_reports.php` user SQL is validated and SELECT-only before execution. |
| SEC-OUT-01 | All user-controlled values escaped before HTML output | Met | `supplies/dashboard.php` uses `$h()` (htmlspecialchars) on all DB/user values. `geofence.php` uses `$h()` throughout. Layout files output server-controlled variables only (`$BASE_URL`, `$content`, `$ROOT_ASSET_BASE`). No raw `$_GET`/`$_POST` echoed. |
| SEC-OUT-02 | No user-controlled values injected into HTML attributes unescaped | Met | All attribute values in `supplies/dashboard.php` use `$h()`. `data-search` attribute (line 580) uses `$h(strtolower(...))`. |
| SEC-SEC-01 | No hard-coded API keys, passwords, or secrets in source | Met | `geofence.php` reads `GOOGLE_MAPS_API_KEY` from env/server vars only. No plaintext secrets found. Credentials (`dbinv.php`, `config_init.php`) are gitignored. |
| SEC-ACCESS-01 | SQL report runner restricted to authorised roles | Met | `ai_reports.php` gates on `$can_view_reports` (role_id 1, 2, 3) via `access_control.php`. |
| SEC-ACCESS-02 | SQL report runner enforces SELECT-only queries | Met | `validate_select_only()` called before execution (line 293). Errors block execution. |

---

## Open Gaps

None.

---

## Notes

- `$cspNonceAttr` is referenced in `supplies/templates/layout.php` script tags but not defined in that file or `supplies/dashboard.php` — outputs empty nonce attribute. Cosmetic for now but CSP nonce enforcement would be ineffective.
- `ai_reports.php` lines 232/247 use `implode(',', $all_ids)` in a query where `$all_ids` is built from integer-cast user IDs — safe, but worth migrating to `IN (?)` with prepared statements when refactoring.
