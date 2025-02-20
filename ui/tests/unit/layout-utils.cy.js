// cspell:ignore idontexist
import {
  isChildNode,
  replaceUUIDsAndUpdateModel,
  findParentRegion,
} from '@/features/layout/layoutUtils';
import layoutFixture from '../fixtures/layout-default.json';
import regionsLayoutFixture from '../fixtures/layout-regions.json';

let layout, regionsLayout;
beforeEach(() => {
  layout = layoutFixture;
  regionsLayout = regionsLayoutFixture;
});

describe('isChildNode', () => {
  it('should correctly identify child nodes', () => {
    expect(isChildNode(layout.layout, 'a7470350-deb2-4d9f-982c-464d356403d4'))
      .to.be.false;
    expect(isChildNode(layout.layout, 'static-static-card2df')).to.be.false;
    expect(isChildNode(layout.layout, 'static-static-card1ab')).to.be.true;
    expect(isChildNode(layout.layout, 'idontexist')).to.be.null;
  });
});

describe('replaceUUIDsAndUpdateModel', () => {
  it('should replace UUIDs and update the model correctly', () => {
    const inputLayout = layout.layout;

    const inputModel = layout.model;
    expect(Object.keys(inputModel).length).to.equal(10);

    const inputNode = {
      nodeType: 'component',
      uuid: '3cf2625f-a0a8-4c97-85c0-06df16239c21',
      type: 'sdc.foo-bar',
      slots: [
        {
          nodeType: 'slot',
          name: 'mySlot',
          id: '3cf2625f-a0a8-4c97-85c0-06df16239c21/mySlot',
          components: inputLayout[0].components,
        },
      ],
    };
    expect(inputNode.uuid).to.equal('3cf2625f-a0a8-4c97-85c0-06df16239c21');

    const { updatedNode, updatedModel } = replaceUUIDsAndUpdateModel(
      inputNode,
      inputModel,
    );

    expect(updatedNode.uuid).not.to.equal(inputNode.uuid);

    function checkUUIDs(oldNode, newNode) {
      if (oldNode.nodeType === 'slot') {
        expect(newNode.id).not.to.equal(oldNode.id);
        expect(newNode.components.length).to.equal(oldNode.components.length);
        oldNode.components.forEach((oldSlot, index) => {
          checkUUIDs(oldSlot, newNode.components[index]);
        });
      } else {
        expect(newNode.uuid).not.to.equal(oldNode.uuid);
        expect(newNode.slots.length).to.equal(oldNode.slots.length);
        oldNode.slots.forEach((oldSlot, index) => {
          checkUUIDs(oldSlot, newNode.slots[index]);
        });
      }
    }

    checkUUIDs(inputNode, updatedNode);

    expect(Object.keys(updatedModel).length).to.equal(10);

    Object.keys(updatedModel).forEach((newUUID) => {
      const oldUUID = Object.keys(inputModel).find(
        (oldUUID) =>
          JSON.stringify(updatedModel[newUUID]) ===
          JSON.stringify(inputModel[oldUUID]),
      );
      expect(oldUUID).to.exist;
      expect(newUUID).not.to.equal(oldUUID);
    });

    expect(updatedNode.slots).to.have.length(1);
    expect(updatedNode.slots[0].components).to.have.length(5);
    expect(updatedNode.slots[0].components[0].slots).to.have.length(1);
    expect(updatedNode.slots[0].components[4].slots).to.have.length(2);
    expect(
      updatedNode.slots[0].components[4].slots[1].components,
    ).to.have.length(2);

    // Check if node types and component types are preserved
    expect(updatedNode.type).to.equal('sdc.foo-bar');
    expect(updatedNode.slots[0].components[0].type).to.equal(
      'sdc.experience_builder.two_column',
    );
    expect(updatedNode.slots[0].components[1].type).to.equal(
      'sdc.sdc_test.my-cta',
    );

    expect(updatedNode.slots[0].components[2].type).to.equal(
      'sdc.sdc_test.my-cta',
    );
    expect(updatedNode.slots[0].components[3].type).to.equal(
      'sdc.experience_builder.image',
    );

    // Check if model data is preserved
    Object.keys(updatedModel).forEach((newUUID) => {
      const componentData = updatedModel[newUUID];
      if (componentData.image) {
        expect(componentData.image).to.have.all.keys(
          'src',
          'alt',
          'width',
          'height',
        );
      } else if (componentData.element) {
        expect(componentData).to.have.all.keys(
          'name',
          'text',
          'style',
          'element',
        );
      } else if (componentData.text) {
        expect(componentData).to.have.all.keys('text', 'href', 'name');
      }
    });
  });

  describe('findParentRegion', () => {
    it('should find the correct parent region for a given UUID', () => {
      // Test for a component directly in a region
      const headerRegion = findParentRegion(
        regionsLayout.layout,
        '13ea974f-cf74-406a-9171-dad5f96e805f',
      );
      expect(headerRegion.id).to.equal('header');

      // Test for a component in nested slots
      const contentRegion = findParentRegion(
        regionsLayout.layout,
        '8afbb203-ae72-4155-8319-8c7b1915787a',
      );
      expect(contentRegion.id).to.equal('content');

      // Test for a non-existent UUID
      const nonExistentRegion = findParentRegion(
        regionsLayout.layout,
        'non-existent-uuid',
      );
      expect(nonExistentRegion).to.be.undefined;
    });
  });
});
