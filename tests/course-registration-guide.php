<?php
// Run with PHP; no database or external services required.
require_once __DIR__ . '/../includes/course-registration-guide.php';
function checkGuide(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$guide = courseRegistrationGuide(['slug' => 'website-development-for-beginners']);
checkGuide($guide !== null && count($guide['blocks']) > 0, 'Guide must load');
checkGuide(courseRegistrationGuide(['slug' => 'another-course']) === null, 'Other courses must be unaffected');
checkGuide(validCourseGuideAcknowledgment(null, []), 'Other courses need no acknowledgment');
foreach ([[], ['guide_accepted' => true], ['guide_accepted' => 'true', 'guide_version' => $guide['version']], ['guide_accepted' => false, 'guide_version' => $guide['version']], ['guide_accepted' => true, 'guide_version' => 'outdated'], ['guide_accepted' => true, 'guide_version' => []]] as $invalid) {
    checkGuide(!validCourseGuideAcknowledgment($guide, $invalid), 'Missing, invalid or stale consent must be rejected');
}
checkGuide(validCourseGuideAcknowledgment($guide, ['guide_accepted' => true, 'guide_version' => $guide['version']]), 'Current explicit consent must pass');
echo "PASS: guide loading, course scope, explicit consent and stale-version rejection.\n";

$course = ['slug' => 'website-development-for-beginners'];
checkGuide(coursePlanAmountMinor($course, 'sunday_physical', 100000) === 5000000, 'Physical class must charge the full inclusive fee');
checkGuide(coursePlanAmountMinor($course, 'online', 100000) === 100000, 'Online price must remain unchanged');
checkGuide(hasSundayPhysicalClass(['title' => 'Website Development for Beginners', 'slug' => 'custom-slug']), 'Exact title supports a custom slug');
foreach ([['slug' => 'another-course'], []] as $other) {
    try { coursePlanAmountMinor($other, 'sunday_physical', 0); throw new RuntimeException('Unrelated course accepted'); }
    catch (InvalidArgumentException $expected) {}
}
echo "PASS: inclusive Sunday price, unchanged online price and eligible course checks.\n";
