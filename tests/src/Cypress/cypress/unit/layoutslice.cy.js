import {selectHistory, deleteNode, moveNode, layoutModelSlice, setLayoutModel, initialState } from "../../../../../ui/src/features/layout/layoutModelSlice";
import layout from "../../../../../ui/src/mocks/fixtures/layout-default.json"
import { makeStore } from "../../../../../ui/src/app/store";
import { ActionCreators } from "redux-undo";

describe('Set layout model', () => {
  it('Should set model and layout', () => {
    const state = layoutModelSlice.reducer(initialState, setLayoutModel(layout))
    expect(state.layout).to.eq(layout.layout);
    expect(state.model).to.eq(layout.model);
  })
})

describe('Delete node', () => {
  it('Should delete node', () => {
    cy.wrap(layout.layout.children).should('have.length', 3);
    expect(layout.layout.children.map(item => item.uuid)).to.eq([
      '43cd7aa4-0160-4787-a3af-baf44ff17a88',
      'fcd2490d-1124-4146-82b6-b1e049ed8026',
      '1941ffae-f9ed-4ce3-8145-a2c3977ac65b',
    ]);
    const state = layoutModelSlice.reducer(layout, deleteNode('43cd7aa4-0160-4787-a3af-baf44ff17a88'))
    cy.wrap(state.layout.children).should('have.length', 2);
    expect(state.layout.children.map(item => item.uuid)).to.eq([
      'fcd2490d-1124-4146-82b6-b1e049ed8026',
      '1941ffae-f9ed-4ce3-8145-a2c3977ac65b',
    ]);
  })
})

describe('Move node', () => {
  it('Should move node', () => {
    cy.wrap(layout.layout.children[2].children[0].children).should('have.length', 1);
    cy.wrap(layout.layout.children[2].children[1].children).should('have.length', 1);
    expect(layout.layout.children[2].children[0].children[0].uuid).to.eq('bdfce52f-e666-49f0-a57f-dfb8c5c0c75b');
    const state = layoutModelSlice.reducer(layout, moveNode({
      uuid: 'bdfce52f-e666-49f0-a57f-dfb8c5c0c75b',
      to: [2, 1, 1],
    }))
    cy.wrap(state.layout.children[2].children[0].children).should('have.length', 0);
    cy.wrap(state.layout.children[2].children[1].children).should('have.length', 2);
    expect(state.layout.children[2].children[1].children.map(item => item.uuid)).to.eq([
      'fe01d628-55ab-4146-9d04-71e5a01ad233',
      'bdfce52f-e666-49f0-a57f-dfb8c5c0c75b',
    ]);
  })
})

describe('Undo/redo', () => {
  it('Should support undo when past state exists', () => {
    const store = makeStore({layoutModel: {present: layout, past: [initialState], future: []}});
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
    const store = makeStore({layoutModel: {present: layout, past: [initialState], future: []}});
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
    const store = makeStore({layoutModel: {present: initialState, past: [], future: []}});
    let state = selectHistory(store.getState());
    expect(state.present).to.eq(initialState);
    cy.wrap(state.past).should('have.length', 0);
    cy.wrap(state.future).should('have.length', 0);
    store.dispatch(setLayoutModel(layout));

    state = selectHistory(store.getState());
    expect(state.present).to.eq(layout);
    cy.wrap(state.past).should('have.length', 0);
    cy.wrap(state.future).should('have.length', 0);
  });
});
