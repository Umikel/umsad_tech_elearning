# Local setup and operations

This guide configures the current Umsad Tech application for local development with macOS XAMPP. It documents the code that exists in this repository; it does not imply that the application is ready for an internet-facing production deployment.

## 1. Prerequisites

- XAMPP with Apache, MySQL, and PHP 8.1+
- PHP extensions: `pdo_mysql`, `mbstring`, `curl`, `openssl`, `json`, and `fileinfo`
- Composer 2, or a deployment artifact that already includes `vendor/`
- A browser with JavaScript enabled
- Internet access for Google Fonts/remote images, Paystack hosted checkout, PHP-to-Paystack API calls, and outbound SMTP when email is enabled

The default project path used below is:

```text
/Applications/XAMPP/xamppfiles/htdocs/umsadtech
```

Confirm the XAMPP PHP version and required extensions:

```bash
/Applications/XAMPP/xamppfiles/bin/php -v
/Applications/XAMPP/xamppfiles/bin/php -m | grep -E 'curl|fileinfo|json|mbstring|openssl|PDO|pdo_mysql'
```

## 2. Start XAMPP

Use XAMPP Manager, or start the two required services from Terminal:

```bash
sudo /Applications/XAMPP/xamppfiles/xampp startapache
sudo /Applications/XAMPP/xamppfiles/xampp startmysql
```

Check service status:

```bash
sudo /Applications/XAMPP/xamppfiles/xampp status
```

The application and phpMyAdmin should then be available at:

- `http://localhost/umsadtech`
- `http://localhost/phpmyadmin`

If another application already uses ports 80 or 3306, resolve that conflict or configure XAMPP and `APP_URL` for the alternative ports.

## 3. Create `.env`

Run these commands from the project directory:

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/umsadtech
cp .env.example .env
```

Edit `.env` and verify at least:

```dotenv
DB_HOST=localhost
DB_USER=root
DB_PASS=
DB_NAME=umsad_tech_elearning

APP_NAME=Umsad Tech E-Learning
APP_URL=http://localhost/umsadtech
APP_ENV=development

SESSION_LIFETIME=86400
TIMEZONE=Africa/Lagos

PAYSTACK_PUBLIC_KEY=
PAYSTACK_SECRET_KEY=

MAIL_ENABLED=false
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your_smtp_username
MAIL_PASSWORD=replace_with_your_smtp_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME="Umsad Tech"
```

Notes:

- `APP_URL` must be an absolute `http://` or `https://` URL with the correct project path; a trailing slash is normalized away.
- `APP_ENV` accepts `development`, `testing`, or `production`.
- `DB_PORT` is optional and defaults to `3306`.
- `DB_SOCKET` is optional and can point to an absolute MySQL Unix-socket path; when set, it takes precedence over host and port.
- `SESSION_COOKIE_SECURE` defaults to true for HTTPS URLs and false for local HTTP. Set it explicitly to `true` in production.
- Paystack is configured only when the public and secret credentials are both valid and belong to the same test/live mode. A mixed pair disables checkout.
- Real process/server environment variables take precedence over values loaded from `.env`.
- `.env` is ignored by Git and denied by the included `.htaccess`. Do not publish or commit it.
- Email verification sends only when `MAIL_ENABLED=true` and all canonical SMTP values are valid. `MAIL_USER`, `MAIL_PASS`, and `MAIL_FROM` remain accepted as legacy aliases.
- `MAX_UPLOAD_SIZE` and `UPLOAD_PATH` remain legacy placeholders. Instructor material and learner assignment uploads use the validated limits and destination rules in the upload helper.

## 4. Import the database schema

[`database/schema.sql`](database/schema.sql) creates 22 tables in the database you select, including verification, password-reset, learning, payment, certificate, and transactional-notification records. This works with shared-hosting accounts that are not allowed to create databases from an import.

### Command line

