<script setup lang="ts">
import { uid } from '@/utils/ids';

defineProps<{ title: string; help?: string }>();
const id = uid('swbi-ss');
</script>

<template>
  <section class="swbi-ss" :aria-labelledby="id">
    <div class="swbi-ss__intro">
      <h3 :id="id" class="swbi-ss__title">{{ title }}</h3>
      <p v-if="help" class="swbi-ss__help">{{ help }}</p>
    </div>
    <div class="swbi-ss__body"><slot /></div>
  </section>
</template>

<style scoped>
.swbi-ss {
  display: grid;
  grid-template-columns: 200px minmax(0, 1fr);
  gap: 16px 32px;
  padding: 28px 32px;
  border-bottom: 1px solid var(--swbi-border);
}
.swbi-ss:last-child {
  border-bottom: 0;
}
.swbi-ss__title {
  font-size: 14px;
  font-weight: 650;
}
.swbi-ss__help {
  margin-top: 4px;
  font-size: 13px;
  line-height: 1.6;
  color: var(--swbi-muted);
}
.swbi-ss__body {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
}
@container (max-width: 700px) {
  .swbi-ss {
    grid-template-columns: minmax(0, 1fr);
    padding: 22px 18px;
  }
}
</style>
