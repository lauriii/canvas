import { afterEach, describe, expect, it, vi } from 'vitest';

import {
  browseMedia,
  getMediaTypeFromContext,
  MediaEndpointError,
  uploadMedia,
} from './mediaEndpoints';
import { mediaLibraryWidget } from './MediaLibraryWidget';

import type { ClientWidgetContext } from '../types';
import type { MediaInputsResolved } from './mediaEndpoints';

const makeContext = (
  overrides: Partial<ClientWidgetContext> = {},
): ClientWidgetContext =>
  ({
    propName: 'image',
    componentId: 'sdc.canvas_test_sdc.card',
    componentVersion: 'abc123',
    jsonSchema: { type: 'object' },
    sourceTypeSettings: {
      instance: { handler_settings: { target_bundles: { image: 'image' } } },
    },
    cardinality: 1,
    required: false,
    fieldData: {},
    ...overrides,
  }) as unknown as ClientWidgetContext;

const inputsResolved: MediaInputsResolved = {
  src: '/files/cat.png',
  alt: 'A cat',
  width: 100,
  height: 80,
};

const item = (
  id: number | string,
  resolved: MediaInputsResolved | null = null,
) => ({
  id,
  label: `Media ${id}`,
  thumbnailUrl: null,
  inputsResolved: resolved,
});

const { codec } = mediaLibraryWidget;

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('mediaLibraryWidget codec.toModel', () => {
  it('returns null for an empty or non-array selection', () => {
    expect(codec.toModel([], makeContext())).toBeNull();
    expect(codec.toModel(undefined, makeContext())).toBeNull();
    expect(codec.toModel(null, makeContext())).toBeNull();
  });

  it('stores a bare target id for single cardinality', () => {
    const result = codec.toModel([item(5, inputsResolved)], makeContext());
    expect(result).toEqual({ resolved: inputsResolved, source: 5 });
  });

  it('falls back to the target id as resolved when inputs are unknown', () => {
    const result = codec.toModel([item(5)], makeContext());
    expect(result).toEqual({ resolved: 5, source: 5 });
  });

  it('stores an ordered array of target ids for unlimited cardinality', () => {
    const context = makeContext({ cardinality: -1 });
    const result = codec.toModel([item(7, inputsResolved), item(3)], context);
    expect(result).toEqual({
      resolved: [inputsResolved, 3],
      source: [7, 3],
    });
  });

  it('treats any fixed cardinality above one as multiple', () => {
    const context = makeContext({ cardinality: 2 });
    const result = codec.toModel([item(9)], context);
    expect(result).toEqual({ resolved: [9], source: [9] });
  });
});

describe('mediaLibraryWidget codec.fromModel', () => {
  it('maps empty source values to an empty selection', () => {
    expect(codec.fromModel(undefined, undefined, makeContext())).toEqual([]);
    expect(codec.fromModel(null, undefined, makeContext())).toEqual([]);
    expect(codec.fromModel('', undefined, makeContext())).toEqual([]);
    expect(codec.fromModel([], undefined, makeContext())).toEqual([]);
  });

  it('produces un-hydrated placeholders from a single stored id', () => {
    expect(codec.fromModel(5, inputsResolved, makeContext())).toEqual([
      { id: 5, label: '', thumbnailUrl: null, inputsResolved: null },
    ]);
  });

  it('preserves order for multiple stored ids', () => {
    const context = makeContext({ cardinality: -1 });
    expect(codec.fromModel(['7', '3'], undefined, context)).toEqual([
      { id: '7', label: '', thumbnailUrl: null, inputsResolved: null },
      { id: '3', label: '', thumbnailUrl: null, inputsResolved: null },
    ]);
  });

  it('tolerates target_id records and skips empty entries', () => {
    const context = makeContext({ cardinality: -1 });
    expect(
      codec.fromModel([{ target_id: 9 }, '', null, 4], undefined, context),
    ).toEqual([
      { id: 9, label: '', thumbnailUrl: null, inputsResolved: null },
      { id: 4, label: '', thumbnailUrl: null, inputsResolved: null },
    ]);
  });

  it('round-trips stored ids through fromModel and toModel', () => {
    const context = makeContext({ cardinality: -1 });
    const widgetValue = codec.fromModel([7, 3], undefined, context);
    // Un-hydrated items resolve optimistically to their ids; the server
    // evaluation echo supplies the real resolved values.
    expect(codec.toModel(widgetValue, context)).toEqual({
      resolved: [7, 3],
      source: [7, 3],
    });
  });
});