```bash
/Applications/XAMPP/xamppfiles/bin/mysql -u root -p \
  -e "CREATE DATABASE IF NOT EXISTS umsad_tech_elearning CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
/Applications/XAMPP/xamppfiles/bin/mysql -u root -p umsad_tech_elearning \
  < database/schema.sql
```

Press Enter at the prompt if the local XAMPP root account has a blank password. If you use a different database account, update both the command and `.env`.

### phpMyAdmin

1. Open `http://localhost/phpmyadmin`.
2. Create `umsad_tech_elearning`, or select the database your host already assigned.
3. Choose **Import** inside that selected database.
4. Select `database/schema.sql` and run the import.

On shared hosting, the database may have an account prefix. Use that complete name in `DB_NAME`; the import intentionally contains no `CREATE DATABASE` or `USE` statement.

Verify the import:

```bash
/Applications/XAMPP/xamppfiles/bin/mysql -u root -p \
  -e "USE umsad_tech_elearning; SHOW TABLES;"
```

### Upgrade an existing database

Fresh imports of `database/schema.sql` already contain the current structures. For an older database, make a tested backup and apply the structural migrations below in this order **before publishing the new PHP code**.

1. Upgrade the payment table:

   ```bash
   /Applications/XAMPP/xamppfiles/bin/mysql -u root -p umsad_tech_elearning \
     < database/migrations/20260820_paystack_production.sql
   ```

   This adds hosted-checkout access data, provider status, verification/failure fields, a unique provider-transaction index, and payment lookup indexes. Existing duplicate non-null transaction IDs must be resolved before the unique index can be added.

2. Add email-verification state:

   ```bash
   /Applications/XAMPP/xamppfiles/bin/mysql -u root -p umsad_tech_elearning \
     < database/migrations/20260820_email_verification.sql
   ```

   The first run adds `users.email_verified_at` and marks accounts that already existed as verified so they are not locked out. Re-running the migration does not verify users registered later. It also creates `email_verification_tokens` if needed.

3. Add the transactional notification outbox:

   ```bash
   /Applications/XAMPP/xamppfiles/bin/mysql -u root -p umsad_tech_elearning \
     < database/migrations/20260822_notification_outbox.sql
   ```

   This creates `notification_outbox`, including unique event keys, due/stale-work indexes, delivery status, attempt, lock, and retry fields. The application expects this table when registering a learner, creating an enrollment, or completing a payment.

4. Add secure one-time password reset support:

   ```bash
   /Applications/XAMPP/xamppfiles/bin/mysql -u root -p umsad_tech_elearning \
     < database/migrations/20260901_learning_platform_features.sql
   ```

   This creates the password-reset token table used by `/forgot-password.php` and `/reset-password.php`. Quiz, assignment, review, certificate, and course-authoring tables already existed in the earlier schema.

5. Provision Website Development for Beginners after confirming that at least one active instructor exists:

   ```bash
   /Applications/XAMPP/xamppfiles/bin/mysql -u root -p umsad_tech_elearning \
     -e "SELECT id, email FROM users WHERE user_type='instructor' AND is_active=1 ORDER BY id LIMIT 1"
   /Applications/XAMPP/xamppfiles/bin/mysql -u root -p umsad_tech_elearning \
     < database/migrations/20260822_website_development_course.sql
   ```

   Stop if the prerequisite query returns no row; provision an instructor through the deployment's controlled account process before running the course migration. An existing matching course retains its owner. On first creation, the migration assigns the earliest active instructor and inserts the published ₦50,000 course with four weekly modules and twelve lessons. Re-running it inserts only missing records and preserves later operational edits.

The first four migrations are structural and must precede the matching application code. The fifth is an idempotent data migration for populated databases. A fresh schema plus the optional development seeder below already includes the course and does not require that separate data-provisioning step.

## 5. Check writable storage

The repository already contains:

```text
uploads/courses/
uploads/materials/
uploads/assignments/
```

Apache needs write access for instructor material uploads and learner assignment attachments. Inspect ownership before changing it:

```bash
ls -ld uploads uploads/courses uploads/materials uploads/assignments
```

