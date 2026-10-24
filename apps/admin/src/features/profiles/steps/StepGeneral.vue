<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useNotify } from '@/composables/useNotify';
import type { BackupType, ProfileInput } from '@/types/api';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { ADVANCED_TYPES } from '../defaults';
import { useIssueMessage } from '../useIssues';
import {
  applyPreset,
  detectPreset,
  includesDatabase,
  includesFiles,
  NAME_MAX,
  type Issue,
  type PresetId,
} from '../wizard';
import StepSection from './StepSection.vue';

const form = defineModel<ProfileInput>({ required: true });
const props = defineProps<{
  profileId: number | null;
  fullBackups: number | null;
  issues: Issue[];
  showIssues: boolean;
}>();
const { t } = useI18n();
const notify = useNotify();
const msg = useIssueMessage(
  () => props.issues,
  () => props.showIssues,
);

const TYPES: Array<{ value: BackupType; icon: string }> = [
  { value: 'full', icon: 'r_layers' },
  { value: 'files', icon: 'r_folder_open' },
  { value: 'database', icon: 'r_storage' },
  { value: 'incremental', icon: 'r_account_tree' },
  { value: 'differential', icon: 'r_bar_chart' },
];
const isAdvanced = (type: BackupType): boolean =>
  (ADVANCED_TYPES as readonly string[]).includes(type);

const preset = computed<PresetId | null>({
  get: () => {
    const p = detectPreset(form.value.limits);
    return p === 'custom' ? null : p;
  },
  set: (id) => {
    if (id) form.value.limits = applyPreset(form.value.limits, id);
  },
});
const isCustom = computed(() => detectPreset(form.value.limits) === 'custom');
const copied = ref(false);
async function copyId(): Promise<void> {
  if (props.profileId === null) return;
  try {
    await navigator.clipboard.writeText(String(props.profileId));
    copied.value = true;
  } catch {
    notify.info(t('backups.restore.copyFailed'));
  }
}
</script>

