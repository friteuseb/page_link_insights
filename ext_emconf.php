<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Page Link Insights',
    'description' => 'Visualize internal page links with D3.js force diagrams and thematic clustering for content analysis.',
    'category' => 'module',
    'state' => 'stable',
    'clearCacheOnLoad' => true,
    'author' => 'Cyril Wolfangel',
    'author_email' => 'cyril.wolfangel@gmail.com',
    'version' => '1.7.1',
    'uploadfolder' => false,
    'createDirs' => '',
    'autoload' => [],
    'CGLcompliance' => '',
    'CGLcompliance_note' => '',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-13.4.99',
            'php' => '8.1.0-8.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];