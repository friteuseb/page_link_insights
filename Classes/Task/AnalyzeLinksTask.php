<?php

namespace Cywolf\PageLinkInsights\Task;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use Cywolf\PageLinkInsights\Service\PageMetricsService;
use Cywolf\PageLinkInsights\Service\ThemeDataService;
use TYPO3\CMS\Core\Database\ConnectionPool; 


class AnalyzeLinksTask extends AbstractTask
{
    public int $rootPageId = 1;  // Page racine par défaut
    
    public function execute(): bool
    {
        try {
            // Analyse des liens existante
            /** @var PageMetricsService $metricsService */
            $metricsService = GeneralUtility::makeInstance(PageMetricsService::class);
            $metricsService->analyzeSite($this->rootPageId);
            
            // Nouvelle analyse thématique
            /** @var ThemeDataService $themeService */
            $themeService = GeneralUtility::makeInstance(ThemeDataService::class);
            
            // Nettoyer les anciennes données
            $this->cleanOldThemeData();
            
            // Analyser et stocker les nouvelles données thématiques
            $themeService->analyzePageContent($this->rootPageId);
            
            return true;
        } catch (\Exception $e) {
            // Log de l'erreur
            return false;
        }
    }
    
    private function cleanOldThemeData(): void
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        
        // Supprimer les données de plus de 30 jours
        $threshold = time() - (30 * 24 * 60 * 60);
        
        $tables = [
            'tx_pagelinkinsights_keywords',
            'tx_pagelinkinsights_themes',
            'tx_pagelinkinsights_page_themes'
        ];
        
        foreach ($tables as $table) {
            $queryBuilder = $connectionPool->getQueryBuilderForTable($table);
            $queryBuilder
                ->delete($table)
                ->where(
                    $queryBuilder->expr()->lt(
                        'tstamp',
                        $queryBuilder->createNamedParameter($threshold)
                    )
                )
                ->executeStatement();
        }
    }
    
    public function getAdditionalInformation(): string
    {
        return 'Analyze links and themes for root page: ' . $this->rootPageId;
    }
}