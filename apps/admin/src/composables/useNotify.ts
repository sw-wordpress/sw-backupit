import { Notify } from 'quasar';
import { useI18n } from 'vue-i18n';
import { toApiError } from './useAsyncData';

export function useNotify() {
  const { t } = useI18n();
  return {
    success(message: string): void {
      Notify.create({
        type: 'positive',
        message,
        icon: 'r_check_circle',
        timeout: 3500,
        position: 'bottom-right',
      });
    },
    info(message: string): void {
      Notify.create({
        type: 'info',
        message,
        icon: 'r_info',
        timeout: 4000,
        position: 'bottom-right',
      });
    },
    error(error: unknown, fallback?: string): void {
      const err = toApiError(error);
      if (err.isAborted) return;
      if (err.status === 501) {
        // Not an error: the feature intentionally lives elsewhere (e.g. the standalone restore wizard).
        Notify.create({
          type: 'info',
          message: err.message || t('errors.useWizard'),
          caption: t('errors.useWizardHint'),
          icon: 'r_info',
          timeout: 9000,
          position: 'bottom-right',
        });
        return;
      }
      const params = Object.entries(err.params)
        .map(([k, v]) => `${k}: ${v}`)
        .join(' · ');
      if (err.status === 429) {
        Notify.create({
          type: 'warning',
          message: t('errors.rateLimited'),
          caption: err.message,
          icon: 'r_hourglass_top',
          timeout: 8000,
          position: 'bottom-right',
        });
        return;
      }
      Notify.create({
        type: 'negative',
        message: err.message || fallback || t('errors.generic'),
        caption: params !== '' ? params : err.code,
        icon: 'r_error',
        timeout: 7000,
        position: 'bottom-right',
        actions: [
          {
            icon: 'r_close',
            color: 'white',
            round: true,
            flat: true,
            'aria-label': t('common.close'),
          },
        ],
      });
    },
  };
}
