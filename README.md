<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
# volymolystudio-api

## Google OAuth login

Laravel owns Google OAuth, identity linking, users and sessions. Next.js only
proxies API requests. Google callbacks return to the frontend origin, so the
session cookie used for OAuth state is also the cookie used by /api/auth/me.

Routes (all use the existing web/session middleware):
- GET /api/auth/google/redirect
- GET /api/auth/google/callback
- POST /api/auth/google/link (CSRF and password confirmation; five attempts)

Google identities are matched by unique google_id (Google sub). Verified new
Google users are created with a null password. A matching email on an existing
password account creates a pending link in the session for ten minutes; the
user must explicitly confirm that account's password. Existing passwords are
never replaced. Linked users continue to be identified by google_id if their
Google email changes; their local account email is not silently overwritten.
No application JWT, personal access token, or stored Google access/refresh token
is created. Socialite's transitive JWT library is used by the provider package,
not as this application's authentication mechanism.

### Local configuration

backend-api/.env (keep the existing APP_KEY and database settings):

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:3000
GOOGLE_CLIENT_ID=your-local-web-client-id
GOOGLE_CLIENT_SECRET=your-local-client-secret
GOOGLE_REDIRECT_URI=http://localhost:3000/api/auth/google/callback
SESSION_DRIVER=database
SESSION_COOKIE=laravel_session
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=false
SESSION_SAME_SITE=lax
SANCTUM_STATEFUL_DOMAINS=localhost:3000
```

backend-fornt/.env.local:

```dotenv
API_UPSTREAM_URL=http://localhost:8000
LARAVEL_SESSION_COOKIE=laravel_session
```

Use localhost consistently in the browser; do not mix it with 127.0.0.1.
FRONTEND_URL may contain a CORS allowlist, but its first origin is the fixed
OAuth success/error destination. It must match the GOOGLE_REDIRECT_URI origin.
Google credentials stay in Laravel; never use NEXT_PUBLIC_* for secrets.
NEXT_PUBLIC_API_URL is not used by the frontend proxy.

From backend-api:

```sh
composer install
php artisan config:clear
php artisan migrate
php artisan test
php artisan serve --host=localhost --port=8000
```

Socialite is already declared in composer.json and locked in composer.lock.
For a fresh manual installation only: composer require laravel/socialite.
The new migration adds a nullable unique google_id and makes password nullable.
Existing password hashes remain intact. Rollback removes google_id but deliberately
keeps password nullable so Google-only accounts are not deleted or assigned
invented passwords. PHPUnit uses isolated in-memory SQLite, not the application DB.

From backend-fornt, in another terminal:

```sh
npm ci
npm test
npm run dev
```

No new npm dependency is required. Use npm run build for a production build check.

### Google Cloud Console

Create an OAuth client of type Web application. Configure the consent screen
with your app's support details and the openid, email and profile scopes.
While the OAuth app is in Testing, add the Google accounts you will use as test
users. Prefer separate OAuth clients for local development and production.

Authorized redirect URIs, entered exactly without a trailing slash:
- Local: http://localhost:3000/api/auth/google/callback
- Production: https://volymolystudio-frontend.vercel.app/api/auth/google/callback

Authorized JavaScript origins are not required for this server-side redirect
flow (there is no Google browser SDK). If you populate that Console section:
- Local: http://localhost:3000
- Production: https://volymolystudio-frontend.vercel.app

The upstream API URL https://volymoly.com is not the public callback URL in this
proxy architecture. Do not redirect Google directly to that host.

### Manual verification

1. Start both applications and open http://localhost:3000 in a fresh browser session.
2. Click Continue with Google. The frontend proxy must return a 302 to Google
   while setting a frontend session cookie.
3. Select a configured test account. Google returns code/state to the frontend
   callback; Laravel exchanges the code and validates state.
4. A new/previously linked identity reaches /dashboard. Verify /api/auth/me is
   200, then refresh the dashboard and verify the session persists.
5. For an existing password account with the same email, expect the Connect
   Google screen. A wrong password must fail; the correct password links the
   account without changing its old password.
6. Verify logout through the existing API, then /api/auth/me must return 401.
   Existing email/password login must still work.
7. Cancel consent and retry; verify a readable error. Tampering with callback
   state must not authenticate. Reusing a callback URL must be rejected.
8. A Google-only user can set a password through the existing forgot/reset
   password flow when mail delivery is configured.

Automated tests use the real Socialite state checks with mocked Google HTTP
responses. A real consent/code-exchange test requires actual Google credentials.

### Production rollout (manual; not deployed by this implementation)

Backend environment:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://volymoly.com
FRONTEND_URL=https://volymolystudio-frontend.vercel.app
GOOGLE_CLIENT_ID=your-production-web-client-id
GOOGLE_CLIENT_SECRET=your-production-client-secret
GOOGLE_REDIRECT_URI=https://volymolystudio-frontend.vercel.app/api/auth/google/callback
SESSION_DRIVER=database
SESSION_COOKIE=laravel_session
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SANCTUM_STATEFUL_DOMAINS=volymolystudio-frontend.vercel.app
```

