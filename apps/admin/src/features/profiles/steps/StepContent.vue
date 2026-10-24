<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import TagInput from '@/components/ui/TagInput.vue';
import type { ProfileInput } from '@/types/api';
import { includesDatabase, includesFiles } from '../wizard';
import StepSection from './StepSection.vue';

const form = defineModel<ProfileInput>({ required: true });
const { t } = useI18n();
</script>

<template>
  <div>
    <StepSection :title="t('profiles.sections.paths')" :help="t('profiles.sections.pathsDesc')">
      <p v-if="!includesFiles(form.type)" class="swbi-note" role="note">
        <q-icon name="r_info" size="18px" aria-hidden="true" />
        {{ t('profiles.wizard.noFilesForType') }}
      </p>
      <template v-else>
        <p class="swbi-note" role="note">
          <q-icon name="r_info" size="18px" aria-hidden="true" />
          {{ t('profiles.wizard.pathsDefault') }}
        </p>
        <TagInput
          v-model="form.include_paths"
          :label="t('profiles.includePaths')"
          :hint="t('profiles.pathsHint')"
        />
        <TagInput v-model="form.exclude_paths" :label="t('profiles.excludePaths')" />
        <TagInput
          v-model="form.external_paths"
          :label="t('profiles.externalPaths')"
          :hint="t('profiles.externalPathsHint')"
        />
      </template>
    </StepSection>
    <StepSection
      v-if="includesFiles(form.type)"
      :title="t('profiles.sections.patterns')"
      :help="t('profiles.sections.patternsDesc')"
    >
      <TagInput
        v-model="form.include_patterns"
        :label="t('profiles.includePatterns')"
        placeholder="**/*.php"
      />
      <TagInput
        v-model="form.exclude_patterns"
        :label="t('profiles.excludePatterns')"
        placeholder="**/*.log"
      />
    </StepSection>
    <StepSection
      :title="t('profiles.sections.database')"
      :help="t('profiles.sections.databaseDesc')"
    >
      <p v-if="!includesDatabase(form.type)" class="swbi-note" role="note">
        <q-icon name="r_info" size="18px" aria-hidden="true" />
        {{ t('profiles.wizard.noDbForType') }}
      </p>
      <template v-else>
        <TagInput
          v-model="form.db_tables_include"
          :label="t('profiles.tablesInclude')"
          :hint="t('profiles.tablesIncludeHint')"
        />
        <TagInput v-model="form.db_tables_exclude" :label="t('profiles.tablesExclude')" />
        <q-toggle v-model="form.db_include_foreign_tables" :label="t('profiles.foreignTables')" />
        <q-toggle v-model="form.db_consistent_snapshot" :label="t('profiles.consistentSnapshot')" />
        <p class="swbi-note" role="note">
          <q-icon name="r_info" size="18px" aria-hidden="true" />
          {{ t('profiles.consistentSnapshotInfo') }}
        </p>
      </template>
    </StepSection>
  </div>
</template>

<style scoped>
.swbi-note {
  display: flex;
  gap: 8px;
  padding: 10px 14px;
  border-radius: var(--swbi-radius-sm);
  background: var(--swbi-info-bg);
  color: var(--swbi-info-text);
  font-size: 13.5px;
}
</style>
