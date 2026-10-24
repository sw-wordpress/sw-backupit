import { useI18n } from 'vue-i18n';

/** Translated label of a job step id; unknown ids are shown as delivered, null as a dash. */
export function usePhaseLabel() {
  const { t, te } = useI18n();
  return (phase: string | null | undefined): string => {
    if (!phase) return '–';
    return te(`phases.${phase}`) ? t(`phases.${phase}`) : phase;
  };
}
