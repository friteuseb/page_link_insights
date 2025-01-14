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

    protected function getPages(): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');
            
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            
        return $queryBuilder
            ->select('uid', 'pid', 'title')
            ->from('pages')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    protected function getContentLinks(): array
    {
        $links = [];
        
        // Récupérer les enregistrements de la table sys_refindex
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_refindex');
            
        $refs = $queryBuilder
            ->select('*')
            ->from('sys_refindex')
            ->where(
                $queryBuilder->expr()->eq('ref_table', $queryBuilder->createNamedParameter('pages')),
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter('tt_content'))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        // Récupérer les détails des éléments de contenu référencés
        $contentElements = [];
        if (!empty($refs)) {
            $contentUids = array_unique(array_column($refs, 'recuid'));
            
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable('tt_content');
                
            $contentElements = $queryBuilder
                ->select('uid', 'pid', 'header', 'CType', 'list_type')
                ->from('tt_content')
                ->where(
                    $queryBuilder->expr()->in(
                        'uid',
                        $queryBuilder->createNamedParameter($contentUids, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                    )
                )
                ->executeQuery()
                ->fetchAllAssociative();
                
            $contentElements = array_column($contentElements, null, 'uid');
        }

        // Construction du tableau des liens
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

        return $links;
    }

    protected function buildGraphData(array $pages, array $links): array
    {
        // Créer un mapping des UIDs de pages vers leurs titres
        $pageTitles = array_column($pages, 'title', 'uid');
        
        // Collecter uniquement les pages qui ont des liens
        $usedPageIds = [];
        foreach ($links as $link) {
            $usedPageIds[] = $link['sourcePageId'];
            $usedPageIds[] = $link['targetPageId'];
        }
        $usedPageIds = array_unique($usedPageIds);
        
        // Créer les nœuds uniquement pour les pages utilisées
        $nodes = [];
        foreach ($usedPageIds as $pageId) {
            $nodes[] = [
                'id' => (string)$pageId,
                'title' => $pageTitles[$pageId] ?? "Page $pageId"
            ];
        }
        
        // Convertir les liens au format attendu par D3
        $d3Links = array_map(function($link) {
            return [
                'source' => $link['sourcePageId'],
                'target' => $link['targetPageId'],
                'contentElement' => $link['contentElement']
            ];
        }, $links);

        return [
            'nodes' => $nodes,
            'links' => $d3Links
        ];
    }

    public function mainAction(): ResponseInterface
    {
        $this->pageRenderer->addJsFile(
            'EXT:page_link_insights/Resources/Public/JavaScript/Vendor/d3.min.js',
        );
    
        $this->pageRenderer->addJsFile(
            'EXT:page_link_insights/Resources/Public/JavaScript/force-diagram.js',
        );
    
        $pages = $this->getPages();
        $links = $this->getContentLinks();
        $data = $this->buildGraphData($pages, $links);

        // Debug
        // echo '<pre>' . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) . '</pre>';
        // die();
    
        $this->view->assign('data', json_encode($data));
    
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setContent($this->view->render());
        
        return $this->htmlResponse($moduleTemplate->renderContent());
    }
}