<template>
  <div>
    <StepSection :title="t('profiles.sections.identity')" :help="t('profiles.wizard.identityHelp')">
      <q-input
        v-model="form.name"
        outlined
        :maxlength="NAME_MAX + 20"
        autocomplete="off"
        :label="t('common.name')"
        :error="msg('name') !== undefined"
        :error-message="msg('name')"
        :hint="t('profiles.wizard.nameExample')"
        hide-bottom-space
      >
        <template #append>
          <span class="swbi-mono swbi-count" :class="{ 'is-over': form.name.length > NAME_MAX }"
            >{{ form.name.length }}/{{ NAME_MAX }}</span
          >
        </template>
        <template #label>
          {{ t('common.name') }} <span class="swbi-req">· {{ t('common.required') }}</span>
        </template>
      </q-input>
      <q-input
        v-model="form.description"
        outlined
        type="textarea"
        autogrow
        :label="t('common.description')"
        :placeholder="t('profiles.wizard.descPlaceholder')"
      />
      <div class="swbi-idchip">
        <span class="swbi-eyebrow">{{ t('profiles.wizard.idLabel') }}</span>
        <code v-if="profileId !== null">#{{ profileId }}</code>
        <span v-else class="swbi-muted">{{ t('profiles.wizard.idPending') }}</span>
        <q-btn
          v-if="profileId !== null"
          flat
          round
          dense
          size="sm"
          :icon="copied ? 'r_check' : 'r_content_copy'"
          :aria-label="t('profiles.wizard.copyId')"
          class="swbi-idchip__copy"
          @click="copyId"
        />
      </div>
    </StepSection>

    <StepSection :title="t('profiles.sections.type')" :help="t('profiles.wizard.typeHelp')">
      <fieldset class="swbi-types">
        <legend class="swbi-sr-only">{{ t('profiles.sections.type') }}</legend>
        <label
          v-for="ty in TYPES"
          :key="ty.value"
          class="swbi-type"
          :class="{ 'is-on': form.type === ty.value }"
        >
          <input
            v-model="form.type"
            class="swbi-sr-only"
            type="radio"
            name="swbi-type"
            :value="ty.value"
          />
          <span class="swbi-type__row">
            <span class="swbi-type__ico" aria-hidden="true"
              ><q-icon :name="ty.icon" size="20px"
            /></span>
            <span class="swbi-type__main">
              <span class="swbi-type__title">
                {{ t(`types.${ty.value}`) }}
                <span v-if="ty.value === 'full'" class="swbi-badge swbi-badge--ok">{{
                  t('profiles.wizard.recommended')
                }}</span>
                <span v-if="isAdvanced(ty.value)" class="swbi-badge swbi-badge--adv">{{
                  t('profiles.wizard.advanced')
                }}</span>
              </span>
              <span class="swbi-type__desc">{{ t(`types.${ty.value}Desc`) }}</span>
            </span>
            <span class="swbi-type__dot" aria-hidden="true"><i /></span>
          </span>
          <span v-if="isAdvanced(ty.value)" class="swbi-type__foot swbi-type__foot--warn">
            <q-icon name="r_warning" size="14px" aria-hidden="true" />
            {{ t('profiles.wizard.needsFull') }}
            <template v-if="fullBackups !== null">
              ·
              <span class="swbi-mono" :class="{ 'is-zero': fullBackups === 0 }">{{
                t('profiles.wizard.fullFound', { n: fullBackups }, fullBackups)
              }}</span>
            </template>
          </span>
          <span v-else class="swbi-type__foot swbi-mono">
            <span :class="{ 'is-off': !includesFiles(ty.value) }"
              ><q-icon name="r_description" size="13px" aria-hidden="true" />
              {{ t('profiles.wizard.capFiles') }}</span
            >
            <span :class="{ 'is-off': !includesDatabase(ty.value) }"
              ><q-icon name="r_storage" size="13px" aria-hidden="true" />
              {{ t('profiles.wizard.capDb') }}</span
            >
          </span>
        </label>
      </fieldset>
    </StepSection>

    <StepSection :title="t('profiles.wizard.execution')" :help="t('profiles.wizard.executionHelp')">
      <div>
        <div class="swbi-label">{{ t('profiles.wizard.priority') }}</div>
        <SegmentedControl
          v-model="preset"
          :legend="t('profiles.wizard.priority')"
          :options="[
            { value: 'low', label: t('profiles.wizard.presets.low') },
            { value: 'normal', label: t('profiles.wizard.presets.normal') },
            { value: 'high', label: t('profiles.wizard.presets.high') },
          ]"
        />
        <p class="swbi-mono swbi-muted swbi-values">
          time_budget={{ form.limits.time_budget_seconds }} s · batch_rows={{
            form.limits.batch_rows
          }}
          · memory={{ form.limits.memory_mb }} MB
          <span v-if="isCustom" class="swbi-custom"
            >· {{ t('profiles.wizard.presets.custom') }}</span
          >
        </p>
        <details class="swbi-fine">
          <summary>{{ t('profiles.wizard.fineTune') }}</summary>
          <div class="swbi-fine__grid">
            <q-input
              v-model.number="form.limits.time_budget_seconds"
              outlined
              dense
              type="number"
              suffix="s"
              :label="t('profiles.timeBudget')"
              :hint="t('profiles.timeBudgetHint')"
              :error="msg('limits.time_budget_seconds') !== undefined"
              :error-message="msg('limits.time_budget_seconds')"
            />
            <q-input
              v-model.number="form.limits.batch_rows"
              outlined
              dense
              type="number"
              :label="t('profiles.batchRows')"
              :hint="t('profiles.batchRowsHint')"
              :error="msg('limits.batch_rows') !== undefined"
              :error-message="msg('limits.batch_rows')"
            />
            <q-input
              v-model.number="form.limits.memory_mb"
              outlined
              dense
              type="number"
              suffix="MB"
              :label="t('profiles.memory')"
              :error="msg('limits.memory_mb') !== undefined"
              :error-message="msg('limits.memory_mb')"
            />
          </div>
        </details>
      </div>
      <div class="swbi-opt">
        <div>
          <div class="swbi-opt__title">{{ t('profiles.wizard.checksumsTitle') }}</div>
          <p class="swbi-opt__text">{{ t('profiles.wizard.checksumsText') }}</p>
        </div>
        <q-toggle v-model="form.checksums" :aria-label="t('profiles.wizard.checksumsTitle')" />
      </div>
    </StepSection>
  </div>
</template>

