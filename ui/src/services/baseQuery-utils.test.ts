import { afterEach, describe, expect, it, vi } from 'vitest';

import { setMaintenanceMessage } from '@/features/error-handling/queryErrorSlice';
import {
  baseQuery,
  extractEntityParams,
  replaceEntityParamsInUrl,
} from '@/services/baseQuery';
import { forgetCsrfToken } from '@/utils/csrf';

import type { BaseQueryApi } from '@reduxjs/toolkit/query/react';

describe('extractEntityParams', () => {
  it('removes query parameters and hash fragments', () => {
    const url = '/canvas/editor/node/1234/?foo=bar#section';
    const result = extractEntityParams(url);
    expect(result).toEqual({ entityType: 'node', entityId: '1234' });
  });

  it('removes hash fragments', () => {
    const url = '/canvas/editor/node/1234#section';
    const result = extractEntityParams(url);
    expect(result).toEqual({ entityType: 'node', entityId: '1234' });
  });

  it('works with template editor URLs', () => {
    const url = '/canvas/template/article/bundle/view/5678?x=1#y';
    const result = extractEntityParams(url);
    expect(result).toEqual({
      entityType: 'article',
      templateBundle: 'bundle',
      templateViewMode: 'view',
      entityId: '5678',
    });
  });

  it('returns undefined for non-matching URLs', () => {
    const url = '/not/a/matching/url?foo=bar#baz';
    const result = extractEntityParams(url);
    expect(result).toEqual({ entityType: undefined, entityId: undefined });
  });
});

describe('replaceEntityParamsInUrl', () => {
  it('replaces {entity_type} and {entity_id} in the URL', () => {
    const url = '/api/{entity_type}/{entity_id}';
    const result = replaceEntityParamsInUrl(url, 'node', '123');
    expect(result).toBe('/api/node/123');
  });

  it('replaces all template params in the URL', () => {
    const url =
      '/api/{entity_type}/{template_bundle}/{template_view_mode}/{entity_id}';
    const result = replaceEntityParamsInUrl(
      url,
      'article',
      '456',
      'bundle',
      'view',
    );
    expect(result).toBe('/api/article/bundle/view/456');
  });

  it('throws an error if a required param is missing', () => {
    const url = '/api/{entity_type}/{entity_id}';
    expect(() => replaceEntityParamsInUrl(url, undefined, '123')).toThrow();
    expect(() => replaceEntityParamsInUrl(url, 'node', undefined)).toThrow();
  });

  it('returns the URL unchanged if no params are present', () => {
    const url = '/api/static/path';
    const result = replaceEntityParamsInUrl(url);
    expect(result).toBe('/api/static/path');
  });
});

