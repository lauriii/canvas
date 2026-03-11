import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import EmptyStateCallout from '@/components/EmptyStateCallout';

describe('EmptyStateCallout', () => {
  it('renders a compact callout when only the title is provided', () => {
    const store = makeStore();

    render(
      <AppWrapper store={store} location="/" path="/">
        <EmptyStateCallout title="No items to show in Code" />
      </AppWrapper>,
    );

    expect(screen.getByText('No items to show in Code')).toBeInTheDocument();
    expect(screen.queryByText('Helpful description')).not.toBeInTheDocument();
  });

  it('renders a description below the title when provided', () => {
    const store = makeStore();

    render(
      <AppWrapper store={store} location="/" path="/">
        <EmptyStateCallout
          title="No fonts uploaded yet."
          description="Helpful description"
        />
      </AppWrapper>,
    );

    expect(screen.getByText('No fonts uploaded yet.')).toBeInTheDocument();
    expect(screen.getByText('Helpful description')).toBeInTheDocument();
  });
});
