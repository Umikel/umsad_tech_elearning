<?php
function hasSundayPhysicalClass(array $course): bool
{
    $slug = strtolower(trim((string) ($course['slug'] ?? '')));
    $title = strtolower(trim((string) ($course['title'] ?? '')));
    return $slug === 'website-development-for-beginners'
        || in_array($title, ['website development for beginners', 'one-month practical html course'], true);
}

/** Plain-text source document and versioned enrollment acknowledgment. */
function courseRegistrationGuide(array $course): ?array
{
    if (!hasSundayPhysicalClass($course)) return null;
    $path = dirname(__DIR__) . '/assets/data/website-development-registration-guide.json';
    $source = file_get_contents($path);
    if ($source === false) throw new RuntimeException('Course registration guide is unavailable.');
    $blocks = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
    return ['version' => hash('sha256', $source), 'blocks' => $blocks];
}

function validCourseGuideAcknowledgment(?array $guide, array $data): bool
{
    return $guide === null || (($data['guide_accepted'] ?? null) === true
        && is_string($data['guide_version'] ?? null)
        && hash_equals($guide['version'], $data['guide_version']));
}

function recordCourseGuideAcknowledgment(Database $db, int $studentId, int $courseId, array $guide): void
{
    $db->query('INSERT INTO course_registration_acknowledgments (student_id, course_id, guide_version, accepted_at)
                VALUES (:student_id, :course_id, :guide_version, NOW())
                ON DUPLICATE KEY UPDATE id = id');
    $db->bind(':student_id', $studentId);
    $db->bind(':course_id', $courseId);
    $db->bind(':guide_version', $guide['version']);
    $db->execute();
}

/** Resolve a plan on the server; never trust a price supplied by the browser. */
function coursePlanAmountMinor(array $course, $plan, ?int $onlineAmount): ?int
{
    if ($plan === 'online') return $onlineAmount;
    if ($plan === 'sunday_physical' && hasSundayPhysicalClass($course)) return 5000000;
    throw new InvalidArgumentException('Invalid class option.');
}
