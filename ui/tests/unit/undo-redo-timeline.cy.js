import {
  setPageData,
  selectPageDataHistory,
} from '@/features/pageData/pageDataSlice';
import { selectUndoType, selectRedoType } from '@/features/ui/uiSlice';
import { makeStore } from '@/app/store';
import {
  UndoRedoActionCreators,
  initialState as uiInitialState,
} from '@/features/ui/uiSlice';
import {
  selectLayoutHistory,
  initialState,
  insertNodes,
} from '@/features/layout/layoutModelSlice';

let pageData = {
  title: [{ value: 'Some title' }],
};
let layout = {
  initialized: false,
  layout: [
    {
      nodeType: 'region',
      components: [
        {
          nodeType: 'component',
          uuid: 'static-static-card1ab',
          type: 'sdc_test:my-cta',
          slots: [],
        },
      ],
      id: 'content',
      name: 'Content',
    },
  ],
  model: {
    'static-static-card1ab': {
      text: 'hello, world!',
      href: 'https://drupal.org',
      name: 'My Test CTA Component',
    },
  },
};

describe('Undo/redo timeline works across slices', () => {
  it('Should support undo and redo across slices', () => {
    const store = makeStore({
      pageData: { present: pageData, past: [{}], future: [] },
      layoutModel: { present: layout, past: [initialState], future: [] },
      ui: uiInitialState,
    });
    let pageState = selectPageDataHistory(store.getState());
    let layoutState = selectLayoutHistory(store.getState());
    expect(pageState.present).to.deep.equal(pageData);
    expect(pageState.past).to.have.lengthOf(1);
    expect(pageState.future).to.have.lengthOf(0);
    expect(layoutState.present).to.deep.equal({
      ...layout,
      initialized: false,
    });
    expect(layoutState.past).to.have.lengthOf(1);
    expect(layoutState.future).to.have.lengthOf(0);
    expect(store.getState().ui.redoStack).to.deep.equal([]);
    expect(store.getState().ui.undoStack).to.deep.equal([]);

    // Perform some actions.
    // 1) Update page title
    const newTitle = { title: [{ value: 'New title' }] };
    store.dispatch(setPageData(newTitle));
    pageState = selectPageDataHistory(store.getState());
    layoutState = selectLayoutHistory(store.getState());
    expect(pageState.present).to.deep.equal(newTitle);
    expect(pageState.past).to.have.lengthOf(2);
    expect(pageState.future).to.have.lengthOf(0);
    expect(layoutState.present).to.deep.equal(layout);
    expect(layoutState.past).to.have.lengthOf(1);
    expect(layoutState.future).to.have.lengthOf(0);
    expect(store.getState().ui.redoStack).to.deep.equal([]);
    expect(store.getState().ui.undoStack).to.deep.equal(['pageData']);

    // 2) Change layout model
    const newItem = {
      layout: [
        {
          slots: [],
          nodeType: 'component',
          type: 'some.block',
          uuid: 'abc1234',
        },
      ],
      model: {
        abc1234: { title: 'New component' },
      },
    };

    store.dispatch(
      insertNodes({
        to: [0, 0],
        layoutModel: newItem,
        useUUID: 'abc1234',
      }),
    );
    pageState = selectPageDataHistory(store.getState());
    layoutState = selectLayoutHistory(store.getState());
    expect(pageState.present).to.deep.equal(newTitle);
    expect(pageState.past).to.have.lengthOf(2);
    expect(pageState.future).to.have.lengthOf(0);
    expect(Object.keys(layoutState.present.model)).to.deep.equal([
      'static-static-card1ab',
      'abc1234',
    ]);
    expect(layoutState.past).to.have.lengthOf(2);
    expect(layoutState.future).to.have.lengthOf(0);
    expect(store.getState().ui.redoStack).to.deep.equal([]);
    expect(store.getState().ui.undoStack).to.deep.equal([
      'pageData',
      'layoutModel',
    ]);

    // 3) Change page title
    const newerTitle = { title: [{ value: 'Newer title' }] };
    store.dispatch(setPageData(newerTitle));
    pageState = selectPageDataHistory(store.getState());
    layoutState = selectLayoutHistory(store.getState());
    expect(pageState.present).to.deep.equal(newerTitle);
    expect(pageState.past).to.have.lengthOf(3);
    expect(pageState.future).to.have.lengthOf(0);
    expect(Object.keys(layoutState.present.model)).to.deep.equal([
      'static-static-card1ab',
      'abc1234',
    ]);
    expect(layoutState.past).to.have.lengthOf(2);
    expect(layoutState.future).to.have.lengthOf(0);
    expect(store.getState().ui.redoStack).to.deep.equal([]);
    expect(store.getState().ui.undoStack).to.deep.equal([
      'pageData',
      'layoutModel',
      'pageData',
    ]);

    // 4) Undo page title change (3)
    let undoType = selectUndoType(store.getState());
    expect(undoType).to.eq('pageData');
    store.dispatch(UndoRedoActionCreators.undo(undoType));
    pageState = selectPageDataHistory(store.getState());
    layoutState = selectLayoutHistory(store.getState());
    expect(pageState.present).to.deep.equal(newTitle);
    expect(pageState.past).to.have.lengthOf(2);
    expect(pageState.future).to.have.lengthOf(1);
    expect(Object.keys(layoutState.present.model)).to.deep.equal([
      'static-static-card1ab',
      'abc1234',
    ]);
    expect(layoutState.past).to.have.lengthOf(2);
    expect(layoutState.future).to.have.lengthOf(0);
    expect(store.getState().ui.redoStack).to.deep.equal(['pageData']);
    expect(store.getState().ui.undoStack).to.deep.equal([
      'pageData',
      'layoutModel',
    ]);

    // 5) Undo layout model change (2)
    undoType = selectUndoType(store.getState());
    expect(undoType).to.eq('layoutModel');
    store.dispatch(UndoRedoActionCreators.undo(undoType));
    pageState = selectPageDataHistory(store.getState());
    layoutState = selectLayoutHistory(store.getState());
    expect(pageState.present).to.deep.equal(newTitle);
    expect(pageState.past).to.have.lengthOf(2);
    expect(pageState.future).to.have.lengthOf(1);
    expect(layoutState.past).to.have.lengthOf(1);
    expect(Object.keys(layoutState.present.model)).to.deep.equal([
      'static-static-card1ab',
    ]);
    expect(layoutState.future).to.have.lengthOf(1);
    expect(store.getState().ui.redoStack).to.deep.equal([
      'layoutModel',
      'pageData',
    ]);
    expect(store.getState().ui.undoStack).to.deep.equal(['pageData']);

    // 6) Redo layout model change (2)
    let redoType = selectRedoType(store.getState());
    expect(redoType).to.eq('layoutModel');
    store.dispatch(UndoRedoActionCreators.redo(redoType));
    pageState = selectPageDataHistory(store.getState());
    layoutState = selectLayoutHistory(store.getState());
    expect(pageState.present).to.deep.equal(newTitle);
    expect(pageState.past).to.have.lengthOf(2);
    expect(pageState.future).to.have.lengthOf(1);
    expect(layoutState.past).to.have.lengthOf(2);
    expect(Object.keys(layoutState.present.model)).to.deep.equal(
      ['static-static-card1ab', 'abc1234'],
      'Layout state restored',
    );
    expect(layoutState.future).to.have.lengthOf(0);
    expect(store.getState().ui.redoStack).to.deep.equal(['pageData']);
    expect(store.getState().ui.undoStack).to.deep.equal([
      'pageData',
      'layoutModel',
    ]);

    // 7) Redo page title change (3)
    redoType = selectRedoType(store.getState());
    expect(redoType).to.eq('pageData');
    store.dispatch(UndoRedoActionCreators.redo(redoType));
    pageState = selectPageDataHistory(store.getState());
    layoutState = selectLayoutHistory(store.getState());
    expect(pageState.present).to.deep.equal(newerTitle);
    expect(pageState.past).to.have.lengthOf(3);
    expect(pageState.future).to.have.lengthOf(0);
    expect(layoutState.past).to.have.lengthOf(2);
    expect(Object.keys(layoutState.present.model)).to.deep.equal(
      ['static-static-card1ab', 'abc1234'],
      'Layout state restored',
    );
    expect(layoutState.future).to.have.lengthOf(0);
    expect(store.getState().ui.redoStack).to.deep.equal([]);
    expect(store.getState().ui.undoStack).to.deep.equal([
      'pageData',
      'layoutModel',
      'pageData',
    ]);

    // 8) Undo page title change (7)
    undoType = selectUndoType(store.getState());
    expect(undoType).to.eq('pageData');
    store.dispatch(UndoRedoActionCreators.undo(undoType));
    pageState = selectPageDataHistory(store.getState());
    layoutState = selectLayoutHistory(store.getState());
    expect(pageState.present).to.deep.equal(newTitle);
    expect(pageState.past).to.have.lengthOf(2);
    expect(pageState.future).to.have.lengthOf(1);
    expect(Object.keys(layoutState.present.model)).to.deep.equal([
      'static-static-card1ab',
      'abc1234',
    ]);
    expect(layoutState.past).to.have.lengthOf(2);
    expect(layoutState.future).to.have.lengthOf(0);
    expect(store.getState().ui.redoStack).to.deep.equal(['pageData']);
    expect(store.getState().ui.undoStack).to.deep.equal([
      'pageData',
      'layoutModel',
    ]);

    // 9) Make a layout model change
    const secondNewItem = {
      layout: [
        {
          slots: [],
          nodeType: 'component',
          type: 'some.other_block',
          uuid: 'bar1234',
        },
      ],
      model: {
        bar1234: { title: 'Second component' },
      },
    };

    store.dispatch(
      insertNodes({
        to: [0, 1],
        layoutModel: secondNewItem,
        useUUID: 'bar1234',
      }),
    );
    pageState = selectPageDataHistory(store.getState());
    layoutState = selectLayoutHistory(store.getState());
    expect(pageState.present).to.deep.equal(newTitle);
    expect(pageState.past).to.have.lengthOf(2);
    // Future state should be lost because we've dispatched a different action.
    expect(pageState.future).to.have.lengthOf(0);
    expect(Object.keys(layoutState.present.model)).to.deep.equal([
      'static-static-card1ab',
      'abc1234',
      'bar1234',
    ]);
    expect(layoutState.past).to.have.lengthOf(3);
    expect(layoutState.future).to.have.lengthOf(0);
    // There is now no redo because we've performed a different action.
    expect(store.getState().ui.redoStack).to.deep.equal([]);
    expect(store.getState().ui.undoStack).to.deep.equal([
      'pageData',
      'layoutModel',
      'layoutModel',
    ]);

    // 10) Make a page title change
    const newestTitle = { title: [{ value: 'Newest title' }] };
    store.dispatch(setPageData(newestTitle));
    pageState = selectPageDataHistory(store.getState());
    layoutState = selectLayoutHistory(store.getState());
    expect(pageState.present).to.deep.equal(newestTitle);
    expect(pageState.past).to.have.lengthOf(3);
    expect(pageState.future).to.have.lengthOf(0);
    expect(Object.keys(layoutState.present.model)).to.deep.equal([
      'static-static-card1ab',
      'abc1234',
      'bar1234',
    ]);
    expect(layoutState.past).to.have.lengthOf(3);
    expect(layoutState.future).to.have.lengthOf(0);
    // There is now no redo because we've performed a different action.
    expect(store.getState().ui.redoStack).to.deep.equal([]);
    expect(store.getState().ui.undoStack).to.deep.equal([
      'pageData',
      'layoutModel',
      'layoutModel',
      'pageData',
    ]);

    // 11) Undo page title change (10)
    undoType = selectUndoType(store.getState());
    expect(undoType).to.eq('pageData');
    store.dispatch(UndoRedoActionCreators.undo(undoType));
    pageState = selectPageDataHistory(store.getState());
    layoutState = selectLayoutHistory(store.getState());
    expect(pageState.present).to.deep.equal(newTitle);
    expect(pageState.past).to.have.lengthOf(2);
    expect(pageState.future).to.have.lengthOf(1);
    expect(Object.keys(layoutState.present.model)).to.deep.equal([
      'static-static-card1ab',
      'abc1234',
      'bar1234',
    ]);
    expect(layoutState.past).to.have.lengthOf(3);
    expect(layoutState.future).to.have.lengthOf(0);
    // There is now no redo because we've performed a different action.
    expect(store.getState().ui.redoStack).to.deep.equal(['pageData']);
    expect(store.getState().ui.undoStack).to.deep.equal([
      'pageData',
      'layoutModel',
      'layoutModel',
    ]);

    // 12) Undo layout model change (9)
    undoType = selectUndoType(store.getState());
    expect(undoType).to.eq('layoutModel');
    store.dispatch(UndoRedoActionCreators.undo(undoType));
    pageState = selectPageDataHistory(store.getState());
    layoutState = selectLayoutHistory(store.getState());
    expect(pageState.present).to.deep.equal(newTitle);
    expect(pageState.past).to.have.lengthOf(2);
    expect(pageState.future).to.have.lengthOf(1);
    expect(Object.keys(layoutState.present.model)).to.deep.equal([
      'static-static-card1ab',
      'abc1234',
    ]);
    expect(layoutState.past).to.have.lengthOf(2);
    expect(layoutState.future).to.have.lengthOf(1);
    // There is now no redo because we've performed a different action.
    expect(store.getState().ui.redoStack).to.deep.equal([
      'layoutModel',
      'pageData',
    ]);
    expect(store.getState().ui.undoStack).to.deep.equal([
      'pageData',
      'layoutModel',
    ]);

    // 13) Redo layout model change (9)
    redoType = selectRedoType(store.getState());
    expect(redoType).to.eq('layoutModel');
    store.dispatch(UndoRedoActionCreators.redo(redoType));
    pageState = selectPageDataHistory(store.getState());
    layoutState = selectLayoutHistory(store.getState());
    expect(pageState.present).to.deep.equal(newTitle);
    expect(pageState.past).to.have.lengthOf(2);
    expect(pageState.future).to.have.lengthOf(1);
    expect(Object.keys(layoutState.present.model)).to.deep.equal([
      'static-static-card1ab',
      'abc1234',
      'bar1234',
    ]);
    expect(layoutState.past).to.have.lengthOf(3);
    expect(layoutState.future).to.have.lengthOf(0);
    // There is now no redo because we've performed a different action.
    expect(store.getState().ui.redoStack).to.deep.equal(['pageData']);
    expect(store.getState().ui.undoStack).to.deep.equal([
      'pageData',
      'layoutModel',
      'layoutModel',
    ]);
  });
});
