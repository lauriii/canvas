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
  setVariantDisabled,
} from '@/features/layout/layoutModelSlice';
import {
  findRootSwitch,
  getCaseVariantId,
  getSwitchCases,
  getSwitchVariants,
} from '@/features/layout/personalizationUtils';

import VariantsMenu from './VariantsMenu';

import type { AppStore } from '@/app/store';
import type * as ComponentAndLayoutService from '@/services/componentAndLayout';
import type * as PersonalizationService from '@/services/personalization';

const mocks = vi.hoisted(() => ({
  segments: {
    default: {
      id: 'default',
      label: 'Everyone (default)',
      status: true,
      weight: 10,
    },
    returning: {
      id: 'returning',
      label: 'Returning visitors',
      status: true,
      weight: 0,
    },
  },
  components: {
    'p13n.switch': { id: 'p13n.switch', version: 'v1', name: 'Switch' },
    'p13n.case': { id: 'p13n.case', version: 'v1', name: 'Case' },
  },
}));

vi.mock('@/services/personalization', async (importOriginal) => {
  const actual = await importOriginal<typeof PersonalizationService>();
  return {
    ...actual,
    useGetSegmentsQuery: () => ({
      data: mocks.segments,
      isLoading: false,
      error: undefined,
    }),
  };
});

vi.mock('@/services/componentAndLayout', async (importOriginal) => {
  const actual = await importOriginal<typeof ComponentAndLayoutService>();
  return {
    ...actual,
    useGetComponentsQuery: () => ({
      data: mocks.components,
      isLoading: false,
      error: undefined,
    }),
  };
});

const HERO_UUID = 'hero-uuid';

const buildStore = (): AppStore => {
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
  return store;
};

const getPresent = (store: AppStore) => store.getState().layoutModel.present;

const personalizeStore = (store: AppStore): string => {
  store.dispatch(
    personalizePage({
      switchComponentType: 'p13n.switch@v1',
      caseComponentType: 'p13n.case@v1',
    }),
  );
  const rootSwitch = findRootSwitch(getPresent(store).layout[0]);
  if (!rootSwitch) {
    throw new Error('Expected personalizePage to create a root switch.');
  }
  return rootSwitch.uuid;
};

const renderMenu = (store: AppStore) =>
  render(
    <Provider store={store}>
      <Theme>
        <VariantsMenu />
      </Theme>
    </Provider>,
  );

