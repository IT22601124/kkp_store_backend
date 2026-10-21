# Integrated web, mobile, and backend workflows

Implemented on 21 October 2026 across `E:/hutch`, this Laravel backend, and the earlier mobile fixes in `F:/kkp_rep_mobile_app`. Original findings: [web-backend-review.md](web-backend-review.md) and [mobile-backend-review.md](mobile-backend-review.md).

## Completed changes

| Workflow | Result |
| --- | --- |
| Authentication | Exact passwords, active-account checks, one web auth context, real token revocation, account state reset, and rejection of responses belonging to previous sessions. Starting a sign-in also invalidates outstanding restore requests. |
| Failed saves | Removed local-only success/fallback mutations. Forms await confirmed saves; API errors are displayed. Empty targets, payrolls, and other operational lists stay empty. |
| Shops and credit | General and mobile shop APIs share validation/access rules. Debt cannot be replaced through a metadata edit. Collections create invoice-linked payments and refresh authoritative balances. |
| Cash deposits | Deposit list and verification routes exist. Verification preserves bank balances; rejection reverses once. Direct deposits support retry keys. Receipt IDs distinguish deposits from sale payments. |
| Inter-bank transfers | Dedicated persisted ledger, account locks, sufficient-balance/currency checks, atomic debit/credit, and idempotency keys retained by the web client after uncertain results. This records agency accounting; it does not instruct an external bank to move funds. |
| Sale receipts | Display actual uploaded evidence or explicitly missing evidence. Attaching a receipt cannot create a payment, change its amount/account/type, or turn a rejected payment into a credited payment. Verified receipts and cancelled invoices cannot be replaced through this path. |
| Payment audit | Verification/rejection updates the actual payment/deposit, records the auditor, and maintains invoice/shop debt and bank balances. Settlement approval has no fallback to an unrelated DSR trip. |
| Payroll | Server-backed creation, approval, disbursement recording, and draft deletion. Server calculates net pay. Approved amounts and disbursed records are protected. Item commission settings persist. The payroll form supports all twelve months and explicit salary inputs. Recording disbursement does not initiate an external salary payment. |
| Daily cash sheet | Replaced the example worksheet with recorded sales/collections, item totals, counted cash, notes, saved snapshots, and approval protection. Agents select the rep/date; reps remain scoped to themselves. Server-derived totals override supplied totals. |
| GRN | Persisted edits/deletion update warehouse inventory transactionally. Metadata edits do not change stock. Reducing/reversing receipts is rejected when available stock cannot cover it. |
| Stock issuance | Both issue APIs use one service. Warehouse quantities are checked/consumed by batch, actual mobile rep balances are created, and request/transfer/header IDs remain distinct. Retrying a keyed request cannot issue twice. Issued transfers cannot be deleted or have their accounting fields edited. |
| Adjustments and returns | Rep damage/loss/returns update the same issued-stock rows used by mobile sales, with before/after audit lines. Returns restore their actual warehouse batches. Warehouse adjustments cannot consume unavailable stock. |
| Targets and reports | Canonical target months, validated representatives/amounts, no invented target amounts on normalization, cancelled sales excluded from calculated achievement, working report period filters, and inventory purchase valuation without fabricated prices. |
| Configuration and maps | Web reads `VITE_API_BASE_URL`, with `/api` as the production same-origin default. Mobile reads `API_BASE_URL`; release requires HTTPS. Mobile uses Flutter Map/OpenStreetMap and needs no Google Maps key. |

## Verification

- Backend: **82 tests, 453 assertions passed** with SQLite `:memory:`. The test bootstrap refuses persistent databases.
- Web: production TypeScript/Vite build passed; **12 regression tests passed**. Lint completed with 136 warnings and no errors.
- Flutter: **9 tests passed** again after the shared backend changes. Earlier Android debug APK build and analysis are documented in [mobile-backend-fixes.md](mobile-backend-fixes.md).
- Real headless Chrome walkthrough against an isolated SQLite HTTP backend passed eight groups of checks: login/dashboard; warehouse issuance followed by a mobile API sale; browser fund transfer; browser deposit; cash sheet showing the mobile sale and surviving a reload; payroll creation/approval/disbursement; all management views rendering; logout followed by rejection of the revoked token. No JavaScript runtime exceptions or failed browser API requests were recorded.
- The browser fixture used artificial accounts and a separate database. No browser-test sales, deposits, payrolls, or transfers were written to `hutch2`.

Repeat backend tests from this directory with `php vendor/phpunit/phpunit/phpunit --do-not-cache-result`. Run `npm.cmd run build`, `npm.cmd run lint`, and `npm.cmd test` from `E:/hutch`.

The browser check is `node tests/System/web-browser-smoke.cjs`. It requires installed web dependencies, PHP SQLite, Node with WebSocket support, Chrome, and free local ports 18080/15173/19222. Override `WEB_APP_PATH` and `CHROME_PATH` for other locations. It creates a unique fixture/profile below ignored `storage/app/private/browser-checks` and stops the temporary servers/browser it starts. Its JSON result is stored with that fixture. Treat fixture files as private test artifacts.

## Local database

Backed up the checked local MySQL database `hutch2` before applying `2026_10_21_000003_complete_web_workflows`:

- Backup: `storage/app/private/backups/before-web-integration-20261021-180309.sql`.
- Size: 67,170 bytes; SHA256: `ec6039a426134e4b242a24155c92833915cc463a817686d97679695d5e5b73b7`.
- Existing counts stayed unchanged: users 2; shops 1; sales 1; sale payments 1; warehouse rows 1; rep stock headers 1; rep stock lines 1; bank accounts 1; settlements 0; payrolls 0.
- The earlier two mobile migrations were already applied. No historic balances, payment evidence, or stock allocations were invented/backfilled.

## Running and deploying

Local backend: `php artisan serve --host=0.0.0.0 --port=8000`.

Local web, from `E:/hutch`: `npm.cmd run dev -- --host 127.0.0.1 --port 5173`. Open `http://127.0.0.1:5173`. Local startup binds to this computer only. Default development API: `http://localhost:8000/api`. Set `VITE_API_BASE_URL` before starting/building when using another address. A browser on another computer needs a backend address reachable from that computer.

Mobile development: `flutter run --dart-define=API_BASE_URL=http://YOUR_PC_LAN_IP:8000/api/v1/`. An Android emulator can use `10.0.2.2` for its host. See the mobile project's `docs/backend-connection.md`.

For another backend environment: back up its database, apply the three additive migrations, configure `APP_URL` and public storage, and deploy the backend/web together. Host the web with `/api` proxied to Laravel or build with the actual HTTPS API URL and configure CORS for that origin. Build the mobile release with `--dart-define=API_BASE_URL=https://YOUR_SERVER/api/v1/`.

## Remaining environment checks

No public HTTPS backend URL was supplied, so live hosting and a mobile release connection remain unconfigured. Physical-device location/map behavior, iOS compilation, and real Firebase/Pusher delivery were not verified. Polling provides stock-request refresh without push delivery. Commission values remain explicit payroll inputs; stored rules do not silently post automatic commission payments.

Historical shop debt without matching invoices and legacy inventory/payment inconsistencies still require reconciliation against actual records. Saved settlement figures are snapshots; refresh/resubmit an unapproved sheet after changing the day's transactions. These fixes prevent new inconsistencies rather than guessing missing history.
