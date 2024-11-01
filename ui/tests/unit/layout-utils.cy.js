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
      const inputNode = layout.layout;
      const inputModel = layout.model;

      const { updatedNode, updatedModel } = replaceUUIDsAndUpdateModel(
        inputNode,
        inputModel,
      );

      expect(updatedNode.uuid).not.to.equal(inputNode.uuid);

      function checkUUIDs(oldNode, newNode) {
        expect(newNode.uuid).not.to.equal(oldNode.uuid);
        if (oldNode.children && newNode.children) {
          expect(newNode.children.length).to.equal(oldNode.children.length);
          oldNode.children.forEach((oldChild, index) => {
            checkUUIDs(oldChild, newNode.children[index]);
          });
        }
      }

      checkUUIDs(inputNode, updatedNode);

      expect(Object.keys(updatedModel).length).to.equal(
        Object.keys(inputModel).length,
      );

      Object.keys(updatedModel).forEach((newUUID) => {
        const oldUUID = Object.keys(inputModel).find(
          (oldUUID) =>
            JSON.stringify(updatedModel[newUUID]) ===
            JSON.stringify(inputModel[oldUUID]),
        );
        expect(oldUUID).to.exist;
        expect(newUUID).not.to.equal(oldUUID);
      });

      expect(updatedNode.children).to.have.length(4);
      expect(updatedNode.children[0].children).to.have.length(1);
      expect(updatedNode.children[0].children[0].children).to.have.length(1);
      expect(updatedNode.children[2].children).to.have.length(1);
      expect(updatedNode.children[2].children[0].children).to.have.length(0);

      // Check if node types and component types are preserved
      expect(updatedNode.nodeType).to.equal('root');
      expect(updatedNode.children[0].type).to.equal('experience_builder:image');
      expect(updatedNode.children[1].type).to.equal('sdc_test:my-cta');

      expect(updatedNode.children[2].type).to.equal('sdc_test:my-cta');
      expect(updatedNode.children[3].type).to.equal('experience_builder:image');

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
        } else if (componentData.text) {
          expect(componentData).to.have.all.keys('text', 'href', 'name');
        }
      });
    });
  });
});