describe('VariantsMenu', () => {
  it('personalizes the page through the menu action', async () => {
    const user = userEvent.setup();
    const store = buildStore();
    renderMenu(store);

    await user.click(screen.getByRole('button', { name: 'Personalize' }));
    await user.click(
      screen.getByRole('menuitem', { name: 'Personalize this page' }),
    );

    const { layout, model } = getPresent(store);
    const rootSwitch = findRootSwitch(layout[0]);
    expect(rootSwitch).not.toBeNull();
    expect(getSwitchVariants(model, rootSwitch!.uuid)).toEqual(['default']);
    const cases = getSwitchCases(rootSwitch!);
    expect(cases).toHaveLength(1);
    // The existing page content moved into the default case unchanged.
    expect(
      cases[0].slots[0].components.map((component) => component.uuid),
    ).toEqual([HERO_UUID]);
  });

  it('lists variants in priority order and selects the previewed variant', async () => {
    const user = userEvent.setup();
    const store = buildStore();
    const switchUuid = personalizeStore(store);
    store.dispatch(
      addVariant({
        switchUuid,
        variantId: 'offer',
        segments: ['returning'],
        sourceVariantId: 'default',
      }),
    );
    renderMenu(store);

    const trigger = screen.getByRole('button', { name: 'Manage variants' });
    expect(trigger).toHaveTextContent('Variant: default');
    await user.click(trigger);

    const rows = screen.getAllByTestId(/^variant-row-/);
    expect(rows.map((row) => row.getAttribute('data-testid'))).toEqual([
      'variant-row-offer',
      'variant-row-default',
    ]);

    await user.click(screen.getByRole('radio', { name: 'offer' }));
    expect(store.getState().ui.previewedVariants[switchUuid]).toBe('offer');
    expect(
      screen.getByRole('button', { name: 'Manage variants' }),
    ).toHaveTextContent('Variant: offer');
  });

  it('creates a variant from the dialog and previews it', async () => {
    const user = userEvent.setup();
    const store = buildStore();
    const switchUuid = personalizeStore(store);
    renderMenu(store);

    await user.click(screen.getByRole('button', { name: 'Manage variants' }));
    await user.click(screen.getByRole('button', { name: 'New variant' }));
    await user.type(screen.getByLabelText('Name'), 'Returning offer');
    expect(
      screen.getByText('Machine name: returning_offer'),
    ).toBeInTheDocument();
    await user.click(
      screen.getByRole('checkbox', { name: 'Returning visitors' }),
    );
    await user.click(screen.getByRole('button', { name: 'Create' }));

    const { layout, model } = getPresent(store);
    expect(getSwitchVariants(model, switchUuid)).toEqual([
      'returning_offer',
      'default',
    ]);
    const cases = getSwitchCases(findRootSwitch(layout[0])!);
    expect(model[cases[0].uuid].resolved).toEqual({
      variant_id: 'returning_offer',
      segments: ['returning'],
    });
    expect(store.getState().ui.previewedVariants[switchUuid]).toBe(
      'returning_offer',
    );
  });

  it('promotes a variant to default from the variant menu', async () => {
    const user = userEvent.setup();
    const store = buildStore();
    const switchUuid = personalizeStore(store);
    store.dispatch(
      addVariant({
        switchUuid,
        variantId: 'offer',
        segments: ['returning'],
        sourceVariantId: 'default',
      }),
    );
    const beforeCases = getSwitchCases(
      findRootSwitch(getPresent(store).layout[0])!,
    );
    const offerCaseUuid = beforeCases[0].uuid;
    renderMenu(store);

    await user.click(screen.getByRole('button', { name: 'Manage variants' }));
    await user.click(
      screen.getByRole('button', { name: 'Open offer variant menu' }),
    );
    await user.click(
      screen.getByRole('menuitem', { name: 'Promote to default' }),
    );

    const { layout, model } = getPresent(store);
    expect(getSwitchVariants(model, switchUuid)).toEqual(['offer', 'default']);
    const cases = getSwitchCases(findRootSwitch(layout[0])!);
    // The promoted case now sits last and is the default variant.
    expect(cases[1].uuid).toBe(offerCaseUuid);
    expect(getCaseVariantId(model, cases[1])).toBe('default');
  });

  it('deletes a variant after confirmation', async () => {
    const user = userEvent.setup();
    const store = buildStore();
    const switchUuid = personalizeStore(store);
    store.dispatch(
      addVariant({
        switchUuid,
        variantId: 'offer',
        segments: ['returning'],
        sourceVariantId: 'default',
      }),
    );
    renderMenu(store);

    await user.click(screen.getByRole('button', { name: 'Manage variants' }));
    await user.click(
      screen.getByRole('button', { name: 'Open offer variant menu' }),
    );
    await user.click(screen.getByRole('menuitem', { name: 'Delete variant' }));
    await user.click(screen.getByRole('button', { name: 'Delete variant' }));

    const { layout, model } = getPresent(store);
    expect(getSwitchVariants(model, switchUuid)).toEqual(['default']);
    expect(getSwitchCases(findRootSwitch(layout[0])!)).toHaveLength(1);
  });

  it('dims disabled variants in the list', async () => {
    const user = userEvent.setup();
    const store = buildStore();
    const switchUuid = personalizeStore(store);
    store.dispatch(
      addVariant({
        switchUuid,
        variantId: 'offer',
        segments: ['returning'],
        sourceVariantId: 'default',
      }),
    );
    store.dispatch(
      setVariantDisabled({ switchUuid, variantId: 'offer', disabled: true }),
    );
    renderMenu(store);

    await user.click(screen.getByRole('button', { name: 'Manage variants' }));
    expect(screen.getByTestId('variant-row-offer')).toHaveStyle({
      opacity: '0.6',
    });
    expect(screen.getByText('Disabled')).toBeInTheDocument();
    expect(screen.getByTestId('variant-row-default')).toHaveStyle({
      opacity: '1',
    });
  });
});
