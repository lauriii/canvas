import { Provider } from 'react-redux';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { makeStore } from '@/app/store';
import {
  addVariant,
  NodeType,
  personalizeComponent,
  personalizePage,
  setInitialLayoutModel,
  setVariantDisabled,
} from '@/features/layout/layoutModelSlice';
import {
  findRootSwitch,
  findSwitchNodes,
  getCaseVariantId,
  getSwitchCases,
  getSwitchVariants,
} from '@/features/layout/personalizationUtils';

import VariantsMenu from './VariantsMenu';

import type { AppStore } from '@/app/store';
import type * as ComponentAndLayoutService from '@/services/componentAndLayout';
import type * as PersonalizationService from '@/services/personalization';

const mocks = vi.hoisted(() => {
  const baseSegments = {
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
  };
  return {
    baseSegments,
    segments: baseSegments as Record<string, (typeof baseSegments)['default']>,
    components: {
      'p13n.switch': { id: 'p13n.switch', version: 'v1', name: 'Switch' },
      'p13n.case': { id: 'p13n.case', version: 'v1', name: 'Case' },
      'sdc.hero': { id: 'sdc.hero', version: '1', name: 'Hero' },
    },
  };
});

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
        <MemoryRouter>
          <VariantsMenu />
        </MemoryRouter>
      </Theme>
    </Provider>,
  );

