document.addEventListener('DOMContentLoaded', function() {
    // Debug - afficher les données brutes
    console.log('Raw data:', document.getElementById('diagram-data').textContent);
    
    const diagramData = JSON.parse(document.getElementById('diagram-data').textContent.trim());
    console.log('Parsed data:', diagramData);
    
    const nodes = diagramData.nodes;
    const links = diagramData.links;

    const width = 800;
    const height = 600;

    const svg = d3.select("#force-diagram")
        .attr("width", width)
        .attr("height", height);

    const simulation = d3.forceSimulation(nodes)
        .force("link", d3.forceLink(links).id(d => d.id).distance(100))
        .force("charge", d3.forceManyBody().strength(-400))
        .force("center", d3.forceCenter(width / 2, height / 2));

    const link = svg.append("g")
        .attr("stroke", "#999")
        .attr("stroke-opacity", 0.6)
        .selectAll("line")
        .data(links)
        .join("line")
        .attr("stroke-width", 2);

    const node = svg.append("g")
        .attr("stroke", "#fff")
        .attr("stroke-width", 1.5)
        .selectAll("circle")
        .data(nodes)
        .join("circle")
        .attr("r", 10)
        .attr("fill", "#69b3a2");

    node.append("title")
        .text(d => d.id);

    simulation.on("tick", () => {
        link
            .attr("x1", d => d.source.x)
            .attr("y1", d => d.source.y)
            .attr("x2", d => d.target.x)
            .attr("y2", d => d.target.y);

        node
            .attr("cx", d => d.x)
            .attr("cy", d => d.y);
    });
});