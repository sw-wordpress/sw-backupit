<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import TagInput from '@/components/ui/TagInput.vue';
import type { ProfileInput } from '@/types/api';
import { useIssueMessage } from '../useIssues';
import type { Issue } from '../wizard';
import StepSection from './StepSection.vue';

const form = defineModel<ProfileInput>({ required: true });
const props = defineProps<{ issues: Issue[]; showIssues: boolean }>();
const { t } = useI18n();
const msg = useIssueMessage(
  () => props.issues,
  () => props.showIssues,
);
const gfs = ['daily', 'weekly', 'monthly', 'yearly'] as const;
</script>

<template>
  <div>
    <StepSection
      :title="t('profiles.sections.retention')"
      :help="t('profiles.sections.retentionDesc')"
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
          :error="msg('retention.max_count') !== undefined"
          :error-message="msg('retention.max_count')"
        />
        <q-input
          v-model.number="form.retention.max_age_days"
          outlined
          dense
          type="number"
          min="0"
          suffix="d"
          :label="t('profiles.maxAge')"
          :hint="t('profiles.zeroOff')"
          :error="msg('retention.max_age_days') !== undefined"
          :error-message="msg('retention.max_age_days')"
        />
      </div>
      <q-input
        v-model.number="form.retention.max_storage_mb"
        outlined
        dense
        type="number"
        min="0"
        suffix="MB"
        :label="t('profiles.maxStorage')"
        :hint="t('profiles.zeroOff')"
        :error="msg('retention.max_storage_mb') !== undefined"
        :error-message="msg('retention.max_storage_mb')"
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
            v-for="g in gfs"
            :key="g"
            v-model.number="form.retention.gfs[g]"
            outlined
            dense
            type="number"
            min="0"
            :label="t(`profiles.gfs${g.charAt(0).toUpperCase()}${g.slice(1)}`)"
            :error="msg(`retention.gfs.${g}`) !== undefined"
            :error-message="msg(`retention.gfs.${g}`)"
          />
        </div>
      </fieldset>
    </StepSection>
    <StepSection
      :title="t('profiles.sections.notifications')"
      :help="t('profiles.sections.notificationsDesc')"
    >
      <q-toggle v-model="form.notifications.on_success" :label="t('profiles.notifySuccess')" />
      <q-toggle v-model="form.notifications.on_failure" :label="t('profiles.notifyFailure')" />
      <q-toggle v-model="form.notifications.on_warning" :label="t('profiles.notifyWarning')" />
      <TagInput
        v-model="form.notifications.recipients"
        :label="t('profiles.recipients')"
        :hint="t('profiles.recipientsHint')"
      />
      <p v-if="msg('notifications.recipients')" class="swbi-error-text" role="alert">
        {{ msg('notifications.recipients') }}
      </p>
    </StepSection>
  </div>
</template>

<style scoped>
.swbi-small {
  font-size: 13px;
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
  min-width: 0;
  margin: 0;
  padding: 12px 14px 4px;
  border: 1px solid var(--swbi-border);
  border-radius: var(--swbi-radius-sm);
}
.swbi-gfs legend {
  padding: 0 6px;
  font-size: 14px;
  font-weight: 600;
}
.swbi-error-text {
  font-size: 13px;
  color: var(--swbi-danger-text);
}
@media (max-width: 560px) {
  .swbi-four {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
