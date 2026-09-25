// @cspell:ignore Fuser fother cother Fquery
import { describe, expect, it } from 'vitest';

import {
  isSafeProxyPath,
  mapJsonApiRequestToProxy,
  mapProxyPathToUpstream,
  normalizeBaseUrl,
  resolveJsonApiProxyPath,
  toBackendRelativePath,
} from './jsonapi-proxy';

describe('proxy path resolution', () => {
  it('accepts JSON:API and router paths with optional language prefixes', () => {
    expect(resolveJsonApiProxyPath('/jsonapi/node/article')).toEqual({
      endpoint: 'jsonapi',
      path: '/jsonapi/node/article',
    });
    expect(resolveJsonApiProxyPath('/jsonapi')).toEqual({
      endpoint: 'jsonapi',
      path: '/jsonapi',
    });
    expect(resolveJsonApiProxyPath('/de/jsonapi/node/article')?.endpoint).toBe(
      'jsonapi',
    );
    expect(resolveJsonApiProxyPath('/pt-br/jsonapi/index')?.endpoint).toBe(
      'jsonapi',
    );
    expect(resolveJsonApiProxyPath('/router/translate-path')?.endpoint).toBe(
      'router',
    );
    expect(resolveJsonApiProxyPath('/fr/router/translate-path')?.endpoint).toBe(
      'router',
    );
  });

  it.each(['deutsch', 'custom_123', 'fran%C3%A7ais'])(
    'accepts a custom single-segment language prefix: %s',
    (prefix) => {
      expect(
        resolveJsonApiProxyPath(`/${prefix}/jsonapi/node/article`),
      ).toEqual({
        endpoint: 'jsonapi',
        path: `/${prefix}/jsonapi/node/article`,
      });
      expect(
        resolveJsonApiProxyPath(`/${prefix}/router/translate-path`)?.endpoint,
      ).toBe('router');
    },
  );

  it.each([
    '/deutsch/other/jsonapi/node',
    '/deutsch/jsonapiX/node',
    '/deutsch/user/login',
    '/deutsch/routerX/translate-path',
    '/%2e/jsonapi/node',
    '/%2e%2e/jsonapi/node',
    '/deutsch%2fother/jsonapi/node',
    '/deutsch%5cother/jsonapi/node',
    '/deutsch%252fother/jsonapi/node',
    '/%/jsonapi/node',
    '/%00/jsonapi/node',
    '/admin%3Fquery/jsonapi/node',
    '/admin%23fragment/jsonapi/node',
  ])('rejects malformed prefixes or non-endpoints: %s', (path) => {
    expect(resolveJsonApiProxyPath(path)).toBeNull();
  });

  it('honors custom prefixes, including multi-segment ones', () => {
    expect(
      resolveJsonApiProxyPath('/api/node/article', { apiPrefix: 'api' })
        ?.endpoint,
    ).toBe('jsonapi');
    expect(
      resolveJsonApiProxyPath('/drupal/api/node/article', {
        apiPrefix: '/drupal/api/',
      })?.endpoint,
    ).toBe('jsonapi');
    expect(
      resolveJsonApiProxyPath('/jsonapi/node/article', { apiPrefix: 'api' }),
    ).toBeNull();
    expect(
      resolveJsonApiProxyPath('/router', { routerPrefix: 'router' })?.endpoint,
    ).toBe('router');
  });

  it('rejects paths outside the boundary', () => {
    expect(resolveJsonApiProxyPath('/oauth/token')).toBeNull();
    expect(resolveJsonApiProxyPath('/user/login')).toBeNull();
    expect(resolveJsonApiProxyPath('/jsonapiX/node')).toBeNull();
    // This is an endpoint allowlist, not a lookup of configured languages.
    expect(resolveJsonApiProxyPath('/canvas/jsonapi/node')?.endpoint).toBe(
      'jsonapi',
    );
    // Any one safe leading segment can be a configured language prefix.
    expect(resolveJsonApiProxyPath('/foo/jsonapi/node')?.endpoint).toBe(
      'jsonapi',
    );
    expect(resolveJsonApiProxyPath('/en/de/jsonapi/node')).toBeNull();
    expect(resolveJsonApiProxyPath('/canvas/api/v0/site-data')).toBeNull();
  });

  it('rejects unsafe paths', () => {
    expect(isSafeProxyPath('jsonapi/node')).toBe(false);
    expect(isSafeProxyPath('//evil.example/jsonapi')).toBe(false);
    expect(isSafeProxyPath('/jsonapi/../oauth/token')).toBe(false);
    expect(isSafeProxyPath('/jsonapi/./node')).toBe(false);
    expect(isSafeProxyPath('/jsonapi//node')).toBe(false);
    expect(isSafeProxyPath('/jsonapi%2F..%2Fuser')).toBe(false);
    expect(isSafeProxyPath('/jsonapi\\node')).toBe(false);
    expect(isSafeProxyPath('/jsonapi/node/')).toBe(true);
  });

  it('normalizes base URLs and splits backend-relative paths', () => {
    expect(normalizeBaseUrl('https://drupal.example/')).toBe(
      'https://drupal.example',
    );
    expect(normalizeBaseUrl('https://drupal.example/sub/?x#y')).toBe(
      'https://drupal.example/sub',
    );
    expect(
      toBackendRelativePath(
        'https://drupal.example/jsonapi/node/article?page[offset]=50',
        'https://drupal.example',
      ),
    ).toEqual({ path: '/jsonapi/node/article', search: '?page[offset]=50' });
    expect(
      toBackendRelativePath(
        'https://drupal.example/sub/jsonapi',
        'https://drupal.example/sub',
      ),
    ).toEqual({ path: '/jsonapi', search: '' });
    expect(
      toBackendRelativePath(
        'https://drupal.example/subway/jsonapi',
        'https://drupal.example/sub',
      ),
    ).toBeNull();
    expect(
      toBackendRelativePath(
        'https://other.example/jsonapi',
        'https://drupal.example',
      ),
    ).toBeNull();
  });

  it('keeps supporting endpoints on the backend with a foreign JSON:API base', () => {
    const options = {
      baseUrl: 'https://drupal.example/site',
      proxyUrl: '/api/canvas/jsonapi',
      apiUrl: 'https://api.example/drupal/api/',
    };
    expect(
      mapJsonApiRequestToProxy(
        'https://api.example/drupal/api/node/article?page[limit]=5',
        options,
      ),
    ).toBe('/api/canvas/jsonapi/drupal/api/node/article?page[limit]=5');
    expect(
      mapProxyPathToUpstream(
        '/api/canvas/jsonapi/drupal/api/node/article',
        options,
      ),
    ).toEqual({
      url: 'https://api.example/drupal/api/node/article',
      endpoint: 'jsonapi',
    });
    expect(
      mapJsonApiRequestToProxy(
        'https://drupal.example/site/router/translate-path?path=/about',
        options,
      ),
    ).toBe('/api/canvas/jsonapi/router/translate-path?path=/about');
    expect(
      mapProxyPathToUpstream(
        '/api/canvas/jsonapi/router/translate-path',
        options,
      ),
    ).toEqual({
      url: 'https://drupal.example/site/router/translate-path',
      endpoint: 'router',
    });
    // The backend's own prefix and the JSON:API host's other paths are
    // outside the boundary.
    expect(() =>
      mapJsonApiRequestToProxy(
        'https://drupal.example/site/jsonapi/node/article',
        options,
      ),
    ).toThrowError(/not a supported/);
    expect(() =>
      mapJsonApiRequestToProxy(
        'https://api.example/router/translate-path',
        options,
      ),
    ).toThrowError(/not a supported/);
    expect(
      mapProxyPathToUpstream(
        '/api/canvas/jsonapi/jsonapi/node/article',
        options,
      ),
    ).toBeNull();
    expect(
      mapJsonApiRequestToProxy('https://cdn.example/image.png', options),
    ).toBeNull();
  });

  it('treats a same-site JSON:API URL override as a prefix under the site path', () => {
    const options = {
      baseUrl: 'https://drupal.example/sub/',
      proxyUrl: '/api/canvas/jsonapi',
      apiUrl: 'https://drupal.example/sub/api',
    };
    expect(
      mapJsonApiRequestToProxy(
        'https://drupal.example/sub/fr/api/node/article',
        options,
      ),
    ).toBe('/api/canvas/jsonapi/fr/api/node/article');
    expect(
      mapProxyPathToUpstream(
        '/api/canvas/jsonapi/fr/api/node/article',
        options,
      ),
    ).toEqual({
      url: 'https://drupal.example/sub/fr/api/node/article',
      endpoint: 'jsonapi',
    });
    expect(
      mapProxyPathToUpstream(
        '/api/canvas/jsonapi/router/translate-path',
        options,
      ),
    ).toEqual({
      url: 'https://drupal.example/sub/router/translate-path',
      endpoint: 'router',
    });
    // The site's own default prefix is outside the boundary, and so is any
    // backend URL outside the site path: neither leaves unmapped.
    expect(() =>
      mapJsonApiRequestToProxy(
        'https://drupal.example/sub/jsonapi/node/article',
        options,
      ),
    ).toThrowError(/not a supported/);
    expect(() =>
      mapJsonApiRequestToProxy(
        'https://drupal.example/router/translate-path',
        options,
      ),
    ).toThrowError(/not a supported/);
    expect(
      mapProxyPathToUpstream(
        '/api/canvas/jsonapi/jsonapi/node/article',
        options,
      ),
    ).toBeNull();
  });

  it.each([
    {
      baseUrl: 'https://drupal.example/sub',
      apiPrefix: 'api/v1',
      routerPrefix: 'paths/translate',
    },
    {
      baseUrl: 'https://drupal.example/sub',
      apiUrl: 'https://drupal.example/sub/api/v1',
    },
    {
      baseUrl: 'https://drupal.example/sub',
      apiUrl: 'https://api.example/mount/api/v1',
      apiSiteUrl: 'https://api.example/mount',
    },
  ])(
    'round-trips custom language prefixes at the configured site roots: %j',
    (config) => {
      const options = { ...config, proxyUrl: '/custom/proxy' };
      const apiBase =
        'apiSiteUrl' in config ? config.apiSiteUrl : config.baseUrl;
      for (const [base, endpoint, tail] of [
        [apiBase, 'jsonapi', 'api/v1/node/article'],
        [
          config.baseUrl,
          'router',
          config.routerPrefix ?? 'router/translate-path',
        ],
      ]) {
        const path = `/deutsch/${tail}`;
        const upstream = `${base}${path}`;
        expect(
          mapJsonApiRequestToProxy(`${upstream}?page[limit]=3`, options),
        ).toBe(`/custom/proxy${path}?page[limit]=3`);
        expect(mapProxyPathToUpstream(`/custom/proxy${path}`, options)).toEqual(
          { url: upstream, endpoint },
        );
      }
    },
  );

  it.each(['de', 'deutsch'])(
    'does not guess a foreign API site root for %s',
    (prefix) => {
      const options = {
        baseUrl: 'https://drupal.example/sub',
        apiUrl: 'https://api.example/mount/api',
        proxyUrl: '/proxy',
      };
      expect(() =>
        mapJsonApiRequestToProxy(
          `https://api.example/${prefix}/mount/api/node`,
          options,
        ),
      ).toThrow();
      expect(
        mapProxyPathToUpstream(`/proxy/${prefix}/mount/api/node`, options),
      ).toBeNull();
    },
  );

  it('maps upstream URLs to the proxy and back', () => {
    const options = {
      baseUrl: 'https://drupal.example',
      proxyUrl: '/api/canvas/jsonapi',
    };
    const upstream =
      'https://drupal.example/de/jsonapi/node/article?include=field_tags&page[limit]=5';
    const proxied = mapJsonApiRequestToProxy(upstream, options);
    expect(proxied).toBe(
      '/api/canvas/jsonapi/de/jsonapi/node/article?include=field_tags&page[limit]=5',
    );
    expect(
      mapProxyPathToUpstream(
        '/api/canvas/jsonapi/de/jsonapi/node/article',
        options,
      ),
    ).toEqual({
      url: 'https://drupal.example/de/jsonapi/node/article',
      endpoint: 'jsonapi',
    });
    expect(
      mapJsonApiRequestToProxy(
        'https://drupal.example/router/translate-path?path=/about',
        options,
      ),
    ).toBe('/api/canvas/jsonapi/router/translate-path?path=/about');
    expect(
      mapJsonApiRequestToProxy('https://cdn.example/image.png', options),
    ).toBeNull();
    expect(() =>
      mapJsonApiRequestToProxy('https://drupal.example/oauth/token', options),
    ).toThrowError(/not a supported/);
    expect(
      mapProxyPathToUpstream('/api/canvas/jsonapi/oauth/token', options),
    ).toBeNull();
    expect(
      mapProxyPathToUpstream('/other/jsonapi/node/article', options),
    ).toBeNull();
    expect(
      mapProxyPathToUpstream('/api/canvas/jsonapi/jsonapi/../user', options),
    ).toBeNull();
  });
});
