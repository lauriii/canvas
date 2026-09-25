// @cspell:ignore missingmissing
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { createJsonApiClient } from './jsonapi-client';
import {
  JsonApiClientProvider,
  useHasJsonApiClient,
  useJsonApiClient,
} from './jsonapi-client-context';
import { resetWarnings } from './warnings';

function Probe() {
  const client = useJsonApiClient();
  return <>{client ? client.baseUrl : 'missing'}</>;
}

describe('JSON:API client context', () => {
  beforeEach(() => {
    resetWarnings();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('returns the provided client', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const client = createJsonApiClient({ baseUrl: 'https://drupal.example' });
    expect(
      renderToStaticMarkup(
        <JsonApiClientProvider client={client}>
          <Probe />
        </JsonApiClientProvider>,
      ),
    ).toBe('https://drupal.example');
    expect(warn).not.toHaveBeenCalled();
  });

  it('returns null and warns once without a provider', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    expect(
      renderToStaticMarkup(
        <>
          <Probe />
          <Probe />
        </>,
      ),
    ).toBe('missingmissing');
    expect(warn).toHaveBeenCalledTimes(1);
    expect(warn.mock.calls[0][0]).toContain('useJsonApiClient()');
  });

  it('exposes whether a provider is mounted', () => {
    function HasClient() {
      return <>{String(useHasJsonApiClient())}</>;
    }
    const client = createJsonApiClient({ baseUrl: 'https://drupal.example' });
    expect(renderToStaticMarkup(<HasClient />)).toBe('false');
    expect(
      renderToStaticMarkup(
        <JsonApiClientProvider client={client}>
          <HasClient />
        </JsonApiClientProvider>,
      ),
    ).toBe('true');
  });
});