Vercel server environment:

```dotenv
API_UPSTREAM_URL=https://volymoly.com
LARAVEL_SESSION_COOKIE=laravel_session
```

Preserve production APP_KEY, database, SMTP and queue settings. Back up the
database, then run composer install --no-dev --optimize-autoloader,
php artisan migrate --force, and php artisan config:cache. If routes were
previously cached, refresh them with php artisan route:cache. Restart persistent
application/queue processes as required by your hosting setup. Deploy the frontend
with its server environment variables and the normal npm ci / npm run build flow.

Publish the Google consent screen when ready for users outside the test list,
and complete any verification Google requests. If the frontend domain changes,
update FRONTEND_URL, GOOGLE_REDIRECT_URI, the Console redirect URI/origin and
the frontend deployment together. Changing only the backend origin requires
updating APP_URL and API_UPSTREAM_URL.

Verify the complete browser flow, /me after refresh, account linking, password
login and logout on production. Ensure application/proxy access logs redact the
OAuth callback query (authorization codes/state); do not log Google tokens.


## Password login with email OTP

Password login now has two steps. All endpoints use the existing session and
CSRF middleware; no JWT or personal access token is issued.

- POST /api/auth/login with email/password returns HTTP 202 and otp_required.
  It queues a six-digit login code and stores a pending challenge in the session.
  The user remains unauthenticated; /api/auth/me returns 401 until OTP succeeds.
- POST /api/auth/login/verify with code completes login and rotates the session.
  The account comes from the password-verified session, not a submitted email.
- POST /api/auth/login/resend sends a replacement code to that same account.
  Resend is limited to once per 60 seconds and does not reset the five-attempt
  budget or the original ten-minute expiry. Use the latest code.

Login codes are hashed in the existing email_verification_codes table with
purpose=login. No additional schema migration or package installation is needed
for this change. Run existing migrations on a fresh database. Password/email
changes, an expired challenge, or a newer login invalidate the pending challenge.
Registration/recovery verification cannot authenticate a password login.
Existing Google OAuth keeps its separate provider verification flow.

Local setup: start the database, Laravel (port 8000), and Next.js (port 3000).
Configure working SMTP in Laravel and keep SESSION_DRIVER=database and
QUEUE_CONNECTION=database. In a separate terminal in backend-api run:

    php artisan queue:work --tries=3 --timeout=30

Do not use MAIL_MAILER=log when testing actual inbox delivery. Email credentials
remain in the ignored Laravel .env; no frontend mail credentials are needed.

Manual test with an existing email/password account:
1. Open http://localhost:3000, enter email, continue, and submit the password.
2. Confirm the six-box OTP screen opens and /api/auth/me still returns 401.
3. Enter the emailed code; confirm dashboard opens and /api/auth/me returns 200.
4. Refresh dashboard, log out, and confirm /api/auth/me returns 401 again.
5. Try a wrong code, a resend after 60 seconds, and an expired code. No dashboard
   access should be possible until a valid OTP completes the pending login.

Deploy backend and frontend together: /login now returns 202 instead of a
completed login response. Keep the production queue worker supervised and
restart it after deployment (php artisan queue:restart). Refresh route/config
caches using the deployment's normal process. Existing logged-in sessions stay
valid; new password logins require OTP. This change does not implement new-user
email/password registration.


