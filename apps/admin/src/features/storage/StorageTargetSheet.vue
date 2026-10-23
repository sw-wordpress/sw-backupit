<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import FormSection from '@/components/ui/FormSection.vue';
import FormSheet from '@/components/ui/FormSheet.vue';
import SecretInput from '@/components/ui/SecretInput.vue';
import { useNotify } from '@/composables/useNotify';
import type { Retention, StorageTarget, StorageTargetInput, StorageType } from '@/types/api';

const open = defineModel<boolean>({ required: true });
const props = defineProps<{ target: StorageTarget | null; readonly?: boolean }>();
const emit = defineEmits<{ (e: 'saved', target: StorageTarget): void }>();

const { t } = useI18n();
const notify = useNotify();
const saving = ref(false);

const name = ref('');
const type = ref<StorageType>('local');
const immutable = ref(false);
const ownRetention = ref(false);
const retention = ref<Retention>(blankRetention());
const cfg = ref<Record<string, string | number | boolean>>({});
/** Write-only secrets. Never prefilled from the server, wiped on close. */
const secrets = ref<Record<string, string>>({});

function blankRetention(): Retention {
  return { max_count: 10, max_age_days: 0, max_storage_mb: 0, gfs: { daily: 0, weekly: 0, monthly: 0, yearly: 0 } };
}
function defaultsFor(kind: StorageType): Record<string, string | number | boolean> {
  switch (kind) {
    case 'sftp':
      return { host: '', port: 22, username: '', root: '' };
    case 's3':
      return { endpoint: '', region: '', bucket: '', prefix: '', path_style: false };
    default:
      return { path: '' };
  }
}

watch(open, (isOpen) => {
  if (isOpen) {
    const tg = props.target;
    name.value = tg?.name ?? '';
    type.value = tg?.type ?? 'local';
    immutable.value = tg?.immutable ?? false;
    ownRetention.value = tg?.retention != null;
    retention.value = tg?.retention ? JSON.parse(JSON.stringify(tg.retention)) : blankRetention();
    cfg.value = { ...defaultsFor(type.value), ...(tg?.config ?? {}) };
    secrets.value = {};
  } else {
    secrets.value = {};
  }
});
watch(type, (kind) => {
  if (!props.target) cfg.value = defaultsFor(kind);
  secrets.value = {};
});

const isEdit = computed(() => props.target !== null);
const credentialsSet = computed(() => props.target?.credentials_set === true);
const required = (v: string | number | boolean): true | string => (v !== '' && v !== undefined) || t('common.required');
const str = (key: string): string => String(cfg.value[key] ?? '');
function setStr(key: string, value: string | number | null): void {
  cfg.value = { ...cfg.value, [key]: value ?? '' };
}

async function save(): Promise<void> {
  const credentials: Record<string, string> = {};
  for (const [k, v] of Object.entries(secrets.value)) if (v !== '') credentials[k] = v;
  const input: StorageTargetInput = {
    name: name.value.trim(),
    type: type.value,
    config: cfg.value,
    immutable: immutable.value,
    retention: ownRetention.value ? retention.value : null,
    ...(Object.keys(credentials).length > 0 ? { credentials } : {}),
  };
  saving.value = true;
  try {
    const api = useApi();
    const saved = props.target ? await api.storage.update(props.target.id, input) : await api.storage.create(input);
    notify.success(t('common.saved'));
    emit('saved', saved);
    open.value = false;
  } catch (e) {
    notify.error(e);
  } finally {
    saving.value = false;
    secrets.value = {};
  }
}
</script>

