<?php

$EM_CONF['page_link_insights'] = [
    'title' => 'Page Link Insights',
    'description' => 'A TYPO3 extension that adds a backend module with a D3.js force diagram visualization for understanding page relationships.',
    'category' => 'module',
    'state' => 'stable',
    'clearCacheOnLoad' => true,
    'author' => 'Cyril Wolfangel',
    'author_email' => 'cyril.wolfangel@gmail.com',
    'version' => '1.2.1',
    'constraints' => [
        'depends' => [
            'typo3' => '>=12.4.0,<14.0.0',
        ],
    ],
];