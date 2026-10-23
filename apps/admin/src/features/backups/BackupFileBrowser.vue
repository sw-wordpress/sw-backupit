<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import type { ApiError } from '@/api/client';
import EmptyState from '@/components/ui/EmptyState.vue';
import ErrorState from '@/components/ui/ErrorState.vue';
import { toApiError } from '@/composables/useAsyncData';
import { useFormat } from '@/composables/useFormat';
import type { ArchiveEntry } from '@/types/api';

const props = defineProps<{ backupId: string }>();
const { t } = useI18n();
const fmt = useFormat();
const api = useApi();

const PER_PAGE = 100;
const path = ref('');
const search = ref('');
const entries = ref<ArchiveEntry[]>([]);
const total = ref(0);
const page = ref(0);
const pages = ref(1);
const loading = ref(false);
const error = ref<ApiError | null>(null);
let ticket = 0;
let searchTimer: ReturnType<typeof setTimeout> | null = null;

const crumbs = computed(() => {
  const parts = path.value.split('/').filter(Boolean);
  return parts.map((name, i) => ({ name, path: parts.slice(0, i + 1).join('/') }));
});
const hasMore = computed(() => page.value < pages.value);
const searching = computed(() => search.value.trim() !== '');

async function loadPage(reset: boolean): Promise<void> {
  if (loading.value && !reset) return;
  const mine = ++ticket;
  if (reset) {
    entries.value = [];
    page.value = 0;
    pages.value = 1;
    total.value = 0;
  }
  loading.value = true;
  error.value = null;
  try {
    const res = await api.backups.files(props.backupId, {
      path: path.value || undefined,
      search: search.value.trim() || undefined,
      page: page.value + 1,
      per_page: PER_PAGE,
    });
    if (mine !== ticket) return;
    entries.value = entries.value.concat(res.items);
    page.value += 1;
    pages.value = res.pages;
    total.value = res.total;
  } catch (e) {
    if (mine === ticket) error.value = toApiError(e);
  } finally {
    if (mine === ticket) loading.value = false;
  }
}

function go(next: string): void {
  path.value = next;
  search.value = '';
}
function open(entry: ArchiveEntry): void {
  if (entry.type === 'dir') go(entry.path);
}
function nameOf(entry: ArchiveEntry): string {
  return searching.value ? entry.path : (entry.path.split('/').pop() ?? entry.path);
}
function onVirtualScroll(details: { index: number; to: number }): void {
  if (hasMore.value && !loading.value && details.to >= entries.value.length - 10) void loadPage(false);
}

watch(path, () => void loadPage(true));
watch(search, () => {
  if (searchTimer) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => void loadPage(true), 300);
});
watch(() => props.backupId, () => {
  path.value = '';
  search.value = '';
  void loadPage(true);
});
onMounted(() => void loadPage(true));
</script>

