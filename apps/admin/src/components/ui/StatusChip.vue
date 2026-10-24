<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

export type ChipKind =
  'success' | 'warning' | 'danger' | 'info' | 'neutral' | 'running' | 'primary';
export type ChipDomain = 'job' | 'backup' | 'health' | 'schedule' | 'location' | 'upload';

interface Meta {
  kind: ChipKind;
  icon: string;
}

const MAP: Record<ChipDomain, Record<string, Meta>> = {
  job: {
    pending: { kind: 'neutral', icon: 'r_hourglass_empty' },
    preparing: { kind: 'running', icon: 'r_autorenew' },
    scanning: { kind: 'running', icon: 'r_autorenew' },
    exporting: { kind: 'running', icon: 'r_autorenew' },
    archiving: { kind: 'running', icon: 'r_autorenew' },
    encrypting: { kind: 'running', icon: 'r_autorenew' },
    uploading: { kind: 'running', icon: 'r_autorenew' },
    verifying: { kind: 'running', icon: 'r_autorenew' },
    completed: { kind: 'success', icon: 'r_check_circle' },
    paused: { kind: 'warning', icon: 'r_pause_circle' },
    retry_waiting: { kind: 'warning', icon: 'r_schedule' },
    failed: { kind: 'danger', icon: 'r_error' },
    cancelled: { kind: 'neutral', icon: 'r_cancel' },
  },
  backup: {
    complete: { kind: 'success', icon: 'r_check_circle' },
    verified: { kind: 'success', icon: 'r_verified' },
    corrupt: { kind: 'danger', icon: 'r_error' },
  },
  health: {
    ok: { kind: 'success', icon: 'r_check_circle' },
    warning: { kind: 'warning', icon: 'r_warning' },
    error: { kind: 'danger', icon: 'r_error' },
  },
  schedule: {
    completed: { kind: 'success', icon: 'r_check_circle' },
    failed: { kind: 'danger', icon: 'r_error' },
    never: { kind: 'neutral', icon: 'r_remove_circle_outline' },
  },
  location: {
    verified: { kind: 'success', icon: 'r_verified' },
    failed: { kind: 'danger', icon: 'r_error' },
    pending: { kind: 'neutral', icon: 'r_hourglass_empty' },
  },
  upload: {
    pending: { kind: 'neutral', icon: 'r_hourglass_empty' },
    uploading: { kind: 'running', icon: 'r_cloud_upload' },
    verified: { kind: 'success', icon: 'r_verified' },
    failed: { kind: 'danger', icon: 'r_error' },
  },
};

const props = withDefaults(
  defineProps<{
    domain?: ChipDomain;
    status?: string;
    kind?: ChipKind;
    icon?: string;
    label?: string;
    dense?: boolean;
  }>(),
  {
    domain: undefined,
    status: undefined,
    kind: undefined,
    icon: undefined,
    label: undefined,
    dense: false,
  },
);

const { t, te } = useI18n();

const meta = computed<Meta>(() => {
  const fromMap = props.domain && props.status ? MAP[props.domain][props.status] : undefined;
  return {
    kind: props.kind ?? fromMap?.kind ?? 'neutral',
    icon: props.icon ?? fromMap?.icon ?? 'r_circle',
  };
});

const text = computed(() => {
  if (props.label) return props.label;
  if (props.domain && props.status) {
    const key = `status.${props.domain}.${props.status}`;
    return te(key) ? t(key) : props.status;
  }
  return '';
});
</script>

<template>
  <span class="swbi-chip" :class="[`swbi-chip--${meta.kind}`, { 'swbi-chip--dense': dense }]">
    <q-icon :name="meta.icon" class="swbi-chip__icon" size="1.15em" aria-hidden="true" />
    <span class="swbi-chip__text">{{ text }}</span>
  </span>
</template>

<style scoped>
.swbi-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 10px 3px 8px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 600;
  line-height: 1.4;
  white-space: nowrap;
  max-width: 100%;
  background: var(--swbi-neutral-bg);
  color: var(--swbi-neutral-text);
  border: 1px solid transparent;
}
.swbi-chip--dense {
  font-size: 12px;
  padding: 1px 8px 1px 6px;
}
.swbi-chip__text {
  overflow: hidden;
  text-overflow: ellipsis;
}
.swbi-chip--success {
  background: var(--swbi-success-bg);
  color: var(--swbi-success-text);
}
.swbi-chip--warning {
  background: var(--swbi-warning-bg);
  color: var(--swbi-warning-text);
}
.swbi-chip--danger {
  background: var(--swbi-danger-bg);
  color: var(--swbi-danger-text);
}
.swbi-chip--info {
  background: var(--swbi-info-bg);
  color: var(--swbi-info-text);
}
.swbi-chip--primary,
.swbi-chip--running {
  background: var(--swbi-primary-bg);
  color: var(--swbi-primary-text);
}
.swbi-chip--running .swbi-chip__icon {
  animation: swbi-spin 1.6s linear infinite;
}
</style>
