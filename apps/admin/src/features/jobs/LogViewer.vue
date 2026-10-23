<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import type { QVirtualScroll } from 'quasar';
import { useI18n } from 'vue-i18n';
import StatusChip from '@/components/ui/StatusChip.vue';
import type { ChipKind } from '@/components/ui/StatusChip.vue';
import { useFormat } from '@/composables/useFormat';
import type { JobLogEntry, Severity } from '@/types/api';

const props = defineProps<{ logs: JobLogEntry[]; dropped: number }>();
const { t } = useI18n();
const fmt = useFormat();

const SEVERITIES: Severity[] = ['debug', 'info', 'notice', 'warning', 'error'];
const KIND: Record<Severity, { kind: ChipKind; icon: string }> = {
  debug: { kind: 'neutral', icon: 'r_bug_report' },
  info: { kind: 'info', icon: 'r_info' },
  notice: { kind: 'primary', icon: 'r_campaign' },
  warning: { kind: 'warning', icon: 'r_warning' },
  error: { kind: 'danger', icon: 'r_error' },
};

const severity = ref<Severity | null>(null);
const phase = ref<string | null>(null);
const autoScroll = ref(true);
const scroller = ref<QVirtualScroll | null>(null);

const phaseOptions = computed(() => [...new Set(props.logs.map((l) => l.phase))].filter(Boolean));
const filtered = computed(() =>
  props.logs.filter(
    (l) =>
      (severity.value === null || l.severity === severity.value) &&
      (phase.value === null || l.phase === phase.value),
  ),
);

watch(
  () => filtered.value.length,
  async (len) => {
    if (!autoScroll.value || len === 0) return;
    await nextTick();
    scroller.value?.scrollTo(len - 1, 'end');
  },
  { flush: 'post' },
);
watch(autoScroll, async (on) => {
  if (on && filtered.value.length > 0) {
    await nextTick();
    scroller.value?.scrollTo(filtered.value.length - 1, 'end');
  }
});

function context(entry: JobLogEntry): string {
  return JSON.stringify(entry.context, null, 2);
}
function hasContext(entry: JobLogEntry): boolean {
  return Object.keys(entry.context ?? {}).length > 0;
}
</script>

<template>
  <div class="swbi-log">
    <div class="swbi-log__bar">
      <q-select
        v-model="severity"
        outlined
        dense
        clearable
        emit-value
        map-options
        class="swbi-log__sel"
        :label="t('jobs.log.severity')"
        :options="SEVERITIES.map((s) => ({ value: s, label: t(`severity.${s}`) }))"
      />
      <q-select
        v-model="phase"
        outlined
        dense
        clearable
        class="swbi-log__sel"
        :label="t('jobs.log.phase')"
        :options="phaseOptions"
      />
      <q-toggle v-model="autoScroll" :label="t('jobs.log.autoScroll')" />
    </div>
    <p v-if="dropped > 0" class="swbi-log__note" role="note">
      {{ t('jobs.log.truncated', { n: dropped }, dropped) }}
    </p>

    <div v-if="filtered.length === 0" class="swbi-log__empty swbi-muted">
      {{ logs.length === 0 ? t('jobs.log.empty') : t('jobs.log.noMatch') }}
    </div>
    <q-virtual-scroll
      v-else
      ref="scroller"
      class="swbi-log__list"
      role="log"
      aria-live="off"
      tabindex="0"
      :aria-label="t('jobs.log.title')"
      :items="filtered"
      :virtual-scroll-item-size="36"
    >
      <template #default="{ item }">
        <div :key="item.id" class="swbi-log__row" :class="`swbi-log__row--${item.severity}`">
          <time class="swbi-log__time swbi-num" :datetime="item.time">{{
            fmt.time(item.time)
          }}</time>
          <StatusChip
            :kind="KIND[item.severity as Severity].kind"
            :icon="KIND[item.severity as Severity].icon"
            :label="t(`severity.${item.severity as Severity}`)"
            dense
            class="swbi-log__sev"
          />
          <span class="swbi-log__phase swbi-muted">{{ item.phase }}</span>
          <span class="swbi-log__msg" :title="item.message">{{ item.message }}</span>
          <q-btn
            v-if="hasContext(item)"
            flat
            round
            dense
            size="sm"
            icon="r_data_object"
            :aria-label="t('jobs.log.context')"
          >
            <q-menu>
              <pre class="swbi-log__ctx">{{ context(item) }}</pre>
            </q-menu>
          </q-btn>
        </div>
      </template>
    </q-virtual-scroll>
  </div>
</template>

<style scoped>
.swbi-log__bar {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  align-items: center;
  margin-bottom: 12px;
}
.swbi-log__sel {
  min-width: 170px;
  flex: 0 1 200px;
}
.swbi-log__note {
  font-size: 13px;
  color: var(--swbi-warning-text);
  margin-bottom: 8px;
}
.swbi-log__empty {
  padding: 28px;
  text-align: center;
}
.swbi-log__list {
  height: 360px;
  border: 1px solid var(--swbi-border);
  border-radius: var(--swbi-radius-sm);
  background: var(--swbi-surface-2);
  font-size: 13px;
}
.swbi-log__row {
  display: grid;
  grid-template-columns: 78px 96px 92px minmax(0, 1fr) auto;
  align-items: center;
  gap: 10px;
  height: 36px;
  padding: 0 12px;
  border-bottom: 1px solid var(--swbi-border);
}
.swbi-log__row--error {
  background: var(--swbi-danger-bg);
}
.swbi-log__row--warning {
  background: var(--swbi-warning-bg);
}
.swbi-log__time {
  color: var(--swbi-muted);
}
.swbi-log__msg {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-family: var(--swbi-mono);
  font-size: 12.5px;
}
.swbi-log__ctx {
  margin: 0;
  padding: 12px;
  max-width: 480px;
  max-height: 280px;
  overflow: auto;
  font-family: var(--swbi-mono);
  font-size: 12px;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
@media (max-width: 640px) {
  .swbi-log__row {
    grid-template-columns: 70px auto minmax(0, 1fr) auto;
  }
  .swbi-log__phase {
    display: none;
  }
  /* icon-only severity on small screens; the text stays available to screen readers */
  .swbi-log__sev :deep(.swbi-chip__text) {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
  }
}
</style>
