<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import StatusChip from '@/components/ui/StatusChip.vue';
import type { ChipKind } from '@/components/ui/StatusChip.vue';
import { useCapabilities } from '@/composables/useCapabilities';
import { useFormat } from '@/composables/useFormat';
import { useNotify } from '@/composables/useNotify';
import type { Backup, RestoreTestResult, RestoreTestStatus } from '@/types/api';

const props = defineProps<{ backup: Backup }>();
const emit = defineEmits<{ (e: 'tested', result: RestoreTestResult): void }>();
const { t } = useI18n();
const fmt = useFormat();
const notify = useNotify();
const { can } = useCapabilities();

const confirmOpen = ref(false);
const running = ref(false);

const OVERALL: Record<RestoreTestStatus, { kind: ChipKind; icon: string }> = {
  passed: { kind: 'success', icon: 'r_verified' },
  partial: { kind: 'warning', icon: 'r_rule' },
  failed: { kind: 'danger', icon: 'r_error' },
};
const CHECK: Record<string, { kind: ChipKind; icon: string }> = {
  passed: { kind: 'success', icon: 'r_check_circle' },
  failed: { kind: 'danger', icon: 'r_error' },
  skipped: { kind: 'neutral', icon: 'r_skip_next' },
};

async function run(): Promise<void> {
  running.value = true;
  try {
    const result = await useApi().backups.restoreTest(props.backup.id);
    confirmOpen.value = false;
    emit('tested', result);
    notify.success(t(`backups.restoreTest.done.${result.status}`));
  } catch (e) {
    notify.error(e);
    confirmOpen.value = false;
  } finally {
    running.value = false;
  }
}
</script>

<template>
  <section class="swbi-rt" :aria-labelledby="`swbi-rt-${backup.id}`">
    <div class="swbi-rt__head">
      <h3 :id="`swbi-rt-${backup.id}`" class="swbi-rt__title">
        {{ t('backups.restoreTest.title') }}
      </h3>
      <q-btn
        v-if="can('swbi_restore')"
        outline
        no-caps
        dense
        icon="r_science"
        :label="t('backups.restoreTest.run')"
        :loading="running"
        @click="confirmOpen = true"
      />
    </div>

    <div v-if="running" aria-busy="true" role="status">
      <q-skeleton height="80px" />
      <p class="swbi-muted swbi-rt__small">{{ t('backups.restoreTest.running') }}</p>
    </div>

    <template v-else-if="backup.restore_test">
      <div class="swbi-rt__summary">
        <StatusChip
          :kind="OVERALL[backup.restore_test.status].kind"
          :icon="OVERALL[backup.restore_test.status].icon"
          :label="t(`backups.restoreTest.status.${backup.restore_test.status}`)"
        />
        <span class="swbi-muted swbi-num">
          {{
            t('backups.restoreTest.duration', { time: fmt.duration(backup.restore_test.seconds) })
          }}
          · {{ fmt.dateTime(backup.restore_test.tested_at) }} ({{
            fmt.relative(backup.restore_test.tested_at)
          }})
        </span>
      </div>
      <p v-if="backup.restore_test.status === 'partial'" class="swbi-rt__note" role="note">
        <q-icon name="r_info" size="18px" aria-hidden="true" />
        {{ t('backups.restoreTest.partialExplain') }}
      </p>
      <ul class="swbi-rt__checks" :aria-label="t('backups.restoreTest.checks')">
        <li v-for="c in backup.restore_test.checks" :key="c.name">
          <StatusChip
            :kind="(CHECK[c.status] ?? CHECK.skipped!).kind"
            :icon="(CHECK[c.status] ?? CHECK.skipped!).icon"
            :label="t(`backups.restoreTest.check.${c.status}`)"
            dense
          />
          <strong>{{ c.name }}</strong>
          <span class="swbi-muted">{{ c.detail }}</span>
        </li>
      </ul>
    </template>

    <p v-else class="swbi-muted swbi-rt__never">
      <q-icon name="r_help_outline" size="18px" aria-hidden="true" />
      {{
        backup.restore_tested_at
          ? t('backups.restoreTest.noDetails')
          : t('backups.restoreTest.never')
      }}
    </p>

    <ConfirmDialog
      v-model="confirmOpen"
      :danger="false"
      :title="t('backups.restoreTest.confirmTitle')"
      :message="t('backups.restoreTest.confirmMessage')"
      :target="`${backup.profile_name} · ${fmt.dateTime(backup.created_at)} (${fmt.shortId(backup.id)})`"
      :consequences="[
        t('backups.restoreTest.consequence1'),
        t('backups.restoreTest.consequence2'),
        t('backups.restoreTest.consequence3'),
      ]"
      :confirm-label="t('backups.restoreTest.start')"
      :loading="running"
      @confirm="run"
    />
  </section>
</template>

<style scoped>
.swbi-rt {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
  border: 1px solid var(--swbi-border);
  border-radius: var(--swbi-radius-sm);
}
.swbi-rt__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.swbi-rt__title {
  font-size: 15px;
  font-weight: 650;
}
.swbi-rt__summary {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.swbi-rt__small {
  font-size: 13px;
  margin-top: 6px;
}
.swbi-rt__note {
  display: flex;
  gap: 8px;
  padding: 10px 12px;
  border-radius: var(--swbi-radius-sm);
  background: var(--swbi-warning-bg);
  color: var(--swbi-warning-text);
  font-size: 13px;
}
.swbi-rt__checks {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.swbi-rt__checks li {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 2px 10px;
  align-items: center;
}
.swbi-rt__checks li .swbi-muted {
  grid-column: 2;
  font-size: 13px;
}
.swbi-rt__never {
  display: flex;
  gap: 8px;
  align-items: center;
}
</style>
