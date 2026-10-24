<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
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
import { useRunProfile } from '@/composables/useRunProfile';
import { useLookupsStore } from '@/stores/lookups';
import type { Profile } from '@/types/api';
import { ADVANCED_TYPES } from './defaults';

const { t } = useI18n();
const fmt = useFormat();
const api = useApi();
const notify = useNotify();
const lookups = useLookupsStore();
const { can } = useCapabilities();
const { busyId, runProfile } = useRunProfile();

const page = ref(1);
const list = useAsyncData(() => api.profiles.list({ page: page.value, per_page: 12 }));
watch(page, () => void list.load());

const items = computed(() => list.data.value?.items ?? []);
const toDelete = ref<Profile | null>(null);
const deleteOpen = ref(false);
const deleting = ref(false);
const duplicatingId = ref<number | null>(null);
const canManage = computed(() => can('swbi_manage_profiles'));

const router = useRouter();
function openCreate(): void {
  void router.push({ name: 'profile-new' });
}
function openEdit(p: Profile): void {
  void router.push({ name: 'profile-edit', params: { id: p.id } });
}
function askDelete(p: Profile): void {
  toDelete.value = p;
  deleteOpen.value = true;
}
async function doDelete(): Promise<void> {
  if (!toDelete.value) return;
  deleting.value = true;
  try {
    await api.profiles.remove(toDelete.value.id);
    notify.success(t('profiles.deleted'));
    deleteOpen.value = false;
    lookups.invalidate();
    await list.load();
  } catch (e) {
    notify.error(e);
  } finally {
    deleting.value = false;
  }
}
async function duplicate(p: Profile): Promise<void> {
  duplicatingId.value = p.id;
  try {
    await api.profiles.duplicate(p.id);
    notify.success(t('profiles.duplicated'));
    lookups.invalidate();
    await list.load();
  } catch (e) {
    notify.error(e);
  } finally {
    duplicatingId.value = null;
  }
}
function retentionSummary(p: Profile): string {
  const parts: string[] = [];
  if (p.retention.max_count > 0)
    parts.push(t('profiles.keepCount', { n: p.retention.max_count }, p.retention.max_count));
  if (p.retention.max_age_days > 0)
    parts.push(t('profiles.keepDays', { n: p.retention.max_age_days }, p.retention.max_age_days));
  const g = p.retention.gfs;
  if (g.daily + g.weekly + g.monthly + g.yearly > 0)
    parts.push(t('profiles.gfsShort', { d: g.daily, w: g.weekly, m: g.monthly, y: g.yearly }));
  return parts.length > 0 ? parts.join(' · ') : t('profiles.keepAll');
}
</script>

