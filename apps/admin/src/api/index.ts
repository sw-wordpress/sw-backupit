import { getConfig } from '@/config';
import { ApiClient } from './client';
import type { Query } from './client';
import type {
  ArchiveEntry,
  AuditEntry,
  RestoreTestResult,
  Backup,
  BackupFilters,
  CompareMode,
  CompareResult,
  CreatedEncryptionKey,
  DownloadPart,
  EncryptionKey,
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
  Transfer,
  TransferInput,
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
      /** `storageTargetId`: destination for this run only (0 = local staging only, undefined = profile targets). */
      run: (id: number, storageTargetId?: number) =>
        client.post<Job>(
          `profiles/${id}/run`,
          storageTargetId === undefined ? undefined : { storage_target_id: storageTargetId },
        ),
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
      retry: (id: string) => client.post<Job>(`jobs/${id}/retry`),
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
      restoreTest: (id: string) => client.post<RestoreTestResult>(`backups/${id}/restore-test`),
      compare: (id: string, mode: CompareMode) =>
        client.post<CompareResult>(`backups/${id}/compare`, { mode }),
      downloadUrl: (id: string, part: DownloadPart, volume?: number) =>
        client.authenticatedUrl(`backups/${id}/download`, { part, volume }),
      packageUrl: (id: string) => client.authenticatedUrl(`backups/${id}/package`),
      restorePrepare: (id: string) =>
        client.post<RestorePrepareResult>(`backups/${id}/restore/prepare`),
      transfer: (id: string, input: TransferInput) =>
        client.post<Transfer>(`backups/${id}/transfer`, input),
    },
    transfers: {
      list: (signal?: AbortSignal) => client.get<Transfer[]>('transfers', undefined, signal),
      cancel: (id: string) => client.post<{ cancelled: boolean }>(`transfers/${id}/cancel`),
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
      import: (id: number, backupIds: string[], download: boolean) =>
        client.post<{ imported: number }>(`storage-targets/${id}/import`, {
          backup_ids: backupIds,
          download,
        }),
    },
    audit: {
      list: (q: PageQuery & { action?: string; object_type?: string; user_id?: number } = {}) =>
        client.get<Paginated<AuditEntry>>('audit', q as Query),
    },
    keys: {
      list: () => client.get<{ items: EncryptionKey[] }>('encryption-keys'),
      create: (id: string, passphrase?: string) =>
        client.post<CreatedEncryptionKey>('encryption-keys', {
          id,
          ...(passphrase ? { passphrase } : {}),
        }),
      remove: (id: string) => client.delete<void>(`encryption-keys/${encodeURIComponent(id)}`),
    },
    settings: {
      get: () => client.get<Settings>('settings'),
      /** Sends only the given groups (top-level keys). */
      update: (input: Partial<Settings>) => client.put<Settings>('settings', input),
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
