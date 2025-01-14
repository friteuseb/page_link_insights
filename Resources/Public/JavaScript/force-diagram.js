document.addEventListener('DOMContentLoaded', function() {
    const diagramData = JSON.parse(document.getElementById('diagram-data').textContent.trim());
    
    const container = document.getElementById('force-diagram-container');
    const svg = d3.select("#force-diagram");
    
    // Récupérer les dimensions du conteneur
    const width = container.clientWidth;
    const height = container.clientHeight;
    
    svg
        .attr("width", width)
        .attr("height", height)
        .attr("viewBox", [0, 0, width, height]);

    const g = svg.append("g");

    // Ajouter le zoom
    const zoom = d3.zoom()
        .scaleExtent([0.1, 4])
        .on("zoom", (event) => {
            g.attr("transform", event.transform);
        });

    svg.call(zoom);

    const simulation = d3.forceSimulation(diagramData.nodes)
        .force("link", d3.forceLink(diagramData.links)
            .id(d => d.id)
            .distance(200))
        .force("charge", d3.forceManyBody().strength(-500))
        .force("center", d3.forceCenter(width / 2, height / 2));

    // Créer les liens
    const link = g.append("g")
        .selectAll("g")
        .data(diagramData.links)
        .join("g");

    link.append("line")
        .attr("stroke", "#999")
        .attr("stroke-width", 2)
        .attr("stroke-dasharray", "5,5")
        .attr("marker-end", "url(#arrow)");

    // Ajouter les flèches
    svg.append("defs").append("marker")
        .attr("id", "arrow")
        .attr("viewBox", "0 -5 10 10")
        .attr("refX", 20)
        .attr("refY", 0)
        .attr("markerWidth", 6)
        .attr("markerHeight", 6)
        .attr("orient", "auto")
        .append("path")
        .attr("fill", "#999")
        .attr("d", "M0,-5L10,0L0,5");

    // Créer les nœuds
    const node = g.append("g")
        .selectAll("g")
        .data(diagramData.nodes)
        .join("g")
        .call(d3.drag()
            .on("start", dragstarted)
            .on("drag", dragged)
            .on("end", dragended))
        .on("contextmenu", function(event, d) {
            event.preventDefault();
            // Supprimer le nœud et ses liens associés
            const nodeId = d.id;
            diagramData.nodes = diagramData.nodes.filter(n => n.id !== nodeId);
            diagramData.links = diagramData.links.filter(l => 
                l.source.id !== nodeId && l.target.id !== nodeId
            );
            // Redémarrer la simulation
            simulation.nodes(diagramData.nodes);
            simulation.force("link").links(diagramData.links);
            
            // Supprimer les éléments visuels
            d3.select(this).remove();
            link.filter(l => l.source.id === nodeId || l.target.id === nodeId).remove();
            
            simulation.alpha(1).restart();
        });

    // Ajouter les cercles pour les nœuds
    node.append("circle")
        .attr("r", 10)
        .attr("fill", "#69b3a2");

    // Ajouter les labels des nœuds
    node.append("text")
        .attr("dx", 15)
        .attr("dy", ".35em")
        .text(d => d.title)
        .attr("font-size", "12px");

    // Ajouter les tooltips
    node.append("title")
        .text(d => {
            const outgoingLinks = diagramData.links.filter(l => l.source.id === d.id);
            const incomingLinks = diagramData.links.filter(l => l.target.id === d.id);
            
            return `Page: ${d.title}
ID: ${d.id}
Liens sortants: ${outgoingLinks.length}
Liens entrants: ${incomingLinks.length}`;
        });

    simulation.on("tick", () => {
        link.selectAll("line")
            .attr("x1", d => d.source.x)
            .attr("y1", d => d.source.y)
            .attr("x2", d => d.target.x)
            .attr("y2", d => d.target.y);

        node.attr("transform", d => `translate(${d.x},${d.y})`);
    });

    // Fonctions pour le drag & drop
    function dragstarted(event, d) {
        if (!event.active) simulation.alphaTarget(0.3).restart();
        d.fx = d.x;
        d.fy = d.y;
    }

    function dragged(event, d) {
        d.fx = event.x;
        d.fy = event.y;
    }

    function dragended(event, d) {
        if (!event.active) simulation.alphaTarget(0);
        d.fx = null;
        d.fy = null;
    }

    // Ajuster la taille lors du redimensionnement de la fenêtre
    window.addEventListener('resize', () => {
        const newWidth = container.clientWidth;
        const newHeight = container.clientHeight;
        svg
            .attr("width", newWidth)
            .attr("height", newHeight)
            .attr("viewBox", [0, 0, newWidth, newHeight]);
        
        simulation.force("center", d3.forceCenter(newWidth / 2, newHeight / 2));
        simulation.alpha(1).restart();
    });
});