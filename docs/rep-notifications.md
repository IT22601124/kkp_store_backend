# Rep broadcast notifications

In the agent frontend, open **Rep Notifications**, enter a title (up to 100 characters) and message (up to 1,000 characters), then select **Send to all reps**. The audience includes all users with role `DSR_REP` and status `ACTIVE`, across all branches. Every recipient gets a saved mobile inbox entry. Reps without a nonblank FCM token skip the push only. Shared tokens receive one push, while each rep has an independent inbox and read status.

## Setup

Run `php artisan migrate` in the backend and keep `php artisan queue:work --tries=1 --timeout=45` running. The default queue connection is `database`; each registered device gets a separate job. Configure Firebase using the project's existing service-account configuration (`FIREBASE_CREDENTIALS`, or a service account in `storage/app/firebase/`). Reps must have registered their device token through the existing mobile FCM-token endpoint.

Broadcasts have `queued`, `processing`, `completed`, `partial`, or `failed` status. History refreshes every five seconds. `sent_count` counts pushes accepted by Firebase, not confirmations that a rep read or received them. A failed recipient is not automatically retried, which avoids sending duplicates after an uncertain network result. Device tokens in delivery records are cleared once their attempt finishes.

## API

Both endpoints require a Sanctum token for an active `AGENT`. History is scoped to the sending agent. The audience is global and is unaffected by the frontend branch filter.

- `GET /api/notification-broadcasts?page=1`: current audience counts and paginated history (20 rows).
- `POST /api/notification-broadcasts`: JSON `{ "title": "Meeting", "body": "Meet at 9 AM." }`; returns HTTP 202 with the broadcast and its initial status. Limited to six requests per minute per authenticated user. Returns 422 for invalid messages or no active reps. Announcements with no registered devices still save inbox entries and complete with zero push attempts.

## Saved mobile inbox

The `rep_notifications` table links a broadcast to each recipient user with an independent `read_at` timestamp. Entries are saved in the same transaction as the announcement, before any Firebase job is dispatched. A Firebase error does not remove the inbox entry. Recipient membership is recorded at send time; users created or activated later do not receive earlier announcements retroactively. Broadcasts created before the inbox feature have no reliable recipient mapping and are not backfilled.

The mobile header bell and drawer **Notifications** entry open the inbox. The badge shows the server unread count. Tapping a message shows its full text and saves its read status; **Read all** updates the signed-in rep's unread messages. The inbox supports pagination, pull-to-refresh, error/retry states, foreground Firebase updates, and refresh on resume. Banner taps open the inbox, including after a cold start or login.

These endpoints require an active `DSR_REP` Sanctum session and only expose that user's entries:

- `GET /api/v1/mobile/notifications?page=1`: `{ data: { notifications: <20-row paginator>, unread_count: <integer> } }`, newest first.
- `POST /api/v1/mobile/notifications/{id}/read`: idempotently marks an owned entry read and returns its read time and the remaining unread count. Other users' IDs return 404.
- `POST /api/v1/mobile/notifications/read-all`: marks the current rep's unread entries read.

Apply `2026_10_19_000002_create_rep_notifications_table.php` before using the new backend code and rebuild/install the mobile APK. Existing tables and announcement data are preserved by this additive migration.

Queue jobs claim delivery records before sending. Completed attempts are not resent if a job is delivered again. After fixing a delivery failure, compose a new broadcast if another announcement is needed.

## Phone banners

FCM messages include a visible title/body notification, high Android delivery priority, display priority for older Android versions, and an APNs alert with sound. Android uses `FIREBASE_ANDROID_CHANNEL_ID` (default `high_importance_channel`). The mobile app must create that channel with high importance and request notification permission. The channel identifier must match the mobile app. Changing server priority cannot override a channel the user muted.

For messages received while the mobile app is open, the app must handle `FirebaseMessaging.onMessage` and show a local notification on Android, or configure foreground presentation on Apple devices. Background notification payloads are displayed by the operating system when permissions allow. The backend alone cannot guarantee a banner. See [Firebase Flutter receiving messages](https://firebase.google.com/docs/cloud-messaging/flutter/receive-messages).

If history remains **queued**, the queue worker is not processing the job. Run `php artisan queue:work --tries=1 --timeout=45` from the backend directory and keep it running. Restart existing workers after backend code changes.

The rep mobile project is `F:\kkp_rep_mobile_app`. Its notification service creates the matching high-importance Android channel, shows foreground Android local notifications with sound/vibration, and enables native iOS foreground alert presentation. The Android manifest handles the `FLUTTER_NOTIFICATION_CLICK` action sent by the backend. Rebuild and install the mobile app after these changes.

To verify on a phone, open the rebuilt app, allow notifications, and sign in as a rep so its FCM token is registered. Send an announcement from the agent portal with the mobile app visible, then send a different announcement with it in the background. Check both the visible banner and the broadcast's Firebase acceptance status. An accepted FCM message is not proof of a visible banner; muted channels, disabled notifications, Do Not Disturb, and phone connectivity can affect presentation.

On this workstation, drive F ran out of space during APK packaging. Its generated `F:\kkp_rep_mobile_app\build` directory was moved, with approval, to `E:\kkp_store_backend\kkp_store-backend\storage\app\private\rep-mobile-build`. A directory junction at the original build path preserves Flutter's expected paths. Source files remain in the mobile project; the generated output is ignored by backend Git rules.
