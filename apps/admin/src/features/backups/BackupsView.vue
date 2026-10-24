<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { useApi } from '@/api';
import DataState from '@/components/ui/DataState.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import StatusChip from '@/components/ui/StatusChip.vue';
import SwbiCard from '@/components/ui/SwbiCard.vue';
import { useAsyncData } from '@/composables/useAsyncData';
import { useFormat } from '@/composables/useFormat';
import { useLookupsStore } from '@/stores/lookups';
import type { BackupFilters, BackupStatus, BackupType } from '@/types/api';
import BackupSheet from './BackupSheet.vue';
import { useTransfers } from './useTransfers';

const props = defineProps<{ id?: string }>();
const { t } = useI18n();
const fmt = useFormat();
const api = useApi();
const route = useRoute();
const router = useRouter();
const lookups = useLookupsStore();

const transferList = useTransfers(() => void list.load());
const DAY_MS = 86_400_000;
/** Running transfers and failures of the last day. */
const visibleTransfers = computed(() =>
  transferList.transfers.value.filter(
    (tr) =>
      tr.status === 'queued' ||
      tr.status === 'running' ||
      (tr.status === 'failed' && Date.now() - new Date(tr.updated_at).getTime() < DAY_MS),
  ),
);

const profileId = ref<number | null>(null);
const type = ref<BackupType | null>(null);
const status = ref<BackupStatus | null>(null);
const search = ref('');
const debouncedSearch = ref('');
const page = ref(1);
let timer: ReturnType<typeof setTimeout> | null = null;

const filters = computed<BackupFilters>(() => ({
  profile_id: profileId.value ?? undefined,
  type: type.value ?? undefined,
  status: status.value ?? undefined,
  search: debouncedSearch.value || undefined,
  page: page.value,
  per_page: 20,
}));
const list = useAsyncData(() => api.backups.list(filters.value));
const items = computed(() => list.data.value?.items ?? []);
const hasFilters = computed(
  () =>
    profileId.value !== null ||
    type.value !== null ||
    status.value !== null ||
    debouncedSearch.value !== '',
);

watch([profileId, type, status, debouncedSearch], () => {
  page.value = 1;
  void list.load();
});
watch(page, () => void list.load());
watch(search, (v) => {
  if (timer) clearTimeout(timer);
  timer = setTimeout(() => {
    debouncedSearch.value = v.trim();
  }, 300);
});
onBeforeUnmount(() => {
  if (timer) clearTimeout(timer);
});

onMounted(async () => {
  void transferList.load();
  try {
    await lookups.loadProfiles();
  } catch {
    /* filter just stays empty */
  }
});

const profileOptions = computed(() =>
  lookups.profiles.map((p) => ({ label: p.name, value: p.id })),
);
const typeOptions = computed(() =>
  (['full', 'files', 'database', 'incremental', 'differential'] as const).map((v) => ({
    label: t(`types.${v}`),
    value: v,
  })),
);
const statusOptions = computed(() =>
  (['complete', 'verified', 'corrupt'] as const).map((v) => ({
    label: t(`status.backup.${v}`),
    value: v,
  })),
);

const sheetOpen = ref(false);
watch(
  () => props.id,
  (id) => {
    sheetOpen.value = id !== undefined && id !== '';
  },
  { immediate: true },
);
watch(sheetOpen, (isOpen) => {
  if (!isOpen && props.id) void router.push({ name: 'backups' });
});

function resetFilters(): void {
  profileId.value = null;
  type.value = null;
  status.value = null;
  search.value = '';
  debouncedSearch.value = '';
}
function openRow(id: string): void {
  void router.push({ name: 'backups', params: { id }, query: route.query });
}
</script>

