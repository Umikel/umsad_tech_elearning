# Umsad Tech quick start

Use this checklist for the macOS XAMPP development setup. See [`SETUP.md`](SETUP.md) for details and troubleshooting.

## 1. Start Apache and MySQL

Start both services in XAMPP Manager, or run:

```bash
sudo /Applications/XAMPP/xamppfiles/xampp startapache
sudo /Applications/XAMPP/xamppfiles/xampp startmysql
```

## 2. Configure the application

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/umsadtech
cp .env.example .env
```

Check these values in `.env`:

```dotenv
DB_HOST=localhost
DB_USER=root
DB_PASS=
DB_NAME=umsad_tech_elearning
APP_URL=http://localhost/umsadtech
APP_ENV=development
```

Leave the Paystack placeholders unchanged if you only need free-course enrollment. Do not commit `.env`.

## 3. Import the schema

Create the database first, then import all 22 tables into it:

```bash
/Applications/XAMPP/xamppfiles/bin/mysql -u root -p \
  -e "CREATE DATABASE IF NOT EXISTS umsad_tech_elearning CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
/Applications/XAMPP/xamppfiles/bin/mysql -u root -p umsad_tech_elearning \
  < database/schema.sql
```

Press Enter if the local root password is blank. You can instead create/select the database in phpMyAdmin and import [`database/schema.sql`](database/schema.sql). Shared-hosting users must select the provider-assigned database and use its exact name in `.env`.

For a database created with an older schema, back it up and apply these structural migrations in order **before uploading the new application code**:

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

Fresh schema imports already include these fields, indexes, reset tokens, and the notification outbox. The email migration marks only pre-existing accounts verified when it first adds the column; re-running it never verifies subsequently registered users.

To add Website Development for Beginners to an existing populated database, first confirm that at least one active instructor exists, then apply its idempotent data migration:

```bash
/Applications/XAMPP/xamppfiles/bin/mysql -u root -p umsad_tech_elearning \
  -e "SELECT id, email FROM users WHERE user_type='instructor' AND is_active=1 ORDER BY id LIMIT 1"
/Applications/XAMPP/xamppfiles/bin/mysql -u root -p umsad_tech_elearning \
  < database/migrations/20260822_website_development_course.sql
```

The migration retains the owner of an existing matching course; on first creation it assigns the earliest active instructor. It inserts the ₦50,000 published course, four weekly modules, and twelve lessons, while preserving later operational edits when re-run. The optional fresh-data seeder below already includes this course.

## 4. Add optional sample data

```bash
/Applications/XAMPP/xamppfiles/bin/php seed-data.php
```

The seeder:

- runs only from the command line;
- refuses `APP_ENV=production`;
- runs only when the `users` table is empty;
- generates and prints strong admin, instructor, and student passwords once.
- explicitly marks its development accounts as email verified.
- creates five published courses, 12 modules, and 21 lessons, including Website Development for Beginners.

Save the printed credentials securely. Do not use them for a public deployment. To choose repeatable local passwords, set `SEED_ADMIN_PASSWORD`, `SEED_INSTRUCTOR_PASSWORD`, and `SEED_STUDENT_PASSWORD` in `.env` to values of at least 12 characters before the first run.

## 5. Open the site

Visit [http://localhost/umsadtech](http://localhost/umsadtech).

Useful routes:

| Page | URL |
| --- | --- |
| Browse courses | `/courses.php` |
| Student dashboard | `/student/dashboard.php` |
| Student course library | `/student/my-courses.php` |
| Instructor dashboard | `/instructor/dashboard.php` |
| Instructor courses / builder | `/instructor/courses.php` and `/instructor/course-edit.php` |
| Instructor assessments / grading | `/instructor/assessments.php` and `/instructor/submissions.php` |
| Admin dashboard | `/admin/dashboard.php` |
| Admin operations | `/admin/users.php`, `/admin/courses.php`, `/admin/engagement.php`, `/admin/payments.php` |
| Profile / security | `/profile.php` and `/settings.php` |
| Verify / resend email | `/verify-email.php` and `/resend-verification.php` |
| Password recovery | `/forgot-password.php` and `/reset-password.php` |

Public registration creates students only. Instructors can author courses, lessons, resources, quizzes, and assignments and grade submissions. Administrators can provision accounts, manage course publication/ownership, moderate engagement, and export payment records.

## Signup and transactional email

Install production dependencies, or include the generated `vendor/` directory in the upload when the host cannot run Composer:

```bash
composer install --no-dev --optimize-autoloader
```

Configure authenticated SMTP in `.env` using the canonical `MAIL_ENABLED`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, and `MAIL_FROM_NAME` values from `.env.example`. Use `tls` on 587/2525 or `smtps` on 465. Configure SPF, DKIM, and DMARC for the From domain, then test signup, verification/resend, password recovery, expiry, and rate limits with a real mailbox.

In addition to verification messages, the application queues three branded transactional emails: welcome after signup, course confirmation after a new enrollment, and payment confirmation after the first successful paid-course fulfillment. Delivery is attempted after the business transaction commits; unique event keys prevent intentional duplicate queue records, and SMTP failure leaves the completed signup, enrollment, or payment intact.

Process due retries from the project directory:

```bash
/Applications/XAMPP/xamppfiles/bin/php bin/process-notification-outbox.php --limit=100
```

The default batch is 50 and `--limit` accepts 1–500. On a deployed site, run the worker every minute as a service account that can read the application and `.env`, and capture its output. For example, add this with `crontab -e`:

```cron
* * * * * cd /Applications/XAMPP/xamppfiles/htdocs/umsadtech && /Applications/XAMPP/xamppfiles/bin/php bin/process-notification-outbox.php --limit=100 2>&1 | /usr/bin/logger -t umsad-notifications
```

The worker leaves messages pending and exits nonzero when SMTP is not completely configured. Monitor its exit status, protected server logs, and `notification_outbox`; transient delivery errors are retried with increasing delays.

## Paystack hosted checkout

For paid-course testing, paste a matching Paystack test public/secret pair into the blank `PAYSTACK_PUBLIC_KEY` and `PAYSTACK_SECRET_KEY` entries in `.env`. Mixed test/live credentials disable checkout. Never commit or print the credentials.

Sign in as a student and open a paid course. PHP calls Paystack `/transaction/initialize`, then the browser redirects to the validated `https://checkout.paystack.com` URL. Paystack returns to `/payment-callback.php`, where the signed-in learner's transaction is verified server-to-server before course access is granted.

