import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import SegmentDetails from './SegmentDetails';

import type { Segment, SegmentRules } from '@/types/Personalization';

const mocks = vi.hoisted(() => ({
  segment: undefined as Segment | undefined,
  updateSegment: vi.fn(async (_arg: unknown) => ({ data: {} })),
}));

vi.mock('@/services/personalization', () => ({
  useGetSegmentQuery: () => ({
    data: mocks.segment,
    isLoading: false,
    error: undefined,
  }),
  useUpdateSegmentMutation: () => [mocks.updateSegment, { isLoading: false }],
}));

const fullRules = (): SegmentRules => ({
  query_parameter: {
    id: 'query_parameter',
    negate: false,
    parameter: 'ref',
    value: 'news',
    matching: 'starts_with',
  },
  utm_parameters: {
    id: 'utm_parameters',
    negate: false,
    all: true,
    parameters: [{ key: 'utm_source', value: 'newsletter', matching: 'exact' }],
  },
  geolocation: {
    id: 'geolocation',
    negate: true,
    countries: ['US', 'CA'],
    regions: ['NY'],
  },
  day_of_week: {
    id: 'day_of_week',
    negate: false,
    days: ['monday', 'friday'],
  },
});

const makeSegment = (rules: SegmentRules): Segment => ({
  id: 'returning',
  label: 'Returning visitors',
  description: 'Visitors who have been here before',
  status: true,
  weight: 0,
  rules,
});

const renderDetails = () =>
  render(
    <Theme>
      <MemoryRouter initialEntries={['/segments/returning']}>
        <Routes>
          <Route path="/segments/:segmentId" element={<SegmentDetails />} />
        </Routes>
      </MemoryRouter>
    </Theme>,
  );

const saveRules = async (user: ReturnType<typeof userEvent.setup>) => {
  await user.click(screen.getByRole('button', { name: 'Save rules' }));
  await waitFor(() => {
    expect(mocks.updateSegment).toHaveBeenCalledTimes(1);
  });
  return mocks.updateSegment.mock.calls[0][0] as unknown as {
    id: string;
    changes: { rules: SegmentRules };
  };
};

