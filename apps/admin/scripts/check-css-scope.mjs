// Verifies that every selector in the built CSS is namespaced (swbi-*) or scoped to .swbi-root.
import { readFileSync } from 'node:fs';
import postcss from 'postcss';

const file = process.argv[2] ?? '../../assets/admin/app.css';
const root = postcss.parse(readFileSync(file, 'utf8'));
const bad = new Set();
const allowed = [
    /\[data-v-[0-9a-f]+\]/,
    /^body\.swbi-admin-page /,
    /^body\.q-body--prevent-scroll$/,
    /^\.swbi-/,
    /^\.swbi-root/,
    /^\.q-portal/,
];
root.walkRules((rule) => {
    const parent = rule.parent;
    if (parent && parent.type === 'atrule' && /keyframes$/i.test(parent.name)) return;
    for (const sel of rule.selectors) {
        if (!allowed.some((re) => re.test(sel.trim()))) bad.add(sel.trim());
    }
});
root.walkAtRules('font-face', () => {});
if (bad.size > 0) {
    console.error(`Unscoped selectors (${bad.size}):`);
    for (const s of [...bad].slice(0, 40)) console.error('  ' + s);
    process.exit(1);
}
console.log('CSS scope OK: all selectors are namespaced.');
