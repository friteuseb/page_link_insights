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

/**
 * Extrait les termes significatifs d'un contenu textuel
 * 
 * @param string $content Le contenu à analyser
 * @param int $pageId L'ID de la page
 * @return array An array of significant terms with their frequencies
 */
private function extractSignificantTerms(string $content, int $pageId): array
{
    try {
        // Detect the language of the content
        $language = $this->languageDetector->detectLanguage($content);
        

        // Add at the beginning
        $logFile = \TYPO3\CMS\Core\Core\Environment::getProjectPath() . '/var/log/theme_extraction_test.log';
        file_put_contents($logFile, "=== Test d'extraction pour la page $pageId ===\n", FILE_APPEND);

        // Some logs kept for diagnosis
        if ($this->debugMode) {
            $this->logger->info('Extraction de termes pour la page ' . $pageId, [
                'langue' => $language,
                'longueur_contenu' => strlen($content)
            ]);
        }
        
        // Completely remove HTML tags
        $cleanedContent = strip_tags($content);
        
        // Remove HTML entities (like &nbsp;)
        $cleanedContent = html_entity_decode($cleanedContent, ENT_QUOTES, 'UTF-8');
        
        // Nettoyer les caractères spéciaux
        $cleanedContent = preg_replace('/[^\p{L}\s]/u', ' ', $cleanedContent);
        $cleanedContent = preg_replace('/\s+/', ' ', $cleanedContent);
        
        // Ajouter une liste personnalisée de "stopwords" liés au HTML/CSS/JS
        $htmlStopwords = ['class', 'href', 'div', 'span', 'http', 'https', 'www', 'img', 'src'];
        
        try {
            // Tokenizer et enlever les stop words
            file_put_contents($logFile, "Tentative d'utilisation de nlp_tools...\n", FILE_APPEND);
            $processedContent = $this->textAnalyzer->removeStopWords($cleanedContent, $language);
            $tokens = $this->textAnalyzer->tokenize($processedContent);
            file_put_contents($logFile, "nlp_tools fonctionne correctement! " . count($tokens) . " tokens extraits\n", FILE_APPEND);
            
            
            // Vérifier si $tokens est un tableau valide
            if (!is_array($tokens)) {
                if ($this->debugMode) {
                    $this->logger->warning('Erreur de tokenization, utilisation de la méthode de secours', [
                        'pageId' => $pageId
                    ]);
                }
                return $this->fallbackExtractKeywords($cleanedContent, $pageId);
            }
            
            // Stocker les versions originales des tokens avant stemming
            $originalTokens = [];
            foreach ($tokens as $token) {
                $tokenLower = strtolower($token);
                if (strlen($tokenLower) > 3 && strlen($tokenLower) < 30 && !is_numeric($tokenLower) 
                    && !preg_match('/[0-9]/', $tokenLower) && !in_array($tokenLower, $htmlStopwords)) {
                    $originalTokens[] = $token;
                }
            }
            
            // Créer un mapping entre les stems et les tokens originaux
            $stemToOriginalMap = [];
            $stems = $this->textAnalyzer->stem($processedContent, $language);
             file_put_contents($logFile, "Stemming successful! " . count($stems) . " stems extracted\n", FILE_APPEND);

            if (!is_array($stems)) {
                if ($this->debugMode) {
                    $this->logger->warning('Erreur de stemming, utilisation de la méthode de secours', [
                        'pageId' => $pageId
                    ]);
                }
                return $this->fallbackExtractKeywords($cleanedContent, $pageId);
            }
            
            // Check that both arrays have the same length
            $minLength = min(count($tokens), count($stems));
            for ($i = 0; $i < $minLength; $i++) {
                $stem = $stems[$i];
                $originalToken = $tokens[$i];
                
                // Only if the original token is in our filtered list
                if (in_array($originalToken, $originalTokens)) {
                    if (!isset($stemToOriginalMap[$stem])) {
                        $stemToOriginalMap[$stem] = [];
                    }
                    $stemToOriginalMap[$stem][] = $originalToken;
                }
            }
            
            // Use the stems for frequency counting
            $stemmedTokens = array_filter($stems, function($token) use ($htmlStopwords) {
                return strlen($token) > 3 
                    && strlen($token) < 30 
                    && !is_numeric($token)
                    && !preg_match('/[0-9]/', $token)
                    && !in_array(strtolower($token), $htmlStopwords);
            });
            
            // Compter les occurrences des stems
            $stemFrequency = array_count_values($stemmedTokens);
            
            // Ne garder que les termes apparaissant plus d'une fois
            $stemFrequency = array_filter($stemFrequency, fn($freq) => $freq > 1);
            
            // Sort by frequency
            arsort($stemFrequency);
            
            // Prepare the final result with original forms
            $termFrequency = [];
            foreach (array_slice($stemFrequency, 0, 15, true) as $stem => $frequency) {
                // Choose the most frequent original form (for now, we just take the first one)
                $originalForm = isset($stemToOriginalMap[$stem]) && !empty($stemToOriginalMap[$stem]) 
                    ? $stemToOriginalMap[$stem][0] 
                    : $stem;
                
                // Capitalize the first letter for better presentation
                $originalForm = ucfirst($originalForm);
                
                $termFrequency[$originalForm] = $frequency;
            }
            
            if (count($termFrequency) === 0 && $this->debugMode) {
                $this->logger->info('No term found, using fallback method', [
                    'pageId' => $pageId
                ]);
                return $this->fallbackExtractKeywords($cleanedContent, $pageId);
            }
            
            return $termFrequency;
            
        } catch (\Exception $nlpError) {
            if ($this->debugMode) {
                $this->logger->warning('Exception NLP: ' . $nlpError->getMessage(), [
                    'pageId' => $pageId
                ]);
            }
            file_put_contents($logFile, "ERREUR AVEC NLP-TOOLS: " . $nlpError->getMessage() . "\n", FILE_APPEND);
            return $this->fallbackExtractKeywords($cleanedContent, $pageId);
        }
        
    } catch (\Exception $e) {
        $this->logger->error('Erreur lors de l\'analyse du texte', [
            'pageId' => $pageId,
            'error' => $e->getMessage()
        ]);
        
        return $this->fallbackExtractKeywords($cleanedContent, $pageId);
    }
}

