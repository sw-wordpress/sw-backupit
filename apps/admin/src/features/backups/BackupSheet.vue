<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import ErrorState from '@/components/ui/ErrorState.vue';
import SideSheet from '@/components/ui/SideSheet.vue';
import StatusChip from '@/components/ui/StatusChip.vue';
import { useAsyncData } from '@/composables/useAsyncData';
import { useCapabilities } from '@/composables/useCapabilities';
import { useFormat } from '@/composables/useFormat';
import { useNotify } from '@/composables/useNotify';
import type { Backup, VerifyResult } from '@/types/api';
import BackupFileBrowser from './BackupFileBrowser.vue';
import CompareDialog from './CompareDialog.vue';
import RestoreDialog from './RestoreDialog.vue';
import RestoreTestPanel from './RestoreTestPanel.vue';
import TransferDialog from './TransferDialog.vue';

const open = defineModel<boolean>({ required: true });
const props = defineProps<{ backupId: string }>();
const emit = defineEmits<{ (e: 'changed'): void; (e: 'deleted'): void }>();

const { t } = useI18n();
const fmt = useFormat();
const api = useApi();
const notify = useNotify();
const { can } = useCapabilities();

const detail = useAsyncData(() => api.backups.get(props.backupId), { immediate: false });
const backup = computed(() => detail.data.value);
const tab = ref<'overview' | 'files'>('overview');
const verifying = ref(false);
const verifyResult = ref<VerifyResult | null>(null);
const protecting = ref(false);
const compareOpen = ref(false);
const restoreOpen = ref(false);
const transferOpen = ref(false);
const deleteOpen = ref(false);
const deleting = ref(false);

watch(
  () => [open.value, props.backupId] as const,
  ([isOpen]) => {
    if (isOpen) {
      tab.value = 'overview';
      verifyResult.value = null;
      void detail.load();
    }
  },
  { immediate: true },
);

const volumes = computed(() =>
  Array.from({ length: backup.value?.volume_count ?? 0 }, (_, i) => i + 1),
);

function setBackup(b: Backup): void {
  detail.data.value = b;
}

async function verify(): Promise<void> {
  if (!backup.value) return;
  verifying.value = true;
  verifyResult.value = null;
  try {
    verifyResult.value = await api.backups.verify(backup.value.id);
    await detail.load();
    emit('changed');
  } catch (e) {
    notify.error(e);
  } finally {
    verifying.value = false;
  }
}

async function toggleProtect(value: boolean): Promise<void> {
  if (!backup.value) return;
  protecting.value = true;
  try {
    setBackup(await api.backups.protect(backup.value.id, value));
    notify.success(value ? t('backups.protectedOn') : t('backups.protectedOff'));
    emit('changed');
  } catch (e) {
    notify.error(e);
    await detail.load();
  } finally {
    protecting.value = false;
  }
}

async function doDelete(): Promise<void> {
  if (!backup.value) return;
  deleting.value = true;
  try {
    await api.backups.remove(backup.value.id);
    notify.success(t('backups.deleted'));
    deleteOpen.value = false;
    open.value = false;
    emit('deleted');
  } catch (e) {
    notify.error(e);
  } finally {
    deleting.value = false;
  }
}

const verifyOk = computed(
  () => verifyResult.value !== null && 'ok' in verifyResult.value && verifyResult.value.ok,
);
</script>

