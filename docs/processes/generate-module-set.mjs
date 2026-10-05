import fs from 'node:fs';
import path from 'node:path';

const [catalogPath, outputRoot] = process.argv.slice(2);

if (!catalogPath || !outputRoot) {
  console.error('Usage: node generate-module-set.mjs <catalog.json> <output-root>');
  process.exit(2);
}

const catalog = JSON.parse(fs.readFileSync(catalogPath, 'utf8'));
const xml = (value = '') => String(value)
  .replaceAll('&', '&amp;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;')
  .replaceAll('"', '&quot;')
  .replaceAll("'", '&apos;');

const safeId = (value) => String(value).replace(/[^A-Za-z0-9_]/g, '_');
const nodeSize = (type) => {
  if (type === 'startEvent' || type === 'endEvent') return [36, 36];
  if (type === 'exclusiveGateway') return [50, 50];
  return [150, 72];
};

function renderProcess(module, processSpec) {
  const processId = `Process_${safeId(module.slug)}_${safeId(processSpec.slug)}`;
  const lanes = processSpec.lanes;
  const slots = new Map();
  for (const node of processSpec.nodes) {
    const key = `${node.lane}:${node.col}`;
    const slot = node.layoutRow ?? (slots.get(key) || 0);
    node.offsetY = slot * 120;
    slots.set(key, Math.max(slots.get(key) || 0, slot + 1));
  }
  const maxSlots = Math.max(...slots.values());
  const laneHeight = 180 + (maxSlots - 1) * 120;
  for (const node of processSpec.nodes) {
    node.offsetY -= (maxSlots - 1) * 60;
  }
  const laneTop = 70;
  const processWidth = Math.max(1400, 420 + Math.max(...processSpec.nodes.map((node) => node.col)) * 190);
  const processHeight = laneTop + lanes.length * laneHeight + 30;
  const nodeById = new Map(processSpec.nodes.map((node) => [node.id, node]));
  const incoming = new Map(processSpec.nodes.map((node) => [node.id, []]));
  const outgoing = new Map(processSpec.nodes.map((node) => [node.id, []]));

  for (const flow of processSpec.flows) {
    if (!nodeById.has(flow.from) || !nodeById.has(flow.to)) {
      throw new Error(`${module.slug}/${processSpec.slug}: invalid flow ${flow.from} -> ${flow.to}`);
    }
    outgoing.get(flow.from).push(flow.id);
    incoming.get(flow.to).push(flow.id);
  }

  const laneRefs = lanes.map((lane) => {
    const refs = processSpec.nodes
      .filter((node) => node.lane === lane.id)
      .map((node) => `      <bpmn:flowNodeRef>${xml(node.id)}</bpmn:flowNodeRef>`)
      .join('\n');
    return `    <bpmn:lane id="${xml(lane.id)}" name="${xml(lane.name)}">\n${refs}\n    </bpmn:lane>`;
  }).join('\n');

  const nodeXml = processSpec.nodes.map((node) => {
    const tag = node.type || 'task';
    const incomingXml = incoming.get(node.id).map((id) => `      <bpmn:incoming>${xml(id)}</bpmn:incoming>`).join('\n');
    const outgoingXml = outgoing.get(node.id).map((id) => `      <bpmn:outgoing>${xml(id)}</bpmn:outgoing>`).join('\n');
    const documentation = node.documentation
      ? `\n      <bpmn:documentation>${xml(node.documentation)}</bpmn:documentation>`
      : '';
    const timer = node.timer ? `\n      <bpmn:timerEventDefinition id="${xml(node.id)}_timer"><bpmn:timeCycle>${xml(node.timer)}</bpmn:timeCycle></bpmn:timerEventDefinition>` : '';
    return `    <bpmn:${tag} id="${xml(node.id)}" name="${xml(node.name)}">${documentation}\n${incomingXml}${incomingXml && outgoingXml ? '\n' : ''}${outgoingXml}${timer}\n    </bpmn:${tag}>`;
  }).join('\n');

  const flowXml = processSpec.flows.map((flow) => {
    const documentation = flow.documentation
      ? `\n      <bpmn:documentation>${xml(flow.documentation)}</bpmn:documentation>\n    `
      : '';
    const name = flow.name ? ` name="${xml(flow.name)}"` : '';
    return `    <bpmn:sequenceFlow id="${xml(flow.id)}" sourceRef="${xml(flow.from)}" targetRef="${xml(flow.to)}"${name}>${documentation}</bpmn:sequenceFlow>`;
  }).join('\n');

  const shapes = processSpec.nodes.map((node) => {
    const laneIndex = lanes.findIndex((lane) => lane.id === node.lane);
    if (laneIndex < 0) throw new Error(`${module.slug}/${processSpec.slug}: unknown lane ${node.lane}`);
    const [width, height] = nodeSize(node.type);
    const x = 215 + node.col * 190;
    const laneY = laneTop + laneIndex * laneHeight;
    const y = laneY + (laneHeight - height) / 2 + (node.offsetY || 0);
    return `      <bpmndi:BPMNShape id="${xml(node.id)}_di" bpmnElement="${xml(node.id)}">\n        <dc:Bounds x="${x}" y="${y}" width="${width}" height="${height}" />\n      </bpmndi:BPMNShape>`;
  }).join('\n');

  const edges = processSpec.flows.map((flow) => {
    const source = nodeById.get(flow.from);
    const target = nodeById.get(flow.to);
    const [sw, sh] = nodeSize(source.type);
    const [tw, th] = nodeSize(target.type);
    const sourceLane = lanes.findIndex((lane) => lane.id === source.lane);
    const targetLane = lanes.findIndex((lane) => lane.id === target.lane);
    const sx = 215 + source.col * 190 + sw;
    const sy = laneTop + sourceLane * laneHeight + laneHeight / 2 + (source.offsetY || 0);
    const tx = 215 + target.col * 190;
    const ty = laneTop + targetLane * laneHeight + laneHeight / 2 + (target.offsetY || 0);
    const middleX = flow.middleX ?? Math.round((sx + tx) / 2);
    return `      <bpmndi:BPMNEdge id="${xml(flow.id)}_di" bpmnElement="${xml(flow.id)}">\n        <di:waypoint x="${sx}" y="${sy}" />\n        <di:waypoint x="${middleX}" y="${sy}" />\n        <di:waypoint x="${middleX}" y="${ty}" />\n        <di:waypoint x="${tx}" y="${ty}" />\n      </bpmndi:BPMNEdge>`;
  }).join('\n');

  const laneShapes = lanes.map((lane, index) => `      <bpmndi:BPMNShape id="${xml(lane.id)}_di" bpmnElement="${xml(lane.id)}" isHorizontal="true">\n        <dc:Bounds x="60" y="${laneTop + index * laneHeight}" width="${processWidth - 90}" height="${laneHeight}" />\n      </bpmndi:BPMNShape>`).join('\n');

  const processDocumentation = [
    processSpec.summary,
    `Evidencia: ${processSpec.evidence.join('; ')}`,
    ...(processSpec.confirm || []).map((item) => `Por confirmar: ${item}`),
  ].filter(Boolean).join(' | ');

  return `<?xml version="1.0" encoding="UTF-8"?>
<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL" xmlns:bpmndi="http://www.omg.org/spec/BPMN/20100524/DI" xmlns:dc="http://www.omg.org/spec/DD/20100524/DC" xmlns:di="http://www.omg.org/spec/DD/20100524/DI" id="Definitions_${safeId(processSpec.slug)}" targetNamespace="https://proserge.com/bpmn">
  <bpmn:process id="${processId}" name="${xml(processSpec.title)}" isExecutable="false">
    <bpmn:documentation>${xml(processDocumentation)}</bpmn:documentation>
    <bpmn:laneSet id="LaneSet_${safeId(processSpec.slug)}">
${laneRefs}
    </bpmn:laneSet>
${nodeXml}
${flowXml}
  </bpmn:process>
  <bpmndi:BPMNDiagram id="Diagram_${safeId(processSpec.slug)}">
    <bpmndi:BPMNPlane id="Plane_${safeId(processSpec.slug)}" bpmnElement="${processId}">
${laneShapes}
${shapes}
${edges}
    </bpmndi:BPMNPlane>
  </bpmndi:BPMNDiagram>
</bpmn:definitions>
`;
}

