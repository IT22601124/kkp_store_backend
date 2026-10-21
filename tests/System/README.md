# Browser integration smoke check

Run from the backend root: `node tests/System/web-browser-smoke.cjs`.

Requires PHP with SQLite, installed backend/web dependencies, Node 24, Chrome, and free ports 18080, 15173, and 19222. Default web path: `E:/hutch`. Optional environment variables: `WEB_APP_PATH`, `CHROME_PATH`.

The runner creates a separate SQLite database and Chrome profile under a unique ignored `storage/app/private/browser-checks` directory. The seed refuses other database locations. It uses artificial test credentials and never seeds the configured MySQL database. It stops only the child servers/browser it launches and preserves its result JSON beside the fixture for inspection.

Checks include UI authentication, warehouse issuance followed by a mobile API sale, bank transfer/deposit, saved cash-sheet persistence, payroll lifecycle, management-view rendering, and token revocation. Browser exceptions and failed API responses fail the run. These checks do not verify external bank transactions, push notification delivery, native mobile rendering, or a deployed environment.
