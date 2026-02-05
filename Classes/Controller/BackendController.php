<?php
namespace Cywolf\PageLinkInsights\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use Cywolf\PageLinkInsights\Service\PageLinkService;
use Cywolf\PageLinkInsights\Service\ThemeDataService;
use TYPO3\CMS\Core\Database\ConnectionPool;

class BackendController extends ActionController
{
    protected array $extensionSettings;
    protected bool $debugMode = true;
    protected bool $isVersion13;

    public function __construct(
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly PageRenderer $pageRenderer,
        protected readonly ExtensionConfiguration $extensionConfiguration,
        protected readonly PageLinkService $pageLinkService,
        protected readonly ThemeDataService $themeDataService
    ) {
        $this->extensionSettings = $this->extensionConfiguration->get('page_link_insights') ?? [];
        $typo3Version = GeneralUtility::makeInstance(Typo3Version::class);
        $this->isVersion13 = $typo3Version->getMajorVersion() >= 13;
    }

    // Pour TYPO3 v13 - Interface Extbase ActionController
    public function mainAction(): ResponseInterface
    {
        // If we are in v13, use this implementation
        if ($this->isVersion13) {
            return $this->renderForV13();
        }
        
        // Sinon, utiliser l'implémentation v12 avec l'objet de requête à partir d'Extbase
        return $this->mainActionV12($this->request);
    }

    // Pour TYPO3 v12 - Interface PSR-7 ServerRequestInterface
    public function mainActionV12(ServerRequestInterface $request): ResponseInterface
    {
        // Ajouter CSS
        $this->pageRenderer->addCssFile('EXT:page_link_insights/Resources/Public/Css/styles.css');

        // Ajouter JS
        $this->pageRenderer->addJsFile('EXT:page_link_insights/Resources/Public/JavaScript/Vendor/d3.min.js');
        $this->pageRenderer->addJsFile('EXT:page_link_insights/Resources/Public/JavaScript/force-diagram.js');
        
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setTemplateRootPaths(['EXT:page_link_insights/Resources/Private/Templates/']);
        $view->setLayoutRootPaths(['EXT:page_link_insights/Resources/Private/Layouts/']);
        $view->setPartialRootPaths(['EXT:page_link_insights/Resources/Private/Partials/']);
        $view->setTemplate('Main');
        
        $pageUid = (int)($request->getQueryParams()['id'] ?? 0);
        
        // Retrieve the colPos configuration
        $colPosToAnalyze = $this->extensionSettings['colPosToAnalyze'] ?? '0,2';
        
        // Force reloading of theme data by clearing the appropriate cache
        if ($pageUid > 0) {
            $this->clearThemeCache($pageUid);
        }
        // Prepare the data as usual...
        $data = $this->prepareData($pageUid);
        $kpis = $pageUid > 0 ? $this->getPageKPIs($pageUid) : [];
        $pageMetrics = $pageUid > 0 ? $this->getPageMetrics($pageUid) : [];

        // Check if semantic suggestions should be included (both extension availability and configuration)
        $semanticSuggestionInstalled = $this->pageLinkService->shouldIncludeSemanticSuggestions();

        // Prepare translations for JavaScript with fallbacks
        $translations = $this->getTranslationsWithFallbacks();

        $view->assignMultiple([
            'data' => json_encode($data),
            'kpis' => $kpis,
            'pageMetrics' => $pageMetrics,
            'noPageSelected' => ($pageUid === 0),
            'colPosToAnalyze' => $colPosToAnalyze,
            'semanticSuggestionInstalled' => $semanticSuggestionInstalled,
            'translations' => json_encode($translations),
        ]);
        
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setContent($view->render());
        
        return new HtmlResponse($moduleTemplate->renderContent());
    }

