// @todo: Port tests from code-editor-component-data-props.cy.jsx to this file since we want to move away from
//    Cypress unit tests toward vitest. https://www.drupal.org/i/3523490.
import { describe, expect, it } from 'vitest';
import {
  act,
  cleanup,
  fireEvent,
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
import { getPropMachineName } from '@/features/code-editor/utils/utils';
import { type CodeComponentPropImageExample } from '@/types/CodeComponent';

import Props, { REQUIRED_EXAMPLE_ERROR_MESSAGE } from './Props';

import type { AppStore } from '@/app/store';

let store: AppStore;

const Wrapper = ({ store }: { store: AppStore }) => (
  <AppWrapper
    store={store}
    location="/code-editor/code/test_component"
    path="/code-editor/code/:codeComponentId"
  >
    <Props />
  </AppWrapper>
);

/**
 * Helper function to add a new prop with a specified type and name.
 *
 * It can be called multiple times to add multiple props.
 *
 * @param typeDisplayName - The display name of the prop type (e.g., 'Text', 'Integer', 'Image')
 * @param propName - The name to assign to the prop
 */
const addProp = async (typeDisplayName: string, propName: string) => {
  await userEvent.click(screen.getByRole('button', { name: 'Add' }));

  await waitFor(() => {
    expect(
      screen.getAllByRole('textbox', { name: 'Prop name' }).length,
    ).toBeGreaterThan(0);
  });

  const allPropNameInputs = screen.getAllByRole('textbox', {
    name: 'Prop name',
  });
  const allTypeSelects = screen.getAllByRole('combobox', { name: 'Type' });
  const lastIndex = allPropNameInputs.length - 1;

  await userEvent.click(allTypeSelects[lastIndex]);
  const option = await screen.findByRole('option', { name: typeDisplayName });
  await userEvent.click(option);

  await waitFor(() => {
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  await userEvent.type(allPropNameInputs[lastIndex], propName);
};

describe('props in code editor', () => {
  beforeEach(() => {
    store = makeStore({}); // Create a fresh store for each test
    render(<Wrapper store={store} />);
  });

  describe('props form', () => {
    it('renders empty', async () => {
      expect(
        screen.queryByRole('textbox', { name: 'Prop name' }),
      ).not.toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Add' })).toBeInTheDocument();
    });

    it('adds and removes props', async () => {
      await userEvent.click(screen.getByRole('button', { name: 'Add' }));
      expect(
        screen.getByRole('textbox', { name: 'Prop name' }),
      ).toBeInTheDocument();
      expect(
        screen.getByRole('textbox', { name: 'Example value' }),
      ).toBeInTheDocument();
      expect(
        screen.getByRole('combobox', { name: 'Type' }),
      ).toBeInTheDocument();
      expect(
        screen.getByRole('switch', { name: 'Required' }),
      ).toBeInTheDocument();

      expect(
        selectCodeComponentProperty('props')(store.getState()).length,
      ).toEqual(1);

      await userEvent.click(
        screen.getByRole('button', { name: /Remove prop/ }),
      );
      expect(
        screen.queryByRole('textbox', { name: 'Prop name' }),
      ).not.toBeInTheDocument();
      expect(
        screen.queryByRole('textbox', { name: 'Example value' }),
      ).not.toBeInTheDocument();
      expect(
        screen.queryByRole('combobox', { name: 'Type' }),
      ).not.toBeInTheDocument();
      expect(
        screen.queryByRole('switch', { name: 'Required' }),
      ).not.toBeInTheDocument();

      expect(
        selectCodeComponentProperty('props')(store.getState()).length,
      ).toEqual(0);
    });

    it('saves prop data', async () => {
      await userEvent.click(screen.getByRole('button', { name: 'Add' }));
      expect(
        screen.getByRole('textbox', { name: 'Prop name' }),
      ).toBeInTheDocument();
      await userEvent.type(
        screen.getByRole('textbox', { name: 'Prop name' }),
        'Alpha',
      );
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].name,
      ).toEqual('Alpha');
      await userEvent.type(
        screen.getByRole('textbox', { name: 'Example value' }),
        'Alpha value',
      );
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].example,
      ).toEqual('Alpha value');
    });

    it('sets required', async () => {
      await userEvent.click(screen.getByRole('button', { name: 'Add' }));
      await waitFor(() => {
        expect(
          screen.getByRole('textbox', { name: 'Prop name' }),
        ).toBeInTheDocument();
      });
      const propName = selectCodeComponentProperty('props')(store.getState())[0]
        .name;
      await userEvent.click(screen.getByRole('switch', { name: 'Required' }));
      expect(screen.getByRole('switch', { name: 'Required' })).toBeChecked();
      expect(
        selectCodeComponentProperty('required')(store.getState())[0],
      ).toEqual(getPropMachineName(propName));
    });

    it('changing type clears example data', async () => {
      await addProp('Integer', 'Alpha');
      await waitFor(() => {
        expect(
          screen.getByRole('textbox', { name: 'Prop name' }),
        ).toBeInTheDocument();
      });

      await userEvent.click(screen.getByRole('combobox', { name: 'Type' }));
      await userEvent.click(screen.getByRole('option', { name: 'Text' }));

      expect(
        selectCodeComponentProperty('props')(store.getState())[0].example,
      ).toEqual('');

      await userEvent.type(
        screen.getByRole('textbox', { name: 'Example value' }),
        'Alpha value',
      );
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].example,
      ).toEqual('Alpha value');

      await userEvent.click(screen.getByRole('combobox', { name: 'Type' }));
      await userEvent.click(
        screen.getByRole('option', { name: 'Formatted text' }),
      );
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].example,
      ).toEqual('');
    });
    it('reorders props', async () => {
      await addProp('Text', 'Alpha');
      await addProp('Text', 'Beta');

      expect(
        selectCodeComponentProperty('props')(store.getState())[0].name,
      ).toEqual('Alpha');
      expect(
        selectCodeComponentProperty('props')(store.getState())[1].name,
      ).toEqual('Beta');

      const prop2 = screen.getByTestId('prop-1');
      const dragHandle = within(prop2).getByRole('button', {
        name: /Move prop/,
      });
      // Focus the drag handle
      dragHandle.focus();
      // Activate with Space
      await userEvent.keyboard('[Space]');
      // Wait for drag to activate
      await waitFor(() => {
        expect(dragHandle).toHaveAttribute('aria-pressed', 'true');
      });
      // Move up
      await userEvent.keyboard('[ArrowUp]');
      // Commit with Space
      await userEvent.keyboard('[Space]');
      // Wait for reorder
      await waitFor(() => {
        expect(
          selectCodeComponentProperty('props')(store.getState())[0].name,
        ).toEqual('Beta');
      });
      expect(
        selectCodeComponentProperty('props')(store.getState())[1].name,
      ).toEqual('Alpha');
    });
  });

  describe('prop types', () => {
    it('creates a new integer prop', async () => {
      await addProp('Integer', 'Count');
      expect(screen.getByLabelText('Example value')).toHaveAttribute(
        'placeholder',
        'Enter an integer',
      );
      const integerInput = screen.getByRole('spinbutton', {
        name: 'Example value',
      });
      await userEvent.type(
        integerInput,
        'Typing an invalid string value with hopefully no effect',
      );
      expect(integerInput).toHaveValue(null);
      await userEvent.clear(integerInput);
      await userEvent.type(integerInput, '922');
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          name: 'Count',
          type: 'integer',
          example: '922',
          format: undefined,
          $ref: undefined,
        });
      });
    });

    it('creates a new number prop', async () => {
      await addProp('Number', 'Percentage');
      expect(screen.getByLabelText('Example value')).toHaveAttribute(
        'placeholder',
        'Enter a number',
      );
      const numberInput = screen.getByRole('spinbutton', {
        name: 'Example value',
      });
      await userEvent.type(
        numberInput,
        'Typing an invalid string value with hopefully no effect',
      );
      expect(numberInput).toHaveValue(null);
      await userEvent.clear(numberInput);
      await userEvent.type(numberInput, '9.22');
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          name: 'Percentage',
          type: 'number',
          example: '9.22',
          format: undefined,
          $ref: undefined,
        });
      });
    });

    it('creates a new formatted text prop', async () => {
      await addProp('Formatted text', 'Description');
      expect(screen.getByLabelText('Example value')).toHaveAttribute(
        'placeholder',
        'Enter a text value',
      );
      await userEvent.type(
        screen.getByRole('textbox', { name: 'Example value' }),
        'Your description goes here',
      );
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          name: 'Description',
          type: 'string',
          example: 'Your description goes here',
          format: undefined,
          contentMediaType: 'text/html',
          'x-formatting-context': 'block',
        });
      });
    });

    it('creates a new text prop', async () => {
      await addProp('Text', 'Title');
      expect(screen.getByLabelText('Example value')).toHaveAttribute(
        'placeholder',
        'Enter a text value',
      );
      await userEvent.type(
        screen.getByRole('textbox', { name: 'Example value' }),
        'Your title goes here',
      );
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          name: 'Title',
          type: 'string',
          example: 'Your title goes here',
          format: undefined,
          $ref: undefined,
        });
      });
    });

    it('creates a new link prop (relative path and full url)', async () => {
      await addProp('Link', 'Link');
      expect(screen.getByLabelText('Example value')).toHaveAttribute(
        'placeholder',
        'Enter a path',
      );
      await userEvent.type(
        screen.getByRole('textbox', { name: 'Example value' }),
        'gerbeaud',
      );
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          name: 'Link',
          type: 'string',
          example: 'gerbeaud',
          format: 'uri-reference',
          $ref: undefined,
        });
      });

      // Change the Link type to "Full URL".
      await userEvent.click(
        screen.getByRole('combobox', { name: 'Link type' }),
      );
      const fullUrlOption = await screen.findByRole('option', {
        name: 'Full URL',
      });
      await userEvent.click(fullUrlOption);

      // Wait for the dropdown to close and store to update
      await waitFor(() => {
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
      });

      await waitFor(() => {
        expect(screen.getByLabelText('Example value')).toHaveAttribute(
          'placeholder',
          'Enter a URL',
        );
      });

      await userEvent.clear(
        screen.getByRole('textbox', { name: 'Example value' }),
      );
      await userEvent.type(
        screen.getByRole('textbox', { name: 'Example value' }),
        'https://example.com',
      );

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          name: 'Link',
          type: 'string',
          example: 'https://example.com',
          format: 'uri',
          $ref: undefined,
        });
      });
    });

    it('creates a new image prop', async () => {
      await addProp('Image', 'Image');
      await waitFor(() => {
        expect(screen.getByLabelText('Example aspect ratio')).toHaveTextContent(
          '4:3 (Standard)',
        );
        expect(screen.getByLabelText('Pixel density')).toHaveTextContent(
          '2x (High density)',
        );
      });
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          type: 'object',
          example: {
            src: 'https://placehold.co/800x600@2x.png?alternateWidths=https%3A%2F%2Fplacehold.co%2F%7Bwidth%7Dx%7Bheight%7D%402x.png',
            width: 800,
            height: 600,
            alt: 'Example image placeholder',
          },
          format: undefined,
          $ref: 'json-schema-definitions://canvas.module/image',
        });
      });
      // Select the "None" option for the example aspect ratio.
      await userEvent.click(screen.getByLabelText('Example aspect ratio'));
      const noneOption = await screen.findByRole('option', {
        name: '- None -',
      });
      await userEvent.click(noneOption);

      await waitFor(() => {
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
      });
      // The pixel density should now be hidden.
      expect(screen.queryByLabelText('Pixel density')).not.toBeInTheDocument();

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          type: 'object',
          example: '',
          format: undefined,
          $ref: 'json-schema-definitions://canvas.module/image',
        });
      });
      // Set the prop as required.
      await userEvent.click(screen.getByLabelText('Required'));

      // The example aspect ratio and pixel density should now be the default values.
      await waitFor(() => {
        expect(screen.getByLabelText('Example aspect ratio')).toHaveTextContent(
          '4:3 (Standard)',
        );
        expect(screen.getByLabelText('Pixel density')).toHaveTextContent(
          '2x (High density)',
        );
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          type: 'object',
          example: {
            src: 'https://placehold.co/800x600@2x.png?alternateWidths=https%3A%2F%2Fplacehold.co%2F%7Bwidth%7Dx%7Bheight%7D%402x.png',
            width: 800,
            height: 600,
            alt: 'Example image placeholder',
          },
          format: undefined,
          $ref: 'json-schema-definitions://canvas.module/image',
        });
      });
      // Update the aspect ratio.
      await userEvent.click(screen.getByLabelText('Example aspect ratio'));
      const widescreenOption = await screen.findByRole('option', {
        name: '16:9 (Widescreen)',
      });
      await userEvent.click(widescreenOption);

      await waitFor(() => {
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
      });

      await waitFor(() => {
        expect(screen.getByLabelText('Example aspect ratio')).toHaveTextContent(
          '16:9 (Widescreen)',
        );
      });
      // Update the pixel density.
      await userEvent.click(screen.getByLabelText('Pixel density'));
      const ultraHighOption = await screen.findByRole('option', {
        name: '3x (Ultra-high density)',
      });
      await userEvent.click(ultraHighOption);

      await waitFor(() => {
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
      });

      await waitFor(() => {
        expect(screen.getByLabelText('Pixel density')).toHaveTextContent(
          '3x (Ultra-high density)',
        );
      });
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          type: 'object',
          example: {
            src: 'https://placehold.co/1280x720@3x.png?alternateWidths=https%3A%2F%2Fplacehold.co%2F%7Bwidth%7Dx%7Bheight%7D%403x.png',
            width: 1280,
            height: 720,
            alt: 'Example image placeholder',
          },
          format: undefined,
          $ref: 'json-schema-definitions://canvas.module/image',
        });
      });
      // Set the prop as not required, then back to required.
      await userEvent.click(screen.getByLabelText('Required'));
      await userEvent.click(screen.getByLabelText('Required'));

      // The example aspect ratio and pixel density should be the previous values.
      expect(screen.getByLabelText('Example aspect ratio')).toHaveTextContent(
        '16:9 (Widescreen)',
      );
      expect(screen.getByLabelText('Pixel density')).toHaveTextContent(
        '3x (Ultra-high density)',
      );
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          type: 'object',
          example: {
            src: 'https://placehold.co/1280x720@3x.png?alternateWidths=https%3A%2F%2Fplacehold.co%2F%7Bwidth%7Dx%7Bheight%7D%403x.png',
            width: 1280,
            height: 720,
            alt: 'Example image placeholder',
          },
          format: undefined,
          $ref: 'json-schema-definitions://canvas.module/image',
        });
      });
    });

    it('creates a new video prop', async () => {
      await addProp('Video', 'Video');
      await waitFor(() => {
        expect(screen.getByLabelText('Example aspect ratio')).toHaveTextContent(
          '16:9 (Widescreen)',
        );
      });
      await userEvent.click(
        screen.getByRole('combobox', { name: 'Example aspect ratio' }),
      );
      const widescreenOption = await screen.findByRole('option', {
        name: '16:9 (Widescreen)',
      });
      await userEvent.click(widescreenOption);

      // Wait for listbox to close
      await waitFor(() => {
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          type: 'object',
          example: {
            src: `/ui/assets/videos/mountain_wide.mp4`,
            poster: 'https://placehold.co/1920x1080.png?text=Widescreen',
          },
          format: undefined,
          $ref: 'json-schema-definitions://canvas.module/video',
        });
      });

      await userEvent.click(
        screen.getByRole('combobox', { name: 'Example aspect ratio' }),
      );
      const noneOption = await screen.findByRole('option', {
        name: '- None -',
      });
      await userEvent.click(noneOption);
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          type: 'object',
          example: '',
          format: undefined,
          $ref: 'json-schema-definitions://canvas.module/video',
        });
      });

      await userEvent.click(noneOption);
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          type: 'object',
          example: '',
          format: undefined,
          $ref: 'json-schema-definitions://canvas.module/video',
        });
      });

      // Set the prop as required.
      await userEvent.click(screen.getByLabelText('Required'));

      // The example aspect ratio should now be the default values.
      expect(screen.getByLabelText('Example aspect ratio')).toHaveTextContent(
        '16:9 (Widescreen)',
      );
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          type: 'object',
          example: {
            src: `/ui/assets/videos/mountain_wide.mp4`,
            poster: 'https://placehold.co/1920x1080.png?text=Widescreen',
          },
          format: undefined,
          $ref: 'json-schema-definitions://canvas.module/video',
        });
      });
      // Update the aspect ratio.
      await userEvent.click(screen.getByLabelText('Example aspect ratio'));
      const verticalOption = await screen.findByRole('option', {
        name: '9:16 (Vertical)',
      });
      await userEvent.click(verticalOption);

      expect(screen.getByLabelText('Example aspect ratio')).toHaveTextContent(
        '9:16 (Vertical)',
      );
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          type: 'object',
          example: {
            src: `/ui/assets/videos/bird_vertical.mp4`,
            poster: 'https://placehold.co/1080x1920.png?text=Vertical',
          },
          format: undefined,
          $ref: 'json-schema-definitions://canvas.module/video',
        });
      });
      // Set the prop as not required, then back to required. The example aspect
      // ratio should be the previous values.
      await userEvent.click(screen.getByLabelText('Required'));
      await userEvent.click(screen.getByLabelText('Required'));

      expect(screen.getByLabelText('Example aspect ratio')).toHaveTextContent(
        '9:16 (Vertical)',
      );
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          type: 'object',
          example: {
            src: `/ui/assets/videos/bird_vertical.mp4`,
            poster: 'https://placehold.co/1080x1920.png?text=Vertical',
          },
          format: undefined,
          $ref: 'json-schema-definitions://canvas.module/video',
        });
      });
    });

    it('creates a new boolean prop', async () => {
      await addProp('Boolean', 'Is featured');
      await waitFor(() => {
        expect(
          screen.getByRole('switch', { name: 'Example value' }),
        ).toBeInTheDocument();
      });
      expect(
        screen.getByRole('switch', { name: 'Example value' }),
      ).not.toBeChecked();

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          name: 'Is featured',
          type: 'boolean',
          example: false,
          format: undefined,
          $ref: undefined,
        });
      });
      await userEvent.click(
        screen.getByRole('switch', { name: 'Example value' }),
      );
      expect(
        screen.getByRole('switch', { name: 'Example value' }),
      ).toBeChecked();

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          name: 'Is featured',
          type: 'boolean',
          example: true,
        });
      });
    });

    it('creates a new date and time prop (date only and datetime)', async () => {
      await addProp('Date and time', 'PublishDate');
      await waitFor(() => {
        expect(
          screen.getByRole('combobox', { name: 'Date type' }),
        ).toBeInTheDocument();
      });

      // Switch to Date only
      await userEvent.click(
        screen.getByRole('combobox', { name: 'Date type' }),
      );
      const dateOnlyOption = await screen.findByRole('option', {
        name: 'Date only',
      });
      await userEvent.click(dateOnlyOption);

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.format).toEqual('date');
      });

      await waitFor(() => {
        expect(screen.getByLabelText('Example value')).toBeInTheDocument();
      });

      await act(async () => {
        fireEvent.change(screen.getByLabelText('Example value'), {
          target: { value: '2026-01-15' },
        });
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          name: 'PublishDate',
          type: 'string',
          example: '2026-01-15',
          format: 'date',
          $ref: undefined,
        });
      });

      // Switch to Date and time
      await userEvent.click(
        screen.getByRole('combobox', { name: 'Date type' }),
      );
      const dateTimeOption = await screen.findByRole('option', {
        name: 'Date and time',
      });
      await userEvent.click(dateTimeOption);

      await act(async () => {
        fireEvent.change(screen.getByLabelText('Example value'), {
          target: { value: '2026-01-15T12:34' },
        });
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          name: 'PublishDate',
          type: 'string',
          example: '2026-01-15T01:34:00.000Z',
          format: 'date-time',
          $ref: undefined,
        });
      });
    });

    it('creates a new text list prop', async () => {
      await addProp('List: text', 'Tags');
      await waitFor(() => {
        expect(
          screen.getByRole('button', { name: 'Add value' }),
        ).toBeInTheDocument();
      });
      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;
      expect(screen.queryByLabelText('Default value')).not.toBeInTheDocument();

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          name: 'Tags',
          type: 'string',
          enum: [],
          format: undefined,
          $ref: undefined,
        });
      });

      // Add a new value, make sure "Default value" is not shown yet while the new value is empty.
      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      expect(screen.queryByLabelText('Default value')).not.toBeInTheDocument();

      // Type a value, make sure "Default value" is shown.
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
        'Alpha',
      );
      await waitFor(() => {
        expect(screen.getByLabelText('Default value')).toBeInTheDocument();
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.enum).toEqual([{ label: 'Alpha', value: 'Alpha' }]);
      });
      // Clear the value, make sure "Default value" is not shown.
      await userEvent.clear(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
      );
      expect(screen.queryByLabelText('Default value')).not.toBeInTheDocument();

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.enum).toEqual([{ label: '', value: '' }]);
      });
      // Type a value, then add two more values.
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
        'Alpha',
      );
      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-1`),
        'Bravo',
      );
      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-2`),
        'Charlie',
      );
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.enum).toEqual([
          { label: 'Alpha', value: 'Alpha' },
          { label: 'Bravo', value: 'Bravo' },
          { label: 'Charlie', value: 'Charlie' },
        ]);
      });
      // Set the prop as required. "Default value" now should have the first value selected.
      await userEvent.click(screen.getByLabelText('Required'));

      await waitFor(() => {
        expect(screen.getByLabelText('Default value')).toHaveTextContent(
          'Alpha',
        );
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          example: 'Alpha',
        });
      });
      // Clear the first value that is also currently the selected default value.
      await userEvent.clear(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
      );
      // Verify that the default value is now the second value, and that the
      // dropdown has the remaining values.
      await waitFor(() => {
        expect(screen.getByLabelText('Default value')).toHaveTextContent(
          'Bravo',
        );
      });
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          example: 'Bravo',
          enum: [
            { label: '', value: '' },
            { label: 'Bravo', value: 'Bravo' },
            { label: 'Charlie', value: 'Charlie' },
          ],
        });
      });
      await userEvent.click(screen.getByLabelText('Default value'));
      expect(
        await screen.findByRole('option', { name: 'Bravo' }),
      ).toBeInTheDocument();
      expect(
        screen.getByRole('option', { name: 'Charlie' }),
      ).toBeInTheDocument();
      // Select the third value as default while the dropdown is open.
      await userEvent.click(screen.getByRole('option', { name: 'Charlie' }));
      await waitFor(() => {
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
      });
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual('Charlie');
      });

      // Modify the third value.
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-2`),
        'Zulu',
      );
      // The default value should change to the first valid value after the previous default value was modified.
      await waitFor(() => {
        expect(screen.getByLabelText('Default value')).toHaveTextContent(
          'Bravo',
        );
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual('Bravo');
      });
      // Modify the second value — currently default.
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-1`),
        'Yankee',
      );
      // The modified version should become the new default value.
      await waitFor(() => {
        expect(screen.getByLabelText('Default value')).toHaveTextContent(
          'BravoYankee',
        );
      });
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual('BravoYankee');
      });

      // Delete the first value.
      await userEvent.click(
        screen.getByTestId(`canvas-prop-enum-value-delete-${propId}-0`),
      );
      await waitFor(() => {
        expect(
          screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
        ).toHaveValue('BravoYankee');
        expect(
          screen.getByTestId(`canvas-prop-enum-value-${propId}-1`),
        ).toHaveValue('CharlieZulu');
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          example: 'BravoYankee',
          enum: [
            { label: 'BravoYankee', value: 'BravoYankee' },
            { label: 'CharlieZulu', value: 'CharlieZulu' },
          ],
        });
      });
      // Delete the first value. It was previously used as the default value.
      await userEvent.click(
        screen.getByTestId(`canvas-prop-enum-value-delete-${propId}-0`),
      );

      await waitFor(() => {
        expect(screen.getByLabelText('Default value')).toHaveTextContent(
          'CharlieZulu',
        );
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual('CharlieZulu');
      });

      // Modify the first value. Make sure the default value follows it.
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
        'XRay',
      );
      await waitFor(() => {
        expect(screen.getByLabelText('Default value')).toHaveTextContent(
          'CharlieZuluXRay',
        );
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual('CharlieZuluXRay');
      });

      // Set the prop as not required.
      await userEvent.click(screen.getByLabelText('Required'));

      // Modify the first value. The default value should now be removed.
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
        'Whiskey',
      );
      await waitFor(() => {
        expect(screen.getByLabelText('Default value')).toHaveTextContent(
          '- None -',
        );
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual('');
      });

      // Set the prop as required again. The default value should now be the first value.
      await userEvent.click(screen.getByLabelText('Required'));

      await waitFor(() => {
        expect(screen.getByLabelText('Default value')).toHaveTextContent(
          'CharlieZuluXRayWhiskey',
        );
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual('CharlieZuluXRayWhiskey');
      });
      // Delete the one last remaining value. "Default value" should not be visible.
      await userEvent.click(
        screen.getByTestId(`canvas-prop-enum-value-delete-${propId}-0`),
      );

      expect(screen.queryByLabelText('Default value')).not.toBeInTheDocument();

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          enum: [],
          example: '',
        });
      });
      // User should be warned that each value must be unique.
      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
        'Same',
      );
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-1`),
        'Same',
      );
      await waitFor(() => {
        expect(screen.getAllByText('Value must be unique.')).toHaveLength(2);
      });

      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-1`),
        '... not!',
      );

      await waitFor(() => {
        expect(
          screen.queryByText('Value must be unique.'),
        ).not.toBeInTheDocument();
      });
    });

    it('creates a new integer list prop', async () => {
      // The 'creates a new text list prop' test case already covers the
      // functionality of adding and removing values. This test is here as a sanity
      // check that the integer type works as expected. The only difference is that
      // we can't add a string value.
      await addProp('List: integer', 'Level');
      await waitFor(() => {
        expect(
          screen.getByRole('button', { name: 'Add value' }),
        ).toBeInTheDocument();
      });
      expect(screen.queryByLabelText('Default value')).not.toBeInTheDocument();
      // Add a new value, make sure "Default value" is not shown yet while the
      // new value is empty.
      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      expect(screen.queryByLabelText('Default value')).not.toBeInTheDocument();
      // Ensure we can't type a string value.
      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
        'Typing an invalid string value with hopefully no effect',
      );
      expect(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
      ).toHaveAttribute('value', '');

      // Clear before typing a valid integer value.
      await userEvent.clear(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
      );
      // Type a valid integer value.
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
        '1',
      );
      expect(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
      ).toHaveValue(1);
      expect(screen.queryByLabelText('Default value')).toBeInTheDocument();

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).containSubset({
          enum: [{ label: '1', value: '1' }],
          example: '',
        });
      });
      // Set the prop as required. "Default value" now should have the value
      // selected.
      await userEvent.click(screen.getByRole('switch', { name: 'Required' }));
      expect(screen.getByLabelText('Default value')).toHaveTextContent('1');
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).containSubset({
          enum: [{ label: '1', value: '1' }],
          example: '1',
        });
      });
      // Add a second value
      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-1`),
        '2',
      );
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).containSubset({
          enum: [
            { label: '1', value: '1' },
            { label: '2', value: '2' },
          ],
          example: '1',
        });
      });
      // Set the second value as the default value.
      await userEvent.click(screen.getByLabelText('Default value'));
      const option2 = await screen.findByRole('option', { name: '2' });
      await userEvent.click(option2);

      await waitFor(() => {
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          enum: [
            { label: '1', value: '1' },
            { label: '2', value: '2' },
          ],
          example: '2',
        });
      });
      // Delete the second value.
      await userEvent.click(
        screen.getByTestId(`canvas-prop-enum-value-delete-${propId}-1`),
      );
      await waitFor(() => {
        expect(screen.getByLabelText('Default value')).toHaveTextContent('1');
      });
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          enum: [{ label: '1', value: '1' }],
          example: '1',
        });
      });
    });
  });

  describe('image data', () => {
    const aspectRatios = [
      ['1:1 (Square)', { width: 600, height: 600 }],
      ['4:3 (Standard)', { width: 800, height: 600 }],
      ['16:9 (Widescreen)', { width: 1280, height: 720 }],
      ['3:2 (Classic Photo)', { width: 900, height: 600 }],
      ['21:9 (Ultrawide)', { width: 1400, height: 600 }],
      ['9:16 (Vertical)', { width: 720, height: 1280 }],
      ['2:1 (Panoramic)', { width: 1000, height: 500 }],
    ] as const;
    const selectAspectRatio = async (size: string) => {
      await userEvent.click(
        screen.getByRole('combobox', { name: 'Example aspect ratio' }),
      );
      await userEvent.click(screen.getByRole('option', { name: size }));
    };
    const selectPixelDensity = async (density: string) => {
      await userEvent.click(
        screen.getByRole('combobox', { name: 'Pixel density' }),
      );
      await userEvent.click(
        screen.getByRole('option', { name: new RegExp(density) }),
      );
    };
    const getImageExample = () =>
      selectCodeComponentProperty('props')(store.getState())[0]
        .example as CodeComponentPropImageExample;
    it.each(aspectRatios)('%s', async (size, expectedSize) => {
      await addProp('Image', 'Image');
      await selectAspectRatio(size);
      let example = getImageExample();
      expect(example).toMatchObject(expectedSize);
      expect(example.src).toMatch(
        new RegExp(`${expectedSize.width}x${expectedSize.height}@2x\\.png`),
      );
      await selectPixelDensity('1x');
      example = getImageExample();
      expect(example.src).toMatch(
        new RegExp(`${expectedSize.width}x${expectedSize.height}\\.png\\?`),
      );
      await selectPixelDensity('3x');
      example = getImageExample();
      expect(example.src).toMatch(
        new RegExp(`${expectedSize.width}x${expectedSize.height}@3x\\.png`),
      );
    });
  });

  describe('prop form for exposed component', () => {
    const existingPropId = 'existing-prop-id';

    // Clean up the render from the parent beforeEach
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
            props: [
              {
                id: existingPropId,
                name: 'Alpha',
                type: 'string',
                example: 'test value',
                derivedType: 'text',
              },
            ],
          },
          initialPropIds: [existingPropId], // Mark as existing prop
        },
      });
    };

    it('disables name and type field for existing props on exposed component', async () => {
      const exposedStore = createExposedComponentStore();
      render(<Wrapper store={exposedStore} />);

      const nameField = screen.getByRole('textbox', { name: 'Prop name' });
      expect(nameField).toBeDisabled();
      const typeSelect = screen.getByRole('combobox', { name: 'Type' });
      expect(typeSelect).toBeDisabled();
      const exampleField = screen.getByRole('textbox', {
        name: 'Example value',
      });
      expect(exampleField).not.toBeDisabled();
    });

    it('allows editing name and type for newly added props on an exposed component', async () => {
      const exposedStore = createExposedComponentStore();
      render(<Wrapper store={exposedStore} />);

      // Add a new prop
      await userEvent.click(screen.getByRole('button', { name: 'Add' }));

      await waitFor(() => {
        expect(
          screen.getAllByRole('textbox', { name: 'Prop name' }),
        ).toHaveLength(2);
      });

      // The second prop name field (newly added) should not be disabled
      const propNameFields = screen.getAllByRole('textbox', {
        name: 'Prop name',
      });
      expect(propNameFields[0]).toBeDisabled(); // Existing prop
      expect(propNameFields[1]).not.toBeDisabled(); // New prop
      // The second type select (newly added) should not be disabled
      const typeSelects = screen.getAllByRole('combobox', { name: 'Type' });
      expect(typeSelects[0]).toBeDisabled(); // Existing prop
      expect(typeSelects[1]).not.toBeDisabled(); // New prop
    });

    it('allows removing props on exposed component', async () => {
      const exposedStore = createExposedComponentStore();
      render(<Wrapper store={exposedStore} />);

      // Verify initial state - one existing prop
      expect(
        selectCodeComponentProperty('props')(exposedStore.getState()),
      ).toHaveLength(1);

      // Remove the prop
      await userEvent.click(
        screen.getByRole('button', { name: /Remove prop/ }),
      );

      // Verify prop was removed
      expect(
        selectCodeComponentProperty('props')(exposedStore.getState()),
      ).toHaveLength(0);
      expect(
        screen.queryByRole('textbox', { name: 'Prop name' }),
      ).not.toBeInTheDocument();
    });
  });

  describe('required prop example validation', () => {
    it('prefills default example when required is toggled on and shows error when cleared', async () => {
      await addProp('Text', 'Title');
      // Verify example is initially empty
      expect(
        screen.getByRole('textbox', { name: 'Example value' }),
      ).toHaveValue('');

      // Toggle required
      await userEvent.click(screen.getByRole('switch', { name: 'Required' }));

      // Verify default example is prefilled
      await waitFor(() => {
        expect(
          screen.getByRole('textbox', { name: 'Example value' }),
        ).toHaveValue('Example text');
      });

      // Clear the prefilled example
      await userEvent.clear(
        screen.getByRole('textbox', { name: 'Example value' }),
      );

      // Verify error message is shown
      expect(
        screen.getByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
      ).toBeInTheDocument();
    });

    it('clears validation error when required is toggled off', async () => {
      await addProp('Text', 'Title');
      await userEvent.click(screen.getByRole('switch', { name: 'Required' }));
      await userEvent.clear(
        screen.getByRole('textbox', { name: 'Example value' }),
      );

      // Error should be visible
      expect(
        screen.getByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
      ).toBeInTheDocument();

      // Toggle required off
      await userEvent.click(screen.getByRole('switch', { name: 'Required' }));

      // Error should be cleared
      expect(
        screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
      ).not.toBeInTheDocument();
    });

    it('prefills default example when changing type on required prop', async () => {
      await addProp('Text', 'MyProp');
      await userEvent.click(screen.getByRole('switch', { name: 'Required' }));

      // Change type to Integer
      await userEvent.click(screen.getByRole('combobox', { name: 'Type' }));
      await userEvent.click(screen.getByRole('option', { name: 'Integer' }));

      // Verify default example for integer is set
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual('0');
      });
    });

    it('prefills enum option when required is toggled on for list:text with no options', async () => {
      await addProp('List: text', 'Tags');
      await userEvent.click(screen.getByRole('switch', { name: 'Required' }));

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.enum).toEqual([{ value: 'option_1', label: 'Option 1' }]);
        expect(prop.example).toEqual('option_1');
      });
    });
  });
});
