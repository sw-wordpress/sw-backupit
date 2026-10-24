import { createI18n } from 'vue-i18n';
import { resolveUiLocale } from '@/config';
import de from './de';
import en from './en';
import fr from './fr';
import it from './it';
import pl from './pl';

/** Compile-time checked message keys for `t()`. */
export type MessageSchema = typeof de;
declare module 'vue-i18n' {
  // eslint-disable-next-line @typescript-eslint/no-empty-object-type
  export interface DefineLocaleMessage extends MessageSchema {}
}

export function createAppI18n(wpLocale: string) {
  return createI18n({
    legacy: false,
    locale: resolveUiLocale(wpLocale),
    fallbackLocale: 'en',
    messages: { de, en, fr, pl, it },
    // Pluralisation uses the pipe syntax; numbers are formatted by Intl via useFormat().
    missingWarn: import.meta.env.DEV,
    fallbackWarn: false,
  });
}
