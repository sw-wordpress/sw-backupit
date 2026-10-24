<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { onBeforeRouteLeave, useRouter } from 'vue-router';
import { useApi } from '@/api';
import ErrorState from '@/components/ui/ErrorState.vue';
import { useAsyncData } from '@/composables/useAsyncData';
import { useCapabilities } from '@/composables/useCapabilities';
import { useFormError } from '@/composables/useFormError';
import { useNotify } from '@/composables/useNotify';
import NoAccess from '@/layouts/NoAccess.vue';
import { useAppStore } from '@/stores/app';
import { useLookupsStore } from '@/stores/lookups';
import type { Profile, ProfileInput, StorageTarget } from '@/types/api';
import { defaultProfileInput, profileToInput } from './defaults';
import ProfileContextPanel from './ProfileContextPanel.vue';
import ProfileStepper from './ProfileStepper.vue';
import type { StepView } from './ProfileStepper.vue';
import StepCompression from './steps/StepCompression.vue';
import StepContent from './steps/StepContent.vue';
import StepGeneral from './steps/StepGeneral.vue';
import StepRetention from './steps/StepRetention.vue';
import StepStorage from './steps/StepStorage.vue';
import StepSummary from './steps/StepSummary.vue';
import { firstStepWithIssues, hasIssues, STEP_IDS, validateProfile } from './wizard';

const props = defineProps<{ id?: string }>();
const { t } = useI18n();
const api = useApi();
const router = useRouter();
const notify = useNotify();
const app = useAppStore();
const lookups = useLookupsStore();
const { can } = useCapabilities();
const formError = useFormError();

const profileId = computed(() => (props.id !== undefined ? Number(props.id) : null));
const isNew = computed(() => profileId.value === null);
const canManage = computed(() => can('swbi_manage_profiles'));
const readonly = computed(() => !isNew.value && !canManage.value);

const loaded = useAsyncData<Profile | null>(
  () => (profileId.value === null ? Promise.resolve(null) : api.profiles.get(profileId.value)),
  { immediate: true },
);
const form = ref<ProfileInput>(defaultProfileInput());
const original = ref(JSON.stringify(form.value));
const ready = computed(() => isNew.value || loaded.data.value !== null);

watch(
  () => loaded.data.value,
  (profile) => {
    if (profile) {
      form.value = profileToInput(profile);
      original.value = JSON.stringify(form.value);
      maxReached.value = STEP_IDS.length - 1;
    }
  },
  { immediate: true },
);

const targets = ref<StorageTarget[]>([]);
const targetsLoading = ref(true);
const keyIds = ref<string[]>([]);
const keysLoading = ref(true);
const fullBackups = ref<number | null>(null);

onMounted(() => {
  lookups
    .loadTargets(true)
    .then((list) => {
      targets.value = list;
    })
    .catch((e: unknown) => notify.error(e))
    .finally(() => {
      targetsLoading.value = false;
    });
  api.keys
    .list()
    .then((res) => {
      keyIds.value = res.items.map((k) => k.id);
    })
    .catch(() => {
      /* keys need another capability; the select then only offers the current value */
    })
    .finally(() => {
      keysLoading.value = false;
    });
  api.backups
    .list({ type: 'full', per_page: 1 })
    .then((res) => {
      fullBackups.value = res.total;
    })
    .catch(() => {
      fullBackups.value = null;
    });
});

// ---- wizard state ----
const current = ref(0);
const maxReached = ref(0);
const attempted = ref<Set<number>>(new Set());
const issues = computed(() => validateProfile(form.value));
const stepId = computed(() => STEP_IDS[current.value] ?? 'general');
const isLast = computed(() => current.value === STEP_IDS.length - 1);
const dirty = computed(() => JSON.stringify(form.value) !== original.value);
const saving = ref(false);
const saved = ref(false);
const heading = ref<HTMLElement | null>(null);

