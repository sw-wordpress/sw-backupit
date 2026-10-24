import { describe, expect, it, vi } from 'vitest';
import { createApi } from '../src/api';
import { ApiClient } from '../src/api/client';

function setup() {
  const fetchMock = vi.fn(
    async () =>
      new Response(JSON.stringify({ id: 'x' }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
  );
  const api = createApi(
    new ApiClient({
      restRoot: 'https://x.test/wp-json/sw-backupit/v1/',
      nonce: 'n',
      fetchImpl: fetchMock as unknown as typeof fetch,
    }),
  );
  const call = (i: number) => fetchMock.mock.calls[i] as unknown as [string, RequestInit];
  return { api, call };
}

describe('run with another destination and backup transfers', () => {
  it('sends the destination of a single run only when one is chosen', async () => {
    const { api, call } = setup();
    await api.profiles.run(3);
    await api.profiles.run(3, 0);
    await api.profiles.run(3, 7);
    expect(call(0)[1].body).toBe('{}');
    expect(call(1)[1].body).toBe('{"storage_target_id":0}');
    expect(call(2)[1].body).toBe('{"storage_target_id":7}');
  });

  it('queues, lists and cancels transfers', async () => {
    const { api, call } = setup();
    await api.backups.transfer('b1', { target_id: 2, source_id: null, move: true });
    await api.transfers.list();
    await api.transfers.cancel('abc123abc123');
    expect(call(0)[0]).toBe('https://x.test/wp-json/sw-backupit/v1/backups/b1/transfer');
    expect(call(0)[1].body).toBe('{"target_id":2,"source_id":null,"move":true}');
    expect(call(1)[0]).toBe('https://x.test/wp-json/sw-backupit/v1/transfers');
    expect(call(2)[0]).toBe('https://x.test/wp-json/sw-backupit/v1/transfers/abc123abc123/cancel');
  });
});
