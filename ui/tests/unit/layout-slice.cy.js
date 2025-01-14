import {
  selectLayoutHistory,
  deleteNode,
  moveNode,
  layoutModelSlice,
  setLayoutModel,
  initialState,
  duplicateNode,
} from '@/features/layout/layoutModelSlice';
import { makeStore } from '@/app/store';
import {
  selectUndoType,
  UndoRedoActionCreators,
  initialState as uiInitialState,
} from '@/features/ui/uiSlice';
import { setPageData } from '@/features/pageData/pageDataSlice';

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
    expect(layout.layout[0].components).to.have.length(5);
    expect(layout.layout[0].components.map((item) => item.uuid)).to.deep.equal([
      'static-image-udf7d',
      'static-static-card2df',
      'static-static-card3rr',
      'static-image-static-imageStyle-something7d',
      'ee07d472-a754-4427-b6d4-acfc6f92bbdc',
    ]);
    let state = layoutModelSlice.reducer(
      layout,
      deleteNode('static-static-card2df'),
    );
    cy.wrap(state.layout[0].components).should('have.length', 4);
    expect(state.layout[0].components.map((item) => item.uuid)).to.deep.equal([
      'static-image-udf7d',
      'static-static-card3rr',
      'static-image-static-imageStyle-something7d',
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
    cy.wrap(state.layout[0].components).should('have.length', 4);
    expect(state.layout[0].components.map((item) => item.uuid)).to.deep.equal([
      'static-image-udf7d',
      'static-static-card2df',
      'static-static-card3rr',
      'static-image-static-imageStyle-something7d',
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
      'static-image-udf7d',
      'static-static-card1ab',
      'static-static-card2df',
      'static-static-card3rr',
      'static-image-static-imageStyle-something7d',
    ]);
  });
});

describe('Move node', () => {
  it('Should move node', () => {
    cy.wrap(layout.layout[0].components[0].slots[0].components).should(
      'have.length',
      1,
    );
    cy.wrap(layout.layout[0].components[2].slots[0].components).should(
      'have.length',
      0,
    );
    expect(
      layout.layout[0].components[0].slots[0].components[0].uuid,
    ).to.deep.equal('static-static-card1ab');
    const state = layoutModelSlice.reducer(
      layout,
      moveNode({
        uuid: 'static-static-card1ab',
        to: [0, 2, 0, 1],
      }),
    );
    cy.wrap(state.layout[0].components[0].slots[0].components).should(
      'have.length',
      0,
    );
    cy.wrap(state.layout[0].components[2].slots[0].components).should(
      'have.length',
      1,
    );
    expect(
      state.layout[0].components[2].slots[0].components.map(
        (item) => item.uuid,
      ),
    ).to.deep.equal(['static-static-card1ab']);
  });
});

