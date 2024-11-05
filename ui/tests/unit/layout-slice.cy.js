import {
  selectHistory,
  deleteNode,
  moveNode,
  layoutModelSlice,
  setLayoutModel,
  initialState,
  duplicateNode,
} from '@/features/layout/layoutModelSlice';
import { makeStore } from '@/app/store';
import { ActionCreators } from 'redux-undo';

let layout;
before('Load fixture', function () {
  cy.fixture('layout-default.json').then((data) => {
    layout = data;
  });
});

describe('Set layout model', () => {
  it('Should set model and layout', () => {
    const state = layoutModelSlice.reducer(
      initialState,
      setLayoutModel(layout),
    );
    expect(state.layout).to.eq(layout.layout);
    expect(state.model).to.eq(layout.model);
  });
});

describe('Delete node', () => {
  it('Should delete node', () => {
    expect(layout.layout.children).to.have.length(5);
    expect(layout.layout.children.map((item) => item.uuid)).to.deep.equal([
      'dynamic-image-udf7d',
      'dynamic-static-card2df',
      'dynamic-dynamic-card3rr',
      'dynamic-image-static-imageStyle-something7d',
      'ee07d472-a754-4427-b6d4-acfc6f92bbdc',
    ]);
    let state = layoutModelSlice.reducer(
      layout,
      deleteNode('dynamic-static-card2df'),
    );
    cy.wrap(state.layout.children).should('have.length', 4);
    expect(state.layout.children.map((item) => item.uuid)).to.deep.equal([
      'dynamic-image-udf7d',
      'dynamic-dynamic-card3rr',
      'dynamic-image-static-imageStyle-something7d',
      'ee07d472-a754-4427-b6d4-acfc6f92bbdc',
    ]);

    // Delete node with children
    // Check if all UUIDs of nested component(Two Column) exists before deletion
    cy.wrap(Object.keys(state.model)).then((items) => {
      [
        'ee07d472-a754-4427-b6d4-acfc6f92bbdc',
        '6f3224e2-cb61-46e4-a9e4-35b4d18f0a82',
        '3b709ed2-99d3-4db2-869d-ca426f69fbb9',
        'eaa37ee1-7d50-4041-b04c-c80bdbac3412',
      ].forEach((element) => {
        expect(items).to.include(element);
      });
    });
    state = layoutModelSlice.reducer(
      layout,
      deleteNode('ee07d472-a754-4427-b6d4-acfc6f92bbdc'),
    );
    cy.wrap(state.layout.children).should('have.length', 4);
    expect(state.layout.children.map((item) => item.uuid)).to.deep.equal([
      'dynamic-image-udf7d',
      'dynamic-static-card2df',
      'dynamic-dynamic-card3rr',
      'dynamic-image-static-imageStyle-something7d',
    ]);
    // To be double sure: check if all UUIDs of nested component(Two Column) should not exist after deletion
    cy.wrap(Object.keys(state.model)).then((items) => {
      [
        'ee07d472-a754-4427-b6d4-acfc6f92bbdc',
        '6f3224e2-cb61-46e4-a9e4-35b4d18f0a82',
        '3b709ed2-99d3-4db2-869d-ca426f69fbb9',
        'eaa37ee1-7d50-4041-b04c-c80bdbac3412',
      ].forEach((element) => {
        expect(items).to.not.include(element);
      });
    });
    expect(Object.keys(state.model)).to.deep.equal([
      'dynamic-image-udf7d',
      'static-static-card1ab',
      'dynamic-static-card2df',
      'dynamic-dynamic-card3rr',
      'dynamic-image-static-imageStyle-something7d',
    ]);
  });
});

describe('Move node', () => {
  it('Should move node', () => {
    cy.wrap(layout.layout.children[0].children[0].children).should(
      'have.length',
      1,
    );
    cy.wrap(layout.layout.children[2].children[0].children).should(
      'have.length',
      0,
    );
    expect(
      layout.layout.children[0].children[0].children[0].uuid,
    ).to.deep.equal('static-static-card1ab');
    const state = layoutModelSlice.reducer(
      layout,
      moveNode({
        uuid: 'static-static-card1ab',
        to: [2, 0, 1],
      }),
    );
    cy.wrap(state.layout.children[0].children[0].children).should(
      'have.length',
      0,
    );
    cy.wrap(state.layout.children[2].children[0].children).should(
      'have.length',
      1,
    );
    expect(
      state.layout.children[2].children[0].children.map((item) => item.uuid),
    ).to.deep.equal(['static-static-card1ab']);
  });
});

