<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { ProfileInput, StorageTarget } from '@/types/api';
import StepSection from './StepSection.vue';

const form = defineModel<ProfileInput>({ required: true });
defineProps<{ targets: StorageTarget[]; loading: boolean }>();
const { t } = useI18n();
</script>

<template>
  <div>
    <StepSection :title="t('profiles.sections.targets')" :help="t('profiles.sections.targetsDesc')">
      <q-skeleton v-if="loading" type="text" width="60%" />
      <p v-else-if="targets.length === 0" class="swbi-muted">
        {{ t('profiles.noTargets') }}
        <router-link to="/storage">{{ t('storage.add') }}</router-link>
      </p>
      <fieldset v-else class="swbi-checks">
        <legend class="swbi-sr-only">{{ t('profiles.sections.targets') }}</legend>
        <label
          v-for="tg in targets"
          :key="tg.id"
          class="swbi-target"
          :class="{ 'is-on': form.storage_target_ids.includes(tg.id) }"
        >
          <q-checkbox v-model="form.storage_target_ids" :val="tg.id" :aria-label="tg.name" />
          <span class="swbi-target__main">
            <strong>{{ tg.name }}</strong>
            <span class="swbi-muted">{{ t(`storage.types.${tg.type}`) }}</span>
          </span>
          <span v-if="tg.status !== 'ok'" class="swbi-target__warn">
            <q-icon name="r_warning" size="16px" aria-hidden="true" />
            {{ t(`status.health.${tg.status}`) }}
          </span>
        </label>
      </fieldset>
      <p
        v-if="!loading && targets.length > 0 && form.storage_target_ids.length === 0"
        class="swbi-warn"
        role="note"
      >
        <q-icon name="r_warning" size="18px" aria-hidden="true" />
        {{ t('profiles.wizard.noTargetSelected') }}
      </p>
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
    </StepSection>
  </div>
</template>

<style scoped>
.swbi-checks {
  display: grid;
  gap: 8px;
  margin: 0;
  padding: 0;
  border: 0;
}
.swbi-target {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px 8px 6px;
  border: 1px solid var(--swbi-border);
  border-radius: 12px;
  cursor: pointer;
}
.swbi-target.is-on {
  border-color: var(--swbi-primary);
  background: var(--swbi-primary-soft);
}
.swbi-target__main {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
  font-size: 14px;
}
.swbi-target__main .swbi-muted {
  font-size: 12.5px;
}
.swbi-target__warn {
  display: inline-flex;
  gap: 4px;
  align-items: center;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--swbi-warning-text);
}
.swbi-warn {
  display: flex;
  gap: 8px;
  padding: 10px 14px;
  border-radius: var(--swbi-radius-sm);
  background: var(--swbi-warning-bg);
  color: var(--swbi-warning-text);
  font-size: 13.5px;
}
</style>