<template>
  <FormSheet v-model="open" :title="isEdit ? t('storage.edit') : t('storage.add')" :subtitle="target?.name" :loading="saving" :readonly="readonly" :width="640" @submit="save">
    <fieldset class="swbi-fieldset" :disabled="readonly">
      <FormSection :title="t('storage.sections.general')">
        <q-input v-model="name" outlined dense :label="t('common.name')" maxlength="120" :rules="[(v: string) => v.trim() !== '' || t('common.required')]" lazy-rules="ondemand" />
        <q-select
          v-model="type"
          outlined
          dense
          emit-value
          map-options
          :disable="isEdit"
          :options="(['local', 'sftp', 's3'] as const).map((v) => ({ value: v, label: t(`storage.types.${v}`) }))"
          :label="t('common.type')"
          :hint="isEdit ? t('storage.typeLocked') : undefined"
        />
      </FormSection>

      <FormSection :title="t('storage.sections.connection')" :description="t(`storage.sections.connectionDesc.${type}`)">
        <template v-if="type === 'local'">
          <q-input :model-value="str('path')" outlined dense :label="t('storage.fields.path')" :hint="t('storage.fields.pathHint')" :rules="[required]" lazy-rules="ondemand" @update:model-value="setStr('path', $event)" />
        </template>
        <template v-else-if="type === 'sftp'">
          <div class="swbi-two">
            <q-input :model-value="str('host')" outlined dense :label="t('storage.fields.host')" autocomplete="off" :rules="[required]" lazy-rules="ondemand" @update:model-value="setStr('host', $event)" />
            <q-input :model-value="cfg.port as number" outlined dense type="number" min="1" max="65535" :label="t('storage.fields.port')" @update:model-value="setStr('port', Number($event))" />
          </div>
          <q-input :model-value="str('username')" outlined dense :label="t('storage.fields.username')" autocomplete="off" :rules="[required]" lazy-rules="ondemand" @update:model-value="setStr('username', $event)" />
          <q-input :model-value="str('root')" outlined dense :label="t('storage.fields.root')" :hint="t('storage.fields.rootHint')" @update:model-value="setStr('root', $event)" />
        </template>
        <template v-else>
          <q-input :model-value="str('endpoint')" outlined dense :label="t('storage.fields.endpoint')" :hint="t('storage.fields.endpointHint')" @update:model-value="setStr('endpoint', $event)" />
          <div class="swbi-two">
            <q-input :model-value="str('region')" outlined dense :label="t('storage.fields.region')" @update:model-value="setStr('region', $event)" />
            <q-input :model-value="str('bucket')" outlined dense :label="t('storage.fields.bucket')" :rules="[required]" lazy-rules="ondemand" @update:model-value="setStr('bucket', $event)" />
          </div>
          <q-input :model-value="str('prefix')" outlined dense :label="t('storage.fields.prefix')" @update:model-value="setStr('prefix', $event)" />
          <q-toggle :model-value="cfg.path_style === true" :label="t('storage.fields.pathStyle')" @update:model-value="cfg = { ...cfg, path_style: $event }" />
        </template>
      </FormSection>

      <FormSection v-if="type !== 'local'" :title="t('storage.sections.credentials')" :description="t('storage.sections.credentialsDesc')">
        <template v-if="type === 'sftp'">
          <SecretInput v-model="secrets.password" :label="t('storage.fields.password')" :is-set="credentialsSet" />
          <SecretInput v-model="secrets.private_key" :label="t('storage.fields.privateKey')" :is-set="credentialsSet" multiline />
        </template>
        <template v-else>
          <SecretInput v-model="secrets.access_key" :label="t('storage.fields.accessKey')" :is-set="credentialsSet" :required="!isEdit" />
          <SecretInput v-model="secrets.secret_key" :label="t('storage.fields.secretKey')" :is-set="credentialsSet" :required="!isEdit" />
        </template>
        <p class="swbi-muted swbi-small"><q-icon name="r_lock" size="14px" aria-hidden="true" /> {{ t('storage.writeOnly') }}</p>
      </FormSection>

      <FormSection :title="t('storage.sections.protection')">
        <q-toggle v-model="immutable" :label="t('storage.immutable')" />
        <p class="swbi-muted swbi-small">{{ t('storage.immutableHint') }}</p>
        <q-toggle v-model="ownRetention" :label="t('storage.ownRetention')" />
        <div v-if="ownRetention" class="swbi-two">
          <q-input v-model.number="retention.max_count" outlined dense type="number" min="0" :label="t('profiles.maxCount')" :hint="t('profiles.zeroOff')" />
          <q-input v-model.number="retention.max_age_days" outlined dense type="number" min="0" suffix="d" :label="t('profiles.maxAge')" :hint="t('profiles.zeroOff')" />
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
.swbi-small {
  font-size: 13px;
}
</style>
