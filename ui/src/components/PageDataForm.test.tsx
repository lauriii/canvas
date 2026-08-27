import { useEffect } from 'react';
import { ErrorBoundary } from 'react-error-boundary';
import { Provider } from 'react-redux';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render } from '@testing-library/react';

import { makeStore } from '@/app/store';
import PageDataFormRenderer from '@/components/PageDataForm';
import { setInitialPageData } from '@/features/pageData/pageDataSlice';

// Records the template each form instance was mounted with, so the test can
// tell a fresh mount from an in-place update.
const mountedWith: string[] = [];

const MountProbe = ({ template }: { template: string }) => {
  useEffect(() => {
    mountedWith.push(template);
    // Deliberately mount-only: form elements that take their value from the
    // markup read it once, when they mount.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  return <div data-testid="mount-probe">{template}</div>;
};

let formQueryState: { currentData?: string; isFetching: boolean } = {
  isFetching: false,
};

vi.mock('@/utils/parse-hyperscriptify-template', () => ({
  default: (html: string) => ({ html }),
}));

vi.mock('@/local_packages/hyperscriptify', () => ({
  default: (template: { html: string }) => (
    <MountProbe template={template.html} />
  ),
}));

vi.mock('@/hooks/useDrupalBehaviors', () => ({
  useDrupalBehaviors: () => {},
}));

vi.mock('react-router', async () => {
  const actual = await vi.importActual('react-router');
  return {
    ...actual,
    useParams: () => ({ entityId: '1', entityType: 'canvas_page' }),
  };
});

vi.mock('@/services/pageDataForm', async () => {
  const actual = await vi.importActual('@/services/pageDataForm');
  return {
    ...actual,
    useGetPageDataFormQuery: () => ({ ...formQueryState, refetch: () => {} }),
  };
});

vi.mock('@/services/pageVariants', async () => {
  const actual = await vi.importActual('@/services/pageVariants');
  return {
    ...actual,
    useGetDefaultPageVariantQuery: () => ({ data: undefined }),
  };
});

vi.mock('@/services/componentAndLayout', async () => {
  const actual = await vi.importActual('@/services/componentAndLayout');
  return {
    ...actual,
    useGetPageLayoutQuery: () => ({ isFetching: false }),
  };
});

describe('PageDataFormRenderer', () => {
  beforeEach(() => {
    mountedWith.length = 0;
  });

  it('mounts the form from the newly fetched template, not the previous one', () => {
    const store = makeStore();
    store.dispatch(setInitialPageData({ 'title[0][value]': 'About' }));
    // A fresh element every time: React bails out of re-rendering a child when
    // the element it is given is referentially identical.
    const form = () => (
      <Provider store={store}>
        <MemoryRouter>
          <ErrorBoundary fallbackRender={() => <div />}>
            <PageDataFormRenderer />
          </ErrorBoundary>
        </MemoryRouter>
      </Provider>
    );

    formQueryState = { currentData: 'template-a', isFetching: false };
    const { rerender } = render(form());
    expect(mountedWith).toEqual(['template-a']);

    // The form is invalidated and refetched, for example after publishing.
    // RTK Query keeps serving the previous data while the request is in flight.
    formQueryState = { currentData: 'template-a', isFetching: true };
    rerender(form());

    formQueryState = { currentData: 'template-b', isFetching: false };
    rerender(form());

    // The refetched template is what gets mounted. Mounting the previous
    // template first would leave markup-driven widgets, such as a multi-value
    // select, showing the values the refetch replaced.
    expect(mountedWith).toEqual(['template-a', 'template-b']);
  });
});
