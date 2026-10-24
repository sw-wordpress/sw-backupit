<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ProfileInput, StorageTarget } from '@/types/api';
import { useIssueText } from '../useIssues';
import {
  detectPreset,
  includesDatabase,
  includesFiles,
  STEP_IDS,
  type StepId,
  type StepIssues,
} from '../wizard';

const props = defineProps<{ form: ProfileInput; targets: StorageTarget[]; issues: StepIssues }>();
const emit = defineEmits<{ (e: 'go', index: number): void }>();
const { t } = useI18n();
const issueText = useIssueText();

const yesNo = (v: boolean): string =>
  v ? t('profiles.wizard.summary.yes') : t('profiles.wizard.summary.no');
const list = (v: string[], empty: string): string => (v.length > 0 ? v.join(', ') : empty);
const none = computed(() => t('profiles.wizard.summary.none'));

type Row = [label: string, value: string];
const sections = computed<Array<{ id: StepId; rows: Row[] }>>(() => {
  const f = props.form;
  const r = f.retention;
  const preset = detectPreset(f.limits);
  const selected = props.targets
    .filter((x) => f.storage_target_ids.includes(x.id))
    .map((x) => x.name);
  return [
    {
      id: 'general',
      rows: [
        [t('common.name'), f.name.trim() || '–'],
        [t('common.description'), f.description.trim() || '–'],
        [t('common.type'), t(`types.${f.type}`)],
        [
          t('profiles.wizard.priority'),
          `${t(`profiles.wizard.presets.${preset}`)} (${f.limits.time_budget_seconds} s, ${f.limits.batch_rows} rows, ${f.limits.memory_mb} MB)`,
        ],
        [t('profiles.checksums'), yesNo(f.checksums)],
      ],
    },
    {
      id: 'content',
      rows: [
        ...(includesFiles(f.type)
          ? ([
              [
                t('profiles.includePaths'),
                list(f.include_paths, t('profiles.wizard.summary.wholeSite')),
              ],
              [t('profiles.excludePaths'), list(f.exclude_paths, none.value)],
              [t('profiles.includePatterns'), list(f.include_patterns, none.value)],
              [t('profiles.excludePatterns'), list(f.exclude_patterns, none.value)],
            ] as Row[])
          : []),
        ...(includesDatabase(f.type)
          ? ([
              [
                t('profiles.tablesInclude'),
                list(f.db_tables_include, t('profiles.wizard.summary.allTables')),
              ],
              [t('profiles.tablesExclude'), list(f.db_tables_exclude, none.value)],
              [t('profiles.consistentSnapshot'), yesNo(f.db_consistent_snapshot)],
            ] as Row[])
          : []),
      ],
    },
    {
      id: 'compression',
      rows: [
        [
          t('profiles.method'),
          f.compression.method === 'store'
            ? t('profiles.methodStore')
            : `${t('profiles.methodDeflate')}, ${t('profiles.levelShort', { n: f.compression.level })}`,
        ],
        [t('profiles.volumeSize'), `${f.volume_size_mb} MB`],
        [
          t('profiles.encrypt'),
          f.encryption.enabled
            ? `${t('profiles.wizard.summary.yes')} (${f.encryption.key_id || '–'})`
            : yesNo(false),
        ],
      ],
    },
    {
      id: 'storage',
      rows: [
        [t('profiles.sections.targets'), list(selected, t('profiles.wizard.summary.noTargets'))],
        [t('profiles.successPolicy'), t(`profiles.policy.${f.success_policy}`)],
      ],
    },
    {
      id: 'retention',
      rows: [
        [
          t('profiles.maxCount'),
          r.max_count > 0 ? String(r.max_count) : t('profiles.wizard.summary.off'),
        ],
        [
          t('profiles.maxAge'),
          r.max_age_days > 0 ? `${r.max_age_days} d` : t('profiles.wizard.summary.off'),
        ],
        [
          t('profiles.maxStorage'),
          r.max_storage_mb > 0 ? `${r.max_storage_mb} MB` : t('profiles.wizard.summary.off'),
        ],
        [
          t('profiles.gfs'),
          `${r.gfs.daily} / ${r.gfs.weekly} / ${r.gfs.monthly} / ${r.gfs.yearly}`,
        ],
        [t('profiles.deleteLocalAfterUpload'), yesNo(r.delete_local_after_upload)],
        [
          t('profiles.sections.notifications'),
          [
            f.notifications.on_success ? t('profiles.notifySuccess') : '',
            f.notifications.on_failure ? t('profiles.notifyFailure') : '',
            f.notifications.on_warning ? t('profiles.notifyWarning') : '',
          ]
            .filter(Boolean)
            .join(', ') || none.value,
        ],
        [t('profiles.recipients'), list(f.notifications.recipients, '–')],
      ],
    },
  ];
});

