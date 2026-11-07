import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  createPatchScheduler,
  rebaseComponentModel,
} from '@/services/patchScheduler';

describe('createPatchScheduler', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it('sends a lone change right away and paces typing', async () => {
    const sent: string[] = [];
    // Each request takes 50ms.
    const send = (value: string) => {
      sent.push(value);
      return new Promise((resolve) => setTimeout(resolve, 50));
    };
    const scheduler = createPatchScheduler(send);

    scheduler.schedule('a', 'lone');
    await vi.advanceTimersByTimeAsync(0);
    expect(sent).toEqual(['lone']);

    // 30 keystrokes, 100ms apart, after a quiet moment.
    await vi.advanceTimersByTimeAsync(2000);
    for (let i = 1; i <= 30; i++) {
      scheduler.schedule('a', `key ${i}`);
      expect(scheduler.has('a')).toBe(true);
      await vi.advanceTimersByTimeAsync(100);
    }
    await vi.advanceTimersByTimeAsync(1000);

    // The first keystroke, then about one per second while typing, then the
    // last one shortly after the pause.
    expect(sent[1]).toBe('key 1');
    expect(sent.at(-1)).toBe('key 30');
    expect(sent.length).toBeGreaterThanOrEqual(4);
    expect(sent.length).toBeLessThanOrEqual(6);
    expect(scheduler.has('a')).toBe(false);
  });

  it('sends one request at a time, including for other keys', async () => {
    let inFlight = 0;
    let maxInFlight = 0;
    const sent: string[] = [];
    const scheduler = createPatchScheduler((value: string) => {
      sent.push(value);
      maxInFlight = Math.max(maxInFlight, ++inFlight);
      return new Promise((resolve) =>
        setTimeout(() => resolve(inFlight--), 500),
      );
    });

    scheduler.schedule('a', 'a1');
    scheduler.schedule('b', 'b1');
    scheduler.schedule('a', 'a2');
    await vi.advanceTimersByTimeAsync(3000);

    expect(sent).toEqual(['a2', 'b1']);
    expect(maxInFlight).toBe(1);
  });

  it('drains waiting changes and sends urgent ones right away', async () => {
    const sent: string[] = [];
    const scheduler = createPatchScheduler((value: string) => {
      sent.push(value);
      return new Promise((resolve) => setTimeout(resolve, 50));
    });

    // Typing: the second change waits for a pause.
    scheduler.schedule('a', 'a1');
    await vi.advanceTimersByTimeAsync(10);
    scheduler.schedule('a', 'a2');
    expect(sent).toEqual(['a1']);

    let drained = false;
    scheduler.drain().then(() => (drained = true));
    await vi.advanceTimersByTimeAsync(40);
    expect(drained).toBe(false);
    await vi.advanceTimersByTimeAsync(100);
    expect(sent).toEqual(['a1', 'a2']);
    expect(drained).toBe(true);

    scheduler.schedule('a', 'a3');
    scheduler.schedule('a', 'a4', true);
    await vi.advanceTimersByTimeAsync(0);
    expect(sent).toEqual(['a1', 'a2', 'a4']);
  });
});

describe('rebaseComponentModel', () => {
  it('keeps props saved meanwhile unless the change edited them', () => {
    const text = (value: string) => ({ sourceType: 'static', value });
    // Typed while the image was being removed, on the model with the image.
    // The outgoing sources repeat each value, the saved ones may not.
    const base = {
      source: {
        text: { sourceType: 'static' },
        image: { sourceType: 'media' },
      },
      resolved: { text: 'a', image: 'cat.jpg', size: 'big' },
    };
    const changed = {
      source: {
        text: text('ab'),
        image: { sourceType: 'media', value: 'cat.jpg' },
      },
      resolved: { text: 'ab', image: 'cat.jpg' },
    };
    const latest = {
      source: {
        text: { sourceType: 'static' },
        image: { sourceType: 'media' },
      },
      resolved: { text: 'a', image: [], size: 'big' },
    };
    expect(rebaseComponentModel(base, changed, latest)).toEqual({
      source: { text: text('ab'), image: { sourceType: 'media' } },
      resolved: { text: 'ab', image: [] },
    });
  });
});
