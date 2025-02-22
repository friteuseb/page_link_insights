<?php

$EM_CONF['page_link_insights'] = [
    'title' => 'Page Link Insights',
    'description' => 'A TYPO3 extension that adds a backend module with a D3.js force diagram visualization for understanding page relationships.',
    'category' => 'module',
    'state' => 'stable',
    'clearCacheOnLoad' => true,
    'author' => 'Cyril Wolfangel',
    'author_email' => 'cyril.wolfangel@gmail.com',
    'version' => '1.0.3',
    'constraints' => [
        'depends' => [
            'typo3' => '13.0.0-13.99.99',
        ],
    ],
];