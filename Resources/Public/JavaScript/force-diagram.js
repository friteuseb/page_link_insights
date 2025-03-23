document.addEventListener('DOMContentLoaded', function() {
    console.log('Script starting...');

    const dataElement = document.getElementById('diagram-data');
    if (!dataElement) {
        console.error('diagram-data element not found');
        return;
    }

    try {
        const diagramData = JSON.parse(dataElement.textContent.trim());
        console.log('Parsed data:', diagramData);

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

        // Échelle pour la taille des nœuds
        const nodeScale = d3.scaleLinear()
            .domain([0, d3.max(diagramData.nodes, d => d.incomingLinks)])
            .range([baseNodeRadius, baseNodeRadius * 2.5]);

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
            .attr("refX", d => nodeScale(d3.max(diagramData.nodes, d => d.incomingLinks)) + 10)
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

        // Créer la simulation de forces
        const simulation = d3.forceSimulation(diagramData.nodes)
            .force("link", d3.forceLink(validLinks) // Utiliser uniquement les liens valides
                .id(d => d.id)
                .distance(150))
            .force("charge", d3.forceManyBody().strength(-1000))
            .force("center", d3.forceCenter(width / 2, height / 2))
            .force("collide", d3.forceCollide().radius(d => nodeScale(d.incomingLinks) + 10));

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
                            <strong>Lien brisé</strong><br>
                            Source: ${d.source}<br>
                            Cible: ${d.target}<br>
                            <em>La page cible n'existe pas.</em>
                        `);
                } else if (d.contentElement?.type === 'semantic_suggestion') {
                    tooltip.style("visibility", "visible")
                        .html(`
                            <strong>Suggestion sémantique</strong><br>
                            Source: Page #${d.source.id || d.source}<br>
                            Cible: Page #${d.target.id || d.target}<br>
                            ${d.similarity ? `Score: ${(d.similarity * 100).toFixed(1)}%<br>` : ''}
                            <em>Lien généré automatiquement par analyse de contenu</em>
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
                    themeHtml = '<br><strong>Thèmes:</strong><br>';
                    d.themes.slice(0, 3).forEach(theme => {
                        themeHtml += `${theme.theme} (${theme.relevance.toFixed(1)})<br>`;
                    });
                }
                
                tooltip.style("visibility", "visible")
                    .html(`
                        <strong>${d.title}</strong><br>
                        ID: ${d.id}<br>
                        Liens entrants: ${d.incomingLinks}
                        ${themeHtml}
                        <em>Ctrl+Clic pour ouvrir dans TYPO3<br>
                        Clic droit pour supprimer</em>
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
            const legend = svg.append("g")
                .attr("class", "legend")
                .attr("transform", `translate(${width - 200}, 30)`);

            // Titre de la légende
            legend.append("text")
                .attr("x", 0)
                .attr("y", -10)
                .attr("fill", "#00ff00")
                .text("Thèmes dominants");

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
            const linkLegend = svg.append("g")
            .attr("class", "link-legend")
            .attr("transform", `translate(20, 30)`); // Positionné en haut à gauche

            // Titre de la légende
            linkLegend.append("text")
            .attr("x", 0)
            .attr("y", -10)
            .attr("fill", "#00ff00")
            .text("Types de liens");

            // Définir les types de liens pour la légende
            const linkTypes = [
            { type: "standard", color: "#999", dash: null, label: "Liens standards" },
            { type: "semantic", color: "#9c27b0", dash: "8,4", label: "Suggestions sémantiques" },
            { type: "broken", color: "#ff0000", dash: "5,5", label: "Liens cassés" }
            ];

            // Éléments de la légende
            linkTypes.forEach((linkType, i) => {
            const legendItem = linkLegend.append("g")
                .attr("transform", `translate(0, ${i * 25})`);
                
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