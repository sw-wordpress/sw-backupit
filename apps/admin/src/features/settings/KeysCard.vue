<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataState from '@/components/ui/DataState.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import SecretInput from '@/components/ui/SecretInput.vue';
import SwbiCard from '@/components/ui/SwbiCard.vue';
import { useAsyncData } from '@/composables/useAsyncData';
import { useCapabilities } from '@/composables/useCapabilities';
import { useFormError } from '@/composables/useFormError';
import { useNotify } from '@/composables/useNotify';
import type { CreatedEncryptionKey, EncryptionKey } from '@/types/api';

const { t } = useI18n();
const api = useApi();
const notify = useNotify();
const { can } = useCapabilities();
const canManage = computed(() => can('swbi_settings'));

const list = useAsyncData(() => api.keys.list());
const items = computed(() => list.data.value?.items ?? []);

const newId = ref('');
const passphrase = ref('');
const creating = ref(false);
const createError = useFormError();

/** The secret lives only in this ref while the reveal dialog is open. */
const created = ref<CreatedEncryptionKey | null>(null);
const revealOpen = ref(false);
const stored = ref(false);
const copied = ref(false);

const toDelete = ref<EncryptionKey | null>(null);
const deleteOpen = ref(false);
const deleting = ref(false);
const deleteError = ref<string | null>(null);

const idRule = (v: string): true | string =>
  /^[A-Za-z0-9._-]{1,64}$/.test(v) || t('settings.keys.idInvalid');

async function create(): Promise<void> {
  createError.clear();
  creating.value = true;
  try {
    created.value = await api.keys.create(newId.value.trim(), passphrase.value || undefined);
    stored.value = false;
    copied.value = false;
    revealOpen.value = true;
    newId.value = '';
    passphrase.value = '';
    await list.load();
  } catch (e) {
    createError.handle(e);
  } finally {
    creating.value = false;
  }
}

async function copySecret(): Promise<void> {
  if (!created.value) return;
  try {
    await navigator.clipboard.writeText(created.value.secret);
    copied.value = true;
  } catch {
    notify.info(t('settings.keys.copyFailed'));
  }
}

function closeReveal(): void {
  revealOpen.value = false;
  created.value = null; // drop the secret from memory
}

function askDelete(key: EncryptionKey): void {
  toDelete.value = key;
  deleteError.value = null;
  deleteOpen.value = true;
}
async function doDelete(): Promise<void> {
  if (!toDelete.value) return;
  deleting.value = true;
  try {
    await api.keys.remove(toDelete.value.id);
    notify.success(t('settings.keys.deleted'));
    deleteOpen.value = false;
    await list.load();
  } catch (e) {
    deleteError.value = e instanceof Error ? e.message : t('errors.generic');
  } finally {
    deleting.value = false;
  }
}
</script>

