<?php
defined('TYPO3') or die();

// Register scheduler task
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][\Cywolf\PageLinkInsights\Task\AnalyzeLinksTask::class] = [
    'extension' => 'page_link_insights',
    'title' => 'LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:task.analyzeLinks.title',
    'description' => 'LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:task.analyzeLinks.description',
    'additionalFields' => \Cywolf\PageLinkInsights\Task\AnalyzeLinksTaskAdditionalFieldProvider::class
];

// Automatic Typoscript configuration for SOLR, you can uncomment or add your typoscript manually
//\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptSetup(
//    '@import "EXT:page_link_insights/Configuration/TypoScript/setup.typoscript"'
//);