<script setup lang="ts">
import { uid } from '@/utils/ids';

defineProps<{ title: string; description?: string }>();
const id = uid('swbi-fs');
</script>

<template>
  <section class="swbi-fs" :aria-labelledby="id">
    <div class="swbi-fs__intro">
      <h3 :id="id" class="swbi-fs__title">{{ title }}</h3>
      <p v-if="description" class="swbi-fs__desc">{{ description }}</p>
    </div>
    <div class="swbi-fs__fields"><slot /></div>
  </section>
</template>

<style scoped>
.swbi-fs {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 2fr);
  gap: 12px 32px;
  padding: 22px 0;
  border-bottom: 1px solid var(--swbi-border);
}
.swbi-fs:last-child {
  border-bottom: 0;
}
.swbi-fs__title {
  font-size: 15px;
  font-weight: 650;
}
.swbi-fs__desc {
  margin-top: 4px;
  font-size: 13px;
  color: var(--swbi-muted);
}
.swbi-fs__fields {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
}
@media (max-width: 820px) {
  .swbi-fs {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
