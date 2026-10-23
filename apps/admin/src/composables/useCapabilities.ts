import { getConfig } from '@/config';
import type { Capability } from '@/types/config';

/** UX-only capability gate. The server remains authoritative. */
export function useCapabilities() {
  const caps = new Set(getConfig().capabilities);
  return {
    can: (capability: Capability): boolean => caps.has(capability),
  };
}
