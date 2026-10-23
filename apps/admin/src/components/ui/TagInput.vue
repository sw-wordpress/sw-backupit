<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

/** Editable list of strings (paths, patterns, tables, e-mail addresses) rendered as chips. */
const model = defineModel<string[]>({ required: true });
defineProps<{ label: string; hint?: string; placeholder?: string; disable?: boolean }>();
const { t } = useI18n();
const input = ref('');

function add(): void {
  const value = input.value.trim();
  if (value !== '' && !model.value.includes(value)) model.value = [...model.value, value];
  input.value = '';
}
function remove(value: string): void {
  model.value = model.value.filter((v) => v !== value);
}
</script>

<template>
  <div class="swbi-tags">
    <q-input
      v-model="input"
      outlined
      dense
      :label="label"
      :hint="hint"
      :placeholder="placeholder"
      :disable="disable"
      @keydown.enter.prevent="add"
      @blur="add"
    >
      <template #append>
        <q-btn
          flat
          round
          dense
          icon="r_add"
          :aria-label="t('common.add')"
          :disable="disable || input.trim() === ''"
          @click="add"
        />
      </template>
    </q-input>
    <ul v-if="model.length > 0" class="swbi-tags__list" :aria-label="label">
      <li v-for="value in model" :key="value" class="swbi-tags__item">
        <span class="swbi-mono swbi-truncate">{{ value }}</span>
        <button
          v-if="!disable"
          type="button"
          class="swbi-tags__remove"
          :aria-label="t('common.removeItem', { item: value })"
          @click="remove(value)"
        >
          <q-icon name="r_close" size="16px" aria-hidden="true" />
        </button>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.swbi-tags__list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 8px;
}
.swbi-tags__item {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  max-width: 100%;
  padding: 2px 4px 2px 10px;
  border-radius: 999px;
  background: var(--swbi-surface-3);
  color: var(--swbi-text);
}
.swbi-tags__remove {
  display: grid;
  place-items: center;
  width: 22px;
  height: 22px;
  border: 0;
  border-radius: 50%;
  background: transparent;
  color: var(--swbi-muted);
  cursor: pointer;
}
.swbi-tags__remove:hover {
  background: var(--swbi-border);
  color: var(--swbi-text);
}
</style>
