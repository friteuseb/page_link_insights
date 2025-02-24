<?php

namespace Cywolf\PageLinkInsights\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use Psr\Log\LoggerInterface;
use Cywolf\NlpTools\Service\TextAnalysisService;
use Cywolf\NlpTools\Service\LanguageDetectionService;

class ThemeDataService {
    private ConnectionPool $connectionPool;
    private LoggerInterface $logger;
    private CacheManager $cacheManager;
    private TextAnalysisService $textAnalyzer;
    private LanguageDetectionService $languageDetector;
    
    public function __construct() {
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $this->logger = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)->getLogger(__CLASS__);
        $this->cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $this->textAnalyzer = GeneralUtility::makeInstance(TextAnalysisService::class);
        $this->languageDetector = GeneralUtility::makeInstance(LanguageDetectionService::class);
    }

    private function extractSignificantTerms(string $content, int $pageId): array {
        try {
            // Détecter la langue du contenu
            $language = $this->languageDetector->detectLanguage($content);
            
            // Nettoyer et prétraiter le texte
            $cleanedContent = preg_replace('/[^\p{L}\s]/u', ' ', $content);
            $cleanedContent = preg_replace('/\s+/', ' ', $cleanedContent);
            
            // Tokenizer et enlever les stop words
            $processedContent = $this->textAnalyzer->removeStopWords($cleanedContent, $language);
            $tokens = $this->textAnalyzer->tokenize($processedContent);
            
            // Appliquer le stemming pour regrouper les mots de même racine
            $stemmedTokens = $this->textAnalyzer->stem($processedContent, $language);
            
            // Filtrer et compter les occurrences
            $termFrequency = array_count_values(array_filter($stemmedTokens, function($token) {
                return strlen($token) > 3 
                    && strlen($token) < 30 
                    && !is_numeric($token)
                    && !preg_match('/[0-9]/', $token);
            }));
            
            // Ne garder que les termes apparaissant plus d'une fois
            $termFrequency = array_filter($termFrequency, fn($freq) => $freq > 1);
            
            arsort($termFrequency);
            return array_slice($termFrequency, 0, 15, true);
            
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de l\'analyse du texte', [
                'pageId' => $pageId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    public function getThemesForSubtree(int $pageUid): array {
        try {
            $cacheIdentifier = 'themes_' . $pageUid;
            $cache = $this->cacheManager->getCache('pages');
            
            // Essayer de récupérer du cache
            $themeData = $cache->get($cacheIdentifier);
            if ($themeData) {
                return $themeData;
            }
            
            // Récupérer toutes les pages du sous-arbre
            $pageIds = $this->getSubtreePageIds($pageUid);
            
            // Récupérer les données
            $themeData = [
                'themes' => $this->getThemes($pageIds),
                'pageThemes' => $this->getPageThemeAssociations($pageIds),
                'keywords' => $this->getTopKeywords($pageIds)
            ];
            
            // Stocker en cache
            $cache->set($cacheIdentifier, $themeData);
            
            return $themeData;
            
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la récupération des thèmes', [
                'pageUid' => $pageUid,
                'error' => $e->getMessage()
            ]);
            return [
                'themes' => [],
                'pageThemes' => [],
                'keywords' => []
            ];
        }
    }

    public function analyzePageContent(int $pageUid): void {
        try {
            // Récupérer toutes les pages du sous-arbre
            $pageIds = $this->getSubtreePageIds($pageUid);
            
            // Récupérer le contenu des pages
            $pageContents = $this->getPageContents($pageIds);
            
            // 1. Analyse des mots-clés par page
            $pageKeywords = [];
            foreach ($pageContents as $pid => $content) {
                // Correction : On passe maintenant le $pid comme second paramètre
                $keywords = $this->extractSignificantTerms($content, $pid);
                $this->saveKeywords($pid, $keywords);
                $pageKeywords[$pid] = $keywords;
            }
            
            // 2. Identifier les thèmes globaux
            $globalThemes = $this->identifyThemes($pageKeywords);
            
            // 3. Sauvegarder les thèmes
            $themeIds = $this->saveThemes($globalThemes);
            
            // 4. Créer les associations pages-thèmes
            $this->createPageThemeAssociations($pageKeywords, $themeIds);
            
            $this->logger->info('Analyse thématique terminée', [
                'pageUid' => $pageUid,
                'contentCount' => count($pageContents),
                'themesCount' => count($globalThemes)
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de l\'analyse du contenu', [
                'pageUid' => $pageUid,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function identifyThemes(array $pageKeywords): array {
        // Regrouper tous les mots-clés
        $allKeywords = [];
        foreach ($pageKeywords as $keywords) {
            foreach ($keywords as $word => $freq) {
                if (!isset($allKeywords[$word])) {
                    $allKeywords[$word] = 0;
                }
                $allKeywords[$word] += $freq;
            }
        }
        
        // Trier par fréquence
        arsort($allKeywords);
        
        // Prendre les N mots les plus fréquents comme thèmes
        $themes = array_slice($allKeywords, 0, 10, true);
        
        return array_map(function($keyword, $frequency) {
            return [
                'name' => $keyword,
                'weight' => $frequency,
                'keywords' => [$keyword] // Pour le moment, chaque thème est basé sur un mot-clé
            ];
        }, array_keys($themes), array_values($themes));
    }

    private function saveThemes(array $themes): array {
        $connection = $this->connectionPool->getConnectionForTable('tx_pagelinkinsights_themes');
        $now = time();
        $themeIds = [];
        
        foreach ($themes as $theme) {
            $connection->insert(
                'tx_pagelinkinsights_themes',
                [
                    'pid' => 0,
                    'tstamp' => $now,
                    'crdate' => $now,
                    'theme_name' => $theme['name'],
                    'keywords' => json_encode($theme['keywords']),
                    'weight' => $theme['weight'],
                    'language' => 0
                ]
            );
            
            $themeIds[$theme['name']] = $connection->lastInsertId('tx_pagelinkinsights_themes');
        }
        
        return $themeIds;
    }

    private function createPageThemeAssociations(array $pageKeywords, array $themeIds): void {
        $connection = $this->connectionPool->getConnectionForTable('tx_pagelinkinsights_page_themes');
        $now = time();
        
        foreach ($pageKeywords as $pageId => $keywords) {
            // Pour chaque thème, calculer la pertinence pour cette page
            foreach ($themeIds as $themeName => $themeId) {
                $relevance = isset($keywords[$themeName]) ? $keywords[$themeName] : 0;
                
                if ($relevance > 0) {
                    $connection->insert(
                        'tx_pagelinkinsights_page_themes',
                        [
                            'pid' => 0,
                            'tstamp' => $now,
                            'crdate' => $now,
                            'page_uid' => $pageId,
                            'theme_uid' => $themeId,
                            'relevance' => $relevance
                        ]
                    );
                }
            }
        }
    }
    
    private function getSubtreePageIds(int $rootPageId): array {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        
        $pages = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('uid', 
                        $queryBuilder->createNamedParameter($rootPageId)
                    ),
                    $queryBuilder->expr()->eq('pid', 
                        $queryBuilder->createNamedParameter($rootPageId)
                    )
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();
            
        return array_column($pages, 'uid');
    }
    
    private function getThemes(array $pageIds): array {
        if (empty($pageIds)) {
            return [];
        }
        
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_pagelinkinsights_themes');
        
        return $queryBuilder
            ->select('*')
            ->from('tx_pagelinkinsights_themes')
            ->where(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($pageIds, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                )
            )
            ->orderBy('weight', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }
    
    private function getPageThemeAssociations(array $pageIds): array {
        if (empty($pageIds)) {
            return [];
        }
        
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_pagelinkinsights_page_themes');
        
        $associations = $queryBuilder
            ->select('pt.*', 't.theme_name', 't.keywords')
            ->from('tx_pagelinkinsights_page_themes', 'pt')
            ->join(
                'pt',
                'tx_pagelinkinsights_themes',
                't',
                'pt.theme_uid = t.uid'
            )
            ->where(
                $queryBuilder->expr()->in(
                    'pt.page_uid',
                    $queryBuilder->createNamedParameter($pageIds, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                )
            )
            ->orderBy('pt.relevance', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
            
        // Organiser par page
        $byPage = [];
        foreach ($associations as $assoc) {
            $pageUid = $assoc['page_uid'];
            if (!isset($byPage[$pageUid])) {
                $byPage[$pageUid] = [];
            }
            $byPage[$pageUid][] = [
                'theme' => $assoc['theme_name'],
                'relevance' => $assoc['relevance'],
                'keywords' => json_decode($assoc['keywords'], true)
            ];
        }
        
        return $byPage;
    }
    
    private function getPageContents(array $pageIds): array {
        if (empty($pageIds)) {
            return [];
        }
        
        // Récupérer la configuration colPos depuis l'extension
        $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
        $config = $extensionConfiguration->get('page_link_insights');
        $allowedColPos = GeneralUtility::intExplode(',', $config['colPosToAnalyze'] ?? '0', true);
        
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        
        $contents = $queryBuilder
            ->select('pid', 'header', 'bodytext', 'subheader')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($pageIds, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->in(
                    'colPos',
                    $queryBuilder->createNamedParameter($allowedColPos, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();
            
        // Regrouper par page
        $pageContents = [];
        foreach ($contents as $content) {
            if (!isset($pageContents[$content['pid']])) {
                $pageContents[$content['pid']] = '';
            }
            $pageContents[$content['pid']] .= ' ' . $content['header'] . ' ' . 
                                            $content['subheader'] . ' ' . 
                                            $content['bodytext'];
        }
        
        return $pageContents;
    }
    
    
    private function saveKeywords(int $pageUid, array $keywords): void {
        $connection = $this->connectionPool->getConnectionForTable('tx_pagelinkinsights_keywords');
        $now = time();
        
        foreach ($keywords as $keyword => $frequency) {
            $connection->insert(
                'tx_pagelinkinsights_keywords',
                [
                    'pid' => 0,
                    'tstamp' => $now,
                    'crdate' => $now,
                    'page_uid' => $pageUid,
                    'keyword' => $keyword,
                    'frequency' => $frequency,
                    'weight' => 1.0,
                    'language' => 0
                ]
            );
        }
    }
    
    private function getTopKeywords(array $pageIds): array {
        if (empty($pageIds)) {
            return [];
        }
        
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_pagelinkinsights_keywords');
        
        return $queryBuilder
            ->select('keyword')
            ->addSelectLiteral('SUM(frequency * weight) as total_weight')
            ->from('tx_pagelinkinsights_keywords')
            ->where(
                $queryBuilder->expr()->in(
                    'page_uid',
                    $queryBuilder->createNamedParameter($pageIds, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                )
            )
            ->groupBy('keyword')
            ->orderBy('total_weight', 'DESC')
            ->setMaxResults(50)
            ->executeQuery()
            ->fetchAllAssociative();
    }
    
    public function enrichNodesWithThemes(array $nodes, array $themeData): array {
        foreach ($nodes as &$node) {
            $pageUid = $node['id'];
            
            if (isset($themeData['pageThemes'][$pageUid])) {
                // Ajouter les thèmes principaux de la page
                $node['themes'] = $themeData['pageThemes'][$pageUid];
                
                // Ajouter le thème dominant (celui avec la plus haute pertinence)
                $dominantTheme = reset($themeData['pageThemes'][$pageUid]);
                $node['mainTheme'] = [
                    'name' => $dominantTheme['theme'],
                    'relevance' => $dominantTheme['relevance']
                ];
            }
        }
        
        return $nodes;
    }
}