<template>
  <SideSheet
    v-model="open"
    :title="
      backup ? `${backup.profile_name} · ${fmt.dateTime(backup.created_at)}` : t('backups.detail')
    "
    :subtitle="backup ? backup.id : undefined"
    :width="780"
  >
    <ErrorState
      v-if="detail.error.value && !backup"
      :error="detail.error.value"
      @retry="detail.load()"
    />
    <div v-else-if="!backup" aria-busy="true" class="swbi-stack swbi-bs__skel">
      <q-skeleton type="text" width="50%" height="28px" />
      <q-skeleton height="120px" />
      <q-skeleton height="160px" />
    </div>

    <div v-else class="swbi-stack swbi-bs">
      <div class="swbi-row">
        <StatusChip domain="backup" :status="backup.status" />
        <StatusChip kind="primary" icon="r_inventory_2" :label="t(`types.${backup.type}`)" />
        <StatusChip
          v-if="backup.encrypted"
          kind="info"
          icon="r_lock"
          :label="t('backups.encrypted')"
        />
        <StatusChip
          v-if="backup.protected"
          kind="warning"
          icon="r_shield"
          :label="t('backups.protected')"
        />
      </div>

      <div class="swbi-row swbi-bs__actions" role="toolbar" :aria-label="t('common.actions')">
        <q-btn
          v-if="can('swbi_run')"
          outline
          no-caps
          icon="r_fact_check"
          :label="t('backups.verify')"
          :loading="verifying"
          @click="verify"
        />
        <q-btn
          v-if="can('swbi_download')"
          outline
          no-caps
          icon="r_download"
          :label="t('backups.download')"
        >
          <q-menu auto-close>
            <q-list style="min-width: 200px">
              <q-item clickable tag="a" :href="api.backups.packageUrl(backup.id)" rel="noopener">
                <q-item-section avatar><q-icon name="r_inventory_2" /></q-item-section>
                <q-item-section>
                  <q-item-label>{{ t('backups.package') }}</q-item-label>
                  <q-item-label caption>{{ t('backups.packageHint') }}</q-item-label>
                </q-item-section>
              </q-item>
              <q-separator />
              <q-item-label header>{{ t('backups.downloadVolume') }}</q-item-label>
              <q-item
                v-for="v in volumes"
                :key="`v${v}`"
                clickable
                tag="a"
                :href="api.backups.downloadUrl(backup.id, 'volume', v)"
                rel="noopener"
              >
                <q-item-section avatar><q-icon name="r_folder_zip" /></q-item-section>
                <q-item-section>{{
                  t('backups.volumeN', { n: v, total: backup.volume_count })
                }}</q-item-section>
              </q-item>
              <q-separator />
              <q-item-label header>{{ t('backups.downloadParts') }}</q-item-label>
              <q-item
                v-if="backup.type !== 'files'"
                clickable
                tag="a"
                :href="api.backups.downloadUrl(backup.id, 'database', 1)"
                rel="noopener"
              >
                <q-item-section avatar><q-icon name="r_storage" /></q-item-section>
                <q-item-section>{{ t('backups.part.database') }}</q-item-section>
              </q-item>
              <q-item
                v-for="part in ['manifest', 'index'] as const"
                :key="part"
                clickable
                tag="a"
                :href="api.backups.downloadUrl(backup.id, part)"
                rel="noopener"
              >
                <q-item-section avatar><q-icon name="r_description" /></q-item-section>
                <q-item-section>{{ t(`backups.part.${part}`) }}</q-item-section>
              </q-item>
            </q-list>
          </q-menu>
        </q-btn>
        <q-btn
          outline
          no-caps
          icon="r_compare_arrows"
          :label="t('backups.compare.open')"
          @click="compareOpen = true"
        />
        <q-btn
          v-if="can('swbi_manage_storage')"
          outline
          no-caps
          icon="r_drive_file_move"
          :label="t('backups.transfer.open')"
          @click="transferOpen = true"
        />
        <q-btn
          v-if="can('swbi_restore')"
          outline
          no-caps
          color="negative"
          icon="r_restore"
          :label="t('backups.restore.open')"
          @click="restoreOpen = true"
        />
        <q-btn
          v-if="can('swbi_delete')"
          flat
          no-caps
          color="negative"
          icon="r_delete"
          :label="t('common.delete')"
          :disable="backup.protected"
          @click="deleteOpen = true"
        >
          <q-tooltip v-if="backup.protected">{{ t('backups.protectedNoDelete') }}</q-tooltip>
        </q-btn>
      </div>

      <div
        v-if="verifyResult"
        class="swbi-bs__verify"
        :class="verifyOk || 'job' in verifyResult ? 'is-ok' : 'is-bad'"
        role="status"
      >
        <template v-if="'job' in verifyResult">
          <q-icon name="r_sync" size="20px" aria-hidden="true" />
          <span
            >{{ t('backups.verifyJob') }}
            <router-link :to="{ name: 'job', params: { id: verifyResult.job.id } }">{{
              t('jobs.open')
            }}</router-link></span
          >
        </template>
        <template v-else>
          <q-icon
            :name="verifyResult.ok ? 'r_verified' : 'r_error'"
            size="20px"
            aria-hidden="true"
          />
          <div>
            <strong>{{
              verifyResult.ok
                ? t(
                    'backups.verifyOk',
                    { n: verifyResult.checked_volumes },
                    verifyResult.checked_volumes,
                  )
                : t('backups.verifyFailed')
            }}</strong>
            <ul v-if="verifyResult.errors.length > 0">
              <li v-for="(err, i) in verifyResult.errors" :key="i">{{ err }}</li>
            </ul>
          </div>
        </template>
      </div>

      <q-tabs
        v-model="tab"
        dense
        align="left"
        no-caps
        active-color="primary"
        indicator-color="primary"
      >
        <q-tab name="overview" :label="t('backups.tabs.overview')" />
        <q-tab name="files" :label="t('backups.tabs.files')" />
      </q-tabs>
      <q-separator />

      <div v-if="tab === 'overview'" class="swbi-stack" role="tabpanel">
        <q-toggle
          v-if="can('swbi_delete')"
          :model-value="backup.protected"
          :disable="protecting"
          :label="t('backups.protectToggle')"
          @update:model-value="toggleProtect"
        />
        <p v-if="can('swbi_delete')" class="swbi-muted swbi-bs__small">
          {{ t('backups.protectHint') }}
        </p>

        <dl class="swbi-bs__meta">
          <div>
            <dt>{{ t('backups.meta.id') }}</dt>
            <dd class="swbi-mono">{{ backup.id }}</dd>
          </div>
          <div>
            <dt>{{ t('backups.meta.created') }}</dt>
            <dd>{{ fmt.dateTime(backup.created_at) }}</dd>
          </div>
          <div>
            <dt>{{ t('backups.meta.profile') }}</dt>
            <dd>{{ backup.profile_name }}</dd>
          </div>
          <div>
            <dt>{{ t('backups.meta.size') }}</dt>
            <dd class="swbi-num">{{ fmt.bytes(backup.size_bytes) }}</dd>
          </div>
          <div>
            <dt>{{ t('backups.meta.files') }}</dt>
            <dd class="swbi-num">{{ fmt.number(backup.file_count) }}</dd>
          </div>
          <div>
            <dt>{{ t('backups.meta.volumes') }}</dt>
            <dd class="swbi-num">{{ backup.volume_count }}</dd>
          </div>
          <div>
            <dt>{{ t('backups.meta.wp') }}</dt>
            <dd>{{ backup.wp_version }}</dd>
          </div>
          <div>
            <dt>{{ t('backups.meta.site') }}</dt>
            <dd class="swbi-mono">{{ backup.site_url }}</dd>
          </div>
          <div v-if="backup.parent_id">
            <dt>{{ t('backups.meta.parent') }}</dt>
            <dd>
              <router-link
                class="swbi-mono"
                :to="{ name: 'backups', params: { id: backup.parent_id } }"
                >{{ fmt.shortId(backup.parent_id) }}</router-link
              >
            </dd>
          </div>
          <div>
            <dt>{{ t('backups.meta.verified') }}</dt>
            <dd>{{ backup.verified_at ? fmt.dateTime(backup.verified_at) : t('common.never') }}</dd>
          </div>
          <div>
            <dt>{{ t('backups.meta.restoreTested') }}</dt>
            <dd>
              {{
                backup.restore_tested_at
                  ? fmt.dateTime(backup.restore_tested_at)
                  : t('common.never')
              }}
            </dd>
          </div>
        </dl>

        <div v-if="backup.parent_id" class="swbi-bs__chain" role="note">
          <q-icon name="r_account_tree" size="20px" aria-hidden="true" />
          <div>
            <strong>{{ t('backups.chain.title') }}</strong>
            <p>{{ t('backups.chain.text', { type: t(`types.${backup.type}`) }) }}</p>
            <router-link
              class="swbi-mono"
              :to="{ name: 'backups', params: { id: backup.parent_id } }"
              >{{ fmt.shortId(backup.parent_id) }}</router-link
            >
          </div>
        </div>

        <RestoreTestPanel
          :backup="backup"
          @tested="
            detail.load();
            emit('changed');
          "
        />

        <section>
          <h3 class="swbi-bs__h">{{ t('backups.locations') }}</h3>
          <p v-if="backup.locations.length === 0" class="swbi-muted">
            {{ t('backups.noLocations') }}
          </p>
          <ul v-else class="swbi-bs__locs">
            <li v-for="loc in backup.locations" :key="loc.target_id">
              <span class="swbi-bs__locname"
                ><q-icon name="r_dns" size="18px" aria-hidden="true" />{{ loc.name }}</span
              >
              <StatusChip domain="location" :status="loc.status" dense />
              <span class="swbi-muted swbi-bs__small">{{
                loc.verified_at ? fmt.relative(loc.verified_at) : ''
              }}</span>
            </li>
          </ul>
        </section>
      </div>

      <div v-else role="tabpanel"><BackupFileBrowser :backup-id="backup.id" /></div>
    </div>

    <CompareDialog v-if="backup" v-model="compareOpen" :backup-id="backup.id" />
    <RestoreDialog v-if="backup" v-model="restoreOpen" :backup="backup" />
    <TransferDialog
      v-if="backup"
      v-model="transferOpen"
      :backup="backup"
      @changed="
        detail.load();
        emit('changed');
      "
    />
    <ConfirmDialog
      v-if="backup"
      v-model="deleteOpen"
      :title="t('backups.deleteTitle')"
      :target="`${backup.profile_name} · ${fmt.dateTime(backup.created_at)} (${fmt.shortId(backup.id)})`"
      :consequences="[
        t('backups.deleteConsequence1', { n: backup.locations.length }, backup.locations.length),
        t('backups.deleteConsequence2'),
        t('common.irreversible'),
      ]"
      :confirm-label="t('backups.deleteConfirm')"
      :require-text="fmt.shortId(backup.id)"
      :loading="deleting"
      @confirm="doDelete"
    />
  </SideSheet>
