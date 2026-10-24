<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import FormSection from '@/components/ui/FormSection.vue';
import FormSheet from '@/components/ui/FormSheet.vue';
import SecretInput from '@/components/ui/SecretInput.vue';
import { useFormError } from '@/composables/useFormError';
import { useNotify } from '@/composables/useNotify';
import type {
  Retention,
  StorageConfig,
  StorageTarget,
  StorageTargetInput,
  StorageType,
} from '@/types/api';

const open = defineModel<boolean>({ required: true });
const props = defineProps<{ target: StorageTarget | null; readonly?: boolean }>();
const emit = defineEmits<{ (e: 'saved', target: StorageTarget): void }>();

const { t } = useI18n();
const notify = useNotify();
const formError = useFormError();
const saving = ref(false);

const name = ref('');
const type = ref<StorageType>('local');
const immutable = ref(false);
const ownRetention = ref(false);
const retention = ref<Retention>(blankRetention());
const cfg = ref<StorageConfig>({});
const lockMode = ref<'' | 'GOVERNANCE' | 'COMPLIANCE'>('');
const lockObjectDays = ref(30);
/** Write-only secrets. Never prefilled from the server, wiped on close. */
const secrets = ref<Record<string, string>>({});

function blankRetention(): Retention {
  return {
    max_count: 10,
    max_age_days: 0,
    max_storage_mb: 0,
    delete_local_after_upload: false,
    gfs: { daily: 0, weekly: 0, monthly: 0, yearly: 0 },
  };
}
function defaultsFor(kind: StorageType): StorageConfig {
  switch (kind) {
    case 'sftp':
      return {
        host: '',
        port: 22,
        username: '',
        root: '',
        host_fingerprint: '',
        trust_unknown_host: false,
        allow_private_network: false,
        timeout: 30,
        lock_days: 0,
      };
    case 's3':
      return {
        endpoint: '',
        region: '',
        bucket: '',
        prefix: '',
        path_style: false,
        allow_private_network: false,
        lock_days: 0,
      };
    default:
      return { path: '', lock_days: 0 };
  }
}

