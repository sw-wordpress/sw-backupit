import type { AtRule, Plugin, Rule } from 'postcss';

/**
 * PostCSS plugin that confines Quasar's global stylesheet to the plugin's own DOM.
 *
 * Quasar ships a reset and utility CSS that targets `html`, `body`, `*` and generic
 * elements. Inside wp-admin that would restyle the whole dashboard. Only CSS that
 * originates from `node_modules/quasar` and `@quasar/extras` is rewritten; our own CSS is already namespaced.
 *
 * Mapping:
 * - `html`, `body`, `:root`        -> `.swbi-root`
 * - `body.body--dark X`            -> `.swbi-root.swbi-dark X`
 * - `body.body--light X`           -> `.swbi-root:not(.swbi-dark) X`
 * - `body.q-body--*`/`html.q-*`    -> `.swbi-root.q-body--*` (never matches: scroll lock is handled in app.css)
 * - everything else                -> `.swbi-root <selector>`
 *
 * Quasar portals (dialogs, menus, notifications) are created with the class `swbi-root`
 * via `config.globalNodes.class`, so the descendant selectors apply to them as well.
 */
export interface ScopeOptions {
  scope?: string;
  darkClass?: string;
  /** Substrings of which one must be in a file path for the plugin to touch it. */
  filePatterns?: string[];
}

const KEYFRAME_RE = /keyframes$/i;

export function rewriteSelector(selector: string, scope: string, darkClass: string): string {
  let sel = selector.trim();
  if (sel === '') return sel;
  if (sel.startsWith(scope)) return sel;

  // dark / light body variants
  const dark = /^(?:body|html)?\.body--dark/;
  const light = /^(?:body|html)?\.body--light/;
  if (dark.test(sel)) {
    return sel.replace(dark, `${scope}.${darkClass}`);
  }
  if (light.test(sel)) {
    return sel.replace(light, `${scope}:not(.${darkClass})`);
  }

  // scroll-lock classes toggled on html/body by Quasar: neutralised
  if (
    /^(?:body|html)\.q-/.test(sel) ||
    /^(?:body|html)\.(?:desktop|mobile|touch|no-touch|platform-)/.test(sel)
  ) {
    return sel.replace(/^(?:body|html)/, scope);
  }

  const root = /^(?:html|body|:root)(?![\w-])/;
  if (root.test(sel)) {
    sel = sel.replace(root, scope);
    return sel;
  }
  return `${scope} ${sel}`;
}

export function swbiScope(options: ScopeOptions = {}): Plugin {
  const scope = options.scope ?? '.swbi-root';
  const darkClass = options.darkClass ?? 'swbi-dark';
  const patterns = options.filePatterns ?? [
    '/node_modules/quasar/',
    '/node_modules/@quasar/extras/',
  ];
  return {
    postcssPlugin: 'swbi-scope',
    Once(root) {
      const file = root.source?.input.file?.replace(/\\/g, '/') ?? '';
      if (!patterns.some((p) => file.includes(p))) return;
      root.walkRules((rule: Rule) => {
        const parent = rule.parent as AtRule | undefined;
        if (parent && parent.type === 'atrule' && KEYFRAME_RE.test(parent.name)) return;
        const seen = new Set<string>();
        rule.selectors = rule.selectors
          .map((s) => rewriteSelector(s, scope, darkClass))
          .filter((s) => (seen.has(s) ? false : (seen.add(s), true)));
      });
    },
  };
}
swbiScope.postcss = true;
