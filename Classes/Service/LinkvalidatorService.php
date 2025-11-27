<?php

declare(strict_types=1);

namespace Cywolf\PageLinkInsights\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service to integrate with TYPO3's linkvalidator extension
 * for enhanced broken link detection.
 */
class LinkvalidatorService
{
    private const TABLE = 'tx_linkvalidator_link';

    public function __construct(
        private readonly ConnectionPool $connectionPool
    ) {}

    /**
     * Check if linkvalidator extension is available
     */
    public function isAvailable(): bool
    {
        return ExtensionManagementUtility::isLoaded('linkvalidator');
    }

    /**
     * Check if linkvalidator has data (scheduler has been run)
     */
    public function hasData(): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $count = $queryBuilder
                ->count('uid')
                ->from(self::TABLE)
                ->executeQuery()
                ->fetchOne();

            return (int)$count > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get all broken links for a list of page UIDs
     * Returns an array of broken link targets (page UIDs)
     *
     * @param array $pageUids List of page UIDs to check
     * @return array Array of broken internal link data ['sourcePageId' => [...targetPageIds]]
     */
    public function getBrokenLinksForPages(array $pageUids): array
    {
        if (!$this->isAvailable() || empty($pageUids)) {
            return [];
        }

        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

            $result = $queryBuilder
                ->select('record_pid', 'url', 'field', 'link_type', 'url_response')
                ->from(self::TABLE)
                ->where(
                    $queryBuilder->expr()->in(
                        'record_pid',
                        $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)
                    ),
                    // Only internal page links
                    $queryBuilder->expr()->eq(
                        'link_type',
                        $queryBuilder->createNamedParameter('db')
                    )
                )
                ->executeQuery();

            $brokenLinks = [];
            while ($row = $result->fetchAssociative()) {
                $targetPageId = $this->extractPageIdFromUrl($row['url']);
                if ($targetPageId !== null) {
                    $sourcePageId = (int)$row['record_pid'];
                    if (!isset($brokenLinks[$sourcePageId])) {
                        $brokenLinks[$sourcePageId] = [];
                    }
                    $brokenLinks[$sourcePageId][] = [
                        'targetPageId' => $targetPageId,
                        'field' => $row['field'],
                        'url' => $row['url'],
                        'response' => $row['url_response'] ? json_decode($row['url_response'], true) : null
                    ];
                }
            }

            return $brokenLinks;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Check if a specific link is marked as broken in linkvalidator
     *
     * @param int $sourcePageId Source page UID
     * @param int $targetPageId Target page UID
     * @return bool|null True if broken, false if valid, null if not in linkvalidator data
     */
    public function isLinkBroken(int $sourcePageId, int $targetPageId): ?bool
    {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

            // Build possible URL patterns for the target page
            $urlPatterns = [
                't3://page?uid=' . $targetPageId,
                (string)$targetPageId,
            ];

            $orConditions = [];
            foreach ($urlPatterns as $pattern) {
                $orConditions[] = $queryBuilder->expr()->eq(
                    'url',
                    $queryBuilder->createNamedParameter($pattern)
                );
                $orConditions[] = $queryBuilder->expr()->like(
                    'url',
                    $queryBuilder->createNamedParameter($pattern . '%')
                );
            }

            $count = $queryBuilder
                ->count('uid')
                ->from(self::TABLE)
                ->where(
                    $queryBuilder->expr()->eq(
                        'record_pid',
                        $queryBuilder->createNamedParameter($sourcePageId, Connection::PARAM_INT)
                    ),
                    $queryBuilder->expr()->eq(
                        'link_type',
                        $queryBuilder->createNamedParameter('db')
                    ),
                    $queryBuilder->expr()->or(...$orConditions)
                )
                ->executeQuery()
                ->fetchOne();

            // If found in broken links table, it's broken
            // If not found, we can't determine from linkvalidator (return null)
            return (int)$count > 0 ? true : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get a set of all broken target page IDs from linkvalidator
     * This is useful for quick lookup
     *
     * @param array $pageUids Limit to links originating from these pages
     * @return array Set of broken target page IDs
     */
    public function getBrokenTargetPageIds(array $pageUids = []): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

            $queryBuilder
                ->select('url')
                ->from(self::TABLE)
                ->where(
                    $queryBuilder->expr()->eq(
                        'link_type',
                        $queryBuilder->createNamedParameter('db')
                    )
                );

            if (!empty($pageUids)) {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->in(
                        'record_pid',
                        $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)
                    )
                );
            }

            $result = $queryBuilder->executeQuery();

            $brokenPageIds = [];
            while ($row = $result->fetchAssociative()) {
                $pageId = $this->extractPageIdFromUrl($row['url']);
                if ($pageId !== null) {
                    $brokenPageIds[$pageId] = true;
                }
            }

            return array_keys($brokenPageIds);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Extract page ID from linkvalidator URL format
     * Handles formats like: t3://page?uid=123, t3://page?uid=123#c456, 123
     *
     * @param string $url The URL from linkvalidator
     * @return int|null The page ID or null if not parseable
     */
    private function extractPageIdFromUrl(string $url): ?int
    {
        // Format: t3://page?uid=123 or t3://page?uid=123#c456 or t3://page?uid=123&_language=1
        if (preg_match('/t3:\/\/page\?uid=(\d+)/', $url, $matches)) {
            return (int)$matches[1];
        }

        // Format: just a number (legacy or simple format)
        if (preg_match('/^(\d+)$/', $url, $matches)) {
            return (int)$matches[1];
        }

        // Format: record:pages:123
        if (preg_match('/record:pages:(\d+)/', $url, $matches)) {
            return (int)$matches[1];
        }

        return null;
    }

    /**
     * Get statistics about broken links from linkvalidator
     *
     * @param array $pageUids Limit to these pages
     * @return array Statistics array with counts
     */
    public function getStatistics(array $pageUids = []): array
    {
        if (!$this->isAvailable()) {
            return [
                'available' => false,
                'totalBrokenLinks' => 0,
                'brokenInternalLinks' => 0,
                'brokenExternalLinks' => 0,
                'brokenFileLinks' => 0,
            ];
        }

        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

            $queryBuilder
                ->select('link_type')
                ->addSelectLiteral('COUNT(*) as count')
                ->from(self::TABLE)
                ->groupBy('link_type');

            if (!empty($pageUids)) {
                $queryBuilder->where(
                    $queryBuilder->expr()->in(
                        'record_pid',
                        $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)
                    )
                );
            }

            $result = $queryBuilder->executeQuery();

            $stats = [
                'available' => true,
                'totalBrokenLinks' => 0,
                'brokenInternalLinks' => 0,
                'brokenExternalLinks' => 0,
                'brokenFileLinks' => 0,
            ];

            while ($row = $result->fetchAssociative()) {
                $count = (int)$row['count'];
                $stats['totalBrokenLinks'] += $count;

                switch ($row['link_type']) {
                    case 'db':
                        $stats['brokenInternalLinks'] = $count;
                        break;
                    case 'external':
                        $stats['brokenExternalLinks'] = $count;
                        break;
                    case 'file':
                        $stats['brokenFileLinks'] = $count;
                        break;
                }
            }

            return $stats;
        } catch (\Exception $e) {
            return [
                'available' => false,
                'totalBrokenLinks' => 0,
                'brokenInternalLinks' => 0,
                'brokenExternalLinks' => 0,
                'brokenFileLinks' => 0,
            ];
        }
    }
}
