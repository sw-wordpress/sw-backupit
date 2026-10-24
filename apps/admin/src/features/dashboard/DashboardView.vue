<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import DataState from '@/components/ui/DataState.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusChip from '@/components/ui/StatusChip.vue';
import SwbiCard from '@/components/ui/SwbiCard.vue';
import { useAsyncData } from '@/composables/useAsyncData';
import { useCapabilities } from '@/composables/useCapabilities';
import { useFormat } from '@/composables/useFormat';
import { useRunProfile } from '@/composables/useRunProfile';
import { useJobsStore } from '@/stores/jobs';
import { useLookupsStore } from '@/stores/lookups';
import RunningJobCard from '@/features/jobs/RunningJobCard.vue';
import HealthHero from './HealthHero.vue';

const { t } = useI18n();
const fmt = useFormat();
const api = useApi();
const jobs = useJobsStore();
const lookups = useLookupsStore();
const { can } = useCapabilities();
const { busyId, runProfile } = useRunProfile();

const health = useAsyncData(() => api.health.get());
const report = computed(() => health.data.value);
const selectedProfile = ref<number | null>(null);
const profileOptions = computed(() =>
  lookups.profiles.map((p) => ({ label: p.name, value: p.id })),
);

onMounted(async () => {
  try {
    await lookups.loadProfiles(true);
    selectedProfile.value = lookups.profiles[0]?.id ?? null;
  } catch {
    /* quick actions simply stay empty; the health card shows its own error state */
  }
});
// A job finishing changes the numbers on this page.
watch(
  () => jobs.count,
  (now, before) => {
    if (now < before) void health.load();
  },
);

const cronNeedsHint = computed(
  () =>
    report.value !== null &&
    (report.value.cron.mode === 'wp-cron' || report.value.cron.wp_cron_disabled),
);
const cronWarn = computed(
  () => report.value?.cron.mode === 'wp-cron' && report.value.cron.wp_cron_disabled,
);
</script>

