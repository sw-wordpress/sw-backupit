import { createRouter, createWebHashHistory } from 'vue-router';
import type { RouteRecordRaw } from 'vue-router';
import type { Capability } from '@/types/config';

export interface NavItem {
  name: string;
  to: string;
  icon: string;
  labelKey: string;
  /** UX-only: hide the entry without this capability. */
  cap?: Capability;
}

export const NAV_ITEMS: NavItem[] = [
  { name: 'dashboard', to: '/', icon: 'r_space_dashboard', labelKey: 'nav.dashboard' },
  { name: 'backups', to: '/backups', icon: 'r_inventory_2', labelKey: 'nav.backups' },
  { name: 'jobs', to: '/jobs', icon: 'r_sync', labelKey: 'nav.jobs' },
  { name: 'profiles', to: '/profiles', icon: 'r_tune', labelKey: 'nav.profiles' },
  { name: 'schedules', to: '/schedules', icon: 'r_calendar_month', labelKey: 'nav.schedules' },
  { name: 'storage', to: '/storage', icon: 'r_cloud', labelKey: 'nav.storage' },
  { name: 'audit', to: '/audit', icon: 'r_history', labelKey: 'nav.audit', cap: 'swbi_settings' },
  { name: 'settings', to: '/settings', icon: 'r_settings', labelKey: 'nav.settings' },
];

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'dashboard',
    component: () => import('@/features/dashboard/DashboardView.vue'),
  },
  {
    path: '/profiles',
    name: 'profiles',
    component: () => import('@/features/profiles/ProfilesView.vue'),
  },
  {
    path: '/schedules',
    name: 'schedules',
    component: () => import('@/features/schedules/SchedulesView.vue'),
  },
  {
    path: '/backups/:id?',
    name: 'backups',
    component: () => import('@/features/backups/BackupsView.vue'),
    props: true,
  },
  { path: '/jobs', name: 'jobs', component: () => import('@/features/jobs/JobsView.vue') },
  {
    path: '/jobs/:id',
    name: 'job',
    component: () => import('@/features/jobs/JobDetailView.vue'),
    props: true,
  },
  {
    path: '/storage',
    name: 'storage',
    component: () => import('@/features/storage/StorageView.vue'),
  },
  { path: '/audit', name: 'audit', component: () => import('@/features/audit/AuditView.vue') },
  {
    path: '/settings',
    name: 'settings',
    component: () => import('@/features/settings/SettingsView.vue'),
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
];

export function createAppRouter() {
  return createRouter({
    history: createWebHashHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
  });
}
