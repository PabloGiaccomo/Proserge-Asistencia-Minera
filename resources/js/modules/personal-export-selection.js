export function findExportDniMatches(query, workers) {
    const value = query.trim();
    if (!/^[0-9]+(?:\s+[0-9]+)*$/u.test(value)) return [];

    const requested = new Set(value.split(/\s+/u).filter(number => number.length === 8));
    return workers.filter(worker => worker.id && (
        requested.has(String(worker.dni || '')) || requested.has(String(worker.documento || ''))
    ));
}
