# Mobile/backend integration fixes

Implemented on 21 October 2026 in this backend and `F:/kkp_rep_mobile_app`.
The original findings are retained in [mobile-backend-review.md](mobile-backend-review.md).

## Changes

- The app now uses `/api/v1/mobile/sales`. Both sales API variants enforce the authenticated rep's identity, and both accept the app's date range parameters. Rep-created sales derive the branch from the rep profile.
- Authenticated rep tokens are restricted to mobile operations, including through general API aliases. Agent/admin management endpoints and settlement approval require the appropriate role. Inactive accounts/profiles cannot continue using issued tokens.
- Shop reads, edits, deletion, and collection check the rep's assigned route or shop ownership. Mobile PUT/PATCH shop routes now exist; shop fields and coordinates are validated. Shops with invoice history/debt cannot be deleted. Route lists expose the rep's assigned route.
- Credit collection validates a positive amount, locks the shop/invoices, allocates cash against the oldest unpaid invoices belonging to the collecting rep, creates collector-linked payments, and updates both invoice and shop balances in one transaction. Collections against older invoices appear in today's settlement preview. Amounts exceeding collectible debt are rejected.
- Completed rep sales consume issued rep stock in FIFO order, respect an explicitly supplied batch, and roll back the entire sale on insufficient stock, including duplicate item lines. Allocation records preserve which stock rows were consumed. Agent cancellation restores those allocations once and reverses the cancelled invoice's debt/bank effects. Completed invoices cannot be deleted directly or reopened; create a new invoice after cancellation. Payment records cannot be replaced through invoice editing; record collections through the collection endpoint.
- Split payments must agree with `paid_amount`; inconsistent totals, overpayments, invalid credit combinations, inactive items, and discounts exceeding line/subtotal values are rejected. Sale/settlement codes use random suffixes to avoid count-based collisions.
- Resubmitting the same settlement deposit snapshot does not add deposits or bank balances again. Changed snapshots reverse the previous deposit effect before applying the replacement. An empty snapshot clears the deposits; an omitted snapshot preserves them. Approved sheets cannot be edited by resubmission.
- Mobile API clients share session tokens and reject responses from an earlier session. Auth uses one provider instance; shop, sales, stock, and bank providers are recreated for each authenticated session. Detached providers cannot notify disposed screens. Unconditional HTTP request/response logging was removed.
- `API_BASE_URL` is configurable through `--dart-define`. Release requests require HTTPS; Android HTTP access is enabled only in the debug manifest. The existing local address remains the development default.
- The shop map picker now uses `flutter_map` and OpenStreetMap tiles with attribution and a current-location button. The Google Maps dependency, key placeholder, and native initialization were removed. **No Google Maps key is required.** Map tiles still require internet access.

## Validation and local database

- Backend: **66 tests passed, 344 assertions**, using isolated SQLite `:memory:` with the existing persistent-database refusal guard.
- Flutter: **9 tests passed**, including account token switching and rejection of stale responses.
- Android debug build succeeded: `F:/kkp_rep_mobile_app/build/app/outputs/flutter-apk/app-debug.apk`.
- Flutter analysis found no errors. Existing unrelated warning/deprecation notices remain; analysis was run with `--no-fatal-infos --no-fatal-warnings` and the notices were reviewed.
- The local MySQL development database was backed up before applying only the two new migrations. Backup: `storage/app/private/backups/before-mobile-integration-20261021-172606.sql` (67,020 bytes; SHA256 `788733ead75a5070b6b4f4893204cdf11cf112e85031a88f98c3e8922b9d648e`). The backup is excluded from version control.
- Applied locally: `2026_10_21_000001_add_collector_to_sale_payments` and `2026_10_21_000002_create_sale_stock_allocations_table`. No historical invoices, balances, or stock quantities were backfilled.

## Deployment and device checks

For another backend environment, back up its database and apply the two additive migrations before deploying this code. See the mobile project's `docs/backend-connection.md` for API configuration.

Development: `flutter run --dart-define=API_BASE_URL=http://YOUR_PC_LAN_IP:8000/api/v1/` with Laravel listening on a reachable interface. A standard Android emulator can use host address `10.0.2.2`; a physical phone needs a reachable LAN address.

Release: `flutter build apk --dart-define=API_BASE_URL=https://YOUR_SERVER/api/v1/`. No deployed HTTPS URL was provided, so a release connection was not configured or tested. Configure Laravel's externally reachable `APP_URL` and public storage link for avatars/slips as appropriate.

Still requires a device walkthrough: login with two different reps, edit a shop, sell issued stock, collect an older credit invoice, submit settlement, and pick a map location. Real Firebase delivery and iOS compilation were not tested here.

Existing shop balances without matching collectible invoices need reconciliation before collection. The fixes prevent new inconsistencies; they do not invent missing historical payment or inventory records.
