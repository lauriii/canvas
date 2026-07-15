import { afterEach, describe, expect, it, vi } from 'vitest';

import { fidsCodec, fileGenericWidget } from './FileWidget';
import { MediaEndpointError, uploadFile } from './mediaEndpoints';

import type { ClientWidgetContext } from '../types';
import type { UploadedFileValue } from './FileWidget';

const context = {
  propName: 'attachment',
  componentId: 'sdc.canvas_test_sdc.download',
  componentVersion: 'abc123',
  jsonSchema: { type: 'object' },
  sourceTypeSettings: { instance: {} },
  cardinality: 1,
  required: false,
  fieldData: {},
} as unknown as ClientWidgetContext;

const fileValue: UploadedFileValue = {
  fid: 12,
  url: '/files/report.pdf',
  filename: 'report.pdf',
  filesize: 2048,
  width: null,
  height: null,
};

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('fidsCodec.toModel', () => {
  it('returns null for empty values', () => {
    expect(fidsCodec.toModel(null, context)).toBeNull();
    expect(fidsCodec.toModel(undefined, context)).toBeNull();
  });

  it('persists the fids array as both source and resolved', () => {
    // Parity with `mainProperty(name: 'fids')`: single cardinality persists
    // the widget record's fids value, which is itself an array.
    expect(fidsCodec.toModel(fileValue, context)).toEqual({
      resolved: [12],
      source: [12],
    });
  });
});

describe('fidsCodec.fromModel', () => {
  it('returns null when no fid is stored', () => {
    expect(fidsCodec.fromModel(undefined, undefined, context)).toBeNull();
    expect(fidsCodec.fromModel(null, undefined, context)).toBeNull();
    expect(fidsCodec.fromModel([], undefined, context)).toBeNull();
  });

  it('extracts the fid from the persisted fids array', () => {
    expect(fidsCodec.fromModel([12], undefined, context)).toEqual({
      fid: 12,
      url: '',
      filename: '',
      filesize: null,
      width: null,
      height: null,
    });
  });

  it('tolerates scalar, string, and record-shaped fids values', () => {
    expect(fidsCodec.fromModel(12, undefined, context)).toMatchObject({
      fid: 12,
    });
    expect(fidsCodec.fromModel('12', undefined, context)).toMatchObject({
      fid: 12,
    });
    expect(
      fidsCodec.fromModel({ fids: [12] }, undefined, context),
    ).toMatchObject({ fid: 12 });
  });

  it('reuses the server-evaluated resolved object for presentation', () => {
    const resolved = { src: '/files/photo.png?v=2', width: 100, height: 80 };
    expect(fidsCodec.fromModel([12], resolved, context)).toEqual({
      fid: 12,
      url: '/files/photo.png?v=2',
      filename: 'photo.png',
      filesize: null,
      width: 100,
      height: 80,
    });
  });

  it('round-trips the persisted fids array', () => {
    const widgetValue = fidsCodec.fromModel([12], undefined, context);
    expect(fidsCodec.toModel(widgetValue, context)).toEqual({
      resolved: [12],
      source: [12],
    });
  });
});

describe('fileGenericWidget definition', () => {
  it('uses the fids codec and stays single-value (multi goes to the hatch)', () => {
    expect(fileGenericWidget.codec).toBe(fidsCodec);
    expect(fileGenericWidget.handlesMultipleValues).toBeUndefined();
  });
});

describe('uploadFile', () => {
  it('posts multipart form data with prop context and a CSRF token', async () => {
    const payload = {
      fid: 12,
      uuid: 'u-12',
      url: '/files/report.pdf',
      filename: 'report.pdf',
      filesize: 2048,
      width: null,
      height: null,
    };
    const fetchMock = vi.fn(async (url: string) => {
      if (String(url).endsWith('session/token')) {
        return { ok: true, status: 200, text: async () => 'token123' };
      }
      return { ok: true, status: 201, json: async () => payload };
    });
    vi.stubGlobal('fetch', fetchMock);

    const file = new File(['data'], 'report.pdf', { type: 'application/pdf' });
    const result = await uploadFile(file, {
      component: 'sdc.canvas_test_sdc.download',
      version: 'abc123',
      prop: 'attachment',
    });

    expect(result).toEqual(payload);
    const uploadCall = fetchMock.mock.calls.find(
      ([url]) => !String(url).endsWith('session/token'),
    );
    expect(uploadCall).toBeDefined();
    const [url, init] = uploadCall as unknown as [string, RequestInit];
    expect(url).toBe(
      '/canvas/api/v0/file/upload?component=sdc.canvas_test_sdc.download&version=abc123&prop=attachment',
    );
    expect(init.method).toBe('POST');
    expect(init.credentials).toBe('same-origin');
    expect(init.headers).toEqual({ 'X-CSRF-Token': 'token123' });
    const body = init.body as FormData;
    expect(body).toBeInstanceOf(FormData);
    expect((body.get('file') as File).name).toBe('report.pdf');
  });

  it('surfaces 422 validation failures', async () => {
    const fetchMock = vi.fn(async (url: string) => {
      if (String(url).endsWith('session/token')) {
        return { ok: true, status: 200, text: async () => 'token123' };
      }
      return {
        ok: false,
        status: 422,
        json: async () => ({
          message: 'File validation failed.',
          errors: { file: ['The file is too large.'] },
        }),
      };
    });
    vi.stubGlobal('fetch', fetchMock);

    const file = new File(['data'], 'huge.pdf', { type: 'application/pdf' });
    const error = await uploadFile(file, {
      component: 'sdc.canvas_test_sdc.download',
      version: 'abc123',
      prop: 'attachment',
    }).catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(MediaEndpointError);
    expect((error as MediaEndpointError).message).toBe(
      'File validation failed.',
    );
    expect((error as MediaEndpointError).status).toBe(422);
    expect((error as MediaEndpointError).errors).toEqual([
      'The file is too large.',
    ]);
  });
});
