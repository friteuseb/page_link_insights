<?php

namespace Cwolf\PageLinkInsights\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Database\Connection;

class PageLinkService {
    public function __construct(
        private readonly ConnectionPool $connectionPool
    ) {}

    public function getPageLinksForSubtree(int $pageUid): array
    {
        $pages = $this->getPagesInSubtree($pageUid);
        if (empty($pages)) {
            return ['nodes' => [], 'links' => []];
        }
    
        $pageUids = array_column($pages, 'uid');
        $links = $this->getContentElementLinks($pageUids);
    
        return $this->formatLinksForD3($links);
    }

    private function findLinksInContent(string $content): array 
    {
        $links = [];
        
        // Recherche des liens t3://page
        if (preg_match_all('/t3:\/\/page\?uid=(\d+)/', $content, $matches)) {
            foreach ($matches[1] as $pageUid) {
                $links[] = (int)$pageUid;
            }
        }
        
        // Recherche des liens <link>
        if (preg_match_all('/<link (\d+)>/', $content, $matches)) {
            foreach ($matches[1] as $pageUid) {
                $links[] = (int)$pageUid;
            }
        }
        
        // Recherche des liens de type "record:pages:UID"
        if (preg_match_all('/record:pages:(\d+)/', $content, $matches)) {
            foreach ($matches[1] as $pageUid) {
                $links[] = (int)$pageUid;
            }
        }
    
        // Recherche des anciens liens typolink
        if (preg_match_all('/\b(?:t3:\/\/)?page,(\d+)(?:,|\s|$)/', $content, $matches)) {
            foreach ($matches[1] as $pageUid) {
                $links[] = (int)$pageUid;
            }
        }
    
        return array_unique($links);
    }

    private function getPagesInSubtree(int $pageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new HiddenRestriction());

        $rootPage = $queryBuilder
            ->select('uid', 'title', 'pid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', 
                    $queryBuilder->createNamedParameter($pageUid, \PDO::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!$rootPage) {
            return [];
        }

        // Récupérer toutes les sous-pages récursivement
        $allPages = [$rootPage];
        $pagesToProcess = [$pageUid];
        
        while (!empty($pagesToProcess)) {
            $currentPageUids = $pagesToProcess;
            $pagesToProcess = [];

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()
                ->removeAll()
                ->add(new DeletedRestriction())
                ->add(new HiddenRestriction());
                
            $subPages = $queryBuilder
                ->select('uid', 'title', 'pid')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->in(
                        'pid',
                        $queryBuilder->createNamedParameter($currentPageUids, Connection::PARAM_INT_ARRAY)
                    )
                )
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($subPages as $subPage) {
                $allPages[] = $subPage;
                $pagesToProcess[] = $subPage['uid'];
            }
        }

