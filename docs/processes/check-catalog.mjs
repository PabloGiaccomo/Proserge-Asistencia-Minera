import fs from 'node:fs';
const catalog=JSON.parse(fs.readFileSync(new URL('./process-catalog.json',import.meta.url),'utf8'));
const problems=[];
let count=0;
for(const module of catalog.modules) for(const process of module.processes) {
 count++;
 const context=`${module.slug}/${process.slug}`;
 const ids=new Set(process.nodes.map(n=>n.id));
 if(ids.size!==process.nodes.length) problems.push(`${context}: duplicate node id`);
 const lanes=new Set(process.lanes.map(l=>l.id));
 const outgoing=new Map(process.nodes.map(n=>[n.id,[]]));
 const incoming=new Map(process.nodes.map(n=>[n.id,[]]));
 for(const node of process.nodes) if(!lanes.has(node.lane)) problems.push(`${context}: invalid lane ${node.id}`);
 for(const flow of process.flows) {
  if(!ids.has(flow.from)||!ids.has(flow.to)) {problems.push(`${context}: invalid flow ${flow.id}`);continue;}
  outgoing.get(flow.from).push(flow.to);
  incoming.get(flow.to).push(flow.from);
 }
 const starts=process.nodes.filter(n=>n.type==='startEvent').map(n=>n.id);
 const ends=process.nodes.filter(n=>n.type==='endEvent').map(n=>n.id);
 function walk(seeds,edges) {
  const visited=new Set();const queue=[...seeds];
  while(queue.length) {const id=queue.pop();if(visited.has(id)) continue;visited.add(id);queue.push(...edges.get(id));}
  return visited;
 }
 const reachable=walk(starts,outgoing), terminates=walk(ends,incoming);
 for(const id of ids) {
  if(!reachable.has(id)) problems.push(`${context}: unreachable ${id}`);
  if(!terminates.has(id)) problems.push(`${context}: no route to end ${id}`);
 }
 for(const id of starts) if(incoming.get(id).length) problems.push(`${context}: incoming start ${id}`);
 for(const id of ends) if(outgoing.get(id).length) problems.push(`${context}: outgoing end ${id}`);
 for(const extension of ['bpmn','svg']) if(!fs.existsSync(new URL(`./${context}.${extension}`,import.meta.url))) problems.push(`${context}: missing ${extension}`);
}
console.log(`${count} processes checked; ${problems.length} structural problems.`);
for(const problem of problems) console.error(problem);
process.exitCode=problems.length?1:0;
