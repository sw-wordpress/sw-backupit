<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useNotify } from '@/composables/useNotify';
import type { ProfileInput } from '@/types/api';
import { cliCommand, jsonLines, previewPayload } from './wizard';

const props = defineProps<{ form: ProfileInput; profileId: number | null }>();
const { t } = useI18n();
const notify = useNotify();
const lines = computed(() => jsonLines(previewPayload(props.form)));
const cli = computed(() => cliCommand(props.form.name, props.profileId));
const copied = ref(false);

async function copyCli(): Promise<void> {
  try {
    await navigator.clipboard.writeText(cli.value);
    copied.value = true;
  } catch {
    notify.info(t('backups.restore.copyFailed'));
  }
}
</script>

<template>
  <section class="swbi-ctx" :aria-label="t('profiles.wizard.previewTitle')">
    <header class="swbi-ctx__bar">
      <span class="swbi-ctx__dots" aria-hidden="true"><i /><i /></span>
      <span class="swbi-mono swbi-ctx__file">profile.json</span>
      <span class="swbi-mono swbi-ctx__live"
        ><i aria-hidden="true" />{{ t('profiles.wizard.live') }}</span
      >
    </header>
    <pre
      class="swbi-ctx__pre"
      tabindex="0"
      :aria-label="t('profiles.wizard.previewHint')"
    ><code><template v-for="(line, i) in lines" :key="i"><span v-for="(tok, j) in line" :key="j" :class="`tok-${tok.kind}`">{{ tok.text }}</span>{{ '\n' }}</template></code></pre>
    <footer class="swbi-ctx__cli">
      <span class="swbi-ctx__cmd swbi-mono"
        ><span class="swbi-ctx__prompt" aria-hidden="true">$</span> {{ cli }}</span
      >
      <q-btn
        flat
        round
        dense
        size="sm"
        :icon="copied ? 'r_check' : 'r_content_copy'"
        :aria-label="t('profiles.wizard.copyCommand')"
        @click="copyCli"
      />
    </footer>
  </section>
</template>

<style scoped>
.swbi-ctx {
  overflow: hidden;
  border: 1px solid #1f2547;
  border-radius: var(--swbi-radius);
  background: #0f1222;
  color: #cbd5e1;
  box-shadow: var(--swbi-shadow-2);
}
.swbi-ctx__bar {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 40px;
  padding: 0 16px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
  font-size: 11px;
}
.swbi-ctx__dots {
  display: inline-flex;
  gap: 6px;
}
.swbi-ctx__dots i {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.12);
}
.swbi-ctx__file {
  margin-left: 4px;
  color: #94a3b8;
}
.swbi-ctx__live {
  margin-left: auto;
  color: #34d399;
  font-size: 10px;
}
.swbi-ctx__live i {
  display: inline-block;
  width: 6px;
  height: 6px;
  margin-right: 4px;
  border-radius: 50%;
  background: currentColor;
  vertical-align: middle;
}
.swbi-ctx__pre {
  max-height: 460px;
  margin: 0;
  padding: 14px 16px;
  overflow: auto;
  font-family: var(--swbi-mono);
  font-size: 11.5px;
  line-height: 1.7;
  color: #cbd5e1;
}
.swbi-ctx__pre code {
  background: transparent;
  padding: 0;
  font-size: inherit;
  color: inherit;
}
.tok-key {
  color: #7dd3fc;
}
.tok-string {
  color: #fde68a;
}
.tok-literal {
  color: #c4b5fd;
}
.tok-number {
  color: #fdba74;
}
.swbi-ctx__cli {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 8px 8px 16px;
  border-top: 1px solid rgba(255, 255, 255, 0.06);
  color: #94a3b8;
}
.swbi-ctx__cmd {
  flex: 1;
  min-width: 0;
  overflow-x: auto;
  white-space: nowrap;
  font-size: 11px;
}
.swbi-ctx__prompt {
  color: #64748b;
}
</style>
