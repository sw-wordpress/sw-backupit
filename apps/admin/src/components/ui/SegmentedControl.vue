<script setup lang="ts" generic="T extends string">
import { uid } from '@/utils/ids';

/** Radio group styled as a segmented control (native radios: arrow keys, labels, focus ring). */
const model = defineModel<T | null>({ required: true });
defineProps<{ options: Array<{ value: T; label: string }>; legend: string; disabled?: boolean }>();
const name = uid('swbi-seg');
</script>

<template>
  <fieldset class="swbi-seg" :disabled="disabled">
    <legend class="swbi-sr-only">{{ legend }}</legend>
    <label
      v-for="o in options"
      :key="o.value"
      class="swbi-seg__opt"
      :class="{ 'is-on': model === o.value }"
    >
      <input v-model="model" class="swbi-sr-only" type="radio" :name="name" :value="o.value" />
      <span>{{ o.label }}</span>
    </label>
  </fieldset>
</template>

<style scoped>
.swbi-seg {
  display: inline-grid;
  grid-auto-flow: column;
  grid-auto-columns: 1fr;
  gap: 2px;
  margin: 0;
  padding: 4px;
  border: 0;
  border-radius: 10px;
  background: var(--swbi-surface-3);
  max-width: 100%;
}
.swbi-seg__opt {
  display: grid;
  place-items: center;
  height: 32px;
  padding: 0 16px;
  border-radius: 8px;
  color: var(--swbi-muted);
  font-size: 14px;
  cursor: pointer;
  white-space: nowrap;
  transition:
    background-color 0.15s ease,
    color 0.15s ease;
}
.swbi-seg__opt:hover {
  color: var(--swbi-text);
}
.swbi-seg__opt.is-on {
  background: var(--swbi-surface);
  color: var(--swbi-text);
  font-weight: 600;
  box-shadow: var(--swbi-shadow-1);
}
.swbi-seg__opt:focus-within {
  outline: 3px solid var(--swbi-focus);
  outline-offset: 1px;
}
@media (max-width: 480px) {
  .swbi-seg {
    display: grid;
    width: 100%;
  }
  .swbi-seg__opt {
    padding: 0 6px;
  }
}
</style>
