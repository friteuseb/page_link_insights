<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Page Link Insights',
    'description' => 'A demo TYPO3 extension that adds a backend module with a D3.js force diagram visualization.',
    'category' => 'module',
    'state' => 'stable',
    'clearCacheOnLoad' => true,
    'author' => 'Cyril Wolfangel',
    'author_email' => 'cyril.wolfangel@gmail.com',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-13.99.99',
        ],
    ],
];