<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { uid } from '@/utils/ids';

const open = defineModel<boolean>({ required: true });
const props = withDefaults(
  defineProps<{
    title: string;
    message?: string;
    /** Name of the object that is acted upon (shown prominently). */
    target?: string;
    consequences?: string[];
    confirmLabel: string;
    danger?: boolean;
    loading?: boolean;
    /** If set, the user must type exactly this text to enable the confirm button. */
    requireText?: string;
    checkboxLabel?: string;
  }>(),
  { message: undefined, target: undefined, consequences: () => [], danger: true, loading: false, requireText: undefined, checkboxLabel: undefined },
);
const emit = defineEmits<{ (e: 'confirm'): void }>();
const { t } = useI18n();

const typed = ref('');
const checked = ref(false);
const titleId = uid('swbi-cd');
const ready = computed(
  () =>
    (props.requireText === undefined || typed.value.trim() === props.requireText) &&
    (props.checkboxLabel === undefined || checked.value),
);

watch(open, (value) => {
  if (value) {
    typed.value = '';
    checked.value = false;
  }
});
</script>

<template>
  <q-dialog v-model="open" persistent :aria-labelledby="titleId" @keyup.esc="open = false">
    <q-card class="swbi-cd" role="alertdialog" :aria-labelledby="titleId">
      <q-card-section class="swbi-cd__head">
        <span class="swbi-cd__icon" :class="{ 'swbi-cd__icon--danger': danger }" aria-hidden="true">
          <q-icon :name="danger ? 'r_warning' : 'r_help'" size="24px" />
        </span>
        <div>
          <h2 :id="titleId" class="swbi-cd__title">{{ title }}</h2>
          <p v-if="message" class="swbi-cd__msg">{{ message }}</p>
        </div>
      </q-card-section>
      <q-card-section class="swbi-cd__body">
        <p v-if="target" class="swbi-cd__target">
          <span class="swbi-eyebrow">{{ t('common.target') }}</span>
          <strong class="swbi-truncate">{{ target }}</strong>
        </p>
        <ul v-if="consequences.length > 0" class="swbi-cd__list">
          <li v-for="item in consequences" :key="item">
            <q-icon name="r_chevron_right" size="18px" aria-hidden="true" />{{ item }}
          </li>
        </ul>
        <q-input
          v-if="requireText !== undefined"
          v-model="typed"
          outlined
          dense
          autocomplete="off"
          data-autofocus
          :label="t('common.typeToConfirm', { text: requireText })"
        />
        <q-checkbox v-if="checkboxLabel !== undefined" v-model="checked" :label="checkboxLabel" />
        <slot />
      </q-card-section>
      <q-card-actions align="right" class="swbi-cd__actions">
        <q-btn flat no-caps :label="t('common.cancel')" :disable="loading" data-autofocus @click="open = false" />
        <q-btn
          unelevated
          no-caps
          :color="danger ? 'negative' : 'primary'"
          :label="confirmLabel"
          :loading="loading"
          :disable="!ready"
          @click="emit('confirm')"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<style scoped>
.swbi-cd {
  width: min(520px, calc(100vw - 24px));
  max-width: none;
}
.swbi-cd__head {
  display: flex;
  gap: 14px;
  align-items: flex-start;
}
.swbi-cd__icon {
  display: grid;
  place-items: center;
  flex: none;
  width: 44px;
  height: 44px;
  border-radius: 12px;
  background: var(--swbi-primary-bg);
  color: var(--swbi-primary-text);
}
.swbi-cd__icon--danger {
  background: var(--swbi-danger-bg);
  color: var(--swbi-danger-text);
}
.swbi-cd__title {
  font-size: 18px;
  font-weight: 700;
  line-height: 1.3;
}
.swbi-cd__msg {
  margin-top: 4px;
  color: var(--swbi-muted);
}
.swbi-cd__body {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding-top: 0;
}
.swbi-cd__target {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 10px 14px;
  border-radius: var(--swbi-radius-sm);
  background: var(--swbi-surface-2);
  border: 1px solid var(--swbi-border);
}
.swbi-cd__list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 14px;
}
.swbi-cd__list li {
  display: flex;
  gap: 4px;
  align-items: flex-start;
}
.swbi-cd__actions {
  padding: 8px 16px 16px;
}
</style>
