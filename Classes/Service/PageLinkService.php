<?php
declare(strict_types=1);

namespace Cwolf\PageLinkInsights\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Doctrine\DBAL\ParameterType;

class PageLinkService
{
    private ConnectionPool $connectionPool;

    public function __construct()
    {
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
    }

    public function getPageLinksForSubtree(int $pageUid): array
    {
        error_log("Starting getPageLinksForSubtree with pageUid: $pageUid");
        
        // Get initial pages in the tree
        $pages = $this->getPageTreeInfo($pageUid);
        error_log("Found initial pages: " . json_encode($pages));
        
        if (empty($pages)) {
            return ['nodes' => [], 'links' => []];
        }

        $pageUids = array_column($pages, 'uid');
        $links = $this->getContentElementLinks($pageUids);
        
        // Collect all page UIDs referenced in links
        $referencedPageIds = [];
        foreach ($links as $link) {
            $referencedPageIds[] = $link['sourcePageId'];
            $referencedPageIds[] = $link['targetPageId'];
        }
        $referencedPageIds = array_unique($referencedPageIds);
        
        // Get additional page information for referenced pages not in the tree
        $missingPageIds = array_diff($referencedPageIds, $pageUids);
        if (!empty($missingPageIds)) {
            $additionalPages = $this->getAdditionalPagesInfo($missingPageIds);
            $pages = array_merge($pages, $additionalPages);
        }

        error_log("Generated links: " . json_encode($links));
        error_log("Final pages: " . json_encode($pages));

        return [
            'nodes' => array_values(array_map(fn($page) => [
                'id' => (string)$page['uid'],
                'title' => $page['title']
            ], $pages)),
            'links' => $links
        ];
    }

    private function getPageTreeInfo(int $rootPageId): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());

        return $queryBuilder
            ->select('uid', 'pid', 'title', 'doktype')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('uid', 
                        $queryBuilder->createNamedParameter($rootPageId, ParameterType::INTEGER)
                    ),
                    $queryBuilder->expr()->eq('pid', 
                        $queryBuilder->createNamedParameter($rootPageId, ParameterType::INTEGER)
                    )
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function getAdditionalPagesInfo(array $pageIds): array
    {
        if (empty($pageIds)) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());

        return $queryBuilder
            ->select('uid', 'pid', 'title', 'doktype')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter(
                        array_map('intval', $pageIds),
                        \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY
                    )
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function getContentElementLinks(array $pageUids): array
    {
        $links = [];
        
        if (empty($pageUids)) {
            return $links;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());

        $contentElements = $queryBuilder
            ->select('uid', 'pid', 'CType', 'header', 'bodytext', 'header_link', 'pages')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($pageUids, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        error_log("Found content elements: " . json_encode($contentElements));

        foreach ($contentElements as $content) {
            // Process text body
            if (!empty($content['bodytext'])) {
                $this->processTextLinks($content['bodytext'], $content, $links);
            }

            // Process header links
            if (!empty($content['header_link'])) {
                $this->processTextLinks($content['header_link'], $content, $links);
            }

            // Process menu elements
            if (str_starts_with($content['CType'], 'menu_') && !empty($content['pages'])) {
                $this->processMenuLinks($content, $links);
            }
        }

        return $links;
    }

    private function processTextLinks(string $content, array $element, array &$links): void
    {
        // Search for t3://page links
        if (preg_match_all('/t3:\/\/page\?uid=(\d+)/', $content, $matches)) {
            foreach ($matches[1] as $pageUid) {
                $links[] = [
                    'sourcePageId' => (string)$element['pid'],
                    'targetPageId' => (string)$pageUid,
                    'contentElement' => [
                        'uid' => $element['uid'],
                        'type' => $element['CType'],
                        'header' => $element['header']
                    ]
                ];
            }
        }

        // Search for <link> tags
        if (preg_match_all('/<link (\d+)>/', $content, $matches)) {
            foreach ($matches[1] as $pageUid) {
                $links[] = [
                    'sourcePageId' => (string)$element['pid'],
                    'targetPageId' => (string)$pageUid,
                    'contentElement' => [
                        'uid' => $element['uid'],
                        'type' => $element['CType'],
                        'header' => $element['header']
                    ]
                ];
            }
        }
        
        // Search for legacy typolinks
        if (preg_match_all('/\b(?:t3:\/\/)?page,(\d+)(?:,|\s|$)/', $content, $matches)) {
            foreach ($matches[1] as $pageUid) {
                $links[] = [
                    'sourcePageId' => (string)$element['pid'],
                    'targetPageId' => (string)$pageUid,
                    'contentElement' => [
                        'uid' => $element['uid'],
                        'type' => $element['CType'],
                        'header' => $element['header']
                    ]
                ];
            }
        }
    }

    private function processMenuLinks(array $content, array &$links): void
    {
        $pages = GeneralUtility::intExplode(',', $content['pages'], true);
        foreach ($pages as $pageUid) {
            $links[] = [
                'sourcePageId' => (string)$content['pid'],
                'targetPageId' => (string)$pageUid,
                'contentElement' => [
                    'uid' => $content['uid'],
                    'type' => $content['CType'],
                    'header' => $content['header']
                ]
            ];
        }
    }
}