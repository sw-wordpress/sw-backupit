<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataState from '@/components/ui/DataState.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import StatusChip from '@/components/ui/StatusChip.vue';
import SwbiCard from '@/components/ui/SwbiCard.vue';
import { useAsyncData } from '@/composables/useAsyncData';
import { useCapabilities } from '@/composables/useCapabilities';
import { useFormat } from '@/composables/useFormat';
import { useNotify } from '@/composables/useNotify';
import { useLookupsStore } from '@/stores/lookups';
import type { StorageTarget, StorageTestResult } from '@/types/api';
import DiscoverSheet from './DiscoverSheet.vue';
import StorageTargetSheet from './StorageTargetSheet.vue';

const { t } = useI18n();
const fmt = useFormat();
const api = useApi();
const notify = useNotify();
const lookups = useLookupsStore();
const { can } = useCapabilities();

const page = ref(1);
const list = useAsyncData(() => api.storage.list({ page: page.value, per_page: 12 }));
watch(page, () => void list.load());
const items = computed(() => list.data.value?.items ?? []);
const canManage = computed(() => can('swbi_manage_storage'));

const sheetOpen = ref(false);
const editing = ref<StorageTarget | null>(null);
const discoverOpen = ref(false);
const discoverTarget = ref<StorageTarget | null>(null);
const toDelete = ref<StorageTarget | null>(null);
const deleteOpen = ref(false);
const deleting = ref(false);
const testingId = ref<number | null>(null);
const results = ref<Record<number, StorageTestResult | { ok: false; message: string; latency_ms: 0 }>>({});

function openCreate(): void {
  editing.value = null;
  sheetOpen.value = true;
}
function openEdit(tg: StorageTarget): void {
  editing.value = tg;
  sheetOpen.value = true;
}
function openDiscover(tg: StorageTarget): void {
  discoverTarget.value = tg;
  discoverOpen.value = true;
}
function onSaved(): void {
  lookups.invalidate();
  void list.load();
}
async function test(tg: StorageTarget): Promise<void> {
  testingId.value = tg.id;
  try {
    results.value = { ...results.value, [tg.id]: await api.storage.test(tg.id) };
    void list.load();
  } catch (e) {
    const msg = e instanceof Error ? e.message : t('errors.generic');
    results.value = { ...results.value, [tg.id]: { ok: false, message: msg, latency_ms: 0 } };
  } finally {
    testingId.value = null;
  }
}
function askDelete(tg: StorageTarget): void {
  toDelete.value = tg;
  deleteOpen.value = true;
}
async function doDelete(): Promise<void> {
  if (!toDelete.value) return;
  deleting.value = true;
  try {
    await api.storage.remove(toDelete.value.id);
    notify.success(t('storage.deleted'));
    deleteOpen.value = false;
    lookups.invalidate();
    await list.load();
  } catch (e) {
    notify.error(e);
  } finally {
    deleting.value = false;
  }
}
function summary(tg: StorageTarget): string {
  const c = tg.config;
  if (tg.type === 'local') return String(c.path ?? '');
  if (tg.type === 'sftp') return `${String(c.username ?? '')}@${String(c.host ?? '')}:${String(c.port ?? 22)}${String(c.root ?? '')}`;
  return `s3://${String(c.bucket ?? '')}/${String(c.prefix ?? '')}`;
}
</script>

