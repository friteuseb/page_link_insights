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
        // Récupérer toutes les pages dans l'arborescence
        $pages = $this->getPagesInSubtree($pageUid);
        $pageUids = array_column($pages, 'uid');
        
        if (empty($pageUids)) {
            return ['nodes' => [], 'links' => []];
        }

        // Récupérer tous les liens pour ces pages
        $links = [];
        $links = array_merge($links, $this->getContentElementLinks($pageUids));
        $links = array_merge($links, $this->getTypoLinks($pageUids));
        $links = array_merge($links, $this->getMenuLinks($pageUids));
        
        return $this->formatLinksForD3($links);
    }

    private function getPagesInSubtree(int $pageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new HiddenRestriction());

        // Récupérer la page racine
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

        // Récupérer toutes les sous-pages
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new HiddenRestriction());

        $subPages = $queryBuilder
            ->select('uid', 'title', 'pid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->like(
                    'pid_list',
                    $queryBuilder->createNamedParameter(
                        '%,' . $pageUid . ',%',
                        \PDO::PARAM_STR
                    )
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_merge([$rootPage], $subPages);
    }

    private function getContentElementLinks(array $pageUids): array {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new HiddenRestriction());

        $result = $queryBuilder
            ->select('uid', 'pid', 'header', 'bodytext', 'CType')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $links = [];
        foreach ($result as $content) {
            // Analyse du bodytext pour les liens HTML
            $htmlLinks = $this->extractHtmlLinks($content['bodytext']);
            foreach ($htmlLinks as $link) {
                $target = $this->extractPageId($link);
                if ($target > 0) {
                    $links[] = [
                        'source' => $content['pid'],
                        'target' => $target,
                        'type' => 'html',
                        'element' => 'tt_content_' . $content['uid']
                    ];
                }
            }
        }

        return $links;
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

    private function formatLinksForD3(array $links): array {
        $nodes = [];
        $formattedLinks = [];
        
        $pageIds = [];
        foreach ($links as $link) {
            $pageIds[] = $link['source'];
            $pageIds[] = $link['target'];
        }
        $pageIds = array_unique($pageIds);

        foreach ($pageIds as $pageId) {
            $nodes[] = [
                'id' => $pageId,
                'title' => $this->getPageTitle($pageId)
            ];
        }

        foreach ($links as $link) {
            $formattedLinks[] = [
                'source' => $link['source'],
                'target' => $link['target'],
                'type' => $link['type']
            ];
        }

        return [
            'nodes' => $nodes,
            'links' => $formattedLinks
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