describe('VariantsMenu', () => {
  beforeEach(() => {
    mocks.segments = mocks.baseSegments;
    // The first-run explainer dismissal persists in localStorage.
    window.localStorage.clear();
  });

  it('personalizes the page after confirmation', async () => {
    const user = userEvent.setup();
    const store = buildStore();
    renderMenu(store);

    await user.click(screen.getByRole('button', { name: 'Personalize' }));
    expect(
      screen.getByText(
        'This wraps the current page in a default variant. You can then add variants for specific audiences.',
      ),
    ).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Personalize page' }));

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

  it('does not personalize the page when the confirmation is cancelled', async () => {
    const user = userEvent.setup();
    const store = buildStore();
    renderMenu(store);

    await user.click(screen.getByRole('button', { name: 'Personalize' }));
    await user.click(screen.getByRole('button', { name: 'Cancel' }));

    expect(findRootSwitch(getPresent(store).layout[0])).toBeNull();
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
    expect(trigger).toHaveTextContent('Variant: Default');
    await user.click(trigger);

    const rows = screen.getAllByTestId(/^variant-row-/);
    expect(rows.map((row) => row.getAttribute('data-testid'))).toEqual([
      'variant-row-offer',
      'variant-row-default',
    ]);

    await user.click(screen.getByRole('radio', { name: 'Offer' }));
    expect(store.getState().ui.previewedVariants[switchUuid]).toBe('offer');
    expect(
      screen.getByRole('button', { name: 'Manage variants' }),
    ).toHaveTextContent('Variant: Offer');
  });

  it('shows the audience of every variant and of the previewed variant', async () => {
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
    // A variant whose segment no longer exists falls back to the raw ID.
    store.dispatch(
      addVariant({
        switchUuid,
        variantId: 'ghost_offer',
        segments: ['missing_segment'],
        sourceVariantId: 'default',
      }),
    );
    renderMenu(store);

    await user.click(screen.getByRole('button', { name: 'Manage variants' }));

    expect(screen.getByTestId('variant-row-offer')).toHaveTextContent(
      'Audience: Returning visitors',
    );
    expect(screen.getByTestId('variant-row-default')).toHaveTextContent(
      'Everyone (fallback)',
    );
    const ghostRow = screen.getByTestId('variant-row-ghost_offer');
    expect(ghostRow).toHaveTextContent('Audience: missing_segment');
    expect(
      within(ghostRow).getByTitle('Missing segment: missing_segment'),
    ).toBeInTheDocument();

    // The previewed variant reads from the trigger and the checked radio;
    // there is no separate "previewing" line.
    expect(
      screen.queryByTestId('previewed-variant-audience'),
    ).not.toBeInTheDocument();
    await user.click(screen.getByRole('radio', { name: 'Offer' }));
    expect(screen.getByRole('radio', { name: 'Offer' })).toBeChecked();
  });

  it('flags a targeted segment that is disabled', async () => {
    mocks.segments = {
      ...mocks.baseSegments,
      dormant: {
        id: 'dormant',
        label: 'Dormant visitors',
        status: false,
        weight: 1,
      },
    };
    const user = userEvent.setup();
    const store = buildStore();
    const switchUuid = personalizeStore(store);
    store.dispatch(
      addVariant({
        switchUuid,
        variantId: 'dormant_offer',
        segments: ['dormant'],
        sourceVariantId: 'default',
      }),
    );
    renderMenu(store);

    await user.click(screen.getByRole('button', { name: 'Manage variants' }));

    const row = screen.getByTestId('variant-row-dormant_offer');
    const label = within(row).getByTitle(
      'Segment is disabled and never matches',
    );
    expect(label).toHaveTextContent('Dormant visitors');
    expect(label).toHaveAttribute('data-accent-color', 'amber');
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
    // The default segment is the fallback and is not offered as an audience.
    expect(
      screen.queryByRole('checkbox', { name: 'Everyone (default)' }),
    ).not.toBeInTheDocument();
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

  it('guides to segment creation when no non-default segments exist', async () => {
    mocks.segments = { default: mocks.baseSegments.default };
    const user = userEvent.setup();
    const store = buildStore();
    personalizeStore(store);
    renderMenu(store);

    await user.click(screen.getByRole('button', { name: 'Manage variants' }));
    await user.click(screen.getByRole('button', { name: 'New variant' }));

    expect(
      screen.getByRole('link', {
        name: 'Create a segment first to target an audience.',
      }),
    ).toHaveAttribute('href', '/segments');
    // Without an audience to target, creation stays disabled.
    await user.type(screen.getByLabelText('Name'), 'Offer');
    expect(screen.getByRole('button', { name: 'Create' })).toBeDisabled();
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
      screen.getByRole('button', { name: 'Open Offer variant menu' }),
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
      screen.getByRole('button', { name: 'Open Offer variant menu' }),
    );
    await user.click(screen.getByRole('menuitem', { name: 'Delete variant' }));
    // The confirmation shows the humanized variant name.
    expect(
      screen.getByRole('heading', { name: 'Delete Offer variant' }),
    ).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Delete variant' }));

    const { layout, model } = getPresent(store);
    expect(getSwitchVariants(model, switchUuid)).toEqual(['default']);
    expect(getSwitchCases(findRootSwitch(layout[0])!)).toHaveLength(1);
  });

  it('states the first-match rule in the popover', async () => {
    const user = userEvent.setup();
    const store = buildStore();
    personalizeStore(store);
    renderMenu(store);

    await user.click(screen.getByRole('button', { name: 'Manage variants' }));

    expect(
      screen.getByText(
        'Visitors see the first variant whose audience matches, top to bottom.',
      ),
    ).toBeInTheDocument();
  });

  it('shows the first-run explainer until it is dismissed', async () => {
    const user = userEvent.setup();
    const store = buildStore();
    personalizeStore(store);
    const { unmount } = renderMenu(store);

    await user.click(screen.getByRole('button', { name: 'Manage variants' }));
    const explainer = screen.getByTestId('personalization-explainer');
    expect(explainer).toHaveTextContent(
      'Each variant targets an audience (segments).',
    );
    expect(explainer).toHaveTextContent(
      'Visitors see the first matching variant, top to bottom.',
    );
    expect(explainer).toHaveTextContent(
      'The Default variant is the fallback for everyone else.',
    );

    await user.click(
      within(explainer).getByRole('button', { name: 'Dismiss' }),
    );
    expect(
      screen.queryByTestId('personalization-explainer'),
    ).not.toBeInTheDocument();
    expect(
      window.localStorage.getItem('canvas.personalization.explainerDismissed'),
    ).toBe('true');

    // The dismissal persists across mounts.
    unmount();
    renderMenu(store);
    await user.click(screen.getByRole('button', { name: 'Manage variants' }));
    expect(
      screen.queryByTestId('personalization-explainer'),
    ).not.toBeInTheDocument();
  });

  it('offers the visitor simulation inside the popover', async () => {
    const user = userEvent.setup();
    const store = buildStore();
    personalizeStore(store);
    renderMenu(store);

    await user.click(screen.getByRole('button', { name: 'Manage variants' }));
    expect(
      screen.getByRole('button', { name: 'Preview as visitor' }),
    ).toBeInTheDocument();
  });

  it('lists each switch as a section when the layout has multiple switches', async () => {
    const user = userEvent.setup();
    const store = buildStore();
    const rootSwitchUuid = personalizeStore(store);
    // Personalize the hero component inside the default case, creating a
    // second, nested switch.
    store.dispatch(
      personalizeComponent({
        componentUuid: HERO_UUID,
        switchComponentType: 'p13n.switch@v1',
        caseComponentType: 'p13n.case@v1',
      }),
    );
    const componentSwitch = findSwitchNodes(getPresent(store).layout).find(
      (switchNode) => switchNode.uuid !== rootSwitchUuid,
    );
    if (!componentSwitch) {
      throw new Error('Expected personalizeComponent to create a switch.');
    }
    store.dispatch(
      addVariant({
        switchUuid: componentSwitch.uuid,
        variantId: 'offer',
        segments: ['returning'],
        sourceVariantId: 'default',
      }),
    );
    renderMenu(store);

    await user.click(screen.getByRole('button', { name: 'Manage variants' }));

    // The root switch is labeled "Page"; the component switch is named
    // after the component it personalizes.
    const pageSection = screen.getByTestId(
      `variant-switch-section-${rootSwitchUuid}`,
    );
    const componentSection = screen.getByTestId(
      `variant-switch-section-${componentSwitch.uuid}`,
    );
    expect(within(pageSection).getByText('Page')).toBeInTheDocument();
    expect(within(componentSection).getByText('Hero')).toBeInTheDocument();
    // Every switch lists its own rows and its own create action.
    expect(screen.getAllByTestId('variant-row-default')).toHaveLength(2);
    expect(
      within(componentSection).getByTestId('variant-row-offer'),
    ).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: 'New variant' })).toHaveLength(
      2,
    );

    // Selecting a variant only affects that section's switch.
    await user.click(
      within(componentSection).getByRole('radio', { name: 'Offer' }),
    );
    expect(store.getState().ui.previewedVariants[componentSwitch.uuid]).toBe(
      'offer',
    );
    expect(
      store.getState().ui.previewedVariants[rootSwitchUuid] ?? 'default',
    ).toBe('default');
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
