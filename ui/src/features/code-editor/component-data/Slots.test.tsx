// @todo: Port tests from code-editor-component-data-slots.cy.jsx to this file since we want to move away from
//    Cypress unit tests toward vitest. https://www.drupal.org/i/3523490.
import { describe, expect, it } from 'vitest';
import {
  cleanup,
  render,
  screen,
  waitFor,
  within,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import {
  initialState,
  selectCodeComponentProperty,
} from '@/features/code-editor/codeEditorSlice';

import Slots from './Slots';

import type { AppStore } from '@/app/store';

let store: AppStore;

const Wrapper = ({ store }: { store: AppStore }) => (
  <AppWrapper
    store={store}
    location="/code-editor/code/test_component"
    path="/code-editor/code/:codeComponentId"
  >
    <Slots />
  </AppWrapper>
);

describe('Slots', () => {
  beforeEach(() => {
    store = makeStore({});
    render(<Wrapper store={store} />);
  });

  describe('slots form', () => {
    it('renders empty', () => {
      expect(
        screen.queryByRole('textbox', { name: 'Slot name' }),
      ).not.toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Add' })).toBeInTheDocument();
    });

    it('add new slot form', async () => {
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
      // First add a slot so we can remove it
      await userEvent.click(screen.getByRole('button', { name: 'Add' }));
      await userEvent.click(
        screen.getByRole('button', { name: /Remove slot/ }),
      );
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
      await userEvent.click(screen.getByRole('button', { name: 'Add' }));
      await userEvent.click(screen.getByRole('button', { name: 'Add' }));
      expect(screen.getByTestId('slot-0')).toBeInTheDocument();
      expect(screen.getByTestId('slot-1')).toBeInTheDocument();

      const slot1 = screen.getByTestId('slot-0');
      const slot2 = screen.getByTestId('slot-1');

      await userEvent.type(
        within(slot1).getByRole('textbox', { name: 'Slot name' }),
        'Alpha',
      );
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

  describe('slots form for exposed component', () => {
    const existingSlotId = 'existing-slot-id';

    beforeEach(() => {
      cleanup();
    });

    const createExposedComponentStore = () => {
      return makeStore({
        codeEditor: {
          ...initialState,
          codeComponent: {
            ...initialState.codeComponent,
            status: true, // Component is exposed
            slots: [
              {
                id: existingSlotId,
                name: 'ExistingSlot',
                example: '<p>Example content</p>',
              },
            ],
          },
          initialSlotIds: [existingSlotId], // Mark as existing slot
        },
      });
    };

    it('disables name field for existing slots on exposed component', async () => {
      const exposedStore = createExposedComponentStore();
      render(<Wrapper store={exposedStore} />);

      const nameField = screen.getByRole('textbox', { name: 'Slot name' });
      expect(nameField).toBeDisabled();
      expect(nameField).toHaveValue('ExistingSlot');
      const exampleField = screen.getByRole('textbox', { name: /Example/ });
      expect(exampleField).not.toBeDisabled();
    });

    it('allows editing name for newly added slot on exposed component', async () => {
      const exposedStore = createExposedComponentStore();
      render(<Wrapper store={exposedStore} />);

      // Click the Add button
      await userEvent.click(screen.getByRole('button', { name: 'Add' }));

      await waitFor(() => {
        expect(
          screen.getAllByRole('textbox', { name: 'Slot name' }),
        ).toHaveLength(2);
      });

      // The second slot name field (newly added) should not be disabled
      const slotNameFields = screen.getAllByRole('textbox', {
        name: 'Slot name',
      });
      expect(slotNameFields).toHaveLength(2);
      expect(slotNameFields[0]).toBeDisabled(); // Existing slot
      expect(slotNameFields[1]).not.toBeDisabled(); // New slot
    });

    it('allows removing slots on exposed component', async () => {
      const exposedStore = createExposedComponentStore();
      render(<Wrapper store={exposedStore} />);

      // Remove the slot
      await userEvent.click(
        screen.getByRole('button', { name: /Remove slot/ }),
      );

      // Verify slot was removed
      expect(
        selectCodeComponentProperty('slots')(exposedStore.getState()),
      ).toHaveLength(0);
      expect(
        screen.queryByRole('textbox', { name: 'Slot name' }),
      ).not.toBeInTheDocument();
    });
  });
});
