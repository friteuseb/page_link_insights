<?php

$EM_CONF['page_link_insights'] = [
    'title' => 'Page Link Insights',
    'description' => 'Visualize internal page links with D3.js force diagrams and thematic clustering for content analysis.',
    'category' => 'module',
    'state' => 'stable',
    'clearCacheOnLoad' => true,
    'author' => 'Cyril Wolfangel',
    'author_email' => 'cyril.wolfangel@gmail.com',
    'version' => '1.3.0',
    'constraints' => [
        'depends' => [
            'typo3' => '>=12.4.0,<14.0.0',
            'php' => '>=8.1.0,<8.3.0',
        ],
        'suggests' => [
            'nlp_tools' => '>=1.0.0', // Maintenant en suggestion plutôt qu'en dépendance
        ],
    ],
];