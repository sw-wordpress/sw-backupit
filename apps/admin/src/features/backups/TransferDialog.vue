<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import SideSheet from '@/components/ui/SideSheet.vue';
import StatusChip from '@/components/ui/StatusChip.vue';
import { useCapabilities } from '@/composables/useCapabilities';
import { useFormat } from '@/composables/useFormat';
import { useNotify } from '@/composables/useNotify';
import { useLookupsStore } from '@/stores/lookups';
import type { Backup, Transfer } from '@/types/api';
import { useTransfers } from './useTransfers';

const open = defineModel<boolean>({ required: true });
const props = defineProps<{ backup: Backup }>();
const emit = defineEmits<{ (e: 'changed'): void }>();

const { t } = useI18n();
const fmt = useFormat();
const notify = useNotify();
const lookups = useLookupsStore();
const { can } = useCapabilities();

const targetId = ref<number | null>(null);
const sourceId = ref<number | null>(null);
const move = ref(false);
const starting = ref(false);
const cancelling = ref(false);

const { transfers, load } = useTransfers((finished) => {
  if (finished.backup_id !== props.backup.id) return;
  if (finished.status === 'completed') {
    notify.success(
      finished.mode === 'move' ? t('backups.transfer.moved') : t('backups.transfer.copied'),
    );
  }
  emit('changed');
});
/** The newest transfer of this backup (running or just finished). */
const current = computed<Transfer | null>(
  () => transfers.value.find((tr) => tr.backup_id === props.backup.id) ?? null,
);
const busy = computed(
  () => current.value !== null && ['queued', 'running'].includes(current.value.status),
);
const percent = computed(() =>
  current.value && current.value.bytes_total > 0
    ? current.value.bytes_done / current.value.bytes_total
    : 0,
);

const verified = computed(() => props.backup.locations.filter((loc) => loc.status === 'verified'));
const sourceOptions = computed(() =>
  verified.value.map((loc) => ({ label: loc.name, value: loc.target_id })),
);
const targetOptions = computed(() =>
  lookups.targets
    .filter((target) => !verified.value.some((loc) => loc.target_id === target.id))
    .map((target) => ({
      label: target.name,
      caption: t(`storage.types.${target.type}`),
      value: target.id,
      disable: target.id === sourceId.value,
    })),
);
const sourceLocked = computed(() => {
  const source = lookups.targets.find((target) => target.id === sourceId.value);
  if (source === undefined) return false;
  const lockDays = Number(source.config.lock_days ?? 0);
  const lockedUntil = new Date(props.backup.created_at).getTime() + lockDays * 86_400_000;
  return source.immutable || (lockDays > 0 && lockedUntil > Date.now());
});

watch(
  open,
  async (isOpen) => {
    if (!isOpen) return;
    targetId.value = null;
    move.value = false;
    sourceId.value = verified.value[0]?.target_id ?? null;
    void load();
    try {
      await lookups.loadTargets(true);
    } catch (e) {
      notify.error(e);
    }
  },
  { immediate: true },
);
watch(sourceLocked, (locked) => {
  if (locked) move.value = false;
});

async function start(): Promise<void> {
  if (targetId.value === null) return;
  starting.value = true;
  try {
    await useApi().backups.transfer(props.backup.id, {
      target_id: targetId.value,
      source_id: sourceId.value,
      move: move.value,
    });
    notify.success(t('backups.transfer.queued'));
    await load();
    emit('changed');
  } catch (e) {
    notify.error(e);
  } finally {
    starting.value = false;
  }
}

async function cancel(): Promise<void> {
  if (!current.value) return;
  cancelling.value = true;
  try {
    await useApi().transfers.cancel(current.value.id);
    await load();
  } catch (e) {
    notify.error(e);
  } finally {
    cancelling.value = false;
  }
}
</script>