describe('baseQuery', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    forgetCsrfToken('http://localhost/');
  });

  it('fetches a new CSRF token when a mutation gets a 403 with an HTML body', async () => {
    const tokens = ['stale-token', 'new-token'];
    const sentTokens: string[] = [];
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const request =
          input instanceof Request ? input : new Request(input, init);
        if (request.url.endsWith('session/token')) {
          return new Response(tokens.shift());
        }
        const token = request.headers.get('X-CSRF-Token')!;
        sentTokens.push(token);
        return token === 'new-token'
          ? Response.json({ saved: true })
          : new Response('<html>Forbidden</html>', {
              status: 403,
              headers: { 'Content-Type': 'text/html' },
            });
      }),
    );
    const api = {
      type: 'mutation',
      endpoint: 'test',
      getState: () => ({ configuration: { baseUrl: 'http://localhost/' } }),
      signal: new AbortController().signal,
      abort: vi.fn(),
      dispatch: vi.fn(),
    } as unknown as BaseQueryApi;

    const result = await baseQuery(
      { url: 'canvas/api/v0/test', method: 'POST', body: {} },
      api,
      {},
    );

    expect(result.data).toEqual({ saved: true });
    expect(sentTokens).toEqual(['stale-token', 'new-token']);
  });

  // Drupal's maintenance mode response: the configured message, which may be
  // empty or happen to be valid JSON, as plain text (or an HTML page).
  it.each([
    ['a message', 'Back soon.', 'text/plain; charset=UTF-8', 'Back soon.'],
    [
      'an empty message',
      '',
      'text/plain; charset=UTF-8',
      'Site under maintenance.',
    ],
    [
      'a message that is valid JSON',
      '"Back soon."',
      'text/plain; charset=UTF-8',
      'Back soon.',
    ],
    [
      'an HTML page',
      '<p>Back soon.</p>',
      'text/html; charset=UTF-8',
      '<p>Back soon.</p>',
    ],
  ])(
    'flags maintenance mode on HTTP 503 with %s',
    async (_label, body, contentType, expectedMessage) => {
      vi.stubGlobal(
        'fetch',
        vi.fn(
          async () =>
            new Response(body, {
              status: 503,
              headers: { 'Content-Type': contentType },
            }),
        ),
      );
      const dispatch = vi.fn();
      const api = {
        dispatch,
        getState: () => ({ configuration: { baseUrl: 'http://localhost/' } }),
        signal: new AbortController().signal,
        abort: vi.fn(),
        type: 'query',
        endpoint: 'test',
        extra: undefined,
      } as unknown as BaseQueryApi;
      // The request never settles, so wait for the dispatch instead.
      void baseQuery('foo', api, {});
      await vi.waitFor(() =>
        expect(dispatch).toHaveBeenCalledWith(
          setMaintenanceMessage(expectedMessage),
        ),
      );
      vi.unstubAllGlobals();
    },
  );

  it('returns a regular error on HTTP 503 with a JSON body', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => Response.json({ message: 'Busy.' }, { status: 503 })),
    );
    const dispatch = vi.fn();
    const api = {
      dispatch,
      getState: () => ({ configuration: { baseUrl: 'http://localhost/' } }),
      signal: new AbortController().signal,
      abort: vi.fn(),
      type: 'query',
      endpoint: 'test',
      extra: undefined,
    } as unknown as BaseQueryApi;
    const result = await baseQuery('foo', api, {});
    expect(result.error).toMatchObject({
      status: 503,
      data: { message: 'Busy.' },
    });
    expect(dispatch).not.toHaveBeenCalled();
    vi.unstubAllGlobals();
  });
});

describe('baseQuery with a stale CSRF token', () => {
  const baseUrl = 'http://localhost/';
  const api = (dispatch: () => void) =>
    ({
      dispatch,
      getState: () => ({ configuration: { baseUrl } }),
      signal: new AbortController().signal,
      abort: vi.fn(),
      type: 'mutation',
      endpoint: 'test',
      extra: undefined,
    }) as unknown as BaseQueryApi;

  // Responds to token requests with a new token each time, and to other
  // requests with the given responses in order. Returns the token each of
  // those requests was sent with.
  const stubFetch = (...responses: Response[]) => {
    const sentTokens: Array<string | null> = [];
    let tokenCount = 0;
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => {
        const request = input instanceof Request ? input : new Request(input);
        if (request.url === `${baseUrl}session/token`) {
          tokenCount += 1;
          return new Response(`token-${tokenCount}`);
        }
        sentTokens.push(request.headers.get('X-CSRF-Token'));
        return responses.shift();
      }),
    );
    return sentTokens;
  };

  afterEach(() => {
    vi.unstubAllGlobals();
    forgetCsrfToken(baseUrl);
  });

  it('retries once with a new token, then flags maintenance mode on HTTP 503', async () => {
    const sentTokens = stubFetch(
      Response.json({ message: 'Invalid CSRF token.' }, { status: 403 }),
      new Response('Back soon.', {
        status: 503,
        headers: { 'Content-Type': 'text/plain; charset=UTF-8' },
      }),
    );
    const dispatch = vi.fn();
    const settled = vi.fn();

    void Promise.resolve(baseQuery('foo', api(dispatch), {})).then(
      settled,
      settled,
    );

    await vi.waitFor(() =>
      expect(dispatch).toHaveBeenCalledWith(
        setMaintenanceMessage('Back soon.'),
      ),
    );
    expect(sentTokens).toEqual(['token-1', 'token-2']);
    // The request never settles.
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(settled).not.toHaveBeenCalled();
  });

  it('retries once with a new token, then returns a JSON HTTP 503 as a regular error', async () => {
    const sentTokens = stubFetch(
      Response.json({ message: 'Invalid CSRF token.' }, { status: 403 }),
      Response.json({ message: 'Busy.' }, { status: 503 }),
    );
    const dispatch = vi.fn();

    const result = await baseQuery('foo', api(dispatch), {});

    expect(result.error).toMatchObject({
      status: 503,
      data: { message: 'Busy.' },
    });
    expect(sentTokens).toEqual(['token-1', 'token-2']);
    expect(dispatch).not.toHaveBeenCalled();
  });
});