On a publicly reachable test deployment, configure Paystack's webhook URL as `https://your-app.example/api/payments/webhook.php`. It validates the `X-Paystack-Signature` HMAC-SHA512 signature, re-verifies the reference with Paystack, and fulfills confirmed `charge.success` events through the same idempotent logic even when the learner does not return to the callback.

The implemented JSON routes are:

```text
POST /api/enrollments/create.php
POST /api/payments/initialize.php
POST /api/payments/verify.php
POST /api/payments/webhook.php
```

The first three require an authenticated student session, JSON, and a CSRF token. The webhook has no browser session or CSRF token; it requires Paystack's valid signature over the raw event body.

Live checkout requires a matching live credential pair plus all three settings:

```dotenv
APP_ENV=production
APP_URL=https://your-app.example
SESSION_COOKIE_SECURE=true
```

The application refuses live checkout over HTTP or with development/insecure-cookie settings. Apply the payment migration, configure the public HTTPS webhook URL, and test callback plus webhook fulfillment in the deployment environment.

## Fast troubleshooting

- **Database error:** start MySQL, verify `.env`, and confirm `database/schema.sql` was imported.
- **Wrong redirects/404:** make `APP_URL` exactly match the browser URL and project folder.
- **Login expires:** clear cookies and do not enable secure cookies on plain HTTP.
- **CSRF error:** reload the page and keep the same host, port, and scheme.
- **Payment unavailable:** verify a matching credential pair, PHP cURL, outbound HTTPS, and the payment migration.
- **Live checkout disabled:** use `APP_ENV=production`, an HTTPS `APP_URL`, and `SESSION_COOKIE_SECURE=true`.
- **Paid but not enrolled:** inspect the callback response and confirm Paystack can reach the signed webhook endpoint.
- **Welcome/enrollment/payment email missing:** apply the outbox migration, verify SMTP, run the notification worker, and inspect `notification_outbox` plus the protected PHP error log.
- **Missing fonts/images:** Bootstrap and Font Awesome are local; check access to Google Fonts and remote placeholder images.
- **Server error:** inspect `/Applications/XAMPP/xamppfiles/logs/php_error_log`.

Check PHP syntax at any time:

```bash
find . -type f -name '*.php' -print0 \
  | xargs -0 -n1 /Applications/XAMPP/xamppfiles/bin/php -l
```

Before a public launch, run the migration, deployment, backup/restore, mail-delivery, Paystack, accessibility, and security checks in [`README.md`](README.md) and [`SETUP.md`](SETUP.md).
