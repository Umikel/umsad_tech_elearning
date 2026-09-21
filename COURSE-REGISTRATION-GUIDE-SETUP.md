# Course registration guide update

The Website Development for Beginners course now offers a **Read the course guide** button, including before sign-in. Student registration opens the guide and requires an explicit checkbox acknowledgment before free enrollment or paid checkout. Both APIs reject missing or outdated acknowledgments. Other courses retain their existing flow. Learning access still requires admin approval.

## Upload

1. Back up the application files and database.
2. Select the application database in phpMyAdmin and import `database/migrations/20260913_course_registration_guide.sql` before uploading the PHP changes. This creates the acknowledgment table without changing existing enrollments. Fresh installations already include the table in `database/schema.sql`.
3. Extract `course-registration-guide-update.zip` in the application folder, preserving paths. The package contains the course page, guide helper/template/content, two API routes, migration and this setup note. It assumes the prior enrollment approval update is installed.
4. Refresh the course page and check the guide on desktop and mobile. Preview and close it while signed out. As a student, cancel registration, then retry and acknowledge it before continuing. Confirm successful registration remains awaiting admin approval.

Acknowledgments store student, course, acceptance time and a SHA-256 version of the guide JSON. Editing `assets/data/website-development-registration-guide.json` changes that version, so students with an older open page must refresh before registering. Paid-checkout acknowledgment records acceptance before checkout; it is not proof of payment or enrollment.

The supplied guide states a ₦30,000 fee; the page separately displays the database price and explicitly highlights it in the guide. This update does not alter course prices. The supplied text also describes approximately two-hour classes while its weekday timetable allocates 90 minutes. Confirm these source details before publishing the course information.

## Validation

Passed PHP syntax checks, PHP acknowledgment validation tests and JavaScript interaction tests covering preview, cancel, checkbox enforcement, free/paid payloads, duplicate submission and other courses. Existing enrollment-access and payment-callback regression tests also passed. Tests use isolated data and mocked browser elements; live MySQL, real checkout and visual browser verification have not been performed for this update.

Commands:

```sh
php tests/course-registration-guide.php
node tests/course-registration-guide.cjs
python3 tests/enrollment-access.py
node tests/payment-callback.cjs
```
