import { describe, expect, it } from 'vitest';
import { LineCounter, parseDocument } from 'yaml';

import {
  normalizeComponentMetadata,
  validateComponentMetadataEnvelope,
} from './metadata-validation';

function parsed(source: string) {
  const lineCounter = new LineCounter();
  const document = parseDocument(source, { lineCounter });
  return { document, lineCounter, source, value: document.toJS() };
}

describe('styleParts in component metadata', () => {
  it('accepts style lists and limit maps, and keeps them', () => {
    const metadata = parsed(
      [
        'name: Card',
        'machineName: card',
        'styles: [surface]',
        'styleParts:',
        '  heading:',
        '    label: Heading',
        '    styles: [text_size]',
        '  call_to_action:',
        '    label: Call to action',
        '    styles:',
        '      fill: { folders: [Brand], custom: false }',
        '      text_size: ~',
      ].join('\n'),
    );
    expect(validateComponentMetadataEnvelope(metadata)).toEqual([]);
    expect(normalizeComponentMetadata(metadata.value).styleParts).toEqual({
      heading: { label: 'Heading', styles: ['text_size'] },
      call_to_action: {
        label: 'Call to action',
        styles: {
          fill: { folders: ['Brand'], custom: false },
          text_size: null,
        },
      },
    });
  });

  it('rejects invalid part IDs, labels, style IDs, and limit keys', () => {
    const diagnostics = validateComponentMetadataEnvelope(
      parsed(
        [
          'name: Card',
          'machineName: card',
          'styleParts:',
          '  Heading:',
          '    label: Heading',
          '    styles: [text_size]',
          '  body:',
          '    label: " "',
          '    styles: [Text-Size]',
          '  footer:',
          '    label: Footer',
          '    styles:',
          '      fill: { sizes: [l] }',
        ].join('\n'),
      ),
    );
    const paths = diagnostics.map((d) => d.path);
    expect(paths).toContain('$.styleParts');
    expect(paths).toContain('$.styleParts.body.label');
    expect(paths).toContain('$.styleParts.body.styles[0]');
    expect(paths).toContain('$.styleParts.footer.styles.fill.sizes');
    expect(
      diagnostics.find(
        (d) => d.path === '$.styleParts.footer.styles.fill.sizes',
      )?.message,
    ).toBe("must not contain unknown property 'sizes'");
  });

  it('requires a label and styles on each part', () => {
    expect(
      validateComponentMetadataEnvelope(
        parsed('name: Card\nmachineName: card\nstyleParts:\n  heading: {}\n'),
      ).map((d) => d.message),
    ).toEqual([
      "must contain required property 'label'",
      "must contain required property 'styles'",
    ]);
  });
});