Avoid `chmod 777`. Grant the Apache service account write access only to the required upload directories. The included `uploads/.htaccess` prevents script execution and directory listing; confirm Apache honors it before accepting public uploads.

## 6. Optional development seed

Run the seeder only after the schema import and only in a non-production environment:

```bash
/Applications/XAMPP/xamppfiles/bin/php seed-data.php
```

Safety behavior:

- Web requests to `seed-data.php` receive a 404; it is command-line only.
- It refuses to run while `APP_ENV=production`.
- It checks `users` first and makes no changes when any user already exists.
- The inserts run in a transaction.
- On the first successful run, passwords are generated and printed once.
- Seeded accounts explicitly receive `email_verified_at=NOW()` and can sign in immediately.

The seed creates:

- `admin@example.com`
- `instructor@example.com` and `instructor2@example.com`
- `student@example.com` and `student2@example.com`
- five published courses, 12 modules, 21 lessons, and sample enrollments
- Website Development for Beginners with four weekly modules and twelve live-class lessons

The two instructors share the printed instructor password; the two students share the printed student password. Capture the generated credentials securely and change them through `/settings.php` after signing in.

For repeatable local credentials, append values of 12–72 characters to `.env` before the first seed:

```dotenv
SEED_ADMIN_PASSWORD=replace-with-a-long-local-secret
SEED_INSTRUCTOR_PASSWORD=replace-with-a-long-local-secret
SEED_STUDENT_PASSWORD=replace-with-a-long-local-secret
```

Never reuse development seed passwords in a deployed environment. Public `/register.php` creates student accounts only; production administrators can provision privileged accounts from `/admin/users.php` after signing in.

## 7. Open and verify the application

Start at `http://localhost/umsadtech` and check:

1. The homepage and `/courses.php` load published courses.
2. `/register.php` creates a student account and sends its verification and welcome messages when SMTP is enabled.
3. Login sends each seeded role to its own dashboard.
4. Logout opens a confirmation page and submits a CSRF-protected POST.
5. A student can enroll in the free seeded course from its course-details page and receive an enrollment confirmation.
6. `/student/course-lessons.php?id=COURSE_ID` rejects users without an enrollment.
7. Marking lessons complete updates student progress.
8. A learner can take a quiz, submit an assignment, receive a grade, and review an enrolled course.
9. Completing every course lesson issues a unique certificate automatically.
10. An instructor can create a draft course, add modules, lessons and materials, publish it, export learner progress, and grade submissions.
11. An administrator can provision accounts, manage course ownership/publication, moderate reviews/messages, and export the payment ledger.
12. `/profile.php` and `/settings.php` update only the signed-in account.
13. `/contact.php` inserts a row into `contact_messages`.

### Role destinations

| Role | Primary pages | Current behavior |
| --- | --- | --- |
| Student | `/student/dashboard.php`, `/student/my-courses.php`, `/student/course-lessons.php`, `/student/quiz.php`, `/student/assignment.php`, `/student/certificate.php` | Course learning, lesson progress, assessments, feedback, reviews, and certificates |
| Instructor | `/instructor/dashboard.php`, `/instructor/courses.php`, `/instructor/course-edit.php`, `/instructor/learners.php`, `/instructor/assessments.php`, `/instructor/submissions.php` | Owned-course authoring, publication, learner reporting, assessments, and grading |
| Admin | `/admin/dashboard.php`, `/admin/users.php`, `/admin/courses.php`, `/admin/engagement.php`, `/admin/payments.php` | User/course operations, moderation, engagement, and payment reporting |

Role checks and ownership filters are enforced server-side. Students cannot open un-enrolled learning content, instructors cannot manage another instructor's course, and administrators are protected from deactivating or demoting the final active administrator.

## 8. Configure signup and transactional email

Install PHPMailer and the locked runtime dependencies:

```bash
composer install --no-dev --optimize-autoloader
```

If Composer is unavailable on the production host, run that command before packaging and upload the resulting `vendor/` directory with the code. The included `.htaccess` denies direct access to `vendor/` and Composer metadata.

