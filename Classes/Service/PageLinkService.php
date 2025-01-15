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
    
        // Recherche des liens HTML standards avec id= ou page=
        if (preg_match_all('/<a[^>]+href=["\'](?:[^"\']*?)(?:id=|page=)(\d+)/', $content, $matches)) {
            foreach ($matches[1] as $pageUid) {
                $links[] = (int)$pageUid;
            }
        }
    
        return array_unique($links);
    }

    private function getPagesInSubtree(int $pageUid): array
    {
        // Récupérer la page racine
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new HiddenRestriction());
    
        $rootPage = $queryBuilder
            ->select('uid', 'title', 'pid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, \PDO::PARAM_INT))
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
            // Traitement spécial pour les menus et sitemaps
            if (str_starts_with($content['CType'], 'menu_') || $content['CType'] === 'sitemap') {
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

    private function getTypoLinks(array $pageUids): array {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $result = $queryBuilder
            ->select('uid', 'pid', 'header', 'bodytext')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->and(
                    $queryBuilder->expr()->in(
                        'pid',
                        $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)
                    ),
                    $queryBuilder->expr()->like('bodytext', $queryBuilder->createNamedParameter('%t3://page?uid=%'))
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $links = [];
        foreach ($result as $content) {
            preg_match_all('/t3:\/\/page\?uid=(\d+)/', $content['bodytext'], $matches);
            foreach ($matches[1] as $pageUid) {
                $links[] = [
                    'source' => $content['pid'],
                    'target' => (int)$pageUid,
                    'type' => 'typolink',
                    'element' => 'tt_content_' . $content['uid']
                ];
            }
        }

        return $links;
    }

    private function getMenuLinks(array $pageUids): array {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $result = $queryBuilder
            ->select('uid', 'pid', 'pages', 'CType')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->and(
                    $queryBuilder->expr()->in(
                        'pid',
                        $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)
                    ),
                    $queryBuilder->expr()->like('CType', $queryBuilder->createNamedParameter('menu_%', \PDO::PARAM_STR))
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $links = [];
        foreach ($result as $content) {
            if ($content['pages']) {
                $pageList = GeneralUtility::trimExplode(',', $content['pages'], true);
                foreach ($pageList as $pageUid) {
                    $links[] = [
                        'source' => $content['pid'],
                        'target' => (int)$pageUid,
                        'type' => 'menu',
                        'element' => 'tt_content_' . $content['uid']
                    ];
                }
            }
        }

        return $links;
    }

    // Les autres méthodes restent inchangées
    private function extractHtmlLinks(string $content): array {
        preg_match_all('/<a\s[^>]*href=([\'"])(.+?)\1[^>]*>/i', $content, $matches);
        return $matches[2] ?? [];
    }

    private function extractPageId(string $url): int {
        if (preg_match('/id=(\d+)/', $url, $matches)) {
            return (int)$matches[1];
        }
        if (preg_match('/\/page\/(\d+)/', $url, $matches)) {
            return (int)$matches[1];
        }
        return 0;
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
            'links' => $links // Les liens sont déjà au bon format
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