<template>
  <div>
    <PageHeader :title="t('nav.dashboard')" :subtitle="t('dashboard.subtitle')">
      <q-btn
        flat
        no-caps
        icon="r_refresh"
        :label="t('common.refresh')"
        :loading="health.loading.value"
        @click="health.load()"
      />
    </PageHeader>

    <DataState
      :loading="health.loading.value"
      :error="health.error.value"
      :empty="report === null"
      variant="block"
      @retry="health.load()"
    >
      <div v-if="report" class="swbi-stack">
        <div
          v-if="cronNeedsHint"
          class="swbi-banner"
          :class="{ 'swbi-banner--warn': cronWarn }"
          role="note"
        >
          <q-icon :name="cronWarn ? 'r_warning' : 'r_info'" size="22px" aria-hidden="true" />
          <div>
            <strong>{{
              cronWarn ? t('dashboard.cronDisabledTitle') : t('dashboard.cronTitle')
            }}</strong>
            <p>{{ report.cron.notice }}</p>
            <p>{{ t('dashboard.cronRecommend') }}</p>
            <p v-if="report.cron.last_tick_at" class="swbi-muted">
              {{ t('dashboard.cronLastTick', { time: fmt.relative(report.cron.last_tick_at) }) }}
            </p>
          </div>
        </div>

        <HealthHero :report="report" />

        <section v-if="jobs.running.length > 0" :aria-label="t('jobs.running')">
          <h2 class="swbi-section-title">{{ t('jobs.running') }}</h2>
          <div class="swbi-grid swbi-grid--wide">
            <RunningJobCard v-for="job in jobs.running" :key="job.id" :job="job" />
          </div>
        </section>

        <div class="swbi-grid swbi-grid--stats">
          <StatTile
            :label="t('dashboard.lastSuccess')"
            icon="r_check_circle"
            tone="success"
            :value="
              report.last_success
                ? fmt.relative(report.last_success.finished_at)
                : t('common.never')
            "
          >
            <template #hint>
              <router-link
                v-if="report.last_success"
                :to="{ name: 'backups', params: { id: report.last_success.backup_id } }"
                >{{ fmt.dateTime(report.last_success.finished_at) }}</router-link
              >
              <span v-else>{{ t('dashboard.noSuccessYet') }}</span>
            </template>
          </StatTile>
          <StatTile
            :label="t('dashboard.lastFailure')"
            icon="r_error"
            :tone="report.last_failure ? 'danger' : 'default'"
            :value="
              report.last_failure ? fmt.relative(report.last_failure.finished_at) : t('common.none')
            "
          >
            <template #hint>
              <router-link
                v-if="report.last_failure"
                :to="{ name: 'job', params: { id: report.last_failure.job_id } }"
                >{{ report.last_failure.error }}</router-link
              >
              <span v-else>{{ t('dashboard.noFailures') }}</span>
            </template>
          </StatTile>
          <StatTile
            :label="t('dashboard.nextRun')"
            icon="r_event"
            :value="
              report.next_run ? fmt.relative(report.next_run.at) : t('dashboard.notScheduled')
            "
          >
            <template #hint>
              <span v-if="report.next_run"
                >{{ report.next_run.schedule_name }} · {{ fmt.dateTime(report.next_run.at) }}</span
              >
              <router-link v-else to="/schedules">{{ t('dashboard.createSchedule') }}</router-link>
            </template>
          </StatTile>
          <StatTile
            :label="t('dashboard.backupCount')"
            icon="r_inventory_2"
            :value="fmt.number(report.backup_count)"
          >
            <template #hint
              ><router-link to="/backups">{{ t('dashboard.showBackups') }}</router-link></template
            >
          </StatTile>
          <StatTile
            :label="t('dashboard.storageUsed')"
            icon="r_storage"
            :value="fmt.bytes(report.storage_bytes)"
            :hint="
              t(
                'dashboard.acrossTargets',
                { n: report.storage_targets.length },
                report.storage_targets.length,
              )
            "
          />
        </div>

        <div class="swbi-grid swbi-grid--wide">
          <SwbiCard :title="t('dashboard.storageHealth')" icon="r_cloud_done">
            <EmptyState
              v-if="report.storage_targets.length === 0"
              compact
              illustration="storage"
              :title="t('storage.emptyTitle')"
              :text="t('storage.emptyText')"
            >
              <q-btn
                v-if="can('swbi_manage_storage')"
                to="/storage"
                unelevated
                no-caps
                color="primary"
                :label="t('storage.add')"
              />
            </EmptyState>
            <ul v-else class="swbi-targets">
              <li v-for="target in report.storage_targets" :key="target.id">
                <span class="swbi-targets__name"
                  ><q-icon name="r_dns" size="18px" aria-hidden="true" /> {{ target.name }}
                  <span class="swbi-muted">({{ t(`storage.types.${target.type}`) }})</span></span
                >
                <StatusChip domain="health" :status="target.status" dense />
                <span v-if="target.message" class="swbi-targets__msg">{{ target.message }}</span>
              </li>
            </ul>
          </SwbiCard>

          <SwbiCard :title="t('dashboard.warnings')" icon="r_notifications">
            <p v-if="report.warnings.length === 0" class="swbi-muted swbi-ok">
              <q-icon name="r_check_circle" size="18px" aria-hidden="true" />
              {{ t('dashboard.noWarnings') }}
            </p>
            <ul v-else class="swbi-warnings">
              <li v-for="(w, i) in report.warnings" :key="i">
                <q-icon name="r_warning" size="18px" aria-hidden="true" /><span>{{ w }}</span>
              </li>
            </ul>
          </SwbiCard>

          <SwbiCard :title="t('dashboard.quickActions')" icon="r_bolt">
            <div v-if="can('swbi_run')" class="swbi-stack">
              <q-select
                v-model="selectedProfile"
                outlined
                dense
                emit-value
                map-options
                :options="profileOptions"
                :label="t('dashboard.profileToRun')"
                :disable="profileOptions.length === 0"
              />
              <q-btn
                unelevated
                no-caps
                color="primary"
                icon="r_play_arrow"
                :label="t('profiles.runNow')"
                :disable="selectedProfile === null"
                :loading="busyId !== null && busyId === selectedProfile"
                @click="selectedProfile !== null && runProfile(selectedProfile)"
              />
              <router-link v-if="profileOptions.length === 0" to="/profiles">{{
                t('profiles.create')
              }}</router-link>
            </div>
            <p v-else class="swbi-muted">{{ t('common.noPermission') }}</p>
          </SwbiCard>
        </div>
      </div>
    </DataState>
  </div>
</template>

<style scoped>
.swbi-grid--stats {
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 185px), 1fr));
}
.swbi-section-title {
  font-size: 16px;
  font-weight: 650;
  margin-bottom: 12px;
}
.swbi-banner {
  display: flex;
  gap: 14px;
  align-items: flex-start;
  padding: 14px 18px;
  border-radius: var(--swbi-radius);
  background: var(--swbi-info-bg);
  color: var(--swbi-info-text);
  font-size: 14px;
}
.swbi-banner--warn {
  background: var(--swbi-warning-bg);
  color: var(--swbi-warning-text);
}
.swbi-banner p {
  margin-top: 2px;
}
.swbi-targets {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.swbi-targets li {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 4px 10px;
  align-items: center;
}
.swbi-targets__name {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
  min-width: 0;
}
.swbi-targets__msg {
  grid-column: 1 / -1;
  font-size: 13px;
  color: var(--swbi-muted);
}
.swbi-warnings {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.swbi-warnings li {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  color: var(--swbi-warning-text);
}
.swbi-ok {
  display: flex;
  align-items: center;
  gap: 8px;
}
</style>
