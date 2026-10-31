// Strict coverage gate: line coverage must be strictly greater than the limit (exactly 80 % fails).
// Vitest's own `thresholds` accept equality, so this check runs after `vitest run --coverage`.
import { readFileSync } from 'node:fs';

const LIMIT = 80;
const file = new URL('../coverage/coverage-summary.json', import.meta.url);
let summary;
try {
    summary = JSON.parse(readFileSync(file, 'utf8'));
} catch {
    console.error('coverage/coverage-summary.json missing: run `npm run test:coverage` first.');
    process.exit(1);
}
const { covered, total } = summary.total.lines;
const pct = total === 0 ? 0 : (covered / total) * 100;
const msg = `Line coverage ${pct.toFixed(2)} % (${covered}/${total}), required > ${LIMIT} %`;
if (!(total > 0 && covered * 100 > LIMIT * total)) {
    console.error(`FAIL: ${msg}`);
    process.exit(1);
}
console.log(`OK: ${msg}`);
