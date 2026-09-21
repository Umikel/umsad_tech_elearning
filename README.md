# Umsad Tech E-Learning

Umsad Tech is a full-role PHP/MySQL learning platform with a responsive public catalog and dedicated learner, instructor, and administrator workspaces. It supports secure accounts, enrollment and payments, structured course authoring, lesson progress, quizzes, assignments, grading, moderated reviews, automatic certificates, reporting, and operational administration.

The application is designed for XAMPP-style Apache/PHP hosting and shared-hosting deployment. Account verification and password recovery use hashed, expiring email tokens; a durable outbox handles welcome, enrollment, and payment messages; and paid enrollment uses server-verified Paystack hosted checkout.

## Current functionality

| Area | Implemented now |
| --- | --- |
| Public site | Homepage, published-course search/filtering, course details, about, contact, privacy, and terms pages |
| Accounts | Learner registration, email verification/resend, rate-limited one-time password reset, login throttling, secure logout, profile editing, email/password changes, and session expiry |
| Students | Learning dashboard, course library, free/paid enrollment, protected lessons and materials, progress tracking, timed quizzes, assignment submissions, instructor feedback, reviews, and automatic certificates |
| Instructors | Analytics dashboard, ownership-scoped course/module/lesson/resource authoring, publication controls, learner progress and CSV export, quiz/question building, assignments, and grading |
| Administrators | Platform metrics, account provisioning/roles/status, course publication/ownership, review and contact moderation, and searchable/exportable payment ledger |
| Payments | Server-side Paystack initialization, hosted checkout redirect, authenticated callback verification, signed webhook fulfillment, and payment-confirmation email |
| Notifications | Idempotent transactional outbox with immediate best-effort delivery and a CLI retry worker |
| Development data | CLI-only seed command with generated or explicitly configured passwords and five published courses |

The 22-table database includes users, verification and reset tokens, course content, materials, enrollments, progress, quizzes, assignments, payments, reviews, certificates, blog records, contact messages, and the notification outbox. Blog authoring is the main schema-backed area that does not yet have an editorial interface.

## Requirements

- Apache 2.4 (XAMPP is the expected local environment)
- PHP 8.1 or newer; the project is currently linted with XAMPP PHP 8.2
- PHP extensions: PDO MySQL, mbstring, cURL, OpenSSL, JSON, and fileinfo
- MySQL 5.7 or newer
- Composer 2, or a deployment package that already contains `vendor/`
- Internet access for Google Fonts and remote placeholder images; outbound SMTP and HTTPS from PHP are required for email and Paystack

The examples below assume this macOS XAMPP location:

```text
/Applications/XAMPP/xamppfiles/htdocs/umsadtech
```

If the folder or web-server port differs, update `APP_URL` accordingly.

## Quick installation

1. Start Apache and MySQL from XAMPP Manager, or run:

   ```bash
   sudo /Applications/XAMPP/xamppfiles/xampp startapache
   sudo /Applications/XAMPP/xamppfiles/xampp startmysql
   ```

2. From the project directory, create the local configuration:

   ```bash
   cp .env.example .env
   ```

   Keep `APP_ENV=development` locally, confirm the database credentials, and set:

   ```dotenv
   DB_HOST=localhost
   DB_USER=root
   DB_PASS=
   DB_NAME=umsad_tech_elearning
   APP_URL=http://localhost/umsadtech
   ```

3. Create/select the database, then import the schema into it:

   ```bash
   /Applications/XAMPP/xamppfiles/bin/mysql -u root -p \
     -e "CREATE DATABASE IF NOT EXISTS umsad_tech_elearning CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   /Applications/XAMPP/xamppfiles/bin/mysql -u root -p umsad_tech_elearning \
     < database/schema.sql
   ```

   With XAMPP's default blank MySQL root password, press Enter at the password prompt. Alternatively, create and select the database in [phpMyAdmin](http://localhost/phpmyadmin), then import [`database/schema.sql`](database/schema.sql). On shared hosting, use the exact prefixed database name assigned by the provider and set the same value as `DB_NAME`.

   The current 22-table schema already includes payment, email-verification, password-reset, and notification-outbox structures. When upgrading a database created from an older schema, back it up and apply these structural migrations in order **before uploading the new PHP code**:

   ```bash
   /Applications/XAMPP/xamppfiles/bin/mysql -u root -p umsad_tech_elearning \
     < database/migrations/20260820_paystack_production.sql
   /Applications/XAMPP/xamppfiles/bin/mysql -u root -p umsad_tech_elearning \
     < database/migrations/20260820_email_verification.sql
   /Applications/XAMPP/xamppfiles/bin/mysql -u root -p umsad_tech_elearning \
     < database/migrations/20260822_notification_outbox.sql
   /Applications/XAMPP/xamppfiles/bin/mysql -u root -p umsad_tech_elearning \
     < database/migrations/20260901_learning_platform_features.sql
   ```

   The email migration marks only accounts that existed when the column was first introduced as verified, preserving their access. Re-running it never automatically verifies subsequently registered accounts.

   The Website Development for Beginners migration is a separate, idempotent data migration. It requires at least one active instructor; an existing course keeps its owner, while a new course is assigned to the earliest active instructor. Confirm that prerequisite, then run:

   ```bash
   /Applications/XAMPP/xamppfiles/bin/mysql -u root -p umsad_tech_elearning \
     -e "SELECT id, email FROM users WHERE user_type='instructor' AND is_active=1 ORDER BY id LIMIT 1"
   /Applications/XAMPP/xamppfiles/bin/mysql -u root -p umsad_tech_elearning \
     < database/migrations/20260822_website_development_course.sql
   ```

   It adds the published ₦50,000 course, four weekly modules, and twelve lessons without overwriting later operational edits. A fresh schema plus `seed-data.php` already receives the same course, so the data migration is mainly for an existing populated database.

