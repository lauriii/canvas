/* cspell:ignore Mycomponentname HelloWorldexample */
import {
  deserializeProps,
  serializeProps,
  serializeSlots,
  deserializeSlots,
  getPropValuesForPreview,
  formatToValidImportName,
} from '@/features/code-editor/utils';
import fixtureProps from '../fixtures/code-component-props.json';
import fixtureSlots from '../fixtures/code-component-slots.json';
import { getImportsFromAst } from '@/features/code-editor/utils';
import { parse } from '@babel/parser';

const {
  deserialized: deserializedPropsFixture,
  serialized: serializedPropsFixture,
} = fixtureProps;

const {
  deserialized: deserializedSlotsFixture,
  serialized: serializedSlotsFixture,
} = fixtureSlots;

/**
 * Asserts that serialized props match the expected fixtures.
 *
 * @param {string[]} fixtureKeys - The keys from the fixture to match.
 */
chai.Assertion.addMethod('matchSerializedProps', function (fixtureKeys) {
  const expected = {};
  fixtureKeys.forEach((prop) => {
    expected[prop] = serializedPropsFixture[prop];
  });
  this.assert(
    chai.util.eql(this._obj, expected),
    'expected #{this} to match serialized props #{exp}',
    'expected #{this} to not match serialized props #{exp}',
    expected,
  );
});

/**
 * Asserts that deserialized props match the expected fixtures.
 *
 * @param {number[]} props - The indices from the fixture to match.
 */
chai.Assertion.addMethod('matchDeserializedProps', function (props) {
  // Deserialized props should be an array.
  new chai.Assertion(this._obj).to.be.an('array');

  // Get the expected values from the fixture using the provided indices.
  const expected = [];
  props.forEach((index) => {
    expected.push(deserializedPropsFixture[index]);
  });

  // Check that each deserialized prop has an `id` key with a string in it.
  this._obj.forEach((prop) => {
    new chai.Assertion(prop).to.have.property('id').that.is.a('string');
  });

  // Compare the rest of the props by removing IDs first
  const actualWithoutIds = this._obj.map((prop) => {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    const { id, ...rest } = prop;
    return rest;
  });
  const expectedWithoutIds = expected.map((prop) => {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    const { id, ...rest } = prop;
    return rest;
  });

  this.assert(
    chai.util.eql(actualWithoutIds, expectedWithoutIds),
    `expected ${JSON.stringify(actualWithoutIds, null, 2)} to match deserialized props ${JSON.stringify(expectedWithoutIds, null, 2)}`,
    `expected ${JSON.stringify(actualWithoutIds, null, 2)} to not match deserialized props ${JSON.stringify(expectedWithoutIds, null, 2)}`,
    expectedWithoutIds,
  );
});

