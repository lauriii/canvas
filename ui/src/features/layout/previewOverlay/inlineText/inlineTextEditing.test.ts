import { describe, expect, it } from 'vitest';

import {
  createInlineEditingHost,
  findInlineEditableProps,
  getInlineTextKind,
  humanizePropName,
  isSafeHref,
  normalizeLinkInput,
  readPlainValue,
} from './inlineTextEditing';

import type { FieldDataItem } from '@/types/Component';

const propSource = (
  sourceType: string,
  extra: Partial<FieldDataItem> = {},
): FieldDataItem =>
  ({
    sourceType,
    expression: '',
    default_values: { source: {}, resolved: {} },
    ...extra,
  }) as FieldDataItem;

const richSource = (format: string, type = 'text_long') =>
  propSource(`static:field_item:${type}`, {
    sourceTypeSettings: { instance: { allowed_formats: [format] } },
  });

const render = (html: string) => {
  document.body.innerHTML = html;
  return document;
};

const propSources = {
  heading: propSource('static:field_item:string', { required: true }),
  text: richSource('canvas_html_block'),
  notes: propSource('static:field_item:string_long'),
};

const names = (props: Array<{ propName: string }>) =>
  props.map(({ propName }) => propName);

describe('getInlineTextKind', () => {
  it('classifies the supported text prop shapes', () => {
    expect(
      getInlineTextKind(propSource('static:field_item:string'), undefined),
    ).toEqual({ kind: 'plain' });
    expect(
      getInlineTextKind(propSource('static:field_item:string_long'), undefined),
    ).toEqual({ kind: 'multiline' });
    expect(getInlineTextKind(richSource('basic_html'), undefined)).toEqual({
      kind: 'richBlock',
      format: 'basic_html',
    });
    expect(
      getInlineTextKind(richSource('basic_html', 'text'), undefined),
    ).toEqual({ kind: 'richInline', format: 'basic_html' });
  });

  it('explains why a prop cannot be edited in place', () => {
    const reason = (...args: Parameters<typeof getInlineTextKind>) =>
      (getInlineTextKind(...args) as { reason?: string }).reason;
    expect(
      reason(propSource('static:field_item:string'), 'entity-field'),
    ).toMatch(/linked/);
    expect(
      reason(
        propSource('static:field_item:string', {
          sourceTypeSettings: { cardinality: 3 },
        }),
        undefined,
      ),
    ).toMatch(/multiple values/);
    expect(
      reason(
        propSource('static:field_item:text_long', {
          sourceTypeSettings: {
            instance: { allowed_formats: ['basic_html', 'full_html'] },
          },
        }),
        undefined,
      ),
    ).toMatch(/one allowed text format/);
    expect(reason(propSource('static:field_item:link'), undefined)).toMatch(
      /not text/,
    );
  });
});

