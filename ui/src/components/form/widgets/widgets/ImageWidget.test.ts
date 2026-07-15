import { describe, expect, it } from 'vitest';

import { fidsCodec } from './FileWidget';
import { imageImageWidget } from './ImageWidget';

import type { ClientWidgetContext } from '../types';
import type { UploadedFileValue } from './FileWidget';

const context = {
  propName: 'image',
  componentId: 'sdc.canvas_test_sdc.hero',
  componentVersion: 'abc123',
  jsonSchema: { type: 'object' },
  sourceTypeSettings: { instance: { file_extensions: 'png gif jpg jpeg' } },
  cardinality: 1,
  required: false,
  fieldData: {},
} as unknown as ClientWidgetContext;

describe('imageImageWidget definition', () => {
  it('shares the fids codec with the file widget', () => {
    expect(imageImageWidget.codec).toBe(fidsCodec);
    expect(imageImageWidget.handlesMultipleValues).toBeUndefined();
  });
});

describe('imageImageWidget codec', () => {
  it('persists an uploaded image as a single-element fids array', () => {
    const uploaded: UploadedFileValue = {
      fid: 34,
      url: '/files/hero.png',
      filename: 'hero.png',
      filesize: 4096,
      width: 640,
      height: 480,
    };
    expect(imageImageWidget.codec.toModel(uploaded, context)).toEqual({
      resolved: [34],
      source: [34],
    });
  });

  it('returns null when the image is removed', () => {
    expect(imageImageWidget.codec.toModel(null, context)).toBeNull();
  });

  it('rebuilds presentation data from the evaluated image object', () => {
    const resolved = {
      src: '/files/hero.png',
      alt: 'Hero',
      width: 640,
      height: 480,
    };
    expect(imageImageWidget.codec.fromModel([34], resolved, context)).toEqual({
      fid: 34,
      url: '/files/hero.png',
      filename: 'hero.png',
      filesize: null,
      width: 640,
      height: 480,
    });
  });

  it('round-trips the persisted fids array before server evaluation', () => {
    // Fresh from the model, before any evaluation echo, resolved still holds
    // the fids array itself.
    const widgetValue = imageImageWidget.codec.fromModel([34], [34], context);
    expect(widgetValue).toMatchObject({ fid: 34, url: '', filename: '' });
    expect(imageImageWidget.codec.toModel(widgetValue, context)).toEqual({
      resolved: [34],
      source: [34],
    });
  });
});
