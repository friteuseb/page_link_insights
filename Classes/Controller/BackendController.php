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
        // Si nous sommes en v13, utiliser cette implémentation
        if ($this->isVersion13) {
            return $this->renderForV13();
        }
        
        // Sinon, utiliser l'implémentation v12 avec l'objet de requête à partir d'Extbase
        return $this->mainActionV12($this->request);
    }

    // Pour TYPO3 v12 - Interface PSR-7 ServerRequestInterface
    public function mainActionV12(ServerRequestInterface $request): ResponseInterface
    {
        // Ajouter JS
        $this->pageRenderer->addJsFile('EXT:page_link_insights/Resources/Public/JavaScript/Vendor/d3.min.js');
        $this->pageRenderer->addJsFile('EXT:page_link_insights/Resources/Public/JavaScript/force-diagram.js');
        
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setTemplateRootPaths(['EXT:page_link_insights/Resources/Private/Templates/']);
        $view->setLayoutRootPaths(['EXT:page_link_insights/Resources/Private/Layouts/']);
        $view->setPartialRootPaths(['EXT:page_link_insights/Resources/Private/Partials/']);
        $view->setTemplate('Main');
        
        $pageUid = (int)($request->getQueryParams()['id'] ?? 0);
        
        // Récupérer la configuration des colPos
        $colPosToAnalyze = $this->extensionSettings['colPosToAnalyze'] ?? '0,2';
        
        // Forcer le rechargement des données de thème en vidant le cache approprié
        if ($pageUid > 0) {
            $this->clearThemeCache($pageUid);
        }
        // Préparer les données comme d'habitude...
        $data = $this->prepareData($pageUid);
        $kpis = $pageUid > 0 ? $this->getPageKPIs($pageUid) : [];
        
        // Vérifier si l'extension semanticSuggestion est installée
        $semanticSuggestionInstalled = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('semantic_suggestion');
        
        // Prepare translations for JavaScript
        $translations = [
            'dominantThemes' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:diagram.legend.dominantThemes'),
            'linkTypes' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:diagram.legend.linkTypes'),
            'themes' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:diagram.tooltip.themes'),
            'standardLinks' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:diagram.legend.standardLinks'),
            'semanticSuggestions' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:diagram.legend.semanticSuggestions'),
            'brokenLinks' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:diagram.legend.brokenLinks'),
            'incomingLinks' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:diagram.tooltip.incomingLinks'),
            'ctrlClickToOpen' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:diagram.tooltip.ctrlClickToOpen'),
            'rightClickToRemove' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:diagram.tooltip.rightClickToRemove'),
            'fitToWindow' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:diagram.button.fitToWindow'),
            'statisticsNoData' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:statistics.notice.noData'),
            'statisticsRunAnalysis' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:statistics.notice.runAnalysis'),
            'statisticsSchedulerInfo' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:statistics.notice.schedulerInfo')
        ];

        $view->assignMultiple([
            'data' => json_encode($data),
            'kpis' => $kpis,
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
        // Ajouter JS
        $this->pageRenderer->addJsFile('EXT:page_link_insights/Resources/Public/JavaScript/Vendor/d3.min.js');
        $this->pageRenderer->addJsFile('EXT:page_link_insights/Resources/Public/JavaScript/force-diagram.js');
        
        $pageUid = (int)($this->request->getQueryParams()['id'] ?? 0);
        
        // Utiliser directement la configuration initialisée dans le constructeur
        $colPosToAnalyze = $this->extensionSettings['colPosToAnalyze'] ?? '0,2';
        
        // Forcer le rechargement des données de thème en vidant le cache approprié
        if ($pageUid > 0) {
            $this->clearThemeCache($pageUid);
        }

        // Préparer les données comme d'habitude...
        $data = $this->prepareData($pageUid);
        $kpis = $pageUid > 0 ? $this->getPageKPIs($pageUid) : [];
        
        // Vérifier si l'extension semanticSuggestion est installée
        $semanticSuggestionInstalled = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('semantic_suggestion');

        // Prepare translations for JavaScript
        $translations = [
            'dominantThemes' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:diagram.legend.dominantThemes'),
            'linkTypes' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:diagram.legend.linkTypes'),
            'themes' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:diagram.tooltip.themes'),
            'standardLinks' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:diagram.legend.standardLinks'),
            'semanticSuggestions' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:diagram.legend.semanticSuggestions'),
            'brokenLinks' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:diagram.legend.brokenLinks'),
            'incomingLinks' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:diagram.tooltip.incomingLinks'),
            'ctrlClickToOpen' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:diagram.tooltip.ctrlClickToOpen'),
            'rightClickToRemove' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:diagram.tooltip.rightClickToRemove'),
            'fitToWindow' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:diagram.button.fitToWindow'),
            'statisticsNoData' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:statistics.notice.noData'),
            'statisticsRunAnalysis' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:statistics.notice.runAnalysis'),
            'statisticsSchedulerInfo' => $GLOBALS['LANG']->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:statistics.notice.schedulerInfo')
        ];

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
    
    
    protected function prepareData(int $pageUid): array
    {
        if ($pageUid === 0) {
            return ['nodes' => [], 'links' => []];
        }
        
        try {
            $data = $this->pageLinkService->getPageLinksForSubtree($pageUid);
            
            // Récupérer les données thématiques
            $themeData = $this->themeDataService->getThemesForSubtree($pageUid);
            
            // Enrichir les nœuds avec les informations thématiques
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
        
        // Récupérer la dernière analyse effectuée (la plus récente)
        $queryBuilder = $connectionPool->getQueryBuilderForTable('tx_pagelinkinsights_statistics');
        $statistics = $queryBuilder
            ->select('*')
            ->from('tx_pagelinkinsights_statistics')
            ->orderBy('tstamp', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        $this->debug('Statistics Data', $statistics);

        // Si nous avons des statistiques, les renvoyer, sinon retourner des valeurs par défaut
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
     * Vide le cache des thèmes pour une page spécifique
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
                    $this->debug('Cache des thèmes vidé pour la page ' . $pageUid);
                }
            }
        } catch (\Exception $e) {
            $this->debug('Erreur lors de la suppression du cache des thèmes', $e->getMessage());
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