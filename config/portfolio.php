<?php

/*
|--------------------------------------------------------------------------
| Portfolio content
|--------------------------------------------------------------------------
|
| Everything rendered on /me lives here, so content updates never require
| touching the Blade templates.
|
*/

return [

    'name' => 'Izwan Salim',
    'full_name' => 'Mohammad Izwan Bin Abdul Salim',
    'first_name' => 'Izwan',
    'initials' => 'IS',
    'role' => 'Full Stack Developer',
    'tagline' => 'Building web products from CMS to deployment.',
    'location' => 'Cheras, Kuala Lumpur',

    'typewriter' => [
        'Crafting Laravel Systems',
        'Building RESTful APIs',
        'Integrating Payment Gateways',
        'Shipping with the TALL Stack',
        'Pairing with AI Agents',
    ],

    'about' => [
        'heading' => 'Code to Production',
        'paragraphs' => [
            'Full Stack Developer with <span class="hl-violet">3+ years</span> of experience building and maintaining web applications with Laravel and the TALL stack.',
            'Day to day that means custom <span class="hl-blue">CMS platforms</span>, RESTful APIs, payment integrations, deployments, and the server work that keeps client websites stable and fast.',
            'Lately I build alongside <span class="hl-blue">AI coding assistants</span> like Claude Code and Codex, and MCP-based Figma workflows that turn designs into working code faster.',
        ],
        'quote' => ['highlight' => 'Ship it', 'rest' => ', then make it better.'],
        'nodes' => [
            ['label' => 'Build', 'x' => 50, 'y' => 46, 'size' => 'lg'],
            ['label' => 'Integrate', 'x' => 22, 'y' => 12, 'size' => 'sm'],
            ['label' => 'Deploy', 'x' => 86, 'y' => 44, 'size' => 'md'],
            ['label' => 'Maintain', 'x' => 74, 'y' => 84, 'size' => 'sm'],
            ['label' => 'Learn', 'x' => 34, 'y' => 88, 'size' => 'sm'],
        ],
    ],

    // Three marquee rows on the toolkit section; icons live in public/images/skills.
    'skills' => [
        [
            ['name' => 'Laravel', 'icon' => 'laravel'],
            ['name' => 'Livewire', 'icon' => 'livewire'],
            ['name' => 'Alpine.js', 'icon' => 'alpinejs'],
            ['name' => 'Tailwind CSS', 'icon' => 'tailwindcss'],
            ['name' => 'Filament', 'icon' => 'filament'],
            ['name' => 'PHP', 'icon' => 'php'],
            ['name' => 'JavaScript', 'icon' => 'javascript'],
            ['name' => 'MySQL', 'icon' => 'mysql'],
        ],
        [
            ['name' => 'HTML', 'icon' => 'html5'],
            ['name' => 'CSS', 'icon' => 'css3'],
            ['name' => 'Bootstrap', 'icon' => 'bootstrap'],
            ['name' => 'Java', 'icon' => 'java'],
            ['name' => 'Dart', 'icon' => 'dart'],
            ['name' => 'Flutter', 'icon' => 'flutter'],
            ['name' => 'Stripe', 'icon' => 'stripe'],
            ['name' => 'Postman', 'icon' => 'postman'],
        ],
        [
            ['name' => 'Claude Code', 'icon' => 'claude'],
            ['name' => 'OpenAI Codex', 'icon' => 'openai'],
            ['name' => 'Figma', 'icon' => 'figma'],
            ['name' => 'Git', 'icon' => 'git'],
            ['name' => 'Bitbucket', 'icon' => 'bitbucket'],
            ['name' => 'Linux', 'icon' => 'linux'],
            ['name' => 'cPanel', 'icon' => 'cpanel'],
            ['name' => 'phpMyAdmin', 'icon' => 'phpmyadmin'],
            ['name' => 'Jira', 'icon' => 'jira'],
        ],
    ],

    'projects' => [
        [
            'slug' => 'moodcrave',
            'file' => 'moodcrave.blade.php',
            'category' => 'AI Experience · IPC Mall',
            'title' => 'MoodCrave',
            'summary' => 'An interactive food recommendation website that reads your mood and suggests what to eat.',
            'points' => [
                'Uses an external facial-expression recognition plugin to read a visitor\'s mood in real time.',
                'Pairs the detected mood with AI-powered food suggestions to create a playful food discovery experience for mall visitors.',
            ],
            'tags' => ['Laravel', 'JavaScript', 'Facial Recognition', 'AI'],
        ],
        [
            'slug' => 'job-portal',
            'file' => 'job-portal.php',
            'category' => 'Web Platform · MySyarikat',
            'title' => 'Job Portal',
            'summary' => 'Job portal features built from scratch, with employer tools and token-based payments.',
            'points' => [
                'Built employer tools for publishing vacancies and reviewing applicants with Laravel and Livewire.',
                'Integrated CHIP-IN and Stripe so employers can purchase tokens for additional job postings.',
            ],
            'tags' => ['Laravel', 'Livewire', 'CHIP-IN', 'Stripe'],
        ],
        [
            'slug' => 'hellopal',
            'file' => 'hellopal-crm.php',
            'category' => 'CRM System · HelloPal',
            'title' => 'HelloPal CRM',
            'summary' => 'A CRM with a dynamic form builder, shareable forms, and scheduled WhatsApp broadcasts.',
            'points' => [
                'Developed a dynamic form builder for creating forms and managing submissions with the TALL stack and Filament PHP.',
                'Made forms shareable through URLs and QR codes.',
                'Integrated a third-party WhatsApp API for scheduled broadcast messaging.',
            ],
            'tags' => ['TALL Stack', 'Filament', 'WhatsApp API', 'QR Codes'],
        ],
        [
            'slug' => 'hrms',
            'file' => 'hrms.php',
            'category' => 'HR Platform · MySyarikat',
            'title' => 'HRMS',
            'summary' => 'The GajiNow! advance salary module and mobile sync APIs, as part of a Yii2 to Laravel migration.',
            'points' => [
                'Developed the GajiNow! advance salary module with Laravel, Livewire, and the TALL stack.',
                'Built RESTful APIs to sync data between the web platform and mobile apps, tested with Postman.',
                'Resolved bugs across attendance, leave, PDF generation, employee, overtime, and time-off modules.',
            ],
            'tags' => ['Laravel', 'Livewire', 'REST API', 'Yii2 Migration'],
        ],
    ],

    // Rendered top to bottom on the timeline (oldest first), alternating sides.
    'experience' => [
        [
            'slug' => 'um',
            'file' => 'universiti-malaya.php',
            'type' => 'education',
            'company' => 'Universiti Malaya',
            'role' => 'B.CS (Software Engineering)',
            'period' => '2017 — 2022',
            'headline' => 'The foundation for everything that came after.',
            'body' => 'Bachelor of Computer Science majoring in Software Engineering at Universiti Malaya.',
            'tags' => [],
        ],
        [
            'slug' => 'brunsfield',
            'file' => 'brunsfield.php',
            'type' => 'work',
            'company' => 'Brunsfield Computer System',
            'role' => 'Software Engineering Intern',
            'period' => 'Aug 2022 — Jan 2023',
            'headline' => 'Where the full software development life cycle clicked.',
            'body' => 'Developed and maintained internal company systems in JSP and Java, contributed across design, development, and testing, worked with clients to improve existing projects, and optimised SQL queries for faster, smoother workflows.',
            'tags' => ['Java', 'JSP', 'SQL'],
        ],
        [
            'slug' => 'byond',
            'file' => 'byond-tech.php',
            'type' => 'work',
            'company' => 'Byond Tech Global Berhad',
            'role' => 'Software Developer',
            'period' => 'Feb 2023 — Jun 2025',
            'headline' => 'Shipped TALL stack products with payments, subscriptions, and secure APIs.',
            'body' => 'Developed and maintained web applications with Tailwind CSS, Alpine.js, Livewire, and Laravel. Implemented payment and subscription features for a job portal with CHIP-IN and Stripe, built RESTful APIs secured with personal access tokens, and validated them with Postman.',
            'tags' => ['TALL Stack', 'Stripe', 'CHIP-IN', 'REST API', 'Postman'],
        ],
        [
            'slug' => 'loki',
            'file' => 'loki-digital.php',
            'type' => 'work',
            'company' => 'LOKi Digital Sdn Bhd',
            'role' => 'Full Stack Developer',
            'period' => 'Jul 2025 — Present',
            'headline' => 'Building and running client websites for an advertising agency, from custom CMS to server.',
            'body' => 'Develop and maintain client sites including Elaeis Gardens, DeGem Diamond, Diamond N Platinum, and Wilayah Credit. Design custom Laravel CMS platforms, manage deployments and server configuration, integrate third-party APIs, build responsive HTML email campaigns, and ship MoodCrave for IPC Mall using AI-assisted and MCP-based Figma workflows.',
            'tags' => ['Laravel', 'Custom CMS', 'WHM / cPanel', 'EDM', 'Claude Code', 'Figma MCP'],
        ],
    ],

    'contact' => [
        'heading' => "What's Next?",
        'blurb' => 'Building Laravel products, CMS platforms, and AI-assisted web experiences.',
        'links' => [
            ['label' => 'LinkedIn', 'icon' => 'linkedin', 'href' => 'https://www.linkedin.com/in/izwansalim98'],
            ['label' => 'Email', 'icon' => 'mail', 'href' => 'mailto:mohdizwansalim@gmail.com'],
            ['label' => 'Phone', 'icon' => 'phone', 'href' => 'tel:+60137077488'],
        ],
    ],

];
