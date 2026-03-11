import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import ReviewErrors from '@/components/review/ReviewErrors';

import type { ErrorResponse } from '@/services/pendingChangesApi';

vi.mock('@assets/icons/brand-kit.svg?react', () => ({
  default: (props: Record<string, unknown>) => (
    <svg data-testid="brand-kit-icon" {...props} />
  ),
}));

const renderReviewErrors = (errorState: ErrorResponse) => {
  const store = makeStore();

  render(
    <AppWrapper store={store} location="/" path="*">
      <ReviewErrors errorState={errorState} />
    </AppWrapper>,
  );
};

describe('ReviewErrors', () => {
  it('does not render an editor link for Brand kit publish errors', () => {
    renderReviewErrors({
      errors: [
        {
          code: 3,
          detail:
            'When publishing components you must also publish the Global CSS and any pending Brand kit changes. Please select them and retry.',
          source: {
            pointer: 'brand_kit:global',
          },
          meta: {
            entity_type: 'brand_kit',
            entity_id: 'global',
            label: 'Global brand kit',
          },
        },
      ],
    });

    expect(screen.getByText('Brand kit')).toBeInTheDocument();
    expect(screen.queryByTestId('publish-error-link')).not.toBeInTheDocument();
  });

  it('keeps editor links for routable entity types', () => {
    const componentUuid = '7f0b0d6d-5ec6-4f1a-bf53-0c5df5e2d2a1';

    renderReviewErrors({
      errors: [
        {
          code: 2,
          detail: 'Component publish failed.',
          source: {
            pointer: `model.components.${componentUuid}`,
          },
          meta: {
            entity_type: 'js_component',
            entity_id: 'hero_banner',
            label: 'Hero banner',
          },
        },
      ],
    });

    expect(screen.getByText('Hero banner')).toBeInTheDocument();
    expect(screen.getByTestId('publish-error-link')).toHaveAttribute(
      'href',
      `/editor/js_component/hero_banner/component/${componentUuid}`,
    );
  });
});
