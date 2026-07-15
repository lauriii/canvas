import { mkdir, mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { buildComponentMetadataPayload } from './component-metadata';
import { createComponentMetadataHandler } from './handler';

import type * as ServerModule from '../server';

const verifyAssertionByRedemption = vi.hoisted(() => vi.fn());

vi.mock('../server', async (importOriginal) => ({
  ...(await importOriginal<typeof ServerModule>()),
  verifyAssertionByRedemption,
}));

const ORIGIN = 'https://drupal.example';

const config = () => ({
  baseUrl: ORIGIN,
});

/**
 * A project root with one discoverable component and no manifest.
 */
async function makeProject(): Promise<string> {
  const root = await mkdtemp(path.join(tmpdir(), 'canvas-handler-test-'));
  await writeFile(
    path.join(root, 'canvas.config.json'),
    JSON.stringify({ componentDir: 'components' }),
  );
  const componentDir = path.join(root, 'components', 'hello');
  await mkdir(componentDir, { recursive: true });
  await writeFile(
    path.join(componentDir, 'component.yml'),
    ['name: Hello', 'machineName: hello', 'status: true'].join('\n'),
  );
  // A framework single-file component is a valid entry for the metadata
  // registry — the app renders it; Drupal never compiles it.
  await writeFile(path.join(componentDir, 'index.astro'), '<div></div>\n');
  return root;
}

function request(headers: Record<string, string> = {}): Request {
  return new Request('https://app.example/api/canvas/components', { headers });
}

beforeEach(() => {
  verifyAssertionByRedemption.mockReset();
});

describe('createComponentMetadataHandler', () => {
  it('demands a Bearer assertion', async () => {
    const { GET } = createComponentMetadataHandler({ config });
    const response = await GET(request());
    expect(response.status).toBe(401);
    expect(response.headers.get('WWW-Authenticate')).toBe('Bearer');
    expect(verifyAssertionByRedemption).not.toHaveBeenCalled();
  });

  it('does not expose browser CORS headers', async () => {
    const { GET } = createComponentMetadataHandler({ config });
    const response = await GET(request({ origin: 'https://browser.example' }));
    expect(response.status).toBe(401);
    expect(response.headers.get('Access-Control-Allow-Origin')).toBeNull();
  });

  it('passes a refused verification through', async () => {
    verifyAssertionByRedemption.mockResolvedValue({
      ok: false,
      status: 401,
      message: 'The assertion was refused.',
    });
    const { GET } = createComponentMetadataHandler({ config });
    const response = await GET(request({ authorization: 'Bearer bad' }));
    expect(response.status).toBe(401);
    expect(await response.json()).toMatchObject({
      error: 'invalid_assertion',
    });
  });

  it('answers the live-scanned registry in development', async () => {
    verifyAssertionByRedemption.mockResolvedValue({ ok: true });
    const projectRoot = await makeProject();
    const { GET } = createComponentMetadataHandler({
      config,
      isProduction: false,
      scanComponents: () => buildComponentMetadataPayload({ projectRoot }),
    });
    const response = await GET(request({ authorization: 'Bearer good' }));
    expect(response.status).toBe(200);
    expect(response.headers.get('Cache-Control')).toBe('no-store');
    const payload = (await response.json()) as {
      components: Array<{ machineName: string }>;
    };
    expect(payload.components.map((c) => c.machineName)).toEqual(['hello']);
  });

  it('serves an injected manifest loader in production', async () => {
    verifyAssertionByRedemption.mockResolvedValue({ ok: true });
    const manifest = {
      version: 1 as const,
      components: [],
      warnings: [],
    };
    const { GET } = createComponentMetadataHandler({
      config,
      isProduction: true,
      loadManifest: async () => manifest,
    });
    const response = await GET(request({ authorization: 'Bearer good' }));
    expect(response.status).toBe(200);
    expect(await response.json()).toEqual(manifest);
  });

  it('hard-fails when an injected manifest loader answers null', async () => {
    verifyAssertionByRedemption.mockResolvedValue({ ok: true });
    const { GET } = createComponentMetadataHandler({
      config,
      isProduction: true,
      loadManifest: async () => null,
    });
    const response = await GET(request({ authorization: 'Bearer good' }));
    expect(response.status).toBe(500);
    expect(await response.json()).toMatchObject({ error: 'manifest_missing' });
  });

  it('hard-fails on a missing production manifest', async () => {
    verifyAssertionByRedemption.mockResolvedValue({ ok: true });
    const { GET } = createComponentMetadataHandler({
      config,
      isProduction: true,
    });
    const response = await GET(request({ authorization: 'Bearer good' }));
    expect(response.status).toBe(500);
    expect(await response.json()).toMatchObject({ error: 'manifest_missing' });
  });

  it('hard-fails on a missing development scanner', async () => {
    verifyAssertionByRedemption.mockResolvedValue({ ok: true });
    const { GET } = createComponentMetadataHandler({
      config,
      isProduction: false,
    });
    const response = await GET(request({ authorization: 'Bearer good' }));
    expect(response.status).toBe(500);
    expect(await response.json()).toMatchObject({
      error: 'configuration_error',
    });
  });
});
