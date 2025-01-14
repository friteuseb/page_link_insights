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
use TYPO3\CMS\Backend\Utility\BackendUtility;

class BackendController extends ActionController
{
    protected PageRepository $pageRepository;
    
    public function __construct(
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly PageRenderer $pageRenderer,
        PageRepository $pageRepository
    ) {
        $this->pageRepository = $pageRepository;
    }

    protected function getPagesInSubtree(int $pageUid): array
    {
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

        // Récupérer toutes les sous-pages
        $subPages = $queryBuilder
            ->select('uid', 'title', 'pid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $allPages = [$currentPage];
        
        // Récupérer récursivement les sous-pages
        foreach ($subPages as $subPage) {
            $allPages[] = $subPage;
            $allPages = array_merge($allPages, $this->getPagesInSubtree($subPage['uid']));
        }

        return $allPages;
    }

    protected function getContentLinks(array $pageUids): array
    {
        $links = [];
        
        // Récupérer d'abord tous les éléments de contenu des pages sélectionnées
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
            
        $contentElements = $queryBuilder
            ->select('uid', 'pid', 'header', 'CType', 'list_type')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($pageUids, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        if (!empty($contentElements)) {
            $contentUids = array_column($contentElements, 'uid');
            $contentElements = array_column($contentElements, null, 'uid');

            // Maintenant récupérer les références pour ces éléments de contenu
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable('sys_refindex');
                
            $refs = $queryBuilder
                ->select('*')
                ->from('sys_refindex')
                ->where(
                    $queryBuilder->expr()->eq('ref_table', $queryBuilder->createNamedParameter('pages')),
                    $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter('tt_content')),
                    $queryBuilder->expr()->in(
                        'recuid',
                        $queryBuilder->createNamedParameter($contentUids, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                    )
                )
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($refs as $ref) {
                if (isset($contentElements[$ref['recuid']])) {
                    $content = $contentElements[$ref['recuid']];
                    $links[] = [
                        'sourcePageId' => (string)$content['pid'],
                        'targetPageId' => (string)$ref['ref_uid'],
                        'contentElement' => [
                            'uid' => $content['uid'],
                            'type' => $content['CType'],
                            'header' => $content['header'],
                            'plugin' => $content['list_type'] ?? null,
                            'field' => $ref['field']
                        ]
                    ];
                }
            }
        }

        return $links;
    }

    protected function buildGraphData(array $pages, array $links): array
    {
        // Créer un mapping des UIDs de pages vers leurs titres
        $pageTitles = array_column($pages, 'title', 'uid');
        
        // Collecter toutes les pages impliquées (source et cible des liens)
        $usedPageIds = [];
        foreach ($links as $link) {
            $usedPageIds[] = $link['sourcePageId'];
            $usedPageIds[] = $link['targetPageId'];
        }
        $usedPageIds = array_unique($usedPageIds);
        
        // Créer les nœuds
        $nodes = [];
        foreach ($usedPageIds as $pageId) {
            $nodes[] = [
                'id' => (string)$pageId,
                'title' => $pageTitles[$pageId] ?? "Page $pageId"
            ];
        }
        
        return [
            'nodes' => $nodes,
            'links' => array_map(function($link) {
                return [
                    'source' => $link['sourcePageId'],
                    'target' => $link['targetPageId'],
                    'contentElement' => $link['contentElement']
                ];
            }, $links)
        ];
    }

    public function mainAction(): ResponseInterface
    {
        // Récupérer la page sélectionnée dans le page tree
        $pageUid = (int)($this->request->getQueryParams()['id'] ?? 0);
        
        if ($pageUid === 0) {
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
    
        // Récupérer la page et ses sous-pages
        $pages = $this->getPagesInSubtree($pageUid);
        $pageUids = array_column($pages, 'uid');
        
        // Debug
        // echo '<pre>' . htmlspecialchars(print_r(['pages' => $pages, 'uids' => $pageUids], true)) . '</pre>';
        
        // Récupérer les liens pour ces pages
        $links = $this->getContentLinks($pageUids);
        $data = $this->buildGraphData($pages, $links);

        // Debug
        // echo '<pre>' . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) . '</pre>';
        // die();
    
        $this->view->assign('data', json_encode($data));
        $this->view->assign('noPageSelected', false);
    
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setContent($this->view->render());
        
        return $this->htmlResponse($moduleTemplate->renderContent());
    }
}