<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import FormSection from '@/components/ui/FormSection.vue';
import FormSheet from '@/components/ui/FormSheet.vue';
import TagInput from '@/components/ui/TagInput.vue';
import { useFormError } from '@/composables/useFormError';
import { useNotify } from '@/composables/useNotify';
import { useLookupsStore } from '@/stores/lookups';
import type { BackupType, Profile, ProfileInput } from '@/types/api';
import { ADVANCED_TYPES, defaultProfileInput, profileToInput } from './defaults';

const open = defineModel<boolean>({ required: true });
const props = defineProps<{ profile: Profile | null; readonly?: boolean }>();
const emit = defineEmits<{ (e: 'saved', profile: Profile): void }>();

const { t } = useI18n();
const notify = useNotify();
const formError = useFormError();
const lookups = useLookupsStore();
const form = ref<ProfileInput>(defaultProfileInput());
const tab = ref('general');
const saving = ref(false);
const targetsLoading = ref(false);
const keysLoading = ref(false);
const keyIds = ref<string[]>([]);
const keyOptions = computed(() => {
  const ids = new Set(keyIds.value);
  if (form.value.encryption.key_id) ids.add(form.value.encryption.key_id);
  return [...ids].map((id) => ({ value: id, label: id }));
});

const TABS = ['general', 'content', 'compression', 'storage', 'limits', 'notifications'] as const;
const TYPES: BackupType[] = ['full', 'files', 'database', 'incremental', 'differential'];
const emailRule = (v: string[]): true | string =>
  v.every((a) => /^\S+@\S+\.\S+$/.test(a)) || t('profiles.invalidEmail');
const positive = (v: number): true | string =>
  (Number.isFinite(v) && v >= 1) || t('common.minValue', { n: 1 });
const nonNegative = (v: number): true | string =>
  (Number.isFinite(v) && v >= 0) || t('common.minValue', { n: 0 });

watch(open, async (isOpen) => {
  formError.clear();
  if (!isOpen) return;
  form.value = props.profile ? profileToInput(props.profile) : defaultProfileInput();
  tab.value = 'general';
  keysLoading.value = true;
  useApi()
    .keys.list()
    .then((res) => {
      keyIds.value = res.items.map((k) => k.id);
    })
    .catch((e: unknown) => notify.error(e))
    .finally(() => {
      keysLoading.value = false;
    });
  targetsLoading.value = true;
  try {
    await lookups.loadTargets(true);
  } catch (e) {
    notify.error(e);
  } finally {
    targetsLoading.value = false;
  }
});

const targetOptions = computed(() => lookups.targets);
const isAdvanced = computed(() => (ADVANCED_TYPES as readonly string[]).includes(form.value.type));
const title = computed(() => (props.profile ? t('profiles.edit') : t('profiles.create')));

