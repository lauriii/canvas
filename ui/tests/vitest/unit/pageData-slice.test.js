import { makeStore } from '@/app/store';
import {
  pageDataOwnerSlice,
  pageDataSlice,
  resetPageData,
  selectPageDataHistory,
  setInitialPageData,
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

  it('Should reset page state', () => {
    const state = pageDataSlice.reducer(pageData, resetPageData());
    expect(state).to.deep.equal({});
  });

  it('Should replace page state with initial data', () => {
    const initialPageData = { status: [{ value: true }] };
    const state = pageDataSlice.reducer(
      pageData,
      setInitialPageData({ values: initialPageData, owner: null }),
    );
    expect(state).to.deep.equal(initialPageData);
  });

  it('Should record the entity that owns the initial page data', () => {
    const owner = { entityType: 'canvas_page', entityId: '123' };
    const action = setInitialPageData({ values: pageData, owner });

    expect(pageDataOwnerSlice.reducer(null, action)).to.deep.equal(owner);
  });

  it('Should reset the page data owner', () => {
    const owner = { entityType: 'canvas_page', entityId: '123' };

    expect(pageDataOwnerSlice.reducer(owner, resetPageData())).to.equal(null);
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

  it('Should not add a navigation reset to undo history', () => {
    const store = makeStore({
      pageData: { present: pageData, past: [{}], future: [] },
      ui: initialState,
    });

    store.dispatch(resetPageData());

    const state = selectPageDataHistory(store.getState());
    expect(state.present).to.deep.equal({});
    expect(state.past).to.have.lengthOf(1);
    expect(state.future).to.have.lengthOf(0);
    expect(store.getState().ui.undoStack).to.deep.equal([]);
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
