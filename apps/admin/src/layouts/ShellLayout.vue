<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { getConfig } from '@/config';
import { useCapabilities } from '@/composables/useCapabilities';
import { NAV_ITEMS } from '@/router';
import { useAppStore } from '@/stores/app';
import { useJobsStore } from '@/stores/jobs';
import NoAccess from '@/layouts/NoAccess.vue';

const { t } = useI18n();
const route = useRoute();
const app = useAppStore();
const jobs = useJobsStore();
const { can } = useCapabilities();
const config = getConfig();
const main = ref<HTMLElement | null>(null);
const allowed = computed(() => can('swbi_view'));

onMounted(() => {
  if (allowed.value) jobs.subscribe();
});
onBeforeUnmount(() => {
  if (allowed.value) jobs.unsubscribe();
});

function focusMain(): void {
  main.value?.focus();
}

const transitionKey = computed(() => String(route.name ?? ''));
</script>

<template>
  <div class="swbi-shell" :class="{ 'swbi-shell--collapsed': app.railCollapsed }">
    <a href="#swbi-main" class="swbi-skip" @click.prevent="focusMain">{{ t('app.skipToContent') }}</a>

    <aside class="swbi-rail">
      <div class="swbi-rail__brand">
        <span class="swbi-rail__logo" aria-hidden="true"><q-icon name="r_shield" size="22px" /></span>
        <span class="swbi-rail__name">
          <strong>SWBackupIt</strong>
          <small v-if="config.version" class="swbi-num">v{{ config.version }}</small>
        </span>
      </div>

      <nav v-if="allowed" class="swbi-rail__nav" :aria-label="t('app.mainNavigation')">
        <ul>
          <li v-for="item in NAV_ITEMS" :key="item.name">
            <router-link :to="item.to" class="swbi-nav-link" :active-class="item.to === '/' ? '' : 'swbi-nav-link--active'" exact-active-class="swbi-nav-link--active">
              <q-icon :name="item.icon" size="22px" aria-hidden="true" />
              <span class="swbi-nav-link__label">{{ t(item.labelKey) }}</span>
              <span
                v-if="item.name === 'jobs' && jobs.count > 0"
                class="swbi-nav-link__badge swbi-num"
                :aria-label="t('jobs.runningCount', { n: jobs.count }, jobs.count)"
              >{{ jobs.count }}</span>
              <q-tooltip v-if="app.railCollapsed" anchor="center right" self="center left" :offset="[8, 0]">{{ t(item.labelKey) }}</q-tooltip>
            </router-link>
          </li>
        </ul>
      </nav>

      <div class="swbi-rail__tools">
        <q-btn
          flat
          round
          dense
          :icon="app.isDark ? 'r_light_mode' : 'r_dark_mode'"
          :aria-label="app.isDark ? t('app.switchToLight') : t('app.switchToDark')"
          :aria-pressed="app.isDark"
          @click="app.toggleTheme()"
        >
          <q-tooltip>{{ app.isDark ? t('app.switchToLight') : t('app.switchToDark') }}</q-tooltip>
        </q-btn>
        <q-btn
          class="swbi-rail__collapse"
          flat
          round
          dense
          :icon="app.railCollapsed ? 'r_keyboard_double_arrow_right' : 'r_keyboard_double_arrow_left'"
          :aria-label="app.railCollapsed ? t('app.expandNav') : t('app.collapseNav')"
          :aria-expanded="!app.railCollapsed"
          @click="app.railCollapsed = !app.railCollapsed"
        />
      </div>
    </aside>

    <main id="swbi-main" ref="main" class="swbi-main" tabindex="-1">
      <NoAccess v-if="!allowed" />
      <router-view v-else v-slot="{ Component }">
        <transition name="swbi-fade" mode="out-in">
          <component :is="Component" :key="transitionKey" />
        </transition>
      </router-view>
    </main>

    <div class="swbi-sr-only" role="status" aria-live="polite" aria-atomic="true">{{ app.announcement }}</div>
  </div>
</template>