const stepViews = computed<StepView[]>(() =>
  STEP_IDS.map((id, i) => ({
    id,
    state: i === current.value ? 'current' : i < current.value ? 'done' : 'todo',
    reachable: readonly.value || i <= maxReached.value,
    hasIssues:
      (attempted.value.has(i) || current.value === STEP_IDS.length - 1) &&
      issues.value[id].length > 0,
  })),
);
const showIssues = computed(() => attempted.value.has(current.value) || isLast.value);
const stepIssueCount = computed(() => issues.value[stepId.value].length);
const totalIssueCount = computed(() => STEP_IDS.reduce((n, id) => n + issues.value[id].length, 0));

const ICONS: Record<(typeof STEP_IDS)[number], string> = {
  general: 'r_badge',
  content: 'r_folder_open',
  compression: 'r_lock',
  storage: 'r_cloud',
  retention: 'r_auto_delete',
  summary: 'r_flag',
};

async function goTo(index: number): Promise<void> {
  current.value = index;
  await nextTick();
  heading.value?.focus({ preventScroll: false });
  app.announce(
    t('profiles.wizard.announceStep', {
      n: index + 1,
      total: STEP_IDS.length,
      title: t(`profiles.wizard.steps.${STEP_IDS[index] ?? 'general'}.title`),
    }),
  );
}

function next(): void {
  if (!readonly.value && stepIssueCount.value > 0) {
    attempted.value = new Set(attempted.value).add(current.value);
    return;
  }
  maxReached.value = Math.max(maxReached.value, current.value + 1);
  void goTo(current.value + 1);
}
function back(): void {
  if (current.value > 0) void goTo(current.value - 1);
}
function jump(index: number): void {
  if (readonly.value || index <= maxReached.value) void goTo(index);
}

async function save(): Promise<void> {
  formError.clear();
  const problems = issues.value;
  if (hasIssues(problems)) {
    attempted.value = new Set(STEP_IDS.map((_, i) => i));
    const first = firstStepWithIssues(problems);
    maxReached.value = STEP_IDS.length - 1;
    if (first !== null && first !== stepId.value && !isLast.value)
      await goTo(STEP_IDS.indexOf(first));
    else if (!isLast.value) await goTo(STEP_IDS.length - 1);
    return;
  }
  saving.value = true;
  try {
    const result =
      profileId.value === null
        ? await api.profiles.create(form.value)
        : await api.profiles.update(profileId.value, form.value);
    saved.value = true;
    lookups.invalidate();
    notify.success(t('profiles.wizard.savedWithId', { id: result.id }));
    await router.push({ name: 'profiles' });
  } catch (e) {
    formError.handle(e);
    saving.value = false;
  }
}

function cancel(): void {
  void router.push({ name: 'profiles' });
}

onBeforeRouteLeave(() => {
  if (saved.value || !dirty.value || readonly.value) return true;
  return window.confirm(t('settings.unsaved'));
});
</script>