## Resend and password-reset email operations

OTP resend and reset-link resend both go through Laravel. Accepted (HTTP 202)
means the email was queued; it does not confirm receipt in the user's inbox.
Resend is user-triggered, not an automatic mail loop. The frontend displays the
server's Retry-After cooldown on HTTP 429 and enables retry when it ends.

- Login OTP resend replaces the code while retaining the original ten-minute
  expiry and five-attempt limit. After expiry, restart password login.
- Password-reset links can be requested again via password/forgot after the
  60-second cooldown, including from the expired-link screen. Issuing a new link
  invalidates the previous token; successful reset consumes the latest token.
- Known and unknown email addresses receive the same reset response and cooldown.
- Newly queued authentication mail checks its OTP/reset token before sending.
  Expired, consumed, or superseded mail is skipped, including on queue retry.
- Mail payloads remain encrypted in the database queue. Transient SMTP errors
  propagate to the worker for retry (three attempts; backoff 10 then 60 seconds).
- Next.js hides backend server-error details and does not show resend success
  when Laravel returns a failure.

Production prerequisites (configure on the server, never in frontend code):

    APP_ENV=production
    APP_DEBUG=false
    APP_URL=https://volymoly.com
    FRONTEND_URL=https://volymolystudio-frontend.vercel.app
    SESSION_DRIVER=database
    SESSION_COOKIE=laravel_session
    SESSION_DOMAIN=null
    SESSION_SECURE_COOKIE=true
    SESSION_SAME_SITE=lax
    QUEUE_CONNECTION=database
    CACHE_STORE=database
    MAIL_MAILER=smtp
    MAIL_TIMEOUT=20

Set MAIL_HOST, MAIL_PORT, MAIL_SCHEME, MAIL_USERNAME, MAIL_PASSWORD and a verified
MAIL_FROM_ADDRESS from the chosen SMTP provider. Use its TLS settings; do not
fall back to a log mailer for real delivery. Keep the existing stable APP_KEY,
as changing it prevents existing encrypted queue jobs from being decrypted.

Run migrations using the normal deployment process (jobs, failed_jobs, sessions,
cache, password_reset_tokens and email_verification_codes tables are required).
No new migration is introduced by this resend change. Rebuild configuration and
route caches when deploying, then restart the supervised queue worker:

    php artisan config:cache
    php artisan route:cache
    php artisan queue:restart

Keep this process running under the server's process manager, with automatic
restart enabled:

    php artisan queue:work database --queue=default --sleep=1 --tries=3 --timeout=30

The configured queue retry_after (default 90 seconds) must exceed the job/worker
timeout. If DB_QUEUE changes, pass that queue name to the worker as well. Monitor
queue backlog and php artisan queue:failed. Fix SMTP/worker problems before
retrying failed jobs; request a fresh code/link if the original expired. Older
jobs queued before this change do not contain token metadata, so review old
failed jobs before bulk retrying them.

Deploy Next.js with API_UPSTREAM_URL=https://volymoly.com and
LARAVEL_SESSION_COOKIE=laravel_session, together with the updated Laravel code.
Validate delivery to a real test inbox, OTP login/logout, resend cooldown, an old
reset link failing after resend, and the latest reset link succeeding. Automated
tests use the real database queue and an in-memory mail transport, not live SMTP.


## Password-reset email design

Initial and resent reset links use resources/views/emails/password-reset.blade.php
with a plain-text alternative. The recipient and reset URL are dynamic; local and
production URLs come from the existing FRONTEND_URL configuration. The bundled
resources/images/mail/volymoly-logo.png is embedded in each email, so the logo
does not require a public image URL. Ship both views and the image with the API.

The email shows the configured password-broker expiry and single-use behavior.
Password reset does not disconnect Google or sign out sessions at external
providers. No database migration, new package, or mail environment variable is
needed for this template. After deploying, rebuild cached Blade views if used
(php artisan view:cache) and restart the existing queue workers
(php artisan queue:restart) so they load the template delivery code.


## Login verification email design