<template>
  <div class="swbi-fb">
    <div class="swbi-fb__bar">
      <nav :aria-label="t('backups.files.breadcrumb')" class="swbi-fb__crumbs">
        <ol>
          <li>
            <button type="button" class="swbi-fb__crumb" :aria-current="crumbs.length === 0 ? 'page' : undefined" @click="go('')">
              <q-icon name="r_inventory_2" size="16px" aria-hidden="true" /> {{ t('backups.files.root') }}
            </button>
          </li>
          <li v-for="(c, i) in crumbs" :key="c.path">
            <q-icon name="r_chevron_right" size="16px" aria-hidden="true" />
            <button type="button" class="swbi-fb__crumb" :aria-current="i === crumbs.length - 1 ? 'page' : undefined" @click="go(c.path)">{{ c.name }}</button>
          </li>
        </ol>
      </nav>
      <q-input v-model="search" outlined dense clearable class="swbi-fb__search" :label="t('backups.files.search')" type="search" autocomplete="off">
        <template #prepend><q-icon name="r_search" aria-hidden="true" /></template>
      </q-input>
    </div>

    <ErrorState v-if="error && entries.length === 0" :error="error" compact @retry="loadPage(true)" />
    <div v-else-if="loading && entries.length === 0" class="swbi-fb__skeleton" aria-busy="true">
      <q-skeleton v-for="n in 6" :key="n" type="text" height="32px" />
    </div>
    <EmptyState v-else-if="entries.length === 0" compact :illustration="searching ? 'search' : 'files'" :title="searching ? t('backups.files.noResults') : t('backups.files.empty')" />
    <template v-else>
      <div class="swbi-fb__head" aria-hidden="true">
        <span>{{ t('common.name') }}</span><span class="swbi-fb__num">{{ t('backups.files.size') }}</span><span class="swbi-fb__vol">{{ t('backups.files.volume') }}</span>
      </div>
      <q-virtual-scroll class="swbi-fb__list" :items="entries" :virtual-scroll-item-size="44" separator @virtual-scroll="onVirtualScroll">
        <template #default="{ item }">
          <div :key="item.path" class="swbi-fb__row">
            <button v-if="item.type === 'dir'" type="button" class="swbi-fb__name swbi-fb__name--dir" @click="open(item)">
              <q-icon name="r_folder" size="20px" aria-hidden="true" /><span class="swbi-truncate">{{ nameOf(item) }}</span>
            </button>
            <span v-else class="swbi-fb__name">
              <q-icon name="r_description" size="20px" aria-hidden="true" /><span class="swbi-truncate" :title="item.path">{{ nameOf(item) }}</span>
            </span>
            <span class="swbi-fb__num swbi-num">{{ item.type === 'dir' ? '' : fmt.bytes(item.size) }}</span>
            <span class="swbi-fb__vol swbi-num">{{ item.volume }}</span>
          </div>
        </template>
      </q-virtual-scroll>
      <div class="swbi-fb__foot" aria-live="polite">
        <span class="swbi-muted swbi-num">{{ t('backups.files.shown', { shown: fmt.number(entries.length), total: fmt.number(total) }) }}</span>
        <q-btn v-if="hasMore" flat no-caps dense color="primary" :loading="loading" :label="t('backups.files.loadMore')" @click="loadPage(false)" />
      </div>
    </template>
  </div>
</template>

<style scoped>
.swbi-fb {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.swbi-fb__bar {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
}
.swbi-fb__search {
  flex: 1 1 220px;
  max-width: 320px;
}
.swbi-fb__crumbs ol {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 2px;
}
.swbi-fb__crumbs li {
  display: inline-flex;
  align-items: center;
}
.swbi-fb__crumb {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 8px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: var(--swbi-primary);
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}
.swbi-fb__crumb[aria-current='page'] {
  color: var(--swbi-text);
}
.swbi-fb__crumb:hover {
  background: var(--swbi-surface-3);
}
.swbi-fb__head,
.swbi-fb__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 96px 56px;
  gap: 10px;
  align-items: center;
  padding: 0 12px;
}
.swbi-fb__head {
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--swbi-muted);
}
.swbi-fb__list {
  max-height: 420px;
  border: 1px solid var(--swbi-border);
  border-radius: var(--swbi-radius-sm);
}
.swbi-fb__row {
  min-height: 44px;
  border-bottom: 1px solid var(--swbi-border);
  font-size: 14px;
}
.swbi-fb__name {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  color: inherit;
}
.swbi-fb__name--dir {
  border: 0;
  background: transparent;
  font: inherit;
  font-weight: 600;
  color: var(--swbi-primary);
  cursor: pointer;
  text-align: left;
  padding: 8px 0;
}
.swbi-fb__num {
  text-align: right;
  color: var(--swbi-muted);
}
.swbi-fb__vol {
  text-align: right;
  color: var(--swbi-muted);
}
.swbi-fb__foot {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 13px;
}
.swbi-fb__skeleton {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
</style>
