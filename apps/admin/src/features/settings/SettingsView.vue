<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { onBeforeRouteLeave } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import DataState from '@/components/ui/DataState.vue';
import FormSection from '@/components/ui/FormSection.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import SwbiCard from '@/components/ui/SwbiCard.vue';
import TagInput from '@/components/ui/TagInput.vue';
import { useAsyncData } from '@/composables/useAsyncData';
import { useCapabilities } from '@/composables/useCapabilities';
import { useNotify } from '@/composables/useNotify';
import { useLookupsStore } from '@/stores/lookups';
import type { Settings, Severity } from '@/types/api';

const { t } = useI18n();
const api = useApi();
const notify = useNotify();
const lookups = useLookupsStore();
const { can } = useCapabilities();

const load = useAsyncData(() => api.settings.get());
const form = ref<Settings | null>(null);
const original = ref('');
const saving = ref(false);
const readonly = computed(() => !can('swbi_settings'));
const dirty = computed(() => form.value !== null && JSON.stringify(form.value) !== original.value);
const allTimezones =
  typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : ['UTC'];
const tzOptions = ref<string[]>(allTimezones);

watch(
  () => load.data.value,
  (data) => {
    if (data) {
      form.value = JSON.parse(JSON.stringify(data)) as Settings;
      original.value = JSON.stringify(data);
    }
  },
  { immediate: true },
);
onMounted(async () => {
  try {
    await lookups.loadProfiles();
  } catch {
    /* pre-update profile picker stays empty */
  }
});

const profileOptions = computed(() =>
  lookups.profiles.map((p) => ({ value: p.id, label: p.name })),
);
const levels: Severity[] = ['debug', 'info', 'notice', 'warning', 'error'];
const nonNegative = (v: number): true | string =>
  (Number.isFinite(v) && v >= 0) || t('common.minValue', { n: 0 });
const positive = (v: number): true | string =>
  (Number.isFinite(v) && v >= 1) || t('common.minValue', { n: 1 });

function filterTz(val: string, update: (fn: () => void) => void): void {
  update(() => {
    const needle = val.toLowerCase();
    tzOptions.value = allTimezones.filter((z) => z.toLowerCase().includes(needle));
  });
}

async function save(): Promise<void> {
  if (!form.value) return;
  saving.value = true;
  try {
    const saved = await api.settings.update(form.value);
    load.data.value = saved;
    notify.success(t('settings.saved'));
  } catch (e) {
    notify.error(e);
  } finally {
    saving.value = false;
  }
}
function discard(): void {
  if (load.data.value) form.value = JSON.parse(original.value) as Settings;
}

onBeforeRouteLeave(() => (dirty.value ? window.confirm(t('settings.unsaved')) : true));
</script>

