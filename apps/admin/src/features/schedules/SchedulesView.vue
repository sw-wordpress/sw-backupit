<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
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
import { useRunProfile } from '@/composables/useRunProfile';
import { useLookupsStore } from '@/stores/lookups';
import type { Schedule, ScheduleInput } from '@/types/api';
import ScheduleSheet from './ScheduleSheet.vue';

const { t } = useI18n();
const fmt = useFormat();
const api = useApi();
const notify = useNotify();
const lookups = useLookupsStore();
const { can } = useCapabilities();
const { busyId, runSchedule } = useRunProfile();

const page = ref(1);
const list = useAsyncData(() => api.schedules.list({ page: page.value, per_page: 20 }));
watch(page, () => void list.load());
const items = computed(() => list.data.value?.items ?? []);
const canManage = computed(() => can('swbi_manage_profiles'));
const defaultTimezone = ref(Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC');

onMounted(async () => {
  try {
    await lookups.loadProfiles(true);
    const settings = await api.settings.get();
    defaultTimezone.value = settings.cron.timezone || defaultTimezone.value;
  } catch {
    /* profile names fall back to ids; timezone falls back to the browser's */
  }
});

const profileName = (id: number): string =>
  lookups.profiles.find((p) => p.id === id)?.name ?? `#${id}`;

const sheetOpen = ref(false);
const editing = ref<Schedule | null>(null);
const toDelete = ref<Schedule | null>(null);
const deleteOpen = ref(false);
const deleting = ref(false);
const togglingId = ref<number | null>(null);

function openCreate(): void {
  editing.value = null;
  sheetOpen.value = true;
}
function openEdit(s: Schedule): void {
  editing.value = s;
  sheetOpen.value = true;
}
function describe(s: Schedule): string {
  if (s.frequency === 'cron') return s.cron_expression;
  if (s.frequency === 'hourly') return t('schedules.frequency.hourly');
  return t(`schedules.every.${s.frequency}`, { time: s.time_of_day });
}
async function toggle(s: Schedule, enabled: boolean): Promise<void> {
  togglingId.value = s.id;
  try {
    const {
      id: _id,
      next_run_at: _n,
      last_run_at: _l,
      last_status: _s,
      last_error: _e,
      ...input
    } = s;
    await api.schedules.update(s.id, { ...(input as ScheduleInput), enabled });
    notify.success(enabled ? t('schedules.enabledMsg') : t('schedules.disabledMsg'));
    await list.load();
  } catch (e) {
    notify.error(e);
    await list.load();
  } finally {
    togglingId.value = null;
  }
}
function askDelete(s: Schedule): void {
  toDelete.value = s;
  deleteOpen.value = true;
}
async function doDelete(): Promise<void> {
  if (!toDelete.value) return;
  deleting.value = true;
  try {
    await api.schedules.remove(toDelete.value.id);
    notify.success(t('schedules.deleted'));
    deleteOpen.value = false;
    await list.load();
  } catch (e) {
    notify.error(e);
  } finally {
    deleting.value = false;
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('nav.schedules')" :subtitle="t('schedules.subtitle')">
      <q-btn
        v-if="canManage"
        unelevated
        no-caps
        color="primary"
        icon="r_add"
        :label="t('schedules.create')"
        @click="openCreate"
      />
    </PageHeader>

    <SwbiCard :padding="false">
      <DataState
        :loading="list.loading.value"
        :error="list.error.value"
        :empty="items.length === 0"
        @retry="list.load()"
      >
        <template #empty>
          <EmptyState
            illustration="schedules"
            :title="t('schedules.emptyTitle')"
            :text="t('schedules.emptyText')"
          >
            <q-btn
              v-if="canManage"
              unelevated
              no-caps
              color="primary"
              icon="r_add"
              :label="t('schedules.create')"
              @click="openCreate"
            />
          </EmptyState>
        </template>
        <table class="swbi-table">
          <thead>
            <tr>
              <th scope="col">{{ t('common.name') }}</th>
              <th scope="col">{{ t('schedules.enabled') }}</th>
              <th scope="col">{{ t('schedules.when') }}</th>
              <th scope="col">{{ t('schedules.nextRun') }}</th>
              <th scope="col">{{ t('schedules.lastRun') }}</th>
              <th scope="col">
                <span class="swbi-sr-only">{{ t('common.actions') }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in items" :key="s.id">
              <td :data-label="t('common.name')">
                <div class="swbi-cell-main">
                  <strong>{{ s.name }}</strong>
                  <span class="swbi-muted">{{ profileName(s.profile_id) }}</span>
                </div>
              </td>
              <td :data-label="t('schedules.enabled')">
                <q-toggle
                  :model-value="s.enabled"
                  :disable="!canManage || togglingId === s.id"
                  :aria-label="t('schedules.toggleNamed', { name: s.name })"
                  @update:model-value="toggle(s, $event)"
                />
              </td>
              <td :data-label="t('schedules.when')">
                <div class="swbi-cell-main">
                  <span :class="{ 'swbi-mono': s.frequency === 'cron' }">{{ describe(s) }}</span>
                  <span class="swbi-muted">{{ s.timezone }}</span>
                </div>
              </td>
              <td :data-label="t('schedules.nextRun')">
                <div class="swbi-cell-main">
                  <template v-if="s.enabled && s.next_run_at">
                    <span>{{ fmt.relative(s.next_run_at) }}</span>
                    <span class="swbi-muted">{{ fmt.dateTime(s.next_run_at) }}</span>
                  </template>
                  <span v-else class="swbi-muted">{{
                    s.enabled ? '–' : t('schedules.paused')
                  }}</span>
                </div>
              </td>
              <td :data-label="t('schedules.lastRun')">
                <div class="swbi-cell-main">
                  <StatusChip domain="schedule" :status="s.last_status ?? 'never'" dense />
                  <span v-if="s.last_run_at" class="swbi-muted">{{
                    fmt.relative(s.last_run_at)
                  }}</span>
                  <span v-if="s.last_error" class="swbi-cell-error">{{ s.last_error }}</span>
                </div>
              </td>
              <td data-label="">
                <div class="swbi-actions">
                  <q-btn
                    v-if="can('swbi_run')"
                    flat
                    round
                    dense
                    icon="r_play_arrow"
                    :loading="busyId === s.id"
                    :aria-label="t('schedules.runNamed', { name: s.name })"
                    @click="runSchedule(s.id)"
                    ><q-tooltip>{{ t('profiles.runNow') }}</q-tooltip></q-btn
                  >
                  <q-btn
                    flat
                    round
                    dense
                    :icon="canManage ? 'r_edit' : 'r_visibility'"
                    :aria-label="t('profiles.editNamed', { name: s.name })"
                    @click="openEdit(s)"
                    ><q-tooltip>{{
                      canManage ? t('common.edit') : t('common.details')
                    }}</q-tooltip></q-btn
                  >
                  <q-btn
                    v-if="canManage"
                    flat
                    round
                    dense
                    icon="r_delete"
                    color="negative"
                    :aria-label="t('profiles.deleteNamed', { name: s.name })"
                    @click="askDelete(s)"
                    ><q-tooltip>{{ t('common.delete') }}</q-tooltip></q-btn
                  >
                </div>
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

    <ScheduleSheet
      v-model="sheetOpen"
      :schedule="editing"
      :readonly="!canManage"
      :default-timezone="defaultTimezone"
      @saved="list.load()"
    />
    <ConfirmDialog
      v-model="deleteOpen"
      :title="t('schedules.deleteTitle')"
      :target="toDelete?.name"
      :consequences="[t('schedules.deleteConsequence'), t('common.irreversible')]"
      :confirm-label="t('common.delete')"
      :loading="deleting"
      @confirm="doDelete"
    />
  </div>
</template>

<style scoped>
.swbi-cell-main {
  display: flex;
  flex-direction: column;
  gap: 2px;
  align-items: flex-start;
}
.swbi-cell-main .swbi-muted {
  font-size: 13px;
}
.swbi-cell-error {
  font-size: 12px;
  color: var(--swbi-danger-text);
  max-width: 280px;
}
.swbi-actions {
  display: flex;
  justify-content: flex-end;
  gap: 2px;
}
@media (max-width: 760px) {
  .swbi-cell-main {
    align-items: flex-end;
  }
}
</style>