Set these values in the live `.env` using credentials from an authenticated SMTP provider:

```dotenv
MAIL_ENABLED=true
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your_smtp_username
MAIL_PASSWORD=replace_with_your_smtp_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME="Umsad Tech"
MAIL_REPLY_TO_ADDRESS=support@example.com
MAIL_TIMEOUT=10

EMAIL_VERIFICATION_TTL=86400
EMAIL_VERIFICATION_RESEND_COOLDOWN=60
EMAIL_VERIFICATION_MAX_PER_HOUR=5
PASSWORD_RESET_TTL=3600
PASSWORD_RESET_COOLDOWN=60
PASSWORD_RESET_MAX_PER_HOUR=5
```

Use `tls` with port 587 or 2525, or `smtps` with port 465. After signup, the message links to `/verify-email.php`; an unverified learner can request another message at `/resend-verification.php`. Resends replace the current token and are limited by both the cooldown and hourly maximum. Password recovery uses `/forgot-password.php` and `/reset-password.php` with the same non-enumerating, hashed-token, cooldown, hourly-limit, expiry, and single-use protections.

The application also queues three branded transactional messages:

- a welcome message after signup;
- a course confirmation after a new free or paid enrollment;
- a payment confirmation after the first successful paid-course fulfillment.

Each business event has a stable unique key, so repeated form requests, verification callbacks, or signed webhooks do not intentionally create duplicate queue entries. The application attempts delivery only after the owning database transaction commits. When SMTP is temporarily unavailable, the completed signup, enrollment, or payment remains valid and its message stays queued.

Process all currently due messages, up to the requested batch size, from the project directory:

```bash
/Applications/XAMPP/xamppfiles/bin/php bin/process-notification-outbox.php --limit=100
```

The default limit is 50; `--limit` accepts an integer from 1 through 500. The worker is CLI-only and refuses to consume the queue when `MAIL_ENABLED` or another required SMTP setting is missing. Transient delivery failures return to `pending` with increasing retry delays, while a stale processing lock can be recovered after ten minutes.

For deployment, schedule the worker every minute under a dedicated service account that can read the application, `.env`, and Composer dependencies. Capture output in the platform's protected logging system and monitor nonzero exits. A macOS XAMPP `crontab -e` example is:

```cron
* * * * * cd /Applications/XAMPP/xamppfiles/htdocs/umsadtech && /Applications/XAMPP/xamppfiles/bin/php bin/process-notification-outbox.php --limit=100 2>&1 | /usr/bin/logger -t umsad-notifications
```

Check `notification_outbox.status`, `attempts`, `available_at`, and `last_error` when investigating delivery. The worker reports processed, sent, and retained-for-retry totals without exposing recipient content or SMTP credentials.

Configure the From domain's SPF record for your sender, enable DKIM signing through the provider, and publish a DMARC policy. Test link generation, expiry, resend limits, and inbox/spam delivery on HTTPS before launch.

## 9. Configure Paystack hosted checkout

The paid-enrollment path initializes each transaction from PHP, redirects the learner to Paystack's hosted checkout, and fulfills access through server verification from the browser callback or a signed webhook.

1. Obtain a matching Paystack test public/secret pair. Paste both values into the blank `PAYSTACK_PUBLIC_KEY` and `PAYSTACK_SECRET_KEY` entries in `.env`; do not log, publish, or commit either credential.
2. Apply the payment migration above if this is an existing database.
3. Confirm cURL is enabled:

   ```bash
   /Applications/XAMPP/xamppfiles/bin/php -r 'var_export(function_exists("curl_init")); echo PHP_EOL;'
   ```

