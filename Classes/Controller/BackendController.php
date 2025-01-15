<?php

namespace Cwolf\PageLinkInsights\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use Cwolf\PageLinkInsights\Service\PageLinkService;

class BackendController extends ActionController
{
    protected array $extensionSettings;
    protected bool $debugMode = true;
    
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly PageRepository $pageRepository,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly PageLinkService $pageLinkService
    ) {
    }

    protected function initializeAction(): void
    {
        parent::initializeAction();
        $this->extensionSettings = $this->extensionConfiguration->get('page_link_insights') ?? [];
        $this->debug('Controller initialized');
        
        // Ajouter les fichiers JS nécessaires dès l'initialisation
        $this->pageRenderer->addJsFile(
            'EXT:page_link_insights/Resources/Public/JavaScript/Vendor/d3.min.js'
        );
        
        $this->pageRenderer->addJsFile(
            'EXT:page_link_insights/Resources/Public/JavaScript/force-diagram.js'
        );
    }

    public function mainAction(): ResponseInterface
    {
        $this->debug('Starting mainAction');
        
        // Récupérer la page sélectionnée dans le page tree
        $pageUid = (int)($this->request->getQueryParams()['id'] ?? 0);
        $this->debug('Page UID from request', $pageUid);
            
        if ($pageUid === 0) {
            $this->debug('No page selected, showing default view');
            $this->view->assign('noPageSelected', true);
            $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
            $moduleTemplate->setContent($this->view->render());
            return $this->htmlResponse($moduleTemplate->renderContent());
        }

        try {
            // Récupérer les pages dans l'arborescence
            $pages = $this->getPagesInSubtree($pageUid);
            $this->debug('Pages in subtree', $pages);
            
            if (empty($pages)) {
                throw new \Exception('No pages found in subtree');
            }

            // Récupérer les liens pour ces pages
            $pageUids = array_column($pages, 'uid');
            $links = $this->getContentLinks($pageUids);
            $this->debug('Content links', $links);

            // Construire les données pour le graphe
            $data = $this->buildGraphData($pages, $links);
            $this->debug('Graph data built', $data);

            // Vérifier que nous avons des données valides
            if (empty($data['nodes']) || empty($data['links'])) {
                $this->debug('No valid graph data found');
                $data = ['nodes' => [], 'links' => []];
            }

        } catch (\Exception $e) {
            $this->debug('Error occurred', $e->getMessage());
            $data = ['nodes' => [], 'links' => []];
        }
    
        // Préparer la vue
        $this->view->assign('data', json_encode($data));
        $this->view->assign('noPageSelected', false);

        // Ajouter le debug log à la vue si le mode debug est actif
        if ($this->debugMode) {
            $this->view->assign('debugLog', json_encode($this->getDebugLog(), JSON_PRETTY_PRINT));
        }
    
        // Rendu final
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setContent($this->view->render());
        
        return $this->htmlResponse($moduleTemplate->renderContent());
    }

    protected function initialize(): void
    {
        parent::initialize();
        $this->debug('Controller initialized');
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

    protected function getPagesInSubtree(int $pageUid): array
    {
        $this->debug('Starting getPagesInSubtree', ['pageUid' => $pageUid]);

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');
                
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                
        // Récupérer d'abord la page courante
        $currentPage = $queryBuilder
            ->select('uid', 'title', 'pid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid))
            )
            ->executeQuery()
            ->fetchAssociative();

        $this->debug('Current page found', $currentPage);

        if (!$currentPage) {
            $this->debug('No current page found, returning empty array');
            return [];
        }

        // Récupérer TOUTES les sous-pages en une seule requête
        $allSubPages = [];
        $pagesToProcess = [$pageUid];
        
        while (!empty($pagesToProcess)) {
            $currentPageUids = $pagesToProcess;
            $pagesToProcess = [];

            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable('pages');
                    
            $queryBuilder->getRestrictions()
                ->removeAll()
                ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                    
            $subPages = $queryBuilder
                ->select('uid', 'title', 'pid')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->in(
                        'pid',
                        $queryBuilder->createNamedParameter($currentPageUids, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                    )
                )
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($subPages as $subPage) {
                $allSubPages[] = $subPage;
                $pagesToProcess[] = $subPage['uid'];
            }
        }

        $this->debug('All sub-pages found', $allSubPages);

        $result = array_merge([$currentPage], $allSubPages);
        $this->debug('Final pages result', $result);
        return $result;
    }

    private function findLinksInContent(string $content): array 
    {
        $this->debug('Starting findLinksInContent', ['content_length' => strlen($content)]);
        
        $links = [];
        
        // Recherche des liens t3://page
        if (preg_match_all('/t3:\/\/page\?uid=(\d+)/', $content, $matches)) {
            $this->debug('Found t3://page links', $matches[1]);
            foreach ($matches[1] as $pageUid) {
                $links[] = (int)$pageUid;
            }
        }
        
        // Recherche des liens <link>
        if (preg_match_all('/<link (\d+)>/', $content, $matches)) {
            $this->debug('Found <link> style links', $matches[1]);
            foreach ($matches[1] as $pageUid) {
                $links[] = (int)$pageUid;
            }
        }
        
        // Recherche des liens de type "record:pages:UID"
        if (preg_match_all('/record:pages:(\d+)/', $content, $matches)) {
            $this->debug('Found record:pages links', $matches[1]);
            foreach ($matches[1] as $pageUid) {
                $links[] = (int)$pageUid;
            }
        }
    
        // Recherche des anciens liens typolink
        if (preg_match_all('/\b(?:t3:\/\/)?page,(\d+)(?:,|\s|$)/', $content, $matches)) {
            $this->debug('Found old typolink style links', $matches[1]);
            foreach ($matches[1] as $pageUid) {
                $links[] = (int)$pageUid;
            }
        }
    
        // Recherche des liens HTML standards avec id= ou page=
        if (preg_match_all('/<a[^>]+href=["\'](?:[^"\']*?)(?:id=|page=)(\d+)/', $content, $matches)) {
            $this->debug('Found HTML links with id/page parameter', $matches[1]);
            foreach ($matches[1] as $pageUid) {
                $links[] = (int)$pageUid;
            }
        }
    
        // Recherche des liens dans les menus
        if (preg_match_all('/pages\s*=\s*"([^"]+)"/', $content, $matches)) {
            $this->debug('Found menu page references', $matches[1]);
            foreach ($matches[1] as $pageList) {
                $pageIds = \TYPO3\CMS\Core\Utility\GeneralUtility::intExplode(',', $pageList, true);
                $links = array_merge($links, $pageIds);
            }
        }
        
        $uniqueLinks = array_unique($links);
        $this->debug('Final unique links found', $uniqueLinks);
        return $uniqueLinks;
    }
    
    private function getContentLinks(array $pageUids): array
    {
        $this->debug('Starting getContentLinks', ['pageUids' => $pageUids]);
        
        $links = [];
        $allowedColPos = GeneralUtility::intExplode(',', $this->extensionSettings['colPosToAnalyze'] ?? '0,1,2,3,4', true);
        $this->debug('Allowed colPos', $allowedColPos);
            
        // Construire la requête pour tt_content
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
                
        // Gérer les restrictions
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                
        if (!($this->extensionSettings['includeHidden'] ?? false)) {
            $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(HiddenRestriction::class));
        }
    
        // Récupérer le contenu des pages sélectionnées
        $contentElements = $queryBuilder
            ->select('uid', 'pid', 'header', 'bodytext', 'CType', 'list_type', 'colPos', 'header_link', 'pages', 'selected_categories')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($pageUids, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->in(
                    'colPos',
                    $queryBuilder->createNamedParameter($allowedColPos, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();
    
        $this->debug('Content elements found', $contentElements);
    
        // Analyser chaque élément de contenu
        foreach ($contentElements as $content) {
            $this->debug('Analyzing content element', $content);
            
            // Traitement spécial pour les éléments de menu
            if (str_starts_with($content['CType'], 'menu_')) {
                $targetPages = [];
                
                switch ($content['CType']) {
                    case 'menu_subpages':
                        // Pour menu_subpages, on récupère toutes les sous-pages de la page référencée
                        if (!empty($content['pages'])) {
                            $parentPageUid = (int)$content['pages'];
                            $subPages = $this->getSubPages($parentPageUid);
                            $targetPages = array_column($subPages, 'uid');
                        }
                        break;
                        
                    case 'menu_card_dir':
                        // Pour menu_card_dir, on récupère aussi toutes les sous-pages
                        if (!empty($content['pages'])) {
                            $parentPageUid = (int)$content['pages'];
                            $subPages = $this->getSubPages($parentPageUid);
                            $targetPages = array_column($subPages, 'uid');
                        }
                        break;
                        
                    default:
                        // Pour les autres types de menus, on utilise directement le champ pages
                        if (!empty($content['pages'])) {
                            $targetPages = GeneralUtility::intExplode(',', $content['pages'], true);
                        }
                }
                
                foreach ($targetPages as $targetPageUid) {
                    $links[] = [
                        'sourcePageId' => (string)$content['pid'],
                        'targetPageId' => (string)$targetPageUid,
                        'contentElement' => [
                            'uid' => $content['uid'],
                            'type' => $content['CType'],
                            'header' => $content['header'],
                            'colPos' => $content['colPos'],
                            'field' => 'pages'
                        ]
                    ];
                }
            }
            
            // Chercher dans le bodytext
            if ($content['bodytext']) {
                $foundLinks = $this->findLinksInContent($content['bodytext']);
                $this->debug('Links found in bodytext', $foundLinks);
                
                foreach ($foundLinks as $targetPageUid) {
                    $links[] = [
                        'sourcePageId' => (string)$content['pid'],
                        'targetPageId' => (string)$targetPageUid,
                        'contentElement' => [
                            'uid' => $content['uid'],
                            'type' => $content['CType'],
                            'header' => $content['header'],
                            'colPos' => $content['colPos'],
                            'field' => 'bodytext'
                        ]
                    ];
                }
            }
            
            // Chercher dans le header_link
            if ($content['header_link']) {
                $foundLinks = $this->findLinksInContent($content['header_link']);
                $this->debug('Links found in header_link', $foundLinks);
                
                foreach ($foundLinks as $targetPageUid) {
                    $links[] = [
                        'sourcePageId' => (string)$content['pid'],
                        'targetPageId' => (string)$targetPageUid,
                        'contentElement' => [
                            'uid' => $content['uid'],
                            'type' => $content['CType'],
                            'header' => $content['header'],
                            'colPos' => $content['colPos'],
                            'field' => 'header_link'
                        ]
                    ];
                }
            }
        }
    
        $this->debug('Final links array', $links);
        return $links;
    }


    private function getSubPages(int $pageUid): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');
                
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
    
        return $queryBuilder
            ->select('uid', 'title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($pageUid, \PDO::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }
    
    protected function buildGraphData(array $pages, array $links): array
    {
        $this->debug('Starting buildGraphData', [
            'pages_count' => count($pages),
            'links_count' => count($links)
        ]);
        
        // Créer un mapping des UIDs de pages vers leurs titres
        $pageTitles = array_column($pages, 'title', 'uid');
        
        // Récupérer tous les IDs de pages impliqués
        $allPageIds = [];
        foreach ($links as $link) {
            $allPageIds[] = $link['sourcePageId'];
            $allPageIds[] = $link['targetPageId'];
        }
        $allPageIds = array_unique(array_merge(
            array_column($pages, 'uid'),
            $allPageIds
        ));
        
        // Créer les nœuds pour toutes les pages
        $nodes = [];
        foreach ($allPageIds as $pageId) {
            $nodes[] = [
                'id' => (string)$pageId,
                'title' => $pageTitles[$pageId] ?? "Page $pageId (externe)"
            ];
        }
        
        $result = [
            'nodes' => $nodes,
            'links' => array_map(function($link) {
                return [
                    'source' => $link['sourcePageId'],
                    'target' => $link['targetPageId'],
                    'contentElement' => $link['contentElement']
                ];
            }, $links)
        ];
        
        $this->debug('Final graph data', $result);
        return $result;
    }

}