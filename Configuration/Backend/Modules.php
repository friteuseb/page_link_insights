<?php
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$typo3Version = GeneralUtility::makeInstance(Typo3Version::class);
$isVersion13 = $typo3Version->getMajorVersion() >= 13;

$moduleConfig = [
    'parent' => 'web',
    'position' => ['after' => 'web_layout'],
    'access' => 'user,group',
    'workspaces' => 'live',
    'path' => '/module/web/page-link-insights',
    'labels' => 'LLL:EXT:page_link_insights/Resources/Private/Language/locallang_mod.xlf',
    'iconIdentifier' => 'module-page-link-insights',
    'navigationComponentId' => 'TYPO3/CMS/Backend/PageTree/PageTreeElement',
];

// Version-specific configuration
if ($isVersion13) {
    $moduleConfig['extensionName'] = 'PageLinkInsights';
    $moduleConfig['controllerActions'] = [
        \Cywolf\PageLinkInsights\Controller\BackendController::class => [
            'main'
        ],
    ];
} else {
    // For TYPO3 v12 - define routes explicitly
    $moduleConfig['routes'] = [
        '_default' => [
            'target' => \Cywolf\PageLinkInsights\Controller\BackendController::class . '::mainActionV12'
        ],
    ];
}

return [
    'web_page_link_insights' => $moduleConfig,
];