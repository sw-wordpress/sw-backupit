<script setup lang="ts">
withDefaults(
  defineProps<{
    title?: string;
    subtitle?: string;
    icon?: string;
    padding?: boolean;
    interactive?: boolean;
    tag?: string;
    accent?: boolean;
  }>(),
  {
    title: undefined,
    subtitle: undefined,
    icon: undefined,
    padding: true,
    interactive: false,
    tag: 'section',
    accent: false,
  },
);
</script>

<template>
  <component
    :is="tag"
    class="swbi-card"
    :class="{ 'swbi-card--interactive': interactive, 'swbi-card--accent': accent }"
  >
    <header v-if="title || $slots.header || $slots.actions" class="swbi-card__header">
      <slot name="header">
        <div class="swbi-card__heading">
          <span v-if="icon" class="swbi-card__icon" aria-hidden="true"
            ><q-icon :name="icon" size="20px"
          /></span>
          <div class="swbi-card__titles">
            <h2 class="swbi-card__title">{{ title }}</h2>
            <p v-if="subtitle" class="swbi-card__subtitle">{{ subtitle }}</p>
          </div>
        </div>
      </slot>
      <div v-if="$slots.actions" class="swbi-card__actions"><slot name="actions" /></div>
    </header>
    <div class="swbi-card__body" :class="{ 'swbi-card__body--flush': !padding }"><slot /></div>
    <footer v-if="$slots.footer" class="swbi-card__footer"><slot name="footer" /></footer>
  </component>
</template>

<style scoped>
.swbi-card {
  position: relative;
  background: var(--swbi-surface);
  border: 1px solid var(--swbi-border);
  border-radius: var(--swbi-radius);
  box-shadow: var(--swbi-shadow-1);
  min-width: 0;
  transition:
    box-shadow 0.2s ease,
    transform 0.2s ease,
    border-color 0.2s ease;
}
.swbi-card--interactive:hover {
  box-shadow: var(--swbi-shadow-2);
  transform: translateY(-1px);
}
.swbi-card--accent::before {
  content: '';
  position: absolute;
  inset: 0 0 auto 0;
  height: 3px;
  border-radius: var(--swbi-radius) var(--swbi-radius) 0 0;
  background: var(--swbi-gradient);
}
.swbi-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  padding: 18px 22px 0;
}
.swbi-card__heading {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}
.swbi-card__icon {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  flex: none;
  border-radius: 10px;
  color: var(--swbi-primary);
  background: var(--swbi-gradient-soft);
}
.swbi-card__title {
  font-size: 16px;
  font-weight: 650;
  line-height: 1.3;
}
.swbi-card__subtitle {
  font-size: 13px;
  color: var(--swbi-muted);
}
.swbi-card__actions {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
}
.swbi-card__body {
  padding: 18px 22px 22px;
}
.swbi-card__body--flush {
  padding: 12px 0 0;
}
.swbi-card__footer {
  padding: 14px 22px;
  border-top: 1px solid var(--swbi-border);
}
@media (max-width: 520px) {
  .swbi-card__header {
    padding: 16px 16px 0;
  }
  .swbi-card__body {
    padding: 14px 16px 18px;
  }
}
</style>
