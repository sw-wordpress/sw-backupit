import { useI18n } from 'vue-i18n';
import type { Issue } from './wizard';

/** Translated text of one validation issue. */
export function useIssueText() {
  const { t } = useI18n();
  return (issue: Issue): string => {
    switch (issue.code) {
      case 'required':
        return t('common.required');
      case 'tooLong':
        return t('profiles.wizard.tooLong', { n: issue.n ?? 0 });
      case 'min':
        return t('common.minValue', { n: issue.n ?? 0 });
      case 'email':
        return t('profiles.invalidEmail');
    }
  };
}

/** Message for a field, shown only after the user tried to continue. */
export function useIssueMessage(issues: () => Issue[], show: () => boolean) {
  const text = useIssueText();
  return (field: string): string | undefined => {
    if (!show()) return undefined;
    const issue = issues().find((i) => i.field === field);
    return issue ? text(issue) : undefined;
  };
}
