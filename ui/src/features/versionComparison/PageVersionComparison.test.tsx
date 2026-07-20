import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render } from '@testing-library/react';

import { PageVersionComparison } from './PageVersionComparison';

const mocks = vi.hoisted(() => ({
  layoutRequests: vi.fn(),
}));

vi.mock('@/services/componentAndLayout', () => ({
  useGetConflictPageLayoutQuery: (arg: unknown) => {
    mocks.layoutRequests(arg);
    const data = {
      html: '<main>Version preview</main>',
      entity_form_fields: {},
      layout: [],
      model: {},
      updated: 1_777_000_000,
    };
    return {
      data,
      currentData: data,
      isFetching: false,
      isError: false,
    };
  },
}));

vi.mock('@/features/versionComparison/PageVersionComparisonView', () => ({
  PageVersionComparisonView: () => (
    <div data-testid="page-version-comparison-view" />
  ),
}));

describe('PageVersionComparison', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('uses the draft version key to isolate the auto-save layout query cache', () => {
    render(
      <PageVersionComparison
        entityId="1"
        entityType="canvas_page"
        draftVersionKey="hash-1:1777000000"
      />,
    );

    expect(mocks.layoutRequests).toHaveBeenCalledWith({
      entityId: '1',
      entityType: 'canvas_page',
      versionKey: 'hash-1:1777000000',
    });
    expect(mocks.layoutRequests).toHaveBeenCalledWith({
      entityId: '1',
      entityType: 'canvas_page',
      publishedVersion: true,
    });
  });
});