describe('Undo/redo', () => {
  it('Should support undo when past state exists', () => {
    const store = makeStore({
      layoutModel: { present: layout, past: [initialState], future: [] },
    });
    let state = selectHistory(store.getState());
    expect(state.present).to.eq(layout);
    cy.wrap(state.past).should('have.length', 1);
    cy.wrap(state.future).should('have.length', 0);
    store.dispatch(ActionCreators.undo());

    state = selectHistory(store.getState());
    expect(state.present).to.eq(initialState);
    cy.wrap(state.past).should('have.length', 0);
    cy.wrap(state.future).should('have.length', 1);
  });
  it('Should support redo when future state exists', () => {
    const store = makeStore({
      layoutModel: { present: layout, past: [initialState], future: [] },
    });
    let state = selectHistory(store.getState());
    expect(state.present).to.eq(layout);
    store.dispatch(ActionCreators.undo());

    state = selectHistory(store.getState());
    expect(state.present).to.eq(initialState);
    cy.wrap(state.past).should('have.length', 0);
    cy.wrap(state.future).should('have.length', 1);
    store.dispatch(ActionCreators.redo());

    state = selectHistory(store.getState());
    expect(state.present).to.eq(layout);
    cy.wrap(state.past).should('have.length', 1);
    cy.wrap(state.future).should('have.length', 0);
  });
  it('Should not support undo of initial load', () => {
    const store = makeStore({
      layoutModel: { present: initialState, past: [], future: [] },
    });
    let state = selectHistory(store.getState());
    expect(state.present).to.eq(initialState);
    cy.wrap(state.past).should('have.length', 0);
    cy.wrap(state.future).should('have.length', 0);
    store.dispatch(setLayoutModel(layout));

    state = selectHistory(store.getState());
    expect(state.present.layout).to.deep.equal(layout.layout);
    expect(state.present.model).to.deep.equal(layout.model);
    cy.wrap(state.past).should('have.length', 0);
    cy.wrap(state.future).should('have.length', 0);
  });
});
describe('Duplicate node', () => {
  it('Should duplicate a node correctly with a new UUID and duplicate its children nodes', () => {
    // Initialize state with a layout
    const initialStateWithLayout = layoutModelSlice.reducer(
      initialState,
      setLayoutModel({
        layout: {
          uuid: 'root',
          nodeType: 'root',
          name: 'root',
          children: [
            {
              uuid: 'original-node',
              nodeType: 'component',
              name: 'Original Node',
              children: [
                {
                  uuid: 'child-1',
                  nodeType: 'component',
                  name: 'Child 1',
                  children: [],
                },
                {
                  uuid: 'child-2',
                  nodeType: 'component',
                  name: 'Child 2',
                  children: [],
                },
              ],
            },
          ],
        },
        model: {},
        initialized: true,
      }),
    );

    const nodeToDuplicateUUID = 'original-node';
    const stateAfterDuplication = layoutModelSlice.reducer(
      initialStateWithLayout,
      duplicateNode({ uuid: nodeToDuplicateUUID }),
    );

    const originalNode = initialStateWithLayout.layout.children.find(
      (node) => node.uuid === nodeToDuplicateUUID,
    );
    const newNode = stateAfterDuplication.layout.children.find(
      (node) => node.uuid !== nodeToDuplicateUUID,
    );

    // Ensure the new node is a duplicate and has a different UUID
    expect(newNode).to.not.be.undefined;
    expect(newNode.uuid).to.not.equal(nodeToDuplicateUUID);
    expect(newNode.name).to.equal(originalNode.name);
    expect(newNode.nodeType).to.equal(originalNode.nodeType);
    expect(newNode.children.length).to.equal(originalNode.children.length);

    // Verify each child node's UUID in the new node
    originalNode.children.forEach((originalChild, index) => {
      const newChild = newNode.children[index];
      expect(newChild).to.not.be.undefined;
      expect(newChild.uuid).to.not.equal(originalChild.uuid);
      expect(newChild.name).to.equal(originalChild.name);
      expect(newChild.nodeType).to.equal(originalChild.nodeType);
      expect(newChild.children).to.deep.equal(originalChild.children);
    });

    // Verify the model for the new node and its children
    expect(stateAfterDuplication.model[newNode.uuid]).to.deep.equal(
      stateAfterDuplication.model[nodeToDuplicateUUID],
    );
  });
});
