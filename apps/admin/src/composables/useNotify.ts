import { Notify } from 'quasar';
import { useI18n } from 'vue-i18n';
import { toApiError } from './useAsyncData';

export function useNotify() {
  const { t } = useI18n();
  return {
    success(message: string): void {
      Notify.create({ type: 'positive', message, icon: 'r_check_circle', timeout: 3500, position: 'bottom-right' });
    },
    info(message: string): void {
      Notify.create({ type: 'info', message, icon: 'r_info', timeout: 4000, position: 'bottom-right' });
    },
    error(error: unknown, fallback?: string): void {
      const err = toApiError(error);
      if (err.isAborted) return;
      Notify.create({
        type: 'negative',
        message: err.message || fallback || t('errors.generic'),
        caption: err.code,
        icon: 'r_error',
        timeout: 7000,
        position: 'bottom-right',
        actions: [{ icon: 'r_close', color: 'white', round: true, flat: true, 'aria-label': t('common.close') }],
      });
    },
  };
}
