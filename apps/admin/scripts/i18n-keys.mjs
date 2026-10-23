// Lists the i18n keys referenced in src/ (static) and dynamic key templates. Dev helper.
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

function walk(dir, out = []) {
    for (const name of readdirSync(dir)) {
        const p = join(dir, name);
        if (statSync(p).isDirectory()) walk(p, out);
        else if (/\.(vue|ts)$/.test(name) && !p.includes('/i18n/')) out.push(p);
    }
    return out;
}
const stat = new Set();
const dyn = new Set();
for (const file of walk('src')) {
    const text = readFileSync(file, 'utf8');
    for (const m of text.matchAll(/(?<![A-Za-z_.])\$?t\(\s*(['"`])([^'"`]+)\1/g)) {
        (m[2].includes('${') ? dyn : stat).add(m[2]);
    }
    for (const m of text.matchAll(/(?<![A-Za-z_.])\$?te\(\s*(['"`])([^'"`]+)\1/g)) {
        (m[2].includes('${') ? dyn : stat).add(m[2]);
    }
    for (const m of text.matchAll(/(?<![A-Za-z_.])t\(\s*`([^`]+)`/g)) dyn.add(m[1]);
    for (const m of text.matchAll(/labelKey: '([^']+)'/g)) stat.add(m[1]);
}
console.log('STATIC\n' + [...stat].sort().join('\n'));
console.log('\nDYNAMIC\n' + [...dyn].sort().join('\n'));