4. Optionally add development accounts, courses, lessons, and enrollments:

   ```bash
   /Applications/XAMPP/xamppfiles/bin/php seed-data.php
   ```

   `seed-data.php` runs only from a command line, refuses `APP_ENV=production`, and exits without changes when the `users` table already contains data. On a first run it generates strong role passwords and prints them once, and creates five published courses (including Website Development for Beginners), 12 modules, and 21 lessons. Store the password output safely and change the passwords after signing in.

5. Open [http://localhost/umsadtech](http://localhost/umsadtech).

For expanded setup and troubleshooting, see [`SETUP.md`](SETUP.md). For the shortest checklist, see [`QUICKSTART.md`](QUICKSTART.md).

## Environment configuration

[`includes/config.php`](includes/config.php) loads `.env` without overwriting real process/server environment variables. Start from [`.env.example`](.env.example); never commit the resulting `.env`.

Runtime settings currently used include:

| Variable | Purpose |
| --- | --- |
| `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME`, `DB_SOCKET` | MySQL connection (`DB_PORT` defaults to `3306`; `DB_SOCKET` is optional) |
| `APP_NAME`, `APP_URL`, `APP_ENV` | Display name, absolute base URL, and `development`, `testing`, or `production` behavior |
| `SESSION_LIFETIME` | Inactivity timeout in seconds, clamped to 5 minutes–30 days |
| `SESSION_COOKIE_NAME`, `SESSION_COOKIE_SECURE` | Optional session-cookie overrides |
| `PASSWORD_COST` | Optional bcrypt cost, clamped to 10–14 |
| `PAYSTACK_PUBLIC_KEY`, `PAYSTACK_SECRET_KEY` | A matching Paystack test pair or matching live pair; mixed modes disable checkout |
| `MAIL_ENABLED`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` | Authenticated SMTP; use `tls` on 587/2525 or `smtps` on 465 |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `MAIL_REPLY_TO_ADDRESS`, `MAIL_TIMEOUT` | Sender identity, optional reply-to, and 5–30 second connection timeout |
| `EMAIL_VERIFICATION_TTL`, `EMAIL_VERIFICATION_RESEND_COOLDOWN`, `EMAIL_VERIFICATION_MAX_PER_HOUR` | Token lifetime and resend limits, validated to safe ranges |
| `PASSWORD_RESET_TTL`, `PASSWORD_RESET_COOLDOWN`, `PASSWORD_RESET_MAX_PER_HOUR` | Password-reset lifetime and request limits, validated to safe ranges |
| `TIMEZONE` | PHP timezone; defaults to `Africa/Lagos` |

Legacy `MAIL_USER`, `MAIL_PASS`, and `MAIL_FROM` names remain accepted, but new deployments should use the canonical names above. `MAX_UPLOAD_SIZE` and `UPLOAD_PATH` are reserved placeholders; the upload helper uses its own validated defaults.

## Accounts and role routes

Public registration creates student accounts only. Administrators can provision verified student, instructor, or administrator accounts from the protected user-management workspace. The final active administrator cannot be demoted or deactivated.

| Audience | Routes |
| --- | --- |
| Public | `/`, `/courses.php`, `/course-detail.php?id=ID`, `/about.php`, `/contact.php`, `/login.php`, `/register.php`, `/verify-email.php`, `/resend-verification.php`, `/forgot-password.php`, `/reset-password.php`, `/privacy.php`, `/terms.php` |
| Any authenticated user | `/profile.php`, `/settings.php`, `/logout.php` |
| Student | `/student/dashboard.php`, `/student/my-courses.php`, `/student/course-lessons.php?id=ID`, `/student/quiz.php?quiz_id=ID`, `/student/assignment.php?assignment_id=ID`, `/student/certificate.php?course_id=ID` |
| Instructor | `/instructor/dashboard.php`, `/instructor/courses.php`, `/instructor/course-edit.php`, `/instructor/learners.php`, `/instructor/assessments.php`, `/instructor/submissions.php` |
| Administrator | `/admin/dashboard.php`, `/admin/users.php`, `/admin/courses.php`, `/admin/engagement.php`, `/admin/payments.php` |

Lesson, quiz, and assignment access are checked against the current student's enrollment. Completing every lesson marks the enrollment complete and issues a unique certificate automatically; older completed enrollments are backfilled when their certificate is opened.

`/forgot-password.php` always returns the same user-facing response for eligible and unknown addresses. Reset tokens are stored as SHA-256 hashes, rate limited, expire automatically, and are deleted after one successful password change.

## Signup and transactional email

Install dependencies with `composer install --no-dev --optimize-autoloader`. If the live host cannot run Composer, package and upload the generated `vendor/` directory with the application; Apache denies direct web access to it.

Set the canonical `MAIL_*` variables from `.env.example`, enable mail, and use credentials issued by your SMTP provider. New students receive a time-limited link to `/verify-email.php`; `/resend-verification.php` issues a replacement subject to cooldown and hourly limits. Tokens are stored only as SHA-256 hashes, and each user has at most one current token.

The application also queues three branded transactional messages: a welcome message after signup, a course confirmation when a new enrollment is created, and a payment confirmation after the first successful paid-course fulfillment. Queue records use stable event keys, so a repeated enrollment request, callback, or webhook does not intentionally enqueue the same business event twice. The request attempts delivery after its database transaction commits; an SMTP failure does not undo a successful signup, enrollment, or payment.

Run the CLI worker regularly to deliver due retries (the default batch is 50; `--limit` accepts 1–500):

```bash
/Applications/XAMPP/xamppfiles/bin/php bin/process-notification-outbox.php --limit=100
```

For a deployed site, schedule it every minute under a service account that can read the application and `.env`, for example with `crontab -e`:

```cron
* * * * * cd /Applications/XAMPP/xamppfiles/htdocs/umsadtech && /Applications/XAMPP/xamppfiles/bin/php bin/process-notification-outbox.php --limit=100 2>&1 | /usr/bin/logger -t umsad-notifications
```

The worker exits without consuming queued messages when SMTP is not fully configured. Review its exit status, protected PHP/server logs, and `notification_outbox` rows in production; transient failures are rescheduled with increasing delays.

Before sending publicly, configure SPF for the sending service, enable DKIM signing, and publish a DMARC policy for the From domain. Verify delivery and spam placement with a real mailbox; valid SMTP credentials alone do not establish domain alignment or reputation.

## HTTP endpoints

These are the application JSON endpoints currently implemented:

| Method and endpoint | JSON body | Rules |
| --- | --- | --- |
| `POST /api/enrollments/create.php` | `{"course_id": 1}` | Authenticated student; published course whose effective price is exactly zero |
| `POST /api/payments/initialize.php` | `{"course_id": 1}` | Authenticated student; creates/reuses a pending payment, calls Paystack `/transaction/initialize`, and returns a validated hosted-checkout URL |
| `POST /api/payments/verify.php` | `{"reference": "provider-reference"}` | Authenticated student; reference must belong to that student and pass server-to-server Paystack verification |
| `POST /api/payments/webhook.php` | Raw Paystack event JSON | No browser session or CSRF; requires a valid `X-Paystack-Signature` HMAC-SHA512 signature |

The enrollment, initialize, and verify endpoints must be same-origin, include the authenticated session cookie, use `Content-Type: application/json`, and supply the session CSRF token in the `X-CSRF-Token` header or `csrf_token` JSON field. The frontend handles this automatically. The webhook is deliberately sessionless and authenticates the exact raw body with Paystack's signature instead. Responses use the envelope:

```json
{
  "success": true,
  "message": "...",
  "data": {}
}
```

There are no `/api/auth/*`, course CRUD, payment-history, invoice, quiz, or assignment endpoints. Login and registration are server-rendered form posts.

## Paystack development setup

1. Obtain a Paystack test public/secret pair and place both values in `.env`. The application accepts checkout configuration only when both credentials are valid and declare the same mode.
2. Apply [`database/migrations/20260820_paystack_production.sql`](database/migrations/20260820_paystack_production.sql) when upgrading an existing database. Fresh imports of `database/schema.sql` already contain those columns and indexes.
3. Confirm PHP cURL is enabled and the server can make outbound HTTPS requests to `api.paystack.co`.
4. Sign in as a student and open a published paid course. The application calculates the amount, calls Paystack's `/transaction/initialize` from PHP, validates the returned `https://checkout.paystack.com` URL, and redirects the browser there.
5. Paystack returns the learner to `/payment-callback.php`. That page treats the redirect only as a prompt to call the authenticated verification endpoint, which checks the transaction with Paystack before enrollment.
6. Configure the Paystack webhook URL as `https://your-app.example/api/payments/webhook.php` (including any application subdirectory). A signed `charge.success` event prompts a fresh server-to-server verification and can fulfill the same payment even when the learner does not return to the callback.
7. Use Paystack's current official test-payment data and confirm the payment, provider status, verification timestamp, and enrollment after success.

Fulfillment is transactional and idempotent across callback retries and webhook delivery. It validates payment mode, reference, provider transaction ID, integer minor-unit amount, `NGN` currency, account state, and local ownership before granting access.

For live checkout, use a matching live credential pair and all three required application settings: `APP_ENV=production`, an HTTPS `APP_URL`, and `SESSION_COOKIE_SECURE=true`. The course page and initialization endpoint refuse live checkout when any requirement is missing. The webhook URL must also be publicly reachable over HTTPS; Paystack cannot call a localhost address.

## Project layout

```text
umsadtech/
├── admin/                  # Users, catalog, moderation, payments, and platform operations
├── api/
│   ├── enrollments/        # Free-enrollment endpoint
│   └── payments/           # Paystack initialize, verify, and signed webhook endpoints
├── assets/                 # Shared CSS and JavaScript
├── bin/                    # CLI notification retry worker
├── database/
│   ├── schema.sql          # Current 22-table MySQL schema
│   └── migrations/         # Existing-database upgrades
├── includes/               # Environment, auth, course content, Paystack, mail, and helpers
├── instructor/             # Course authoring, learners, assessments, and grading
├── student/                # Dashboard, lessons, quizzes, assignments, and certificates
├── templates/              # Shared header and footer
├── uploads/                # Course/material/assignment storage directories
├── vendor/                 # Composer runtime dependencies (web access denied)
├── .env.example            # Local configuration template
├── .htaccess               # Apache access restrictions and security headers
├── payment-callback.php    # Hosted-checkout browser return and verification UI
├── seed-data.php           # Development-only CLI seed command
└── *.php                   # Public and account pages
```

## Security notes

The implementation includes prepared PDO statements, escaped output, server-side validation, CSRF protection, bcrypt password hashing, hashed/expiring verification and reset tokens, request throttling, session ID rotation, HTTP-only SameSite cookies, role and resource-ownership checks, upload validation, server-side Paystack initialization/verification, strict checkout-host validation, and signed webhook processing.

Those controls do not replace a production security review. Before deployment:

- set `APP_ENV=production`, use HTTPS, and set `SESSION_COOKIE_SECURE=true`;
- keep `.env` out of version control and preferably outside the served document root;
- ensure Apache honors the included `.htaccess`, which denies `.env`, dotfiles, docs, `includes/`, and `database/`;
- remove or separately restrict development diagnostic/test scripts;
- use a least-privilege MySQL account instead of a database administrator;
- keep SMTP credentials in `.env`, configure SPF/DKIM/DMARC, run and monitor the outbox worker, and review failed delivery records;
- rotate seeded credentials, configure backups/log monitoring, and test restore procedures;
- monitor callback/webhook failures, review flagged duplicate payments, and complete an application/security review.

Diagnostic pages return 404 outside localhost development mode, but they can expose useful internals while enabled. Do not make a development environment publicly reachable.

## Verification

Lint all PHP files with the XAMPP runtime:

```bash
find . -type f -name '*.php' -print0 \
  | xargs -0 -n1 /Applications/XAMPP/xamppfiles/bin/php -l
```

Then test registration, verification and password-reset expiry/rate limits, role redirects, course authoring/publication, free enrollment, lesson progress, quiz scoring, assignment submission/grading, review moderation, certificate issuance, profile/settings changes, contact handling, payment-ledger export, hosted Paystack test checkout, callback/webhook verification, and retry-worker processing with Apache and MySQL running.

## License and support

This repository identifies the project as proprietary Umsad Tech software. For application support, use `/contact.php` or email `support@umsadtech.com`.