    protected function renderForV13(): ResponseInterface
    {
        // Ajouter CSS
        $this->pageRenderer->addCssFile('EXT:page_link_insights/Resources/Public/Css/styles.css');

        // Ajouter JS
        $this->pageRenderer->addJsFile('EXT:page_link_insights/Resources/Public/JavaScript/Vendor/d3.min.js');
        $this->pageRenderer->addJsFile('EXT:page_link_insights/Resources/Public/JavaScript/force-diagram.js');
        
        $pageUid = (int)($this->request->getQueryParams()['id'] ?? 0);
        
        // Use the configuration directly initialized in the constructor
        $colPosToAnalyze = $this->extensionSettings['colPosToAnalyze'] ?? '0,2';
        
        // Force reloading of theme data by clearing the appropriate cache
        if ($pageUid > 0) {
            $this->clearThemeCache($pageUid);
        }

        // Prepare the data as usual...
        $data = $this->prepareData($pageUid);
        $kpis = $pageUid > 0 ? $this->getPageKPIs($pageUid) : [];
        $pageMetrics = $pageUid > 0 ? $this->getPageMetrics($pageUid) : [];

        // Check if semantic suggestions should be included (both extension availability and configuration)
        $semanticSuggestionInstalled = $this->pageLinkService->shouldIncludeSemanticSuggestions();

        // Prepare translations for JavaScript with fallbacks
        $translations = $this->getTranslationsWithFallbacks();

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->assignMultiple([
            'data' => json_encode($data),
            'kpis' => $kpis,
            'pageMetrics' => $pageMetrics,
            'noPageSelected' => ($pageUid === 0),
            'colPosToAnalyze' => $colPosToAnalyze,
            'semanticSuggestionInstalled' => $semanticSuggestionInstalled,
            'translations' => json_encode($translations),
        ]);
        
        return $moduleTemplate->renderResponse('Main');
    }
    
    
    protected function prepareData(int $pageUid): array
    {
        if ($pageUid === 0) {
            return ['nodes' => [], 'links' => []];
        }
        
        try {
            $data = $this->pageLinkService->getPageLinksForSubtree($pageUid);
            
            // Retrieve the thematic data
            $themeData = $this->themeDataService->getThemesForSubtree($pageUid);
            
            // Enrich the nodes with thematic information
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
        
        // Retrieve the last analysis performed (the most recent)
        $queryBuilder = $connectionPool->getQueryBuilderForTable('tx_pagelinkinsights_statistics');
        $statistics = $queryBuilder
            ->select('*')
            ->from('tx_pagelinkinsights_statistics')
            ->orderBy('tstamp', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        $this->debug('Statistics Data', $statistics);

        // If we have statistics, return them, otherwise return default values
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

        // No statistics available - indicate this to template
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

    protected function initialize(): void
    {
        parent::initialize();
        $this->debug('Controller initialized');
    }

    /**
     * Clears the theme cache for a specific page
     */
    protected function clearThemeCache(int $pageUid): void
    {
        try {
            $cacheManager = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class);
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

    /**
     * Get page metrics from analysis table (latest entry per page)
     */
    protected function getPageMetrics(int $pageUid): array
    {
        if ($pageUid === 0) {
            return [];
        }

        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        // Get all pages in the subtree
        $subtreePageIds = $this->pageLinkService->getSubtreePageIds($pageUid);

        if (empty($subtreePageIds)) {
            return [];
        }

        // First, get the latest tstamp for each page_uid
        $subQueryBuilder = $connectionPool->getQueryBuilderForTable('tx_pagelinkinsights_pageanalysis');
        $subQuery = $subQueryBuilder
            ->select('page_uid')
            ->addSelectLiteral('MAX(tstamp) as max_tstamp')
            ->from('tx_pagelinkinsights_pageanalysis')
            ->where(
                $subQueryBuilder->expr()->in(
                    'page_uid',
                    $subQueryBuilder->createNamedParameter($subtreePageIds, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                )
            )
            ->groupBy('page_uid')
            ->executeQuery()
            ->fetchAllAssociative();

        if (empty($subQuery)) {
            return [];
        }

        // Build conditions for latest entries
        $queryBuilder = $connectionPool->getQueryBuilderForTable('tx_pagelinkinsights_pageanalysis');
        $orConditions = [];
        foreach ($subQuery as $row) {
            $orConditions[] = $queryBuilder->expr()->and(
                $queryBuilder->expr()->eq('pa.page_uid', $queryBuilder->createNamedParameter((int)$row['page_uid'], \TYPO3\CMS\Core\Database\Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('pa.tstamp', $queryBuilder->createNamedParameter((int)$row['max_tstamp'], \TYPO3\CMS\Core\Database\Connection::PARAM_INT))
            );
        }

        $result = $queryBuilder
            ->select(
                'pa.page_uid',
                'pa.pagerank',
                'pa.inbound_links',
                'pa.outbound_links',
                'pa.broken_links',
                'pa.centrality_score',
                'p.title'
            )
            ->from('tx_pagelinkinsights_pageanalysis', 'pa')
            ->join(
                'pa',
                'pages',
                'p',
                $queryBuilder->expr()->eq('pa.page_uid', $queryBuilder->quoteIdentifier('p.uid'))
            )
            ->where(
                $queryBuilder->expr()->or(...$orConditions)
            )
            ->orderBy('pa.pagerank', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->normalizeMetrics($result);
    }

    /**
     * Normalize pagerank and centrality_score to 0-100 scale using min-max normalization
     */
    protected function normalizeMetrics(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $pagerankValues = array_column($rows, 'pagerank');
        $centralityValues = array_column($rows, 'centrality_score');

        $prMin = min($pagerankValues);
        $prMax = max($pagerankValues);
        $prRange = $prMax - $prMin;

        $cMin = min($centralityValues);
        $cMax = max($centralityValues);
        $cRange = $cMax - $cMin;

        foreach ($rows as &$row) {
            $row['pagerank'] = $prRange > 0
                ? round(($row['pagerank'] - $prMin) / $prRange * 100, 1)
                : (count($rows) === 1 ? 100.0 : 0.0);

            $row['centrality_score'] = $cRange > 0
                ? round(($row['centrality_score'] - $cMin) / $cRange * 100, 1)
                : (count($rows) === 1 ? 100.0 : 0.0);
        }
        unset($row);

        return $rows;
    }

    protected function getDebugLog(): array
    {
        return $GLOBALS['PAGE_LINK_INSIGHTS_DEBUG'] ?? [];
    }

    /**
     * Get translations with English fallbacks
     */
    protected function getTranslationsWithFallbacks(): array
    {
        $fallbacks = [
            'dominantThemes' => 'Dominant Themes',
            'linkTypes' => 'Link Types',
            'themes' => 'Themes:',
            'standardLinks' => 'Standard Links',
            'semanticSuggestions' => 'Semantic Suggestions',
            'brokenLinks' => 'Broken Links',
            'incomingLinks' => 'Incoming links:',
            'ctrlClickToOpen' => 'Ctrl+Click to open in TYPO3',
            'rightClickToRemove' => 'Right-click to remove',
            'fitToWindow' => 'Fit to Window',
            'statisticsNoData' => 'No statistics available yet',
            'statisticsRunAnalysis' => 'Run the "Analyze Internal Links" task to generate statistics.',
            'statisticsSchedulerInfo' => 'Configure this task in the TYPO3 Scheduler for automatic updates.',
            'helpTitle' => 'Diagram Guide',
            'helpArrowsTitle' => 'Arrow Directions',
            'helpArrowsDescription' => 'Arrows point from source pages to target pages.',
            'helpColorsTitle' => 'Link Colors',
            'helpColorsStandard' => 'Standard page links from content elements',
            'helpColorsSemantic' => 'AI-generated semantic suggestions',
            'helpColorsBroken' => 'Broken links (target page missing)',
            'helpNodesTitle' => 'Node Information',
            'helpNodesSize' => 'Node size reflects incoming links count.',
            'helpNodesColors' => 'Node colors are based on dominant themes.',
            'helpInteractionsTitle' => 'Interactions',
            'helpInteractionsDrag' => 'Drag nodes to rearrange',
            'helpInteractionsZoom' => 'Mouse wheel to zoom, drag to pan',
            'helpInteractionsHover' => 'Hover for details',
            'helpInteractionsCtrlClick' => 'Ctrl+Click to open in TYPO3 backend',
            'helpOverviewTitle' => 'Overview',
            'helpOverviewDescription' => 'This diagram visualizes page relationships.',
            'helpLinksTitle' => 'Link Types',
            'noticesShowTitle' => 'Show dismissed notices',
            'noticesRestoredMessage' => 'Notices restored',
            'fullscreen' => 'Fullscreen',
            'exitFullscreen' => 'Exit Fullscreen',
            'tableTitle' => 'Page Metrics',
            'tableColumnPage' => 'Page',
            'tableColumnPagerank' => 'PageRank',
            'tableColumnInbound' => 'Inbound Links',
            'tableColumnOutbound' => 'Outbound Links',
            'tableColumnCentrality' => 'Centrality',
            'tableToggleShow' => 'Show Page Metrics',
            'tableToggleHide' => 'Hide Page Metrics',
            'tableTooltipPagerank' => 'PageRank score (0-100): Measures page importance based on incoming links. 100 = most important page, 0 = least important.',
            'tableTooltipInbound' => 'Number of internal links pointing TO this page from other pages in the site.',
            'tableTooltipOutbound' => 'Number of internal links going FROM this page to other pages in the site.',
            'tableTooltipCentrality' => 'Centrality score (0-100): Measures how connected this page is relative to others. 100 = most central page, 0 = least central.',
        ];

        $translationKeys = [
            'dominantThemes' => 'diagram.legend.dominantThemes',
            'linkTypes' => 'diagram.legend.linkTypes',
            'themes' => 'diagram.tooltip.themes',
            'standardLinks' => 'diagram.legend.standardLinks',
            'semanticSuggestions' => 'diagram.legend.semanticSuggestions',
            'brokenLinks' => 'diagram.legend.brokenLinks',
            'incomingLinks' => 'diagram.tooltip.incomingLinks',
            'ctrlClickToOpen' => 'diagram.tooltip.ctrlClickToOpen',
            'rightClickToRemove' => 'diagram.tooltip.rightClickToRemove',
            'fitToWindow' => 'diagram.button.fitToWindow',
            'statisticsNoData' => 'statistics.notice.noData',
            'statisticsRunAnalysis' => 'statistics.notice.runAnalysis',
            'statisticsSchedulerInfo' => 'statistics.notice.schedulerInfo',
            'helpTitle' => 'help.title',
            'helpArrowsTitle' => 'help.arrows.title',
            'helpArrowsDescription' => 'help.arrows.description',
            'helpColorsTitle' => 'help.colors.title',
            'helpColorsStandard' => 'help.colors.standard',
            'helpColorsSemantic' => 'help.colors.semantic',
            'helpColorsBroken' => 'help.colors.broken',
            'helpNodesTitle' => 'help.nodes.title',
            'helpNodesSize' => 'help.nodes.size',
            'helpNodesColors' => 'help.nodes.colors',
            'helpInteractionsTitle' => 'help.interactions.title',
            'helpInteractionsDrag' => 'help.interactions.drag',
            'helpInteractionsZoom' => 'help.interactions.zoom',
            'helpInteractionsHover' => 'help.interactions.hover',
            'helpInteractionsCtrlClick' => 'help.interactions.ctrlclick',
            'helpOverviewTitle' => 'help.overview.title',
            'helpOverviewDescription' => 'help.overview.description',
            'helpLinksTitle' => 'help.links.title',
            'noticesShowTitle' => 'notices.show.title',
            'noticesRestoredMessage' => 'notices.restored.message',
            'fullscreen' => 'diagram.button.fullscreen',
            'exitFullscreen' => 'diagram.button.exitFullscreen',
            'tableTitle' => 'table.title',
            'tableColumnPage' => 'table.column.page',
            'tableColumnPagerank' => 'table.column.pagerank',
            'tableColumnInbound' => 'table.column.inbound',
            'tableColumnOutbound' => 'table.column.outbound',
            'tableColumnCentrality' => 'table.column.centrality',
            'tableToggleShow' => 'table.toggle.show',
            'tableToggleHide' => 'table.toggle.hide',
            'tableTooltipPagerank' => 'table.tooltip.pagerank',
            'tableTooltipInbound' => 'table.tooltip.inbound',
            'tableTooltipOutbound' => 'table.tooltip.outbound',
            'tableTooltipCentrality' => 'table.tooltip.centrality',
        ];

        $translations = [];
        foreach ($translationKeys as $key => $xliffKey) {
            $translated = $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:' . $xliffKey);
            $translations[$key] = !empty($translated) ? $translated : $fallbacks[$key];
        }

        return $translations;
    }
}