Password login and login/resend use the branded HTML email in
resources/views/emails/login-verification.blade.php, with a plain-text alternative
and the same embedded PNG logo as password-reset mail. The actual six-digit code
is carried only in the encrypted mail job; the verification table stores its hash.
The expiry message is calculated when the worker sends the message. Resending
does not extend the original ten-minute login challenge. Expired, superseded,
consumed, and locked challenges are still skipped before rendering/sending.

No new package, environment variable, or migration is required. Include both
new views when deploying, refresh cached views if used (php artisan view:cache),
and restart queue workers (php artisan queue:restart). Jobs queued before this
change still send their existing plain text. Registration/recovery code mail and
the reset-password email keep their existing delivery behavior.


## New-device login alert (reference UI)

The supplied New device signed in email is implemented in
resources/views/emails/new-device.blade.php, with a text alternative and embedded
logo. It uses the account email, browser/OS description, IP and country from the
successful login. It hooks password login after OTP verification and both Google
sign-in/linking. Failed passwords, wrong OTPs and pending Google links never
register a device or queue an alert.

Activation is intentionally OFF (NEW_DEVICE_ALERTS_ENABLED=false): the Review
activity / Secure My Account screens still need the user's design references.
No replacement UI or broken dashboard link has been invented. The activity API
is ready, but does not implement account securing/session revocation yet.

After implementing and deploying the approved review screen:
1. Run php artisan migrate using the normal deployment process. The new migration
   creates recognized_login_devices and login_activities. Do not reset the DB.
2. Set AUTH_PROXY_SECRET to the SAME random private value of at least 32 ASCII
   characters in Laravel and Next.js. Never use a NEXT_PUBLIC_ variable for it.
   One generation command is: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
3. Set LOGIN_ACTIVITY_REVIEW_PATH to the deployed frontend page path, for example
   /security/activity ONLY when that page actually exists. The email appends an
   opaque token query parameter. FRONTEND_URL supplies the canonical origin.
4. Enable NEW_DEVICE_ALERTS_ENABLED=true in Laravel, refresh config/views and
   restart workers: php artisan config:cache; php artisan view:cache;
   php artisan queue:restart. Keep the existing database queue worker running.
5. Deploy the Next.js proxy changes together with the API. No npm/composer package
   installation is required for this feature.

Recognition uses a random, encrypted, HttpOnly cookie named volymoly_device with
SameSite=Lax and the session's Secure setting. Its lifetime is 180 days. It only
suppresses duplicate device alerts; it never authenticates a user or bypasses OTP.
Device records are scoped to the account. Browser updates and IP changes with
the same cookie do not cause another alert. A different browser, private window,
cleared cookies or expired cookie counts as a new device. Existing users receive
a first alert at their first successful login after activation.

Alerts are queued after commit with a five-second delay and encrypted payload;
workers retry transient failures up to three times. Already-sent or expired
alerts are skipped. As with SMTP in general, a crash after server acceptance but
before recording success may duplicate an email. The review token is random,
hashed in the database, and valid for 24 hours. GET /api/auth/security/activity
through the Next.js proxy accepts only token and returns that event's email,
device, location, IP and timestamp; invalid/expired links return 410. Opening
or email-scanning this GET never logs in, revokes sessions or consumes the link.
The future frontend page must use no-referrer and avoid leaking its token to
analytics/external resources.

Next.js signs the browser metadata with HMAC. Laravel rejects unsigned, tampered,
or older-than-60-second metadata. On Vercel, the server reads the platform IP
and country headers described at https://vercel.com/docs/headers/request-headers.
Country is approximate IP geolocation, not GPS. Other hosts/local Next.js do not
trust incoming forwarded-IP/location headers; their email shows Unavailable for
missing values until a trusted hosting adapter is added. Direct Laravel requests
use the direct connection IP. No external geolocation service receives user IPs.
The user-agent display is best effort; Windows 10 and 11 share a user-agent
identifier, so the template truthfully says Windows 10 or later.