<template>
  <div>
    <PageHeader :title="t('nav.storage')" :subtitle="t('storage.subtitle')">
      <q-btn v-if="canManage" unelevated no-caps color="primary" icon="r_add" :label="t('storage.add')" @click="openCreate" />
    </PageHeader>

    <DataState :loading="list.loading.value" :error="list.error.value" :empty="items.length === 0" variant="cards" @retry="list.load()">
      <template #empty>
        <SwbiCard>
          <EmptyState illustration="storage" :title="t('storage.emptyTitle')" :text="t('storage.emptyText')">
            <q-btn v-if="canManage" unelevated no-caps color="primary" icon="r_add" :label="t('storage.add')" @click="openCreate" />
          </EmptyState>
        </SwbiCard>
      </template>
      <ul class="swbi-grid swbi-grid--wide">
        <li v-for="tg in items" :key="tg.id">
          <SwbiCard tag="article" :aria-label="tg.name" accent>
            <div class="swbi-sc__head">
              <h2 class="swbi-sc__name swbi-truncate">{{ tg.name }}</h2>
              <StatusChip domain="health" :status="tg.status" dense />
            </div>
            <p class="swbi-sc__type"><q-icon :name="tg.type === 'local' ? 'r_hard_drive' : tg.type === 'sftp' ? 'r_terminal' : 'r_cloud'" size="18px" aria-hidden="true" /> {{ t(`storage.types.${tg.type}`) }}</p>
            <p class="swbi-mono swbi-sc__path" :title="summary(tg)">{{ summary(tg) }}</p>
            <p v-if="tg.message" class="swbi-sc__msg">{{ tg.message }}</p>
            <dl class="swbi-sc__facts">
              <div><dt>{{ t('storage.usage') }}</dt><dd class="swbi-num">{{ tg.usage_bytes === null ? t('storage.usageUnknown') : fmt.bytes(tg.usage_bytes) }}</dd></div>
              <div><dt>{{ t('storage.lastChecked') }}</dt><dd>{{ tg.last_checked_at ? fmt.relative(tg.last_checked_at) : t('common.never') }}</dd></div>
            </dl>
            <div class="swbi-row swbi-sc__badges">
              <StatusChip v-if="tg.type !== 'local'" :kind="tg.credentials_set ? 'success' : 'warning'" :icon="tg.credentials_set ? 'r_lock' : 'r_lock_open'" :label="tg.credentials_set ? t('storage.credentialsSet') : t('storage.credentialsMissing')" dense />
              <StatusChip v-if="tg.immutable" kind="info" icon="r_shield" :label="t('storage.immutableShort')" dense />
            </div>
            <div v-if="results[tg.id]" class="swbi-sc__test" :class="results[tg.id]?.ok ? 'is-ok' : 'is-bad'" role="status">
              <q-icon :name="results[tg.id]?.ok ? 'r_check_circle' : 'r_error'" size="18px" aria-hidden="true" />
              <span>{{ results[tg.id]?.ok ? t('storage.testOk', { ms: results[tg.id]?.latency_ms }) : t('storage.testFailed') }} – {{ results[tg.id]?.message }}</span>
            </div>
            <template #footer>
              <div class="swbi-sc__actions">
                <q-btn v-if="canManage" flat no-caps dense icon="r_network_check" :label="t('storage.test')" :loading="testingId === tg.id" @click="test(tg)" />
                <q-btn v-if="canManage" flat no-caps dense icon="r_travel_explore" :label="t('storage.discover.open')" @click="openDiscover(tg)" />
                <span class="swbi-sc__spacer" />
                <q-btn flat round dense :icon="canManage ? 'r_edit' : 'r_visibility'" :aria-label="t('profiles.editNamed', { name: tg.name })" @click="openEdit(tg)"><q-tooltip>{{ canManage ? t('common.edit') : t('common.details') }}</q-tooltip></q-btn>
                <q-btn v-if="canManage" flat round dense color="negative" icon="r_delete" :aria-label="t('profiles.deleteNamed', { name: tg.name })" @click="askDelete(tg)"><q-tooltip>{{ t('common.delete') }}</q-tooltip></q-btn>
              </div>
            </template>
          </SwbiCard>
        </li>
      </ul>
      <PaginationBar v-if="list.data.value" v-model:page="page" :pages="list.data.value.pages" :total="list.data.value.total" />
    </DataState>

    <StorageTargetSheet v-model="sheetOpen" :target="editing" :readonly="!canManage" @saved="onSaved" />
    <DiscoverSheet v-if="discoverTarget" v-model="discoverOpen" :target="discoverTarget" @imported="list.load()" />
    <ConfirmDialog
      v-model="deleteOpen"
      :title="t('storage.deleteTitle')"
      :target="toDelete ? `${toDelete.name} (${t(`storage.types.${toDelete.type}`)})` : undefined"
      :consequences="[t('storage.deleteConsequence1'), t('storage.deleteConsequence2'), t('common.irreversible')]"
      :confirm-label="t('common.delete')"
      :loading="deleting"
      @confirm="doDelete"
    />
  </div>
</template>

<style scoped>
.swbi-sc__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}
.swbi-sc__name {
  font-size: 17px;
  font-weight: 700;
}
.swbi-sc__type {
  margin-top: 6px;
  display: flex;
  gap: 6px;
  align-items: center;
  color: var(--swbi-muted);
}
.swbi-sc__path {
  margin-top: 6px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.swbi-sc__msg {
  margin-top: 6px;
  font-size: 13px;
  color: var(--swbi-muted);
}
.swbi-sc__facts {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  margin-top: 14px;
  font-size: 14px;
}
.swbi-sc__facts dt {
  font-size: 12px;
  color: var(--swbi-muted);
}
.swbi-sc__facts dd {
  font-weight: 600;
}
.swbi-sc__badges {
  margin-top: 12px;
  gap: 6px;
}
.swbi-sc__test {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  margin-top: 12px;
  padding: 8px 12px;
  border-radius: var(--swbi-radius-sm);
  font-size: 13px;
}
.swbi-sc__test.is-ok {
  background: var(--swbi-success-bg);
  color: var(--swbi-success-text);
}
.swbi-sc__test.is-bad {
  background: var(--swbi-danger-bg);
  color: var(--swbi-danger-text);
}
.swbi-sc__actions {
  display: flex;
  align-items: center;
  gap: 2px;
  flex-wrap: wrap;
}
.swbi-sc__spacer {
  flex: 1;
}
</style>