<style scoped>
.swbi-req {
  color: var(--swbi-danger-text);
  font-size: 12px;
}
.swbi-count {
  font-size: 11.5px;
  color: var(--swbi-muted);
}
.swbi-count.is-over {
  color: var(--swbi-danger-text);
  font-weight: 700;
}
.swbi-idchip {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 40px;
  padding: 4px 12px;
  border: 1px dashed var(--swbi-border);
  border-radius: 10px;
  background: var(--swbi-surface-2);
  font-size: 13px;
}
.swbi-idchip__copy {
  margin-left: auto;
}
.swbi-types {
  display: grid;
  gap: 10px;
  margin: 0;
  padding: 0;
  border: 0;
  min-width: 0;
}
.swbi-type {
  display: block;
  padding: 14px 16px;
  border: 1px solid var(--swbi-border);
  border-radius: 14px;
  cursor: pointer;
  transition:
    border-color 0.15s ease,
    background-color 0.15s ease,
    box-shadow 0.15s ease;
}
.swbi-type:hover {
  border-color: var(--swbi-muted);
  background: var(--swbi-surface-2);
}
.swbi-type.is-on {
  border-color: var(--swbi-primary);
  background: var(--swbi-primary-soft);
  box-shadow: 0 0 0 4px var(--swbi-primary-bg);
}
.swbi-type:focus-within {
  outline: 3px solid var(--swbi-focus);
  outline-offset: 2px;
}
.swbi-type__row {
  display: flex;
  align-items: flex-start;
  gap: 12px;
}
.swbi-type__ico {
  display: grid;
  place-items: center;
  flex: none;
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: var(--swbi-surface-3);
  color: var(--swbi-muted);
}
.swbi-type.is-on .swbi-type__ico {
  background: var(--swbi-primary);
  color: #fff;
}
.swbi-type__main {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  flex: 1;
}
.swbi-type__title {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  font-size: 14px;
  font-weight: 650;
}
.swbi-type__desc {
  font-size: 12.5px;
  line-height: 1.45;
  color: var(--swbi-muted);
}
.swbi-type__dot {
  display: grid;
  place-items: center;
  flex: none;
  width: 18px;
  height: 18px;
  border: 2px solid var(--swbi-border);
  border-radius: 50%;
}
.swbi-type__dot i {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: transparent;
}
.swbi-type.is-on .swbi-type__dot {
  border-color: var(--swbi-primary);
}
.swbi-type.is-on .swbi-type__dot i {
  background: var(--swbi-primary);
}
.swbi-type__foot {
  display: flex;
  gap: 16px;
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid var(--swbi-border);
  font-size: 11px;
  color: var(--swbi-muted);
}
.swbi-type__foot .is-off {
  text-decoration: line-through;
  opacity: 0.6;
}
.swbi-type__foot--warn {
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--swbi-warning-text);
  flex-wrap: wrap;
}
.swbi-type__foot--warn .is-zero {
  font-weight: 700;
}
.swbi-badge {
  padding: 1px 7px;
  border-radius: 6px;
  font-size: 10px;
  font-weight: 650;
}
.swbi-badge--ok {
  background: var(--swbi-success-bg);
  color: var(--swbi-success-text);
}
.swbi-badge--adv {
  background: var(--swbi-primary-bg);
  color: var(--swbi-primary-text);
}
.swbi-label {
  margin-bottom: 8px;
  font-size: 12px;
  font-weight: 600;
  color: var(--swbi-muted);
}
.swbi-values {
  margin-top: 8px;
  font-size: 11px;
  overflow-wrap: anywhere;
}
.swbi-custom {
  color: var(--swbi-warning-text);
}
.swbi-fine {
  margin-top: 10px;
  font-size: 13px;
}
.swbi-fine summary {
  cursor: pointer;
  color: var(--swbi-primary);
  font-weight: 600;
}
.swbi-fine__grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  margin-top: 12px;
}
@media (max-width: 640px) {
  .swbi-fine__grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
.swbi-opt {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  padding: 14px 16px;
  border: 1px solid var(--swbi-border);
  border-radius: 14px;
}
.swbi-opt > div {
  flex: 1;
}
.swbi-opt__title {
  font-size: 14px;
  font-weight: 600;
}
.swbi-opt__text {
  margin-top: 2px;
  font-size: 12.5px;
  color: var(--swbi-muted);
}
</style>