Manual verification after activation: log in with password+OTP in browser A,
confirm exactly one alert arrives, then log out/in in A and confirm no new alert.
Repeat in browser B/private mode, then test Google sign-in and linking. Inspect
the real country/IP, use the review link and test its expiry. Tests use SQLite
and an in-memory email transport; they do not verify actual SMTP inbox delivery.

## Deployment audit fixes (2026-10-07)

Auth endpoints now use separate named limits. Configure AUTH_PROXY_SECRET with
exactly the same private random value of at least 32 ASCII characters in Laravel
and the Next.js server environment. Signed Vercel client IPs determine rate-limit
buckets even with NEW_DEVICE_ALERTS_ENABLED=false. Without a valid signature the
transport IP remains the fallback; do not trust arbitrary X-Forwarded-For headers.

Password reset increments users.auth_session_version, rotates the remember token,
and deletes the account's database sessions. Middleware rejects old session
versions on the next request with any session driver. Existing version-zero
sessions remain valid until password reset. Google links remain connected; a
fresh successful Google or password/OTP login creates a valid new session.
The version column is hidden from API responses. Keep the new middleware on any
future authenticated route groups too. Migrate before serving the updated code;
do not roll back this column while the new code is running.

### Clean release and server steps

Do not upload the old backend-api.zip: it was stale and contained an environment
backup. It has been moved to the ignored .local-backups directory and labelled
UNSAFE-DO-NOT-DEPLOY. Environment backups and vendor backup directories are ignored.
Generate a source-only release with:

    powershell -File .\scripts\New-Release.ps1

The archive in dist includes current uncommitted source files and required empty
storage directories. It excludes .env files (except the placeholder .env.example),
cached bootstrap PHP, vendor, database contents, logs, previews, and backups.
The server must install dependencies from composer.lock; do not upload the old
vendor backup. Do not place archives or environment backups inside the public web
root. Configure the web server document root to Laravel's public directory.

Before deploying, back up the live database and preserve the existing production
.env and APP_KEY. Use a maintenance window or the host's atomic release process.
For an in-place deployment, run php artisan down on the current release before
replacing files. Then install the clean release and run on the server:

    composer install --no-dev --prefer-dist --optimize-autoloader
    php artisan optimize:clear
    php artisan migrate --force
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan queue:restart
    php artisan up

Verify writable storage and bootstrap/cache directories and a supervised queue
worker (php artisan queue:work database --queue=default --tries=3 --timeout=30).
Set APP_ENV=production, APP_DEBUG=false, APP_URL=https://volymoly.com,
FRONTEND_URL=https://volymolystudio-frontend.vercel.app, SESSION_DRIVER=database,
SESSION_COOKIE=laravel_session, SESSION_SECURE_COOKIE=true, SESSION_SAME_SITE=lax,
SESSION_DOMAIN=null and production database, SMTP and queue credentials.
Do not copy local .env values into production or generate a new APP_KEY.

Google production configuration must use real GOOGLE_CLIENT_ID and
GOOGLE_CLIENT_SECRET and this exact GOOGLE_REDIRECT_URI:
https://volymolystudio-frontend.vercel.app/api/auth/google/callback
Register that redirect URI and the origin
https://volymolystudio-frontend.vercel.app in Google Cloud Console. Use the
production application's actual frontend domain if it changes.

Redeploy the frontend with API_UPSTREAM_URL=https://volymoly.com and the matching
server-only AUTH_PROXY_SECRET. Local settings are in .env.development.local,
which is ignored and is not loaded by production builds. Both applications must
be updated. The live Google redirect 404 cannot be fixed by local tests alone.

After deployment, check /up, frontend /api/auth/csrf-token, and Google redirect
(302 to Google, not 404). With a designated test account, verify password -> OTP
-> dashboard, incorrect OTP, resend replacing the old code, logout, latest-only
reset link, password reset revoking a second browser's session, and fresh Google
login. Inspect queue failures and actual mailbox delivery. Unit/feature tests
use fake Google and mail responses; they do not prove live credentials/delivery.

NEW_DEVICE_ALERTS_ENABLED stays false until the user-provided Review Activity /
Secure My Account screens are implemented and deployed. Registration completion,
account recovery completion and the external-provider-connected email are separate
unfinished flow items; this release does not claim they are complete.