<template>
  <NoAccess v-if="isNew && !canManage" />
  <ErrorState
    v-else-if="loaded.error.value && !ready"
    :error="loaded.error.value"
    @retry="loaded.load()"
  />
  <div v-else-if="!ready" aria-busy="true" class="swbi-stack">
    <q-skeleton height="90px" /><q-skeleton height="70px" /><q-skeleton height="420px" />
  </div>

  <div v-else class="swbi-pe">
    <header class="swbi-pe__head">
      <div class="swbi-pe__titles">
        <nav :aria-label="t('profiles.wizard.breadcrumb')" class="swbi-pe__crumbs">
          <router-link :to="{ name: 'profiles' }">{{ t('nav.profiles') }}</router-link>
          <q-icon name="r_chevron_right" size="14px" aria-hidden="true" />
          <span aria-current="page">{{
            isNew ? t('profiles.wizard.newCrumb') : form.name || `#${profileId}`
          }}</span>
        </nav>
        <div class="swbi-pe__titlerow">
          <h1 class="swbi-pe__h1">{{ isNew ? t('profiles.create') : t('profiles.edit') }}</h1>
          <span v-if="isNew || dirty" class="swbi-pe__badge" role="status">
            <i aria-hidden="true" />{{
              isNew ? t('profiles.wizard.badgeNew') : t('profiles.wizard.badgeDirty')
            }}
          </span>
        </div>
        <p class="swbi-pe__intro">{{ t('profiles.subtitle') }}</p>
      </div>
      <div class="swbi-pe__actions">
        <q-btn flat no-caps :label="t('common.cancel')" @click="cancel" />
        <q-btn
          v-if="!readonly"
          unelevated
          no-caps
          color="primary"
          icon="r_check"
          :label="t('profiles.wizard.save')"
          :loading="saving"
          @click="save"
        />
      </div>
    </header>

    <div v-if="formError.error.value" class="swbi-pe__error" role="alert">
      <q-icon name="r_error" size="20px" aria-hidden="true" />
      <div>
        <strong>{{ formError.error.value.message }}</strong>
        <ul v-if="Object.keys(formError.error.value.params).length > 0">
          <li v-for="(msg, field) in formError.error.value.params" :key="field">
            <code>{{ field }}</code> {{ msg }}
          </li>
        </ul>
        <span class="swbi-mono">{{ formError.error.value.code }}</span>
      </div>
    </div>

    <ProfileStepper :steps="stepViews" :current="current" class="swbi-pe__stepper" @go="jump" />

    <div class="swbi-pe__grid">
      <section class="swbi-pe__card" :aria-labelledby="`swbi-pe-h-${stepId}`">
        <header class="swbi-pe__cardhead">
          <span class="swbi-pe__ico" aria-hidden="true"
            ><q-icon :name="ICONS[stepId]" size="22px"
          /></span>
          <div>
            <h2 :id="`swbi-pe-h-${stepId}`" ref="heading" tabindex="-1">
              {{ t(`profiles.wizard.steps.${stepId}.title`) }}
            </h2>
            <p>{{ t(`profiles.wizard.steps.${stepId}.desc`) }}</p>
          </div>
          <span class="swbi-mono swbi-pe__tag">step.{{ stepId }}</span>
        </header>

        <fieldset class="swbi-pe__fields" :disabled="readonly">
          <StepGeneral
            v-if="stepId === 'general'"
            v-model="form"
            :profile-id="profileId"
            :full-backups="fullBackups"
            :issues="issues.general"
            :show-issues="showIssues"
          />
          <StepContent v-else-if="stepId === 'content'" v-model="form" />
          <StepCompression
            v-else-if="stepId === 'compression'"
            v-model="form"
            :key-ids="keyIds"
            :keys-loading="keysLoading"
            :issues="issues.compression"
            :show-issues="showIssues"
          />
          <StepStorage
            v-else-if="stepId === 'storage'"
            v-model="form"
            :targets="targets"
            :loading="targetsLoading"
          />
          <StepRetention
            v-else-if="stepId === 'retention'"
            v-model="form"
            :issues="issues.retention"
            :show-issues="showIssues"
          />
          <StepSummary v-else :form="form" :targets="targets" :issues="issues" @go="jump" />
        </fieldset>

        <footer class="swbi-pe__foot">
          <q-btn
            flat
            no-caps
            icon="r_arrow_back"
            :label="t('profiles.wizard.back')"
            :disable="current === 0"
            @click="back"
          />
          <span
            class="swbi-pe__hint"
            :class="{ 'is-bad': totalIssueCount > 0 && showIssues }"
            role="status"
          >
            <template v-if="totalIssueCount > 0 && showIssues">
              <q-icon name="r_error" size="16px" aria-hidden="true" />
              {{ t('profiles.wizard.issuesCount', { n: totalIssueCount }, totalIssueCount) }}
            </template>
            <template v-else-if="totalIssueCount === 0">
              <q-icon name="r_check_circle" size="16px" aria-hidden="true" />
              {{ t('profiles.wizard.allOk') }}
            </template>
          </span>
          <q-btn
            v-if="!isLast"
            unelevated
            no-caps
            class="swbi-pe__next"
            icon-right="r_arrow_forward"
            :label="
              t('profiles.wizard.next', {
                step: t(`profiles.wizard.steps.${STEP_IDS[current + 1] ?? 'summary'}.title`),
              })
            "
            @click="next"
          />
          <q-btn
            v-else-if="!readonly"
            unelevated
            no-caps
            class="swbi-pe__next"
            icon="r_check"
            :label="t('profiles.wizard.save')"
            :loading="saving"
            @click="save"
          />
        </footer>
      </section>

      <aside class="swbi-pe__ctx">
        <ProfileContextPanel :form="form" :profile-id="profileId" />
      </aside>
    </div>
  </div>
