<?php

declare(strict_types=1);

namespace Cywolf\PageLinkInsights\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Reads page-to-page references from sys_refindex.
 *
 * The reference index is the only place where TYPO3 records *every* typolink it
 * knows about, whichever table and field it lives in: RTE links in bodytext,
 * header_link, FlexForm values, the "pages" field of menu elements, page
 * shortcuts, and link fields declared by third-party extensions. Reading it
 * replaces the previous approach of running regular expressions over every
 * string column of tt_content, which both missed everything outside bodytext
 * and matched any bare number that happened to be an existing page uid.
 */
class ReferenceIndexLinkProvider
{
    /**
     * Source tables that hold backend configuration or taxonomy rather than
     * editorial content. A reference from these is not a link a visitor can follow.
     */
    private const EXCLUDED_SOURCE_TABLES = [
        'be_groups',
        'be_users',
        'sys_category',
        'sys_filemounts',
        'sys_file_metadata',
        'sys_file_reference',
    ];

    /**
     * Fields that point at a page for structural reasons (translation handling,
     * versioning) and do not represent a navigable link.
     */
    private const EXCLUDED_FIELDS = [
        'l10n_parent',
        'l18n_parent',
        't3ver_oid',
    ];

    /**
     * Menu content types whose "pages" reference is expanded into links towards
     * the referenced page's children instead of the page itself, because that is
     * what the menu actually renders. PageLinkService owns that expansion, so the
     * raw reference is skipped here to avoid counting the relation twice.
     */
    public const EXPANDED_MENU_CTYPES = [
        'menu_subpages',
        'menu_card_dir',
        'menu_card_list',
        'menu_sitemap',
        'menu_sitemap_pages',
    ];

    private ConnectionPool $connectionPool;

    public function __construct()
    {
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
    }

