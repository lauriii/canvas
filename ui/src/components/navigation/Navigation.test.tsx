import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import Navigation from '@/components/navigation/Navigation';
import { setHomepagePath } from '@/features/configuration/configurationSlice';

import type { ContentStub } from '@/types/Content';

const page = (id: number, title: string): ContentStub => ({
  id,
  title,
  path: `/${title.toLowerCase()}`,
  internalPath: `/page/${id}`,
  status: true,
  autoSaveLabel: null,
  autoSavePath: '',
  links: {},
});

// The server order: newest revision first.
const items = [
  page(1, 'About'),
  page(2, 'Careers'),
  page(5, 'Home'),
  page(6, 'Services'),
];

const renderedIds = (homepagePath?: string) => {
  const store = makeStore();
  if (homepagePath) {
    store.dispatch(setHomepagePath(homepagePath));
  }
  render(
    <AppWrapper store={store} location="/" path="/">
      <Navigation loading={false} showNew={false} items={items} />
    </AppWrapper>,
  );
  return [
    ...screen
      .getByTestId('canvas-navigation-results')
      .querySelectorAll('[data-canvas-page-id]'),
  ].map((el) => el.getAttribute('data-canvas-page-id'));
};

describe('Navigation', () => {
  it('keeps the server order when there is no homepage', () => {
    expect(renderedIds()).toEqual(['1', '2', '5', '6']);
  });

  it.each([
    ['internal path', '/page/5'],
    ['path alias', '/home'],
  ])(
    'pins the homepage set by %s to the top and keeps the rest in order',
    (_, homepagePath) => {
      expect(renderedIds(homepagePath)).toEqual(['5', '1', '2', '6']);
    },
  );
});
