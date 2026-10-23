import postcss from 'postcss';
import { describe, expect, it } from 'vitest';
import { rewriteSelector, swbiScope } from '../tools/postcssScope';

const r = (s: string): string => rewriteSelector(s, '.swbi-root', 'swbi-dark');

describe('rewriteSelector', () => {
  it('prefixes ordinary selectors', () => {
    expect(r('.q-btn')).toBe('.swbi-root .q-btn');
    expect(r('*')).toBe('.swbi-root *');
    expect(r('a:hover')).toBe('.swbi-root a:hover');
  });
  it('maps html/body/:root to the root', () => {
    expect(r('html')).toBe('.swbi-root');
    expect(r('body')).toBe('.swbi-root');
    expect(r(':root')).toBe('.swbi-root');
    expect(r('body.desktop .q-x')).toBe('.swbi-root.desktop .q-x');
  });
  it('maps Quasar dark/light body classes onto our root classes', () => {
    expect(r('body.body--dark .q-card')).toBe('.swbi-root.swbi-dark .q-card');
    expect(r('.body--dark .q-card')).toBe('.swbi-root.swbi-dark .q-card');
    expect(r('body.body--light .q-card')).toBe('.swbi-root:not(.swbi-dark) .q-card');
  });
  it('neutralises body scroll-lock classes', () => {
    expect(r('body.q-body--prevent-scroll')).toBe('.swbi-root.q-body--prevent-scroll');
  });
  it('is idempotent', () => {
    expect(r('.swbi-root .q-btn')).toBe('.swbi-root .q-btn');
  });
});

describe('swbiScope plugin', () => {
  const run = (css: string, from: string): Promise<string> =>
    postcss([swbiScope()])
      .process(css, { from })
      .then((res) => res.css);

  it('rewrites only Quasar files and leaves keyframes alone', async () => {
    const q = await run('.a, body { x: 1 } @keyframes k { from { a: b } }', '/app/node_modules/quasar/dist/q.css');
    expect(q).toContain('.swbi-root .a');
    expect(q).toContain('.swbi-root {');
    expect(q).toContain('from { a: b }');
    const own = await run('.swbi-card { x: 1 }', '/app/src/styles/app.css');
    expect(own).toBe('.swbi-card { x: 1 }');
  });
});