<template>
  <SideSheet
    v-model="open"
    :title="t('backups.transfer.title')"
    :subtitle="t('backups.transfer.subtitle')"
    :width="560"
  >
    <div class="swbi-stack swbi-tr">
      <section v-if="current" class="swbi-tr__status" aria-live="polite">
        <div class="swbi-row">
          <StatusChip domain="transfer" :status="current.status" />
          <span>{{
            t(`backups.transfer.summary.${current.mode}`, {
              source: current.source_name,
              target: current.target_name,
            })
          }}</span>
        </div>
        <q-linear-progress
          v-if="busy"
          :value="percent"
          :indeterminate="current.status === 'queued'"
          rounded
          size="10px"
          color="primary"
          :aria-label="t('backups.transfer.progress')"
        />
        <p v-if="busy" class="swbi-muted swbi-tr__small">
          {{ fmt.bytes(current.bytes_done) }} / {{ fmt.bytes(current.bytes_total) }}
          <template v-if="current.backups.length > 1">
            ·
            {{
              t(
                'backups.transfer.withBases',
                { n: current.backups.length - 1 },
                current.backups.length - 1,
              )
            }}
          </template>
        </p>
        <p v-if="current.error" class="swbi-tr__error" role="alert">
          <q-icon name="r_error" size="18px" aria-hidden="true" />{{ current.error }}
        </p>
        <div v-if="busy">
          <q-btn
            flat
            no-caps
            color="negative"
            icon="r_cancel"
            :label="t('backups.transfer.cancel')"
            :loading="cancelling"
            @click="cancel"
          />
        </div>
      </section>

      <template v-if="!busy">
        <p v-if="verified.length === 0" class="swbi-muted">{{ t('backups.transfer.noSource') }}</p>
        <q-select
          v-model="sourceId"
          outlined
          emit-value
          map-options
          :options="sourceOptions"
          :label="t('backups.transfer.source')"
        />
        <q-select
          v-model="targetId"
          outlined
          emit-value
          map-options
          :options="targetOptions"
          :label="t('backups.transfer.target')"
          :hint="targetOptions.length === 0 ? t('backups.transfer.noTargets') : undefined"
        >
          <template #option="scope">
            <q-item v-bind="scope.itemProps">
              <q-item-section>
                <q-item-label>{{ scope.opt.label }}</q-item-label>
                <q-item-label caption>{{ scope.opt.caption }}</q-item-label>
              </q-item-section>
            </q-item>
          </template>
        </q-select>

        <fieldset class="swbi-tr__mode">
          <legend>{{ t('backups.transfer.mode') }}</legend>
          <q-radio v-model="move" :val="false" :label="t('backups.transfer.copy')" />
          <p class="swbi-muted swbi-tr__small">{{ t('backups.transfer.copyHint') }}</p>
          <q-radio
            v-model="move"
            :val="true"
            :label="t('backups.transfer.move')"
            :disable="!can('swbi_delete') || sourceLocked"
          />
          <p class="swbi-muted swbi-tr__small">
            {{
              sourceLocked
                ? t('backups.transfer.moveLocked')
                : can('swbi_delete')
                  ? t('backups.transfer.moveHint')
                  : t('backups.transfer.moveNoPermission')
            }}
          </p>
        </fieldset>

        <div v-if="backup.parent_id" class="swbi-tr__note" role="note">
          <q-icon name="r_account_tree" size="20px" aria-hidden="true" />
          <span>{{ t('backups.transfer.chainNote') }}</span>
        </div>
        <div v-if="backup.encrypted" class="swbi-tr__note" role="note">
          <q-icon name="r_lock" size="20px" aria-hidden="true" />
          <span>{{ t('backups.transfer.encryptedNote') }}</span>
        </div>

        <div>
          <q-btn
            unelevated
            no-caps
            color="primary"
            :icon="move ? 'r_drive_file_move' : 'r_file_copy'"
            :label="move ? t('backups.transfer.startMove') : t('backups.transfer.startCopy')"
            :disable="targetId === null || sourceId === null"
            :loading="starting"
            @click="start"
          />
        </div>
        <p class="swbi-muted swbi-tr__small">{{ t('backups.transfer.background') }}</p>
      </template>
    </div>
  </SideSheet>
</template>

<style scoped>
.swbi-tr__status {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 14px 16px;
  border-radius: var(--swbi-radius);
  background: var(--swbi-neutral-bg);
}
.swbi-tr__small {
  font-size: 13px;
}
.swbi-tr__error {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  color: var(--swbi-danger-text);
}
.swbi-tr__mode {
  border: 0;
  padding: 0;
  margin: 0;
}
.swbi-tr__mode legend {
  font-weight: 600;
  margin-bottom: 4px;
}
.swbi-tr__mode .swbi-tr__small {
  margin: -4px 0 8px 40px;
}
.swbi-tr__note {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  padding: 10px 14px;
  border-radius: var(--swbi-radius);
  background: var(--swbi-info-bg);
  color: var(--swbi-info-text);
  font-size: 14px;
}
</style>
