import { shallowRef } from 'vue';
import { defineStore } from 'pinia';
import { useApi } from '@/api';
import type { Profile, StorageTarget } from '@/types/api';

/** Small cached lists used by selects (profile and storage-target pickers). */
export const useLookupsStore = defineStore('lookups', () => {
  const profiles = shallowRef<Profile[]>([]);
  const targets = shallowRef<StorageTarget[]>([]);
  let profilesLoaded = false;
  let targetsLoaded = false;

  async function loadProfiles(force = false): Promise<Profile[]> {
    if (!profilesLoaded || force) {
      const res = await useApi().profiles.list({ per_page: 100 });
      profiles.value = res.items;
      profilesLoaded = true;
    }
    return profiles.value;
  }
  async function loadTargets(force = false): Promise<StorageTarget[]> {
    if (!targetsLoaded || force) {
      const res = await useApi().storage.list({ per_page: 100 });
      targets.value = res.items;
      targetsLoaded = true;
    }
    return targets.value;
  }
  function invalidate(): void {
    profilesLoaded = false;
    targetsLoaded = false;
  }

  return { profiles, targets, loadProfiles, loadTargets, invalidate };
});
