<?php

declare(strict_types=1);

namespace Cywolf\PageLinkInsights\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use Cywolf\PageLinkInsights\Service\PageLinkService;
use Cywolf\PageLinkInsights\Service\ThemeDataService;
use TYPO3\CMS\Core\Database\ConnectionPool;

class BackendController extends ActionController
{
    protected array $extensionSettings;
    protected bool $debugMode = true;

    public function __construct(
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly PageRenderer $pageRenderer,
        protected readonly ExtensionConfiguration $extensionConfiguration,
        protected readonly PageLinkService $pageLinkService,
        protected readonly ThemeDataService $themeDataService
    ) {
        $this->extensionSettings = $this->extensionConfiguration->get('page_link_insights') ?? [];
    }

    public function mainAction(): ResponseInterface
    {
        $this->pageRenderer->addCssFile('EXT:page_link_insights/Resources/Public/Css/styles.css');
        $this->pageRenderer->addJsFile('EXT:page_link_insights/Resources/Public/JavaScript/Vendor/d3.min.js');
        $this->pageRenderer->addJsFile('EXT:page_link_insights/Resources/Public/JavaScript/force-diagram.js');

        $pageUid = (int)($this->request->getQueryParams()['id'] ?? 0);
        $colPosToAnalyze = $this->extensionSettings['colPosToAnalyze'] ?? '0,2';

        if ($pageUid > 0) {
            $this->clearThemeCache($pageUid);
        }

        $data = $this->prepareData($pageUid);
        $kpis = $pageUid > 0 ? $this->getPageKPIs($pageUid) : [];
        $semanticSuggestionInstalled = $this->pageLinkService->shouldIncludeSemanticSuggestions();

        $translations = $this->getTranslations();

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->assignMultiple([
            'data' => json_encode($data),
            'kpis' => $kpis,
            'noPageSelected' => ($pageUid === 0),
            'colPosToAnalyze' => $colPosToAnalyze,
            'semanticSuggestionInstalled' => $semanticSuggestionInstalled,
            'translations' => json_encode($translations),
        ]);

        return $moduleTemplate->renderResponse('Main');
    }

    protected function getTranslations(): array
    {
        $languageService = $GLOBALS['LANG'];
        $prefix = 'LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:';

        return [
            'dominantThemes' => $languageService->sL($prefix . 'diagram.legend.dominantThemes'),
            'linkTypes' => $languageService->sL($prefix . 'diagram.legend.linkTypes'),
            'themes' => $languageService->sL($prefix . 'diagram.tooltip.themes'),
            'standardLinks' => $languageService->sL($prefix . 'diagram.legend.standardLinks'),
            'semanticSuggestions' => $languageService->sL($prefix . 'diagram.legend.semanticSuggestions'),
            'brokenLinks' => $languageService->sL($prefix . 'diagram.legend.brokenLinks'),
            'incomingLinks' => $languageService->sL($prefix . 'diagram.tooltip.incomingLinks'),
            'ctrlClickToOpen' => $languageService->sL($prefix . 'diagram.tooltip.ctrlClickToOpen'),
            'rightClickToRemove' => $languageService->sL($prefix . 'diagram.tooltip.rightClickToRemove'),
            'fitToWindow' => $languageService->sL($prefix . 'diagram.button.fitToWindow'),
            'statisticsNoData' => $languageService->sL($prefix . 'statistics.notice.noData'),
            'statisticsRunAnalysis' => $languageService->sL($prefix . 'statistics.notice.runAnalysis'),
            'statisticsSchedulerInfo' => $languageService->sL($prefix . 'statistics.notice.schedulerInfo'),
            'helpTitle' => $languageService->sL($prefix . 'help.title'),
            'helpArrowsTitle' => $languageService->sL($prefix . 'help.arrows.title'),
            'helpArrowsDescription' => $languageService->sL($prefix . 'help.arrows.description'),
            'helpColorsTitle' => $languageService->sL($prefix . 'help.colors.title'),
            'helpColorsStandard' => $languageService->sL($prefix . 'help.colors.standard'),
            'helpColorsSemantic' => $languageService->sL($prefix . 'help.colors.semantic'),
            'helpColorsBroken' => $languageService->sL($prefix . 'help.colors.broken'),
            'helpNodesTitle' => $languageService->sL($prefix . 'help.nodes.title'),
            'helpNodesSize' => $languageService->sL($prefix . 'help.nodes.size'),
            'helpNodesColors' => $languageService->sL($prefix . 'help.nodes.colors'),
            'helpInteractionsTitle' => $languageService->sL($prefix . 'help.interactions.title'),
            'helpInteractionsDrag' => $languageService->sL($prefix . 'help.interactions.drag'),
            'helpInteractionsZoom' => $languageService->sL($prefix . 'help.interactions.zoom'),
            'helpInteractionsHover' => $languageService->sL($prefix . 'help.interactions.hover'),
            'helpInteractionsCtrlClick' => $languageService->sL($prefix . 'help.interactions.ctrlclick'),
            'helpOverviewTitle' => $languageService->sL($prefix . 'help.overview.title'),
            'helpOverviewDescription' => $languageService->sL($prefix . 'help.overview.description'),
            'helpLinksTitle' => $languageService->sL($prefix . 'help.links.title'),
            'noticesShowTitle' => $languageService->sL($prefix . 'notices.show.title'),
            'noticesRestoredMessage' => $languageService->sL($prefix . 'notices.restored.message'),
        ];
    }

