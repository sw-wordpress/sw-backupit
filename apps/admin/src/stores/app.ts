import { computed, ref } from 'vue';
import { defineStore } from 'pinia';

const THEME_KEY = 'swbi-theme';
export type ThemePreference = 'light' | 'dark' | 'system';

function readStoredTheme(): ThemePreference {
  try {
    const v = window.localStorage.getItem(THEME_KEY);
    return v === 'light' || v === 'dark' ? v : 'system';
  } catch {
    return 'system';
  }
}

function writeStoredTheme(value: ThemePreference): void {
  try {
    if (value === 'system') window.localStorage.removeItem(THEME_KEY);
    else window.localStorage.setItem(THEME_KEY, value);
  } catch {
    /* storage unavailable (private mode, blocked): the preference just is not persisted */
  }
}

export const useAppStore = defineStore('app', () => {
  const themePreference = ref<ThemePreference>(readStoredTheme());
  const systemDark = ref(
    typeof window.matchMedia === 'function' &&
      window.matchMedia('(prefers-color-scheme: dark)').matches,
  );
  const railCollapsed = ref(false);
  /** Browser-driven job runner toggle (calls POST /jobs/{id}/tick). Default on, not persisted. */
  const driveJobs = ref(true);
  /** Text for the polite live region. */
  const announcement = ref('');

  const isDark = computed(() =>
    themePreference.value === 'system' ? systemDark.value : themePreference.value === 'dark',
  );

  function setTheme(value: ThemePreference): void {
    themePreference.value = value;
    writeStoredTheme(value);
  }
  function toggleTheme(): void {
    setTheme(isDark.value ? 'light' : 'dark');
  }
  function announce(message: string): void {
    // Re-set to force re-announcement of identical messages.
    announcement.value = '';
    queueMicrotask(() => {
      announcement.value = message;
    });
  }

  return {
    themePreference,
    systemDark,
    isDark,
    railCollapsed,
    driveJobs,
    announcement,
    setTheme,
    toggleTheme,
    announce,
  };
});