<template>
  <div>
    <PageHeader :title="t('nav.settings')" :subtitle="t('settings.subtitle')" />

    <DataState
      :loading="load.loading.value"
      :error="load.error.value"
      :empty="form === null"
      variant="block"
      @retry="load.load()"
    >
      <q-form v-if="form" class="swbi-stack" @submit="save">
        <fieldset class="swbi-fieldset" :disabled="readonly">
          <div class="swbi-stack">
            <SwbiCard :title="t('settings.sections.storage')" icon="r_folder_open">
              <FormSection :title="t('settings.staging')" :description="t('settings.stagingDesc')">
                <q-input
                  v-model="form.staging_dir"
                  outlined
                  dense
                  class="swbi-mono"
                  :label="t('settings.stagingDir')"
                  :rules="[(v: string) => v.trim() !== '' || t('common.required')]"
                  lazy-rules="ondemand"
                />
              </FormSection>
              <FormSection
                :title="t('settings.retention')"
                :description="t('settings.retentionDesc')"
              >
                <div class="swbi-two">
                  <q-input
                    v-model.number="form.retention_default.max_count"
                    outlined
                    dense
                    type="number"
                    min="0"
                    :label="t('profiles.maxCount')"
                    :hint="t('profiles.zeroOff')"
                    :rules="[nonNegative]"
                    lazy-rules="ondemand"
                  />
                  <q-input
                    v-model.number="form.retention_default.max_age_days"
                    outlined
                    dense
                    type="number"
                    min="0"
                    suffix="d"
                    :label="t('profiles.maxAge')"
                    :hint="t('profiles.zeroOff')"
                    :rules="[nonNegative]"
                    lazy-rules="ondemand"
                  />
                </div>
                <div class="swbi-four">
                  <q-input
                    v-model.number="form.retention_default.gfs.daily"
                    outlined
                    dense
                    type="number"
                    min="0"
                    :label="t('profiles.gfsDaily')"
                    :rules="[nonNegative]"
                    lazy-rules="ondemand"
                  />
                  <q-input
                    v-model.number="form.retention_default.gfs.weekly"
                    outlined
                    dense
                    type="number"
                    min="0"
                    :label="t('profiles.gfsWeekly')"
                    :rules="[nonNegative]"
                    lazy-rules="ondemand"
                  />
                  <q-input
                    v-model.number="form.retention_default.gfs.monthly"
                    outlined
                    dense
                    type="number"
                    min="0"
                    :label="t('profiles.gfsMonthly')"
                    :rules="[nonNegative]"
                    lazy-rules="ondemand"
                  />
                  <q-input
                    v-model.number="form.retention_default.gfs.yearly"
                    outlined
                    dense
                    type="number"
                    min="0"
                    :label="t('profiles.gfsYearly')"
                    :rules="[nonNegative]"
                    lazy-rules="ondemand"
                  />
                </div>
              </FormSection>
            </SwbiCard>

            <SwbiCard :title="t('settings.sections.performance')" icon="r_speed">
              <FormSection :title="t('settings.limits')" :description="t('settings.limitsDesc')">
                <q-input
                  v-model.number="form.limits.time_budget_seconds"
                  outlined
                  dense
                  type="number"
                  suffix="s"
                  :label="t('profiles.timeBudget')"
                  :hint="t('profiles.timeBudgetHint')"
                  :rules="[positive]"
                  lazy-rules="ondemand"
                />
                <q-input
                  v-model.number="form.limits.memory_mb"
                  outlined
                  dense
                  type="number"
                  suffix="MB"
                  :label="t('profiles.memory')"
                  :rules="[positive]"
                  lazy-rules="ondemand"
                />
                <q-input
                  v-model.number="form.limits.batch_rows"
                  outlined
                  dense
                  type="number"
                  :label="t('profiles.batchRows')"
                  :rules="[positive]"
                  lazy-rules="ondemand"
                />
                <q-input
                  v-model.number="form.limits.max_parallel_uploads"
                  outlined
                  dense
                  type="number"
                  :label="t('settings.parallelUploads')"
                  :rules="[positive]"
                  lazy-rules="ondemand"
                />
              </FormSection>
              <FormSection :title="t('settings.cron')" :description="t('settings.cronDesc')">
                <q-select
                  v-model="form.cron.mode"
                  outlined
                  dense
                  emit-value
                  map-options
                  :options="
                    (['wp-cron', 'system'] as const).map((v) => ({
                      value: v,
                      label: t(`settings.cronMode.${v}`),
                    }))
                  "
                  :label="t('settings.cronModeLabel')"
                />
                <div v-if="form.cron.mode === 'system'" class="swbi-cron-help" role="note">
                  <p>{{ t('settings.systemCronHelp') }}</p>
                  <code
                    >* * * * * cd /path/to/wordpress &amp;&amp; wp cron event run --due-now</code
                  >
                </div>
                <q-select
                  v-model="form.cron.timezone"
                  outlined
                  dense
                  use-input
                  hide-selected
                  fill-input
                  input-debounce="0"
                  :options="tzOptions"
                  :label="t('schedules.timezone')"
                  @filter="filterTz"
                />
              </FormSection>
            </SwbiCard>

            <SwbiCard :title="t('settings.sections.security')" icon="r_lock">
              <FormSection
                :title="t('settings.encryption')"
                :description="t('settings.encryptionDesc')"
              >
                <q-input
                  v-model="form.encryption.default_key_id"
                  outlined
                  dense
                  autocomplete="off"
                  :label="t('settings.defaultKeyId')"
                  :hint="t('settings.keyIdHint')"
                />
                <p class="swbi-muted swbi-small">
                  <q-icon name="r_info" size="14px" aria-hidden="true" />
                  {{ t('settings.noPassphrase') }}
                </p>
              </FormSection>
            </SwbiCard>

            <SwbiCard :title="t('settings.sections.monitoring')" icon="r_notifications">
              <FormSection
                :title="t('settings.notifications')"
                :description="t('settings.notificationsDesc')"
              >
                <TagInput
                  v-model="form.notifications.recipients"
                  :label="t('profiles.recipients')"
                  :hint="t('profiles.recipientsHint')"
                />
                <q-input
                  v-model.number="form.notifications.dedupe_minutes"
                  outlined
                  dense
                  type="number"
                  min="0"
                  suffix="min"
                  :label="t('settings.dedupe')"
                  :hint="t('settings.dedupeHint')"
                  :rules="[nonNegative]"
                  lazy-rules="ondemand"
                />
              </FormSection>
              <FormSection :title="t('settings.logging')" :description="t('settings.loggingDesc')">
                <q-select
                  v-model="form.logging.level"
                  outlined
                  dense
                  emit-value
                  map-options
                  :options="levels.map((v) => ({ value: v, label: t(`severity.${v}`) }))"
                  :label="t('settings.logLevel')"
                />
                <div class="swbi-two">
                  <q-input
                    v-model.number="form.logging.retention_days"
                    outlined
                    dense
                    type="number"
                    min="0"
                    suffix="d"
                    :label="t('settings.logRetention')"
                    :rules="[nonNegative]"
                    lazy-rules="ondemand"
                  />
                  <q-input
                    v-model.number="form.logging.max_events_per_job"
                    outlined
                    dense
                    type="number"
                    min="1"
                    :label="t('settings.logMaxEvents')"
                    :rules="[positive]"
                    lazy-rules="ondemand"
                  />
                </div>
              </FormSection>
            </SwbiCard>

            <SwbiCard :title="t('settings.sections.updates')" icon="r_system_update_alt">
              <FormSection
                :title="t('settings.preUpdate')"
                :description="t('settings.preUpdateDesc')"
              >
                <q-toggle
                  v-model="form.pre_update_backup.core"
                  :label="t('settings.preUpdateCore')"
                />
                <q-toggle
                  v-model="form.pre_update_backup.plugins"
                  :label="t('settings.preUpdatePlugins')"
                />
                <q-toggle
                  v-model="form.pre_update_backup.themes"
                  :label="t('settings.preUpdateThemes')"
                />
                <q-select
                  v-model="form.pre_update_backup.profile_id"
                  outlined
                  dense
                  clearable
                  emit-value
                  map-options
                  :options="profileOptions"
                  :label="t('settings.preUpdateProfile')"
                  :hint="t('settings.preUpdateProfileHint')"
                />
              </FormSection>
            </SwbiCard>
          </div>
        </fieldset>

        <div v-if="!readonly" class="swbi-savebar" :class="{ 'is-dirty': dirty }">
          <span class="swbi-savebar__msg" role="status">{{
            dirty ? t('settings.unsavedChanges') : t('settings.noChanges')
          }}</span>
          <q-btn
            flat
            no-caps
            :label="t('common.reset')"
            :disable="!dirty || saving"
            @click="discard"
          />
          <q-btn
            unelevated
            no-caps
            color="primary"
            type="submit"
            icon="r_save"
            :label="t('common.save')"
            :loading="saving"
            :disable="!dirty"
          />
        </div>
        <p v-else class="swbi-muted">{{ t('common.noPermission') }}</p>
      </q-form>
    </DataState>
  </div>
</template>

<style scoped>
.swbi-fieldset {
  border: 0;
  padding: 0;
  margin: 0;
  min-width: 0;
}
.swbi-two {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.swbi-four {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
}
@media (max-width: 560px) {
  .swbi-four {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
.swbi-small {
  font-size: 13px;
}
.swbi-cron-help {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px 14px;
  border-radius: var(--swbi-radius-sm);
  background: var(--swbi-surface-2);
  border: 1px solid var(--swbi-border);
  font-size: 13px;
  color: var(--swbi-muted);
}
.swbi-cron-help code {
  overflow-x: auto;
  white-space: nowrap;
}
.swbi-savebar {
  position: sticky;
  bottom: 16px;
  z-index: 5;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 12px;
  padding: 12px 18px;
  border-radius: var(--swbi-radius);
  background: var(--swbi-surface);
  border: 1px solid var(--swbi-border);
  box-shadow: var(--swbi-shadow-2);
}
.swbi-savebar__msg {
  margin-right: auto;
  font-size: 14px;
  color: var(--swbi-muted);
}
.is-dirty .swbi-savebar__msg {
  color: var(--swbi-warning-text);
  font-weight: 600;
}
</style>
