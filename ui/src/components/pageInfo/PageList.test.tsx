import { describe, expect, it } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import PageList from '@/components/pageInfo/PageList';

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

const renderedTitles = (homepagePath?: string) => {
  render(
    <AppWrapper store={makeStore()} location="/" path="/">
      <PageList pageItems={items} homepagePath={homepagePath} />
    </AppWrapper>,
  );
  return within(screen.getByTestId('canvas-page-list'))
    .getAllByText(/^(About|Careers|Home|Services) /)
    .map((el) => el.textContent?.split(' ')[0]);
};

describe('PageList', () => {
  it('keeps the server order when there is no homepage', () => {
    expect(renderedTitles()).toEqual(['About', 'Careers', 'Home', 'Services']);
  });

  it.each([
    ['internal path', '/page/5'],
    ['path alias', '/home'],
  ])(
    'pins the homepage set by %s to the top and keeps the rest in order',
    (_, homepagePath) => {
      expect(renderedTitles(homepagePath)).toEqual([
        'Home',
        'About',
        'Careers',
        'Services',
      ]);
    },
  );
});