function markdownList(items, empty = 'Ninguno identificado.') {
  if (!items || items.length === 0) return empty;
  return items.map((item) => `- ${item}`).join('\n');
}

function moduleIndex(module) {
  const processRows = module.processes.map((processSpec) =>
    `| ${processSpec.title} | [BPMN](./${processSpec.slug}.bpmn) | [SVG](./${processSpec.slug}.svg) | ${processSpec.summary} |`
  ).join('\n');
  const evidence = [...new Set(module.processes.flatMap((processSpec) => processSpec.evidence))];
  return `# ${module.title}\n\n${module.scope}\n\n## Procesos\n\n| Proceso | BPMN | Visual | Alcance |\n|---|---|---|---|\n${processRows}\n\n## Actores y datos\n\n${markdownList(module.actors)}\n\n## Evidencia principal\n\n${markdownList(evidence.map((item) => `\`${item}\``))}\n\n## Duplicidades o inconsistencias\n\n${markdownList(module.inconsistencies)}\n\n## Por confirmar\n\n${markdownList(module.confirm)}\n`;
}

function rootIndex() {
  const rows = catalog.modules.map((module) =>
    `| [${module.title}](./${module.slug}/index.md) | ${module.processes.length} | ${module.scope} |`
  ).join('\n');
  const total = catalog.modules.reduce((sum, module) => sum + module.processes.length, 0);
  return `# Procesos funcionales Proserge\n\nInventario BPMN derivado del codigo existente. Los diagramas describen comportamiento observado; no son una propuesta de proceso futuro. Toda ambiguedad se marca como **Por confirmar**.\n\n- Modulos funcionales documentados: ${catalog.modules.length}\n- Procesos BPMN: ${total}\n- Generado desde rutas, controladores, servicios, modelos, policies, middleware, pruebas y documentacion vigente.\n\n## Indice\n\n| Modulo o dominio | BPMN | Alcance |\n|---|---:|---|\n${rows}\n\n## Modulos de consulta o soporte sin BPMN propio\n\n${markdownList(catalog.supportModules)}\n\n## Inconsistencias transversales\n\n${markdownList(catalog.crossCuttingIssues)}\n\n## Convenciones\n\n- Los carriles distinguen roles humanos, Sistema y procesos automaticos.\n- Las validaciones y permisos aparecen antes de la mutacion protegida.\n- Los finales de rechazo o error se representan de forma explicita.\n- La evidencia de cada elemento queda en \`bpmn:documentation\` y en el indice del modulo.\n`;
}

fs.mkdirSync(outputRoot, { recursive: true });
const index = rootIndex().replace(
  'La evidencia de cada elemento queda en `bpmn:documentation` y en el indice del modulo.',
  'La evidencia se registra por proceso y, donde se ha trazado, por elemento. La cobertura individual sigue en revision.'
);
fs.writeFileSync(path.join(outputRoot, 'index.md'), `${index}\n## Estado de revision\n\nVer [correcciones y decisiones pendientes](./REVISION.md). La validacion tecnica no sustituye la aprobacion funcional.\n`, 'utf8');

let count = 0;
for (const module of catalog.modules) {
  const moduleDir = path.join(outputRoot, module.slug);
  fs.mkdirSync(moduleDir, { recursive: true });
  fs.writeFileSync(path.join(moduleDir, 'index.md'), moduleIndex(module), 'utf8');
  for (const processSpec of module.processes) {
    fs.writeFileSync(path.join(moduleDir, `${processSpec.slug}.bpmn`), renderProcess(module, processSpec), 'utf8');
    count += 1;
  }
}

console.log(`Generated ${count} BPMN files across ${catalog.modules.length} modules.`);
