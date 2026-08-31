import { describe, expect, it } from 'vitest';

import {
  decodeEntities,
  isMediaId,
  progressToHtml,
  removeMediaFields,
} from './placementUtils';

import type { CanvasComponent } from '@/types/Component';

const mediaProp = {
  sourceType: 'static:field_item:entity_reference',
  sourceTypeSettings: { storage: { target_type: 'media' } },
};

const hero = {
  id: 'sdc.test.hero',
  propSources: {
    heading: { sourceType: 'static:field_item:string' },
    media: mediaProp,
  },
} as unknown as CanvasComponent;

describe('isMediaId', () => {
  it('accepts numeric IDs only', () => {
    expect(isMediaId(5)).toBe(true);
    expect(isMediaId('5')).toBe(true);
    expect(isMediaId('https://example.com/a.jpg')).toBe(false);
    expect(isMediaId({ src: 'a.jpg', alt: 'a' })).toBe(false);
    expect(isMediaId('')).toBe(false);
    expect(isMediaId(null)).toBe(false);
  });
});

describe('removeMediaFields', () => {
  it('keeps a media ID and every non-media prop', () => {
    expect(
      removeMediaFields(hero, { fieldValues: { heading: 'Hi', media: 3 } }),
    ).toEqual({ fieldValues: { heading: 'Hi', media: 3 } });
  });

  it('drops values a media-bound prop cannot store', () => {
    for (const media of [
      { src: 'a.jpg', alt: 'a', width: 1, height: 1 },
      { src: 'a.jpg', alt: 'a' },
      'https://example.com/a.jpg',
      '',
    ]) {
      expect(
        removeMediaFields(hero, { fieldValues: { heading: 'Hi', media } }),
      ).toEqual({ fieldValues: { heading: 'Hi' } });
    }
  });

  it('leaves components without prop sources unchanged', () => {
    const block = { id: 'block.test' } as unknown as CanvasComponent;
    const instance = { fieldValues: { anything: { src: 'a.jpg' } } };
    expect(removeMediaFields(block, instance)).toBe(instance);
  });
});

describe('decodeEntities', () => {
  it('decodes named and numeric entities', () => {
    expect(
      decodeEntities('News &amp; events &#8212; &#x2014; &quot;A&quot;'),
    ).toBe('News & events — — "A"');
  });

  it('leaves unknown entities and plain text alone', () => {
    expect(decodeEntities('&unknown; a & b')).toBe('&unknown; a & b');
  });
});

describe('progressToHtml', () => {
  it('escapes the narration once, even when the model wrote entities', () => {
    const html = progressToHtml('News &amp; events\n<b>', false);
    expect(html).toBe(
      'News &amp; events<br>&lt;b&gt;<div class="aiProgressStatus"><span class="aiLoader"></span>Thinking</div>',
    );
  });

  it('switches the status row to finished', () => {
    expect(progressToHtml('Done', true)).toContain('aiCompletedIcon');
  });
});
