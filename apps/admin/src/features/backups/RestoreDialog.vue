<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import SideSheet from '@/components/ui/SideSheet.vue';
import { getConfig } from '@/config';
import { useFormat } from '@/composables/useFormat';
import { useNotify } from '@/composables/useNotify';
import type { Backup, RestorePrepareResult } from '@/types/api';

/**
 * Restore never runs from this UI. "Prepare" asks the server for a one-time token and the
 * URL of the standalone restore wizard; the token is kept in memory only while this dialog
 * is open and is shown once.
 */
const open = defineModel<boolean>({ required: true });
const props = defineProps<{ backup: Backup }>();
const { t } = useI18n();
const fmt = useFormat();
const notify = useNotify();

type Step = 'intro' | 'confirm' | 'ready';
const step = ref<Step>('intro');
const preparing = ref(false);
const prepared = ref<RestorePrepareResult | null>(null);
const pathConfirmed = ref(false);
const typedId = ref('');
const copied = ref(false);
const siteUrl = getConfig().siteUrl;

watch(open, () => {
  step.value = 'intro';
  pathConfirmed.value = false;
  typedId.value = '';
  copied.value = false;
  // The token must not outlive the dialog.
  prepared.value = null;
});

const differentSite = computed(
  () =>
    siteUrl !== '' &&
    props.backup.site_url !== '' &&
    stripSlash(props.backup.site_url) !== stripSlash(siteUrl),
);
const confirmed = computed(
  () => pathConfirmed.value && typedId.value.trim() === fmt.shortId(props.backup.id),
);
const restoreHref = computed(() => {
  // Only ever render http(s) links, whatever the server returned.
  const url = prepared.value?.restore_url ?? '';
  return /^https?:\/\//i.test(url) ? url : null;
});

function stripSlash(s: string): string {
  return s.replace(/\/+$/, '');
}

async function prepare(): Promise<void> {
  preparing.value = true;
  try {
    prepared.value = await useApi().backups.restorePrepare(props.backup.id);
    step.value = 'confirm';
  } catch (e) {
    notify.error(e);
  } finally {
    preparing.value = false;
  }
}

async function copyToken(): Promise<void> {
  if (!prepared.value) return;
  try {
    await navigator.clipboard.writeText(prepared.value.token);
    copied.value = true;
  } catch {
    notify.info(t('backups.restore.copyFailed'));
  }
}
</script>

