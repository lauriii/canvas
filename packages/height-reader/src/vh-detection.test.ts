// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';

import {
  collectViewportRelativeElements,
  isVhMeasurementCandidate,
} from './vh-detection';

afterEach(() => {
  document.body.innerHTML = '';
});

describe('isVhMeasurementCandidate', () => {
  it('excludes html and body', () => {
    expect(isVhMeasurementCandidate(document.documentElement, 800)).toBe(false);
    expect(isVhMeasurementCandidate(document.body, 800)).toBe(false);
  });

  it('matches an element with a Tailwind min-h-screen class', () => {
    const el = document.createElement('div');
    el.className = 'min-h-screen';
    expect(isVhMeasurementCandidate(el, 800)).toBe(true);
  });

  it('matches an element with an inline vh style', () => {
    const el = document.createElement('div');
    el.setAttribute('style', 'height: 100vh');
    expect(isVhMeasurementCandidate(el, 800)).toBe(true);
  });

  it('does not match an element with no viewport-relative sizing', () => {
    const el = document.createElement('div');
    el.className = 'p-4';
    expect(isVhMeasurementCandidate(el, 800)).toBe(false);
  });

  it('does not match plain fixed-size Tailwind min-h-<number> utilities', () => {
    const el = document.createElement('div');
    el.className = 'flex min-h-96 items-center';
    expect(isVhMeasurementCandidate(el, 800)).toBe(false);
  });

  it('matches Tailwind h-dvh/svh/lvh viewport utilities', () => {
    for (const cls of ['h-dvh', 'min-h-svh', 'max-h-lvh']) {
      const el = document.createElement('div');
      el.className = cls;
      expect(isVhMeasurementCandidate(el, 800)).toBe(true);
    }
  });

  it('matches arbitrary Tailwind viewport-height value classes', () => {
    for (const cls of ['h-[100vh]', 'h-[150dvh]', 'min-h-[75svh]']) {
      const el = document.createElement('div');
      el.className = cls;
      expect(isVhMeasurementCandidate(el, 800)).toBe(true);
    }
  });

  it('passes boxes taller than the viewport to the probe', () => {
    const el = document.createElement('div');
    el.style.height = '900px';
    expect(isVhMeasurementCandidate(el, 500)).toBe(true);
  });
});

describe('collectViewportRelativeElements', () => {
  it('finds a viewport-relative descendant anywhere in the subtree', () => {
    const wrapper = document.createElement('div');
    const section = document.createElement('section');
    section.className = 'h-screen';
    wrapper.appendChild(section);
    document.body.appendChild(wrapper);

    const found = collectViewportRelativeElements(document.body, 800);

    expect(found).toContain(section);
  });
});