4. Sign in as a student who is not enrolled in a published paid course.
5. Open `/course-detail.php?id=COURSE_ID` and select **Enroll securely**. PHP calls Paystack's `/transaction/initialize`; the browser accepts only the returned HTTPS URL on `checkout.paystack.com` and leaves the application for hosted checkout.
6. Use Paystack's current official test-payment data. Paystack returns the browser to `/payment-callback.php`, which verifies the reference server-to-server before redirecting to the lesson viewer.
7. For a deployed test environment, configure the Paystack dashboard webhook URL as `https://your-app.example/api/payments/webhook.php`, including the application's base path when it is hosted in a subdirectory. A local `http://localhost` URL cannot receive provider webhooks.
8. Confirm the `payments` row has provider/verification data, the correct `student_enrollments` row exists, and the enrollment/payment confirmation events are sent or queued.

The server, not the browser, calculates the price. Verification checks gateway mode, ownership, status, reference, provider transaction ID, integer minor-unit amount, and `NGN` currency. Recent pending attempts and repeated callback/webhook deliveries are handled idempotently. Successful duplicate payments are flagged for review instead of silently creating duplicate access.

### Payment lifecycle

1. `POST /api/payments/initialize.php` creates or reuses the local pending payment.
2. PHP sends the email, amount, currency, callback URL, server-generated reference, and metadata to Paystack `/transaction/initialize`.
3. The validated authorization URL is saved, and the browser redirects to `https://checkout.paystack.com`.
4. `/payment-callback.php` never trusts the redirect as proof; an authenticated learner session calls `/api/payments/verify.php`, which queries Paystack `/transaction/verify/{reference}`.
5. Independently, `POST /api/payments/webhook.php` verifies `X-Paystack-Signature` as HMAC-SHA512 over the exact raw request body. A signed `charge.success` event is then re-fetched from Paystack before it uses the same transactional fulfillment logic, without a browser session or CSRF token.

Both paths validate the local payment and atomically mark it complete and enroll the student. Unsupported webhook events are acknowledged without fulfillment.

### JSON endpoint contract

The first three endpoints require an active student session and a valid CSRF token:

```text
POST /api/enrollments/create.php     {"course_id": 1}
POST /api/payments/initialize.php    {"course_id": 1}
POST /api/payments/verify.php        {"reference": "provider-reference"}
POST /api/payments/webhook.php       raw Paystack event JSON + signature header
```

For enrollment, initialize, and verify, use `Content-Type: application/json` and send the token through `X-CSRF-Token` or a `csrf_token` JSON property. The existing frontend does this automatically. The webhook is the exception: it has no user session or CSRF token and accepts only a correctly signed raw event body. There are no `/api/auth/*` or course CRUD endpoints.

### Enable live checkout

Use a matching live public/secret credential pair only in the deployment environment. Live checkout is deliberately unavailable unless all of these are true:

```dotenv
APP_ENV=production
APP_URL=https://your-app.example
SESSION_COOKIE_SECURE=true
```

The HTTPS `APP_URL` must include the application subdirectory, if any. Configure the matching public webhook URL in Paystack, confirm the migration is applied, then run a deployment-specific end-to-end checkout and webhook test. The application rejects mixed test/live credentials and refuses live initialization on development, HTTP, or insecure-cookie configurations.

## 10. Security and deployment notes

Current controls include prepared PDO statements, escaped HTML output, CSRF tokens, bcrypt, login-attempt throttling, session regeneration/expiry, role checks, enrollment ownership checks, safe YouTube/Vimeo embedding, server-side payment initialization/verification, fixed hosted-checkout validation, and signed webhook fulfillment.

Before exposing the application publicly:

- set `APP_ENV=production`;
- serve only over HTTPS and set `SESSION_COOKIE_SECURE=true`;
- use a dedicated least-privilege MySQL account;
- move secrets outside the served document root where possible;
- confirm Apache allows the project's `.htaccess` directives;
- package `vendor/` (or run Composer on the host) and confirm direct requests to it are denied;
- configure SPF, DKIM, and DMARC, run the outbox worker, and monitor rejected/bounced transactional mail;
- separately restrict or remove `diagnose*.php` and `test-*.php`;
- rotate all seed credentials and Paystack keys;
- configure database backups, log rotation, monitoring, and recovery tests;
- monitor failed/flagged payments and Paystack callback/webhook delivery;
- review third-party resources and perform security and accessibility testing.

