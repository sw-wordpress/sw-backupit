import type { SwbiConfig } from '@/types/config';

let cached: SwbiConfig | null = null;

function str(value: unknown, fallback: string): string {
  return typeof value === 'string' ? value : fallback;
}

/** Validate/normalise the PHP-provided config. Throws if the REST root is missing. */
export function normaliseConfig(raw: Partial<SwbiConfig> | undefined): SwbiConfig {
  if (!raw || typeof raw.restRoot !== 'string' || raw.restRoot === '') {
    throw new Error('window.swbackupitConfig.restRoot is missing');
  }
  const restRoot = raw.restRoot.endsWith('/') ? raw.restRoot : `${raw.restRoot}/`;
  return {
    restRoot,
    nonce: str(raw.nonce, ''),
    locale: str(raw.locale, 'en_US'),
    version: str(raw.version, ''),
    adminUrl: str(raw.adminUrl, ''),
    capabilities: Array.isArray(raw.capabilities)
      ? raw.capabilities.filter((c): c is string => typeof c === 'string')
      : [],
    siteUrl: str(raw.siteUrl, ''),
  };
}

export function getConfig(): SwbiConfig {
  if (cached === null) {
    cached = normaliseConfig(window.swbackupitConfig);
  }
  return cached;
}

/** For tests. */
export function resetConfigForTests(): void {
  cached = null;
}

/** UI language: German if the WordPress locale starts with `de`, otherwise English. */
export const UI_LOCALES = ['en', 'de', 'fr', 'pl', 'it'] as const;
export type UiLocale = (typeof UI_LOCALES)[number];

/** Maps a WordPress locale (de_DE, fr_CA, pl_PL, it_IT_formal ...) to a supported UI language; English is the fallback. */
export function resolveUiLocale(locale: string): UiLocale {
  const language = locale.toLowerCase().slice(0, 2);
  return (UI_LOCALES as readonly string[]).includes(language) ? (language as UiLocale) : 'en';
}