<style scoped>
.swbi-shell {
  --rail-w: 244px;
  --bar-h: var(--wp-admin--admin-bar--height, 32px);
  display: grid;
  grid-template-columns: var(--rail-w) minmax(0, 1fr);
  min-height: calc(100vh - var(--bar-h));
  background: var(--swbi-bg);
  position: relative;
}
.swbi-shell--collapsed {
  --rail-w: 76px;
}
.swbi-skip {
  position: absolute;
  left: 12px;
  top: -60px;
  z-index: 100;
  padding: 8px 14px;
  border-radius: 8px;
  background: var(--swbi-primary);
  color: #fff !important;
  font-weight: 600;
}
.swbi-skip:focus {
  top: 10px;
}
.swbi-rail {
  position: sticky;
  top: var(--bar-h);
  align-self: start;
  height: calc(100vh - var(--bar-h));
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 18px 14px;
  background: var(--swbi-rail-bg);
  border-right: 1px solid var(--swbi-border);
  transition: width 0.2s ease;
}
.swbi-rail__brand {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 4px 8px 16px;
  min-width: 0;
}
.swbi-rail__logo {
  display: grid;
  place-items: center;
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: 12px;
  color: #fff;
  background: var(--swbi-gradient);
  box-shadow: 0 6px 16px rgba(79, 70, 229, 0.35);
}
.swbi-rail__name {
  display: flex;
  flex-direction: column;
  line-height: 1.2;
  min-width: 0;
}
.swbi-rail__name strong {
  font-size: 16px;
  letter-spacing: -0.01em;
}
.swbi-rail__name small {
  color: var(--swbi-muted);
  font-size: 12px;
}
.swbi-rail__nav {
  flex: 1;
  overflow-y: auto;
}
.swbi-rail__nav ul {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.swbi-nav-link {
  position: relative;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border-radius: 12px;
  color: var(--swbi-muted) !important;
  font-weight: 600;
  text-decoration: none !important;
  transition:
    background-color 0.15s ease,
    color 0.15s ease;
}
.swbi-nav-link:hover {
  background: var(--swbi-surface-3);
  color: var(--swbi-text) !important;
}
.swbi-nav-link--active {
  background: var(--swbi-primary-bg);
  color: var(--swbi-primary-text) !important;
}
.swbi-nav-link--active::before {
  content: '';
  position: absolute;
  left: -14px;
  top: 10px;
  bottom: 10px;
  width: 4px;
  border-radius: 0 4px 4px 0;
  background: var(--swbi-gradient);
}
.swbi-nav-link__badge {
  margin-left: auto;
  min-width: 22px;
  padding: 0 7px;
  border-radius: 999px;
  text-align: center;
  font-size: 12px;
  line-height: 22px;
  color: #fff;
  background: var(--swbi-gradient);
}
.swbi-rail__tools {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 4px 0;
  border-top: 1px solid var(--swbi-border);
  color: var(--swbi-muted);
}
.swbi-shell--collapsed .swbi-rail__name,
.swbi-shell--collapsed .swbi-nav-link__label {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
}
.swbi-shell--collapsed .swbi-nav-link {
  justify-content: center;
}
.swbi-shell--collapsed .swbi-nav-link__badge {
  position: absolute;
  top: 2px;
  right: 2px;
  margin: 0;
  min-width: 18px;
  line-height: 18px;
  font-size: 11px;
  padding: 0 5px;
}
.swbi-shell--collapsed .swbi-rail__tools {
  flex-direction: column;
  gap: 4px;
}
.swbi-shell--collapsed .swbi-rail__brand {
  justify-content: center;
  padding-inline: 0;
}
.swbi-main {
  min-width: 0;
  padding: 28px clamp(16px, 3vw, 40px) 48px;
  outline: none;
}
.swbi-main > * {
  max-width: 1320px;
  margin-inline: auto;
}

@media (max-width: 860px) {
  .swbi-shell,
  .swbi-shell--collapsed {
    grid-template-columns: minmax(0, 1fr);
    grid-template-rows: auto 1fr;
  }
  .swbi-rail {
    position: sticky;
    z-index: 20;
    height: auto;
    flex-direction: row;
    align-items: center;
    gap: 6px;
    padding: 8px 10px;
    border-right: 0;
    border-bottom: 1px solid var(--swbi-border);
  }
  .swbi-rail__brand {
    padding: 0;
  }
  .swbi-rail__name {
    display: none;
  }
  .swbi-rail__nav {
    flex: 1;
    min-width: 0;
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: thin;
  }
  .swbi-rail__nav ul {
    flex-direction: row;
  }
  .swbi-nav-link {
    flex-direction: column;
    gap: 2px;
    padding: 6px 10px;
    font-size: 11px;
  }
  .swbi-shell--collapsed .swbi-nav-link__label {
    position: static;
    width: auto;
    height: auto;
    clip: auto;
  }
  .swbi-nav-link--active::before {
    left: 10px;
    right: 10px;
    top: auto;
    bottom: 0;
    width: auto;
    height: 3px;
    border-radius: 3px 3px 0 0;
  }
  .swbi-nav-link__badge {
    position: absolute;
    top: 0;
    right: 2px;
    margin: 0;
    min-width: 18px;
    line-height: 18px;
    font-size: 11px;
    padding: 0 5px;
  }
  .swbi-rail__tools {
    border-top: 0;
    padding: 0;
    flex-direction: row;
  }
  .swbi-rail__collapse {
    display: none;
  }
  .swbi-main {
    padding-top: 20px;
  }
}
</style>
