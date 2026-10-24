<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import FormSection from '@/components/ui/FormSection.vue';
import FormSheet from '@/components/ui/FormSheet.vue';
import { useFormError } from '@/composables/useFormError';
import { useNotify } from '@/composables/useNotify';
import { useLookupsStore } from '@/stores/lookups';
import type { Frequency, Schedule, ScheduleInput } from '@/types/api';

const open = defineModel<boolean>({ required: true });
const props = defineProps<{
  schedule: Schedule | null;
  readonly?: boolean;
  defaultTimezone: string;
}>();
const emit = defineEmits<{ (e: 'saved', schedule: Schedule): void }>();

const { t } = useI18n();
const notify = useNotify();
const formError = useFormError();
const lookups = useLookupsStore();
const saving = ref(false);
const form = ref<ScheduleInput>(blank());
const allTimezones =
  typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : ['UTC'];
const tzOptions = ref<string[]>(allTimezones);

function blank(): ScheduleInput {
  return {
    name: '',
    profile_id: lookups.profiles[0]?.id ?? 0,
    enabled: true,
    frequency: 'daily',
    cron_expression: '',
    timezone: props.defaultTimezone,
    time_of_day: '02:30',
    weekday: 1,
    monthday: 1,
    retry: { max_attempts: 3, backoff_seconds: 600 },
  };
}

watch(open, async (isOpen) => {
  formError.clear();
  if (!isOpen) return;
  try {
    await lookups.loadProfiles(true);
  } catch (e) {
    notify.error(e);
  }
  if (props.schedule) {
    const {
      id: _id,
      next_run_at: _n,
      last_run_at: _l,
      last_status: _s,
      last_error: _e,
      ...rest
    } = props.schedule;
    form.value = JSON.parse(JSON.stringify(rest)) as ScheduleInput;
  } else {
    form.value = blank();
  }
  tzOptions.value = allTimezones;
});

const profileOptions = computed(() =>
  lookups.profiles.map((p) => ({ value: p.id, label: p.name })),
);
const frequencies: Frequency[] = ['hourly', 'daily', 'weekly', 'monthly', 'cron'];
const needsTime = computed(() => form.value.frequency !== 'cron');
const weekdayOptions = computed(() =>
  [0, 1, 2, 3, 4, 5, 6].map((d) => ({ value: d, label: t(`schedules.weekdays.${d}`) })),
);
const dayRule = (v: number): true | string =>
  (Number.isInteger(v) && v >= 1 && v <= 31) || t('schedules.monthdayInvalid');
const cronRule = (v: string): true | string =>
  /^\s*(\S+\s+){4}\S+\s*$/.test(v) || t('schedules.cronInvalid');
const positive = (v: number): true | string =>
  (Number.isFinite(v) && v >= 1) || t('common.minValue', { n: 1 });

function filterTz(val: string, update: (fn: () => void) => void): void {
  update(() => {
    const needle = val.toLowerCase();
    tzOptions.value = allTimezones.filter((z) => z.toLowerCase().includes(needle));
  });
}

