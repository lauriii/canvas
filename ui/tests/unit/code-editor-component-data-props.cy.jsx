// ⚠️ Do not add new tests here. Add new tests to Prop.test.tsx instead.
// @todo: Port remaining tests from this file to unit tests in Prop.test.tsx.
//   https://www.drupal.org/i/3523490
import { Provider } from 'react-redux';
import { Theme } from '@radix-ui/themes';

import { makeStore } from '@/app/store';
import {
  addProp,
  selectCodeComponentProperty,
  toggleRequired,
  updateProp,
} from '@/features/code-editor/codeEditorSlice';
import ComponentData from '@/features/code-editor/component-data/ComponentData';
import { parseExampleSrc as parseImageExampleSrc } from '@/features/code-editor/component-data/forms/FormPropTypeImage';
import { getPropMachineName } from '@/features/code-editor/utils/utils';

import '@/styles/radix-themes';
import '@/styles/index.css';

describe('Component data / props in code editor', () => {
  let store;

  beforeEach(() => {
    cy.viewport(500, 800);
    store = makeStore({});
    cy.mount(
      <Provider store={store}>
        <Theme
          accentColor="blue"
          hasBackground={false}
          panelBackground="solid"
          appearance="light"
        >
          <ComponentData />
        </Theme>
      </Provider>,
    );
  });

  it('creates, reorders, and removes props', () => {
    // Add a new prop.
    cy.findByText('Add').click();

    cy.findByLabelText('Prop name').should('exist');
    cy.findByLabelText('Type').should('exist');
    cy.findByLabelText('Required').should('exist');

    cy.log('Checking first prop in store with default values');
    cy.wrap(store).then((store) => {
      const props = selectCodeComponentProperty('props')(store.getState());
      expect(props).to.have.length(
        1,
        'Should have exactly one prop after clicking new prop button',
      );
      expect(props[0]).to.deep.include(
        {
          name: '',
          type: 'string',
          example: '',
          derivedType: 'text',
          format: undefined,
          $ref: undefined,
        },
        'Should have the correct default prop values',
      );
    });

    cy.findByLabelText('Prop name').type('Title');
    cy.findByLabelText('Example value').type('Your title goes here');

    cy.log('Checking updated prop');
    cy.wrap(store).then((store) => {
      const props = selectCodeComponentProperty('props')(store.getState());
      expect(props).to.have.length(1, 'Should have exactly one prop');
      expect(props[0]).to.deep.include(
        {
          name: 'Title',
          example: 'Your title goes here',
          derivedType: 'text',
          format: undefined,
          $ref: undefined,
        },
        'Should have the updated name and example value',
      );
    });

    cy.log('Adding more props');

    // Add a test list prop with three values.
    cy.findByText('Add').click();
    cy.findAllByLabelText('Prop name').last().type('Variant');
    cy.findAllByLabelText('Type').last().click();
    cy.findByText('List: text').click();
    cy.findByText('Add value').click();
    cy.findAllByTestId(/canvas-prop-enum-value-[0-9a-f-]+-\d/)
      .last()
      .type('Alpha');
    cy.findByText('Add value').click();
    cy.findAllByTestId(/canvas-prop-enum-value-[0-9a-f-]+-\d/)
      .last()
      .type('Bravo');
    cy.findByText('Add value').click();
    cy.findAllByTestId(/canvas-prop-enum-value-[0-9a-f-]+-\d/)
      .last()
      .type('Charlie');
    cy.findByLabelText('Default value').click();
    cy.findByText('Bravo').click();

    // Add a boolean prop.
    cy.findByText('Add').click();
    cy.findAllByLabelText('Prop name').last().type('Featured');
    cy.findAllByLabelText('Type').last().click();
    cy.findByText('Boolean').click();
    cy.findAllByLabelText('Example value').last().assertToggleState(false);
    cy.findAllByLabelText('Example value').last().toggleToggle();
    cy.findAllByLabelText('Example value').last().assertToggleState(true);

    // Check that the props are in the store.
    cy.wrap(store).then((store) => {
      const props = selectCodeComponentProperty('props')(store.getState());
      expect(props).to.have.length(3, 'Should have exactly three props');
      expect(props[0]).to.deep.include({
        name: 'Title',
        type: 'string',
        example: 'Your title goes here',
        format: undefined,
        $ref: undefined,
        derivedType: 'text',
      });
      expect(props[1]).to.deep.include({
        name: 'Variant',
        type: 'string',
        enum: [
          { label: 'Alpha', value: 'Alpha' },
          { label: 'Bravo', value: 'Bravo' },
          { label: 'Charlie', value: 'Charlie' },
        ],
        example: 'Bravo',
        format: undefined,
        $ref: undefined,
        derivedType: 'listText',
      });
      expect(props[2]).to.deep.include({
        name: 'Featured',
        type: 'boolean',
        example: true,
        format: undefined,
        $ref: undefined,
        derivedType: 'boolean',
      });
    });

    // Reorder the props. Move the first prop to the third position.
    cy.findAllByLabelText('Move prop')
      .first()
      .realDnd('[data-testid="prop-2"]');
    // Check that the props in the store are in the new order.
    cy.wrap(store).then((store) => {
      const props = selectCodeComponentProperty('props')(store.getState());
      expect(props[0].name).to.equal('Variant');
      expect(props[1].name).to.equal('Featured');
      expect(props[2].name).to.equal('Title');
    });

    // Reorder the props again. Move the first prop to the second position.
    cy.findAllByLabelText('Move prop')
      .first()
      .realDnd('[data-testid="prop-0"]', {
        position: 'bottom',
      });
    // Check that the props in the store are in the new order.
    cy.wrap(store).then((store) => {
      const props = selectCodeComponentProperty('props')(store.getState());
      expect(props[0].name).to.equal('Featured');
      expect(props[1].name).to.equal('Variant');
      expect(props[2].name).to.equal('Title');
    });

    // Remove the first prop.
    cy.findAllByLabelText('Remove prop').first().click();
    // Check that the props in the store are in the new order.
    cy.wrap(store).then((store) => {
      const props = selectCodeComponentProperty('props')(store.getState());
      expect(props).to.have.length(2, 'Should have exactly two props');
      expect(props[0].name).to.equal('Variant');
      expect(props[1].name).to.equal('Title');
    });

    // Remove the last prop.
    cy.findAllByLabelText('Remove prop').last().click();
    // Check that the props in the store are in the new order.
    cy.wrap(store).then((store) => {
      const props = selectCodeComponentProperty('props')(store.getState());
      expect(props).to.have.length(1, 'Should have exactly one prop');
      expect(props[0].name).to.equal('Variant');
    });

    // Remove the one remaining prop.
    cy.findByLabelText('Remove prop').click();
    cy.wrap(store).then((store) => {
      const props = selectCodeComponentProperty('props')(store.getState());
      expect(props).to.have.length(0, 'Should have no props');
    });
  });

  it('adds and removes prop from required props', () => {
    // Add a new prop and toggle it as required.
    cy.findByText('Add').click();
    cy.findByLabelText('Required').toggleToggle();

    // Check that the prop is now required.
    cy.log('Checking updated prop');
    cy.wrap(store).then((store) => {
      const propName = selectCodeComponentProperty('props')(store.getState())[0]
        .name;
      const required = selectCodeComponentProperty('required')(
        store.getState(),
      );
      expect(required[0]).to.equal(
        getPropMachineName(propName),
        'Should have the prop as required',
      );
    });

    // Toggle the prop as not required.
    cy.findByLabelText('Required').toggleToggle();

    // Check that the prop is no longer required.
    cy.wrap(store).then((store) => {
      const required = selectCodeComponentProperty('required')(
        store.getState(),
      );
      expect(required).to.have.length(0, 'Should have no required props');
    });

    // Toggle the prop as required again, then delete it.
    cy.findByLabelText('Required').toggleToggle();
    cy.findByLabelText('Remove prop').click();

    // Check that the prop is no longer required.
    cy.wrap(store).then((store) => {
      const required = selectCodeComponentProperty('required')(
        store.getState(),
      );
      expect(required).to.have.length(0, 'Should have no required props');
    });
  });

  it('displays an existing prop', () => {
    // Add a new prop directly to the store, update it, and toggle it as required.
    cy.wrap(store).then((store) => {
      store.dispatch(addProp());
      const newProp = selectCodeComponentProperty('props')(store.getState())[0];
      cy.log(
        `Added new prop directly to the store: ${JSON.stringify(newProp)}`,
      );
      store.dispatch(
        updateProp({
          id: newProp.id,
          updates: { name: 'Title', example: 'Your title goes here' },
        }),
      );
      const updatedProp = selectCodeComponentProperty('props')(
        store.getState(),
      )[0];
      cy.log(
        `Updated prop directly in the store: ${JSON.stringify(updatedProp)}`,
      );
      store.dispatch(toggleRequired({ propId: updatedProp.id }));
      cy.log(
        `Toggled required prop in the store: ${JSON.stringify(updatedProp)}`,
      );
    });

    // Check that the prop is displayed in the component.
    cy.findByLabelText('Prop name').should('have.value', 'Title');
    cy.findByLabelText('Type').should('have.text', 'Text');
    cy.findByLabelText('Required').assertToggleState(true);
    cy.findByLabelText('Example value').should(
      'have.value',
      'Your title goes here',
    );
  });

  it('allows the label of an existing text list prop to be updated independently of its value', () => {
    // Set up: Add a listText prop with one enum value directly to the store.
    cy.wrap(store).then((store) => {
      store.dispatch(addProp());
      const newProp = selectCodeComponentProperty('props')(store.getState())[0];
      cy.log(
        `Added new prop directly to the store: ${JSON.stringify(newProp)}`,
      );
      store.dispatch(
        updateProp({
          id: newProp.id,
          updates: {
            name: 'Title',
            example: 'Alpha',
            derivedType: 'listText',
            enum: [
              {
                label: 'Alpha',
                value: 'Alpha',
              },
            ],
          },
        }),
      );
      const updatedProp = selectCodeComponentProperty('props')(
        store.getState(),
      )[0];
      cy.log(
        `Updated prop directly in the store: ${JSON.stringify(updatedProp)}`,
      );
    });

    // Validate setup state
    cy.findByLabelText('Prop name').should('have.value', 'Title');
    cy.findByLabelText('Type').should('have.text', 'List: text');
    cy.findByLabelText('Default value').should('have.text', 'Alpha');

    cy.log('Existing labels should not auto update when the value is changed');
    cy.findByLabelText('Value').type('Bravo');
    cy.findByLabelText('Label').should('have.value', 'Alpha');

    cy.log('New values should auto update the label when they are entered');
    cy.findByText('Add value').click();
    cy.findAllByLabelText('Value').eq(1).type('Xray');
    cy.findAllByLabelText('Label').eq(1).should('have.value', 'Xray');

    cy.log('New values should auto update the label when they are changed');
    cy.findAllByLabelText('Value').eq(1).type('Zulu');
    cy.findAllByLabelText('Label').eq(1).should('have.value', 'XrayZulu');

    cy.log(
      'Once a label has been changed, it should not be auto updated anymore',
    );
    cy.findAllByLabelText('Label').eq(1).clear();
    cy.findAllByLabelText('Label').eq(1).type('Custom label');
    cy.findAllByLabelText('Value').eq(1).type('Charlie');
    cy.findAllByLabelText('Label').eq(1).should('have.value', 'Custom label');

    cy.wrap(store).then((store) => {
      expect(
        selectCodeComponentProperty('props')(store.getState())[0],
      ).to.deep.include(
        {
          enum: [
            {
              label: 'Alpha',
              value: 'AlphaBravo',
            },
            {
              label: 'Custom label',
              value: 'XrayZuluCharlie',
            },
          ],
        },
        'Should have the appropriate enum and example values',
      );
    });
  });

  it('removes enum values when the type is changed', () => {
    cy.findByText('Add').click();

    // Add an enum value for a List: text prop.
    cy.findByLabelText('Type').click();
    cy.findByText('List: text').click();
    cy.findByText('Add value').click();
    cy.findByTestId(/canvas-prop-enum-value-[0-9a-f-]+-0/).type('Alpha');
    cy.wrap(store).then((store) => {
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].enum,
      ).to.deep.equal([{ label: 'Alpha', value: 'Alpha' }]);
    });

    // Change the type to List: integer. The enum value should be removed.
    cy.findByLabelText('Type').click();
    cy.findByText('List: integer').click();
    cy.findByTestId(/canvas-prop-enum-value-[0-9a-f-]+-0/).should('not.exist');
    cy.wrap(store).then((store) => {
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].enum,
      ).to.deep.equal([]);
    });
    // Add an enum value.
    cy.findByText('Add value').click();
    cy.findByTestId(/canvas-prop-enum-value-[0-9a-f-]+-0/).type('922');
    cy.wrap(store).then((store) => {
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].enum,
      ).to.deep.equal([{ label: '922', value: '922' }]);
    });

    // Change the type to List: text. The enum value should be removed.
    cy.findByLabelText('Type').click();
    cy.findByText('List: text').click();
    cy.findByTestId(/canvas-prop-enum-value-[0-9a-f-]+-0/).should('not.exist');
    cy.wrap(store).then((store) => {
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].enum,
      ).to.deep.equal([]);
    });
  });

  it('removes examples when the type is changed', () => {
    cy.findByText('Add').click();

    // Add an example value for a text prop.
    cy.findByText('Example value').type('Alpha');
    cy.wrap(store).then((store) => {
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].example,
      ).to.equal('Alpha');
    });

    // Change the type to Integer. The example value should be removed.
    cy.findByLabelText('Type').click();
    cy.findByText('Integer').click();
    cy.findByLabelText('Example value').should('have.value', '');
    cy.wrap(store).then((store) => {
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].example,
      ).to.equal('');
    });
    // Add an example value.
    cy.findByLabelText('Example value').type('922');
    cy.wrap(store).then((store) => {
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].example,
      ).to.equal('922');
    });

    // Change the type to Number. The example value should be removed.
    cy.findByLabelText('Type').click();
    cy.findByText('Number').click();
    cy.findByLabelText('Example value').should('have.value', '');
    cy.wrap(store).then((store) => {
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].example,
      ).to.equal('');
    });
    // Add an example value.
    cy.findByLabelText('Example value').type('9.22');
    cy.wrap(store).then((store) => {
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].example,
      ).to.equal('9.22');
    });

    // Change the type to Text. The example value should be removed.
    cy.findByLabelText('Type').click();
    cy.findByText('Text').click();
    cy.findByLabelText('Example value').should('have.value', '');
    cy.wrap(store).then((store) => {
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].example,
      ).to.equal('');
    });

    // Change the type to Formatted text. The example value should be removed.
    cy.findByLabelText('Type').click();
    cy.findByText('Formatted text').click();
    cy.findByLabelText('Example value').should('have.value', '');
    cy.wrap(store).then((store) => {
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].example,
      ).to.equal('');
    });

    // Change type to Image. The example value should be removed.
    cy.findByLabelText('Type').click();
    cy.findByText('Image').click();
    cy.findByLabelText('Example aspect ratio').should(
      'have.text',
      '4:3 (Standard)',
    );
    cy.wrap(store).then((store) => {
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].example,
      ).to.deep.equal({
        src: 'https://placehold.co/800x600@2x.png?alternateWidths=https%3A%2F%2Fplacehold.co%2F%7Bwidth%7Dx%7Bheight%7D%402x.png',
        width: 800,
        height: 600,
        alt: 'Example image placeholder',
      });
    });

    // Change the type to Link. The example value should be removed.
    cy.findByLabelText('Type').click();
    cy.findByText('Link').click();
    cy.findByLabelText('Example value').should('have.value', '');
    cy.wrap(store).then((store) => {
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].example,
      ).to.equal('');
    });
  });

  it('validates example value of a link prop', () => {
    cy.findByText('Add').click();

    cy.findByLabelText('Prop name').type('Link');
    cy.findByLabelText('Type').click();
    cy.findByText('Link').click();
    cy.findByLabelText('Example value').type('gerbeaud');
    cy.findByLabelText('Example value').blur();
    cy.findByLabelText('Example value').should(
      'not.have.attr',
      'data-invalid-prop-value',
    );

    cy.findByLabelText('Example value').type(' ^ 0330');
    cy.findByLabelText('Example value').blur();
    cy.findByLabelText('Example value').should(
      'have.attr',
      'data-invalid-prop-value',
    );

    // Typing into the field should reset the invalid state.
    cy.findByLabelText('Example value').type(' ^');
    cy.findByLabelText('Example value').should(
      'not.have.attr',
      'data-invalid-prop-value',
    );

    cy.findByLabelText('Example value').blur();
    cy.findByLabelText('Example value').should(
      'have.attr',
      'data-invalid-prop-value',
    );

    // Switch to the full URL type.
    cy.findByLabelText('Link type').click();
    cy.findByText('Full URL').click();
    // The invalid state should be cleared by switching the link type.
    cy.findByLabelText('Example value').should(
      'not.have.attr',
      'data-invalid-prop-value',
    );

    cy.findByLabelText('Example value').clear();
    cy.findByLabelText('Example value').type('https://hazelnut.com');
    cy.findByLabelText('Example value').blur();
    cy.findByLabelText('Example value').should(
      'not.have.attr',
      'data-invalid-prop-value',
    );

    cy.findByLabelText('Example value').clear();
    cy.findByLabelText('Example value').type('0203');
    cy.findByLabelText('Example value').blur();
    cy.findByLabelText('Example value').should(
      'have.attr',
      'data-invalid-prop-value',
    );

    // An empty value should be valid.
    cy.findByLabelText('Example value').clear();
    cy.findByLabelText('Example value').blur();
    cy.findByLabelText('Example value').should(
      'not.have.attr',
      'data-invalid-prop-value',
    );
  });

  it('parses the image prop example URL', () => {
    expect(
      parseImageExampleSrc('https://placehold.co/801x601.png'),
    ).to.deep.equal({
      aspectRatio: '4:3', // Fallback to default aspect ratio, size is not known.
      pixelDensity: '1x', // Matched from URL.
    });

    expect(
      parseImageExampleSrc('https://placehold.co/801x601@2x.png'),
    ).to.deep.equal({
      aspectRatio: '4:3', // Fallback to default aspect ratio, size is not known.
      pixelDensity: '2x', // Exact match.
    });

    expect(
      parseImageExampleSrc('https://placehold.co/900x600@4x.png'),
    ).to.deep.equal({
      aspectRatio: '3:2',
      pixelDensity: '2x', // Fallback to default pixel density, density is not known.
    });

    expect(
      parseImageExampleSrc('https://placehold.co/900x600@2x.png'),
    ).to.deep.equal({
      aspectRatio: '3:2',
      pixelDensity: '2x',
    });

    expect(
      parseImageExampleSrc('https://placehold.co/1400x600@3x.png'),
    ).to.deep.equal({
      aspectRatio: '21:9',
      pixelDensity: '3x',
    });
  });
});
