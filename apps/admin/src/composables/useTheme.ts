import { onScopeDispose, watch } from 'vue';
import { Dark } from 'quasar';
import { useAppStore } from '@/stores/app';

const ROOT_CLASS = 'swbi-root';
const DARK_CLASS = 'swbi-dark';

/**
 * Applies the dark class to the app root and to Quasar's body-level portal nodes
 * (dialogs, menus, notifications) which are created with the `swbi-root` class.
 * Persists only the manual choice, in localStorage (see stores/app.ts).
 */
export function useThemeSync(root: () => HTMLElement | null): void {
  const app = useAppStore();
  const mql =
    typeof window.matchMedia === 'function'
      ? window.matchMedia('(prefers-color-scheme: dark)')
      : null;
  const onSystem = (e: MediaQueryListEvent): void => {
    app.systemDark = e.matches;
  };
  mql?.addEventListener('change', onSystem);

  function applyAll(): void {
    const dark = app.isDark;
    Dark.set(dark);
    root()?.classList.toggle(DARK_CLASS, dark);
    document.querySelectorAll<HTMLElement>(`body > .${ROOT_CLASS}`).forEach((el) => {
      el.classList.toggle(DARK_CLASS, dark);
    });
  }

  const observer = new MutationObserver((records) => {
    for (const record of records) {
      record.addedNodes.forEach((node) => {
        if (node instanceof HTMLElement && node.classList.contains(ROOT_CLASS)) {
          node.classList.toggle(DARK_CLASS, app.isDark);
        }
      });
    }
  });
  observer.observe(document.body, { childList: true });

  watch(() => app.isDark, applyAll, { immediate: true, flush: 'post' });

  onScopeDispose(() => {
    mql?.removeEventListener('change', onSystem);
    observer.disconnect();
  });
}
