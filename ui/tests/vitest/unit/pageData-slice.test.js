import { makeStore } from '@/app/store';
import {
  pageDataSlice,
  selectPageDataHistory,
  setPageData,
} from '@/features/pageData/pageDataSlice';
import {
  initialState,
  pushUndo,
  selectUndoItem,
  UndoRedoActionCreators,
} from '@/features/ui/uiSlice';

let pageData = {
  title: [{ value: 'Some title' }],
};

describe('Set page state', () => {
  it('Should set page state', () => {
    const state = pageDataSlice.reducer({}, setPageData(pageData));
    expect(state).to.deep.equal(pageData);
  });
});

describe('Undo/redo', () => {
  it('Should support undo when past state exists', () => {
    const store = makeStore({
      pageData: { present: pageData, past: [{}], future: [] },
      ui: initialState,
    });
    let state = selectPageDataHistory(store.getState());
    expect(state.present).to.deep.equal(pageData);
    expect(state.past).to.have.lengthOf(1);
    expect(state.future).to.have.lengthOf(0);
    store.dispatch(UndoRedoActionCreators.undo('pageData'));

    state = selectPageDataHistory(store.getState());
    expect(state.present).to.deep.equal({});
    expect(state.past).to.have.lengthOf(0);
    expect(state.future).to.have.lengthOf(1);
  });

  it('Should support redo when future state exists', () => {
    const store = makeStore({
      pageData: { present: pageData, past: [{}], future: [] },
      ui: initialState,
    });
    let state = selectPageDataHistory(store.getState());
    expect(state.present).to.deep.equal(pageData);
    store.dispatch(UndoRedoActionCreators.undo('pageData'));

    state = selectPageDataHistory(store.getState());
    expect(state.present).to.deep.equal({});
    expect(state.past).to.have.lengthOf(0);
    expect(state.future).to.have.lengthOf(1);
    store.dispatch(UndoRedoActionCreators.redo('pageData'));

    state = selectPageDataHistory(store.getState());
    expect(state.present).to.deep.equal(pageData);
    expect(state.past).to.have.lengthOf(1);
    expect(state.future).to.have.lengthOf(0);
  });

  it('Should not support undo of initial load', () => {
    const store = makeStore({
      pageData: { present: {}, past: [], future: [] },
      ui: initialState,
    });
    let state = selectPageDataHistory(store.getState());
    expect(state.present).to.deep.equal({});
    expect(state.past).to.have.lengthOf(0);
    expect(state.future).to.have.lengthOf(0);
    store.dispatch(setPageData(pageData));

    state = selectPageDataHistory(store.getState());
    expect(state.present).to.deep.equal(pageData);
    expect(state.past).to.have.lengthOf(0);
    expect(state.future).to.have.lengthOf(0);
  });

  it('Should prune future state if undo type changes', () => {
    const store = makeStore({
      pageData: { present: pageData, past: [], future: [] },
      ui: initialState,
    });
    const newState = {
      ...pageData,
      published: [{ value: true }],
    };
    let state = selectPageDataHistory(store.getState());
    expect(state.present).to.deep.equal(pageData);
    store.dispatch(setPageData(newState));

    state = selectPageDataHistory(store.getState());
    expect(state.past).to.have.lengthOf(1);
    expect(state.future).to.have.lengthOf(0);

    store.dispatch(UndoRedoActionCreators.undo('pageData'));
    state = selectPageDataHistory(store.getState());
    expect(state.present).to.deep.equal(pageData);
    expect(state.past).to.have.lengthOf(0);
    expect(state.future).to.have.lengthOf(1);

    store.dispatch(
      pushUndo({
        targetSlice: 'layoutModel',
        routeSnapshot: {
          pathname: '/test',
          search: '',
          hash: '',
        },
      }),
    );
    const undoRedoType = selectUndoItem(store.getState());
    console.log('undoRedoType', undoRedoType);
    expect(undoRedoType.targetSlice).to.eq('layoutModel');

    state = selectPageDataHistory(store.getState());
    expect(state.present).to.deep.equal(pageData);
    expect(state.past).to.have.lengthOf(0);
    expect(state.future).to.have.lengthOf(0);
  });
});
