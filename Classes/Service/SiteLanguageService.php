<?php

declare(strict_types=1);

namespace Cywolf\PageLinkInsights\Service;

use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Answers which languages a page is available in, and which ISO code the text
 * analysis should use for a given one.
 *
 * The theme extraction used to guess the language from the text itself, which
 * returned "en" for French pages and left French stop words in the results. The
 * site configuration already carries that information; asking it is both exact
 * and free.
 */
class SiteLanguageService
{
    private SiteFinder $siteFinder;

    public function __construct()
    {
        $this->siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
    }

    /**
     * Languages configured for the site the page belongs to.
     *
     * @return array<int, array{id: int, title: string, iso: string, flag: string}>
     */
    public function getAvailableLanguages(int $pageUid): array
    {
        $languages = [];
        foreach ($this->getSiteLanguages($pageUid) as $language) {
            $languages[] = [
                'id' => $language->getLanguageId(),
                'title' => $language->getTitle(),
                'iso' => $this->extractIsoCode($language),
                'flag' => $language->getFlagIdentifier(),
            ];
        }

        return $languages;
    }

    /**
     * Two-letter ISO code of a language, as the stop word lists expect it.
     * Falls back to the site's default language, then to English.
     */
    public function getIsoCode(int $pageUid, int $languageId): string
    {
        $siteLanguages = $this->getSiteLanguages($pageUid);

        foreach ($siteLanguages as $language) {
            if ($language->getLanguageId() === $languageId) {
                return $this->extractIsoCode($language);
            }
        }

        $default = $siteLanguages[0] ?? null;

        return $default !== null ? $this->extractIsoCode($default) : 'en';
    }

    /**
     * Whether the given language id belongs to the page's site. Guards against
     * a language id arriving from the URL that the site does not serve.
     */
    public function isValidLanguage(int $pageUid, int $languageId): bool
    {
        foreach ($this->getSiteLanguages($pageUid) as $language) {
            if ($language->getLanguageId() === $languageId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return SiteLanguage[]
     */
    private function getSiteLanguages(int $pageUid): array
    {
        if ($pageUid <= 0) {
            return [];
        }

        try {
            return array_values($this->siteFinder->getSiteByPageId($pageUid)->getLanguages());
        } catch (SiteNotFoundException) {
            // Pages outside any configured site (system folders on the root
            // level, typically) simply have no language to offer.
            return [];
        }
    }

    private function extractIsoCode(SiteLanguage $language): string
    {
        return strtolower(substr($language->getLocale()->getLanguageCode(), 0, 2));
    }
}
