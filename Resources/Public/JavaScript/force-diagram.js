document.addEventListener('DOMContentLoaded', function() {
    const diagramData = JSON.parse(document.getElementById('diagram-data').textContent.trim());
    
    const width = 1200;
    const height = 800;
    const nodeRadius = 20;

    const svg = d3.select("#force-diagram")
        .attr("width", width)
        .attr("height", height)
        .attr("viewBox", [0, 0, width, height]);

    // Définir les marqueurs pour les flèches
    svg.append("defs").selectAll("marker")
        .data(["end"])
        .join("marker")
        .attr("id", d => d)
        .attr("viewBox", "0 -5 10 10")
        .attr("refX", nodeRadius + 10)  // Ajusté pour éviter le chevauchement avec les nœuds
        .attr("refY", 0)
        .attr("markerWidth", 6)
        .attr("markerHeight", 6)
        .attr("orient", "auto")
        .append("path")
        .attr("d", "M0,-5L10,0L0,5")
        .attr("fill", "#999");

    // Ajout d'un groupe pour le zoom
    const g = svg.append("g");

    // Configuration du zoom
    const zoom = d3.zoom()
        .scaleExtent([0.1, 4])
        .on("zoom", (event) => {
            g.attr("transform", event.transform);
        });

    svg.call(zoom);

    // Définition des couleurs pour les différents types de liens
    const linkColors = {
        'menu': '#2ca02c',
        'html': '#1f77b4',
        'typolink': '#ff7f0e'
    };

    // Créer la simulation
    const simulation = d3.forceSimulation(diagramData.nodes)
        .force("link", d3.forceLink(diagramData.links)
            .id(d => d.id)
            .distance(150))
        .force("charge", d3.forceManyBody()
            .strength(-1000))  // Force de répulsion augmentée
        .force("center", d3.forceCenter(width / 2, height / 2))
        .force("collide", d3.forceCollide().radius(80));  // Augmenté pour éviter le chevauchement des labels

    // Création des liens
    const link = g.append("g")
        .selectAll("line")
        .data(diagramData.links)
        .join("line")
        .attr("stroke", d => linkColors[d.contentElement?.type] || '#999')
        .attr("stroke-width", 2)
        .attr("marker-end", "url(#end)");

    // Création des groupes de nœuds
    const node = g.append("g")
        .selectAll("g")
        .data(diagramData.nodes)
        .join("g")
        .attr("class", "node")
        .call(drag(simulation));

    // Cercles pour les nœuds
    node.append("circle")
        .attr("r", nodeRadius)
        .attr("fill", "#69b3a2")
        .attr("stroke", "#fff")
        .attr("stroke-width", 2);

    // Conteneurs pour les labels
    const labels = node.append("g")
        .attr("class", "label-container");

    // Fond blanc pour les labels
    labels.append("rect")
        .attr("fill", "white")
        .attr("opacity", 0.8)
        .attr("rx", 3)
        .attr("ry", 3);

    // Texte des labels
    const textLabels = labels.append("text")
        .text(d => d.title)
        .attr("text-anchor", "middle")
        .attr("dy", 35)  // Déplacé sous le nœud
        .attr("font-size", "12px")
        .attr("font-family", "Arial, sans-serif");

    // Ajuster la taille des rectangles de fond aux textes
    labels.each(function() {
        const bbox = this.getElementsByTagName("text")[0].getBBox();
        const padding = 4;
        const rect = this.getElementsByTagName("rect")[0];
        rect.setAttribute("x", bbox.x - padding);
        rect.setAttribute("y", bbox.y - padding);
        rect.setAttribute("width", bbox.width + (padding * 2));
        rect.setAttribute("height", bbox.height + (padding * 2));
    });

    // Légende
    const legend = svg.append("g")
        .attr("class", "legend")
        .attr("transform", "translate(20,20)");

    Object.entries(linkColors).forEach(([type, color], i) => {
        const legendRow = legend.append("g")
            .attr("transform", `translate(0,${i * 20})`);
        
        legendRow.append("line")
            .attr("x1", 0)
            .attr("x2", 20)
            .attr("stroke", color)
            .attr("stroke-width", 2);
            
        legendRow.append("text")
            .attr("x", 30)
            .attr("y", 4)
            .text(type)
            .attr("font-size", "12px")
            .attr("font-family", "Arial, sans-serif");
    });

    // Fonction de glisser-déposer
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

    // Animation
    simulation.on("tick", () => {
        // Mise à jour des liens
        link
            .attr("x1", d => d.source.x)
            .attr("y1", d => d.source.y)
            .attr("x2", d => d.target.x)
            .attr("y2", d => d.target.y);

        // Mise à jour des nœuds et de leurs labels
        node.attr("transform", d => `translate(${d.x},${d.y})`);
    });
});