# Solr Integration for Page Link Insights

This documentation explains how to integrate internal linking analysis data (PageRank, inbound links, etc.) into your Solr search results to improve relevancy and enable advanced search features.

## Features

- **PageRank Integration**: Boost search results based on page authority
- **Inbound Link Weighting**: Consider popularity in search relevance
- **Centrality Metrics**: Use page network position for ranking
- **Custom Sorting**: Enable sorting by page importance
- **Configurable Relevance**: Adjust factors through TypoScript

## Installation

### 1. Solr Schema Configuration

Add the following fields to your `schema.xml`:

```xml
<field name="pagerank_f" type="float" indexed="true" stored="true"/>
<field name="inbound_links_i" type="int" indexed="true" stored="true"/>
<field name="centrality_f" type="float" indexed="true" stored="true"/>
```

### 2. TypoScript Configuration

The extension includes a prepared TypoScript configuration. Add it to your site by:

```typoscript
@import "EXT:page_link_insights/Configuration/TypoScript/setup.typoscript"
```

Alternatively, uncomment the import in `ext_localconf.php`.

The configuration includes:
- DataProcessor for page metrics
- Field definitions
- Relevance formula
- Sorting options

### 3. Extension Activation

The Solr integration requires the main features of the extension to be working:

1. Make sure the Scheduler task has been run at least once
2. Verify metrics are stored in `tx_pagelinkinsights_pageanalysis` table
3. Reindex your pages in Solr

## Usage

### Checking Indexed Metrics

To verify the metrics are properly indexed:

1. Access the Solr admin interface
2. Search with `*:*` to see all documents
3. Verify the presence of fields:
   - `pagerank_f`: Authority score of the page
   - `inbound_links_i`: Number of incoming content links
   - `centrality_f`: Network centrality score

### Understanding the Scoring

The default configuration uses the following influence factors:
- PageRank (multiplier: 2.0)
- Inbound links (multiplier: 1.5)
- Base Solr score (multiplier: 1.0)

The final formula combines these elements:
```
final_score = (base_score * 1.0) + (pagerank * 2.0) + (inbound_links * 1.5)
```

### Sorting by Page Importance

The configuration adds a new sorting option "Page Rank" to your Solr frontend, allowing users to sort by page importance rather than just relevance.

## Customization

### Adjusting Relevance Factors

You can customize the influence of different metrics by changing the multipliers:

```typoscript
plugin.tx_solr.search.relevance.multiplier {
    pagerank = 3.0     # Increase PageRank influence
    inboundLinks = 1.0 # Decrease link count influence
}
```

### Advanced Formula Customization

For more complex scoring, you can modify the formula directly:

```typoscript
plugin.tx_solr.search.relevance.formula = sum(
    mul(queryNorm(dismax(v:1)), 1.0),
    mul(fieldValue(pagerank_f), 2.0),
    mul(div(fieldValue(inbound_links_i), 100), 1.5),
    # Add additional factors here
)
```

## Troubleshooting

### Metrics Not Appearing

If metrics aren't showing up in your Solr index:

1. Run the Page Link Insights scheduler task
2. Check data exists in the database table
3. Reindex the pages in Solr
4. Clear all TYPO3 caches

### Incorrect Scoring

If search results aren't ranked as expected:

1. Verify the actual PageRank values in Page Link Insights module
2. Check multiplier settings in TypoScript
3. Examine debug information from Solr
4. Adjust multipliers to achieve desired balance

## Advanced Usage

### Incremental Updates

For large sites, consider implementing incremental updates:

1. Configure the scheduler task to run more frequently on a subset of pages
2. Mark only affected pages for reindexing in Solr
3. Use dedicated indexing queues for metrics updates

### A/B Testing Weights

To find optimal relevance settings:

1. Create multiple search configurations with different weights
2. Use Solr search collections to compare results
3. Analyze user behavior to determine best settings

## FAQ

**Q: Will this slow down my Solr searches?**  
A: The impact is minimal. The additional calculations happen during indexing, not during search.

**Q: How often should I update PageRank metrics?**  
A: For most sites, weekly is sufficient. Sites with frequent content updates may benefit from daily runs.

**Q: Can I use this with non-page records?**  
A: Currently, the metrics are calculated only for pages, but the concept could be extended to other record types.