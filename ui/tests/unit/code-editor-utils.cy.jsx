import {
  deserializeProps,
  serializeProps,
  serializeSlots,
  deserializeSlots,
} from '@/features/code-editor/utils';
import fixtureProps from '../fixtures/code-component-props.json';
import fixtureSlots from '../fixtures/code-component-slots.json';

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
    const { id, ...rest } = prop;
    return rest;
  });
  const expectedWithoutIds = expected.map((prop) => {
    const { id, ...rest } = prop;
    return rest;
  });

  console.log('actualWithoutIds', actualWithoutIds);
  console.log('expectedWithoutIds', expectedWithoutIds);

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

    it('of type number list', () => {
      expect(
        serializeProps([
          deserializedPropsFixture[12],
          deserializedPropsFixture[13],
        ]),
      ).to.matchSerializedProps([
        'numberListWithNoExampleValue',
        'numberListWithExampleValue',
      ]);
    });
    it('of type text area', () => {
      expect(
        serializeProps([
          deserializedPropsFixture[14],
          deserializedPropsFixture[15],
        ]),
      ).to.matchSerializedProps([
        'textAreaWithNoExampleValue',
        'textAreaWithExampleValue',
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

    it('of type number list', () => {
      expect(
        deserializeProps([
          serializedPropsFixture.numberListWithNoExampleValue,
          serializedPropsFixture.numberListWithExampleValue,
        ]),
      ).to.matchDeserializedProps([12, 13]);
    });

    it('of type text area', () => {
      expect(
        deserializeProps([
          serializedPropsFixture.textAreaWithNoExampleValue,
          serializedPropsFixture.textAreaWithExampleValue,
        ]),
      ).to.matchDeserializedProps([14, 15]);
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
