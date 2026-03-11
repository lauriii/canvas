import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import ChangeGroup from '@/components/review/changes/ChangeGroup';

import type { UnpublishedChange } from '@/types/Review';

vi.mock('@assets/icons/brand-kit.svg?react', () => ({
  default: (props: Record<string, unknown>) => (
    <svg data-testid="brand-kit-icon" {...props} />
  ),
}));

const brandKitChange: UnpublishedChange = {
  pointer: 'brand_kit:global',
  label: 'Global brand kit',
  updated: 1_730_000_000,
  entity_type: 'brand_kit',
  data_hash: 'brand-kit-hash',
  entity_id: 'global',
  langcode: 'en',
  owner: {
    name: 'Test user',
    avatar: null,
    id: 1,
    uri: '/user/1',
  },
};

describe('ChangeGroup', () => {
  it('renders Brand kit rows under the Assets group', () => {
    const store = makeStore();

    render(
      <AppWrapper store={store} location="/" path="*">
        <ChangeGroup
          entityType="asset_library"
          changes={[brandKitChange]}
          isBusy={false}
          selectedChanges={[]}
          setSelectedChanges={vi.fn()}
          onDiscardClick={vi.fn()}
        />
      </AppWrapper>,
    );

    expect(screen.getByText('Assets')).toBeInTheDocument();
    expect(screen.getByText('Brand kit')).toBeInTheDocument();
    expect(
      screen.getByLabelText('Select all changes in Assets'),
    ).toBeInTheDocument();
  });
});
