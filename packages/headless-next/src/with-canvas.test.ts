import { mkdtemp } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { withCanvas } from './with-canvas';

const PHASE_PRODUCTION_BUILD = 'phase-production-build';
const context = { defaultConfig: {} };

/** The catch-all rule's Content-Security-Policy, for one environment. */
async function policy(
  env: Record<string, string | undefined>,
  config = {},
): Promise<string> {
  Object.assign(process.env, env);
  const projectRoot = await mkdtemp(path.join(tmpdir(), 'canvas-with-canvas-'));
  const resolved = await withCanvas(config, { projectRoot })(
    PHASE_PRODUCTION_BUILD,
    context,
  );
  const rules = await resolved.headers!();
  return rules[0].headers[0].value;
}

beforeEach(() => {
  delete process.env.CANVAS_EDITOR_ORIGINS;
  delete process.env.CANVAS_SITE_URL;
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe('frame-ancestors', () => {
  it('admits the origin of CANVAS_SITE_URL with no other configuration', async () => {
    await expect(
      policy({ CANVAS_SITE_URL: 'https://cms.example/subdir' }),
    ).resolves.toBe("frame-ancestors 'self' https://cms.example");
  });

  it('admits every origin CANVAS_EDITOR_ORIGINS names', async () => {
    await expect(
      policy({
        CANVAS_EDITOR_ORIGINS:
          'https://cms.example, https://staging.example:8443\nhttp://localhost:8080',
      }),
    ).resolves.toBe(
      "frame-ancestors 'self' https://cms.example https://staging.example:8443 http://localhost:8080",
    );
  });

  it('takes CANVAS_EDITOR_ORIGINS over CANVAS_SITE_URL', async () => {
    await expect(
      policy({
        CANVAS_SITE_URL: 'https://internal.example',
        CANVAS_EDITOR_ORIGINS: 'https://editors.example',
      }),
    ).resolves.toBe("frame-ancestors 'self' https://editors.example");
  });

  it.each([
    ['a non-http scheme', 'javascript:alert(1)'],
    ['embedded credentials', 'https://user:pw@evil.example'],
    ['an unparseable value', 'not a url'],
    // URL parsing permits these in a host, and each one means something in a
    // policy: a wildcard widens the source, `;` ends the directive, and `,`
    // ends the policy, so a configured value could append its own directive.
    ['a wildcard subdomain', 'https://*.example.com'],
    ['a bare wildcard', 'https://*'],
    ['a directive separator', 'https://a.example;script-src'],
    ['a character no hostname carries', 'https://a_b.example'],
  ])('drops %s', async (_label, value) => {
    await expect(
      policy({ CANVAS_EDITOR_ORIGINS: `https://cms.example, ${value}` }),
    ).resolves.toBe("frame-ancestors 'self' https://cms.example");
  });

  it.each([
    ['a domain', 'https://cms.example'],
    ['a hyphenated host', 'https://site-1.ddev.site'],
    ['a port', 'http://localhost:3000'],
    ['an IPv4 literal', 'https://127.0.0.1:32991'],
    ['an IPv6 literal', 'https://[::1]:8443'],
  ])('admits %s', async (_label, value) => {
    await expect(policy({ CANVAS_EDITOR_ORIGINS: value })).resolves.toBe(
      `frame-ancestors 'self' ${value}`,
    );
  });

  it('treats a comma as a list separator, not part of a value', async () => {
    // A policy separator therefore cannot reach the host parser at all: the
    // list splits on it first, and the remainder is not an origin.
    await expect(
      policy({ CANVAS_EDITOR_ORIGINS: 'https://a.example,default-src' }),
    ).resolves.toBe("frame-ancestors 'self' https://a.example");
  });

  it('deduplicates origins that differ only below the origin', async () => {
    await expect(
      policy({
        CANVAS_EDITOR_ORIGINS: 'https://cms.example/a, https://cms.example/b',
      }),
    ).resolves.toBe("frame-ancestors 'self' https://cms.example");
  });

  it('warns and admits nobody when no origin is configured', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    await expect(policy({})).resolves.toBe("frame-ancestors 'self'");
    expect(warn).toHaveBeenCalledWith(
      expect.stringContaining('previews will be refused'),
    );
  });
});

describe("the application's own header rules", () => {
  const rule = (value: string) => ({
    async headers() {
      return [
        {
          source: '/:path*',
          headers: [{ key: 'Content-Security-Policy', value }],
        },
      ];
    },
  });

  it('gains the directive when it sets a policy without one', async () => {
    Object.assign(process.env, { CANVAS_SITE_URL: 'https://cms.example' });
    const projectRoot = await mkdtemp(
      path.join(tmpdir(), 'canvas-with-canvas-'),
    );
    const resolved = await withCanvas(rule("default-src 'self'"), {
      projectRoot,
    })(PHASE_PRODUCTION_BUILD, context);
    const rules = await resolved.headers!();
    expect(rules[1].headers[0].value).toBe(
      "default-src 'self', frame-ancestors 'self' https://cms.example",
    );
  });

  it('stays authoritative when it sets frame-ancestors itself', async () => {
    Object.assign(process.env, { CANVAS_SITE_URL: 'https://cms.example' });
    const projectRoot = await mkdtemp(
      path.join(tmpdir(), 'canvas-with-canvas-'),
    );
    const resolved = await withCanvas(rule("frame-ancestors 'none'"), {
      projectRoot,
    })(PHASE_PRODUCTION_BUILD, context);
    const rules = await resolved.headers!();
    expect(rules[1].headers[0].value).toBe("frame-ancestors 'none'");
  });
});
