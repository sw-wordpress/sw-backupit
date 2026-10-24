<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import DataState from '@/components/ui/DataState.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import SideSheet from '@/components/ui/SideSheet.vue';
import { useAsyncData } from '@/composables/useAsyncData';
import { useFormat } from '@/composables/useFormat';
import { useNotify } from '@/composables/useNotify';
import type { StorageTarget } from '@/types/api';

const open = defineModel<boolean>({ required: true });
const props = defineProps<{ target: StorageTarget }>();
const emit = defineEmits<{ (e: 'imported'): void }>();
const { t } = useI18n();
const fmt = useFormat();
const notify = useNotify();
const api = useApi();

const found = useAsyncData(() => api.storage.discover(props.target.id), { immediate: false });
const selected = ref<string[]>([]);
const importing = ref(false);
const download = ref(false);
const items = computed(() => found.data.value ?? []);
const importable = computed(() =>
  items.value.filter((b) => !b.already_imported && b.error === null),
);

watch(open, (isOpen) => {
  if (isOpen) {
    selected.value = [];
    download.value = false;
    void found.load();
  }
});

async function runImport(): Promise<void> {
  importing.value = true;
  try {
    const res = await api.storage.import(props.target.id, selected.value, download.value);
    notify.success(t('storage.discover.imported', { n: res.imported }, res.imported));
    emit('imported');
    open.value = false;
  } catch (e) {
    notify.error(e);
  } finally {
    importing.value = false;
  }
}
</script>

<template>
  <SideSheet
    v-model="open"
    :title="t('storage.discover.title')"
    :subtitle="target.name"
    :width="620"
  >
    <div class="swbi-stack swbi-ds">
      <p class="swbi-muted">{{ t('storage.discover.intro') }}</p>
      <DataState
        :loading="found.loading.value"
        :error="found.error.value"
        :empty="items.length === 0"
        @retry="found.load()"
      >
        <template #empty
          ><EmptyState
            illustration="search"
            :title="t('storage.discover.none')"
            :text="t('storage.discover.noneText')"
        /></template>
        <fieldset class="swbi-ds__list">
          <legend class="swbi-sr-only">{{ t('storage.discover.title') }}</legend>
          <label
            v-for="b in items"
            :key="b.backup_id"
            class="swbi-ds__row"
            :class="{ 'is-disabled': b.already_imported || b.error !== null }"
          >
            <q-checkbox
              v-model="selected"
              :val="b.backup_id"
              :disable="b.already_imported || b.error !== null"
              :aria-label="b.backup_id"
            />
            <span class="swbi-ds__info">
              <strong class="swbi-mono">{{ fmt.shortId(b.backup_id) }}</strong>
              <span class="swbi-muted"
                >{{ fmt.dateTime(b.created_at) }} · {{ fmt.bytes(b.size_bytes)
                }}<template v-if="b.profile_name"> · {{ b.profile_name }}</template></span
              >
            </span>
            <span v-if="b.error !== null" class="swbi-ds__err">{{ b.error }}</span>
            <span v-else-if="b.already_imported" class="swbi-muted">{{
              t('storage.discover.already')
            }}</span>
          </label>
        </fieldset>
        <q-checkbox v-model="download" :label="t('storage.discover.download')" />
        <p class="swbi-muted swbi-ds__hint">{{ t('storage.discover.downloadHint') }}</p>
        <div class="swbi-row">
          <q-btn
            unelevated
            no-caps
            color="primary"
            icon="r_download_done"
            :label="t('storage.discover.import', { n: selected.length }, selected.length)"
            :disable="selected.length === 0"
            :loading="importing"
            @click="runImport"
          />
          <q-btn
            flat
            no-caps
            :label="t('storage.discover.selectAll')"
            :disable="importable.length === 0"
            @click="selected = importable.map((b) => b.backup_id)"
          />
        </div>
      </DataState>
    </div>
  </SideSheet>
</template>

<style scoped>
.swbi-ds__err {
  color: var(--swbi-danger-text);
  font-size: 13px;
}
.swbi-ds__hint {
  font-size: 13px;
  margin: -8px 0 12px 36px;
}
.swbi-ds {
  padding-top: 16px;
}
.swbi-ds__list {
  border: 1px solid var(--swbi-border);
  border-radius: var(--swbi-radius-sm);
  padding: 0;
  margin: 0 0 16px;
}
.swbi-ds__row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  border-bottom: 1px solid var(--swbi-border);
  cursor: pointer;
}
.swbi-ds__row:last-child {
  border-bottom: 0;
}
.swbi-ds__row.is-disabled {
  opacity: 0.7;
  cursor: default;
}
.swbi-ds__info {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
  font-size: 14px;
}
</style>
