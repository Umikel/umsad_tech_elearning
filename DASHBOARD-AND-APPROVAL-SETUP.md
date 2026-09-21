# Dashboard logo and enrollment approval

## Deploying this update

1. Back up your database.
2. In your hosting control panel, open phpMyAdmin and select the application database. Import `database/migrations/20260912_enrollment_approval.sql` once, before uploading the changed PHP files. New installations use the updated `database/schema.sql` instead.
3. Upload the changed application files and `assets/css/style.css` together.
4. Sign in as admin and open **Enrollments** in the dashboard menu. Click **Approve learning** beside each registration you want to unlock.

All existing and new registrations require approval, including paid courses. Payment confirmation does not approve learning. Students can see their registration in My courses while waiting, but lessons, quizzes, assignments and completion records require approval. Approval is per student and per course.

## Changing the logo later

The shared dashboard logo is in `templates/header.php`, inside `brand-logo-frame--student-app`. Replace `assets/images/umsad-tech-logo.png` to update the shared logo (also used on public pages), or change only that dashboard image source to use a separate dashboard logo. Keep a transparent PNG and preserve the artwork proportions.

The dashboard logo background is controlled by `.brand-logo-frame--student-app` in `assets/css/style.css` (navy `#21213e`). It provides contrast for the existing white and gold lettering across admin, student and instructor dashboards. Change this background to suit a replacement logo. Asset URLs include file modification times for cache refresh.

## Verify after deployment

Register for a free course and confirm My courses shows Awaiting admin approval. Try opening its lesson URL directly; access must be refused. Do the same for quiz, assignment and certificate URLs. Repeat with a paid enrollment. Log in as admin, approve the enrollment, and verify that only that student/course becomes accessible. Check the logo on desktop and mobile in each role.

## Dashboard upgrade — September 13, 2026

The student, admin and instructor overview dashboards now have a shared navy and purple design, desktop sidebar, visible logout control, responsive metric cards, and 7-day / 30-day activity charts with accessible daily totals.

- Students: completed lesson activity, overdue and outstanding assignment priorities, approval notices, and resume-learning links.
- Admins: registration activity, oldest pending enrollment priorities linked to approvals, user/course summaries and recent payments with their recorded currency.
- Instructors: submission activity, oldest ungraded work linked to the grading workspace, course progress and revenue summaries.
- Fixes: repaired the student welcome-card markup, displayed approval success messages, prevented the six-item admin mobile navigation from wrapping into two rows, exposed logout on desktop, and clarified the rolling 30-day learning metric. Revenue summary cards explicitly show NGN totals; payment rows retain their own currency.

### Upload this dashboard update

1. Back up the files listed below on your hosting account.
2. Upload `dashboard-upgrade.zip` to the website's `elearning` folder and extract it there, preserving the folder structure. It contains only the nine dashboard-related application files listed below; no credentials, database exports, or test accounts are included.
3. Refresh a dashboard. Asset URLs are versioned to refresh cached styles. If your host uses a page/CDN cache, clear that cache too.
4. Check each role's dashboard. On desktop, **Log out** is in the top-right header and dashboard sidebar. It opens the existing secure sign-out confirmation page; confirm there to end the session.
5. Switch the activity dropdown between 7 and 30 days, then click **Apply**. Approval and grading links open the existing management screens.

This dashboard update needs no additional database migration. It assumes the existing enrollment-approval migration above is already installed. Do not import that migration again if it has already run.

Files in the update:

- `student/dashboard.php`
- `admin/dashboard.php`
- `instructor/dashboard.php`
- `includes/dashboard-insights.php`
- `templates/dashboard-insights.php`
- `templates/dashboard-sidebar.php`
- `templates/header.php`
- `assets/css/dashboard.css`
- `assets/css/style.css`

### Validation

PHP lint passed for changed PHP files. Isolated SQLite tests exercise the new role-scoped queries, pending-enrollment exclusions, overdue priorities, submitted-work exclusions, period sizes, and empty accounts. Existing enrollment-access and payment-callback regression tests passed. Chrome previews were inspected using isolated fixture data: desktop student dashboard, 390px layouts for all three roles, and the expanding admin mobile menu. These previews do not authenticate against the hosted application. Live hosting/MySQL and real-device testing still need to be checked after upload.