describe('findInlineEditableProps', () => {
  it('finds props printed between markers', () => {
    const doc = render(`
      <h2><!-- canvas-prop-start-c1/heading -->Title<!-- canvas-prop-end-c1/heading --></h2>
      <div><!-- canvas-prop-start-c1/text --><p>Body</p><!-- canvas-prop-end-c1/text --></div>
    `);
    const props = findInlineEditableProps(doc, 'c1', propSources, {
      resolved: { heading: 'Title', text: '<p>Body</p>' },
    });
    expect(
      props.map(({ propName, kind, required, target }) => [
        propName,
        kind,
        required,
        target.type,
      ]),
    ).toEqual([
      ['heading', 'plain', true, 'markers'],
      ['text', 'richBlock', false, 'markers'],
    ]);
  });

  it('skips prints that do not show the stored value', () => {
    // For example a same-named variable in an included template, for plain
    // and for formatted text alike.
    const doc = render(`
      <h2><!-- canvas-prop-start-c1/heading -->Other<!-- canvas-prop-end-c1/heading --></h2>
      <div><!-- canvas-prop-start-c1/text --><p>Other</p><!-- canvas-prop-end-c1/text --></div>
    `);
    expect(
      findInlineEditableProps(doc, 'c1', propSources, {
        resolved: { heading: 'Title', text: '<p>Body</p>' },
      }),
    ).toEqual([]);
  });

  it('skips a prop printed more than once', () => {
    const doc = render(`
      <h2><!-- canvas-prop-start-c1/heading -->Title<!-- canvas-prop-end-c1/heading --></h2>
      <span><!-- canvas-prop-start-c1/heading -->Title<!-- canvas-prop-end-c1/heading --></span>
    `);
    expect(
      findInlineEditableProps(doc, 'c1', propSources, {
        resolved: { heading: 'Title' },
      }),
    ).toEqual([]);
  });

  it('infers the element of a prop passed to an included template', () => {
    // `include(…, with_context: false)` prints the value without markers.
    const doc = render(`
      <!-- canvas-start-c1 -->
      <section>
        <h2 class="x">
          Ready to start?
        </h2>
        <div class="body"><p>Talk to us.</p></div>
        <!-- canvas-start-child -->
        <p>Ready to start?</p>
        <!-- canvas-end-child -->
      </section>
      <!-- canvas-end-c1 -->
    `);
    const props = findInlineEditableProps(doc, 'c1', propSources, {
      resolved: { heading: 'Ready to start?', text: '<p>Talk to us.</p>' },
    });
    expect(
      props.map(({ propName, target }) => [
        propName,
        target.type === 'element' ? target.element.localName : null,
      ]),
    ).toEqual([
      ['heading', 'h2'],
      // The container of the value's paragraphs, not the paragraph itself.
      ['text', 'div'],
    ]);
    // Inference reads the DOM; it does not change it.
    expect(doc.body.innerHTML).not.toContain('canvas-prop-start');
  });

  it('does not infer an element for text that appears twice', () => {
    const doc = render(`
      <!-- canvas-start-c1 --><h2>Same</h2><p>Same</p><!-- canvas-end-c1 -->
    `);
    expect(
      findInlineEditableProps(doc, 'c1', propSources, {
        resolved: { heading: 'Same' },
      }),
    ).toEqual([]);
  });

  it('prefers an explicit data-canvas-prop element', () => {
    // A code component marks where it renders a prop.
    const doc = render(`
      <!-- canvas-start-c1 -->
      <canvas-island><h2 data-canvas-prop="heading">Title</h2><p>Title</p></canvas-island>
      <!-- canvas-end-c1 -->
    `);
    const [prop] = findInlineEditableProps(doc, 'c1', propSources, {
      resolved: { heading: 'Title' },
    });
    expect(
      prop.target.type === 'element' && prop.target.element.localName,
    ).toBe('h2');
  });

  it('honors data-canvas-inline-edit="false"', () => {
    const doc = render(`
      <!-- canvas-start-c1 -->
      <div data-canvas-inline-edit="false">
        <h2><!-- canvas-prop-start-c1/heading -->Title<!-- canvas-prop-end-c1/heading --></h2>
      </div>
      <!-- canvas-end-c1 -->
    `);
    expect(
      findInlineEditableProps(doc, 'c1', propSources, {
        resolved: { heading: 'Title' },
      }),
    ).toEqual([]);
  });

  it('does not give two props the same inferred element', () => {
    const doc = render(`
      <!-- canvas-start-c1 --><h2>Same</h2><!-- canvas-end-c1 -->
    `);
    expect(
      names(
        findInlineEditableProps(
          doc,
          'c1',
          {
            heading: propSources.heading,
            label: propSource('static:field_item:string'),
          },
          { resolved: { heading: 'Same', label: 'Same' } },
        ),
      ),
    ).toEqual([]);
  });
});

