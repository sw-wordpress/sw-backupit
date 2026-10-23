<script setup lang="ts">
import { useI18n } from 'vue-i18n';
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
    width?: number;
  }>(),
  { subtitle: undefined, submitLabel: undefined, loading: false, readonly: false, width: 760 },
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
