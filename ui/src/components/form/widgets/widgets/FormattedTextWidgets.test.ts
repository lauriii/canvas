import { describe, expect, it, vi } from 'vitest';

import {
  formattedTextAreaWidget,
  formattedTextFieldWidget,
} from './FormattedTextWidgets';

import type { TextFormatSummary } from '@/utils/drupal-globals';
import type { ClientWidgetContext } from '../types';

const permittedFormats: TextFormatSummary[] = [
  { id: 'canvas_html_block', label: 'Block HTML', editor: 'ckeditor5' },
  { id: 'canvas_html_inline', label: 'Inline HTML', editor: 'ckeditor5' },
  { id: 'plain_notes', label: 'Plain notes', editor: null },
  { id: 'markdown', label: 'Markdown', editor: 'contrib_markdown' },
];

vi.mock('@/utils/drupal-globals', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  getTextFormats: () => permittedFormats,
}));

const makeContext = (allowedFormats?: string[]): ClientWidgetContext =>
  ({
    propName: 'text',
    componentId: 'sdc.test.banner',
    componentVersion: '1',
    jsonSchema: { type: 'string', contentMediaType: 'text/html' },
    sourceTypeSettings: {
      storage: {},
      instance: allowedFormats ? { allowed_formats: allowedFormats } : {},
    },
    cardinality: 1,
    required: false,
    fieldData: {} as ClientWidgetContext['fieldData'],
  }) as ClientWidgetContext;

describe('formatted text codec', () => {
  const { codec } = formattedTextAreaWidget;

  it('writes {value, format} source with the raw markup as resolved', () => {
    expect(
      codec.toModel(
        { value: '<p>Hello</p>', format: 'canvas_html_block' },
        makeContext(['canvas_html_block']),
      ),
    ).toEqual({
      resolved: '<p>Hello</p>',
      source: { value: '<p>Hello</p>', format: 'canvas_html_block' },
    });
  });

  it('treats an empty value as clearing the prop', () => {
    expect(
      codec.toModel(
        { value: '', format: 'canvas_html_block' },
        makeContext(['canvas_html_block']),
      ),
    ).toBeNull();
  });

  it('prefers the stored source value over the processed resolved value', () => {
    expect(
      codec.fromModel(
        { value: '<p>Raw</p>', format: 'canvas_html_inline' },
        '<p>Processed</p>',
        makeContext(),
      ),
    ).toEqual({ value: '<p>Raw</p>', format: 'canvas_html_inline' });
  });

  it('falls back to resolved markup and the default format without a source', () => {
    expect(
      codec.fromModel(
        undefined,
        '<p>Processed</p>',
        makeContext(['canvas_html_inline']),
      ),
    ).toEqual({ value: '<p>Processed</p>', format: 'canvas_html_inline' });
  });

  it('normalizes a stored format that is no longer usable', () => {
    // A format that was removed from the permitted or allowed list must not
    // be re-written on the next edit.
    expect(
      codec.fromModel(
        { value: '<p>Raw</p>', format: 'removed_format' },
        undefined,
        makeContext(['canvas_html_block']),
      ),
    ).toEqual({ value: '<p>Raw</p>', format: 'canvas_html_block' });
  });

  it('defaults a missing format to the first allowed format', () => {
    expect(
      codec.fromModel(
        { value: '<p>Raw</p>' },
        undefined,
        makeContext(['canvas_html_block', 'canvas_html_inline']),
      ),
    ).toEqual({ value: '<p>Raw</p>', format: 'canvas_html_block' });
  });
});

describe('formatted text eligibility', () => {
  const isEligible = formattedTextAreaWidget.isEligible!;

  it('is native for CKEditor 5 and editorless formats', () => {
    expect(isEligible(makeContext(['canvas_html_block']))).toBe(true);
    expect(isEligible(makeContext(['plain_notes']))).toBe(true);
    expect(isEligible(makeContext(['canvas_html_block', 'plain_notes']))).toBe(
      true,
    );
  });

  it('goes to the escape hatch when any format uses a contrib editor', () => {
    expect(isEligible(makeContext(['markdown']))).toBe(false);
    expect(isEligible(makeContext(['canvas_html_block', 'markdown']))).toBe(
      false,
    );
  });

  it('goes to the escape hatch when no permitted format applies', () => {
    expect(isEligible(makeContext(['not_permitted_format']))).toBe(false);
  });

  it('uses every permitted format when the prop does not restrict them', () => {
    // The unrestricted list includes the contrib-editor format, so the prop
    // is not eligible; this mirrors the `text_format` element offering all
    // permitted formats.
    expect(isEligible(makeContext())).toBe(false);
  });

  it('is shared by the textfield widget', () => {
    expect(formattedTextFieldWidget.isEligible).toBe(isEligible);
  });
});
