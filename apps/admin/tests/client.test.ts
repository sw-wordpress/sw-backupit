import { describe, expect, it, vi } from 'vitest';
import { ApiClient, ApiError, normaliseError } from '../src/api/client';

function jsonResponse(body: unknown, init: ResponseInit = {}): Response {
  return new Response(body === null ? null : JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
    ...init,
  });
}

function makeClient(fetchImpl: typeof fetch): ApiClient {
  return new ApiClient({ restRoot: 'https://x.test/wp-json/swbackupit/v1/', nonce: 'abc', fetchImpl });
}

describe('ApiClient', () => {
  it('sends nonce, same-origin credentials and JSON body', async () => {
    const fetchMock = vi.fn(async () => jsonResponse({ id: 1 }));
    const client = makeClient(fetchMock as unknown as typeof fetch);
    const result = await client.post<{ id: number }>('profiles', { name: 'A' });
    expect(result).toEqual({ id: 1 });
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe('https://x.test/wp-json/swbackupit/v1/profiles');
    expect(init.method).toBe('POST');
    expect(init.credentials).toBe('same-origin');
    expect((init.headers as Record<string, string>)['X-WP-Nonce']).toBe('abc');
    expect((init.headers as Record<string, string>)['Content-Type']).toBe('application/json');
    expect(init.body).toBe('{"name":"A"}');
  });

  it('builds query strings, skipping empty values', () => {
    const client = makeClient(vi.fn() as unknown as typeof fetch);
    expect(client.url('/backups', { page: 2, search: '', type: undefined, status: 'verified' })).toBe(
      'https://x.test/wp-json/swbackupit/v1/backups?page=2&status=verified',
    );
  });

  it('appends to a root that already has a query (plain permalinks)', () => {
    const client = new ApiClient({ restRoot: 'https://x.test/?rest_route=/swbackupit/v1/', nonce: 'n' });
    expect(client.url('jobs', { page: 1 })).toBe('https://x.test/?rest_route=/swbackupit/v1/jobs&page=1');
  });

  it('adds the nonce as _wpnonce for download links', () => {
    const client = makeClient(vi.fn() as unknown as typeof fetch);
    expect(client.authenticatedUrl('backups/1/download', { volume: 2 })).toContain('_wpnonce=abc');
  });

  it('normalises WP_Error bodies into ApiError', async () => {
    const body = { code: 'swbi_invalid_param', message: 'Invalid', data: { status: 400, params: { name: 'Required' } } };
    const client = makeClient((async () => jsonResponse(body, { status: 400 })) as unknown as typeof fetch);
    const error = await client.get('profiles').catch((e: unknown) => e);
    expect(error).toBeInstanceOf(ApiError);
    const api = error as ApiError;
    expect(api.code).toBe('swbi_invalid_param');
    expect(api.status).toBe(400);
    expect(api.params).toEqual({ name: 'Required' });
    expect(api.message).toBe('Invalid');
  });

  it('handles non-JSON error responses', async () => {
    const client = makeClient((async () => new Response('<html>boom</html>', { status: 502 })) as unknown as typeof fetch);
    const error = (await client.get('health').catch((e: unknown) => e)) as ApiError;
    expect(error.code).toBe('swbi_http_502');
    expect(error.status).toBe(502);
  });

  it('maps network failures and aborts', async () => {
    const down = makeClient((async () => {
      throw new TypeError('failed');
    }) as unknown as typeof fetch);
    expect(((await down.get('health').catch((e: unknown) => e)) as ApiError).code).toBe('swbi_network');

    const aborted = makeClient((async () => {
      throw new DOMException('x', 'AbortError');
    }) as unknown as typeof fetch);
    const err = (await aborted.get('health').catch((e: unknown) => e)) as ApiError;
    expect(err.isAborted).toBe(true);
  });

  it('adopts a refreshed nonce from the response header', async () => {
    let call = 0;
    const fetchMock = vi.fn(async () => {
      call += 1;
      return jsonResponse({}, call === 1 ? { headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': 'fresh' } } : {});
    });
    const client = makeClient(fetchMock as unknown as typeof fetch);
    await client.get('health');
    await client.get('health');
    const second = fetchMock.mock.calls[1] as unknown as [string, RequestInit];
    expect((second[1].headers as Record<string, string>)['X-WP-Nonce']).toBe('fresh');
  });

  it('returns null for empty 204 bodies', async () => {
    const client = makeClient((async () => new Response(null, { status: 204 })) as unknown as typeof fetch);
    expect(await client.delete('profiles/1', { confirm: true })).toBeNull();
  });
});

describe('normaliseError', () => {
  it('falls back to the HTTP status for unknown bodies', () => {
    const e = normaliseError(500, { unexpected: true });
    expect(e.code).toBe('swbi_http_500');
  });
});
