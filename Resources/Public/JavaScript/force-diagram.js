document.addEventListener('DOMContentLoaded', function() {
    const diagramData = JSON.parse(document.getElementById('diagram-data').textContent.trim());
    
    const nodes = diagramData.nodes;
    const links = diagramData.links;

    const width = 800;
    const height = 600;

    // Identifier les pages qui ont des liens
    const pagesWithLinks = new Set();
    links.forEach(link => {
        pagesWithLinks.add(link.source);
        pagesWithLinks.add(link.target);
    });

    // Définir les couleurs selon que la page a des liens ou non
    const getNodeColor = (nodeId) => {
        return pagesWithLinks.has(nodeId) ? "#4a90e2" : "#d3d3d3";
    };

    const svg = d3.select("#force-diagram")
        .attr("width", width)
        .attr("height", height);

    // Ajouter un marqueur de flèche pour les liens
    svg.append("defs").append("marker")
        .attr("id", "arrowhead")
        .attr("viewBox", "-0 -5 10 10")
        .attr("refX", 20)
        .attr("refY", 0)
        .attr("orient", "auto")
        .attr("markerWidth", 6)
        .attr("markerHeight", 6)
        .append("path")
        .attr("d", "M0,-5L10,0L0,5")
        .attr("fill", "#999");

    const simulation = d3.forceSimulation(nodes)
        .force("link", d3.forceLink(links)
            .id(d => d.id)
            .distance(100))
        .force("charge", d3.forceManyBody().strength(-300))
        .force("center", d3.forceCenter(width / 2, height / 2))
        .force("collision", d3.forceCollide().radius(50));

    const link = svg.append("g")
        .selectAll("line")
        .data(links)
        .join("line")
        .attr("stroke", "#999")
        .attr("stroke-width", 2)
        .attr("marker-end", "url(#arrowhead)");

    const node = svg.append("g")
        .selectAll("g")
        .data(nodes)
        .join("g")
        .call(d3.drag()
            .on("start", dragstarted)
            .on("drag", dragged)
            .on("end", dragended));

    // Ajouter des cercles pour les nœuds
    node.append("circle")
        .attr("r", 10)
        .attr("fill", d => getNodeColor(d.id));

    // Ajouter les labels
    node.append("text")
        .attr("dx", 15)
        .attr("dy", ".35em")
        .attr("font-size", "12px")
        .text(d => d.title);

    // Ajouter les tooltips
    node.append("title")
        .text(d => {
            const isLinked = pagesWithLinks.has(d.id);
            const incomingLinks = links.filter(l => l.target.id === d.id).length;
            const outgoingLinks = links.filter(l => l.source.id === d.id).length;
            return `Page: ${d.title}\n` +
                   `ID: ${d.id}\n` +
                   `Liens entrants: ${incomingLinks}\n` +
                   `Liens sortants: ${outgoingLinks}`;
        });

    simulation.on("tick", () => {
        link
            .attr("x1", d => d.source.x)
            .attr("y1", d => d.source.y)
            .attr("x2", d => d.target.x)
            .attr("y2", d => d.target.y);

        node
            .attr("transform", d => `translate(${d.x},${d.y})`);
    });

    // Zoom functionality
    const zoom = d3.zoom()
        .scaleExtent([0.1, 4])
        .on("zoom", (event) => {
            svg.selectAll("g").attr("transform", event.transform);
        });

    svg.call(zoom);

    // Fonctions pour le drag & drop
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
});