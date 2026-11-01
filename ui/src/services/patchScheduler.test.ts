import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { createPatchScheduler } from '@/services/patchScheduler';

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
});
