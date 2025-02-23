# Solr Integration for Page Link Insights

This documentation explains how to integrate internal linking analysis data (PageRank, inbound links, etc.) into your Solr search results.

## Features

- Enrichment of Solr documents with local PageRank
- Search result boosting based on page popularity
- Consideration of inbound link count
- Ability to sort results by PageRank

## Installation

1. **Solr Schema Configuration**

Add the following fields to your `schema.xml`:
```xml
<field name="pagerank_f" type="float" indexed="true" stored="true"/>
<field name="inbound_links_i" type="int" indexed="true" stored="true"/>
<field name="centrality_f" type="float" indexed="true" stored="true"/>
```

2. **TypoScript Configuration**

The `Configuration/TypoScript/setup.typoscript` file has been created with:
- DataProcessor configuration
- Field definitions
- Scoring configuration
- Sorting options

3. **Installation Verification**

- Clear all TYPO3 caches
- Reindex all pages in Solr
- Verify the presence of new fields

## Usage

### Checking the Indexation

1. Access the Solr admin interface
2. Search with `*:*` to see all documents
3. Check for the presence of fields:
   - pagerank_f
   - inbound_links_i
   - centrality_f

### Testing the Scoring

The scoring is influenced by:
- PageRank (multiplier of 2.0)
- Number of inbound links (multiplier of 1.5)
- Base search score

Formula: `final_score = (base_score * 1.0) + (pagerank * 2.0) + (inbound_links * 1.5)`

### Result Sorting

A new "Page Rank" sort is available in the result sorting options.

## Troubleshooting

### Metrics Not Appearing in Solr

1. Check that the scheduler task has been executed
2. Control the data in the `tx_pagelinkinsights_pageanalysis` table
3. Reindex the concerned pages

### Scores Seem Incorrect

1. Check PageRank values in the Page Link Insights interface
2. Control multipliers in TypoScript configuration
3. Examine Solr debug report to understand score calculation

## Customization

### Adjusting Multipliers

Modify values in TypoScript:
```typoscript
relevance {
    multiplier {
        pagerank = 2.0    # Increase to give more weight to PageRank
        inboundLinks = 1.5 # Adjust according to inbound links importance
    }
}
```

### Adding New Criteria

1. Add the field in `schema.xml`
2. Modify PageMetricsProcessor.php
3. Update TypoScript configuration
4. Reindex pages

## FAQ

**Q: Why do some pages have a PageRank of 0?**  
A: This can happen if the page has no inbound links or if the last analysis hasn't been run.

**Q: Are link changes immediately reflected?**  
A: No, you need to wait for the next scheduler task execution and then reindex the pages.

**Q: How can I optimize scores for my site?**  
A: Adjust the multipliers in the TypoScript configuration based on the relative importance of PageRank and inbound links for your use case.

## Advanced Usage

### Search Result Boosting

The default configuration boosts pages based on their network metrics. You can customize this in your TypoScript:

```typoscript
plugin.tx_solr {
    search {
        relevance {
            multiplier {
                pagerank = 2.0
                inboundLinks = 1.5
            }
            formula = sum(
                mul(queryNorm(dismax(v:1)), 1.0),
                mul(fieldValue(pagerank_f), 2.0),
                mul(fieldValue(inbound_links_i), 1.5)
            )
        }
    }
}
```

### Debug Mode

To verify that metrics are being properly indexed:

1. Enable Solr debug mode in TYPO3:
```typoscript
plugin.tx_solr.logging.query.queryString = 1
```

2. Check the Solr query log in the TYPO3 backend
3. Verify that your custom fields are included in the query

### Reindexing Strategies

For large sites, consider these reindexing approaches:

1. **Incremental Updates**:
   - Only reindex pages that have changed
   - Configure the scheduler task to mark affected pages for reindexing

2. **Batch Processing**:
   - Use the TYPO3 command line interface
   - Process pages in chunks to avoid timeout issues

Example CLI command:
```bash
vendor/bin/typo3 solr:reindex --site="your-site" --update-metrics
```