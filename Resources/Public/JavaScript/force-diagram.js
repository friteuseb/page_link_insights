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
        
        diagramData.links = diagramData.links.map(link => ({
            source: link.sourcePageId,
            target: link.targetPageId,
            contentElement: link.contentElement
        }));
        
        console.log('Processed links:', diagramData.links);
        
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
            .style("background-color", "white")
            .style("border", "1px solid #ddd")
            .style("border-radius", "4px")
            .style("padding", "10px")
            .style("box-shadow", "0 2px 4px rgba(0,0,0,0.1)");

        // Définir les marqueurs pour les flèches
        svg.append("defs").selectAll("marker")
            .data(["end"])
            .join("marker")
            .attr("id", d => d)
            .attr("viewBox", "0 -5 10 10")
            .attr("refX", d => baseNodeRadius + 10)
            .attr("refY", 0)
            .attr("markerWidth", 6)
            .attr("markerHeight", 6)
            .attr("orient", "auto")
            .append("path")
            .attr("d", "M0,-5L10,0L0,5")
            .attr("fill", "#999");

        const g = svg.append("g");

        const linkColors = {
            'menu': '#2ca02c',
            'menu_sitemap_pages': '#2ca02c',
            'html': '#1f77b4',
            'typolink': '#ff7f0e',
            'sitemap': '#9467bd',
            'text': '#e377c2'
        };

        // Échelle pour la taille des nœuds
        const nodeScale = d3.scaleLinear()
            .domain([0, d3.max(diagramData.nodes, d => d.incomingLinks)])
            .range([baseNodeRadius, baseNodeRadius * 2.5]);

        const simulation = d3.forceSimulation(diagramData.nodes)
            .force("link", d3.forceLink(diagramData.links)
                .id(d => d.id)
                .distance(150))
            .force("charge", d3.forceManyBody().strength(-1000))
            .force("center", d3.forceCenter(width / 2, height / 2))
            .force("collide", d3.forceCollide().radius(d => nodeScale(d.incomingLinks) + 10));

        const link = g.append("g")
            .attr("class", "links")
            .selectAll("line")
            .data(diagramData.links)
            .join("line")
            .attr("stroke", d => linkColors[d.contentElement?.type] || '#999')
            .attr("stroke-width", 2)
            .attr("marker-end", "url(#end)");

        const node = g.append("g")
            .attr("class", "nodes")
            .selectAll("g")
            .data(diagramData.nodes)
            .join("g")
            .attr("class", "node")
            .call(drag(simulation));

        // Cercles pour les nœuds avec taille variable
        node.append("circle")
            .attr("r", d => nodeScale(d.incomingLinks))
            .attr("fill", "#69b3a2");

        node.append("text")
            .attr("dx", d => nodeScale(d.incomingLinks) + 5)
            .attr("dy", ".35em")
            .text(d => d.title)
            .attr("fill", "black")
            .attr("font-family", "Arial")
            .attr("font-size", "12px");

        // Gestion des événements
        node
            .on("mouseover", function(event, d) {
                tooltip.style("visibility", "visible")
                    .html(`
                        <strong>${d.title}</strong><br>
                        ID: ${d.id}<br>
                        Liens entrants: ${d.incomingLinks}<br>
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
                    // Ouvrir la page dans TYPO3 backend
                    const typo3Url = `/typo3/module/site/BePagesEdit?edit[pages][${d.id}]=edit`;
                    window.open(typo3Url, '_blank');
                }
            })
            .on("contextmenu", function(event, d) {
                event.preventDefault();
                
                // Supprimer le nœud et ses liens associés
                diagramData.links = diagramData.links.filter(l => 
                    l.source.id !== d.id && l.target.id !== d.id
                );
                diagramData.nodes = diagramData.nodes.filter(n => n.id !== d.id);
                
                // Mettre à jour la simulation
                simulation.nodes(diagramData.nodes);
                simulation.force("link").links(diagramData.links);
                
                // Mettre à jour le rendu
                node.data(diagramData.nodes, d => d.id).exit().remove();
                link.data(diagramData.links).exit().remove();
                
                simulation.alpha(1).restart();
            });

        const zoom = d3.zoom()
            .scaleExtent([0.1, 4])
            .on("zoom", (event) => {
                g.attr("transform", event.transform);
            });

        svg.call(zoom);

        simulation.on("tick", () => {
            link
                .attr("x1", d => d.source.x)
                .attr("y1", d => d.source.y)
                .attr("x2", d => d.target.x)
                .attr("y2", d => d.target.y);

            node
                .attr("transform", d => `translate(${d.x},${d.y})`);
        });

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