# Web application and backend review

**Implementation update:** the findings below describe the original audit. Fixes, test results, database migration, and deployment details are recorded in [web-backend-fixes.md](web-backend-fixes.md).

Reviewed on 21 October 2026: `E:/hutch` (React/Vite) against the current Laravel backend, including the mobile integration fixes. This review changed no application source or database data. The web production build regenerated ignored build/cache artifacts.

**The web application builds, but several financial and inventory workflows are not reliably connected to the backend.** A successful UI message currently does not always mean the operation was saved.

## 1. High: credit collection can bypass invoice/payment accounting

`E:/hutch/src/services/shopService.ts:215` posts a collection and, on any failure, falls back to `PUT /shops/{id}` with a replacement credit balance. It updates the local cache before the server confirms either operation. An agent can therefore receive a validation failure from the new invoice-linked collection endpoint and still change only the shop balance through the general update handler, leaving invoices unpaid. If both calls fail, the service still returns a locally settled shop.

Reproduced in memory: a mocked 422 collection response triggers a PUT replacing the debt with the client-calculated value. Remove the balance-edit fallback, await a confirmed collection, and refresh authoritative invoice/shop balances. Restrict direct debt editing on the general backend shop update path as well.

## 2. High: three banking operations call unsupported routes

Laravel route matching confirmed the following, without executing the handlers:

| Web request | Current backend route result | User impact |
| --- | --- | --- |
| `GET /api/v1/bank-deposits` | 405: only POST exists | Deposit ledger appears empty because the service catches the error. |
| `POST /api/v1/bank-deposits/{id}/status` | 404 | Cash & Bank deposit verification fails. |
| `POST /api/v1/bank-transfers` | 405: only GET exists on the collection | Inter-bank fund transfers fail. |

Evidence: `E:/hutch/src/services/bankAccountService.ts:177`, `:282`, and `:295`; `routes/api.php`; `app/Http/Controllers/BankAccountController.php` and `BankTransferController.php`.

The existing bank-transfer resource represents customer sale payments/slips, not transfers between the agency's own accounts. Inter-bank movement needs an explicit transactional contract and ledger. Wire sale-slip verification to its existing `/bank-transfers/{id}/verify` action where applicable; actual cash deposit verification is a different resource and needs its own implementation.

## 3. High: failed settlement approval may update the wrong resource or falsely succeed

`E:/hutch/src/services/dsrService.ts:149` posts to the supported settlement approval route. On any error it tries `PUT /dsr-trips/{same numeric id}`. Daily settlements and DSR trips have independent IDs: the fallback can change an unrelated trip. If both writes fail, it returns `{success: true, localOnly: true}`.

`E:/hutch/src/hooks/useDsrTrips.ts:24` changes the visible status before awaiting persistence and displays approval/rejection success even when the backend did not save it. Both the wrong-resource write and local-only success were reproduced with mocked responses. Keep resource identity explicit, remove the fallback, and update visible status only after confirmation or roll it back on failure.

## 4. High: deposit evidence is fabricated or mapped incorrectly

`E:/hutch/src/services/dsrService.ts:63` expects `bank_name`, `slip_ref_number`, `slip_image_url`, and `verification_status`. The backend provides nested `bank_account.bank_name`, `slip_reference`, `deposit_slip_url`/`slip_url`, and `status`. Real deposits can consequently display a generic bank, generated reference, a public Unsplash stock photo, and PENDING even when the stored status is CONFIRMED.

The same mapper invents a slip ID and stock-photo image when online collections exist without deposit records. `E:/hutch/src/hooks/useDsrTrips.ts:65` marks audit slips verified only in React state; it calls no API.

Reproduced with a mocked response in the actual backend shape. Map the real fields, show missing evidence explicitly, and persist verification against a real deposit/payment ID. Never present a placeholder photo as a transaction receipt.

## 5. High: payroll and GRN edits/deletions are local-only

`E:/hutch/src/hooks/usePayroll.ts` starts from hardcoded sample payrolls. Creating, approving, disbursing, and deleting payrolls only update React state; they never call the backend payroll resource. A displayed disbursement does not record a payout.

`E:/hutch/src/hooks/useStock.ts:57` and `:64` similarly update/delete GRNs only in React state. A reload restores the server records, and the displayed change does not update warehouse inventory. Connect these operations to supported, transactionally correct backend actions and expose server failures to the user.

## 6. High: failed writes can be reported as saved

- `E:/hutch/src/services/shopService.ts` fabricates create/update results on API errors and returns `true` for a failed deletion. Reproduced a server 500 returning successful deletion.
- `E:/hutch/src/services/stockService.ts:604` fabricates a GRN with a timestamp ID on API failure. The hook then reports that warehouse stock was updated. Reproduced with a mocked 422 response.
- `stockService.ts:533` similarly fabricates stock adjustments. Other directory/target services also contain local success fallbacks.