const problems = computed(() =>
  STEP_IDS.flatMap((id) =>
    props.issues[id].map((issue) => ({ id, field: issue.field, text: issueText(issue) })),
  ),
);
</script>

<template>
  <div>
    <StepSection
      :title="t('profiles.wizard.steps.summary.title')"
      :help="t('profiles.wizard.summary.intro')"
    >
      <div v-if="problems.length > 0" class="swbi-sum__problems" role="alert">
        <q-icon name="r_error" size="22px" aria-hidden="true" />
        <div>
          <strong>{{ t('profiles.wizard.summary.problems') }}</strong>
          <ul>
            <li v-for="p in problems" :key="p.id + p.field">
              <button type="button" class="swbi-link" @click="emit('go', STEP_IDS.indexOf(p.id))">
                {{ t(`profiles.wizard.steps.${p.id}.title`) }}
              </button>
              · <code>{{ p.field }}</code> {{ p.text }}
            </li>
          </ul>
        </div>
      </div>
      <p v-else class="swbi-sum__ok" role="status">
        <q-icon name="r_check_circle" size="20px" aria-hidden="true" />
        {{ t('profiles.wizard.summary.ok') }}
      </p>
    </StepSection>
    <section
      v-for="s in sections"
      :key="s.id"
      class="swbi-sum__sec"
      :aria-label="t(`profiles.wizard.steps.${s.id}.title`)"
    >
      <header>
        <h3>{{ t(`profiles.wizard.steps.${s.id}.title`) }}</h3>
        <q-btn
          flat
          dense
          no-caps
          color="primary"
          icon="r_edit"
          :label="t('profiles.wizard.summary.edit')"
          :aria-label="
            t('profiles.wizard.summary.editNamed', {
              name: t(`profiles.wizard.steps.${s.id}.title`),
            })
          "
          @click="emit('go', STEP_IDS.indexOf(s.id))"
        />
      </header>
      <dl>
        <div v-for="[label, value] in s.rows" :key="label">
          <dt>{{ label }}</dt>
          <dd>{{ value }}</dd>
        </div>
      </dl>
    </section>
  </div>
</template>

<style scoped>
.swbi-sum__top {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 24px 32px;
  border-bottom: 1px solid var(--swbi-border);
}
.swbi-sum__intro {
  font-size: 14px;
  color: var(--swbi-muted);
}
.swbi-sum__problems {
  display: flex;
  gap: 12px;
  padding: 14px 16px;
  border-radius: var(--swbi-radius-sm);
  background: var(--swbi-danger-bg);
  color: var(--swbi-danger-text);
  font-size: 14px;
}
.swbi-sum__problems ul {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-top: 6px;
}
.swbi-link {
  padding: 0;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  font-weight: 700;
  text-decoration: underline;
  cursor: pointer;
}
.swbi-sum__ok {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--swbi-success-text);
  font-weight: 600;
}
.swbi-sum__sec {
  padding: 20px 32px;
  border-bottom: 1px solid var(--swbi-border);
}
.swbi-sum__sec:last-child {
  border-bottom: 0;
}
.swbi-sum__sec header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}
.swbi-sum__sec h3 {
  font-size: 14px;
  font-weight: 650;
}
.swbi-sum__sec dl {
  display: grid;
  gap: 6px;
}
.swbi-sum__sec dl > div {
  display: grid;
  grid-template-columns: 200px minmax(0, 1fr);
  gap: 16px;
  font-size: 13.5px;
}
.swbi-sum__sec dt {
  color: var(--swbi-muted);
}
.swbi-sum__sec dd {
  overflow-wrap: anywhere;
}
@container (max-width: 700px) {
  .swbi-sum__top {
    padding: 18px;
  }
  .swbi-sum__sec {
    padding: 18px;
  }
  .swbi-sum__sec dl > div {
    grid-template-columns: minmax(0, 1fr);
    gap: 0;
  }
}
</style>