describe('Code editor utilities', () => {
  describe('serialize props', () => {
    it('of type text', () => {
      expect(
        serializeProps([
          deserializedPropsFixture[0],
          deserializedPropsFixture[1],
        ]),
      ).to.matchSerializedProps([
        'stringWithNoExampleValue',
        'stringWithExampleValue',
      ]);
    });

    it('of type integer', () => {
      expect(
        serializeProps([
          deserializedPropsFixture[2],
          deserializedPropsFixture[3],
        ]),
      ).to.matchSerializedProps([
        'integerWithNoExampleValue',
        'integerWithExampleValue',
      ]);
    });

    it('of type number', () => {
      expect(
        serializeProps([
          deserializedPropsFixture[4],
          deserializedPropsFixture[5],
        ]),
      ).to.matchSerializedProps([
        'numberWithNoExampleValue',
        'numberWithExampleValue',
      ]);
    });

    it('of type boolean', () => {
      expect(
        serializeProps([
          deserializedPropsFixture[6],
          deserializedPropsFixture[7],
        ]),
      ).to.matchSerializedProps([
        'booleanWithExampleValueTrue',
        'booleanWithExampleValueFalse',
      ]);
    });

    it('of type text list', () => {
      expect(
        serializeProps([
          deserializedPropsFixture[8],
          deserializedPropsFixture[9],
        ]),
      ).to.matchSerializedProps([
        'textListWithNoExampleValue',
        'textListWithExampleValue',
      ]);
    });

    it('of type integer list', () => {
      expect(
        serializeProps([
          deserializedPropsFixture[10],
          deserializedPropsFixture[11],
        ]),
      ).to.matchSerializedProps([
        'integerListWithNoExampleValue',
        'integerListWithExampleValue',
      ]);
    });

    it('of type formatted text', () => {
      expect(
        serializeProps([
          deserializedPropsFixture[12],
          deserializedPropsFixture[13],
        ]),
      ).to.matchSerializedProps([
        'formattedTextWithNoExampleValue',
        'formattedTextWithExampleValue',
      ]);
    });
  });

  describe('deserialize props', () => {
    it('of type text', () => {
      expect(
        deserializeProps([
          serializedPropsFixture.stringWithNoExampleValue,
          serializedPropsFixture.stringWithExampleValue,
        ]),
      ).to.matchDeserializedProps([0, 1]);
    });

    it('of type integer', () => {
      expect(
        deserializeProps([
          serializedPropsFixture.integerWithNoExampleValue,
          serializedPropsFixture.integerWithExampleValue,
        ]),
      ).to.matchDeserializedProps([2, 3]);
    });

    it('of type number', () => {
      expect(
        deserializeProps([
          serializedPropsFixture.numberWithNoExampleValue,
          serializedPropsFixture.numberWithExampleValue,
        ]),
      ).to.matchDeserializedProps([4, 5]);
    });

    it('of type boolean', () => {
      expect(
        deserializeProps([
          serializedPropsFixture.booleanWithExampleValueTrue,
          serializedPropsFixture.booleanWithExampleValueFalse,
        ]),
      ).to.matchDeserializedProps([6, 7]);
    });

    it('of type text list', () => {
      expect(
        deserializeProps([
          serializedPropsFixture.textListWithNoExampleValue,
          serializedPropsFixture.textListWithExampleValue,
        ]),
      ).to.matchDeserializedProps([8, 9]);
    });

    it('of type integer list', () => {
      expect(
        deserializeProps([
          serializedPropsFixture.integerListWithNoExampleValue,
          serializedPropsFixture.integerListWithExampleValue,
        ]),
      ).to.matchDeserializedProps([10, 11]);
    });

    it('of type formatted text', () => {
      expect(
        deserializeProps([
          serializedPropsFixture.formattedTextWithNoExampleValue,
          serializedPropsFixture.formattedTextWithExampleValue,
        ]),
      ).to.matchDeserializedProps([12, 13]);
    });

    it('of type image', () => {
      expect(
        deserializeProps([
          serializedPropsFixture.imageWithNoExampleValue,
          serializedPropsFixture.imageWithExampleValue,
        ]),
      ).to.matchDeserializedProps([14, 15]);
    });

    it('of type link', () => {
      expect(
        deserializeProps([
          serializedPropsFixture.relativePathLinkWithNoExampleValue,
          serializedPropsFixture.relativePathLinkWithExampleValue,
          serializedPropsFixture.fullUrlLinkWithNoExampleValue,
          serializedPropsFixture.fullUrlLinkWithExampleValue,
        ]),
      ).to.matchDeserializedProps([16, 17, 18, 19]);
    });

    // Backwards compatibility
    // @see https://www.drupal.org/i/3520843
    it('of type deprecated text area - backwards compatibility', () => {
      expect(
        deserializeProps([
          serializedPropsFixture.deprecatedTextAreaWithNoExampleValue,
          serializedPropsFixture.deprecatedTextAreaWithExampleValue,
        ]),
      ).to.matchDeserializedProps([20, 21]);
    });
  });

  it('serialize slots', () => {
    expect(serializeSlots(deserializedSlotsFixture)).to.deep.equal(
      serializedSlotsFixture,
    );
  });

  it('deserialize slots', () => {
    const result = deserializeSlots(serializedSlotsFixture);
    expect(result).to.be.an('array');
    result.forEach((slot, index) => {
      expect(slot).to.have.property('id').that.is.a('string');
      // Compare the slot without the `id` key to the expected fixture.
      const withoutId = { ...slot, id: undefined };
      expect(withoutId).to.deep.equal({
        ...deserializedSlotsFixture[index],
        id: undefined,
      });
    });
  });
});

