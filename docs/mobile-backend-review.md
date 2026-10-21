# Mobile app and backend integration review

Update: the findings below describe the original review. Implemented fixes, verification, and deployment notes are recorded in [mobile-backend-fixes.md](mobile-backend-fixes.md).

Reviewed: 21 October 2026.

Sources: `F:/kkp_rep_mobile_app` (Flutter) and this Laravel backend. This was a source-level integration review plus the existing backend test suite. No application code or persistent database data was changed. A physical device, emulator, deployed API, and real Firebase delivery were not tested.

## Confirmed problems

### 1. High: mobile sales history is not scoped to the logged-in rep

The app uses `sales` under `/api/v1/` (`lib/resources/api_routes.dart:24`, `lib/provider/sales_provider.dart:120`). This resolves to `SaleController::index`, which starts with all sales and only applies a rep filter if supplied by the caller (`app/Http/Controllers/SaleController.php:100`). The app supplies no rep filter. Reps can therefore receive other reps' sales, and dashboard calculations can include them. The separate `/v1/mobile/sales` implementation scopes reads to the authenticated rep, but the app does not call it. Enforce scoping server-side for rep tokens on every sales endpoint they can access; switching only the client URL does not close access through the general endpoint.

### 2. High: credit collection does not settle invoices or record payments

The app posts an amount to `/v1/mobile/shops/{id}/settle-credit` (`lib/provider/shop_provider.dart:237`). `ShopController::settleCredit` only updates `shops.current_credit_balance` (`app/Http/Controllers/ShopController.php:187`). It does not create a payment, allocate the collection against invoices, update sale paid/due amounts, or record a collecting rep. Credit invoice lists and settlement totals derived from sales/payments can consequently disagree with the shop balance. The handler also lacks positive-amount validation, so a negative request increases the debt. Implement validated, transactional collection recording and invoice allocation with an agreed allocation rule.

### 3. High: recording sales does not consume inventory

Both `SaleController::store` and `v1/MobileSaleController::store` create sales, sale items, and payments without checking or decrementing rep stock or warehouse stock. No sale observer was found to perform this work. The invoice screen submits to the general handler (`lib/screens/invoice_creation_screen.dart:1264`), and does not refresh rep stock after success. Stock can remain unchanged after sales and overselling is not prevented by these handlers. Decide which stock source a rep sale consumes, then check and decrement it atomically with the sale.

### 4. High: account switching can retain the previous account's token and data

Each provider owns a separate `DioClient`. Its request interceptor reads SharedPreferences only while its in-memory token is empty (`lib/dio/dio_client.dart:26`). App-level providers persist across logout (`lib/main.dart:38`), while logout clears only the AuthProvider client's token (`lib/provider/auth_provider.dart:280`). `main.dart:60` additionally creates a separate AuthProvider for logout. Other clients can keep sending the previous token after a second account logs in, producing 401s after successful revocation, or acting as the old account if logout failed. Shop and sales caches are also not reset or keyed by account. Use shared session state and clear all account-owned caches on session changes.

### 5. High: rep tokens can access settlement approval endpoints

`GET /v1/daily-settlements` and `POST|PUT /v1/daily-settlements/{id}/status` have only Sanctum authentication (`routes/api.php:176`). Their controller methods contain no supervisor/admin authorization or ownership checks (`app/Http/Controllers/v1/MobileSaleController.php:692` and `:718`). A rep token can list other reps' settlements and change approval statuses. Add role and scope checks on the server. Shop delete endpoints also lack ownership/assigned-route authorization; clarify the intended rep permissions before changing those.

### 6. Medium: editing a shop calls a missing route

`ShopProvider.updateShop` sends `PUT /api/v1/mobile/shops/{id}` (`lib/provider/shop_provider.dart:117`), called by the registration/edit sheet. The backend registers GET and DELETE on this path, but no PUT/PATCH (`routes/api.php:92`). Saving an edit will receive HTTP 405. Add a validated, authorized mobile update handler or align the client with an appropriate supported endpoint and response format.

### 7. Medium: sales date filters are silently ignored