describe('getMediaTypeFromContext', () => {
  it('reads the first bundle from a target_bundles map', () => {
    expect(getMediaTypeFromContext(makeContext())).toBe('image');
  });

  it('reads the first bundle from a target_bundles array', () => {
    const context = makeContext({
      sourceTypeSettings: {
        instance: { handler_settings: { target_bundles: ['video', 'image'] } },
      },
    } as unknown as Partial<ClientWidgetContext>);
    expect(getMediaTypeFromContext(context)).toBe('video');
  });

  it('returns null when no bundles are configured', () => {
    const context = makeContext({
      sourceTypeSettings: { instance: {} },
    } as unknown as Partial<ClientWidgetContext>);
    expect(getMediaTypeFromContext(context)).toBeNull();
    expect(mediaLibraryWidget.isEligible?.(context)).toBe(false);
    expect(mediaLibraryWidget.isEligible?.(makeContext())).toBe(true);
  });
});

describe('media endpoints', () => {
  it('browseMedia requests the list endpoint with query parameters', async () => {
    const payload = {
      items: [
        {
          id: 5,
          uuid: 'u-5',
          label: 'Cat',
          thumbnailUrl: '/thumb/cat.png',
          inputs_resolved: inputsResolved,
        },
      ],
      pager: { page: 1, perPage: 24, total: 42 },
    };
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => payload,
    });
    vi.stubGlobal('fetch', fetchMock);

    const result = await browseMedia('image', {
      search: 'cat',
      page: 1,
      ids: [1, 2],
    });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe('/canvas/api/v0/media/image?search=cat&page=1&ids=1%2C2');
    expect(init.credentials).toBe('same-origin');
    expect(result).toEqual(payload);
  });

  it('uploadMedia posts multipart form data with a CSRF token', async () => {
    const payload = { id: 5, uuid: 'u-5', inputs_resolved: inputsResolved };
    const fetchMock = vi.fn(async (url: string) => {
      if (String(url).endsWith('session/token')) {
        return { ok: true, status: 200, text: async () => 'token123' };
      }
      return { ok: true, status: 201, json: async () => payload };
    });
    vi.stubGlobal('fetch', fetchMock);

    const file = new File(['data'], 'cat.png', { type: 'image/png' });
    const result = await uploadMedia('image', file, { alt: 'A cat' });

    expect(result).toEqual(payload);
    const uploadCall = fetchMock.mock.calls.find(
      ([url]) => !String(url).endsWith('session/token'),
    );
    expect(uploadCall).toBeDefined();
    const [url, init] = uploadCall as unknown as [string, RequestInit];
    expect(url).toBe('/canvas/api/v0/media/image/upload');
    expect(init.method).toBe('POST');
    expect(init.credentials).toBe('same-origin');
    expect(init.headers).toEqual({ 'X-CSRF-Token': 'token123' });
    const body = init.body as FormData;
    expect(body).toBeInstanceOf(FormData);
    expect(body.get('file')).toBeInstanceOf(File);
    expect((body.get('file') as File).name).toBe('cat.png');
    expect(body.get('alt')).toBe('A cat');
  });

  it('surfaces validation errors from rejected uploads', async () => {
    const fetchMock = vi.fn(async (url: string) => {
      if (String(url).endsWith('session/token')) {
        return { ok: true, status: 200, text: async () => 'token123' };
      }
      return {
        ok: false,
        status: 422,
        json: async () => ({
          message: 'Validation failed.',
          errors: ['Only PNG files are allowed.'],
        }),
      };
    });
    vi.stubGlobal('fetch', fetchMock);

    const file = new File(['data'], 'cat.gif', { type: 'image/gif' });
    const error = await uploadMedia('image', file).catch(
      (caught: unknown) => caught,
    );

    expect(error).toBeInstanceOf(MediaEndpointError);
    expect((error as MediaEndpointError).message).toBe('Validation failed.');
    expect((error as MediaEndpointError).status).toBe(422);
    expect((error as MediaEndpointError).errors).toEqual([
      'Only PNG files are allowed.',
    ]);
  });
});
