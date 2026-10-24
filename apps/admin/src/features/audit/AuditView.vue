<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import DataState from '@/components/ui/DataState.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import SwbiCard from '@/components/ui/SwbiCard.vue';
import { useAsyncData } from '@/composables/useAsyncData';
import { useCapabilities } from '@/composables/useCapabilities';
import { useFormat } from '@/composables/useFormat';
import NoAccess from '@/layouts/NoAccess.vue';
import { AUDIT_ACTIONS } from '@/types/api';

const { t, te } = useI18n();
const fmt = useFormat();
const api = useApi();
const { can } = useCapabilities();
const allowed = can('swbi_settings');

const OBJECT_TYPES = [
  'backup',
  'profile',
  'schedule',
  'storage_target',
  'encryption_key',
  'settings',
];

const action = ref<string | null>(null);
const objectType = ref<string | null>(null);
const userId = ref('');
const debouncedUser = ref('');
const page = ref(1);
const expanded = ref<Set<number>>(new Set());
let timer: ReturnType<typeof setTimeout> | null = null;

const list = useAsyncData(
  () =>
    api.audit.list({
      page: page.value,
      per_page: 25,
      action: action.value ?? undefined,
      object_type: objectType.value ?? undefined,
      user_id: debouncedUser.value !== '' ? Number(debouncedUser.value) : undefined,
    }),
  { immediate: allowed },
);
const items = computed(() => list.data.value?.items ?? []);
const hasFilters = computed(
  () => action.value !== null || objectType.value !== null || debouncedUser.value !== '',
);

watch([action, objectType, debouncedUser], () => {
  page.value = 1;
  void list.load();
});
watch(page, () => void list.load());
watch(userId, (v) => {
  if (timer) clearTimeout(timer);
  timer = setTimeout(() => {
    debouncedUser.value = /^\d+$/.test(v.trim()) ? v.trim() : '';
  }, 300);
});
onBeforeUnmount(() => {
  if (timer) clearTimeout(timer);
});

const actionOptions = computed(() =>
  AUDIT_ACTIONS.map((a) => ({ value: a, label: t(`audit.actions.${a}`) })),
);
const typeOptions = computed(() =>
  OBJECT_TYPES.map((o) => ({ value: o, label: t(`audit.objects.${o}`) })),
);
const actionLabel = (a: string): string => (te(`audit.actions.${a}`) ? t(`audit.actions.${a}`) : a);
const objectLabel = (o: string): string => (te(`audit.objects.${o}`) ? t(`audit.objects.${o}`) : o);
const hasContext = (c: Record<string, unknown>): boolean => Object.keys(c ?? {}).length > 0;
const shortObjectId = (id: string): string => (/^[0-9a-f-]{36}$/.test(id) ? id.slice(0, 8) : id);

function toggle(id: number): void {
  const next = new Set(expanded.value);
  if (next.has(id)) next.delete(id);
  else next.add(id);
  expanded.value = next;
}
function reset(): void {
  action.value = null;
  objectType.value = null;
  userId.value = '';
  debouncedUser.value = '';
}
</script>

<template>
  <NoAccess v-if="!allowed" />
  <div v-else>
    <PageHeader :title="t('nav.audit')" :subtitle="t('audit.subtitle')">
      <q-btn
        flat
        no-caps
        icon="r_refresh"
        :label="t('common.refresh')"
        :loading="list.loading.value"
        @click="list.load()"
      />
    </PageHeader>

    <SwbiCard :padding="false">
      <form class="swbi-filters" role="search" :aria-label="t('audit.filters')" @submit.prevent>
        <q-select
          v-model="action"
          outlined
          dense
          clearable
          emit-value
          map-options
          :options="actionOptions"
          :label="t('audit.action')"
        />
        <q-select
          v-model="objectType"
          outlined
          dense
          clearable
          emit-value
          map-options
          :options="typeOptions"
          :label="t('audit.objectType')"
        />
        <q-input
          v-model="userId"
          outlined
          dense
          clearable
          inputmode="numeric"
          :label="t('audit.userId')"
          autocomplete="off"
        />
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
            :title="t('audit.noMatch')"
            :text="t('audit.noMatchText')"
          >
            <q-btn
              flat
              no-caps
              color="primary"
              icon="r_filter_alt_off"
              :label="t('backups.resetFilters')"
              @click="reset"
            />
          </EmptyState>
          <EmptyState
            v-else
            illustration="jobs"
            :title="t('audit.emptyTitle')"
            :text="t('audit.emptyText')"
          />
        </template>
        <table class="swbi-table">
          <thead>
            <tr>
              <th scope="col">{{ t('audit.col.time') }}</th>
              <th scope="col">{{ t('audit.col.user') }}</th>
              <th scope="col">{{ t('audit.action') }}</th>
              <th scope="col">{{ t('audit.col.object') }}</th>
              <th scope="col">
                <span class="swbi-sr-only">{{ t('audit.col.details') }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <template v-for="e in items" :key="e.id">
              <tr>
                <td :data-label="t('audit.col.time')">
                  <div class="swbi-au__stack">
                    <strong>{{ fmt.relative(e.time) }}</strong>
                    <span class="swbi-muted">{{ fmt.dateTime(e.time) }}</span>
                  </div>
                </td>
                <td :data-label="t('audit.col.user')">
                  <div class="swbi-au__stack">
                    <span>{{ e.user || `#${e.user_id}` }}</span>
                    <span class="swbi-muted swbi-mono">{{ e.ip }}</span>
                  </div>
                </td>
                <td :data-label="t('audit.action')">
                  <span class="swbi-au__action">{{ actionLabel(e.action) }}</span>
                </td>
                <td :data-label="t('audit.col.object')">
                  <div class="swbi-au__stack">
                    <span>{{ objectLabel(e.object_type) }}</span>
                    <span class="swbi-muted swbi-mono">{{ shortObjectId(e.object_id) }}</span>
                  </div>
                </td>
                <td data-label="">
                  <q-btn
                    v-if="hasContext(e.context)"
                    flat
                    dense
                    no-caps
                    :icon="expanded.has(e.id) ? 'r_expand_less' : 'r_expand_more'"
                    :label="t('audit.col.details')"
                    :aria-expanded="expanded.has(e.id)"
                    :aria-controls="`swbi-au-${e.id}`"
                    @click="toggle(e.id)"
                  />
                </td>
              </tr>
              <tr v-if="expanded.has(e.id)" :id="`swbi-au-${e.id}`" class="swbi-au__ctx">
                <td colspan="5" data-label="">
                  <pre>{{ JSON.stringify(e.context, null, 2) }}</pre>
                </td>
              </tr>
            </template>
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
  </div>
</template>

<style scoped>
.swbi-filters {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  padding: 18px 20px 14px;
}
@media (max-width: 760px) {
  .swbi-filters {
    grid-template-columns: minmax(0, 1fr);
  }
}
.swbi-au__stack {
  display: flex;
  flex-direction: column;
  gap: 2px;
  align-items: flex-start;
}
.swbi-au__stack .swbi-muted {
  font-size: 13px;
}
.swbi-au__action {
  font-weight: 600;
}
.swbi-au__ctx td {
  background: var(--swbi-surface-2);
}
.swbi-au__ctx pre {
  margin: 0;
  font-family: var(--swbi-mono);
  font-size: 12.5px;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  text-align: left;
}
@media (max-width: 760px) {
  .swbi-au__stack {
    align-items: flex-end;
  }
}
</style>
