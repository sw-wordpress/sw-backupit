export type QueryValue = string | number | boolean | null | undefined;
export type Query = Record<string, QueryValue>;

/** Normalised error for every failed request (HTTP, WP_Error or network). */
export class ApiError extends Error {
  readonly code: string;
  readonly status: number;
  readonly params: Record<string, string>;

  constructor(code: string, message: string, status: number, params: Record<string, string> = {}) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
    this.params = params;
  }

  get isAborted(): boolean {
    return this.code === 'swbi_aborted';
  }
}

export function isApiError(value: unknown): value is ApiError {
  return value instanceof ApiError;
}

export interface RequestOptions {
  query?: Query;
  body?: unknown;
  signal?: AbortSignal;
}

export interface ClientOptions {
  restRoot: string;
  nonce: string;
  fetchImpl?: typeof fetch;
}

interface WpErrorBody {
  code?: unknown;
  message?: unknown;
  data?: { status?: unknown; params?: unknown } | null;
}

function toParams(value: unknown): Record<string, string> {
  const out: Record<string, string> = {};
  if (value && typeof value === 'object') {
    for (const [k, v] of Object.entries(value as Record<string, unknown>)) {
      out[k] = typeof v === 'string' ? v : JSON.stringify(v);
    }
  }
  return out;
}

/** Turn any non-OK response body into an ApiError. Exported for tests. */
export function normaliseError(status: number, body: unknown): ApiError {
  if (body && typeof body === 'object') {
    const b = body as WpErrorBody;
    if (typeof b.code === 'string' && typeof b.message === 'string') {
      const dataStatus = typeof b.data?.status === 'number' ? b.data.status : status;
      return new ApiError(b.code, b.message, dataStatus, toParams(b.data?.params));
    }
  }
  return new ApiError(`swbi_http_${status}`, `HTTP ${status}`, status);
}

/**
 * The single HTTP gateway of the app. Adds the REST nonce, sends cookies, speaks JSON and
 * normalises errors. Components must never call `fetch` directly.
 */
export class ApiClient {
  private readonly root: string;
  private nonce: string;
  private readonly fetchImpl: typeof fetch;

  constructor(options: ClientOptions) {
    this.root = options.restRoot.endsWith('/') ? options.restRoot : `${options.restRoot}/`;
    this.nonce = options.nonce;
    this.fetchImpl = options.fetchImpl ?? ((...args) => fetch(...args));
  }

  /** Absolute URL for a resource path (no leading slash) incl. query string. */
  url(path: string, query?: Query): string {
    const qs = new URLSearchParams();
    for (const [key, value] of Object.entries(query ?? {})) {
      if (value === undefined || value === null || value === '') continue;
      qs.set(key, String(value));
    }
    const rel = path.replace(/^\/+/, '');
    const base = `${this.root}${rel}`;
    const str = qs.toString();
    if (str === '') return base;
    // WP without pretty permalinks uses `?rest_route=`, so the root may already contain `?`.
    return `${base}${base.includes('?') ? '&' : '?'}${str}`;
  }

  /**
   * URL for browser-navigated downloads (anchors cannot send headers). Uses the `_wpnonce`
   * query parameter which WordPress accepts for cookie authentication.
   */
  authenticatedUrl(path: string, query?: Query): string {
    return this.url(path, { ...query, _wpnonce: this.nonce });
  }

  async request<T>(method: string, path: string, options: RequestOptions = {}): Promise<T> {
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'X-WP-Nonce': this.nonce,
    };
    const init: RequestInit = {
      method,
      headers,
      credentials: 'same-origin',
      signal: options.signal,
    };
    if (options.body !== undefined) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(options.body);
    }

    let response: Response;
    try {
      response = await this.fetchImpl(this.url(path, options.query), init);
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') {
        throw new ApiError('swbi_aborted', 'Request aborted', 0);
      }
      throw new ApiError('swbi_network', 'Network error', 0);
    }

    const refreshed = response.headers.get('X-WP-Nonce');
    if (refreshed) this.nonce = refreshed;

    let parsed: unknown = null;
    const text = await response.text();
    if (text !== '') {
      try {
        parsed = JSON.parse(text);
      } catch {
        if (response.ok) {
          throw new ApiError('swbi_invalid_response', 'Invalid JSON response', response.status);
        }
      }
    }

    if (!response.ok) throw normaliseError(response.status, parsed);
    return parsed as T;
  }

  get<T>(path: string, query?: Query, signal?: AbortSignal): Promise<T> {
    return this.request<T>('GET', path, { query, signal });
  }
  post<T>(path: string, body?: unknown, options: Omit<RequestOptions, 'body'> = {}): Promise<T> {
    return this.request<T>('POST', path, { ...options, body: body ?? {} });
  }
  put<T>(path: string, body: unknown, options: Omit<RequestOptions, 'body'> = {}): Promise<T> {
    return this.request<T>('PUT', path, { ...options, body });
  }
  delete<T>(path: string, query?: Query): Promise<T> {
    return this.request<T>('DELETE', path, { query });
  }
}
