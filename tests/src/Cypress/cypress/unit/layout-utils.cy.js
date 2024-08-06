// cspell:ignore idontexist
import { isChildNode }  from '../../../../../ui/src/features/layout/layoutUtils';

let layout;
before('Load fixture', function () {
  cy.fixture('layout-default.json').then((data)=>{
    layout = data;
  })
})

describe('isChildNode', () => {
  it('should correctly identify child nodes', () => {
    expect(isChildNode(layout.layout, 'dynamic-image-udf7d')).to.be.false
    expect(isChildNode(layout.layout, 'dynamic-static-card2df')).to.be.false;
    expect(isChildNode(layout.layout, 'static-static-card1ab')).to.be.true;
    expect(isChildNode(layout.layout, 'idontexist')).to.be.null;
  });
});