<template>
  <div>
    <PageHeader :title="t('nav.profiles')" :subtitle="t('profiles.subtitle')">
      <q-btn
        v-if="canManage"
        unelevated
        no-caps
        color="primary"
        icon="r_add"
        :label="t('profiles.create')"
        @click="openCreate"
      />
    </PageHeader>

    <DataState
      :loading="list.loading.value"
      :error="list.error.value"
      :empty="items.length === 0"
      variant="cards"
      @retry="list.load()"
    >
      <template #empty>
        <SwbiCard>
          <EmptyState
            illustration="profiles"
            :title="t('profiles.emptyTitle')"
            :text="t('profiles.emptyText')"
          >
            <q-btn
              v-if="canManage"
              unelevated
              no-caps
              color="primary"
              icon="r_add"
              :label="t('profiles.create')"
              @click="openCreate"
            />
          </EmptyState>
        </SwbiCard>
      </template>

      <ul class="swbi-grid swbi-grid--wide swbi-plist">
        <li v-for="p in items" :key="p.id">
          <SwbiCard tag="article" :aria-label="p.name" interactive accent class="swbi-pcard">
            <div class="swbi-pcard__head">
              <h2 class="swbi-pcard__name swbi-truncate">{{ p.name }}</h2>
              <StatusChip kind="primary" icon="r_inventory_2" :label="t(`types.${p.type}`)" dense />
            </div>
            <p v-if="p.description" class="swbi-pcard__desc">{{ p.description }}</p>
            <p
              v-if="(ADVANCED_TYPES as readonly string[]).includes(p.type)"
              class="swbi-pcard__adv"
            >
              <q-icon name="r_info" size="16px" aria-hidden="true" />
              {{ t('profiles.advancedChip') }}
            </p>
            <dl class="swbi-pcard__facts">
              <div>
                <dt>{{ t('profiles.facts.targets') }}</dt>
                <dd>
                  {{
                    t(
                      'profiles.targetCount',
                      { n: p.storage_target_ids.length },
                      p.storage_target_ids.length,
                    )
                  }}
                </dd>
              </div>
              <div>
                <dt>{{ t('profiles.facts.retention') }}</dt>
                <dd>{{ retentionSummary(p) }}</dd>
              </div>
              <div>
                <dt>{{ t('profiles.facts.security') }}</dt>
                <dd>
                  <q-icon
                    :name="p.encryption.enabled ? 'r_lock' : 'r_lock_open'"
                    size="16px"
                    aria-hidden="true"
                  />
                  {{ p.encryption.enabled ? t('profiles.encrypted') : t('profiles.notEncrypted') }}
                  ·
                  {{
                    p.compression.method === 'store'
                      ? t('profiles.methodStoreShort')
                      : t('profiles.levelShort', { n: p.compression.level })
                  }}
                </dd>
              </div>
              <div>
                <dt>{{ t('common.updated') }}</dt>
                <dd>{{ fmt.relative(p.updated_at) }}</dd>
              </div>
            </dl>
            <template #footer>
              <div class="swbi-pcard__actions">
                <q-btn
                  v-if="can('swbi_run')"
                  unelevated
                  no-caps
                  dense
                  color="primary"
                  icon="r_play_arrow"
                  :label="t('profiles.runNow')"
                  :loading="busyId === p.id"
                  class="swbi-pcard__run"
                  @click="runProfile(p.id)"
                />
                <span class="swbi-pcard__spacer" />
                <template v-if="canManage">
                  <q-btn
                    flat
                    round
                    dense
                    icon="r_edit"
                    :aria-label="t('profiles.editNamed', { name: p.name })"
                    @click="openEdit(p)"
                    ><q-tooltip>{{ t('common.edit') }}</q-tooltip></q-btn
                  >
                  <q-btn
                    flat
                    round
                    dense
                    icon="r_content_copy"
                    :loading="duplicatingId === p.id"
                    :aria-label="t('profiles.duplicateNamed', { name: p.name })"
                    @click="duplicate(p)"
                    ><q-tooltip>{{ t('common.duplicate') }}</q-tooltip></q-btn
                  >
                  <q-btn
                    flat
                    round
                    dense
                    icon="r_delete"
                    color="negative"
                    :aria-label="t('profiles.deleteNamed', { name: p.name })"
                    @click="askDelete(p)"
                    ><q-tooltip>{{ t('common.delete') }}</q-tooltip></q-btn
                  >
                </template>
                <q-btn
                  v-else
                  flat
                  round
                  dense
                  icon="r_visibility"
                  :aria-label="t('common.details')"
                  @click="openEdit(p)"
                />
              </div>
            </template>
          </SwbiCard>
        </li>
      </ul>
      <PaginationBar
        v-if="list.data.value"
        v-model:page="page"
        :pages="list.data.value.pages"
        :total="list.data.value.total"
      />
    </DataState>

    <ConfirmDialog
      v-model="deleteOpen"
      :title="t('profiles.deleteTitle')"
      :target="toDelete?.name"
      :consequences="[t('profiles.deleteConsequence1'), t('common.irreversible')]"
      :confirm-label="t('common.delete')"
      :loading="deleting"
      @confirm="doDelete"
    />
  </div>
</template>

<style scoped>
.swbi-plist {
  list-style: none;
}
.swbi-pcard__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}
.swbi-pcard__name {
  font-size: 17px;
  font-weight: 700;
}
.swbi-pcard__desc {
  margin-top: 6px;
  color: var(--swbi-muted);
  font-size: 14px;
}
.swbi-pcard__adv {
  display: flex;
  gap: 6px;
  align-items: center;
  margin-top: 8px;
  font-size: 13px;
  color: var(--swbi-info-text);
}
.swbi-pcard__facts {
  display: grid;
  gap: 8px;
  margin-top: 14px;
  font-size: 14px;
}
.swbi-pcard__facts > div {
  display: grid;
  grid-template-columns: 96px minmax(0, 1fr);
  gap: 8px;
}
.swbi-pcard__facts dt {
  color: var(--swbi-muted);
}
.swbi-pcard__facts dd {
  font-variant-numeric: tabular-nums;
}
.swbi-pcard__actions {
  display: flex;
  align-items: center;
  gap: 2px;
}
.swbi-pcard__spacer {
  flex: 1;
}
</style>