The local diagnostic pages return 404 unless the request comes from localhost while `APP_ENV=development`. They intentionally reveal technical details and must not be exposed on a network-accessible development server.

## 11. Troubleshooting

### Apache or MySQL will not start

```bash
sudo /Applications/XAMPP/xamppfiles/xampp status
tail -n 80 /Applications/XAMPP/xamppfiles/logs/error_log
```

Check for port conflicts and review XAMPP Manager. Do not start a second MySQL instance over the same data directory.

### `Unable to connect to the database`

- Confirm MySQL is running.
- Compare `.env` credentials with the account used for schema import.
- Confirm `umsad_tech_elearning` and its tables exist.
- If `localhost` resolves to the wrong socket, try `DB_HOST=127.0.0.1` and confirm `DB_PORT`.
- Review `/Applications/XAMPP/xamppfiles/logs/php_error_log`.

### 404 or redirects point to the wrong folder

Set `APP_URL` to the exact browser base URL, for example `http://localhost/umsadtech`. Confirm Apache's document root contains this project directory.

### Login immediately expires

Clear the application's browser cookies after changing session settings. Confirm the system clock, `SESSION_LIFETIME`, and that `SESSION_COOKIE_SECURE` is not true on plain local HTTP.

### 403/419-style CSRF failure

Reload the form/page to obtain a fresh token. Ensure JavaScript requests send cookies with same-origin credentials and do not mix `localhost`, `127.0.0.1`, ports, or schemes.

### Free enrollment is rejected

The endpoint accepts only published courses whose effective database price is exactly zero. A positive `discount_price` still makes the course paid.

### Paystack is unavailable or verification fails

- Confirm the public and secret credentials are a valid matching test pair or matching live pair.
- Confirm PHP cURL and outbound HTTPS work.
- Confirm the payment migration has been applied to an older database.
- Check that the transaction amount and currency were not changed in the database after initialization.
- Reload after a stale session/CSRF error.
- Inspect `php_error_log`; secret keys are intentionally not sent to the browser.

For live mode, also confirm `APP_ENV=production`, HTTPS `APP_URL`, and `SESSION_COOKIE_SECURE=true`. If checkout succeeds but no enrollment appears, verify that Paystack can reach the configured webhook URL and that its `X-Paystack-Signature` header reaches PHP unchanged.

### Verification email is not delivered

- Confirm the email-verification and notification-outbox migrations ran before the new code and that `vendor/autoload.php` exists.
- Confirm `MAIL_ENABLED=true`, the SMTP username/password are correct, and TLS mode matches the port.
- Confirm the host allows outbound SMTP and inspect the PHP error log without printing credentials.
- Check spam/bounce reports and verify SPF, DKIM, and DMARC alignment for `MAIL_FROM_ADDRESS`.
- Use `/resend-verification.php` after the cooldown; hourly rate limits intentionally reject repeated requests.

### Welcome, enrollment, or payment email is not delivered

- Run `bin/process-notification-outbox.php --limit=100` with the same environment used by the application.
- Inspect `notification_outbox` for `pending`, `processing`, or `failed` rows and review `attempts`, `available_at`, and `last_error`.
- Confirm the cron service account can read `.env` and `vendor/`, connect to MySQL, and reach the SMTP server.
- A disabled or incomplete mail configuration intentionally leaves messages pending; correct it and run the worker again.
- Review the protected PHP/server log for the generic delivery error, and use the SMTP provider's bounce/delivery log for recipient-specific detail.

### Styles or icons are missing

Google Fonts and some placeholder images are remote; Bootstrap and Font Awesome are stored locally. Check internet access, browser developer tools, and Content Security Policy/proxy rules.

### PHP syntax check

```bash
find . -type f -name '*.php' -print0 \
  | xargs -0 -n1 /Applications/XAMPP/xamppfiles/bin/php -l
```

For a local development diagnostic, visit `/diagnose.php` or `/test-system.php`. These routes should return 404 outside localhost development mode.
