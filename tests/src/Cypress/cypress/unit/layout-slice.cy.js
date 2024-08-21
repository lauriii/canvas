import {
  selectHistory,
  deleteNode,
  moveNode,
  layoutModelSlice,
  setLayoutModel,
  initialState,
} from '../../../../../ui/src/features/layout/layoutModelSlice';
import { makeStore } from '../../../../../ui/src/app/store';
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
    expect(layout.layout.children).to.have.length(4);
    expect(layout.layout.children.map((item) => item.uuid)).to.deep.equal([
      'dynamic-image-udf7d',
      'dynamic-static-card2df',
      'dynamic-dynamic-card3rr',
      'dynamic-image-static-imageStyle-something7d',
    ]);
    const state = layoutModelSlice.reducer(
      layout,
      deleteNode('dynamic-static-card2df'),
    );
    cy.wrap(state.layout.children).should('have.length', 3);
    expect(state.layout.children.map((item) => item.uuid)).to.deep.equal([
      'dynamic-image-udf7d',
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
