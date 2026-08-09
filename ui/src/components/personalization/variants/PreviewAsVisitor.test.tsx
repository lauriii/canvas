import { Provider } from 'react-redux';
import { describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { makeStore } from '@/app/store';
import {
  addVariant,
  NodeType,
  personalizeComponent,
  personalizePage,
  setInitialLayoutModel,
} from '@/features/layout/layoutModelSlice';
import {
  findRootSwitch,
  findSwitchNodes,
} from '@/features/layout/personalizationUtils';

import PreviewAsVisitor from './PreviewAsVisitor';

import type { AppStore } from '@/app/store';
import type { ComponentNode } from '@/features/layout/layoutModelSlice';
import type * as PersonalizationService from '@/services/personalization';

const mocks = vi.hoisted(() => ({
  segments: {
    default: {
      id: 'default',
      label: 'Everyone (default)',
      status: true,
      weight: 10,
    },
    coupon: {
      id: 'coupon',
      label: 'Coupon shoppers',
      status: true,
      weight: 0,
      rules: {
        query_parameter: {
          id: 'query_parameter',
          negate: false,
          parameter: 'coupon',
          value: 'WEEKEND',
          matching: 'exact',
        },
      },
    },
    belgium: {
      id: 'belgium',
      label: 'Belgian visitors',
      status: true,
      weight: 1,
      rules: {
        geolocation: { id: 'geolocation', negate: false, countries: ['BE'] },
      },
    },
    weekend: {
      id: 'weekend',
      label: 'Weekend visitors',
      status: true,
      weight: 2,
      rules: {
        day_of_week: {
          id: 'day_of_week',
          negate: false,
          days: ['saturday', 'sunday'],
        },
      },
    },
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

const personalizeStore = (store: AppStore): string => {
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
  return rootSwitch.uuid;
};

const addSegmentVariant = (
  store: AppStore,
  switchUuid: string,
  variantId: string,
  segments: string[],
) => {
  store.dispatch(
    addVariant({ switchUuid, variantId, segments, sourceVariantId: 'default' }),
  );
};

const renderPreview = (
  store: AppStore,
  getSwitchLabel: (switchNode: ComponentNode) => string = () => 'Page',
) =>
  render(
    <Provider store={store}>
      <Theme>
        <PreviewAsVisitor getSwitchLabel={getSwitchLabel} />
      </Theme>
    </Provider>,
  );

const openPanel = async (user: ReturnType<typeof userEvent.setup>) => {
  await user.click(screen.getByRole('button', { name: 'Preview as visitor' }));
};

describe('PreviewAsVisitor', () => {
  it('offers only the inputs the page audiences use', async () => {
    const user = userEvent.setup();
    const store = buildStore();
    const switchUuid = personalizeStore(store);
    addSegmentVariant(store, switchUuid, 'offer', ['coupon']);
    addSegmentVariant(store, switchUuid, 'belgium_offer', ['belgium']);
    addSegmentVariant(store, switchUuid, 'weekend_offer', ['weekend']);
    renderPreview(store);

    await openPanel(user);

    expect(screen.getByLabelText('coupon')).toBeInTheDocument();
    expect(screen.getByLabelText('Country')).toBeInTheDocument();
    expect(screen.getByLabelText('Day')).toBeInTheDocument();
    expect(
      screen.queryByText(
        'The audiences on this page do not use visitor conditions yet.',
      ),
    ).not.toBeInTheDocument();
  });

  it('omits inputs no referenced segment uses', async () => {
    const user = userEvent.setup();
    const store = buildStore();
    const switchUuid = personalizeStore(store);
    addSegmentVariant(store, switchUuid, 'offer', ['coupon']);
    renderPreview(store);

    await openPanel(user);

    expect(screen.getByLabelText('coupon')).toBeInTheDocument();
    expect(screen.queryByLabelText('Country')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Day')).not.toBeInTheDocument();
  });

  it('explains when no audience uses visitor conditions', async () => {
    const user = userEvent.setup();
    const store = buildStore();
    personalizeStore(store);
    renderPreview(store);

    await openPanel(user);

    expect(
      screen.getByText(
        'The audiences on this page do not use visitor conditions yet.',
      ),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: 'Show this in the preview' }),
    ).not.toBeInTheDocument();
  });

  it('updates the outcome as the visitor changes', async () => {
    const user = userEvent.setup();
    const store = buildStore();
    const switchUuid = personalizeStore(store);
    addSegmentVariant(store, switchUuid, 'offer', ['coupon']);
    addSegmentVariant(store, switchUuid, 'belgium_offer', ['belgium']);
    addSegmentVariant(store, switchUuid, 'weekend_offer', ['weekend']);
    renderPreview(store);

    await openPanel(user);
    const outcome = screen.getByTestId('visitor-outcome');
    // The unconstrained defaults simulate a visitor with no coupon, an
    // unknown country, and no particular day.
    expect(outcome).toHaveTextContent('This visitor sees: Default');

    // An exact-match rule only matches the full value.
    await user.type(screen.getByLabelText('coupon'), 'WEEK');
    expect(outcome).toHaveTextContent('This visitor sees: Default');
    await user.type(screen.getByLabelText('coupon'), 'END');
    expect(outcome).toHaveTextContent('This visitor sees: Offer');
    // Opening a select while the text input keeps focus makes the select's
    // focus handoff fire outside act; moving focus first keeps it silent.
    await user.tab();

    // The coupon variant sits higher, so it wins over the country match
    // until the coupon is removed.
    await user.click(screen.getByLabelText('Country'));
    expect(
      screen.getByRole('option', { name: 'Anywhere else' }),
    ).toBeInTheDocument();
    await user.click(screen.getByRole('option', { name: 'Belgium (BE)' }));
    expect(outcome).toHaveTextContent('This visitor sees: Offer');
    await user.clear(screen.getByLabelText('coupon'));
    expect(outcome).toHaveTextContent('This visitor sees: Belgium offer');
  });

  it('ranks a day match below higher-priority variants', async () => {
    const user = userEvent.setup();
    const store = buildStore();
    const switchUuid = personalizeStore(store);
    addSegmentVariant(store, switchUuid, 'belgium_offer', ['belgium']);
    addSegmentVariant(store, switchUuid, 'weekend_offer', ['weekend']);
    renderPreview(store);

    await openPanel(user);
    const outcome = screen.getByTestId('visitor-outcome');

    await user.click(screen.getByLabelText('Day'));
    expect(screen.getByRole('option', { name: 'Any day' })).toBeInTheDocument();
    await user.click(screen.getByRole('option', { name: 'Saturday' }));
    expect(outcome).toHaveTextContent('This visitor sees: Weekend offer');

    await user.click(screen.getByLabelText('Country'));
    await user.click(screen.getByRole('option', { name: 'Belgium (BE)' }));
    expect(outcome).toHaveTextContent('This visitor sees: Belgium offer');
  });

  it('applies the outcome to every switch in the preview', async () => {
    const user = userEvent.setup();
    const store = buildStore();
    const rootSwitchUuid = personalizeStore(store);
    addSegmentVariant(store, rootSwitchUuid, 'offer', ['coupon']);
    // Personalize the hero component inside the default case, creating a
    // second, nested switch.
    store.dispatch(
      personalizeComponent({
        componentUuid: HERO_UUID,
        switchComponentType: 'p13n.switch@v1',
        caseComponentType: 'p13n.case@v1',
      }),
    );
    const componentSwitch = findSwitchNodes(
      store.getState().layoutModel.present.layout,
    ).find((switchNode) => switchNode.uuid !== rootSwitchUuid);
    if (!componentSwitch) {
      throw new Error('Expected personalizeComponent to create a switch.');
    }
    addSegmentVariant(store, componentSwitch.uuid, 'belgium_offer', [
      'belgium',
    ]);
    renderPreview(store, (switchNode) =>
      switchNode.uuid === rootSwitchUuid ? 'Page' : 'Hero',
    );

    await openPanel(user);
    await user.type(screen.getByLabelText('coupon'), 'WEEKEND');
    // Move focus off the input before using the select; see above.
    await user.tab();
    await user.click(screen.getByLabelText('Country'));
    await user.click(screen.getByRole('option', { name: 'Belgium (BE)' }));

    expect(screen.getByTestId('visitor-outcome')).toHaveTextContent(
      'This visitor sees: Page: Offer · Hero: Belgium offer',
    );

    await user.click(
      screen.getByRole('button', { name: 'Show this in the preview' }),
    );
    expect(store.getState().ui.previewedVariants).toEqual({
      [rootSwitchUuid]: 'offer',
      [componentSwitch.uuid]: 'belgium_offer',
    });
  });
});
