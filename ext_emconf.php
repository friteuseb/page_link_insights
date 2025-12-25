<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Page Link Insights',
    'description' => 'Visualize internal page links with D3.js force diagrams and thematic clustering for content analysis.',
    'category' => 'module',
    'state' => 'stable',
    'clearCacheOnLoad' => true,
    'author' => 'Cyril Wolfangel',
    'author_email' => 'cyril.wolfangel@gmail.com',
    'version' => '2.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.99.99',
            'php' => '8.2.0-8.99.99',
            'nlp_tools' => '2.0.0-2.99.99',
        ],
    ],
];