describe('editing hosts', () => {
  const findHeading = (doc: Document, resolved: string) =>
    findInlineEditableProps(doc, 'c1', propSources, {
      resolved: { heading: resolved },
    })[0];

  it('edits the parent in place and restores it on release', () => {
    const doc = render(
      '<h2 class="t"><!-- canvas-prop-start-c1/heading -->Title<!-- canvas-prop-end-c1/heading --></h2>',
    );
    const prop = findHeading(doc, 'Title');
    const host = createInlineEditingHost(prop);
    const h2 = doc.querySelector('h2')!;
    expect(host.element).toBe(h2);
    expect(h2.getAttribute('contenteditable')).toBe('plaintext-only');
    // Typed at the end; the markers are out of the element while editing.
    h2.append(doc.createTextNode('\u00a0more '));
    expect(readPlainValue(h2, 'plain', prop)).toBe('Title more');
    host.release();
    expect(h2.hasAttribute('contenteditable')).toBe(false);
    expect(h2.hasAttribute('style')).toBe(false);
  });

  it('reverts without duplicating markers, so the prop stays editable', () => {
    const doc = render(
      '<h2><!-- canvas-prop-start-c1/heading -->Title<!-- canvas-prop-end-c1/heading --></h2>',
    );
    const host = createInlineEditingHost(findHeading(doc, 'Title'));
    host.element.textContent = '';
    host.revert();
    host.release();
    expect(doc.querySelector('h2')!.innerHTML).toBe(
      '<!-- canvas-prop-start-c1/heading -->Title<!-- canvas-prop-end-c1/heading -->',
    );
    expect(findHeading(doc, 'Title')).toBeDefined();
  });

  it('keeps text typed into an empty prop', () => {
    // Typing lands at the end of the element; with the markers inside it,
    // that would be after the end marker, outside the prop.
    const doc = render(
      '<h2><!-- canvas-prop-start-c1/heading --><!-- canvas-prop-end-c1/heading --></h2>',
    );
    const prop = findHeading(doc, '');
    const host = createInlineEditingHost(prop);
    host.element.append(doc.createTextNode('New'));
    expect(readPlainValue(host.element, 'plain', prop)).toBe('New');
    host.release();
    expect(doc.querySelector('h2')!.innerHTML).toBe(
      '<!-- canvas-prop-start-c1/heading -->New<!-- canvas-prop-end-c1/heading -->',
    );
  });

  it('wraps the range when the parent holds other content', () => {
    const doc = render(
      '<p>Lead: <!-- canvas-prop-start-c1/heading -->Title<!-- canvas-prop-end-c1/heading --> (new)</p>',
    );
    const host = createInlineEditingHost(findHeading(doc, 'Title'));
    expect(host.element.localName).toBe('span');
    host.element.textContent = 'Changed';
    host.revert();
    host.release();
    host.unwrap();
    expect(doc.querySelector('p')!.innerHTML).toBe(
      'Lead: <!-- canvas-prop-start-c1/heading -->Title<!-- canvas-prop-end-c1/heading --> (new)',
    );
  });

  it('reads line breaks of multi-line text from the DOM', () => {
    // Themes with `white-space: normal` collapse stored line breaks; reading
    // the DOM keeps them, including breaks typed as <br> or new blocks.
    const doc = render(
      '<p><!-- canvas-prop-start-c1/notes -->One\nTwo<!-- canvas-prop-end-c1/notes --></p>',
    );
    const [prop] = findInlineEditableProps(doc, 'c1', propSources, {
      resolved: { notes: 'One\nTwo' },
    });
    const host = createInlineEditingHost(prop);
    host.element.append(doc.createElement('br'), doc.createTextNode('Three'));
    const block = doc.createElement('div');
    block.textContent = 'Four';
    host.element.append(block);
    expect(readPlainValue(host.element, 'multiline', prop)).toBe(
      'One\nTwo\nThree\nFour',
    );
  });
});

describe('links', () => {
  it('allows web, mail and phone links, and relative URLs', () => {
    [
      'https://a.test',
      'http://a.test',
      'mailto:a@b.test',
      'tel:+1',
      '/about',
      '#top',
      'about',
    ].forEach((href) => expect(isSafeHref(href)).toBe(true));
  });

  it('rejects other schemes, also when disguised', () => {
    [
      'javascript:alert(1)',
      ' JavaScript:x',
      'java\tscript:alert(1)',
      '\u0001javascript:alert(1)',
      'data:text/html,x',
      '',
    ].forEach((href) => expect(isSafeHref(href)).toBe(false));
  });

  it('turns link input into URLs', () => {
    expect(normalizeLinkInput('about')).toBe('/about');
    expect(normalizeLinkInput('/about')).toBe('/about');
    expect(normalizeLinkInput('#top')).toBe('#top');
    expect(normalizeLinkInput('example.com/x?utm=1&a=2')).toBe(
      'https://example.com/x?utm=1&a=2',
    );
    expect(normalizeLinkInput('team@example.com')).toBe(
      'mailto:team@example.com',
    );
    expect(normalizeLinkInput('http://a.test')).toBe('http://a.test');
  });
});

describe('humanizePropName', () => {
  it('turns machine names into labels', () => {
    expect(humanizePropName('heading_text')).toBe('Heading text');
    expect(humanizePropName('cta-label')).toBe('Cta label');
  });
});