The app sends `start_date` and `end_date` (`lib/provider/sales_provider.dart:116`). Its current backend endpoint reads `date_from` and `date_to` (`app/Http/Controllers/SaleController.php:129`). A request for today's sales can therefore return all dates, affecting daily totals. Align the parameter contract. The separate mobile sales list accepts a single `date`, so simply changing the sales URL will not preserve range filtering without another change.

### 8. Medium: sale creation omits the rep's branch in the current app flow

The invoice screen does not supply `branchId` to `createSale` (`lib/screens/invoice_creation_screen.dart:1264`). The general sale controller stores `branch_id` directly from input (`app/Http/Controllers/SaleController.php:326`), without falling back to the authenticated rep's assigned branch. Sales can have no branch and disappear from branch-filtered reports. The separate mobile handler does have a branch fallback. Derive the branch server-side for rep accounts and validate any allowed override.

### 9. Medium: payment totals can disagree with payment records

Both sale handlers sum split-payment amounts, then let a supplied `paid_amount` override that sum (`app/Http/Controllers/SaleController.php:299`; `v1/MobileSaleController.php:297`). The app sends both values. The server does not reject inconsistent amounts or overpayment. This can produce an invoice with zero debt despite payment records totaling less than the invoice. Derive paid/due amounts from validated payments and define any overpayment/change policy.

### 10. Medium: resubmitting settlement bank deposits duplicates balances

Settlement submission updates an existing rep/date sheet, but unconditionally creates its supplied bank deposits and increments bank balances again (`app/Http/Controllers/v1/MobileSaleController.php:662`). Retrying an identical request with `bank_deposits` duplicates deposits. This is a backend contract issue; the current end-of-day screen was not observed supplying deposits. Make deposit submission idempotent and enforce approved-sheet edit rules.

### 11. Medium: HTTP logging includes credentials and tokens

The app enables Dio request-body and response-body logging unconditionally (`lib/dio/dio_client.dart:47`). Login sends a password and receives a bearer token; those values can appear in logs. Remove or redact sensitive headers/body fields and restrict diagnostic logging to appropriate development use.

## Connection and device checks still needed

- The app hardcodes `http://172.28.112.1:8000/api/v1/` (`lib/resources/api_routes.dart:3`). Confirm that this is reachable from the actual phone/emulator and that Laravel listens on a reachable interface. No live connection was verified. Use an environment-specific API URL and HTTPS for deployment.
- Android's main manifest does not declare a cleartext HTTP exception or network security configuration, while the base URL uses HTTP. Check the merged build manifest and device behavior; prefer HTTPS. This is a configuration concern, not a reproduced device failure.
- The Android Google Maps API key is a placeholder. Verify whether the app's native Google Maps screen is used and configure that key if required.
- Clarify whether reps may view all credit shops, collect against other reps' invoices, and delete shops outside their own routes. Several handlers currently allow broad access.

## Contracts that align in the inspected source

- Login phone/password fields, `data.user`, `data.token`, bearer authentication, profile, avatar, and FCM-token paths align.
- Shop creation, assigned-route shop lookup, visited shops, item listing, rep-stock parsing, stock-request submission, and active-bank-account calls have matching routes and broadly compatible request/response structures.
- Notification inbox pagination (`data.notifications.data`), unread counts, mark-read, and read-all match the backend implementation.
- Daily settlement GET/POST routes and the end-of-day screen's expected summary fields align.

These checks establish source compatibility only; they do not establish successful device execution or delivery to external services.

## Validation

Ran `php vendor/phpunit/phpunit/phpunit --do-not-cache-result` after checking `phpunit.xml`, the absence of its alternate config-cache file, and `tests/TestCase.php`'s persistent-database refusal guard. The suite used isolated SQLite `:memory:`: **56 tests passed, 272 assertions**. Existing tests do not cover all findings above. Flutter analysis/tests and a device walkthrough were not run during this review.

Suggested order: close rep access gaps and session isolation first, fix collection/inventory/payment accounting, then align shop editing, sales filters, and branch assignment. Verify with two different rep accounts, invoices on two different dates, a partial credit collection, a sale with limited stock, and retried settlement submission.
