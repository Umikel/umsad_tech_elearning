-- Provision the Website Development for Beginners course for an existing
-- Umsad Tech database. The migration is safe to run repeatedly: the course is
-- keyed by its unique slug, modules by course/sequence, and lessons by the
-- selected module/sequence.
--
-- An active instructor account must exist. A course that already exists keeps
-- its current owner. On first creation the earliest active instructor is used;
-- review that assignment after import when a deployment has several instructors.
-- The migration inserts missing records but deliberately preserves later
-- operational edits when it is run again.

SET @umsad_website_course_slug = 'website-development-for-beginners';
SET @umsad_website_course_instructor_id = (
    SELECT selected_instructor.id
    FROM (
        SELECT c.instructor_id AS id, 0 AS priority
        FROM courses AS c
        WHERE c.slug = @umsad_website_course_slug

        UNION ALL

        SELECT u.id, 1 AS priority
        FROM users AS u
        WHERE u.user_type = 'instructor'
          AND u.is_active = 1
    ) AS selected_instructor
    ORDER BY selected_instructor.priority ASC, selected_instructor.id ASC
    LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS umsad_website_course_module_seed;
CREATE TEMPORARY TABLE umsad_website_course_module_seed (
    module_sequence TINYINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    PRIMARY KEY (module_sequence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO umsad_website_course_module_seed (module_sequence, title, description) VALUES
    (1, 'Week 1: Web Foundations and HTML', 'Understand how websites work, prepare the development environment and build accessible pages with semantic HTML.'),
    (2, 'Week 2: CSS and Responsive Layouts', 'Style web pages, create modern layouts with Flexbox and Grid, and adapt designs for phones, tablets and computers.'),
    (3, 'Week 3: JavaScript and User Interaction', 'Learn JavaScript fundamentals, work with the DOM and browser events, and build interactive form behaviour.'),
    (4, 'Week 4: Final Website Project', 'Plan, build, refine, test, publish and present a complete responsive website.');

DROP TEMPORARY TABLE IF EXISTS umsad_website_course_lesson_seed;
CREATE TEMPORARY TABLE umsad_website_course_lesson_seed (
    module_sequence TINYINT UNSIGNED NOT NULL,
    lesson_sequence TINYINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    content TEXT NOT NULL,
    PRIMARY KEY (module_sequence, lesson_sequence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO umsad_website_course_lesson_seed (
    module_sequence,
    lesson_sequence,
    title,
    description,
    content
) VALUES
    (
        1,
        1,
        'How Websites Work and Course Setup',
        'Learn how browsers, servers, domain names and hosting work together, then prepare the tools used throughout the course.',
        'Identify the main parts of a website request, organise a project folder, install Visual Studio Code and confirm that the browser can open a local HTML file.'
    ),
    (
        1,
        2,
        'Build Your First HTML Page',
        'Create a complete HTML document and use common elements for headings, text, links, images and lists.',
        'Build a valid HTML page from an empty file, add meaningful page content and practise using relative file paths for links and images.'
    ),
    (
        1,
        3,
        'Semantic HTML and Accessible Structure',
        'Organise content with semantic HTML and apply beginner accessibility practices.',
        'Use header, nav, main, section and footer elements appropriately; add useful alternative text; and structure a form with connected labels.'
    ),
    (
        2,
        1,
        'Style Pages with CSS',
        'Connect a stylesheet and control typography, colour, spacing, borders and reusable visual components.',
        'Practise selectors, the cascade and the box model while turning the Week 1 HTML page into a clear, consistent visual design.'
    ),
    (
        2,
        2,
        'Build Layouts with Flexbox and Grid',
        'Create practical one-dimensional and two-dimensional page layouts with modern CSS.',
        'Use Flexbox for navigation and aligned components, use Grid for page sections and card layouts, and choose the appropriate layout tool for each task.'
    ),
    (
        2,
        3,
        'Make Websites Responsive',
        'Adapt layouts and content to work comfortably across phones, tablets and desktop screens.',
        'Apply a mobile-first approach, flexible sizing, responsive images and focused media queries, then test the page at several viewport widths.'
    ),
    (
        3,
        1,
        'JavaScript Fundamentals',
        'Use variables, values, conditions, functions and arrays to solve small browser-based tasks.',
        'Write and run JavaScript in the browser, inspect results with developer tools and break a simple requirement into reusable functions.'
    ),
    (
        3,
        2,
        'Work with the DOM and Events',
        'Select page elements, respond to user actions and update content safely with JavaScript.',
        'Use DOM query methods and event listeners to create menu, button and content interactions without relying on a JavaScript framework.'
    ),
    (
        3,
        3,
        'Build and Validate Interactive Forms',
        'Create helpful client-side form behaviour and clear validation feedback.',
        'Read user input, prevent invalid submission, display understandable messages and preserve accessible labels and focus behaviour.'
    ),
    (
        4,
        1,
        'Plan and Structure the Final Website',
        'Turn a simple project brief into a page plan, content outline and realistic build checklist.',
        'Define the website goal and audience, sketch the required sections, organise assets and create the semantic HTML foundation for the final project.'
    ),
    (
        4,
        2,
        'Build and Refine the Final Website',
        'Combine HTML, responsive CSS and useful JavaScript interactions in one complete project.',
        'Develop the planned sections, apply a consistent design system, add the required interactions and improve the project through instructor feedback.'
    ),
    (
        4,
        3,
        'Test, Publish and Present Your Website',
        'Review quality across browsers and screen sizes, publish the project and explain the completed work.',
        'Check links, forms, responsive behaviour and accessibility basics; resolve visible defects; publish the project; and present the final result and learning decisions.'
    );

DROP TEMPORARY TABLE IF EXISTS umsad_website_course_module_ids;
CREATE TEMPORARY TABLE umsad_website_course_module_ids (
    module_sequence TINYINT UNSIGNED NOT NULL,
    module_id INT NOT NULL,
    PRIMARY KEY (module_sequence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;

INSERT INTO courses (
    title,
    slug,
    description,
    instructor_id,
    category,
    price,
    discount_price,
    is_published
) VALUES (
    'Website Development for Beginners',
    @umsad_website_course_slug,
    'Learn to plan, build and publish responsive websites from scratch through four weeks of live, practical instruction. No previous coding experience is required.',
    @umsad_website_course_instructor_id,
    'Web Development',
    50000.00,
    NULL,
    1
)
ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id);

SET @umsad_website_course_id = (
    SELECT id
    FROM courses
    WHERE slug = @umsad_website_course_slug
    LIMIT 1
);

INSERT INTO course_modules (course_id, title, description, sequence)
SELECT
    @umsad_website_course_id,
    seed.title,
    seed.description,
    seed.module_sequence
FROM umsad_website_course_module_seed AS seed
WHERE NOT EXISTS (
    SELECT 1
    FROM course_modules AS existing_module
    WHERE existing_module.course_id = @umsad_website_course_id
      AND existing_module.sequence = seed.module_sequence
);

INSERT INTO umsad_website_course_module_ids (module_sequence, module_id)
SELECT
    seed.module_sequence,
    MIN(module.id) AS module_id
FROM umsad_website_course_module_seed AS seed
INNER JOIN course_modules AS module
    ON module.course_id = @umsad_website_course_id
   AND module.sequence = seed.module_sequence
GROUP BY seed.module_sequence;

INSERT INTO course_lessons (
    module_id,
    course_id,
    title,
    description,
    video_url,
    video_type,
    content,
    duration,
    sequence,
    is_free
)
SELECT
    module_map.module_id,
    @umsad_website_course_id,
    seed.title,
    seed.description,
    NULL,
    'youtube',
    seed.content,
    NULL,
    seed.lesson_sequence,
    0
FROM umsad_website_course_lesson_seed AS seed
INNER JOIN umsad_website_course_module_ids AS module_map
    ON module_map.module_sequence = seed.module_sequence
WHERE NOT EXISTS (
    SELECT 1
    FROM course_lessons AS existing_lesson
    WHERE existing_lesson.course_id = @umsad_website_course_id
      AND existing_lesson.module_id = module_map.module_id
      AND existing_lesson.sequence = seed.lesson_sequence
);

COMMIT;

DROP TEMPORARY TABLE IF EXISTS umsad_website_course_module_ids;
DROP TEMPORARY TABLE IF EXISTS umsad_website_course_lesson_seed;
DROP TEMPORARY TABLE IF EXISTS umsad_website_course_module_seed;
