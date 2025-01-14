<?php

namespace Cwolf\PageLinkInsights\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

class BackendController extends ActionController
{
    protected array $extensionSettings;
    protected bool $debugMode = true;
    
    public function __construct(
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly PageRenderer $pageRenderer,
        protected readonly PageRepository $pageRepository,
        protected readonly ExtensionConfiguration $extensionConfiguration
    ) {
        $this->extensionSettings = $this->extensionConfiguration->get('page_link_insights') ?? [];
        $this->debug('Extension settings loaded', $this->extensionSettings);
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

    protected function findLinksInContent(string $content): array 
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
        
        // Recherche des anciens liens typolink
        if (preg_match_all('/\b(?:t3:\/\/)?page,(\d+)(?:,|\s|$)/', $content, $matches)) {
            $this->debug('Found old typolink style links', $matches[1]);
            foreach ($matches[1] as $pageUid) {
                $links[] = (int)$pageUid;
            }
        }
        
        $uniqueLinks = array_unique($links);
        $this->debug('Final unique links found', $uniqueLinks);
        return $uniqueLinks;
    }

    protected function getContentLinks(array $pageUids): array
    {
        $this->debug('Starting getContentLinks', ['pageUids' => $pageUids]);
        
        $links = [];
        $allowedColPos = GeneralUtility::intExplode(',', $this->extensionSettings['colPosToAnalyze'] ?? '0,2', true);
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
            ->select('uid', 'pid', 'header', 'bodytext', 'CType', 'list_type', 'colPos', 'header_link')
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
            
            // Chercher dans le header (si c'est un lien)
            if ($content['header'] && $content['CType'] === 'header' && $content['header_link']) {
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
    
        $this->pageRenderer->addJsFile(
            'EXT:page_link_insights/Resources/Public/JavaScript/Vendor/d3.min.js',
        );
    
        $this->pageRenderer->addJsFile(
            'EXT:page_link_insights/Resources/Public/JavaScript/force-diagram.js',
        );
    
        // Construire les données pour le graphe
        $pages = $this->getPagesInSubtree($pageUid);
        $pageUids = array_column($pages, 'uid');
        $links = $this->getContentLinks($pageUids);
        $data = $this->buildGraphData($pages, $links);
    
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
}