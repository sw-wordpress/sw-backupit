import { describe, expect, it } from 'vitest';
import de from '@/i18n/de';
import en from '@/i18n/en';
import fr from '@/i18n/fr';
import itCatalog from '@/i18n/it';
import pl from '@/i18n/pl';
import { resolveUiLocale } from '@/config';

type Tree = { [key: string]: string | Tree };

function flatten(tree: Tree, prefix = ''): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [key, value] of Object.entries(tree)) {
    if (typeof value === 'string') out[prefix + key] = value;
    else Object.assign(out, flatten(value, `${prefix}${key}.`));
  }
  return out;
}

const placeholders = (text: string): string[] => (text.match(/\{[a-zA-Z0-9_]+\}/g) ?? []).sort();

describe('translation catalogs', () => {
  const reference = flatten(en as unknown as Tree);
  const catalogs: Record<string, Tree> = { de, fr, pl, it: itCatalog };

  for (const [name, catalog] of Object.entries(catalogs)) {
    it(`${name} has exactly the keys of en, with the same placeholders and plural forms`, () => {
      const flat = flatten(catalog);
      expect(Object.keys(flat).sort()).toEqual(Object.keys(reference).sort());
      for (const [key, text] of Object.entries(flat)) {
        expect(text.trim(), `${name}:${key} is empty`).not.toBe('');
        expect(placeholders(text), `${name}:${key} placeholders`).toEqual(
          placeholders(reference[key] ?? ''),
        );
        expect(text.split('|').length, `${name}:${key} plural forms`).toBe(
          (reference[key] ?? '').split('|').length,
        );
      }
    });
  }

  it('maps WordPress locales to the supported UI languages', () => {
    expect(resolveUiLocale('de_DE_formal')).toBe('de');
    expect(resolveUiLocale('fr_CA')).toBe('fr');
    expect(resolveUiLocale('pl_PL')).toBe('pl');
    expect(resolveUiLocale('it_IT')).toBe('it');
    expect(resolveUiLocale('ja')).toBe('en');
  });
});
