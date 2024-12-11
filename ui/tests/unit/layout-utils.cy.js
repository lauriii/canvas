// cspell:ignore idontexist
import {
  isChildNode,
  replaceUUIDsAndUpdateModel,
} from '@/features/layout/layoutUtils';

let layout;
before('Load fixture', function () {
  cy.fixture('layout-default.json').then((data) => {
    layout = data;
  });
});

describe('isChildNode', () => {
  it('should correctly identify child nodes', () => {
    expect(isChildNode(layout.layout, 'dynamic-image-udf7d')).to.be.false;
    expect(isChildNode(layout.layout, 'dynamic-static-card2df')).to.be.false;
    expect(isChildNode(layout.layout, 'static-static-card1ab')).to.be.true;
    expect(isChildNode(layout.layout, 'idontexist')).to.be.null;
  });
});

describe('replaceUUIDsAndUpdateModel', () => {
  it('should replace UUIDs and update the model correctly', () => {
    cy.then(() => {
      const inputLayout = layout.layout;

      const inputModel = layout.model;

      const inputNode = {
        nodeType: 'component',
        uuid: '3cf2625f-a0a8-4c97-85c0-06df16239c21',
        type: 'sdc.foo-bar',
        slots: [
          {
            nodeType: 'slot',
            name: 'mySlot',
            id: '3cf2625f-a0a8-4c97-85c0-06df16239c21/mySlot',
            components: inputLayout.components,
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

      expect(Object.keys(updatedModel).length).to.equal(9);

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
      cy.log(updatedNode);
      expect(updatedNode.slots[0].components[4].slots).to.have.length(2);
      expect(
        updatedNode.slots[0].components[4].slots[1].components,
      ).to.have.length(2);

      // Check if node types and component types are preserved
      expect(updatedNode.type).to.equal('sdc.foo-bar');
      expect(updatedNode.slots[0].components[0].type).to.equal(
        'experience_builder:image',
      );
      expect(updatedNode.slots[0].components[1].type).to.equal(
        'sdc_test:my-cta',
      );

      expect(updatedNode.slots[0].components[2].type).to.equal(
        'sdc_test:my-cta',
      );
      expect(updatedNode.slots[0].components[3].type).to.equal(
        'experience_builder:image',
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
  });
});
