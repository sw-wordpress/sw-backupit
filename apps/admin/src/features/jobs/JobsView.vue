<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import DataState from '@/components/ui/DataState.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import StatusChip from '@/components/ui/StatusChip.vue';
import SwbiCard from '@/components/ui/SwbiCard.vue';
import { useAsyncData } from '@/composables/useAsyncData';
import { useFormat } from '@/composables/useFormat';
import { useJobsStore } from '@/stores/jobs';
import { jobErrorText } from '@/utils/jobs';
import type { JobFilter } from '@/types/api';
import RunningJobCard from './RunningJobCard.vue';

const { t } = useI18n();
const fmt = useFormat();
const api = useApi();
const jobsStore = useJobsStore();

const filter = ref<JobFilter>('finished');
const page = ref(1);
const history = useAsyncData(() =>
  api.jobs.list({ status: filter.value, page: page.value, per_page: 15 }),
);
watch(filter, () => {
  page.value = 1;
  void history.load();
});
watch(page, () => void history.load());
// When a running job disappears from the running list it moved to history.
watch(
  () => jobsStore.count,
  (now, before) => {
    if (now < before) void history.load();
  },
);

const items = computed(() => history.data.value?.items ?? []);
const filterOptions = computed(() =>
  (['finished', 'completed', 'failed', 'cancelled'] as const).map((v) => ({
    value: v,
    label: t(`jobs.filter.${v}`),
  })),
);
const typeLabel = (type: string): string =>
  ['full', 'files', 'database', 'incremental', 'differential'].includes(type)
    ? t(`types.${type}`)
    : type;
</script>

<template>
  <div class="swbi-stack">
    <PageHeader :title="t('nav.jobs')" :subtitle="t('jobs.subtitle')">
      <q-btn
        flat
        no-caps
        icon="r_refresh"
        :label="t('common.refresh')"
        @click="
          jobsStore.refresh();
          history.load();
        "
      />
    </PageHeader>

    <section :aria-label="t('jobs.running')">
      <h2 class="swbi-h2">{{ t('jobs.running') }}</h2>
      <div v-if="!jobsStore.loaded" aria-busy="true"><q-skeleton height="110px" /></div>
      <div v-else-if="jobsStore.running.length > 0" class="swbi-grid swbi-grid--wide">
        <RunningJobCard v-for="job in jobsStore.running" :key="job.id" :job="job" />
      </div>
      <SwbiCard v-else
        ><EmptyState
          compact
          illustration="jobs"
          :title="t('jobs.noneRunning')"
          :text="t('jobs.noneRunningText')"
      /></SwbiCard>
    </section>

    <section :aria-label="t('jobs.history')">
      <div class="swbi-jv__head">
        <h2 class="swbi-h2">{{ t('jobs.history') }}</h2>
        <q-select
          v-model="filter"
          outlined
          dense
          emit-value
          map-options
          :options="filterOptions"
          :label="t('common.status')"
          class="swbi-jv__filter"
        />
      </div>
      <SwbiCard :padding="false">
        <DataState
          :loading="history.loading.value"
          :error="history.error.value"
          :empty="items.length === 0"
          @retry="history.load()"
        >
          <template #empty
            ><EmptyState
              illustration="jobs"
              :title="t('jobs.emptyTitle')"
              :text="t('jobs.emptyText')"
          /></template>
          <table class="swbi-table">
            <thead>
              <tr>
                <th scope="col">{{ t('jobs.col.started') }}</th>
                <th scope="col">{{ t('jobs.col.profile') }}</th>
                <th scope="col">{{ t('common.type') }}</th>
                <th scope="col">{{ t('common.status') }}</th>
                <th scope="col">{{ t('jobs.col.duration') }}</th>
                <th scope="col">{{ t('jobs.col.result') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="j in items" :key="j.id">
                <td :data-label="t('jobs.col.started')">
                  <router-link :to="{ name: 'job', params: { id: j.id } }" class="swbi-jv__link">
                    <strong>{{ fmt.relative(j.started_at) }}</strong>
                    <span class="swbi-muted">{{ fmt.dateTime(j.started_at) }}</span>
                  </router-link>
                </td>
                <td :data-label="t('jobs.col.profile')">{{ j.profile_name }}</td>
                <td :data-label="t('common.type')">{{ typeLabel(j.type) }}</td>
                <td :data-label="t('common.status')">
                  <StatusChip domain="job" :status="j.status" dense />
                </td>
                <td class="swbi-num" :data-label="t('jobs.col.duration')">
                  {{ fmt.duration(j.progress.elapsed_seconds) }}
                </td>
                <td :data-label="t('jobs.col.result')">
                  <router-link
                    v-if="j.backup_id"
                    :to="{ name: 'backups', params: { id: j.backup_id } }"
                    >{{ t('jobs.openBackup') }}</router-link
                  >
                  <span v-else-if="j.error" class="swbi-jv__err" :title="jobErrorText(j.error) ?? ''">{{
                    jobErrorText(j.error)
                  }}</span>
                  <span v-else class="swbi-muted">–</span>
                </td>
              </tr>
            </tbody>
          </table>
          <PaginationBar
            v-if="history.data.value"
            v-model:page="page"
            :pages="history.data.value.pages"
            :total="history.data.value.total"
          />
        </DataState>
      </SwbiCard>
    </section>
  </div>
</template>

<style scoped>
.swbi-h2 {
  font-size: 17px;
  font-weight: 650;
  margin-bottom: 12px;
}
.swbi-jv__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.swbi-jv__head .swbi-h2 {
  margin: 0;
}
.swbi-jv__filter {
  min-width: 180px;
}
.swbi-jv__link {
  display: flex;
  flex-direction: column;
  color: var(--swbi-text);
  text-decoration: none !important;
}
.swbi-jv__link .swbi-muted {
  font-size: 13px;
}
.swbi-jv__err {
  display: inline-block;
  max-width: 320px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--swbi-danger-text);
}
@media (max-width: 760px) {
  .swbi-jv__link {
    align-items: flex-end;
  }
}
</style>
