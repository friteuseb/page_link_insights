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
            source: link.sourcePageId, // Assurez-vous que c'est une chaîne ou un nombre
            target: link.targetPageId, // Assurez-vous que c'est une chaîne ou un nombre
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
            'text': '#00ffcc' // Cyan clair
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


        const electricGradient = d3.interpolateHcl("#00ffff", "#ff00ff"); // Bleu électrique à violet

        const colorScale = d3.scaleSequential(electricGradient)
            .domain([0, d3.max(diagramData.nodes, d => d.incomingLinks)]);

        // Cercles pour les nœuds avec taille variable
        node.append("circle")
            .attr("r", d => nodeScale(d.incomingLinks))
            .attr("fill", "#003300") // Vert Matrix foncé
            .attr("stroke", "#00ff00") // Bordure verte fluo
            .attr("stroke-width", 2); // Épaisseur de la bordure

            const isDarkBackground = true; // ou une logique pour détecter le fond

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
            g.attr("transform", event.transform); // Appliquer uniquement la transformation
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