<template>
  <SideSheet
    v-model="open"
    persistent
    :title="t('backups.restore.title')"
    :subtitle="`${backup.profile_name} · ${fmt.dateTime(backup.created_at)}`"
    :width="620"
  >
    <div class="swbi-stack swbi-rs">
      <ol class="swbi-rs__steps" :aria-label="t('backups.restore.steps')">
        <li
          :class="{ 'is-current': step === 'intro', 'is-done': step !== 'intro' }"
          :aria-current="step === 'intro' ? 'step' : undefined"
        >
          1 · {{ t('backups.restore.step1') }}
        </li>
        <li
          :class="{ 'is-current': step === 'confirm', 'is-done': step === 'ready' }"
          :aria-current="step === 'confirm' ? 'step' : undefined"
        >
          2 · {{ t('backups.restore.step2') }}
        </li>
        <li
          :class="{ 'is-current': step === 'ready' }"
          :aria-current="step === 'ready' ? 'step' : undefined"
        >
          3 · {{ t('backups.restore.step3') }}
        </li>
      </ol>

      <template v-if="step === 'intro'">
        <p>{{ t('backups.restore.intro') }}</p>
        <div class="swbi-rs__box swbi-rs__box--info" role="note">
          <q-icon name="r_info" size="22px" aria-hidden="true" />
          <span>{{ t('backups.restore.wizardOnly') }}</span>
        </div>
        <div class="swbi-rs__box swbi-rs__box--danger" role="note">
          <q-icon name="r_warning" size="22px" aria-hidden="true" />
          <ul>
            <li>{{ t('backups.restore.risk1') }}</li>
            <li>{{ t('backups.restore.risk2') }}</li>
            <li>{{ t('backups.restore.risk3') }}</li>
          </ul>
        </div>
        <div class="swbi-row">
          <q-btn
            unelevated
            no-caps
            color="primary"
            icon="r_arrow_forward"
            :label="t('backups.restore.prepare')"
            :loading="preparing"
            @click="prepare"
          />
          <q-btn flat no-caps :label="t('common.cancel')" @click="open = false" />
        </div>
      </template>

      <template v-else-if="step === 'confirm' && prepared">
        <section
          v-if="prepared.warnings.length > 0"
          class="swbi-rs__box swbi-rs__box--warn"
          role="alert"
        >
          <q-icon name="r_warning" size="22px" aria-hidden="true" />
          <div>
            <strong>{{ t('backups.restore.warnings') }}</strong>
            <ul>
              <li v-for="(w, i) in prepared.warnings" :key="i">{{ w }}</li>
            </ul>
          </div>
        </section>
        <p v-else class="swbi-muted">{{ t('backups.restore.noWarnings') }}</p>

        <div v-if="differentSite" class="swbi-rs__box swbi-rs__box--warn" role="alert">
          <q-icon name="r_swap_horiz" size="22px" aria-hidden="true" />
          <div>
            <strong>{{ t('backups.restore.differentSite') }}</strong>
            <p>
              {{ t('backups.restore.differentSiteText', { from: backup.site_url, to: siteUrl }) }}
            </p>
          </div>
        </div>

        <dl class="swbi-rs__target">
          <div>
            <dt>{{ t('backups.restore.targetPath') }}</dt>
            <dd class="swbi-mono">{{ prepared.target_path }}</dd>
          </div>
          <div>
            <dt>{{ t('backups.restore.targetSite') }}</dt>
            <dd class="swbi-mono">{{ siteUrl || '–' }}</dd>
          </div>
          <div>
            <dt>{{ t('backups.restore.source') }}</dt>
            <dd class="swbi-mono">{{ fmt.shortId(backup.id) }} · {{ fmt.dateTime(backup.created_at) }}</dd>
          </div>
        </dl>

        <q-checkbox v-model="pathConfirmed" :label="t('backups.restore.confirmPath')" />
        <q-input
          v-model="typedId"
          outlined
          dense
          autocomplete="off"
          :label="t('common.typeToConfirm', { text: fmt.shortId(backup.id) })"
        />

        <div class="swbi-row">
          <q-btn
            unelevated
            no-caps
            color="primary"
            icon="r_key"
            :label="t('backups.restore.reveal')"
            :disable="!confirmed"
            @click="step = 'ready'"
          />
          <q-btn flat no-caps :label="t('common.cancel')" @click="open = false" />
        </div>
      </template>

      <template v-else-if="step === 'ready' && prepared">
        <section class="swbi-rs__box swbi-rs__box--ok">
          <q-icon name="r_check_circle" size="22px" aria-hidden="true" />
          <div>
            <strong>{{ t('backups.restore.ready') }}</strong>
            <p>
              {{
                t('backups.restore.expires', {
                  time: fmt.dateTime(prepared.token_expires_at),
                  rel: fmt.relative(prepared.token_expires_at),
                })
              }}
            </p>
          </div>
        </section>

        <div class="swbi-rs__box swbi-rs__box--warn" role="alert">
          <q-icon name="r_visibility" size="22px" aria-hidden="true" />
          <span>{{ t('backups.restore.tokenOnce') }}</span>
        </div>

        <div class="swbi-rs__token">
          <span class="swbi-eyebrow">{{ t('backups.restore.token') }}</span>
          <code class="swbi-rs__tokenvalue" data-testid="restore-token">{{ prepared.token }}</code>
          <q-btn
            outline
            no-caps
            dense
            :icon="copied ? 'r_check' : 'r_content_copy'"
            :label="copied ? t('backups.restore.copied') : t('backups.restore.copy')"
            @click="copyToken"
          />
        </div>

        <div class="swbi-rs__option">
          <h3>{{ t('backups.restore.optionStandalone') }}</h3>
          <p class="swbi-muted">{{ t('backups.restore.optionStandaloneText') }}</p>
          <q-btn
            v-if="restoreHref"
            type="a"
            :href="restoreHref"
            target="_blank"
            rel="noopener noreferrer"
            unelevated
            no-caps
            color="primary"
            icon="r_open_in_new"
            :label="t('backups.restore.openRestore')"
          />
          <p v-else class="swbi-muted">{{ t('backups.restore.invalidUrl') }}</p>
          <p class="swbi-muted swbi-mono">{{ prepared.target_path }}</p>
        </div>
        <q-btn flat no-caps :label="t('common.close')" @click="open = false" />
      </template>
    </div>
  </SideSheet>
