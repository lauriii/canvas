// @todo: Port tests from code-editor-component-data-slots.cy.jsx to this file since we want to move away from
//    Cypress unit tests toward vitest. https://www.drupal.org/i/3523490.
import { describe, expect, it } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import { selectCodeComponentProperty } from '@/features/code-editor/codeEditorSlice';

import Slots from './Slots';

const store = makeStore({});

const Wrapper = () => (
  <AppWrapper
    store={store}
    location="/code-editor/code/test_component"
    path="/code-editor/code/:codeComponentId"
  >
    <Slots />
  </AppWrapper>
);

describe('Slots', () => {
  it('renders empty', () => {
    render(<Wrapper />);
    expect(
      screen.queryByRole('textbox', { name: 'Slot name' }),
    ).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Add' })).toBeInTheDocument();
  });

  it('add new slot form', async () => {
    render(<Wrapper />);
    await userEvent.click(screen.getByRole('button', { name: 'Add' }));
    expect(
      screen.getByRole('textbox', { name: 'Slot name' }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('textbox', { name: /Example/ }),
    ).toBeInTheDocument();
    expect(
      selectCodeComponentProperty('slots')(store.getState()).length,
    ).toEqual(1);
  });

  it('remove slot form', async () => {
    render(<Wrapper />);
    await userEvent.click(screen.getByRole('button', { name: /Remove slot/ }));
    expect(
      screen.queryByRole('textbox', { name: 'Slot name' }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole('textbox', { name: /Example/ }),
    ).not.toBeInTheDocument();
    expect(
      selectCodeComponentProperty('slots')(store.getState()).length,
    ).toEqual(0);
  });

  it('saves slot data', async () => {
    render(<Wrapper />);
    await userEvent.click(screen.getByRole('button', { name: 'Add' }));
    expect(
      screen.getByRole('textbox', { name: 'Slot name' }),
    ).toBeInTheDocument();
    await userEvent.type(
      screen.getByRole('textbox', { name: 'Slot name' }),
      'Alpha',
    );
    await userEvent.type(
      screen.getByRole('textbox', { name: /Example/ }),
      'Alpha example',
    );
    const values = selectCodeComponentProperty('slots')(store.getState());
    expect(values[0]).toMatchObject({
      name: 'Alpha',
      example: 'Alpha example',
    });
  });

  it('reorders slots', async () => {
    render(<Wrapper />);
    await userEvent.click(screen.getByRole('button', { name: 'Add' }));
    expect(screen.getByTestId('slot-0')).toBeInTheDocument();
    expect(screen.getByTestId('slot-1')).toBeInTheDocument();

    const slot2 = screen.getByTestId('slot-1');

    await userEvent.type(
      within(slot2).getByRole('textbox', { name: 'Slot name' }),
      'Beta',
    );

    expect(
      selectCodeComponentProperty('slots')(store.getState())[0].name,
    ).toEqual('Alpha');
    expect(
      selectCodeComponentProperty('slots')(store.getState())[1].name,
    ).toEqual('Beta');

    await userEvent.click(
      within(slot2).getByRole('button', { name: /Move slot/ }),
    );
    await userEvent.keyboard('[Space]');
    await userEvent.keyboard('[ArrowUp]');

    expect(
      selectCodeComponentProperty('slots')(store.getState())[0].name,
    ).toEqual('Beta');
    expect(
      selectCodeComponentProperty('slots')(store.getState())[1].name,
    ).toEqual('Alpha');
  });
});
