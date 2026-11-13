import { promises as fs } from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { createServer } from 'vite';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { createAuthProxy } from './auth-proxy';
import { createWorkbenchPlugin } from './create-workbench-plugin';

import type { AddressInfo } from 'node:net';
import type { ViteDevServer } from 'vite';
import type { WorkbenchPaths } from './paths';

let root: string;
let server: ViteDevServer;
let httpServer: http.Server;
let drupal: http.Server;
let drupalRequests: Array<{ method?: string; url?: string; auth?: string }>;

const url = (server: http.Server, pathname: string) =>
  `http://127.0.0.1:${(server.address() as AddressInfo).port}${pathname}`;

const listen = (server: http.Server) =>
  new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));

const close = (server: http.Server | undefined) =>
  new Promise<void>((resolve) =>
    server ? server.close(() => resolve()) : resolve(),
  );

beforeEach(async () => {
  root = await fs.realpath(
    await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-workbench-routing-')),
  );
  const clientRoot = path.join(root, 'client');
  await fs.mkdir(path.join(root, 'src/components'), { recursive: true });
  await fs.mkdir(clientRoot, { recursive: true });
  await fs.writeFile(
    path.join(clientRoot, 'index.html'),
    '<!doctype html><html><body><div id="workbench"></div></body></html>',
    'utf8',
  );

  // Stands in for the Drupal site.
  drupalRequests = [];
  drupal = http.createServer((req, res) => {
    drupalRequests.push({
      method: req.method,
      url: req.url,
      auth: req.headers.authorization,
    });
    if (req.url === '/session/token') {
      res.setHeader('Content-Type', 'text/plain');
      res.end('drupal-csrf-token');
      return;
    }
    res.statusCode = 404;
    res.end();
  });
  await listen(drupal);
  const siteUrl = url(drupal, '');

  const paths: WorkbenchPaths = {
    aliasBaseRoot: path.join(root, 'src'),
    appHtmlPath: path.join(clientRoot, 'index.html'),
    allowedFsRoots: [root],
    clientRoot,
    componentDiscoveryRoot: path.join(root, 'src/components'),
    contentTemplatesDiscoveryRoot: path.join(root, 'content-templates'),
    siteUrl,
    hostProjectRoot: root,
    packageRoot: root,
    pagesDiscoveryRoot: path.join(root, 'pages'),
    pageTemplatesDiscoveryRoot: path.join(root, 'page-templates'),
    runningInsideWorkbenchPackage: true,
    watchRoots: [path.join(root, 'src/components')],
    workbenchSourceRoot: root,
  };
  const authProxyEntry = await createAuthProxy(siteUrl, {
    CANVAS_ACCESS_TOKEN: 'workbench-token',
  });
  // The proxy of the Drupal session token, as `createWorkbenchConfig()`
  // configures it.
  server = await createServer({
    configFile: false,
    root: clientRoot,
    logLevel: 'silent',
    appType: 'custom',
    server: {
      middlewareMode: true,
      proxy: { '/session/token': authProxyEntry(siteUrl) },
    },
    plugins: [createWorkbenchPlugin(paths)],
  });
  httpServer = http.createServer(server.middlewares);
  await listen(httpServer);
});

afterEach(async () => {
  await close(httpServer);
  await server?.close();
  await close(drupal);
  await fs.rm(root, { recursive: true, force: true });
});

describe('Workbench dev-server routing', () => {
  it('proxies the session token to Drupal', async () => {
    const response = await fetch(url(httpServer, '/session/token'));

    expect(response.status).toBe(200);
    expect(response.headers.get('content-type')).toBe('text/plain');
    expect(await response.text()).toBe('drupal-csrf-token');
    expect(drupalRequests).toEqual([
      {
        method: 'GET',
        url: '/session/token',
        auth: 'Bearer workbench-token',
      },
    ]);
  });

  it.each(['/', '/component/card', '/page/about', '/session/tokens'])(
    'serves the Workbench app for %s',
    async (pathname) => {
      const response = await fetch(url(httpServer, pathname));

      expect(response.status).toBe(200);
      expect(response.headers.get('content-type')).toBe('text/html');
      expect(await response.text()).toContain('<div id="workbench">');
      expect(drupalRequests).toEqual([]);
    },
  );
});