describe('SegmentDetails', () => {
  beforeEach(() => {
    mocks.segment = makeSegment(fullRules());
  });

  it('renders the segment and the existing rule settings', () => {
    renderDetails();

    expect(
      screen.getByRole('heading', { name: 'Returning visitors' }),
    ).toBeInTheDocument();
    expect(
      screen.getByText('Visitors who have been here before'),
    ).toBeInTheDocument();
    expect(screen.getByText('Enabled')).toBeInTheDocument();

    // Query parameter rule.
    expect(screen.getByLabelText('Parameter name')).toHaveValue('ref');
    expect(screen.getByLabelText('Matching')).toHaveTextContent(
      'Starts with value',
    );
    expect(screen.getByLabelText('Value')).toHaveValue('news');

    // UTM parameters rule.
    expect(screen.getByLabelText('Parameter 1 name')).toHaveTextContent(
      'utm_source',
    );
    expect(screen.getByLabelText('Parameter 1 value')).toHaveValue(
      'newsletter',
    );
    expect(screen.getByLabelText('How parameters combine')).toHaveTextContent(
      'All parameters must match',
    );

    // Geolocation rule, negated. Countries render as name chips.
    expect(screen.getByText('United States (US)')).toBeInTheDocument();
    expect(screen.getByText('Canada (CA)')).toBeInTheDocument();
    expect(screen.getByLabelText('Countries')).toHaveValue('');
    expect(screen.getByLabelText('Regions (optional)')).toHaveValue('NY');
    expect(screen.getByTestId('rule-summary-geolocation')).toHaveTextContent(
      'Everyone except: the visitor is in US, CA (regions: NY)',
    );

    // Day of week rule.
    expect(screen.getByRole('checkbox', { name: 'Monday' })).toBeChecked();
    expect(screen.getByRole('checkbox', { name: 'Friday' })).toBeChecked();
    expect(screen.getByRole('checkbox', { name: 'Tuesday' })).not.toBeChecked();
  });

  it('saves an edited query parameter rule', async () => {
    const user = userEvent.setup();
    renderDetails();

    const valueField = screen.getByLabelText('Value');
    await user.clear(valueField);
    await user.type(valueField, 'promo');

    const { id, changes } = await saveRules(user);
    expect(id).toBe('returning');
    expect(changes.rules).toEqual({
      ...fullRules(),
      query_parameter: {
        id: 'query_parameter',
        negate: false,
        parameter: 'ref',
        value: 'promo',
        matching: 'starts_with',
      },
    });
  });

  it('saves a query parameter matching change made with the select', async () => {
    const user = userEvent.setup();
    renderDetails();

    await user.click(screen.getByLabelText('Matching'));
    await user.click(screen.getByRole('option', { name: 'Is present' }));
    // The value field only applies to matching by value.
    expect(screen.queryByLabelText('Value')).not.toBeInTheDocument();

    const { changes } = await saveRules(user);
    expect(changes.rules.query_parameter).toEqual({
      id: 'query_parameter',
      negate: false,
      parameter: 'ref',
      value: 'news',
      matching: 'present',
    });
  });

  it('saves an edited UTM parameters rule', async () => {
    const user = userEvent.setup();
    renderDetails();

    const valueField = screen.getByLabelText('Parameter 1 value');
    await user.clear(valueField);
    await user.type(valueField, 'weekly');

    const { changes } = await saveRules(user);
    expect(changes.rules).toEqual({
      ...fullRules(),
      utm_parameters: {
        id: 'utm_parameters',
        negate: false,
        all: true,
        parameters: [{ key: 'utm_source', value: 'weekly', matching: 'exact' }],
      },
    });
  });

  it('saves an edited geolocation rule from the country picker', async () => {
    const user = userEvent.setup();
    renderDetails();

    await user.click(screen.getByRole('button', { name: 'Remove Canada' }));
    await user.type(screen.getByLabelText('Countries'), 'germ');
    await user.click(screen.getByRole('option', { name: 'Germany (DE)' }));

    const { changes } = await saveRules(user);
    expect(changes.rules).toEqual({
      ...fullRules(),
      geolocation: {
        id: 'geolocation',
        negate: true,
        countries: ['US', 'DE'],
        regions: ['NY'],
      },
    });
  });

  it('suggests countries by name and adds removable chips', async () => {
    const user = userEvent.setup();
    renderDetails();

    const countriesField = screen.getByLabelText('Countries');
    await user.type(countriesField, 'belg');
    await user.click(screen.getByRole('option', { name: 'Belgium (BE)' }));

    // The chip shows the country name with its code, and the input clears
    // for the next search.
    expect(screen.getByText('Belgium (BE)')).toBeInTheDocument();
    expect(countriesField).toHaveValue('');

    // Already selected countries are not suggested again.
    await user.type(countriesField, 'belg');
    expect(
      screen.queryByRole('option', { name: 'Belgium (BE)' }),
    ).not.toBeInTheDocument();
    await user.clear(countriesField);

    const { changes } = await saveRules(user);
    expect(changes.rules.geolocation?.countries).toEqual(['US', 'CA', 'BE']);
  });

  it('saves an edited day of week rule in week order', async () => {
    const user = userEvent.setup();
    renderDetails();

    await user.click(screen.getByRole('checkbox', { name: 'Tuesday' }));

    const { changes } = await saveRules(user);
    expect(changes.rules).toEqual({
      ...fullRules(),
      day_of_week: {
        id: 'day_of_week',
        negate: false,
        days: ['monday', 'tuesday', 'friday'],
      },
    });
  });

  it('saves a negate toggle and a removed rule', async () => {
    const user = userEvent.setup();
    renderDetails();

    // The first rule card is the query parameter rule.
    await user.click(screen.getAllByRole('switch', { name: /Negate/ })[0]);
    // The last rule card is the day of week rule.
    await user.click(screen.getAllByRole('button', { name: 'Remove rule' })[3]);

    const { changes } = await saveRules(user);
    const expected = fullRules();
    delete expected.day_of_week;
    expect(changes.rules).toEqual({
      ...expected,
      query_parameter: { ...expected.query_parameter!, negate: true },
    });
  });

  it('discards unsaved rule edits', async () => {
    const user = userEvent.setup();
    renderDetails();

    const valueField = screen.getByLabelText('Value');
    await user.clear(valueField);
    await user.type(valueField, 'promo');
    await user.click(screen.getByRole('button', { name: 'Discard changes' }));

    expect(screen.getByLabelText('Value')).toHaveValue('news');
    expect(
      screen.queryByRole('button', { name: 'Save rules' }),
    ).not.toBeInTheDocument();
    expect(mocks.updateSegment).not.toHaveBeenCalled();
  });

  it('disables add-rule options for types that are already present', async () => {
    const user = userEvent.setup();
    renderDetails();

    await user.click(screen.getByRole('button', { name: 'Add rule' }));

    for (const name of [
      /Query parameter/,
      /UTM parameters/,
      /Location/,
      /Day of week/,
    ]) {
      expect(screen.getByRole('menuitem', { name })).toHaveAttribute(
        'aria-disabled',
        'true',
      );
    }
  });

  it('adds a rule of a type that is not present yet', async () => {
    mocks.segment = makeSegment({
      query_parameter: fullRules().query_parameter,
    });
    const user = userEvent.setup();
    renderDetails();

    await user.click(screen.getByRole('button', { name: 'Add rule' }));
    expect(
      screen.getByRole('menuitem', { name: /Query parameter/ }),
    ).toHaveAttribute('aria-disabled', 'true');
    const dayItem = screen.getByRole('menuitem', { name: /Day of week/ });
    expect(dayItem).not.toHaveAttribute('aria-disabled');
    await user.click(dayItem);

    await user.click(screen.getByRole('checkbox', { name: 'Saturday' }));
    const { changes } = await saveRules(user);
    expect(changes.rules).toEqual({
      query_parameter: fullRules().query_parameter,
      day_of_week: { id: 'day_of_week', negate: false, days: ['saturday'] },
    });
  });

  it('warns at the top when the segment is disabled', () => {
    mocks.segment = { ...makeSegment(fullRules()), status: false };
    renderDetails();

    expect(
      screen.getByText(
        'This segment is disabled. It never matches visitors until it is enabled.',
      ),
    ).toBeInTheDocument();
  });

  it('shows no disabled warning for an enabled segment', () => {
    renderDetails();

    expect(
      screen.queryByText(/This segment is disabled/),
    ).not.toBeInTheDocument();
  });

  it('toggles the segment status', async () => {
    const user = userEvent.setup();
    renderDetails();

    await user.click(screen.getByRole('button', { name: 'Disable segment' }));

    await waitFor(() => {
      expect(mocks.updateSegment).toHaveBeenCalledWith({
        id: 'returning',
        changes: { status: false },
      });
    });
  });
});