    /**
     * Whether the reference index holds anything at all.
     *
     * An empty index means the site has never run "referenceindex:update"; every
     * diagram would come out empty and the module should say so rather than
     * pretending the site has no internal links.
     */
    public function isPopulated(): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('hash')
            ->from('sys_refindex')
            ->executeQuery()
            ->fetchOne() > 0;
    }

    /**
     * Collect every reference to a page emitted by a record living on one of the
     * given pages.
     *
     * @param int[] $pageUids Pages of the analysed subtree
     * @param int[] $allowedColPos Column positions to analyse (tt_content only)
     * @param int $languageId Language whose records are analysed
     * @return array<int, array{sourcePageUid: int, targetPageUid: int, sourceTable: string, sourceUid: int, sourceField: string, contentElement: array{uid: int, type: string, header: string, colPos: int}}>
     */
    public function getReferences(array $pageUids, array $allowedColPos, bool $includeHidden, int $languageId = 0): array
    {
        if ($pageUids === []) {
            return [];
        }

        $references = [];
        foreach ($this->getSourceTables() as $table) {
            $references = array_merge(
                $references,
                $table === 'pages'
                    ? $this->getReferencesFromPages($pageUids)
                    : $this->getReferencesFromRecords($table, $pageUids, $allowedColPos, $includeHidden, $languageId)
            );
        }

        return $this->normaliseTargetsToDefaultLanguage($references);
    }

    /**
     * A typolink may point at a translated page record. Nodes are keyed by the
     * default-language uid, so such targets are folded back onto their original
     * or they would dangle against a graph that has no node for them.
     */
    private function normaliseTargetsToDefaultLanguage(array $references): array
    {
        $targets = array_unique(array_column($references, 'targetPageUid'));
        if ($targets === []) {
            return $references;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid', 'l10n_parent')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($targets, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->gt('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('l10n_parent', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        if ($rows === []) {
            return $references;
        }

        $originalOf = [];
        foreach ($rows as $row) {
            $originalOf[(int)$row['uid']] = (int)$row['l10n_parent'];
        }

        foreach ($references as &$reference) {
            $target = $reference['targetPageUid'];
            if (isset($originalOf[$target])) {
                $reference['targetPageUid'] = $originalOf[$target];
            }
        }

        return $references;
    }

    /**
     * Distinct tables that currently reference a page, minus the ones we never
     * want to walk. Keeping this dynamic means link fields added by any
     * extension are picked up without this class knowing about them.
     *
     * @return string[]
     */
    private function getSourceTables(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('tablename')
            ->from('sys_refindex')
            ->where(
                $queryBuilder->expr()->eq('ref_table', $queryBuilder->createNamedParameter('pages')),
                $queryBuilder->expr()->eq('workspace', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->groupBy('tablename')
            ->executeQuery()
            ->fetchAllAssociative();

        $tables = [];
        foreach ($rows as $row) {
            $table = (string)$row['tablename'];
            if (in_array($table, self::EXCLUDED_SOURCE_TABLES, true)) {
                continue;
            }
            if ($table !== 'pages' && !isset($GLOBALS['TCA'][$table])) {
                continue;
            }
            $tables[] = $table;
        }

        return $tables;
    }

    /**
     * References emitted by a page record itself, such as a shortcut target.
     * The source page is the referencing record.
     *
     * @param int[] $pageUids
     */
    private function getReferencesFromPages(array $pageUids): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('recuid', 'field', 'ref_uid')
            ->from('sys_refindex')
            ->where(
                $queryBuilder->expr()->eq('ref_table', $queryBuilder->createNamedParameter('pages')),
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter('pages')),
                $queryBuilder->expr()->eq('workspace', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->notIn('field', $queryBuilder->createNamedParameter(self::EXCLUDED_FIELDS, Connection::PARAM_STR_ARRAY)),
                $queryBuilder->expr()->in('recuid', $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $references = [];
        foreach ($rows as $row) {
            $sourceUid = (int)$row['recuid'];
            $references[] = [
                'sourcePageUid' => $sourceUid,
                'targetPageUid' => (int)$row['ref_uid'],
                'sourceTable' => 'pages',
                'sourceUid' => $sourceUid,
                'sourceField' => (string)$row['field'],
                'contentElement' => [
                    'uid' => 0,
                    'type' => 'page_property',
                    'header' => (string)$row['field'],
                    'colPos' => -1,
                ],
            ];
        }

        return $references;
    }

    /**
     * References emitted by records of a content table. The source page is the
     * record's pid, so the join also gives us the metadata the diagram shows in
     * tooltips and the colPos the extension configuration restricts analysis to.
     *
     * @param int[] $pageUids
     * @param int[] $allowedColPos
     */
    private function getReferencesFromRecords(string $table, array $pageUids, array $allowedColPos, bool $includeHidden, int $languageId = 0): array
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        $hasColPos = isset($GLOBALS['TCA'][$table]['columns']['colPos']);
        $hasHeader = isset($GLOBALS['TCA'][$table]['columns']['header']);
        $hasCType = isset($GLOBALS['TCA'][$table]['columns']['CType']);
        $deletedField = $ctrl['delete'] ?? null;
        $disabledField = $ctrl['enablecolumns']['disabled'] ?? null;
        $languageField = $ctrl['languageField'] ?? null;

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $queryBuilder->getRestrictions()->removeAll();

        $queryBuilder
            ->select('r.recuid', 'r.field', 'r.ref_uid', 's.pid')
            ->from('sys_refindex', 'r')
            ->innerJoin('r', $table, 's', 's.uid = r.recuid')
            ->where(
                $queryBuilder->expr()->eq('r.ref_table', $queryBuilder->createNamedParameter('pages')),
                $queryBuilder->expr()->eq('r.tablename', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('r.workspace', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->notIn('r.field', $queryBuilder->createNamedParameter(self::EXCLUDED_FIELDS, Connection::PARAM_STR_ARRAY)),
                $queryBuilder->expr()->in('s.pid', $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY))
            );

        if ($hasColPos && $allowedColPos !== []) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->in('s.colPos', $queryBuilder->createNamedParameter($allowedColPos, Connection::PARAM_INT_ARRAY))
            );
        }
        if ($deletedField !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('s.' . $deletedField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            );
        }
        if ($disabledField !== null && !$includeHidden) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('s.' . $disabledField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            );
        }
        if ($languageField !== null) {
            // Only the analysed language, plus records marked "all languages"
            // (-1). Mixing translations in would duplicate every link of their
            // originals and blend languages the viewer did not ask for.
            $queryBuilder->andWhere(
                $queryBuilder->expr()->in(
                    's.' . $languageField,
                    $queryBuilder->createNamedParameter([$languageId, -1], Connection::PARAM_INT_ARRAY)
                )
            );
        }

        if ($hasCType) {
            $queryBuilder->addSelect('s.CType');
        }
        if ($hasHeader) {
            $queryBuilder->addSelect('s.header');
        }
        if ($hasColPos) {
            $queryBuilder->addSelect('s.colPos');
        }

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        $references = [];
        foreach ($rows as $row) {
            $type = (string)($row['CType'] ?? $table);
            $field = (string)$row['field'];

            // Menus are expanded by PageLinkService against the referenced page's
            // children; linking to the referenced page itself would double count.
            if ($field === 'pages' && in_array($type, self::EXPANDED_MENU_CTYPES, true)) {
                continue;
            }

            $references[] = [
                'sourcePageUid' => (int)$row['pid'],
                'targetPageUid' => (int)$row['ref_uid'],
                'sourceTable' => $table,
                'sourceUid' => (int)$row['recuid'],
                'sourceField' => $field,
                'contentElement' => [
                    'uid' => (int)$row['recuid'],
                    'type' => $type,
                    'header' => (string)($row['header'] ?? ''),
                    'colPos' => (int)($row['colPos'] ?? -1),
                ],
            ];
        }

        return $references;
    }
}