/**
 * Fallback method to extract keywords without using NLP Tools
 * Used when NLP analysis fails or produces no results
 * 
 * @param string $content Le contenu à analyser
 * @param int $pageId L'ID de la page
 * @return array An array of terms with their frequencies
 */
private function fallbackExtractKeywords(string $content, int $pageId): array
{
    if ($this->debugMode) {
        $this->logger->info('Using fallback method for keyword extraction', [
            'pageId' => $pageId
        ]);
    }
    
    try {
        // Clean the content if it's not already cleaned
        if (strpos($content, '<') !== false) {
            $content = strip_tags($content);
            $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
            $content = preg_replace('/[^\p{L}\s]/u', ' ', $content);
            $content = preg_replace('/\s+/', ' ', $content);
        }
        
        // Basic list of stop words (English + German + French)
        $stopWords = ['a', 'an', 'the', 'and', 'or', 'but', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
                      'in', 'on', 'at', 'to', 'for', 'with', 'by', 'about', 'against', 'between', 'into', 'through',
                      'der', 'die', 'das', 'und', 'oder', 'aber', 'ist', 'sind', 'war', 'waren', 'sein', 'gewesen',
                      'in', 'auf', 'an', 'zu', 'für', 'mit', 'durch', 'über', 'unter', 'gegen', 'zwischen', 'hinein',
                      'le', 'la', 'les', 'un', 'une', 'et', 'ou', 'mais', 'est', 'sont', 'était', 'être', 'ont',
                      'dans', 'sur', 'pour', 'avec', 'par', 'que', 'qui', 'donc', 'alors', 'si', 'quand',
                      'ces', 'tous', 'toutes', 'leur', 'leurs', 'votre', 'vos', 'notre', 'nos', 'mon', 'ma', 'mes'];
        
        // Tokenizer le texte
        $words = preg_split('/\s+/', strtolower($content));
        
        // Filtrer les mots courts et les stop words
        $filteredWords = array_filter($words, function($word) use ($stopWords) {
            return strlen($word) > 3 && !in_array($word, $stopWords) && !is_numeric($word) && !preg_match('/[0-9]/', $word);
        });
        
        // Compter les occurrences
        $wordCount = array_count_values($filteredWords);
        
        // Ne garder que les termes apparaissant plus d'une fois
        $wordCount = array_filter($wordCount, fn($freq) => $freq > 1);
        
        // Trier par fréquence
        arsort($wordCount);
        
        // Prendre les 15 premiers mots avec mise en majuscule
        $result = [];
        foreach (array_slice($wordCount, 0, 15, true) as $word => $freq) {
            $result[ucfirst($word)] = $freq;
        }
        
        if (count($result) === 0) {
            if ($this->debugMode) {
                $this->logger->notice('No keyword found, using generic keywords', [
                    'pageId' => $pageId
                ]);
            }
            
            // Si aucun mot-clé n'est trouvé, créer des mots-clés génériques
            return [
                'Content' => 10,
                'Page' => 9,
                'Information' => 8,
                'Website' => 7,
                'Menu' => 6
            ];
        }
        
        return $result;
        
    } catch (\Exception $e) {
        $this->logger->error('Erreur dans fallbackExtractKeywords', [
            'message' => $e->getMessage(),
            'pageId' => $pageId
        ]);
        
        // Retourner au moins quelques mots-clés génériques pour que ça fonctionne
        return [
            'Content' => 10,
            'Page' => 9,
            'Information' => 8,
            'Website' => 7,
            'Menu' => 6
        ];
    }
}
    
    public function getThemesForSubtree(int $pageUid): array
    {
        try {
            $cacheIdentifier = 'themes_' . $pageUid;
            $cache = $this->cacheManager->getCache('pages');
            
            // Try to retrieve from cache
            $themeData = $cache->get($cacheIdentifier);
            if ($themeData) {
                return $themeData;
            }
            
            // Retrieve all pages in the subtree
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
            // Retrieve all pages in the subtree
            $pageIds = $this->getSubtreePageIds($pageUid);

            // Clean old theme data for this subtree before inserting new data
            $this->cleanThemeDataForPages($pageIds);

            // Récupérer le contenu des pages
            $pageContents = $this->getPageContents($pageIds);
            
            // 1. Analyse des mots-clés par page
            $pageKeywords = [];
            foreach ($pageContents as $pid => $content) {
                // Correction: We now pass $pid as the second parameter
                $keywords = $this->extractSignificantTerms($content, $pid);
                $this->saveKeywords($pid, $keywords);
                $pageKeywords[$pid] = $keywords;
            }
            
            // 2. Identify global themes
            $globalThemes = $this->identifyThemes($pageKeywords);
            
            // 3. Sauvegarder les thèmes
            $themeIds = $this->saveThemes($globalThemes);
            
            // 4. Create page-theme associations
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
                // Chercher une correspondance exacte ou le mot-clé est contenu dans le nom du thème
                $relevance = 0;
                foreach ($keywords as $keyword => $frequency) {
                    // Correspondance exacte
                    if ($keyword === $themeName) {
                        $relevance = $frequency;
                        break;
                    }
                    // The keyword is part of the theme name
                    elseif (stripos($themeName, $keyword) !== false || stripos($keyword, $themeName) !== false) {
                        $relevance = max($relevance, $frequency * 0.8); // weight a bit less for partial matches
                    }
                }
                
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
    
    private function getSubtreePageIds(int $rootPageId): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        
        // First retrieve the root page
        $rootPage = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', 
                    $queryBuilder->createNamedParameter($rootPageId)
                )
            )
            ->executeQuery()
            ->fetchAssociative();
            
        if (!$rootPage) {
            return [];
        }
        
        $allPageIds = [$rootPage['uid']];
        $pagesToProcess = [$rootPage['uid']];
        
        // Traverse the tree recursively
        while (!empty($pagesToProcess)) {
            $currentPageIds = $pagesToProcess;
            $pagesToProcess = [];
            
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $childPages = $queryBuilder
                ->select('uid')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->in(
                        'pid',
                        $queryBuilder->createNamedParameter($currentPageIds, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                    )
                )
                ->executeQuery()
                ->fetchAllAssociative();
            
            foreach ($childPages as $page) {
                $allPageIds[] = $page['uid'];
                $pagesToProcess[] = $page['uid'];
            }
        }
        
        return $allPageIds;
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
        
        // Retrieve the colPos configuration from the extension
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
                // Add the main themes of the page
                $node['themes'] = $themeData['pageThemes'][$pageUid];
                
                // Add the dominant theme (the one with the highest relevance)
                $dominantTheme = reset($themeData['pageThemes'][$pageUid]);
                $node['mainTheme'] = [
                    'name' => $dominantTheme['theme'],
                    'relevance' => $dominantTheme['relevance']
                ];
            }
        }
        
        return $nodes;
    }

    /**
     * Debug mode to enable detailed logs
     */
    protected bool $debugMode = false;

    /**
     * Clean theme data for specific pages before inserting new analysis results
     * This prevents data accumulation when running multiple cron tasks
     */
    private function cleanThemeDataForPages(array $pageIds): void
    {
        if (empty($pageIds)) {
            return;
        }

        // Clean keywords for these pages
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_pagelinkinsights_keywords');
        $queryBuilder
            ->delete('tx_pagelinkinsights_keywords')
            ->where(
                $queryBuilder->expr()->in(
                    'page_uid',
                    $queryBuilder->createNamedParameter($pageIds, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeStatement();

        // Clean page-theme associations for these pages
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_pagelinkinsights_page_themes');
        $queryBuilder
            ->delete('tx_pagelinkinsights_page_themes')
            ->where(
                $queryBuilder->expr()->in(
                    'page_uid',
                    $queryBuilder->createNamedParameter($pageIds, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeStatement();

        // Get theme UIDs that are only associated with these pages (orphaned themes)
        // and delete them to avoid accumulating unused themes
        $this->cleanOrphanedThemes();
    }

    /**
     * Remove themes that have no page associations
     */
    private function cleanOrphanedThemes(): void
    {
        // Get all theme UIDs that are still associated with pages
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_pagelinkinsights_page_themes');
        $usedThemeUids = $queryBuilder
            ->select('theme_uid')
            ->from('tx_pagelinkinsights_page_themes')
            ->groupBy('theme_uid')
            ->executeQuery()
            ->fetchFirstColumn();

        // Delete themes that are not in the list of used themes
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_pagelinkinsights_themes');
        if (empty($usedThemeUids)) {
            // No associations exist, delete all themes
            $queryBuilder
                ->delete('tx_pagelinkinsights_themes')
                ->executeStatement();
        } else {
            // Delete only orphaned themes
            $queryBuilder
                ->delete('tx_pagelinkinsights_themes')
                ->where(
                    $queryBuilder->expr()->notIn(
                        'uid',
                        $queryBuilder->createNamedParameter($usedThemeUids, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                    )
                )
                ->executeStatement();
        }
    }

}