<template>
  <SwbiCard :title="t('settings.keys.title')" icon="r_key">
    <p class="swbi-muted swbi-keys__intro">{{ t('settings.keys.intro') }}</p>

    <DataState
      :loading="list.loading.value"
      :error="list.error.value"
      :empty="items.length === 0"
      @retry="list.load()"
    >
      <template #empty>
        <EmptyState
          compact
          illustration="storage"
          :title="t('settings.keys.emptyTitle')"
          :text="t('settings.keys.emptyText')"
        />
      </template>
      <ul class="swbi-keys__list">
        <li v-for="key in items" :key="key.id">
          <q-icon name="r_key" size="20px" aria-hidden="true" />
          <span class="swbi-mono swbi-keys__id">{{ key.id }}</span>
          <span class="swbi-muted">{{ t('settings.keys.source') }}: {{ key.source }}</span>
          <span class="swbi-keys__spacer" />
          <q-btn
            v-if="canManage"
            flat
            round
            dense
            color="negative"
            icon="r_delete"
            :aria-label="t('settings.keys.deleteNamed', { id: key.id })"
            @click="askDelete(key)"
          />
        </li>
      </ul>
    </DataState>

    <q-form v-if="canManage" class="swbi-keys__form" @submit="create">
      <h3 class="swbi-keys__h">{{ t('settings.keys.create') }}</h3>
      <div v-if="createError.error.value" class="swbi-keys__err" role="alert">
        <q-icon name="r_error" size="18px" aria-hidden="true" />
        <span>
          {{ createError.error.value.message }}
          <template v-for="(msg, field) in createError.error.value.params" :key="field">
            · <code>{{ field }}</code> {{ msg }}
          </template>
        </span>
      </div>
      <q-input
        v-model="newId"
        outlined
        dense
        autocomplete="off"
        :label="t('settings.keys.id')"
        :hint="t('settings.keys.idHint')"
        :rules="[idRule]"
        lazy-rules="ondemand"
      />
      <SecretInput v-model="passphrase" :label="t('settings.keys.passphrase')" />
      <p class="swbi-muted swbi-keys__small">{{ t('settings.keys.passphraseHint') }}</p>
      <div>
        <q-btn
          unelevated
          no-caps
          color="primary"
          type="submit"
          icon="r_add"
          :label="t('settings.keys.createButton')"
          :loading="creating"
        />
      </div>
    </q-form>

    <q-dialog :model-value="revealOpen" persistent>
      <q-card class="swbi-keys__dialog" role="alertdialog" aria-labelledby="swbi-key-title">
        <q-card-section>
          <h2 id="swbi-key-title" class="swbi-keys__dtitle">
            {{ t('settings.keys.createdTitle', { id: created?.id ?? '' }) }}
          </h2>
          <div class="swbi-keys__warn" role="alert">
            <q-icon name="r_warning" size="24px" aria-hidden="true" />
            <div>
              <strong>{{ t('settings.keys.storeNow') }}</strong>
              <p>{{ t('settings.keys.storeNowText') }}</p>
              <p v-if="created?.warning">{{ created.warning }}</p>
            </div>
          </div>
          <code class="swbi-keys__secret" data-testid="key-secret">{{ created?.secret }}</code>
          <q-btn
            outline
            no-caps
            dense
            :icon="copied ? 'r_check' : 'r_content_copy'"
            :label="copied ? t('settings.keys.copied') : t('settings.keys.copy')"
            @click="copySecret"
          />
          <q-checkbox
            v-model="stored"
            class="swbi-keys__check"
            :label="t('settings.keys.storedConfirm')"
          />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn
            unelevated
            no-caps
            color="primary"
            :label="t('common.close')"
            :disable="!stored"
            @click="closeReveal"
          />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <ConfirmDialog
      v-model="deleteOpen"
      :title="t('settings.keys.deleteTitle')"
      :target="toDelete?.id"
      :consequences="[
        t('settings.keys.deleteConsequence1'),
        t('settings.keys.deleteConsequence2'),
        t('common.irreversible'),
      ]"
      :confirm-label="t('common.delete')"
      :require-text="toDelete?.id"
      :loading="deleting"
      :error="deleteError"
      @confirm="doDelete"
    />
  </SwbiCard>
</template>

<style scoped>
.swbi-keys__intro {
  margin-bottom: 14px;
}
.swbi-keys__list {
  display: flex;
  flex-direction: column;
  border: 1px solid var(--swbi-border);
  border-radius: var(--swbi-radius-sm);
}
.swbi-keys__list li {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 14px;
  border-bottom: 1px solid var(--swbi-border);
  flex-wrap: wrap;
}
.swbi-keys__list li:last-child {
  border-bottom: 0;
}
.swbi-keys__id {
  font-weight: 600;
}
.swbi-keys__spacer {
  flex: 1;
}
.swbi-keys__form {
  display: flex;
  flex-direction: column;
  gap: 14px;
  margin-top: 22px;
  padding-top: 18px;
  border-top: 1px solid var(--swbi-border);
  max-width: 520px;
}
.swbi-keys__h {
  font-size: 15px;
  font-weight: 650;
}
.swbi-keys__small {
  font-size: 13px;
  margin-top: -8px;
}
.swbi-keys__err {
  display: flex;
  gap: 8px;
  padding: 10px 12px;
  border-radius: var(--swbi-radius-sm);
  background: var(--swbi-danger-bg);
  color: var(--swbi-danger-text);
  font-size: 14px;
}
.swbi-keys__dialog {
  width: min(560px, calc(100vw - 24px));
  max-width: none;
}
.swbi-keys__dtitle {
  font-size: 18px;
  font-weight: 700;
  margin-bottom: 12px;
}
.swbi-keys__warn {
  display: flex;
  gap: 12px;
  padding: 14px 16px;
  margin-bottom: 14px;
  border-radius: var(--swbi-radius-sm);
  background: var(--swbi-danger-bg);
  color: var(--swbi-danger-text);
  font-size: 14px;
}
.swbi-keys__warn p {
  margin-top: 4px;
}
.swbi-keys__secret {
  display: block;
  margin-bottom: 10px;
  padding: 12px;
  font-size: 14px;
  overflow-wrap: anywhere;
  user-select: all;
}
.swbi-keys__check {
  display: block;
  margin-top: 12px;
}
</style>
