import { getConfig } from '@/config';
import { ApiClient } from './client';
import type { Query } from './client';
import type {
  ArchiveEntry,
  Backup,
  BackupFilters,
  CompareMode,
  CompareResult,
  DiscoveredBackup,
  HealthReport,
  Job,
  JobFilter,
  JobLogPage,
  Paginated,
  Profile,
  ProfileInput,
  RestorePrepareResult,
  Schedule,
  ScheduleInput,
  Settings,
  Severity,
  StorageTarget,
  StorageTargetInput,
  StorageTestResult,
  VerifyResult,
} from '@/types/api';

export interface PageQuery {
  page?: number;
  per_page?: number;
}

/** Typed per-resource API. Build once from a client; `useApi()` provides the app singleton. */
export function createApi(client: ApiClient) {
  return {
    client,
    health: {
      get: (signal?: AbortSignal) => client.get<HealthReport>('health', undefined, signal),
    },
    profiles: {
      list: (q: PageQuery = {}) => client.get<Paginated<Profile>>('profiles', q as Query),
      get: (id: number) => client.get<Profile>(`profiles/${id}`),
      create: (input: ProfileInput) => client.post<Profile>('profiles', input),
      update: (id: number, input: ProfileInput) => client.put<Profile>(`profiles/${id}`, input),
      remove: (id: number) => client.delete<void>(`profiles/${id}`, { confirm: true }),
      duplicate: (id: number) => client.post<Profile>(`profiles/${id}/duplicate`),
      run: (id: number) => client.post<Job>(`profiles/${id}/run`),
    },
    schedules: {
      list: (q: PageQuery = {}) => client.get<Paginated<Schedule>>('schedules', q as Query),
      create: (input: ScheduleInput) => client.post<Schedule>('schedules', input),
      update: (id: number, input: ScheduleInput) => client.put<Schedule>(`schedules/${id}`, input),
      remove: (id: number) => client.delete<void>(`schedules/${id}`),
      run: (id: number) => client.post<Job>(`schedules/${id}/run`),
    },
    jobs: {
      list: (q: PageQuery & { status?: JobFilter } = {}, signal?: AbortSignal) =>
        client.get<Paginated<Job>>('jobs', q as Query, signal),
      get: (id: string, signal?: AbortSignal) => client.get<Job>(`jobs/${id}`, undefined, signal),
      pause: (id: string) => client.post<Job>(`jobs/${id}/pause`),
      resume: (id: string) => client.post<Job>(`jobs/${id}/resume`),
      cancel: (id: string) => client.post<Job>(`jobs/${id}/cancel`),
      tick: (id: string, signal?: AbortSignal) =>
        client.post<Job>(`jobs/${id}/tick`, {}, { signal }),
      logs: (
        id: string,
        q: { after?: number; severity?: Severity; phase?: string; limit?: number } = {},
        signal?: AbortSignal,
      ) => client.get<JobLogPage>(`jobs/${id}/logs`, q as Query, signal),
    },
    backups: {
      list: (q: BackupFilters = {}) => client.get<Paginated<Backup>>('backups', q as Query),
      get: (id: string) => client.get<Backup>(`backups/${id}`),
      remove: (id: string) => client.delete<void>(`backups/${id}`, { confirm: id }),
      verify: (id: string) => client.post<VerifyResult>(`backups/${id}/verify`),
      protect: (id: string, value: boolean) =>
        client.post<Backup>(`backups/${id}/protect`, { protected: value }),
      files: (
        id: string,
        q: PageQuery & { path?: string; search?: string },
        signal?: AbortSignal,
      ) => client.get<Paginated<ArchiveEntry>>(`backups/${id}/files`, q as Query, signal),
      compare: (id: string, mode: CompareMode) =>
        client.post<CompareResult>(`backups/${id}/compare`, { mode }),
      downloadUrl: (id: string, volume: number) =>
        client.authenticatedUrl(`backups/${id}/download`, { volume }),
      restorePrepare: (id: string) =>
        client.post<RestorePrepareResult>(`backups/${id}/restore/prepare`),
      restore: (id: string) =>
        client.post<Job>(`backups/${id}/restore`, { confirm: id, target_path_confirmed: true }),
    },
    storage: {
      list: (q: PageQuery = {}) =>
        client.get<Paginated<StorageTarget>>('storage-targets', q as Query),
      create: (input: StorageTargetInput) => client.post<StorageTarget>('storage-targets', input),
      update: (id: number, input: StorageTargetInput) =>
        client.put<StorageTarget>(`storage-targets/${id}`, input),
      remove: (id: number) => client.delete<void>(`storage-targets/${id}`),
      test: (id: number) => client.post<StorageTestResult>(`storage-targets/${id}/test`),
      /** Response shape is not specified in the contract: accepts a list envelope or a bare array. */
      discover: async (id: number): Promise<DiscoveredBackup[]> => {
        const res = await client.get<Paginated<DiscoveredBackup> | DiscoveredBackup[]>(
          `storage-targets/${id}/discover`,
        );
        return Array.isArray(res) ? res : res.items;
      },
      /** Request/response shape assumed: `{ backup_ids: string[] }` -> `{ imported: number }`. */
      import: (id: number, backupIds: string[]) =>
        client.post<{ imported: number }>(`storage-targets/${id}/import`, {
          backup_ids: backupIds,
        }),
    },
    settings: {
      get: () => client.get<Settings>('settings'),
      update: (input: Settings) => client.put<Settings>('settings', input),
    },
  };
}

export type Api = ReturnType<typeof createApi>;

let instance: Api | null = null;

export function useApi(): Api {
  if (instance === null) {
    const cfg = getConfig();
    instance = createApi(new ApiClient({ restRoot: cfg.restRoot, nonce: cfg.nonce }));
  }
  return instance;
}
