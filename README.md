# TYPO3 Page Link Insights Extension

![Force Diagram Example](Resources/Public/Images/force-diagram-example.png)

This TYPO3 extension is designed to help you optimize your website's internal linking structure by providing a powerful visual representation of content-based page connections. Unlike traditional site maps or menu structures, Page Link Insights focuses specifically on links within your content elements, helping you understand and improve your site's semantic link structure and SEO performance.

## Why Content-Based Link Analysis Matters

### Beyond Navigation Structure
While menus and navigation elements are essential for user experience, they don't contribute significantly to your site's semantic structure or SEO. Page Link Insights deliberately excludes menu-based links to reveal your true content interconnections, showing how your pages are contextually linked through actual content references.

### Understanding the Force Diagram
The force-directed visualization provides crucial insights into your content strategy:
- **Node Size**: Larger nodes indicate pages that are frequently referenced in content, highlighting your site's key content hubs
- **Link Patterns**: The way nodes arrange themselves shows natural content clusters and thematic relationships
- **Distance and Positioning**: Closely related pages naturally group together, revealing content silos and potential gaps in your internal linking strategy

### Benefits for Content Strategy and SEO
- **Identify Content Hubs**: Discover which pages serve as primary content anchors in your site
- **Find Orphaned Content**: Easily spot pages with few or no incoming content links
- **Optimize Link Distribution**: Ensure important pages receive adequate content references
- **Improve Topic Clustering**: Visualize and strengthen your site's thematic structure
- **Detect Content Gaps**: Identify opportunities for new content connections

### Real-World Applications
- **Content Audits**: Quickly assess the strength of your internal linking strategy
- **SEO Optimization**: Improve page authority distribution through strategic content linking
- **Content Planning**: Make informed decisions about where to add new content links
- **Website Restructuring**: Visualize the impact of content reorganization

## Features

- Interactive force-directed graph visualization of page links
- Visualization of both direct and indirect page references
- Support for various types of page references:
  - Content elements with page links
  - Menu elements
  - Sitemap elements
  - Legacy TYPO3 link formats
- Visual indicators for broken links
- Dynamic node sizing based on incoming links
- Interactive features:
  - Zoom and pan functionality
  - Drag and drop nodes
  - Ctrl+Click to open pages in TYPO3 backend
  - Right-click to remove nodes from the visualization
  - Hover tooltips with detailed information

## Requirements

- TYPO3 v12 LTS or v13
- PHP 8.1 or higher

## Installation

### Via Composer

```bash
composer require Cywolf/page-link-insights
```

### Via TYPO3 Extension Manager

1. Login to TYPO3 Backend
2. Go to Admin Tools > Extensions
3. Click on "Get Extensions"
4. Search for "page_link_insights"
5. Click "Import and Install"

## Configuration

The extension can be configured through the Extension Configuration in TYPO3 Backend:

![Extension Configuration](Resources/Public/Images/extension-configuration.png)

1. Go to Admin Tools > Settings > Extension Configuration
2. Select "page_link_insights"
3. Configure the following options:

   - `colPosToAnalyze`: Comma-separated list of content column positions to analyze (default: 0)
   - `includeHidden`: Whether to include hidden pages and content elements (default: false)

## Usage

### Getting Started
1. Open the TYPO3 Backend
2. Navigate to the Web > Page module
3. Select a page in the page tree
4. Click on the "Page Link Insights" module in the module menu
5. The visualization will automatically load showing all page connections

### Analyzing Your Content Structure
1. **Initial Assessment**:
   - Look for large nodes (heavily referenced pages)
   - Identify isolated nodes (poorly linked content)
   - Observe natural content clusters

2. **Strategic Analysis**:
   - Evaluate the distribution of content links
   - Check if important pages have sufficient incoming links
   - Look for opportunities to strengthen content relationships

3. **Optimization Steps**:
   - Add content links to isolated pages
   - Strengthen connections between related content clusters
   - Fix any broken links (shown in red)
   - Balance link distribution among key pages

### Interaction Features

- **Zoom**: Use mouse wheel to zoom in/out
- **Pan**: Click and drag on empty space to move the view
- **Move Nodes**: Click and drag nodes to reposition them
- **Open in TYPO3**: Ctrl+Click (or Cmd+Click on Mac) on a node to open the page in TYPO3 backend
- **Remove Node**: Right-click on a node to remove it from the visualization
- **View Details**: Hover over nodes or links to see detailed information

### Understanding the Visualization

#### Node Characteristics
- **Node Size**: Larger nodes represent pages with more incoming content links, helping identify your key content hubs
- **Node Color**: Color intensity correlates with incoming link count, making important pages instantly recognizable
- **Node Position**: The force-directed layout naturally groups related content together, revealing topical clusters

#### Link Types and Their Significance
Different colors represent various types of content references:
- **HTML links (Blue)**: Direct HTML links in your content, showing explicit content relationships
- **Typolink (Orange)**: TYPO3's internal linking system, indicating structured content connections
- **Text links (Pink)**: Text-embedded links, revealing natural content flow and reader pathways
- **Menu/Sitemap-based links (Green/Purple)**: While shown for completeness, these are visually distinct to help focus on true content relationships
- **Broken links (Red, dashed)**: Immediately identify and fix broken content references

#### Link Patterns and Clusters
- Dense clusters indicate strongly related content
- Isolated nodes might need additional content links
- Long link chains suggest potential navigation improvements
- Bridge nodes connecting clusters may be key junction content

## Troubleshooting

- If the visualization is empty, make sure:
  - The selected page has child pages or content with page references
  - The configured column positions (`colPosToAnalyze`) contain content elements
  - You have sufficient permissions to access the pages and their content
- If pages are missing, check if:
  - The pages are not hidden (unless `includeHidden` is enabled)
  - The pages are within the current site root
  - The pages are in the default language (sys_language_uid = 0)

## Support

For bug reports and feature requests, please use the issue tracker on GitHub:
[Project Issue Tracker](https://github.com/friteuseb/page_link_insights/issues)

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This project is licensed under the GNU General Public License v2.0.