</template>

<style scoped>
.swbi-bs,
.swbi-bs__skel {
  padding-top: 18px;
}
.swbi-bs__actions {
  gap: 8px;
}
.swbi-bs__small {
  font-size: 13px;
}
.swbi-bs__verify {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  padding: 12px 14px;
  border-radius: var(--swbi-radius-sm);
  font-size: 14px;
}
.swbi-bs__verify.is-ok {
  background: var(--swbi-success-bg);
  color: var(--swbi-success-text);
}
.swbi-bs__verify.is-bad {
  background: var(--swbi-danger-bg);
  color: var(--swbi-danger-text);
}
.swbi-bs__meta {
  display: grid;
  gap: 0;
  border: 1px solid var(--swbi-border);
  border-radius: var(--swbi-radius-sm);
  overflow: hidden;
}
.swbi-bs__meta > div {
  display: grid;
  grid-template-columns: 150px minmax(0, 1fr);
  gap: 12px;
  padding: 10px 14px;
  border-bottom: 1px solid var(--swbi-border);
  font-size: 14px;
}
.swbi-bs__meta > div:last-child {
  border-bottom: 0;
}
.swbi-bs__meta dt {
  color: var(--swbi-muted);
}
.swbi-bs__meta dd {
  overflow-wrap: anywhere;
}
.swbi-bs__chain {
  display: flex;
  gap: 12px;
  padding: 12px 14px;
  border-radius: var(--swbi-radius-sm);
  background: var(--swbi-info-bg);
  color: var(--swbi-info-text);
  font-size: 14px;
}
.swbi-bs__h {
  font-size: 15px;
  font-weight: 650;
  margin-bottom: 8px;
}
.swbi-bs__locs {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.swbi-bs__locs li {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.swbi-bs__locname {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
}
@media (max-width: 520px) {
  .swbi-bs__meta > div {
    grid-template-columns: minmax(0, 1fr);
    gap: 2px;
  }
}
</style>
