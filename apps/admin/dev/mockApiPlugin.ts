import type { IncomingMessage } from 'node:http';
import type { Plugin } from 'vite';
import { handle } from './mockApi';

const PREFIX = '/wp-json/sw-backupit/v1/';

function readBody(req: IncomingMessage): Promise<Record<string, unknown>> {
  return new Promise((resolve) => {
    const chunks: Buffer[] = [];
    req.on('data', (c: Buffer) => chunks.push(c));
    req.on('end', () => {
      try {
        const text = Buffer.concat(chunks).toString('utf8');
        resolve(text === '' ? {} : (JSON.parse(text) as Record<string, unknown>));
      } catch {
        resolve({});
      }
    });
  });
}

/** Dev-only middleware implementing the REST contract in memory (see dev/mockApi.ts). */
export function mockApiPlugin(): Plugin {
  return {
    name: 'swbi-mock-api',
    apply: 'serve',
    configureServer(server) {
      server.middlewares.use(async (req, res, next) => {
        const url = req.url ?? '';
        if (!url.startsWith(PREFIX)) return next();
        const parsed = new URL(url, 'http://localhost');
        const nonce = req.headers['x-wp-nonce'];
        const isDownload = parsed.pathname.includes('/download');
        if (!isDownload && nonce !== 'dev-nonce') {
          res.statusCode = 403;
          res.setHeader('Content-Type', 'application/json');
          res.end(
            JSON.stringify({
              code: 'rest_cookie_invalid_nonce',
              message: 'Cookie check failed',
              data: { status: 403 },
            }),
          );
          return;
        }
        const body = req.method === 'GET' || req.method === 'DELETE' ? {} : await readBody(req);
        await new Promise((r) => setTimeout(r, 60 + Math.random() * 140));
        const result = handle({
          method: req.method ?? 'GET',
          path: parsed.pathname.slice(PREFIX.length),
          query: parsed.searchParams,
          body,
        });
        res.statusCode = result.status;
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify(result.body));
      });
    },
  };
}