async function save(): Promise<void> {
  formError.clear();
  saving.value = true;
  try {
    const api = useApi();
    const payload: ScheduleInput = {
      ...form.value,
      cron_expression: form.value.frequency === 'cron' ? form.value.cron_expression.trim() : '',
    };
    const saved = props.schedule
      ? await api.schedules.update(props.schedule.id, payload)
      : await api.schedules.create(payload);
    notify.success(t('common.saved'));
    emit('saved', saved);
    open.value = false;
  } catch (e) {
    formError.handle(e);
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <FormSheet
    v-model="open"
    :error="formError.error.value"
    :title="schedule ? t('schedules.edit') : t('schedules.create')"
    :subtitle="schedule?.name"
    :loading="saving"
    :readonly="readonly"
    :width="640"
    @submit="save"
  >
    <fieldset class="swbi-fieldset" :disabled="readonly">
      <FormSection :title="t('schedules.sections.general')">
        <q-input
          v-model="form.name"
          outlined
          dense
          :label="t('common.name')"
          maxlength="120"
          :rules="[(v: string) => v.trim() !== '' || t('common.required')]"
          lazy-rules="ondemand"
        />
        <q-select
          v-model="form.profile_id"
          outlined
          dense
          emit-value
          map-options
          :options="profileOptions"
          :label="t('schedules.profile')"
          :rules="[(v: number) => v > 0 || t('common.required')]"
          lazy-rules="ondemand"
        />
        <q-toggle v-model="form.enabled" :label="t('schedules.enabled')" />
      </FormSection>
      <FormSection
        :title="t('schedules.sections.timing')"
        :description="t('schedules.sections.timingDesc')"
      >
        <q-select
          v-model="form.frequency"
          outlined
          dense
          emit-value
          map-options
          :options="frequencies.map((v) => ({ value: v, label: t(`schedules.frequency.${v}`) }))"
          :label="t('schedules.frequencyLabel')"
        />
        <q-input
          v-if="needsTime"
          v-model="form.time_of_day"
          outlined
          dense
          type="time"
          :label="
            form.frequency === 'hourly' ? t('schedules.hourlyMinute') : t('schedules.timeOfDay')
          "
          :hint="form.frequency === 'hourly' ? t('schedules.hourlyMinuteHint') : undefined"
          :rules="[(v: string) => /^\d{2}:\d{2}$/.test(v) || t('common.required')]"
          lazy-rules="ondemand"
        />
        <q-select
          v-if="form.frequency === 'weekly'"
          v-model="form.weekday"
          outlined
          dense
          emit-value
          map-options
          :options="weekdayOptions"
          :label="t('schedules.weekday')"
        />
        <q-input
          v-if="form.frequency === 'monthly'"
          v-model.number="form.monthday"
          outlined
          dense
          type="number"
          min="1"
          max="31"
          :label="t('schedules.monthday')"
          :hint="t('schedules.monthdayHint')"
          :rules="[dayRule]"
          lazy-rules="ondemand"
        />
        <template v-if="form.frequency === 'cron'">
          <q-input
            v-model="form.cron_expression"
            outlined
            dense
            input-class="swbi-mono"
            :label="t('schedules.cronExpression')"
            placeholder="30 2 * * *"
            autocomplete="off"
            :rules="[cronRule]"
            lazy-rules="ondemand"
          />
          <div class="swbi-cron-help" role="note">
            <p>{{ t('schedules.cronHelp') }}</p>
            <code
              >┌ {{ t('schedules.cronMinute') }} ┬ {{ t('schedules.cronHour') }} ┬
              {{ t('schedules.cronDom') }} ┬ {{ t('schedules.cronMonth') }} ┬
              {{ t('schedules.cronDow') }}</code
            >
            <ul>
              <li><code>30 2 * * *</code> {{ t('schedules.cronEx1') }}</li>
              <li><code>0 */6 * * *</code> {{ t('schedules.cronEx2') }}</li>
              <li><code>0 3 * * 0</code> {{ t('schedules.cronEx3') }}</li>
            </ul>
          </div>
        </template>
        <q-select
          v-model="form.timezone"
          outlined
          dense
          use-input
          hide-selected
          fill-input
          input-debounce="0"
          :options="tzOptions"
          :label="t('schedules.timezone')"
          :rules="[(v: string) => v.trim() !== '' || t('common.required')]"
          lazy-rules="ondemand"
          @filter="filterTz"
        />
      </FormSection>
      <FormSection
        :title="t('schedules.sections.retry')"
        :description="t('schedules.sections.retryDesc')"
      >
        <div class="swbi-two">
          <q-input
            v-model.number="form.retry.max_attempts"
            outlined
            dense
            type="number"
            min="1"
            :label="t('schedules.maxAttempts')"
            :rules="[positive]"
            lazy-rules="ondemand"
          />
          <q-input
            v-model.number="form.retry.backoff_seconds"
            outlined
            dense
            type="number"
            min="1"
            suffix="s"
            :label="t('schedules.backoff')"
            :rules="[positive]"
            lazy-rules="ondemand"
          />
        </div>
      </FormSection>
    </fieldset>
  </FormSheet>
</template>

<style scoped>
.swbi-fieldset {
  border: 0;
  margin: 0;
  padding: 0;
  min-width: 0;
}
.swbi-two {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.swbi-cron-help {
  padding: 12px 14px;
  border-radius: var(--swbi-radius-sm);
  background: var(--swbi-surface-2);
  border: 1px solid var(--swbi-border);
  font-size: 13px;
  color: var(--swbi-muted);
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.swbi-cron-help ul {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
</style>
