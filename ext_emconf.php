<?php

$EM_CONF['page_link_insights'] = [
    'title' => 'Page Link Insights',
    'description' => 'Visualize internal page links with D3.js force diagrams and thematic clustering for content analysis.',
    'category' => 'module',
    'state' => 'stable',
    'clearCacheOnLoad' => true,
    'author' => 'Cyril Wolfangel',
    'author_email' => 'cyril.wolfangel@gmail.com',
    'version' => '1.4.1',
    'uploadfolder' => false,
    'createDirs' => '',
    'autoload' => [],
    'CGLcompliance' => '',
    'CGLcompliance_note' => '',
    'constraints' => [
        'depends' => [
            'typo3' => '>=12.4.0,<14.0.0',
            'php' => '>=8.1.0,<8.5.0',
            'nlp_tools' => '>=1.2.0',
        ],
  ],
];