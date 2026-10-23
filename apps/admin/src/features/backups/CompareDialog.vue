<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import SideSheet from '@/components/ui/SideSheet.vue';
import StatTile from '@/components/ui/StatTile.vue';
import { useNotify } from '@/composables/useNotify';
import { useFormat } from '@/composables/useFormat';
import type { CompareMode, CompareResult } from '@/types/api';

const open = defineModel<boolean>({ required: true });
const props = defineProps<{ backupId: string }>();
const { t } = useI18n();
const fmt = useFormat();
const notify = useNotify();

const mode = ref<CompareMode>('metadata');
const running = ref(false);
const result = ref<CompareResult | null>(null);

watch(open, (isOpen) => {
  if (isOpen) result.value = null;
});

const SAMPLE_KEYS = ['modified', 'only_in_backup', 'only_local'] as const;
const samples = computed(() =>
  SAMPLE_KEYS.map((key) => ({ key, items: result.value?.samples[key] ?? [] })).filter(
    (s) => s.items.length > 0,
  ),
);

async function run(): Promise<void> {
  running.value = true;
  result.value = null;
  try {
    result.value = await useApi().backups.compare(props.backupId, mode.value);
  } catch (e) {
    notify.error(e);
  } finally {
    running.value = false;
  }
}
</script>

<template>
  <SideSheet
    v-model="open"
    :title="t('backups.compare.title')"
    :subtitle="t('backups.compare.subtitle')"
    :width="620"
  >
    <div class="swbi-stack swbi-cmp">
      <fieldset class="swbi-cmp__modes">
        <legend>{{ t('backups.compare.mode') }}</legend>
        <q-radio v-model="mode" val="metadata" :label="t('backups.compare.metadata')" />
        <p class="swbi-muted swbi-cmp__hint">{{ t('backups.compare.metadataHint') }}</p>
        <q-radio v-model="mode" val="sha256" :label="t('backups.compare.sha256')" />
        <p class="swbi-muted swbi-cmp__hint">{{ t('backups.compare.sha256Hint') }}</p>
      </fieldset>
      <div>
        <q-btn
          unelevated
          no-caps
          color="primary"
          icon="r_compare_arrows"
          :label="t('backups.compare.run')"
          :loading="running"
          @click="run"
        />
      </div>

      <div v-if="running" aria-busy="true"><q-skeleton height="120px" /></div>
      <template v-if="result">
        <div
          class="swbi-grid swbi-cmp__tiles"
          role="group"
          :aria-label="t('backups.compare.result')"
        >
          <StatTile
            :label="t('backups.compare.modified')"
            :value="fmt.number(result.modified)"
            icon="r_edit_note"
            :tone="result.modified > 0 ? 'warning' : 'default'"
          />
          <StatTile
            :label="t('backups.compare.onlyInBackup')"
            :value="fmt.number(result.only_in_backup)"
            icon="r_inventory_2"
          />
          <StatTile
            :label="t('backups.compare.onlyLocal')"
            :value="fmt.number(result.only_local)"
            icon="r_computer"
          />
          <StatTile
            :label="t('backups.compare.identical')"
            :value="fmt.number(result.identical)"
            icon="r_task_alt"
            tone="success"
          />
          <StatTile
            :label="t('backups.compare.incomparable')"
            :value="fmt.number(result.incomparable)"
            icon="r_help"
          />
        </div>
        <section v-for="s in samples" :key="s.key" class="swbi-cmp__sample">
          <h3>{{ t(`backups.compare.sample.${s.key}`) }}</h3>
          <ul>
            <li v-for="p in s.items" :key="p" class="swbi-mono swbi-truncate" :title="p">
              {{ p }}
            </li>
          </ul>
          <p class="swbi-muted">{{ t('backups.compare.sampleNote', { n: s.items.length }) }}</p>
        </section>
      </template>
    </div>
  </SideSheet>
</template>

<style scoped>
.swbi-cmp {
  padding-top: 16px;
}
.swbi-cmp__modes {
  border: 1px solid var(--swbi-border);
  border-radius: var(--swbi-radius-sm);
  padding: 8px 14px 12px;
  margin: 0;
}
.swbi-cmp__modes legend {
  padding: 0 6px;
  font-weight: 600;
}
.swbi-cmp__hint {
  margin: 0 0 8px 36px;
  font-size: 13px;
}
.swbi-cmp__tiles {
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 170px), 1fr));
}
.swbi-cmp__sample h3 {
  font-size: 14px;
  font-weight: 650;
  margin-bottom: 6px;
}
.swbi-cmp__sample ul {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 10px 12px;
  border-radius: var(--swbi-radius-sm);
  background: var(--swbi-surface-2);
  border: 1px solid var(--swbi-border);
}
.swbi-cmp__sample p {
  margin-top: 4px;
  font-size: 12px;
}
</style>
