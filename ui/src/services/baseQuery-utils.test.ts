import { describe, expect, it } from 'vitest';

import {
  extractEntityParams,
  replaceEntityParamsInUrl,
} from '@/services/baseQuery';

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
