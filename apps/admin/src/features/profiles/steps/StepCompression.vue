<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ProfileInput } from '@/types/api';
import { useIssueMessage } from '../useIssues';
import type { Issue } from '../wizard';
import StepSection from './StepSection.vue';

const form = defineModel<ProfileInput>({ required: true });
const props = defineProps<{
  keyIds: string[];
  keysLoading: boolean;
  issues: Issue[];
  showIssues: boolean;
}>();
const { t } = useI18n();
const msg = useIssueMessage(
  () => props.issues,
  () => props.showIssues,
);
const keyOptions = computed(() => {
  const ids = new Set(props.keyIds);
  if (form.value.encryption.key_id) ids.add(form.value.encryption.key_id);
  return [...ids].map((id) => ({ value: id, label: id }));
});
</script>

<template>
  <div>
    <StepSection
      :title="t('profiles.sections.compression')"
      :help="t('profiles.sections.compressionDesc')"
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
        <span class="swbi-small"
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
        :error="msg('volume_size_mb') !== undefined"
        :error-message="msg('volume_size_mb')"
      />
    </StepSection>
    <StepSection
      :title="t('profiles.sections.encryption')"
      :help="t('profiles.wizard.encryptionHelp')"
    >
      <q-toggle v-model="form.encryption.enabled" :label="t('profiles.encrypt')" />
      <template v-if="form.encryption.enabled">
        <q-select
          v-model="form.encryption.key_id"
          outlined
          dense
          emit-value
          map-options
          :loading="keysLoading"
          :options="keyOptions"
          :label="t('profiles.keyId')"
          :hint="
            keyOptions.length === 0 && !keysLoading ? t('profiles.noKeys') : t('profiles.keyIdHint')
          "
          :error="msg('encryption.key_id') !== undefined"
          :error-message="msg('encryption.key_id')"
        />
        <router-link v-if="keyOptions.length === 0 && !keysLoading" to="/settings">{{
          t('profiles.createKey')
        }}</router-link>
      </template>
    </StepSection>
  </div>
</template>

<style scoped>
.swbi-small {
  font-size: 13px;
}
</style>
