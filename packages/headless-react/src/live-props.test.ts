import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  getLiveProps,
  LIVE_PROP_HOLD_MS,
  receiveLiveProps,
  settleLiveProps,
  withLiveProps,
} from './live-props';

const image = (name: string) => ({ src: `https://cms.example/${name}.jpg` });

const serverTree = (heading: string, picture: string) => ({
  root: 'hero',
  elements: {
    hero: {
      type: 'js.hero',
      props: { heading, image: image(picture), subheading: 'Kept' } as Record<
        string,
        unknown
      >,
      children: [],
    },
  },
});

const shown = (tree: ReturnType<typeof serverTree>) =>
  withLiveProps(tree, getLiveProps())!.elements.hero.props;

describe('live props', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.runAllTimers();
    vi.useRealTimers();
  });

  it('shows the editor props until the server data shows the same', () => {
    const stale = serverTree('Saved', 'old');
    receiveLiveProps({
      componentUuid: 'hero',
      props: { heading: 'Typ' },
      edit: 1,
    });
    receiveLiveProps({
      componentUuid: 'gone',
      props: { heading: 'No such element' },
      edit: 1,
    });
    expect(withLiveProps(stale, getLiveProps())!.elements).toEqual({
      hero: {
        ...stale.elements.hero,
        props: { heading: 'Typ', image: image('old'), subheading: 'Kept' },
      },
    });

    // The auto-save of edit 1 returns after the user typed on. What Drupal
    // resolved for it shows at once, but must not put back the older text.
    receiveLiveProps({
      componentUuid: 'hero',
      props: { heading: 'Typed' },
      edit: 2,
    });
    receiveLiveProps({
      componentUuid: 'hero',
      props: { heading: 'Typ', image: image('new'), subheading: 'Kept' },
      edit: 1,
    });
    expect(shown(stale)).toEqual({
      heading: 'Typed',
      image: image('new'),
      subheading: 'Kept',
    });

    // A refresh that was requested before that auto-save changes nothing.
    settleLiveProps(stale);
    expect(shown(stale).image).toEqual(image('new'));

    // The refresh that includes it hands the image over to the server data.
    const fresh = serverTree('Typ', 'new');
    settleLiveProps(fresh);
    expect(getLiveProps().get('hero')).toEqual({
      heading: { value: 'Typed', edit: 2 },
    });
    expect(shown(fresh).heading).toBe('Typed');

    // An edit whose auto-save never returns does not outlive the hold.
    vi.advanceTimersByTime(LIVE_PROP_HOLD_MS);
    expect(withLiveProps(fresh, getLiveProps())).toBe(fresh);
  });
});