    protected function prepareData(int $pageUid): array
    {
        if ($pageUid === 0) {
            return ['nodes' => [], 'links' => []];
        }

        try {
            $data = $this->pageLinkService->getPageLinksForSubtree($pageUid);
            $themeData = $this->themeDataService->getThemesForSubtree($pageUid);
            $data['nodes'] = $this->themeDataService->enrichNodesWithThemes($data['nodes'], $themeData);

            return $data;
        } catch (\Exception $e) {
            $this->debug('Error getting data: ' . $e->getMessage());
            return ['nodes' => [], 'links' => []];
        }
    }

    protected function getPageKPIs(int $pageUid): array
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        $queryBuilder = $connectionPool->getQueryBuilderForTable('tx_pagelinkinsights_statistics');
        $statistics = $queryBuilder
            ->select('*')
            ->from('tx_pagelinkinsights_statistics')
            ->orderBy('tstamp', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        $this->debug('Statistics Data', $statistics);

        if ($statistics) {
            return [
                'hasStatistics' => true,
                'site' => [
                    'siteRoot' => $statistics['site_root'],
                    'totalPages' => $statistics['total_pages'],
                    'totalLinks' => $statistics['total_links'],
                    'brokenLinksCount' => $statistics['broken_links_count'],
                    'orphanedPages' => $statistics['orphaned_pages'],
                    'avgLinksPerPage' => round($statistics['avg_links_per_page'], 2),
                    'networkDensity' => round($statistics['network_density'], 4),
                    'lastUpdate' => date('d/m/Y H:i', $statistics['tstamp'])
                ]
            ];
        }

        return [
            'hasStatistics' => false,
            'site' => [
                'siteRoot' => 0,
                'totalPages' => 0,
                'totalLinks' => 0,
                'brokenLinksCount' => 0,
                'orphanedPages' => 0,
                'avgLinksPerPage' => 0,
                'networkDensity' => 0,
                'lastUpdate' => '-'
            ]
        ];
    }

    protected function clearThemeCache(int $pageUid): void
    {
        try {
            $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
            $cacheIdentifier = 'themes_' . $pageUid;

            if ($cacheManager->hasCache('pages')) {
                $pagesCache = $cacheManager->getCache('pages');
                if ($pagesCache->has($cacheIdentifier)) {
                    $pagesCache->remove($cacheIdentifier);
                    $this->debug('Theme cache cleared for page ' . $pageUid);
                }
            }
        } catch (\Exception $e) {
            $this->debug('Error deleting theme cache', $e->getMessage());
        }
    }

    protected function debug(string $message, mixed $data = null): void
    {
        if (!$this->debugMode) {
            return;
        }

        $debugInfo = [
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => $message,
            'data' => $data
        ];

        if (!isset($GLOBALS['PAGE_LINK_INSIGHTS_DEBUG'])) {
            $GLOBALS['PAGE_LINK_INSIGHTS_DEBUG'] = [];
        }

        $GLOBALS['PAGE_LINK_INSIGHTS_DEBUG'][] = $debugInfo;
    }

    protected function getDebugLog(): array
    {
        return $GLOBALS['PAGE_LINK_INSIGHTS_DEBUG'] ?? [];
    }
}