<template>
  <div>
    <PageHeader :title="t('nav.backups')" :subtitle="t('backups.subtitle')">
      <q-btn
        flat
        no-caps
        icon="r_refresh"
        :label="t('common.refresh')"
        :loading="list.loading.value"
        @click="list.load()"
      />
    </PageHeader>

    <SwbiCard
      v-if="visibleTransfers.length > 0"
      :title="t('backups.transfer.listTitle')"
      icon="r_drive_file_move"
      class="swbi-transfers"
    >
      <ul class="swbi-transfers__list" aria-live="polite">
        <li v-for="tr in visibleTransfers" :key="tr.id">
          <div class="swbi-transfers__head">
            <StatusChip domain="transfer" :status="tr.status" dense />
            <router-link
              :to="{ name: 'backups', params: { id: tr.backup_id } }"
              class="swbi-mono"
              >{{ fmt.shortId(tr.backup_id) }}</router-link
            >
            <span>{{
              t(`backups.transfer.summary.${tr.mode}`, {
                source: tr.source_name,
                target: tr.target_name,
              })
            }}</span>
          </div>
          <q-linear-progress
            v-if="tr.status !== 'failed'"
            :value="tr.bytes_total > 0 ? tr.bytes_done / tr.bytes_total : 0"
            :indeterminate="tr.status === 'queued'"
            rounded
            size="8px"
            color="primary"
            :aria-label="t('backups.transfer.progress')"
          />
          <span v-if="tr.status !== 'failed'" class="swbi-muted swbi-transfers__bytes"
            >{{ fmt.bytes(tr.bytes_done) }} / {{ fmt.bytes(tr.bytes_total) }}</span
          >
          <span v-else class="swbi-transfers__error" role="alert">{{ tr.error }}</span>
        </li>
      </ul>
    </SwbiCard>

    <SwbiCard :padding="false">
      <form class="swbi-filters" role="search" :aria-label="t('backups.filters')" @submit.prevent>
        <q-select
          v-model="profileId"
          outlined
          dense
          clearable
          emit-value
          map-options
          :options="profileOptions"
          :label="t('backups.filterProfile')"
        />
        <q-select
          v-model="type"
          outlined
          dense
          clearable
          emit-value
          map-options
          :options="typeOptions"
          :label="t('common.type')"
        />
        <q-select
          v-model="status"
          outlined
          dense
          clearable
          emit-value
          map-options
          :options="statusOptions"
          :label="t('common.status')"
        />
        <q-input
          v-model="search"
          outlined
          dense
          clearable
          type="search"
          :label="t('common.search')"
          autocomplete="off"
        >
          <template #prepend><q-icon name="r_search" aria-hidden="true" /></template>
        </q-input>
      </form>

      <DataState
        :loading="list.loading.value"
        :error="list.error.value"
        :empty="items.length === 0"
        @retry="list.load()"
      >
        <template #empty>
          <EmptyState
            v-if="hasFilters"
            illustration="search"
            :title="t('backups.noMatch')"
            :text="t('backups.noMatchText')"
          >
            <q-btn
              flat
              no-caps
              color="primary"
              icon="r_filter_alt_off"
              :label="t('backups.resetFilters')"
              @click="resetFilters"
            />
          </EmptyState>
          <EmptyState
            v-else
            illustration="backups"
            :title="t('backups.emptyTitle')"
            :text="t('backups.emptyText')"
          >
            <q-btn
              to="/profiles"
              unelevated
              no-caps
              color="primary"
              :label="t('backups.goProfiles')"
            />
          </EmptyState>
        </template>
        <table class="swbi-table">
          <thead>
            <tr>
              <th scope="col">{{ t('backups.col.created') }}</th>
              <th scope="col">{{ t('backups.col.profile') }}</th>
              <th scope="col">{{ t('common.type') }}</th>
              <th scope="col">{{ t('common.status') }}</th>
              <th scope="col" class="swbi-right">{{ t('backups.col.size') }}</th>
              <th scope="col" class="swbi-right">{{ t('backups.col.files') }}</th>
              <th scope="col">{{ t('backups.col.locations') }}</th>
              <th scope="col">
                <span class="swbi-sr-only">{{ t('common.actions') }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="b in items" :key="b.id" class="swbi-row-click" @click="openRow(b.id)">
              <td :data-label="t('backups.col.created')">
                <router-link
                  :to="{ name: 'backups', params: { id: b.id } }"
                  class="swbi-datelink"
                  @click.stop
                >
                  <strong>{{ fmt.relative(b.created_at) }}</strong>
                  <span class="swbi-muted">{{ fmt.dateTime(b.created_at) }}</span>
                </router-link>
              </td>
              <td :data-label="t('backups.col.profile')">{{ b.profile_name }}</td>
              <td :data-label="t('common.type')">
                <StatusChip
                  kind="primary"
                  icon="r_inventory_2"
                  :label="t(`types.${b.type}`)"
                  dense
                />
                <router-link
                  v-if="b.parent_id"
                  class="swbi-chain"
                  :to="{ name: 'backups', params: { id: b.parent_id } }"
                  :title="t('backups.chain.title')"
                  @click.stop
                >
                  <q-icon name="r_subdirectory_arrow_right" size="16px" aria-hidden="true" />
                  <span class="swbi-sr-only">{{ t('backups.chain.basedOn') }}</span>
                  <span class="swbi-mono">{{ fmt.shortId(b.parent_id) }}</span>
                </router-link>
              </td>
              <td :data-label="t('common.status')">
                <div class="swbi-flags">
                  <StatusChip domain="backup" :status="b.status" dense />
                  <q-icon
                    v-if="b.encrypted"
                    name="r_lock"
                    size="18px"
                    class="swbi-muted"
                    :aria-label="t('backups.encrypted')"
                    role="img"
                    ><q-tooltip>{{ t('backups.encrypted') }}</q-tooltip></q-icon
                  >
                  <q-icon
                    v-if="b.protected"
                    name="r_shield"
                    size="18px"
                    class="swbi-muted"
                    :aria-label="t('backups.protected')"
                    role="img"
                    ><q-tooltip>{{ t('backups.protected') }}</q-tooltip></q-icon
                  >
                </div>
              </td>
              <td class="swbi-right swbi-num" :data-label="t('backups.col.size')">
                {{ fmt.bytes(b.size_bytes) }}
              </td>
              <td class="swbi-right swbi-num" :data-label="t('backups.col.files')">
                {{ fmt.number(b.file_count) }}
              </td>
              <td :data-label="t('backups.col.locations')">
                <span class="swbi-num">{{
                  t('backups.locationCount', { n: b.locations.length }, b.locations.length)
                }}</span>
                <span v-if="b.locations.some((l) => l.status === 'failed')" class="swbi-loc-fail"
                  ><q-icon name="r_error" size="16px" aria-hidden="true" />
                  {{ t('status.location.failed') }}</span
                >
              </td>
              <td data-label="">
                <q-btn
                  flat
                  round
                  dense
                  icon="r_chevron_right"
                  :aria-label="t('backups.openDetails')"
                  @click.stop="openRow(b.id)"
                />
              </td>
            </tr>
          </tbody>
        </table>
        <PaginationBar
          v-if="list.data.value"
          v-model:page="page"
          :pages="list.data.value.pages"
          :total="list.data.value.total"
        />
      </DataState>
    </SwbiCard>

    <BackupSheet
      v-if="id"
      v-model="sheetOpen"
      :backup-id="id"
      @changed="
        list.load();
        transferList.load();
      "
      @deleted="list.load()"
    />
  </div>
</template>

<style scoped>
.swbi-transfers {
  margin-bottom: 16px;
}
.swbi-transfers__list {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.swbi-transfers__list li {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.swbi-transfers__head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}
.swbi-transfers__bytes {
  font-size: 13px;
}
.swbi-transfers__error {
  color: var(--swbi-danger-text);
  font-size: 14px;
}
.swbi-chain {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  margin-left: 8px;
  padding: 1px 8px 1px 4px;
  border-radius: 999px;
  background: var(--swbi-neutral-bg);
  color: var(--swbi-neutral-text) !important;
  font-size: 12px;
  text-decoration: none !important;
}
.swbi-filters {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  padding: 18px 20px 14px;
}
@media (max-width: 960px) {
  .swbi-filters {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 520px) {
  .swbi-filters {
    grid-template-columns: minmax(0, 1fr);
  }
}
.swbi-right {
  text-align: right !important;
}
.swbi-row-click {
  cursor: pointer;
}
.swbi-datelink {
  display: flex;
  flex-direction: column;
  color: var(--swbi-text);
  text-decoration: none !important;
}
.swbi-datelink .swbi-muted {
  font-size: 13px;
}
.swbi-flags {
  display: flex;
  align-items: center;
  gap: 8px;
}
.swbi-loc-fail {
  margin-left: 8px;
  color: var(--swbi-danger-text);
  font-size: 13px;
  font-weight: 600;
}
@media (max-width: 760px) {
  .swbi-datelink {
    align-items: flex-end;
  }
}
</style>
