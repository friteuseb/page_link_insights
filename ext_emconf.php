<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Page Link Insights',
    'description' => 'Maps internal linking from the TYPO3 reference index and draws it as an interactive force diagram, with per-language theme extraction and broken link detection.',
    'category' => 'module',
    'state' => 'stable',
    'clearCacheOnLoad' => true,
    'author' => 'Cyril Wolfangel',
    'author_email' => 'cyril.wolfangel@gmail.com',
    'version' => '3.0.2',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.99.99',
            'php' => '8.2.0-8.99.99',
            'nlp_tools' => '2.0.0-2.99.99',
        ],
    ],
];
