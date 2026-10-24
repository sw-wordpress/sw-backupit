<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { ApiError } from '@/api/client';
import SideSheet from './SideSheet.vue';
import { uid } from '@/utils/ids';

const open = defineModel<boolean>({ required: true });
withDefaults(
  defineProps<{
    title: string;
    subtitle?: string;
    submitLabel?: string;
    loading?: boolean;
    readonly?: boolean;
    /** Server error of the last submit; message and per-field `params` are listed above the form. */
    error?: ApiError | null;
    width?: number;
  }>(),
  {
    subtitle: undefined,
    submitLabel: undefined,
    loading: false,
    readonly: false,
    error: null,
    width: 760,
  },
);
const emit = defineEmits<{
  (e: 'submit'): void;
  /** Emits the `data-tab` value of the first invalid field's tab container (if any). */
  (e: 'invalid-tab', tab: string): void;
}>();
const { t } = useI18n();
const formId = uid('swbi-form');

function onValidationError(component: { $el?: HTMLElement } | undefined): void {
  const tab = component?.$el?.closest<HTMLElement>('[data-tab]')?.dataset.tab;
  if (tab) emit('invalid-tab', tab);
}
</script>

<template>
  <SideSheet v-model="open" :title="title" :subtitle="subtitle" :width="width" persistent>
    <q-form :id="formId" greedy @submit="emit('submit')" @validation-error="onValidationError">
      <div v-if="error" class="swbi-fe" role="alert">
        <q-icon name="r_error" size="20px" aria-hidden="true" />
        <div>
          <strong>{{ error.message }}</strong>
          <ul v-if="Object.keys(error.params).length > 0">
            <li v-for="(msg, field) in error.params" :key="field">
              <code>{{ field }}</code> {{ msg }}
            </li>
          </ul>
          <span class="swbi-mono swbi-fe__code">{{ error.code }}</span>
        </div>
      </div>
      <slot />
    </q-form>
    <template #footer>
      <q-btn flat no-caps :label="t('common.cancel')" :disable="loading" @click="open = false" />
      <q-btn
        v-if="!readonly"
        unelevated
        no-caps
        color="primary"
        type="submit"
        :form="formId"
        :loading="loading"
        :label="submitLabel ?? t('common.save')"
      />
    </template>
  </SideSheet>
</template>

<style scoped>
.swbi-fe {
  display: flex;
  gap: 10px;
  margin-top: 16px;
  padding: 12px 14px;
  border-radius: var(--swbi-radius-sm);
  background: var(--swbi-danger-bg);
  color: var(--swbi-danger-text);
  font-size: 14px;
}
.swbi-fe ul {
  margin-top: 4px;
}
.swbi-fe__code {
  display: block;
  margin-top: 4px;
  opacity: 0.8;
}
</style>