        return $allPages;
    }


    private function getContentElementLinks(array $pageUids): array 
    {
        $links = [];
        $allowedColPos = GeneralUtility::intExplode(',', '0,1,2,3,4', true);
    
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new HiddenRestriction());
    
        $contentElements = $queryBuilder
            ->select('uid', 'pid', 'header', 'bodytext', 'CType', 'list_type', 
                    'colPos', 'header_link', 'pages', 'selected_categories')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->in(
                    'colPos',
                    $queryBuilder->createNamedParameter($allowedColPos, Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();
    
        foreach ($contentElements as $content) {
            error_log('Processing content element: ' . $content['uid'] . ' of type ' . $content['CType']);
            
            // Traitement spécial pour les menus
            if (str_starts_with($content['CType'], 'menu_')) {
                $targetPages = [];
                
                switch ($content['CType']) {
                    case 'menu_subpages':
                    case 'menu_card_dir':
                        if (!empty($content['pages'])) {
                            $parentPageUid = (int)$content['pages'];
                            $subPages = $this->getSubPages($parentPageUid);
                            $targetPages = array_column($subPages, 'uid');
                        }
                        break;
                        
                    case 'menu_sitemap':
                    case 'menu_sitemap_pages':
                        // Pour un sitemap, nous récupérons toutes les pages depuis la racine
                        $contentPid = (int)$content['pid'];
                        error_log('Processing sitemap on page: ' . $contentPid);
                        
                        $rootLine = $this->getRootLine($contentPid);
                        error_log('RootLine: ' . print_r($rootLine, true));
                        
                        $rootPageUid = $rootLine[0]['uid'] ?? $contentPid;
                        error_log('Root page UID: ' . $rootPageUid);
                        
                        $allPages = $this->getAllPagesFromRoot($rootPageUid);
                        error_log('Found pages: ' . count($allPages));
                        error_log('Page UIDs: ' . implode(', ', array_column($allPages, 'uid')));
                        
                        $targetPages = array_column($allPages, 'uid');
                        
                        // Exclure la page source des cibles pour éviter les auto-références
                        $targetPages = array_filter($targetPages, function($uid) use ($contentPid) {
                            return $uid != $contentPid;
                        });
                        
                        error_log('Final target pages: ' . implode(', ', $targetPages));
                        break;
                        
                    default:
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
                foreach ($this->findLinksInContent($content['bodytext']) as $targetPageUid) {
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
                foreach ($this->findLinksInContent($content['header_link']) as $targetPageUid) {
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
    
        return $links;
    }


    /**
     * Récupère le chemin complet jusqu'à la racine pour une page donnée
     */
    private function getRootLine(int $pageUid): array
    {
        $rootLine = [];
        $currentPage = $pageUid;

        while ($currentPage > 0) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()
                ->removeAll()
                ->add(new DeletedRestriction())
                ->add(new HiddenRestriction());

            $page = $queryBuilder
                ->select('uid', 'pid', 'title', 'doktype')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq(
                        'uid',
                        $queryBuilder->createNamedParameter($currentPage, \PDO::PARAM_INT)
                    )
                )
                ->executeQuery()
                ->fetchAssociative();

            if ($page) {
                $rootLine[] = $page;
                $currentPage = $page['pid'];
                
                // Si on atteint une page racine (doktype=1), on s'arrête
                if ($page['doktype'] === 1) {
                    break;
                }
            } else {
                break;
            }
        }

        return array_reverse($rootLine);
    }

    /**
     * Récupère toutes les pages du site à partir d'une page racine
     */
    private function getAllPagesFromRoot(int $rootPageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new HiddenRestriction());

        // Commencer par récupérer la page racine
        $rootPage = $queryBuilder
            ->select('uid', 'title', 'pid', 'doktype')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($rootPageUid, \PDO::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!$rootPage) {
            return [];
        }

        // Récupérer récursivement toutes les sous-pages
        $allPages = [$rootPage];
        $pagesToProcess = [$rootPageUid];

        while (!empty($pagesToProcess)) {
            $currentPageUids = $pagesToProcess;
            $pagesToProcess = [];

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()
                ->removeAll()
                ->add(new DeletedRestriction())
                ->add(new HiddenRestriction());

            $subPages = $queryBuilder
                ->select('uid', 'title', 'pid', 'doktype')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->in(
                        'pid',
                        $queryBuilder->createNamedParameter($currentPageUids, Connection::PARAM_INT_ARRAY)
                    )
                )
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($subPages as $subPage) {
                // Ne pas inclure les pages de type shortcut, link, etc.
                if ($subPage['doktype'] <= 4) {
                    $allPages[] = $subPage;
                    $pagesToProcess[] = $subPage['uid'];
                }
            }
        }

        return $allPages;
    }

    private function getSubPages(int $pageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());
    
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

    private function formatLinksForD3(array $links): array
    {
        $nodes = [];
        $allPageIds = [];
        foreach ($links as $link) {
            $allPageIds[] = $link['sourcePageId'];
            $allPageIds[] = $link['targetPageId'];
        }
        $allPageIds = array_unique($allPageIds);

        // Créer les nœuds
        foreach ($allPageIds as $pageId) {
            $nodes[] = [
                'id' => $pageId,
                'title' => $this->getPageTitle((int)$pageId)
            ];
        }

        return [
            'nodes' => $nodes,
            'links' => $links
        ];
    }

    private function getPageTitle(int $pageId): string {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $page = $queryBuilder
            ->select('title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, \PDO::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAssociative();

        return $page['title'] ?? 'Page ' . $pageId;
    }
}