describe('Undo/redo', () => {
  it('Should support undo when past state exists', () => {
    const store = makeStore({
      layoutModel: { present: layout, past: [initialState], future: [] },
      ui: uiInitialState,
    });
    let state = selectLayoutHistory(store.getState());
    expect(state.present).to.eq(layout);
    cy.wrap(state.past).should('have.length', 1);
    cy.wrap(state.future).should('have.length', 0);
    store.dispatch(UndoRedoActionCreators.undo('layoutModel'));

    state = selectLayoutHistory(store.getState());
    expect(state.present).to.eq(initialState);
    cy.wrap(state.past).should('have.length', 0);
    cy.wrap(state.future).should('have.length', 1);
  });
  it('Should support redo when future state exists', () => {
    const store = makeStore({
      layoutModel: { present: layout, past: [initialState], future: [] },
      ui: uiInitialState,
    });
    let state = selectLayoutHistory(store.getState());
    expect(state.present).to.eq(layout);
    store.dispatch(UndoRedoActionCreators.undo('layoutModel'));

    state = selectLayoutHistory(store.getState());
    expect(state.present).to.eq(initialState);
    cy.wrap(state.past).should('have.length', 0);
    cy.wrap(state.future).should('have.length', 1);
    store.dispatch(UndoRedoActionCreators.redo('layoutModel'));

    state = selectLayoutHistory(store.getState());
    expect(state.present).to.eq(layout);
    cy.wrap(state.past).should('have.length', 1);
    cy.wrap(state.future).should('have.length', 0);
  });
  it('Should not support undo of initial load', () => {
    const store = makeStore({
      layoutModel: { present: initialState, past: [], future: [] },
      ui: uiInitialState,
    });
    let state = selectLayoutHistory(store.getState());
    expect(state.present).to.eq(initialState);
    cy.wrap(state.past).should('have.length', 0);
    cy.wrap(state.future).should('have.length', 0);
    store.dispatch(setLayoutModel(layout));
    const undoType = selectUndoType(store.getState());
    expect(undoType).to.eq('layoutModel');

    state = selectLayoutHistory(store.getState());
    expect(state.present.layout).to.deep.equal(layout.layout);
    expect(state.present.model).to.deep.equal(layout.model);
    cy.wrap(state.past).should('have.length', 0);
    cy.wrap(state.future).should('have.length', 0);
  });
  it('Should prune future state if undo type changes', () => {
    const store = makeStore({
      layoutModel: { present: layout, past: [initialState], future: [] },
      pageData: {
        present: { title: [{ value: 'Title' }] },
        past: [{}],
        future: [],
      },
      ui: uiInitialState,
    });
    let state = selectLayoutHistory(store.getState());
    expect(state.present).to.deep.equal(layout);

    state = selectLayoutHistory(store.getState());
    cy.wrap(state.past).should('have.length', 1);
    cy.wrap(state.future).should('have.length', 0);

    store.dispatch(UndoRedoActionCreators.undo('layoutModel'));
    state = selectLayoutHistory(store.getState());
    expect(state.present).to.deep.equal(initialState);
    cy.wrap(state.past).should('have.length', 0);
    cy.wrap(state.future).should('have.length', 1);

    store.dispatch(setPageData({}));
    const undoType = selectUndoType(store.getState());
    expect(undoType).to.eq('pageData');

    state = selectLayoutHistory(store.getState());
    expect(state.present).to.deep.equal(initialState);
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
        layout: [
          {
            nodeType: 'region',
            name: 'content',
            components: [
              {
                uuid: 'original-node',
                nodeType: 'component',
                name: 'Original Node',
                slots: [
                  {
                    id: 'original-node/child1',
                    nodeType: 'slot',
                    name: 'Slot 1',
                    components: [],
                  },
                  {
                    id: 'original-node/child2',
                    nodeType: 'slot',
                    name: 'Slot 2',
                    components: [],
                  },
                ],
              },
            ],
          },
        ],
        model: {},
        initialized: true,
      }),
    );

    const nodeToDuplicateUUID = 'original-node';
    const stateAfterDuplication = layoutModelSlice.reducer(
      initialStateWithLayout,
      duplicateNode({ uuid: nodeToDuplicateUUID }),
    );

    const originalNode = initialStateWithLayout.layout[0].components.find(
      (node) => node.uuid === nodeToDuplicateUUID,
    );
    const newNode = stateAfterDuplication.layout[0].components.find(
      (node) => node.uuid !== nodeToDuplicateUUID,
    );

    // Ensure the new node is a duplicate and has a different UUID
    expect(newNode).to.not.be.undefined;
    expect(newNode.uuid).to.not.equal(nodeToDuplicateUUID);
    expect(newNode.type).to.equal(originalNode.type);
    expect(newNode.nodeType).to.equal(originalNode.nodeType);
    expect(newNode.slots.length).to.equal(originalNode.slots.length);

    // Verify each child node's UUID in the new node
    originalNode.slots.forEach((originalChild, index) => {
      const newChild = newNode.slots[index];
      expect(newChild).to.not.be.undefined;
      expect(newChild.id).to.not.equal(originalChild.id);
      expect(newChild.name).to.equal(originalChild.name);
      expect(newChild.nodeType).to.equal(originalChild.nodeType);
      expect(newChild.components).to.deep.equal(originalChild.components);
    });

    // Verify the model for the new node and its children
    expect(stateAfterDuplication.model[newNode.uuid]).to.deep.equal(
      stateAfterDuplication.model[nodeToDuplicateUUID],
    );
  });
});
