import { useI18n } from 'vue-i18n';
import * as f from '@/utils/format';

/** Formatting helpers bound to the active UI locale. */
export function useFormat() {
  const { locale } = useI18n();
  return {
    number: (v: number, digits = 0) => f.formatNumber(v, locale.value, digits),
    bytes: (v: number | null | undefined) => f.formatBytes(v, locale.value),
    rate: (v: number) => f.formatRate(v, locale.value),
    percent: (v: number, isPercent = false) => f.formatPercent(v, locale.value, isPercent),
    duration: f.formatDuration,
    dateTime: (iso: string | null | undefined) => f.formatDateTime(iso, locale.value),
    time: (iso: string | null | undefined) => f.formatTime(iso, locale.value),
    relative: (iso: string | null | undefined) => f.formatRelative(iso, locale.value),
    shortId: f.shortId,
  };
}
