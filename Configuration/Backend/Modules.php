<?php

return [
    'web_page_link_insights' => [
        'parent' => 'web',
        'position' => ['after' => 'web_layout'],
        'access' => 'user,group',
        'workspaces' => 'live',
        'path' => '/module/web/page-link-insights',
        'labels' => 'LLL:EXT:page_link_insights/Resources/Private/Language/locallang_mod.xlf',
        'iconIdentifier' => 'module-page-link-insights',
        'navigationComponentId' => 'TYPO3/CMS/Backend/PageTree/PageTreeElement',
        'extensionName' => 'PageLinkInsights',
        'controllerActions' => [
            \Cwolf\PageLinkInsights\Controller\BackendController::class => [
                'main'
            ],
        ],
    ],
];