</template>

<style scoped>
.swbi-pe {
  container-type: inline-size;
  display: flex;
  flex-direction: column;
  gap: 20px;
  max-width: 1320px;
  margin-inline: auto;
}
.swbi-pe__head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 16px;
}
.swbi-pe__titles {
  min-width: 0;
}
.swbi-pe__crumbs {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 6px;
  font-size: 12px;
  color: var(--swbi-muted);
}
.swbi-pe__titlerow {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}
.swbi-pe__h1 {
  font-size: 26px;
  font-weight: 750;
  letter-spacing: -0.02em;
  line-height: 1.2;
}
.swbi-pe__badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 2px 10px;
  border: 1px solid var(--swbi-warning);
  border-radius: 999px;
  background: var(--swbi-warning-bg);
  color: var(--swbi-warning-text);
  font-size: 11.5px;
  font-weight: 600;
}
.swbi-pe__badge i {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: currentColor;
}
.swbi-pe__intro {
  margin-top: 4px;
  font-size: 14px;
  color: var(--swbi-muted);
}
.swbi-pe__actions {
  display: flex;
  gap: 8px;
  margin-left: auto;
}
.swbi-pe__error {
  display: flex;
  gap: 10px;
  padding: 12px 14px;
  border-radius: var(--swbi-radius-sm);
  background: var(--swbi-danger-bg);
  color: var(--swbi-danger-text);
  font-size: 14px;
}
.swbi-pe__grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 24px;
  align-items: start;
}
.swbi-pe__card {
  container-type: inline-size;
  min-width: 0;
  border: 1px solid var(--swbi-border);
  border-radius: 20px;
  background: var(--swbi-surface);
  box-shadow: var(--swbi-shadow-1);
}
.swbi-pe__cardhead {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  padding: 24px 32px 20px;
  border-bottom: 1px solid var(--swbi-border);
}
.swbi-pe__cardhead h2 {
  font-size: 18px;
  font-weight: 650;
  outline: none;
}
.swbi-pe__cardhead p {
  font-size: 14px;
  color: var(--swbi-muted);
}
.swbi-pe__ico {
  display: grid;
  place-items: center;
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: 12px;
  background: var(--swbi-primary-bg);
  color: var(--swbi-primary-text);
}
.swbi-pe__tag {
  margin: 4px 0 0 auto;
  font-size: 11px;
  color: var(--swbi-muted);
}
.swbi-pe__fields {
  min-width: 0;
  margin: 0;
  padding: 0;
  border: 0;
}
.swbi-pe__foot {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 16px 32px;
  border-top: 1px solid var(--swbi-border);
  border-radius: 0 0 20px 20px;
  background: var(--swbi-surface-2);
}
.swbi-pe__hint {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-left: auto;
  font-size: 12px;
  color: var(--swbi-success-text);
}
.swbi-pe__hint.is-bad {
  color: var(--swbi-danger-text);
  font-weight: 600;
}
.swbi-pe__next {
  background: var(--swbi-text);
  color: var(--swbi-surface);
  font-weight: 650;
}
.swbi-pe__ctx {
  min-width: 0;
}
@container (min-width: 900px) {
  .swbi-pe__grid {
    grid-template-columns: minmax(0, 1fr) 320px;
  }
  .swbi-pe__ctx {
    position: sticky;
    top: calc(var(--wp-admin--admin-bar--height, 32px) + 16px);
  }
}
@media (max-width: 860px) {
  .swbi-pe__cardhead {
    padding: 18px;
  }
  .swbi-pe__tag {
    display: none;
  }
  .swbi-pe__foot {
    flex-wrap: wrap;
    padding: 14px 18px;
  }
  .swbi-pe__hint {
    order: 3;
    flex-basis: 100%;
    margin-left: 0;
  }
  .swbi-pe__next {
    margin-left: auto;
  }
}
@media (max-width: 520px) {
  .swbi-pe__h1 {
    font-size: 22px;
  }
}
</style>
