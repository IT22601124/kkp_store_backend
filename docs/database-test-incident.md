# Local database test incident — 19 October 2026

The initial two runs of `NotificationBroadcastTest` used the cached Laravel configuration in `bootstrap/cache/config.php`. That configuration selected local MySQL database `hutch2` and the database queue, overriding the intended PHPUnit SQLite configuration. The test's `RefreshDatabase` trait recreated the database tables. The pre-test database contents were not backed up or inventoried, so the amount of lost data is unknown. A read-only check afterward confirmed zero users in `hutch2`.

The subsequent runs used isolated in-memory SQLite. `phpunit.xml` now bypasses the application configuration cache and forces SQLite `:memory:`. `tests/TestCase.php` additionally refuses to initialize tests against a persistent database before the database-refresh traits execute. The isolated suite passed 50 tests with 225 assertions.

Read-only recovery checks found MySQL binary logging disabled. The other local schema named `hutch` contains no tables. No matching SQL backup was found in the checked project directories, drive E, or the accessible Downloads/Documents/Desktop locations. The XAMPP MySQL backup directory contains system schemas only. No restore has been attempted, and the original data has not been recovered. Recovery requires an external backup, another data copy, or specialist recovery outside the scope of these checks.

This incident was caused by running the tests without checking the cached configuration first. It was not an intended part of the notification feature.
