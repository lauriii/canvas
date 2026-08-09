import { Provider } from 'react-redux';
import { describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { makeStore } from '@/app/store';
import {
  addVariant,
  NodeType,
  personalizePage,
  setInitialLayoutModel,
} from '@/features/layout/layoutModelSlice';
import { findRootSwitch } from '@/features/layout/personalizationUtils';
import { setPreviewedVariant } from '@/features/ui/uiSlice';

import VariantContextBar from './VariantContextBar';

import type { AppStore } from '@/app/store';
import type * as PersonalizationService from '@/services/personalization';

vi.mock('@/services/personalization', async (importOriginal) => {
  const actual = await importOriginal<typeof PersonalizationService>();
  return {
    ...actual,
    useGetSegmentsQuery: () => ({
      data: {
        returning: {
          id: 'returning',
          label: 'Returning visitors',
          status: true,
          weight: 0,
        },
      },
      isLoading: false,
      error: undefined,
    }),
  };
});

const HERO_UUID = 'hero-uuid';

const buildPersonalizedStore = (): { store: AppStore; switchUuid: string } => {
  const store = makeStore();
  store.dispatch(
    setInitialLayoutModel({
      layout: [
        {
          nodeType: NodeType.Region,
          id: 'content',
          name: 'Content',
          components: [
            {
              nodeType: NodeType.Component,
              uuid: HERO_UUID,
              type: 'sdc.hero@1',
              slots: [],
            },
          ],
        },
      ],
      model: { [HERO_UUID]: { resolved: { title: 'Hello' } } },
      updatePreview: false,
      isInitialized: true,
      translations: {},
    }),
  );
  store.dispatch(
    personalizePage({
      switchComponentType: 'p13n.switch@v1',
      caseComponentType: 'p13n.case@v1',
    }),
  );
  const rootSwitch = findRootSwitch(
    store.getState().layoutModel.present.layout[0],
  );
  if (!rootSwitch) {
    throw new Error('Expected personalizePage to create a root switch.');
  }
  store.dispatch(
    addVariant({
      switchUuid: rootSwitch.uuid,
      variantId: 'offer',
      segments: ['returning'],
      sourceVariantId: 'default',
    }),
  );
  return { store, switchUuid: rootSwitch.uuid };
};

const renderBar = (store: AppStore) =>
  render(
    <Provider store={store}>
      <Theme>
        <VariantContextBar />
      </Theme>
    </Provider>,
  );

describe('VariantContextBar', () => {
  it('stays hidden while every switch previews the default variant', () => {
    const { store } = buildPersonalizedStore();
    renderBar(store);

    expect(screen.queryByTestId('variant-context-bar')).toBeNull();
  });

  it('names the previewed variant and its audience', () => {
    const { store, switchUuid } = buildPersonalizedStore();
    store.dispatch(setPreviewedVariant({ switchUuid, variantId: 'offer' }));
    renderBar(store);

    const bar = screen.getByTestId('variant-context-bar');
    expect(bar).toHaveTextContent('Editing variant: Offer');
    expect(bar).toHaveTextContent('Audience: Returning visitors');
  });

  it('returns every switch to the default variant', async () => {
    const user = userEvent.setup();
    const { store, switchUuid } = buildPersonalizedStore();
    store.dispatch(setPreviewedVariant({ switchUuid, variantId: 'offer' }));
    renderBar(store);

    await user.click(screen.getByRole('button', { name: 'Back to default' }));

    expect(store.getState().ui.previewedVariants[switchUuid]).toBe('default');
    expect(screen.queryByTestId('variant-context-bar')).toBeNull();
  });
});
