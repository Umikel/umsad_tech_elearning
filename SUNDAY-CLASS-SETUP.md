# Sunday physical class update

Website Development for Beginners now offers Online + Sunday Physical Class for ₦50,000 total, including online learning. Physical lessons meet Sundays, 10am–11am Nigeria time; venue to be announced. The online-only price remains unchanged.

The option appears for the canonical website-development-for-beginners slug or an exact course title of Website Development for Beginners / One-Month Practical HTML Course (case insensitive). It shares the original course content, progress, and admin approval process. Students acknowledge the selected option before checkout. The server sets the fee; free enrollment rejects physical-class requests. Payment verification copies the stored plan to the enrollment. Admin approvals and My courses display the selected plan.

## Upload to hosting

1. Back up your database and application files.
2. In phpMyAdmin, select your website database. Import database/migrations/20260913_course_registration_guide.sql if not already installed.
3. Import database/migrations/20260913_sunday_physical_class.sql ONCE. It adds learning_plan to payments and student_enrollments. Existing records default to online. Do not rerun it: duplicate-column errors indicate the fields already exist. Fresh installations include these fields in database/schema.sql and should not run this ALTER migration.
4. Upload sunday-class-update.zip to your live elearning folder, extract there and overwrite the included application files. Do not extract into a nested folder. Delete the uploaded ZIP afterward.
5. Refresh course-detail.php for the course. Confirm the Sunday offer, total fee, time and venue notice. Test registration using Paystack test mode in a staging environment; confirm payment produces a Sunday enrollment awaiting approval, then approve it and verify online lessons open. Check online-only registration separately.

Existing enrolled students cannot purchase this option again through checkout. This update is for new registrations; it does not implement an upgrade charge for previously enrolled students. Canceled/failed checkout does not create physical-class enrollment. Existing duplicate-payment handling remains in place; do not pay multiple outstanding checkout sessions for the same course.

## Validation

PHP lint, guide and plan-pricing unit checks, guide interaction/payload tests, existing enrollment-access tests and payment-callback tests passed. Live MySQL migration, real checkout and browser visual verification are still required on staging/hosting. No hosted files or database were changed by preparing this package.