There is no durable retry/synchronization mechanism that makes these local changes real. Failed writes must remain failed, or be explicitly represented as unsynchronized drafts with a deliberate synchronization design.

## 7. High: web logout does not revoke the backend token

The active UI path uses `E:/hutch/src/hooks/useAppAuth.ts:50`, which clears local storage without calling the backend. The separate `authService.logout` attempts `POST /api/logout`, which route matching confirmed is missing (404). An issued token therefore remains valid after the portal signs out.

The app also has two separate authentication state systems: `context/AuthContext.tsx` and `hooks/useAppAuth.ts`. The login screen calls the service directly and updates the latter, while the context validates only on mount. `handleLogin` unconditionally labels the session AGENT. Unify authentication state, add/use a supported revocation endpoint, and distinguish inactive-account 403 responses from ordinary permission errors.

## 8. High: web rep-stock adjustments update a different inventory store

`app/Http/Controllers/StockAdjustmentController.php:46` handles DSR_REP damage/reconciliation adjustments by updating the `stocks` table. Issued rep balances and mobile sale consumption use `rep_stocks` plus `accepted_request_items` (`RepStockController` and `app/Services/RepInventory.php`). The adjustment can be recorded without reducing the quantity the mobile app can still sell.

Use one authoritative representation for issued rep stock and preserve batch/allocation history when adjusting it. This affects the web adjustment workflow even though sale consumption is now transactional.

## 9. High: web authentication accepts alternate default passwords

`app/Http/Controllers/AuthController.php:42` treats `password` and `password123` as interchangeable for accounts using either hash. `E:/hutch/src/services/authService.ts:36` trims the supplied password and substitutes a default when missing, and `components/LoginView.tsx` prepopulates credentials. These are development shortcuts in the actual login path. Enforce an exact password comparison and do not supply default credentials. The login handler also issues tokens before checking account active status; the new middleware rejects inactive accounts only on subsequent authenticated requests.

## 10. Medium: targets can show sample financial data from an empty database

`E:/hutch/src/services/targetService.ts:11` returns three hardcoded targets when the API successfully returns an empty array. Reproduced an empty API response becoming three records, including a 50,000 target. `data/mockData.ts:45` and `:102` contain nonempty sample targets/payrolls. Empty and failed states should remain explicit; sample figures must not silently enter operational reports.

## 11. Medium: backend address configuration is ignored

`E:/hutch/.env.example` documents `VITE_API_BASE_URL`, but `src/config/apiConfig.ts:1` hardcodes `http://localhost:8000/api`. A deployed browser would call its own computer, and an HTTPS frontend would attempt HTTP requests. Read the configured environment value and use an appropriate HTTPS or same-origin production URL. Splash/error messages also embed the hardcoded address.

## 12. Medium: account changes can retain old data and stale responses

`E:/hutch/src/App.tsx:184` clears only the resource-loaded flags on logout, leaving domain arrays, selected branch/view, and pending requests alive. `services/api.ts` has no session-generation check on responses, and shop cache keys are global rather than account-specific. An old request can finish after a new login and repopulate state with the old account's data. Recreate account-owned state, scope/remove caches, and cancel or reject responses from previous sessions.

## What matched

Core agent login/check-token response structure, bearer headers, branch/item/category/route resources, the primary stock-issue route, and the primary daily-settlement approval route have matching contracts in the inspected source. The notification broadcast UI uses the supported notification-broadcast endpoints. This establishes source-level alignment, not a successful browser walkthrough of every feature.

## Verification

- Production build: `npm run build` passed, including TypeScript checking and Vite bundling.
- Lint: `npm.cmd run lint` completed successfully with warnings (unused values, React hook/state patterns, and similar notices).
- Eight mocked-response probes executed the actual TypeScript service modules compiled in memory. They reproduced collection balance fallback, failed-delete success, wrong-resource approval fallback, local-only approval success, incorrect deposit evidence mapping, fabricated GRN creation, unsupported deposit-ledger GET, and fabricated targets after an empty response. All HTTP/storage were mocked; no real records were changed.
- Laravel's actual route collection confirmed the four unsupported banking/logout requests and the supported settlement/notification paths. Route matching did not execute financial handlers.
- No automated web test script is defined in `package.json`. A live authenticated browser walkthrough, real transfers, and push delivery were not performed.

Prioritize the collection fallback, approval/evidence handling, and false-success writes before relying on the web app for financial operations. Then connect payroll/GRN/banking actions, unify authentication/session state, and align inventory adjustments.
