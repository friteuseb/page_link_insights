#
# Table structure for table 'tx_pagelinkinsights_pageanalysis'
#
CREATE TABLE tx_pagelinkinsights_pageanalysis (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,
    
    page_uid int(11) DEFAULT '0' NOT NULL,
    pagerank DOUBLE PRECISION DEFAULT '0' NOT NULL,
    inbound_links int(11) DEFAULT '0' NOT NULL,
    outbound_links int(11) DEFAULT '0' NOT NULL,
    broken_links int(11) DEFAULT '0' NOT NULL,
    depth_level int(11) DEFAULT '0' NOT NULL,
    centrality_score DOUBLE PRECISION DEFAULT '0' NOT NULL,
    
    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY page (page_uid)
);

#
# Table structure for table 'tx_pagelinkinsights_linkanalysis'
#
CREATE TABLE tx_pagelinkinsights_linkanalysis (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,
    
    source_page int(11) DEFAULT '0' NOT NULL,
    target_page int(11) DEFAULT '0' NOT NULL,
    content_element int(11) DEFAULT '0' NOT NULL,
    link_type varchar(32) DEFAULT '' NOT NULL,
    is_broken tinyint(1) DEFAULT '0' NOT NULL,
    weight DOUBLE PRECISION DEFAULT '1' NOT NULL,
    
    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY source (source_page),
    KEY target (target_page),
    KEY content (content_element)
);

#
# Table structure for table 'tx_pagelinkinsights_statistics'
#
CREATE TABLE tx_pagelinkinsights_statistics (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,
    
    site_root int(11) DEFAULT '0' NOT NULL,
    total_pages int(11) DEFAULT '0' NOT NULL,
    total_links int(11) DEFAULT '0' NOT NULL,
    broken_links_count int(11) DEFAULT '0' NOT NULL,
    orphaned_pages int(11) DEFAULT '0' NOT NULL,
    max_depth int(11) DEFAULT '0' NOT NULL,
    avg_links_per_page DOUBLE PRECISION DEFAULT '0' NOT NULL,
    network_density DOUBLE PRECISION DEFAULT '0' NOT NULL,
    
    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY site (site_root)
);

CREATE TABLE tx_pagelinkinsights_keywords (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,
    
    page_uid int(11) DEFAULT '0' NOT NULL,
    keyword varchar(255) DEFAULT '' NOT NULL,
    frequency int(11) DEFAULT '0' NOT NULL,
    weight DOUBLE PRECISION DEFAULT '0.00' NOT NULL,
    language int(11) DEFAULT '0' NOT NULL,
    content_type varchar(50) DEFAULT '' NOT NULL, 
    
    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY page (page_uid),
    KEY keyword (keyword),
    KEY language (language)
);

CREATE TABLE tx_pagelinkinsights_themes (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,
    
    theme_name varchar(255) DEFAULT '' NOT NULL,
    keywords text,
    weight DOUBLE PRECISION DEFAULT '0.00' NOT NULL,
    language int(11) DEFAULT '0' NOT NULL,
    
    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY theme (theme_name),
    KEY language (language)
);

CREATE TABLE tx_pagelinkinsights_page_themes (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,
    
    page_uid int(11) DEFAULT '0' NOT NULL,
    theme_uid int(11) DEFAULT '0' NOT NULL,
    relevance DOUBLE PRECISION DEFAULT '0.00' NOT NULL,
    
    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY page (page_uid),
    KEY theme (theme_uid)
);