</template>

<style scoped>
.swbi-rs {
  padding-top: 16px;
}
.swbi-rs__steps {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
  font-size: 13px;
}
.swbi-rs__steps li {
  padding: 8px 10px;
  border-radius: 10px;
  background: var(--swbi-surface-3);
  color: var(--swbi-muted);
  font-weight: 600;
}
.swbi-rs__steps li.is-current {
  background: var(--swbi-primary-bg);
  color: var(--swbi-primary-text);
}
.swbi-rs__steps li.is-done {
  background: var(--swbi-success-bg);
  color: var(--swbi-success-text);
}
.swbi-rs__box {
  display: flex;
  gap: 12px;
  padding: 14px 16px;
  border-radius: var(--swbi-radius-sm);
  font-size: 14px;
}
.swbi-rs__box ul {
  display: flex;
  flex-direction: column;
  gap: 4px;
  list-style: disc;
  padding-left: 18px;
}
.swbi-rs__box--info {
  background: var(--swbi-info-bg);
  color: var(--swbi-info-text);
}
.swbi-rs__box--danger {
  background: var(--swbi-danger-bg);
  color: var(--swbi-danger-text);
}
.swbi-rs__box--warn {
  background: var(--swbi-warning-bg);
  color: var(--swbi-warning-text);
}
.swbi-rs__box--ok {
  background: var(--swbi-success-bg);
  color: var(--swbi-success-text);
}
.swbi-rs__target {
  display: grid;
  gap: 8px;
  padding: 12px 14px;
  border: 1px solid var(--swbi-border);
  border-radius: var(--swbi-radius-sm);
  background: var(--swbi-surface-2);
}
.swbi-rs__target > div {
  display: grid;
  grid-template-columns: 120px minmax(0, 1fr);
  gap: 8px;
}
.swbi-rs__target dt {
  color: var(--swbi-muted);
}
.swbi-rs__target dd {
  overflow-wrap: anywhere;
}
.swbi-rs__token {
  display: flex;
  flex-direction: column;
  gap: 8px;
  align-items: flex-start;
  padding: 14px 16px;
  border: 1px dashed var(--swbi-warning);
  border-radius: var(--swbi-radius-sm);
  background: var(--swbi-surface-2);
}
.swbi-rs__tokenvalue {
  display: block;
  max-width: 100%;
  padding: 10px 12px;
  font-size: 14px;
  overflow-wrap: anywhere;
  user-select: all;
}
.swbi-rs__option {
  display: flex;
  flex-direction: column;
  gap: 8px;
  align-items: flex-start;
  padding: 16px;
  border: 1px solid var(--swbi-border);
  border-radius: var(--swbi-radius-sm);
}
.swbi-rs__option h3 {
  font-size: 15px;
  font-weight: 650;
}
</style>
