document.addEventListener('DOMContentLoaded', function() {
    console.log('Script starting...');

    const dataElement = document.getElementById('diagram-data');
    const translationsElement = document.getElementById('diagram-translations');

    if (!dataElement) {
        console.error('diagram-data element not found');
        return;
    }

    if (!translationsElement) {
        console.error('diagram-translations element not found');
        return;
    }

    try {
        const diagramData = JSON.parse(dataElement.textContent.trim());
        const translations = JSON.parse(translationsElement.textContent.trim());
        console.log('Parsed data:', diagramData);
        console.log('Parsed translations:', translations);

        // Calculer le nombre de liens entrants pour chaque nœud
        const incomingLinks = {};
        diagramData.nodes.forEach(node => {
            incomingLinks[node.id] = 0;
        });

        diagramData.links.forEach(link => {
            incomingLinks[link.targetPageId] = (incomingLinks[link.targetPageId] || 0) + 1;
        });

        // Ajouter le nombre de liens entrants à chaque nœud
        diagramData.nodes = diagramData.nodes.map(node => ({
            ...node,
            incomingLinks: incomingLinks[node.id] || 0
        }));

        // Formater les liens pour la simulation
        const links = diagramData.links.map(link => ({
            source: link.sourcePageId, 
            target: link.targetPageId, 
            contentElement: link.contentElement,
            broken: link.broken
        }));

        console.log('Processed links:', links);

        const svgElement = document.getElementById('force-diagram');
        if (!svgElement) {
            console.error('force-diagram SVG element not found');
            return;
        }

        const container = document.getElementById('force-diagram-container');
        const width = container.clientWidth;
        const height = container.clientHeight;
        const baseNodeRadius = 20;  // Rayon de base pour les nœuds

        console.log('Dimensions:', { width, height });

        // Configurer le SVG
        const svg = d3.select("#force-diagram")
            .attr("width", "100%")
            .attr("height", "100%")
            .attr("viewBox", [0, 0, width, height]);

        console.log('SVG configured');

        // Créer un groupe pour le tooltip
        const tooltip = d3.select("body").append("div")
            .attr("class", "node-tooltip")
            .style("position", "absolute")
            .style("visibility", "hidden")
            .style("background-color", "#333")
            .style("color", "#fff")
            .style("border", "1px solid #555")
            .style("border-radius", "4px")
            .style("padding", "10px")
            .style("box-shadow", "0 2px 4px rgba(0,0,0,0.3)");

        // Initialiser le groupe `g` pour les éléments du diagramme
        const g = svg.append("g");

        // Échelle pour la taille des nœuds (sqrt pour une meilleure proportionnalité visuelle)
        const minNodeRadius = 12;
        const maxNodeRadius = 50;
        const maxIncomingLinks = d3.max(diagramData.nodes, d => d.incomingLinks) || 1;
        const nodeScale = d3.scaleSqrt()
            .domain([0, maxIncomingLinks])
            .range([minNodeRadius, maxNodeRadius]);

        // Calculer une couleur basée sur le thème principal de la page
        const themeColorScale = d3.scaleOrdinal()
            .range(d3.schemeCategory10); // Palette de couleurs prédéfinie

        // Extraire tous les thèmes uniques pour la palette de couleurs
        const uniqueThemes = Array.from(new Set(
            diagramData.nodes
                .filter(node => node.mainTheme && node.mainTheme.name)
                .map(node => node.mainTheme.name)
        ));
        themeColorScale.domain(uniqueThemes);

        // Définir les marqueurs pour les flèches
        svg.append("defs").selectAll("marker")
            .data(["end"])
            .join("marker")
            .attr("id", d => d)
            .attr("viewBox", "0 -5 10 10")
            .attr("refX", () => nodeScale(Math.max(0, d3.max(diagramData.nodes, d => d.incomingLinks) || 0)) + 10)
            .attr("refY", 0)
            .attr("markerWidth", 6)
            .attr("markerHeight", 6)
            .attr("orient", "auto")
            .append("path")
            .attr("d", "M0,-5L10,0L0,5")
            .attr("fill", "#999");

        // Couleurs pour les liens
        const linkColors = {
            'menu': '#00ffff', // Cyan
            'menu_sitemap_pages': '#00ffff',
            'html': '#ff00ff', // Magenta
            'typolink': '#ffcc00', // Jaune électrique
            'sitemap': '#cc00ff', // Violet électrique
            'text': '#00ffcc', // Cyan clair
            'semantic_suggestion': '#9c27b0' // Violet pour les liens sémantiques
        };
        
        // Filtrer les liens brisés
        const validLinks = links.filter(link => 
            diagramData.nodes.some(node => node.id === link.source) &&
            diagramData.nodes.some(node => node.id === link.target)
        );

        console.log('Valid links:', validLinks);

        // Create custom force to keep isolated nodes closer to main graph
        function forceIsolatedNodes() {
            const strength = 0.1;
            let nodes;

            function force(alpha) {
                // Find connected nodes (those with links)
                const connectedNodeIds = new Set();
                validLinks.forEach(link => {
                    connectedNodeIds.add(link.source.id || link.source);
                    connectedNodeIds.add(link.target.id || link.target);
                });

                // Calculate center of connected nodes
                const connectedNodes = nodes.filter(d => connectedNodeIds.has(d.id));
                if (connectedNodes.length === 0) return;

                const centerX = d3.mean(connectedNodes, d => d.x) || width / 2;
                const centerY = d3.mean(connectedNodes, d => d.y) || height / 2;

                // Apply force to isolated nodes to stay closer to center
                nodes.forEach(d => {
                    if (!connectedNodeIds.has(d.id)) {
                        const dx = centerX - d.x;
                        const dy = centerY - d.y;
                        const distance = Math.sqrt(dx * dx + dy * dy);

                        // Only apply force if node is too far from center
                        if (distance > 200) {
                            d.vx += dx * strength * alpha;
                            d.vy += dy * strength * alpha;
                        }
                    }
                });
            }

            force.initialize = (_nodes) => nodes = _nodes;
            return force;
        }

        // Créer la simulation de forces
        const simulation = d3.forceSimulation(diagramData.nodes)
            .force("link", d3.forceLink(validLinks) // Utiliser uniquement les liens valides
                .id(d => d.id)
                .distance(150))
            .force("charge", d3.forceManyBody().strength(-800)) // Reduced strength for better layout
            .force("center", d3.forceCenter(width / 2, height / 2))
            .force("collide", d3.forceCollide().radius(d => nodeScale(d.incomingLinks) + 10))
            .force("isolatedNodes", forceIsolatedNodes()); // Keep isolated nodes closer

        // Fonction pour créer des clusters basés sur les thèmes
        function forceCluster() {
            const strength = 0.15;
            let nodes;

            // Centres des clusters par thème
            const centroids = {};
            
            function force(alpha) {
                // Pour chaque nœud
                for (const d of nodes) {
                    if (d.mainTheme && d.mainTheme.name) {
                        const theme = d.mainTheme.name;
                        
                        // Si pas encore de centroïde pour ce thème, en créer un
                        if (!centroids[theme]) {
                            centroids[theme] = {
                                x: width/2 + (Math.random() - 0.5) * width/4, 
                                y: height/2 + (Math.random() - 0.5) * height/4
                            };
                        }
                        
                        // Attirer le nœud vers le centroïde de son thème
                        d.vx += (centroids[theme].x - d.x) * strength * alpha;
                        d.vy += (centroids[theme].y - d.y) * strength * alpha;
                    }
                }
            }
            
            force.initialize = (_nodes) => nodes = _nodes;
            
            return force;
        }

        // Ajouter la force de clustering à la simulation si des thèmes sont présents
        if (uniqueThemes.length > 0) {
            simulation.force("cluster", forceCluster());
        }

        // Créer les liens
        const link = g.append("g")
            .attr("class", "links")
            .selectAll("line")
            .data(validLinks)
            .join("line")
            .attr("stroke", d => d.broken ? "#ff0000" : linkColors[d.contentElement?.type] || '#999')
            .attr("stroke-width", 2)
            .attr("stroke-dasharray", d => d.broken ? "5,5" : null)
            .attr("marker-end", d => d.broken ? null : "url(#end)")
            .on("mouseover", function(event, d) {
                if (d.broken) {
                    tooltip.style("visibility", "visible")
                        .html(`
                            <strong>Broken Link</strong><br>
                            Source: ${d.source}<br>
                            Target: ${d.target}<br>
                            <em>The target page does not exist.</em>
                        `);
                } else if (d.contentElement?.type === 'semantic_suggestion') {
                    tooltip.style("visibility", "visible")
                        .html(`
                            <strong>Semantic Suggestion</strong><br>
                            Source: Page #${d.source.id || d.source}<br>
                            Target: Page #${d.target.id || d.target}<br>
                            ${d.similarity ? `Score: ${(d.similarity * 100).toFixed(1)}%<br>` : ''}
                            <em>Automatically generated by content analysis</em>
                        `);
                }
            })
            .on("mousemove", function(event) {
                tooltip.style("top", (event.pageY + 10) + "px")
                    .style("left", (event.pageX + 10) + "px");
            })
            .on("mouseout", function() {
                tooltip.style("visibility", "hidden");
            });

        // Créer les nœuds
        const node = g.append("g")
            .attr("class", "nodes")
            .selectAll("g")
            .data(diagramData.nodes)
            .join("g")
            .attr("class", "node")
            .call(drag(simulation));

        // Style sombre pour le fond et les nœuds
        svg.style("background-color", "#1e1e1e"); // Fond sombre

        // Cercles pour les nœuds avec taille variable et couleurs basées sur les thèmes
        node.append("circle")
            .attr("r", d => nodeScale(d.incomingLinks))
            .attr("fill", d => {
                // Si le nœud a un thème principal, utiliser une couleur basée sur ce thème
                if (d.mainTheme && d.mainTheme.name) {
                    return themeColorScale(d.mainTheme.name);
                }
                // Sinon, utiliser la couleur par défaut
                return "#003300"; // Vert Matrix foncé
            })
            .attr("stroke", d => {
                // Intensité de la bordure basée sur la pertinence du thème
                if (d.mainTheme && d.mainTheme.relevance) {
                    // Plus la pertinence est élevée, plus la bordure est brillante
                    const brightness = Math.min(255, Math.round(d.mainTheme.relevance * 10));
                    return `rgb(0, ${brightness}, 0)`;
                }
                return "#00ff00"; // Bordure verte fluo par défaut
            })
            .attr("stroke-width", 2);

        // Texte pour les nœuds
        node.append("text")
            .attr("dx", d => nodeScale(d.incomingLinks) + 5)
            .attr("dy", ".35em")
            .text(d => d.title)
            .attr("fill", "#00ff00") // Texte vert fluo
            .attr("font-family", "Arial")
            .attr("font-size", "12px");

        // Gestion des événements pour les nœuds
        node
            .on("mouseover", function(event, d) {
                let themeHtml = '';
                
                // Ajouter les informations de thème si disponibles
                if (d.themes && d.themes.length > 0) {
                    themeHtml = `<br><strong>${translations.themes}</strong><br>`;
                    d.themes.slice(0, 3).forEach(theme => {
                        themeHtml += `${theme.theme} (${theme.relevance.toFixed(1)})<br>`;
                    });
                }
                
                tooltip.style("visibility", "visible")
                    .html(`
                        <strong>${d.title}</strong><br>
                        ID: ${d.id}<br>
                        ${translations.incomingLinks} ${d.incomingLinks}
                        ${themeHtml}
                        <em>${translations.ctrlClickToOpen}<br>
                        ${translations.rightClickToRemove}</em>
                    `);
            })
            .on("mousemove", function(event) {
                tooltip.style("top", (event.pageY + 10) + "px")
                    .style("left", (event.pageX + 10) + "px");
            })
            .on("mouseout", function() {
                tooltip.style("visibility", "hidden");
            })
            .on("click", function(event, d) {
                if (event.ctrlKey || event.metaKey) {
                    // Récupérer le domaine courant et ouvrir la page dans le module Page de TYPO3
                    const baseUrl = window.location.origin;
                    const typo3Url = `${baseUrl}/typo3/module/web/layout?id=${d.id}`;
                    window.open(typo3Url, '_blank');
                }
            })
            .on("contextmenu", function(event, d) {
                event.preventDefault();
            
                // 1. Supprimer le nœud
                diagramData.nodes = diagramData.nodes.filter(node => node.id !== d.id);
            
                // 2. Supprimer les liens connectés au nœud
                diagramData.links = diagramData.links.filter(link => 
                    link.sourcePageId !== d.id && link.targetPageId !== d.id
                );
            
                // 3. Créer les objets de liens appropriés pour D3
                const d3Links = diagramData.links.map(link => ({
                    source: diagramData.nodes.find(node => node.id === link.sourcePageId),
                    target: diagramData.nodes.find(node => node.id === link.targetPageId),
                    contentElement: link.contentElement,
                    broken: link.broken
                })).filter(link => link.source && link.target); // Filtrer les liens avec des nœuds manquants
            
                // 4. Mettre à jour les sélections D3
                // Mise à jour des nœuds
                const nodeSelection = g.selectAll('.node')
                    .data(diagramData.nodes, node => node.id);
                
                nodeSelection.exit().remove();
                
                // Mise à jour des liens
                const linkSelection = g.selectAll('line')
                    .data(d3Links);
                
                linkSelection.exit().remove();
            
                // 5. Mettre à jour la simulation
                simulation.nodes(diagramData.nodes);
                simulation.force("link").links(d3Links);
            
                // 6. Redémarrer la simulation
                simulation.alpha(1).restart();
            
                // Debug
                console.log("Nodes après suppression:", diagramData.nodes);
                console.log("Liens après suppression:", d3Links);
            });

        // Ajouter une légende pour les thèmes si des thèmes sont présents
        if (uniqueThemes.length > 0) {
            // Responsive positioning for themes legend
            const legendWidth = 180; // Estimated legend width
            const padding = 20; // Minimum padding from edge
            let legendX = Math.max(padding, width - legendWidth - padding);

            // On smaller screens, position vertically
            if (width < 768) {
                legendX = padding;
            }

            const legend = svg.append("g")
                .attr("class", "legend themes-legend")
                .attr("transform", `translate(${legendX}, 30)`);

            // Titre de la légende
            legend.append("text")
                .attr("x", 0)
                .attr("y", -10)
                .attr("fill", "#00ff00")
                .text(translations.dominantThemes);

            // Éléments de la légende
            uniqueThemes.forEach((theme, i) => {
                const legendItem = legend.append("g")
                    .attr("transform", `translate(0, ${i * 25})`);
                    
                legendItem.append("rect")
                    .attr("width", 20)
                    .attr("height", 20)
                    .attr("fill", themeColorScale(theme));
                    
                legendItem.append("text")
                    .attr("x", 30)
                    .attr("y", 15)
                    .attr("fill", "#00ff00")
                    .text(theme);
            });
        }

        // Ajouter une légende pour les types de liens
            // Responsive positioning for link types legend
            let linkLegendY = 30;

            // On smaller screens, position link legend below theme legend if it exists
            if (width < 768 && uniqueThemes.length > 0) {
                const themeItemsHeight = uniqueThemes.length * 25;
                linkLegendY = 60 + themeItemsHeight; // Below theme legend with some spacing
            }

            const linkLegend = svg.append("g")
            .attr("class", "link-legend")
            .attr("transform", `translate(20, ${linkLegendY})`);

            // Titre de la légende
            linkLegend.append("text")
            .attr("x", 0)
            .attr("y", -10)
            .attr("fill", "#00ff00")
            .text(translations.linkTypes);

            // Définir les types de liens pour la légende
            const linkTypes = [
                { type: "standard", color: "#999", dash: null, label: translations.standardLinks },
                { type: "semantic", color: "#9c27b0", dash: "8,4", label: translations.semanticSuggestions },
                { type: "broken", color: "#ff0000", dash: "5,5", label: translations.brokenLinks }
            ];

            // Éléments de la légende
            linkTypes.forEach((linkType, i) => {
            const legendItem = linkLegend.append("g")
                .attr("transform", `translate(0, ${i * 25})`)
                .attr("class", "legend-item")
                .style("cursor", "pointer");

            // Background for click area
            legendItem.append("rect")
                .attr("x", -5)
                .attr("y", -2)
                .attr("width", 150)
                .attr("height", 20)
                .attr("fill", "transparent")
                .attr("stroke", "none");

            // Ligne représentant le lien
            legendItem.append("line")
                .attr("x1", 0)
                .attr("y1", 10)
                .attr("x2", 20)
                .attr("y2", 10)
                .attr("stroke", linkType.color)
                .attr("stroke-width", 2)
                .attr("stroke-dasharray", linkType.dash);

            legendItem.append("text")
                .attr("x", 30)
                .attr("y", 15)
                .attr("fill", "#00ff00")
                .text(linkType.label);

            // Add interactivity
            legendItem.on("click", function(event, d) {
                const filterMap = {
                    'standard': 'filter-standard',
                    'semantic': 'filter-semantic',
                    'broken': 'filter-broken'
                };

                const checkboxId = filterMap[linkType.type];
                if (checkboxId) {
                    const checkbox = document.getElementById(checkboxId);
                    if (checkbox) {
                        checkbox.checked = !checkbox.checked;
                        checkbox.dispatchEvent(new Event('change'));
                    }
                }
            })
            .on("mouseover", function() {
                legendItem.select("rect").attr("fill", "rgba(0, 255, 0, 0.1)");
                legendItem.select("text").attr("fill", "#ffffff");
            })
            .on("mouseout", function() {
                legendItem.select("rect").attr("fill", "transparent");
                legendItem.select("text").attr("fill", "#00ff00");
            });
            });
            
        // Mettre à jour la fonction tick pour utiliser les bonnes références
        simulation.on("tick", () => {
            g.selectAll("line")
                .attr("x1", d => d.source.x)
                .attr("y1", d => d.source.y)
                .attr("x2", d => d.target.x)
                .attr("y2", d => d.target.y);
        
            g.selectAll(".node")
                .attr("transform", d => `translate(${d.x},${d.y})`);
        });

        // Ajouter le zoom et le déplacement
        const zoom = d3.zoom()
            .scaleExtent([0.1, 4])
            .on("zoom", (event) => {
                g.attr("transform", event.transform);
            });
    
        svg.call(zoom);

        // Add window resize handler for responsive legends
        function updateLegendsPosition() {
            const currentWidth = container.clientWidth;

            // Update themes legend position
            const themesLegend = svg.select('.themes-legend');
            if (!themesLegend.empty()) {
                const legendWidth = 180;
                const padding = 20;
                let legendX = Math.max(padding, currentWidth - legendWidth - padding);

                if (currentWidth < 768) {
                    legendX = padding;
                }

                themesLegend.attr("transform", `translate(${legendX}, 30)`);
            }

            // Update link legend position
            const linkLegend = svg.select('.link-legend');
            if (!linkLegend.empty()) {
                let linkLegendY = 30;

                if (currentWidth < 768 && uniqueThemes.length > 0) {
                    const themeItemsHeight = uniqueThemes.length * 25;
                    linkLegendY = 60 + themeItemsHeight;
                }

                linkLegend.attr("transform", `translate(20, ${linkLegendY})`);
            }
        }

        // Listen for window resize events
        window.addEventListener('resize', () => {
            updateLegendsPosition();
        });

        // Initialize fit-to-window button
        function initializeFitButton() {
            const fitButton = document.getElementById('fit-to-window-btn');
            const buttonText = fitButton.querySelector('.btn-text');

            if (buttonText && translations.fitToWindow) {
                buttonText.textContent = translations.fitToWindow;
            }

            if (fitButton) {
                fitButton.addEventListener('click', fitToWindow);
            }
        }

        // Fit all nodes to window viewport
        function fitToWindow() {
            const bounds = g.node().getBBox();
            const fullWidth = width;
            const fullHeight = height;
            const widthScale = fullWidth / bounds.width;
            const heightScale = fullHeight / bounds.height;
            const scale = Math.min(widthScale, heightScale) * 0.8; // 80% to add padding

            const centerX = bounds.x + bounds.width / 2;
            const centerY = bounds.y + bounds.height / 2;
            const translate = [fullWidth / 2 - scale * centerX, fullHeight / 2 - scale * centerY];

            svg.transition()
                .duration(750)
                .call(zoom.transform, d3.zoomIdentity.translate(translate[0], translate[1]).scale(scale));
        }

        // Initialize the fit button
        initializeFitButton();

        // Initialize fullscreen mode button
        function initializeFullscreenButton() {
            const fullscreenButton = document.getElementById('fullscreen-btn');
            const buttonText = fullscreenButton?.querySelector('.btn-text');

            if (!fullscreenButton) return;

            // Update button text based on translations
            function updateButtonText(isFullscreen) {
                if (buttonText) {
                    buttonText.textContent = isFullscreen
                        ? (translations.exitFullscreen || 'Exit Fullscreen')
                        : (translations.fullscreen || 'Fullscreen');
                }
            }

            // Toggle fullscreen mode
            function toggleFullscreen() {
                const isFullscreen = document.body.classList.toggle('fullscreen-mode');
                fullscreenButton.classList.toggle('active', isFullscreen);
                updateButtonText(isFullscreen);

                // Update SVG viewBox after transition
                setTimeout(() => {
                    const newWidth = container.clientWidth;
                    const newHeight = container.clientHeight;
                    svg.attr("viewBox", [0, 0, newWidth, newHeight]);

                    // Re-center the simulation
                    simulation.force("center", d3.forceCenter(newWidth / 2, newHeight / 2));
                    simulation.alpha(0.3).restart();
                }, 100);

                // Store preference in localStorage
                try {
                    localStorage.setItem('page_link_insights_fullscreen', isFullscreen ? 'true' : 'false');
                } catch (e) {
                    console.warn('Could not save fullscreen preference:', e);
                }
            }

            // Handle button click
            fullscreenButton.addEventListener('click', toggleFullscreen);

            // Handle Escape key to exit fullscreen
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && document.body.classList.contains('fullscreen-mode')) {
                    toggleFullscreen();
                }
            });

            // Restore fullscreen preference from localStorage
            try {
                const savedPreference = localStorage.getItem('page_link_insights_fullscreen');
                if (savedPreference === 'true') {
                    toggleFullscreen();
                }
            } catch (e) {
                console.warn('Could not restore fullscreen preference:', e);
            }
        }

        // Initialize the fullscreen button
        initializeFullscreenButton();

        // Initialize translations for all elements
        function initializeTranslations() {
            // Handle data-translation attributes
            const translationElements = document.querySelectorAll('[data-translation]');
            translationElements.forEach(element => {
                const key = element.getAttribute('data-translation');
                if (translations[key]) {
                    element.textContent = translations[key];
                }
            });

        }

        // Initialize dismissible alerts and badges
        function initializeDismissibleAlerts() {
            // Get all dismissible elements (alerts and badges)
            const dismissibleAlerts = document.querySelectorAll('.dismissible-alert');
            const dismissibleBadges = document.querySelectorAll('.dismissible-badge');
            const dismissedAlertsKey = 'page_link_insights_dismissed_alerts';

            // Get dismissed alerts from localStorage
            let dismissedAlerts = [];
            try {
                const stored = localStorage.getItem(dismissedAlertsKey);
                dismissedAlerts = stored ? JSON.parse(stored) : [];
            } catch (e) {
                console.warn('Could not parse dismissed alerts from localStorage:', e);
            }

            // Hide already dismissed alerts and badges
            dismissedAlerts.forEach(alertId => {
                const element = document.getElementById(alertId);
                if (element) {
                    element.style.display = 'none';
                    element.classList.add('dismissed');
                }
            });

            // Initialize badge dismiss buttons
            dismissibleBadges.forEach(badge => {
                const dismissBtn = badge.querySelector('.badge-dismiss-btn');
                if (dismissBtn) {
                    dismissBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();

                        const badgeId = badge.id;
                        badge.classList.add('dismissed');
                        badge.style.display = 'none';

                        if (badgeId && !dismissedAlerts.includes(badgeId)) {
                            dismissedAlerts.push(badgeId);
                            try {
                                localStorage.setItem(dismissedAlertsKey, JSON.stringify(dismissedAlerts));
                            } catch (e) {
                                console.warn('Could not save dismissed badge to localStorage:', e);
                            }
                            updateNoticesButton();
                        }
                    });
                }
            });


            // Initialize the show notices button
            updateNoticesButton();

            function updateNoticesButton() {
                const showNoticesBtn = document.getElementById('show-notices-btn');
                const noticesCount = document.querySelector('.notices-count');

                if (!showNoticesBtn || !noticesCount) return;

                if (dismissedAlerts.length > 0) {
                    showNoticesBtn.style.display = 'flex';
                    noticesCount.textContent = dismissedAlerts.length;

                    // Remove existing listeners to avoid duplicates
                    showNoticesBtn.replaceWith(showNoticesBtn.cloneNode(true));
                    const newShowNoticesBtn = document.getElementById('show-notices-btn');

                    newShowNoticesBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();

                        // Restore all dismissed alerts and badges
                        dismissedAlerts.forEach((alertId, index) => {
                            const element = document.getElementById(alertId);
                            if (element) {
                                element.classList.remove('dismissed', 'fade-out');
                                // Check if it's a badge or alert
                                if (element.classList.contains('badge')) {
                                    element.style.display = 'inline-flex';
                                } else {
                                    element.style.display = 'block';
                                    element.style.opacity = '0';
                                    element.style.transform = 'translateY(-10px)';
                                    setTimeout(() => {
                                        element.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                                        element.style.opacity = '1';
                                        element.style.transform = 'translateY(0)';
                                    }, 50);
                                }
                            }
                        });

                        // Clear localStorage and hide button
                        try {
                            localStorage.removeItem(dismissedAlertsKey);
                        } catch (e) {
                            console.warn('Could not clear dismissed alerts from localStorage:', e);
                        }

                        dismissedAlerts.length = 0; // Clear the array
                        newShowNoticesBtn.style.display = 'none';

                        // Show temporary success message
                        const restoredMessage = translations.noticesRestoredMessage || 'Notices restored';
                        showTemporaryMessage(restoredMessage);
                    });
                } else {
                    showNoticesBtn.style.display = 'none';
                }
            }

            function showTemporaryMessage(message) {
                const messageDiv = document.createElement('div');
                messageDiv.textContent = message;
                messageDiv.style.cssText = `
                    position: fixed;
                    top: 60px;
                    right: 20px;
                    background-color: #28a745;
                    color: white;
                    padding: 8px 16px;
                    border-radius: 4px;
                    font-size: 12px;
                    z-index: 10000;
                    opacity: 0;
                    transition: opacity 0.3s ease;
                `;

                document.body.appendChild(messageDiv);

                // Fade in
                setTimeout(() => messageDiv.style.opacity = '1', 50);

                // Fade out and remove
                setTimeout(() => {
                    messageDiv.style.opacity = '0';
                    setTimeout(() => messageDiv.remove(), 300);
                }, 2000);
            }

            // Update the dismiss functionality to refresh the notices button
            dismissibleAlerts.forEach(alert => {
                const dismissBtn = alert.querySelector('.alert-dismiss-btn');
                if (dismissBtn) {
                    dismissBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();

                        const alertId = alert.id;

                        // Animate dismissal
                        alert.classList.add('fade-out');

                        // After animation completes, hide element and update localStorage
                        setTimeout(() => {
                            alert.style.display = 'none';

                            // Save to localStorage
                            if (alertId && !dismissedAlerts.includes(alertId)) {
                                dismissedAlerts.push(alertId);
                                try {
                                    localStorage.setItem(dismissedAlertsKey, JSON.stringify(dismissedAlerts));
                                } catch (e) {
                                    console.warn('Could not save dismissed alerts to localStorage:', e);
                                }
                                // Update the notices button
                                updateNoticesButton();
                            }
                        }, 300); // Match CSS transition duration
                    });
                }
            });
        }

        // Initialize help panel functionality
        function initializeHelpPanel() {
            const helpButton = document.getElementById('help-btn');
            const helpPanel = document.getElementById('help-panel');
            const helpCloseButton = document.getElementById('help-close-btn');

            if (helpButton && helpPanel) {
                helpButton.addEventListener('click', function() {
                    const isVisible = helpPanel.style.display !== 'none';
                    helpPanel.style.display = isVisible ? 'none' : 'block';
                });
            }

            if (helpCloseButton && helpPanel) {
                helpCloseButton.addEventListener('click', function() {
                    helpPanel.style.display = 'none';
                });
            }

            // Close help panel when clicking outside
            document.addEventListener('click', function(event) {
                if (helpPanel && helpButton) {
                    const isClickInsidePanel = helpPanel.contains(event.target);
                    const isClickOnButton = helpButton.contains(event.target);

                    if (!isClickInsidePanel && !isClickOnButton && helpPanel.style.display === 'block') {
                        helpPanel.style.display = 'none';
                    }
                }
            });
        }

        // Initialize filters panel functionality
        function initializeFiltersPanel() {
            const filtersButton = document.getElementById('filters-btn');
            const filtersPanel = document.getElementById('filters-panel');
            const filtersCloseButton = document.getElementById('filters-close-btn');
            const selectAllButton = document.getElementById('filter-select-all');
            const deselectAllButton = document.getElementById('filter-deselect-all');

            // Filter state tracking
            const filterState = {
                standard: true,
                semantic: true,
                broken: true,
                menu: true,
                html: true,
                typolink: true,
                orphanedNodes: true,
                maxDepth: 10,
                depthFromRoot: false
            };

            if (filtersButton && filtersPanel) {
                filtersButton.addEventListener('click', function() {
                    const isVisible = filtersPanel.style.display !== 'none';
                    filtersPanel.style.display = isVisible ? 'none' : 'block';
                });
            }

            if (filtersCloseButton && filtersPanel) {
                filtersCloseButton.addEventListener('click', function() {
                    filtersPanel.style.display = 'none';
                });
            }

            // Close filters panel when clicking outside
            document.addEventListener('click', function(event) {
                if (filtersPanel && filtersButton) {
                    const isClickInsidePanel = filtersPanel.contains(event.target);
                    const isClickOnButton = filtersButton.contains(event.target);

                    if (!isClickInsidePanel && !isClickOnButton && filtersPanel.style.display === 'block') {
                        filtersPanel.style.display = 'none';
                    }
                }
            });

            // Select all button
            if (selectAllButton) {
                selectAllButton.addEventListener('click', function() {
                    Object.keys(filterState).forEach(key => {
                        filterState[key] = true;
                        const checkbox = document.getElementById(`filter-${key.replace(/([A-Z])/g, '-$1').toLowerCase()}`);
                        if (checkbox) checkbox.checked = true;
                    });
                    applyFilters();
                });
            }

            // Deselect all button
            if (deselectAllButton) {
                deselectAllButton.addEventListener('click', function() {
                    Object.keys(filterState).forEach(key => {
                        filterState[key] = false;
                        const checkbox = document.getElementById(`filter-${key.replace(/([A-Z])/g, '-$1').toLowerCase()}`);
                        if (checkbox) checkbox.checked = false;
                    });
                    applyFilters();
                });
            }

            // Individual filter checkboxes
            Object.keys(filterState).forEach(key => {
                if (key === 'maxDepth' || key === 'depthFromRoot') return; // Handle these separately
                const checkboxId = `filter-${key.replace(/([A-Z])/g, '-$1').toLowerCase()}`;
                const checkbox = document.getElementById(checkboxId);
                if (checkbox) {
                    checkbox.addEventListener('change', function() {
                        filterState[key] = this.checked;
                        applyFilters();
                    });
                }
            });

            // Depth slider control
            const depthSlider = document.getElementById('depth-slider');
            const depthValue = document.getElementById('depth-value');
            const depthFromRootCheckbox = document.getElementById('depth-from-root');

            if (depthSlider && depthValue) {
                depthSlider.addEventListener('input', function() {
                    filterState.maxDepth = parseInt(this.value);
                    depthValue.textContent = filterState.maxDepth === 10 ? '∞' : filterState.maxDepth.toString();
                    applyFilters();
                });
            }

            if (depthFromRootCheckbox) {
                depthFromRootCheckbox.addEventListener('change', function() {
                    filterState.depthFromRoot = this.checked;
                    applyFilters();
                });
            }

            // Function to determine if a link should be visible based on filters
            function shouldShowLink(linkData) {
                // Handle broken links
                if (linkData.broken) {
                    return filterState.broken;
                }

                // Handle semantic suggestions
                if (linkData.contentElement?.type === 'semantic_suggestion') {
                    return filterState.semantic;
                }

                // Handle specific link types based on content element type
                const linkType = linkData.contentElement?.type;

                if (linkType && (linkType.includes('menu') || linkType === 'menu_sitemap_pages')) {
                    return filterState.menu;
                }

                if (linkType === 'html') {
                    return filterState.html;
                }

                if (linkType === 'typolink') {
                    return filterState.typolink;
                }

                // Default to standard links
                return filterState.standard;
            }

            // Function to calculate node depths from a root node
            function calculateNodeDepths(rootNodeId = null) {
                const depths = {};
                const visited = new Set();

                // If no root specified, find the root node (assuming it's the one with lowest ID or specific properties)
                let actualRootId = rootNodeId;
                if (!actualRootId) {
                    // Find root node - could be the homepage (ID 1) or the node with the most incoming links
                    const rootCandidate = diagramData.nodes.find(node => node.id === '1') ||
                                        diagramData.nodes.reduce((prev, current) =>
                                            prev.incomingLinks > current.incomingLinks ? prev : current
                                        );
                    actualRootId = rootCandidate.id;
                }

                function dfs(nodeId, depth) {
                    if (visited.has(nodeId) || depth > filterState.maxDepth) return;

                    visited.add(nodeId);
                    depths[nodeId] = depth;

                    // Find all connected nodes
                    validLinks.forEach(link => {
                        const sourceId = link.source.id || link.source;
                        const targetId = link.target.id || link.target;

                        if (sourceId === nodeId && !visited.has(targetId)) {
                            dfs(targetId, depth + 1);
                        }
                    });
                }

                dfs(actualRootId, 0);
                return depths;
            }

            // Function to determine if a node should be visible
            function shouldShowNode(nodeData, visibleLinks, nodeDepths = {}) {
                // Check depth filtering
                if (filterState.maxDepth < 10) {
                    const nodeDepth = nodeDepths[nodeData.id];
                    if (nodeDepth === undefined || nodeDepth > filterState.maxDepth) {
                        return false;
                    }
                }

                // If orphaned nodes filter is off, only show nodes with connections
                if (!filterState.orphanedNodes) {
                    const hasConnection = visibleLinks.some(link =>
                        (link.source.id || link.source) === nodeData.id ||
                        (link.target.id || link.target) === nodeData.id
                    );
                    return hasConnection;
                }
                return true;
            }

            // Apply filters function
            function applyFilters() {
                // Calculate node depths if depth filtering is enabled
                let nodeDepths = {};
                if (filterState.maxDepth < 10) {
                    nodeDepths = calculateNodeDepths(filterState.depthFromRoot ? '1' : null);
                }

                // Filter links
                const filteredLinks = validLinks.filter(shouldShowLink);

                // Filter nodes based on link visibility, depth, and orphaned nodes setting
                const filteredNodes = diagramData.nodes.filter(node =>
                    shouldShowNode(node, filteredLinks, nodeDepths)
                );

                // Update link visualization
                const linkSelection = g.selectAll('.links line')
                    .style('display', d => shouldShowLink(d) ? 'block' : 'none');

                // Update node visibility based on connections and filter settings
                const nodeSelection = g.selectAll('.node')
                    .style('display', d => shouldShowNode(d, filteredLinks, nodeDepths) ? 'block' : 'none');

                // Update simulation with filtered data for physics calculations
                simulation.force("link").links(filteredLinks);
                simulation.alpha(0.3).restart();

                console.log(`Filtered: ${filteredLinks.length} links, ${filteredNodes.length} nodes visible`);
            }

            // Expose applyFilters for external use
            window.applyLinkFilters = applyFilters;
        }

        // Initialize metrics table functionality
        function initializeMetricsTable() {
            const metricsContainer = document.getElementById('metrics-table-container');
            const metricsContent = document.getElementById('metrics-table-content');
            const toggleBtn = document.getElementById('metrics-toggle-btn');
            const table = document.getElementById('pagerank-table');

            if (!metricsContainer || !toggleBtn) return;

            // Initialize column help tooltips with floating tooltip
            const helpIcons = document.querySelectorAll('.column-help[data-tooltip-key]');
            let floatingTooltip = null;

            helpIcons.forEach(icon => {
                const tooltipKey = icon.getAttribute('data-tooltip-key');
                const tooltipText = translations[tooltipKey] || '';

                icon.addEventListener('mouseenter', function(e) {
                    if (!tooltipText) return;

                    // Create tooltip if it doesn't exist
                    if (!floatingTooltip) {
                        floatingTooltip = document.createElement('div');
                        floatingTooltip.className = 'column-tooltip';
                        document.body.appendChild(floatingTooltip);
                    }

                    floatingTooltip.textContent = tooltipText;
                    floatingTooltip.style.display = 'block';

                    // Position tooltip below the icon
                    const rect = icon.getBoundingClientRect();
                    floatingTooltip.style.left = (rect.left + rect.width / 2 - 140) + 'px';
                    floatingTooltip.style.top = (rect.bottom + 8) + 'px';
                });

                icon.addEventListener('mouseleave', function() {
                    if (floatingTooltip) {
                        floatingTooltip.style.display = 'none';
                    }
                });
            });

            // Toggle visibility
            toggleBtn.addEventListener('click', function() {
                const isVisible = metricsContent.style.display !== 'none';
                metricsContent.style.display = isVisible ? 'none' : 'block';
                toggleBtn.classList.toggle('active', !isVisible);

                const btnText = toggleBtn.querySelector('.btn-text');
                if (btnText) {
                    btnText.textContent = isVisible
                        ? (translations.tableToggleShow || 'Show Page Metrics')
                        : (translations.tableToggleHide || 'Hide Page Metrics');
                }
            });

            if (!table) return;

            // Sort state
            let currentSort = { column: 'pagerank', direction: 'desc' };

            // Sorting functionality
            const headers = table.querySelectorAll('th.sortable');
            headers.forEach(header => {
                header.addEventListener('click', function() {
                    const sortKey = this.getAttribute('data-sort');

                    // Toggle direction if same column, otherwise default to desc
                    if (currentSort.column === sortKey) {
                        currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
                    } else {
                        currentSort.column = sortKey;
                        currentSort.direction = 'desc';
                    }

                    // Update header classes
                    headers.forEach(h => {
                        h.classList.remove('sorted-asc', 'sorted-desc');
                    });
                    this.classList.add(currentSort.direction === 'asc' ? 'sorted-asc' : 'sorted-desc');

                    // Sort the table
                    sortTable(sortKey, currentSort.direction);
                });
            });

            function sortTable(column, direction) {
                const tbody = table.querySelector('tbody');
                const rows = Array.from(tbody.querySelectorAll('tr'));

                const columnMap = {
                    'title': '.page-title',
                    'pagerank': '.pagerank',
                    'inbound': '.inbound',
                    'outbound': '.outbound',
                    'centrality': '.centrality'
                };

                rows.sort((a, b) => {
                    const aCell = a.querySelector(columnMap[column]);
                    const bCell = b.querySelector(columnMap[column]);

                    let aVal = aCell ? aCell.textContent.trim() : '';
                    let bVal = bCell ? bCell.textContent.trim() : '';

                    // For numeric columns, parse as float
                    if (column !== 'title') {
                        aVal = parseFloat(aVal) || 0;
                        bVal = parseFloat(bVal) || 0;
                    }

                    let comparison = 0;
                    if (aVal < bVal) comparison = -1;
                    if (aVal > bVal) comparison = 1;

                    return direction === 'asc' ? comparison : -comparison;
                });

                // Reorder rows in DOM
                rows.forEach(row => tbody.appendChild(row));
            }

            // Row click handler to highlight node in diagram
            const rows = table.querySelectorAll('tbody tr.metrics-row');
            rows.forEach(row => {
                row.addEventListener('click', function() {
                    const pageId = this.getAttribute('data-page-id');

                    // Remove previous highlight from table
                    rows.forEach(r => r.classList.remove('highlighted'));
                    this.classList.add('highlighted');

                    // Highlight node in diagram
                    highlightNode(pageId);
                });
            });
        }

        // Highlight a node in the diagram
        function highlightNode(pageId) {
            // Find the node data
            const nodeData = diagramData.nodes.find(n => String(n.id) === String(pageId));
            if (!nodeData) {
                console.warn('Node not found for page ID:', pageId);
                return;
            }

            // Remove previous highlights
            g.selectAll('.node circle')
                .classed('highlighted-node', false)
                .attr('stroke-width', 2);

            // Find and highlight the node
            const targetNode = g.selectAll('.node')
                .filter(d => String(d.id) === String(pageId));

            if (!targetNode.empty()) {
                // Add highlight style
                targetNode.select('circle')
                    .classed('highlighted-node', true)
                    .attr('stroke', '#ffffff')
                    .attr('stroke-width', 4);

                // Get node position
                const nodeX = nodeData.x;
                const nodeY = nodeData.y;

                // Calculate transform to center on node
                const currentWidth = container.clientWidth;
                const currentHeight = container.clientHeight;
                const scale = 1.5; // Zoom in a bit

                const translateX = currentWidth / 2 - nodeX * scale;
                const translateY = currentHeight / 2 - nodeY * scale;

                // Smooth transition to center on node
                svg.transition()
                    .duration(750)
                    .call(zoom.transform, d3.zoomIdentity.translate(translateX, translateY).scale(scale));

                // Show tooltip for the node
                let themeHtml = '';
                if (nodeData.themes && nodeData.themes.length > 0) {
                    themeHtml = `<br><strong>${translations.themes}</strong><br>`;
                    nodeData.themes.slice(0, 3).forEach(theme => {
                        themeHtml += `${theme.theme} (${theme.relevance.toFixed(1)})<br>`;
                    });
                }

                tooltip.style("visibility", "visible")
                    .html(`
                        <strong>${nodeData.title}</strong><br>
                        ID: ${nodeData.id}<br>
                        ${translations.incomingLinks} ${nodeData.incomingLinks}
                        ${themeHtml}
                        <em>${translations.ctrlClickToOpen}</em>
                    `)
                    .style("top", (currentHeight / 2 + 50) + "px")
                    .style("left", (currentWidth / 2) + "px");

                // Hide tooltip after 3 seconds
                setTimeout(() => {
                    tooltip.style("visibility", "hidden");
                }, 3000);

                // Add pulse animation
                targetNode.select('circle')
                    .transition()
                    .duration(200)
                    .attr('r', d => nodeScale(d.incomingLinks) * 1.3)
                    .transition()
                    .duration(200)
                    .attr('r', d => nodeScale(d.incomingLinks))
                    .transition()
                    .duration(200)
                    .attr('r', d => nodeScale(d.incomingLinks) * 1.2)
                    .transition()
                    .duration(200)
                    .attr('r', d => nodeScale(d.incomingLinks));
            }
        }

        // Expose highlightNode globally for external use
        window.highlightDiagramNode = highlightNode;

        // Initialize all translations, help panel, filters panel, dismissible alerts, and metrics table
        initializeTranslations();
        initializeHelpPanel();
        initializeFiltersPanel();
        initializeDismissibleAlerts();
        initializeMetricsTable();

        function drag(simulation) {
            function dragstarted(event) {
                if (!event.active) simulation.alphaTarget(0.3).restart();
                event.subject.fx = event.subject.x;
                event.subject.fy = event.subject.y;
            }

            function dragged(event) {
                event.subject.fx = event.x;
                event.subject.fy = event.y;
            }

            function dragended(event) {
                if (!event.active) simulation.alphaTarget(0);
                event.subject.fx = null;
                event.subject.fy = null;
            }

            return d3.drag()
                .on("start", dragstarted)
                .on("drag", dragged)
                .on("end", dragended);
        }

        console.log('Setup complete');

    } catch (error) {
        console.error('Error setting up diagram:', error);
    }
});