describe('Code editor preview utilities', () => {
  it('extracts values from props for preview', () => {
    expect(getPropValuesForPreview(deserializedPropsFixture)).to.deep.equal({
      stringWithNoExampleValue: '',
      stringWithExampleValue: 'Experience Builder',
      integerWithNoExampleValue: 0,
      integerWithExampleValue: 922,
      numberWithNoExampleValue: 0,
      numberWithExampleValue: 9.22,
      booleanWithExampleValueTrue: true,
      booleanWithExampleValueFalse: false,
      textListWithNoExampleValue: '',
      textListWithExampleValue: 'In Progress',
      integerListWithNoExampleValue: 0,
      integerListWithExampleValue: 2,
      formattedTextWithNoExampleValue: '',
      formattedTextWithExampleValue:
        "Lorem Ipsum is simply dummy text of the printing and typesetting industry. Lorem Ipsum has been the industry's standard dummy text ever since the 1500s, when an unknown printer took a galley of type and scrambled it to make a type specimen book. It has survived not only five centuries, but also the leap into electronic typesetting, remaining essentially unchanged.",
      imageWithNoExampleValue: '',
      imageWithExampleValue: {
        src: 'https://placehold.co/1200x900@2x.png',
        width: 1200,
        height: 900,
        alt: 'Example image placeholder',
      },
      relativePathLinkWithNoExampleValue: '',
      relativePathLinkWithExampleValue: 'gerbeaud',
      fullUrlLinkWithNoExampleValue: '',
      fullUrlLinkWithExampleValue: 'https://hazelnut.com',
      // Backwards compatibility
      // @see https://www.drupal.org/i/3520843
      deprecatedTextAreaWithNoExampleValue: '',
      deprecatedTextAreaWithExampleValue:
        "Lorem Ipsum is simply dummy text of the printing and typesetting industry. Lorem Ipsum has been the industry's standard dummy text ever since the 1500s, when an unknown printer took a galley of type and scrambled it to make a type specimen book. It has survived not only five centuries, but also the leap into electronic typesetting, remaining essentially unchanged.",
    });
  });

  it('handles empty props when extracting values for preview', () => {
    const result = getPropValuesForPreview([]);
    expect(result).to.deep.equal({});
  });
});

describe('getImportsFromAst', () => {
  it('should return all imports when no scope is provided', () => {
    const code = `
      import React from 'react';
      import { useState } from 'react';
      import MyComponent from '@/components/MyComponent';
      import { Theme } from "@radix-ui/themes";
    `;
    const ast = parse(code, { sourceType: 'module' });
    const result = getImportsFromAst(ast);
    expect(result).to.deep.equal([
      'react',
      'react',
      '@/components/MyComponent',
      '@radix-ui/themes',
    ]);
  });

  it('should return imports filtered by scope', () => {
    const code = `
      import React from 'react';
      import { useState } from 'react';
      import MyComponent from '@/components/MyComponent';
      import MyComponent2 from '@/components/MyComponent2';
    `;
    const ast = parse(code, { sourceType: 'module' });
    const result = getImportsFromAst(ast, '@/components/');
    expect(result).to.deep.equal(['MyComponent', 'MyComponent2']);
  });

  it('should return an empty array if no imports match the scope', () => {
    const code = `
      import React from 'react';
      import { useState } from 'react';
    `;
    const ast = parse(code, { sourceType: 'module' });
    const result = getImportsFromAst(ast, '@/components/');
    expect(result).to.deep.equal([]);
  });

  it('should handle an empty AST gracefully', () => {
    const ast = parse('', { sourceType: 'module' });
    const result = getImportsFromAst(ast);

    expect(result).to.deep.equal([]);
  });
});

describe('formatToValidImportName', () => {
  it('should handle basic strings', () => {
    expect(formatToValidImportName('hello world')).to.equal('HelloWorld');
    expect(formatToValidImportName('my component')).to.equal('MyComponent');
    expect(formatToValidImportName('mixedCase component')).to.equal(
      'MixedCaseComponent',
    );
    expect(formatToValidImportName('CamelCase example')).to.equal(
      'CamelCaseExample',
    );
  });

  it('should handle special characters', () => {
    expect(formatToValidImportName('hello-world!')).to.equal('Helloworld');
    expect(formatToValidImportName('my@component*name')).to.equal(
      'Mycomponentname',
    );
    expect(
      formatToValidImportName('special_chars & symbols    & space'),
    ).to.equal('SpecialCharsSymbolsSpace');
    expect(formatToValidImportName('hello_world-example')).to.equal(
      'HelloWorldexample',
    );
    expect(formatToValidImportName('\ttabbed\nwords')).to.equal('TabbedWords');
  });

  it('should handle numbers', () => {
    expect(formatToValidImportName('foo123')).to.equal('Foo123');
    expect(formatToValidImportName('hello 42 world')).to.equal('Hello42World');
    // Starts with a number.
    expect(formatToValidImportName('123test')).to.equal('Component123test');
    expect(formatToValidImportName('42 foo')).to.equal('Component42Foo');
  });

  it('should handle empty or invalid inputs', () => {
    expect(formatToValidImportName('')).to.equal('');
    expect(formatToValidImportName(null)).to.equal('');
    expect(formatToValidImportName('!@#$%^&*()')).to.equal('');
  });
});