watch(open, (isOpen) => {
  formError.clear();
  if (isOpen) {
    const tg = props.target;
    name.value = tg?.name ?? '';
    type.value = tg?.type ?? 'local';
    immutable.value = tg?.immutable ?? false;
    ownRetention.value = tg?.retention != null;
    retention.value = tg?.retention ? JSON.parse(JSON.stringify(tg.retention)) : blankRetention();
    cfg.value = { ...defaultsFor(type.value), ...(tg?.config ?? {}) };
    const ol = cfg.value.object_lock;
    lockMode.value = typeof ol === 'object' ? ol.mode : '';
    lockObjectDays.value = typeof ol === 'object' ? ol.days : 30;
    delete cfg.value.object_lock;
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
const required = (v: string | number | boolean | undefined): true | string =>
  (v !== '' && v !== undefined) || t('common.required');
const str = (key: string): string => String(cfg.value[key] ?? '');
const secret = (key: string): string => secrets.value[key] ?? '';
function setSecret(key: string, value: string): void {
  secrets.value = { ...secrets.value, [key]: value };
}
function setStr(key: string, value: string | number | null): void {
  cfg.value = { ...cfg.value, [key]: value ?? '' };
}

async function save(): Promise<void> {
  formError.clear();
  const credentials: Record<string, string> = {};
  for (const [k, v] of Object.entries(secrets.value)) if (v !== '') credentials[k] = v;
  const input: StorageTargetInput = {
    name: name.value.trim(),
    type: type.value,
    config: {
      ...cfg.value,
      ...(type.value === 's3' && lockMode.value !== ''
        ? { object_lock: { mode: lockMode.value, days: Number(lockObjectDays.value) } }
        : {}),
    },
    immutable: immutable.value,
    retention: ownRetention.value ? retention.value : null,
    ...(Object.keys(credentials).length > 0 ? { credentials } : {}),
  };
  saving.value = true;
  try {
    const api = useApi();
    const saved = props.target
      ? await api.storage.update(props.target.id, input)
      : await api.storage.create(input);
    notify.success(t('common.saved'));
    emit('saved', saved);
    open.value = false;
  } catch (e) {
    formError.handle(e);
  } finally {
    saving.value = false;
    secrets.value = {};
  }
}
</script>

<template>
  <FormSheet
    v-model="open"
    :error="formError.error.value"
    :title="isEdit ? t('storage.edit') : t('storage.add')"
    :subtitle="target?.name"
    :loading="saving"
    :readonly="readonly"
    :width="640"
    @submit="save"
  >
    <fieldset class="swbi-fieldset" :disabled="readonly">
      <FormSection :title="t('storage.sections.general')">
        <q-input
          v-model="name"
          outlined
          dense
          :label="t('common.name')"
          maxlength="120"
          :rules="[(v: string) => v.trim() !== '' || t('common.required')]"
          lazy-rules="ondemand"
        />
        <q-select
          v-model="type"
          outlined
          dense
          emit-value
          map-options
          :disable="isEdit"
          :options="
            (['local', 'sftp', 's3'] as const).map((v) => ({
              value: v,
              label: t(`storage.types.${v}`),
            }))
          "
          :label="t('common.type')"
          :hint="isEdit ? t('storage.typeLocked') : undefined"
        />
      </FormSection>

      <FormSection
        :title="t('storage.sections.connection')"
        :description="t(`storage.sections.connectionDesc.${type}`)"
      >
        <template v-if="type === 'local'">
          <q-input
            :model-value="str('path')"
            outlined
            dense
            :label="t('storage.fields.path')"
            :hint="t('storage.fields.pathHint')"
            :rules="[required]"
            lazy-rules="ondemand"
            @update:model-value="setStr('path', $event)"
          />
        </template>
        <template v-else-if="type === 'sftp'">
          <div class="swbi-two">
            <q-input
              :model-value="str('host')"
              outlined
              dense
              :label="t('storage.fields.host')"
              autocomplete="off"
              :rules="[required]"
              lazy-rules="ondemand"
              @update:model-value="setStr('host', $event)"
            />
            <q-input
              :model-value="cfg.port as number"
              outlined
              dense
              type="number"
              min="1"
              max="65535"
              :label="t('storage.fields.port')"
              @update:model-value="setStr('port', Number($event))"
            />
          </div>
          <q-input
            :model-value="str('username')"
            outlined
            dense
            :label="t('storage.fields.username')"
            autocomplete="off"
            :rules="[required]"
            lazy-rules="ondemand"
            @update:model-value="setStr('username', $event)"
          />
          <q-input
            :model-value="str('root')"
            outlined
            dense
            :label="t('storage.fields.root')"
            :hint="t('storage.fields.rootHint')"
            @update:model-value="setStr('root', $event)"
          />
          <div class="swbi-two">
            <q-input
              :model-value="str('host_fingerprint')"
              outlined
              dense
              input-class="swbi-mono"
              :label="t('storage.fields.hostFingerprint')"
              :hint="t('storage.fields.hostFingerprintHint')"
              autocomplete="off"
              @update:model-value="setStr('host_fingerprint', $event)"
            />
            <q-input
              :model-value="Number(cfg.timeout ?? 30)"
              outlined
              dense
              type="number"
              min="1"
              suffix="s"
              :label="t('storage.fields.timeout')"
              @update:model-value="setStr('timeout', Number($event))"
            />
          </div>
          <div class="swbi-warnbox" role="note">
            <q-icon name="r_gpp_maybe" size="20px" aria-hidden="true" />
            <div>
              <q-toggle
                :model-value="cfg.trust_unknown_host === true"
                :label="t('storage.fields.trustUnknown')"
                @update:model-value="cfg = { ...cfg, trust_unknown_host: $event }"
              />
              <p>{{ t('storage.fields.trustUnknownWarning') }}</p>
            </div>
          </div>
          <div class="swbi-warnbox" role="note">
            <q-icon name="r_gpp_maybe" size="20px" aria-hidden="true" />
            <div>
              <q-toggle
                :model-value="cfg.allow_private_network === true"
                :label="t('storage.fields.allowPrivate')"
                @update:model-value="cfg = { ...cfg, allow_private_network: $event }"
              />
              <p>{{ t('storage.fields.allowPrivateWarning') }}</p>
            </div>
          </div>
        </template>
        <template v-else>
          <q-input
            :model-value="str('endpoint')"
            outlined
            dense
            :label="t('storage.fields.endpoint')"
            :hint="t('storage.fields.endpointHint')"
            @update:model-value="setStr('endpoint', $event)"
          />
          <div class="swbi-two">
            <q-input
              :model-value="str('region')"
              outlined
              dense
              :label="t('storage.fields.region')"
              @update:model-value="setStr('region', $event)"
            />
            <q-input
              :model-value="str('bucket')"
              outlined
              dense
              :label="t('storage.fields.bucket')"
              :rules="[required]"
              lazy-rules="ondemand"
              @update:model-value="setStr('bucket', $event)"
            />
          </div>
          <q-input
            :model-value="str('prefix')"
            outlined
            dense
            :label="t('storage.fields.prefix')"
            @update:model-value="setStr('prefix', $event)"
          />
          <q-toggle
            :model-value="cfg.path_style === true"
            :label="t('storage.fields.pathStyle')"
            @update:model-value="cfg = { ...cfg, path_style: $event }"
          />
          <div class="swbi-warnbox" role="note">
            <q-icon name="r_gpp_maybe" size="20px" aria-hidden="true" />
            <div>
              <q-toggle
                :model-value="cfg.allow_private_network === true"
                :label="t('storage.fields.allowPrivate')"
                @update:model-value="cfg = { ...cfg, allow_private_network: $event }"
              />
              <p>{{ t('storage.fields.allowPrivateWarning') }}</p>
            </div>
          </div>
        </template>
      </FormSection>

      <FormSection
        v-if="type !== 'local'"
        :title="t('storage.sections.credentials')"
        :description="t('storage.sections.credentialsDesc')"
      >
        <template v-if="type === 'sftp'">
          <SecretInput
            :model-value="secret('password')"
            :label="t('storage.fields.password')"
            :is-set="credentialsSet"
            @update:model-value="setSecret('password', $event)"
          />
          <SecretInput
            :model-value="secret('private_key')"
            :label="t('storage.fields.privateKey')"
            :is-set="credentialsSet"
            multiline
            @update:model-value="setSecret('private_key', $event)"
          />
          <SecretInput
            :model-value="secret('passphrase')"
            :label="t('storage.fields.keyPassphrase')"
            :is-set="credentialsSet"
            @update:model-value="setSecret('passphrase', $event)"
          />
        </template>
        <template v-else>
          <SecretInput
            :model-value="secret('access_key')"
            :label="t('storage.fields.accessKey')"
            :is-set="credentialsSet"
            :required="!isEdit"
            @update:model-value="setSecret('access_key', $event)"
          />
          <SecretInput
            :model-value="secret('secret_key')"
            :label="t('storage.fields.secretKey')"
            :is-set="credentialsSet"
            :required="!isEdit"
            @update:model-value="setSecret('secret_key', $event)"
          />
        </template>
        <p class="swbi-muted swbi-small">
          <q-icon name="r_lock" size="14px" aria-hidden="true" /> {{ t('storage.writeOnly') }}
        </p>
      </FormSection>

      <FormSection :title="t('storage.sections.protection')">
        <q-input
          :model-value="Number(cfg.lock_days ?? 0)"
          outlined
          dense
          type="number"
          min="0"
          suffix="d"
          :label="t('storage.lockDays')"
          :hint="t('storage.lockDaysHint')"
          @update:model-value="setStr('lock_days', Number($event))"
        />
        <template v-if="type === 's3'">
          <div class="swbi-two">
            <q-select
              v-model="lockMode"
              outlined
              dense
              emit-value
              map-options
              :options="[
                { value: '', label: t('storage.objectLock.off') },
                { value: 'GOVERNANCE', label: t('storage.objectLock.governance') },
                { value: 'COMPLIANCE', label: t('storage.objectLock.compliance') },
              ]"
              :label="t('storage.objectLock.mode')"
            />
            <q-input
              v-model.number="lockObjectDays"
              outlined
              dense
              type="number"
              min="1"
              suffix="d"
              :disable="lockMode === ''"
              :label="t('storage.objectLock.days')"
            />
          </div>
          <p class="swbi-muted swbi-small" role="note">{{ t('storage.objectLock.note') }}</p>
        </template>
        <q-toggle v-model="immutable" :label="t('storage.immutable')" />
        <p class="swbi-muted swbi-small">{{ t('storage.immutableHint') }}</p>
        <q-toggle v-model="ownRetention" :label="t('storage.ownRetention')" />
        <div v-if="ownRetention" class="swbi-two">
          <q-input
            v-model.number="retention.max_count"
            outlined
            dense
            type="number"
            min="0"
            :label="t('profiles.maxCount')"
            :hint="t('profiles.zeroOff')"
          />
          <q-input
            v-model.number="retention.max_age_days"
            outlined
            dense
            type="number"
            min="0"
            suffix="d"
            :label="t('profiles.maxAge')"
            :hint="t('profiles.zeroOff')"
          />
        </div>
      </FormSection>
    </fieldset>
  </FormSheet>
</template>

<style scoped>
.swbi-warnbox {
  display: flex;
  gap: 12px;
  padding: 12px 14px;
  border-radius: var(--swbi-radius-sm);
  background: var(--swbi-warning-bg);
  color: var(--swbi-warning-text);
  font-size: 13px;
}
.swbi-warnbox p {
  margin-top: 2px;
}
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
