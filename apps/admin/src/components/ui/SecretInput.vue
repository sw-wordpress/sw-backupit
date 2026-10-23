<script setup lang="ts">
import { useI18n } from 'vue-i18n';

/**
 * Write-only secret field. Never prefilled; `isSet` only shows that the server already holds a
 * value. Leaving it empty means "keep the stored secret".
 */
const model = defineModel<string>({ required: true });
defineProps<{ label: string; isSet?: boolean; multiline?: boolean; required?: boolean }>();
const { t } = useI18n();
</script>

<template>
  <q-input
    v-model="model"
    outlined
    dense
    :label="label"
    :type="multiline ? 'textarea' : 'password'"
    :autogrow="multiline"
    autocomplete="new-password"
    :hint="isSet ? t('storage.credentialsKeepHint') : undefined"
    :rules="required && !isSet ? [(v: string) => v !== '' || t('common.required')] : []"
    lazy-rules="ondemand"
  >
    <template v-if="isSet" #append>
      <span class="swbi-secret-badge"><q-icon name="r_lock" size="14px" aria-hidden="true" /> {{ t('storage.credentialsSet') }}</span>
    </template>
  </q-input>
</template>

<style scoped>
.swbi-secret-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--swbi-success-bg);
  color: var(--swbi-success-text);
  white-space: nowrap;
}
</style>