async function save(): Promise<void> {
  formError.clear();
  saving.value = true;
  try {
    const api = useApi();
    const saved = props.profile
      ? await api.profiles.update(props.profile.id, form.value)
      : await api.profiles.create(form.value);
    notify.success(t('common.saved'));
    lookups.invalidate();
    emit('saved', saved);
    open.value = false;
  } catch (e) {
    formError.handle(e);
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <FormSheet
    v-model="open"
    :error="formError.error.value"
    :title="title"
    :subtitle="profile?.name"
    :loading="saving"
    :readonly="readonly"
    @submit="save"
    @invalid-tab="tab = $event"
  >
    <q-tabs
      v-model="tab"
      dense
      align="left"
      mobile-arrows
      outside-arrows
      no-caps
      active-color="primary"
      indicator-color="primary"
      class="swbi-ptabs"
    >
      <q-tab v-for="name in TABS" :key="name" :name="name" :label="t(`profiles.tabs.${name}`)" />
    </q-tabs>
    <q-separator />

    <fieldset class="swbi-fieldset" :disabled="readonly">
      <div v-show="tab === 'general'" data-tab="general" role="tabpanel">
        <FormSection
          :title="t('profiles.sections.identity')"
          :description="t('profiles.sections.identityDesc')"
        >
          <q-input
            v-model="form.name"
            outlined
            dense
            :label="t('common.name')"
            maxlength="120"
            :rules="[(v: string) => v.trim() !== '' || t('common.required')]"
            lazy-rules="ondemand"
          />
          <q-input
            v-model="form.description"
            outlined
            dense
            type="textarea"
            autogrow
            :label="t('common.description')"
          />
        </FormSection>
        <FormSection
          :title="t('profiles.sections.type')"
          :description="t('profiles.sections.typeDesc')"
        >
          <q-option-group
            v-model="form.type"
            type="radio"
            :options="TYPES.map((v) => ({ value: v, label: t(`types.${v}`) }))"
            class="swbi-types"
          >
            <template #label="opt">
              <span class="swbi-type-opt">
                <strong>{{ opt.label }}</strong>
                <span class="swbi-muted">{{ t(`types.${opt.value as BackupType}Desc`) }}</span>
                <span
                  v-if="(ADVANCED_TYPES as readonly string[]).includes(opt.value as string)"
                  class="swbi-adv-chip"
                >
                  <q-icon name="r_info" size="14px" aria-hidden="true" />{{
                    t('profiles.advancedChip')
                  }}
                </span>
              </span>
            </template>
          </q-option-group>
          <p v-if="isAdvanced" class="swbi-note" role="note">
            <q-icon name="r_info" size="18px" aria-hidden="true" /> {{ t('profiles.advancedNote') }}
          </p>
        </FormSection>
      </div>

      <div v-show="tab === 'content'" data-tab="content" role="tabpanel">
        <FormSection
          :title="t('profiles.sections.paths')"
          :description="t('profiles.sections.pathsDesc')"
        >
          <TagInput
            v-model="form.include_paths"
            :label="t('profiles.includePaths')"
            :hint="t('profiles.pathsHint')"
          />
          <TagInput v-model="form.exclude_paths" :label="t('profiles.excludePaths')" />
          <TagInput
            v-model="form.external_paths"
            :label="t('profiles.externalPaths')"
            :hint="t('profiles.externalPathsHint')"
          />
        </FormSection>
        <FormSection
          :title="t('profiles.sections.patterns')"
          :description="t('profiles.sections.patternsDesc')"
        >
          <TagInput
            v-model="form.include_patterns"
            :label="t('profiles.includePatterns')"
            placeholder="**/*.php"
          />
          <TagInput
            v-model="form.exclude_patterns"
            :label="t('profiles.excludePatterns')"
            placeholder="**/*.log"
          />
        </FormSection>
        <FormSection
          :title="t('profiles.sections.database')"
          :description="t('profiles.sections.databaseDesc')"
        >
          <TagInput
            v-model="form.db_tables_include"
            :label="t('profiles.tablesInclude')"
            :hint="t('profiles.tablesIncludeHint')"
          />
          <TagInput v-model="form.db_tables_exclude" :label="t('profiles.tablesExclude')" />
          <q-toggle v-model="form.db_include_foreign_tables" :label="t('profiles.foreignTables')" />
          <q-toggle
            v-model="form.db_consistent_snapshot"
            :label="t('profiles.consistentSnapshot')"
          />
          <p class="swbi-note" role="note">
            <q-icon name="r_info" size="18px" aria-hidden="true" />
            {{ t('profiles.consistentSnapshotInfo') }}
          </p>
        </FormSection>
      </div>

      <div v-show="tab === 'compression'" data-tab="compression" role="tabpanel">
        <FormSection
          :title="t('profiles.sections.compression')"
          :description="t('profiles.sections.compressionDesc')"
        >
          <q-select
            v-model="form.compression.method"
            outlined
            dense
            emit-value
            map-options
            :options="[
              { value: 'deflate', label: t('profiles.methodDeflate') },
              { value: 'store', label: t('profiles.methodStore') },
            ]"
            :label="t('profiles.method')"
          />
          <div v-if="form.compression.method === 'deflate'">
            <span class="swbi-slider-label"
              >{{ t('profiles.level') }}:
              <strong class="swbi-num">{{ form.compression.level }}</strong></span
            >
            <q-slider
              v-model="form.compression.level"
              :min="1"
              :max="9"
              :step="1"
              snap
              markers
              label
              :aria-label="t('profiles.level')"
              color="primary"
            />
            <span class="swbi-muted swbi-small">{{ t('profiles.levelHint') }}</span>
          </div>
          <q-input
            v-model.number="form.volume_size_mb"
            outlined
            dense
            type="number"
            suffix="MB"
            :label="t('profiles.volumeSize')"
            :hint="t('profiles.volumeSizeHint')"
            :rules="[positive]"
            lazy-rules="ondemand"
          />
          <q-toggle v-model="form.checksums" :label="t('profiles.checksums')" />
        </FormSection>
        <FormSection
          :title="t('profiles.sections.encryption')"
          :description="t('profiles.sections.encryptionDesc')"
        >
          <q-toggle v-model="form.encryption.enabled" :label="t('profiles.encrypt')" />
          <q-select
            v-if="form.encryption.enabled"
            v-model="form.encryption.key_id"
            outlined
            dense
            emit-value
            map-options
            :loading="keysLoading"
            :options="keyOptions"
            :label="t('profiles.keyId')"
            :hint="
              keyOptions.length === 0 && !keysLoading
                ? t('profiles.noKeys')
                : t('profiles.keyIdHint')
            "
            :rules="[(v: string) => v.trim() !== '' || t('common.required')]"
            lazy-rules="ondemand"
          />
          <router-link
            v-if="form.encryption.enabled && keyOptions.length === 0 && !keysLoading"
            to="/settings"
            @click="open = false"
            >{{ t('profiles.createKey') }}</router-link
          >
        </FormSection>
      </div>

      <div v-show="tab === 'storage'" data-tab="storage" role="tabpanel">
        <FormSection
          :title="t('profiles.sections.targets')"
          :description="t('profiles.sections.targetsDesc')"
        >
          <q-skeleton v-if="targetsLoading" type="text" width="60%" />
          <p v-else-if="targetOptions.length === 0" class="swbi-muted">
            {{ t('profiles.noTargets') }}
            <router-link to="/storage" @click="open = false">{{ t('storage.add') }}</router-link>
          </p>
          <fieldset v-else class="swbi-checks">
            <legend class="swbi-sr-only">{{ t('profiles.sections.targets') }}</legend>
            <q-checkbox
              v-for="target in targetOptions"
              :key="target.id"
              v-model="form.storage_target_ids"
              :val="target.id"
              :label="`${target.name} (${t(`storage.types.${target.type}`)})`"
            />
          </fieldset>
          <q-select
            v-model="form.success_policy"
            outlined
            dense
            emit-value
            map-options
            :options="
              (['all', 'any', 'local_only'] as const).map((v) => ({
                value: v,
                label: t(`profiles.policy.${v}`),
              }))
            "
            :label="t('profiles.successPolicy')"
            :hint="t(`profiles.policy.${form.success_policy}Desc`)"
          />
        </FormSection>
        <FormSection
          :title="t('profiles.sections.retention')"
          :description="t('profiles.sections.retentionDesc')"
        >
          <div class="swbi-two">
            <q-input
              v-model.number="form.retention.max_count"
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
              v-model.number="form.retention.max_age_days"
              outlined
              dense
              type="number"
              min="0"
              :label="t('profiles.maxAge')"
              suffix="d"
              :hint="t('profiles.zeroOff')"
              :rules="[nonNegative]"
              lazy-rules="ondemand"
            />
          </div>
          <q-input
            v-model.number="form.retention.max_storage_mb"
            outlined
            dense
            type="number"
            min="0"
            :label="t('profiles.maxStorage')"
            suffix="MB"
            :hint="t('profiles.zeroOff')"
            :rules="[nonNegative]"
            lazy-rules="ondemand"
          />
          <q-toggle
            v-model="form.retention.delete_local_after_upload"
            :label="t('profiles.deleteLocalAfterUpload')"
          />
          <p class="swbi-muted swbi-small">{{ t('profiles.deleteLocalAfterUploadHint') }}</p>
          <fieldset class="swbi-gfs">
            <legend>{{ t('profiles.gfs') }}</legend>
            <p class="swbi-muted swbi-small">{{ t('profiles.gfsHint') }}</p>
            <div class="swbi-four">
              <q-input
                v-model.number="form.retention.gfs.daily"
                outlined
                dense
                type="number"
                min="0"
                :label="t('profiles.gfsDaily')"
                :rules="[nonNegative]"
                lazy-rules="ondemand"
              />
              <q-input
                v-model.number="form.retention.gfs.weekly"
                outlined
                dense
                type="number"
                min="0"
                :label="t('profiles.gfsWeekly')"
                :rules="[nonNegative]"
                lazy-rules="ondemand"
              />
              <q-input
                v-model.number="form.retention.gfs.monthly"
                outlined
                dense
                type="number"
                min="0"
                :label="t('profiles.gfsMonthly')"
                :rules="[nonNegative]"
                lazy-rules="ondemand"
              />
              <q-input
                v-model.number="form.retention.gfs.yearly"
                outlined
                dense
                type="number"
                min="0"
                :label="t('profiles.gfsYearly')"
                :rules="[nonNegative]"
                lazy-rules="ondemand"
              />
            </div>
          </fieldset>
        </FormSection>
      </div>

      <div v-show="tab === 'limits'" data-tab="limits" role="tabpanel">
        <FormSection
          :title="t('profiles.sections.limits')"
          :description="t('profiles.sections.limitsDesc')"
        >
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
            :hint="t('profiles.batchRowsHint')"
            :rules="[positive]"
            lazy-rules="ondemand"
          />
        </FormSection>
      </div>

      <div v-show="tab === 'notifications'" data-tab="notifications" role="tabpanel">
        <FormSection
          :title="t('profiles.sections.notifications')"
          :description="t('profiles.sections.notificationsDesc')"
        >
          <q-toggle v-model="form.notifications.on_success" :label="t('profiles.notifySuccess')" />
          <q-toggle v-model="form.notifications.on_failure" :label="t('profiles.notifyFailure')" />
          <q-toggle v-model="form.notifications.on_warning" :label="t('profiles.notifyWarning')" />
          <TagInput
            v-model="form.notifications.recipients"
            :label="t('profiles.recipients')"
            :hint="t('profiles.recipientsHint')"
          />
          <p
            v-if="emailRule(form.notifications.recipients) !== true"
            class="swbi-error-text"
            role="alert"
          >
            {{ t('profiles.invalidEmail') }}
          </p>
        </FormSection>
      </div>
    </fieldset>
  </FormSheet>
</template>

<style scoped>
.swbi-fieldset {
  border: 0;
  margin: 0;
  padding: 0;
  min-width: 0;
}
.swbi-ptabs {
  margin-top: 4px;
}
.swbi-types :deep(.q-radio) {
  align-items: flex-start;
  padding: 8px 0;
}
.swbi-type-opt {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin-left: 4px;
}
.swbi-adv-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  align-self: flex-start;
  margin-top: 2px;
  padding: 1px 8px;
  font-size: 12px;
  font-weight: 600;
  border-radius: 999px;
  background: var(--swbi-info-bg);
  color: var(--swbi-info-text);
}
.swbi-note {
  display: flex;
  gap: 8px;
  padding: 10px 14px;
  border-radius: var(--swbi-radius-sm);
  background: var(--swbi-info-bg);
  color: var(--swbi-info-text);
  font-size: 14px;
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
.swbi-gfs {
  border: 1px solid var(--swbi-border);
  border-radius: var(--swbi-radius-sm);
  padding: 12px 14px 4px;
  min-width: 0;
}
.swbi-gfs legend {
  padding: 0 6px;
  font-weight: 600;
  font-size: 14px;
}
.swbi-checks {
  border: 0;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
}
.swbi-slider-label,
.swbi-small {
  font-size: 13px;
}
.swbi-error-text {
  color: var(--swbi-danger-text);
  font-size: 13px;
}
@media (max-width: 560px) {
  .swbi-two,
  .swbi-four {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
