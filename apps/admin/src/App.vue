<script setup lang="ts">
import { useThemeSync } from '@/composables/useTheme';
import ShellLayout from '@/layouts/ShellLayout.vue';

useThemeSync(() => document.getElementById('sw-backupit-app'));
</script>

<template>
  <ShellLayout />
</template>
