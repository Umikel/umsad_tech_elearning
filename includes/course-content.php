<?php
declare(strict_types=1);

/**
 * Structured editorial content for courses that need more detail than the
 * core courses/modules/lessons tables currently provide.
 *
 * Values in this catalog are plain text. Renderers must continue to escape
 * each value before placing it in HTML; no entry is intended to be trusted as
 * markup.
 *
 * @return array<string, array<string, mixed>>
 */
if (!function_exists('umsadCourseContentCatalog')) {
    function umsadCourseContentCatalog(): array
    {
        static $catalog = [
            'website-development-for-beginners' => [
                'title' => 'Website Development for Beginners',
                'summary' => 'Learn how to plan, build and publish responsive websites through practical, instructor-led online classes.',
                'prerequisite' => 'No previous coding experience is required.',
                'cta_label' => 'Apply Now',
                'announcement' => [
                    'eyebrow' => 'Registration is open',
                    'title' => 'Website Development for Beginners',
                    'description' => 'Start from the fundamentals and build a complete website in four weeks of live online training.',
                    'highlights' => [
                        'Beginner friendly',
                        'Three live classes every week',
                        'English or Hausa',
                    ],
                ],
                'facts' => [
                    ['key' => 'registration_status', 'label' => 'Registration Status', 'value' => 'Open'],
                    ['key' => 'starting_date', 'label' => 'Starting Date', 'value' => 'To be announced'],
                    ['key' => 'closing_date', 'label' => 'Closing Date', 'value' => 'To be announced'],
                    ['key' => 'class_days', 'label' => 'Class Days', 'value' => 'To be announced'],
                    ['key' => 'class_time', 'label' => 'Class Time', 'value' => 'To be announced'],
                    ['key' => 'duration', 'label' => 'Duration', 'value' => 'Four Weeks'],
                    ['key' => 'classes', 'label' => 'Classes', 'value' => 'Three live classes every week'],
                    ['key' => 'mode', 'label' => 'Mode', 'value' => 'Live Online Training'],
                    ['key' => 'language', 'label' => 'Language', 'value' => 'English or Hausa — your choice'],
                    ['key' => 'course_fee', 'label' => 'Course Fee', 'value' => ''],
                    ['key' => 'level', 'label' => 'Level', 'value' => 'Beginner'],
                    ['key' => 'required_device', 'label' => 'Required Device', 'value' => 'Laptop computer'],
                ],
                'sections' => [
                    'about_the_course' => [
                        'title' => 'About the Course',
                        'paragraphs' => [
                            'This four-week course introduces website development from the ground up. You will learn how web pages work, write clean HTML, style responsive layouts with CSS and add useful interactions with JavaScript.',
                            'Each live class combines a clear explanation, an instructor demonstration and guided practice. By the end of the course, you will have planned, built, tested and published a complete beginner portfolio website.',
                        ],
                        'items' => [],
                        'list_style' => 'bullets',
                    ],
                    'who_can_apply' => [
                        'title' => 'Who Can Apply?',
                        'paragraphs' => [
                            'The course is designed for complete beginners who are ready to learn consistently and practise between classes.',
                        ],
                        'items' => [
                            'Students and recent graduates who want a practical digital skill.',
                            'Entrepreneurs and small-business owners who want to understand or improve their websites.',
                            'Career changers exploring a path into technology.',
                            'Designers, content creators and other professionals who want to build web pages themselves.',
                            'Anyone with a laptop, reliable internet access and the commitment to attend live classes.',
                        ],
                        'list_style' => 'bullets',
                    ],
                    'what_you_will_learn' => [
                        'title' => 'What You Will Learn',
                        'paragraphs' => [
                            'You will move from basic web concepts to a finished project through focused, practical lessons.',
                        ],
                        'items' => [
                            'How websites, browsers, domain names and web hosting work together.',
                            'How to structure accessible pages with semantic HTML.',
                            'How to style typography, colour, spacing and components with CSS.',
                            'How to create flexible layouts with Flexbox and CSS Grid.',
                            'How to make pages adapt cleanly to phones, tablets and computers.',
                            'How to use JavaScript, the DOM and browser events for interaction.',
                            'How to create and validate a practical contact form interface.',
                            'How to test, debug, publish and present a complete website.',
                        ],
                        'list_style' => 'bullets',
                    ],
                    'four_week_course_schedule' => [
                        'title' => 'Four-Week Course Schedule',
                        'paragraphs' => [
                            'The programme includes three live classes each week. Every week builds directly on the work completed in the previous week.',
                        ],
                        'items' => [
                            'Week 1 — Web Foundations and HTML: understand how websites work, prepare your coding tools, build a first page and use semantic HTML.',
                            'Week 2 — CSS and Responsive Layouts: style pages, create layouts with Flexbox and Grid, and adapt the design for different screen sizes.',
                            'Week 3 — JavaScript and User Interaction: learn JavaScript fundamentals, work with the DOM and events, and validate interactive forms.',
                            'Week 4 — Final Website Project: plan, build, refine, test, publish and present a complete responsive website.',
                        ],
                        'list_style' => 'steps',
                    ],
                    'how_every_live_class_will_work' => [
                        'title' => 'How Every Live Class Will Work',
                        'paragraphs' => [
                            'Live classes are structured so that you understand each idea and apply it before moving on.',
                        ],
                        'items' => [
                            'A short recap and review of questions from the previous class.',
                            'A clear explanation of the day’s topic and why it matters.',
                            'A live instructor demonstration with step-by-step coding.',
                            'Guided practice while the instructor is available to correct mistakes.',
                            'Questions, answers and a practical task to complete before the next class.',
                        ],
                        'list_style' => 'steps',
                    ],
                    'course_assessment' => [
                        'title' => 'Course Assessment',
                        'paragraphs' => [
                            'The course will use practical activities to help you apply what you learn. The final assessment format, submission dates and marking criteria will be confirmed before classes begin.',
                        ],
                        'items' => [
                            'Practice activities may be completed during live classes.',
                            'Any weekly tasks and deadlines will be shared by the instructor.',
                            'Any final project requirements will be provided in the official course brief.',
                        ],
                        'list_style' => 'bullets',
                    ],
                    'applications_and_software_required' => [
                        'title' => 'Applications and Software Required',
                        'paragraphs' => [
                            'The final software list and installation guidance will be provided before the first class. Learners will not be expected to buy development software unless this is communicated in advance.',
                        ],
                        'items' => [
                            'A current web browser.',
                            'A code editor for writing and organising project files.',
                            'The online meeting application specified in your class invitation.',
                            'A working email address for class notices, files and support.',
                        ],
                        'list_style' => 'bullets',
                    ],
                    'online_class_instructions' => [
                        'title' => 'Online Class Instructions',
                        'paragraphs' => [
                            'Class access details and final preparation instructions will be sent to enrolled learners before training begins.',
                        ],
                        'items' => [
                            'Use a charged laptop and a reliable internet connection.',
                            'Install and test the meeting application named in your class invitation.',
                            'Join through the private class link sent to your registered contact details.',
                            'Contact support before class if you cannot access the meeting or course materials.',
                        ],
                        'list_style' => 'bullets',
                    ],
                    'student_rules_and_regulations' => [
                        'title' => 'Student Rules and Regulations',
                        'paragraphs' => [
                            'The official student rules, including any attendance, submission and recording policies, will be provided before the programme begins.',
                        ],
                        'items' => [
                            'Treat instructors and fellow students with respect in class and support channels.',
                            'Keep login details and private class links confidential.',
                            'Follow the confirmed classroom and communication guidelines shared with your cohort.',
                        ],
                        'list_style' => 'bullets',
                    ],
                    'certificate_requirements' => [
                        'title' => 'Certificate Requirements',
                        'paragraphs' => [
                            'Certificate eligibility criteria are being finalised. The exact attendance, assessment, project and payment requirements will be communicated in writing before training begins.',
                        ],
                        'items' => [
                            'Review the published certificate criteria when they are issued.',
                            'Complete the confirmed requirements within the stated course period.',
                        ],
                        'list_style' => 'bullets',
                    ],
                    'support_and_communication' => [
                        'title' => 'Support and Communication',
                        'paragraphs' => [
                            'Important announcements, class links and learning resources will be shared through the contact details and support channel provided after registration.',
                            'For account, payment or course-access help, contact support@umsadtech.com. Learning questions can be raised during class or in the designated class support channel.',
                        ],
                        'items' => [],
                        'list_style' => 'bullets',
                    ],
                    'how_to_apply' => [
                        'title' => 'How to Apply',
                        'paragraphs' => [
                            'Complete the steps below to reserve a place in the next available class.',
                        ],
                        'items' => [
                            'Select Apply Now and create a learner account, or sign in if you already have one.',
                            'Confirm your account details and return to this course page.',
                            'Complete the secure course payment through Paystack.',
                            'Wait for the on-screen confirmation and enrollment message.',
                            'Check your email for the welcome message and further class instructions.',
                        ],
                        'list_style' => 'steps',
                    ],
                ],
            ],
        ];

        return $catalog;
    }
}

/**
 * Return structured editorial content for a course slug, when available.
 *
 * @return array<string, mixed>|null
 */
if (!function_exists('umsadCourseContentForSlug')) {
    function umsadCourseContentForSlug(string $slug): ?array
    {
        $normalizedSlug = strtolower(trim($slug));
        if ($normalizedSlug === '') {
            return null;
        }

        $catalog = umsadCourseContentCatalog();
        return $catalog[$normalizedSlug] ?? null;
    }
}
