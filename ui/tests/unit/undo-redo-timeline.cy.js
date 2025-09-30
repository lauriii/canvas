import { makeStore } from '@/app/store';
import {
  deleteNode,
  initialState,
  insertNodes,
  selectLayoutHistory,
  setLayoutModel,
} from '@/features/layout/layoutModelSlice';
import {
  selectPageDataHistory,
  setPageData,
} from '@/features/pageData/pageDataSlice';
import {
  selectRedoItem,
  selectUndoItem,
  initialState as uiInitialState,
  UndoRedoActionCreators,
} from '@/features/ui/uiSlice';

let pageData = {
  title: [{ value: 'Some title' }],
};
let layout = {
  updatePreview: false,
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
    // Mock different routes for testing.
    const routeA = {
      pathname: '/editor/canvas_page/1',
      search: '?test=alpha',
      hash: '#test-bravo',
    };
    const routeB = {
      pathname: '/code-editor/component/hero',
      search: '?test=charlie',
      hash: '#test-delta',
    };
    const routeC = {
      pathname: '/template/node/page/full',
      search: '?test=echo',
      hash: '#test-foxtrot',
    };

    // No need to mock window.location since middleware uses Redux state

    const store = makeStore({
      pageData: { present: pageData, past: [{}], future: [] },
      layoutModel: { present: layout, past: [initialState], future: [] },
      ui: {
        ...uiInitialState,
        currentRoute: routeA, // Start on route A
      },
    });
    let pageState = selectPageDataHistory(store.getState());
    let layoutState = selectLayoutHistory(store.getState());
    expect(pageState.present).to.deep.equal(pageData);
    expect(pageState.past).to.have.lengthOf(1);
    expect(pageState.future).to.have.lengthOf(0);
    expect(layoutState.present).to.deep.equal({
      ...layout,
      updatePreview: false,
    });
    expect(layoutState.past).to.have.lengthOf(1);
    expect(layoutState.future).to.have.lengthOf(0);
    expect(store.getState().ui.redoStack).to.deep.equal([]);
    expect(store.getState().ui.undoStack).to.deep.equal([]);

    // Perform some actions.
    // 1) Update page title (on route A)
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
    expect(store.getState().ui.undoStack).to.deep.equal([
      {
        targetSlice: 'pageData',
        routeSnapshot: routeA, // First action was on route A
      },
    ]);

    // Verify the route snapshot was captured correctly
    const undoItem1 = selectUndoItem(store.getState());
    expect(undoItem1).to.deep.equal({
      targetSlice: 'pageData',
      routeSnapshot: routeA,
    });

    // 2) Change layout model (simulate navigation to route B)
    store.dispatch({ type: 'ui/setCurrentRoute', payload: routeB });

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
      {
        targetSlice: 'pageData',
        routeSnapshot: routeA, // First action was on route A
      },
      {
        targetSlice: 'layoutModel',
        routeSnapshot: routeB, // Second action was on route B
      },
    ]);

    // Verify the route snapshots were captured correctly
    const undoItem2 = selectUndoItem(store.getState());
    expect(undoItem2).to.deep.equal({
      targetSlice: 'layoutModel',
      routeSnapshot: routeB,
    });

    // 3) Change page title (simulate navigation to route C)
    store.dispatch({ type: 'ui/setCurrentRoute', payload: routeC });

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
      {
        targetSlice: 'pageData',
        routeSnapshot: routeA, // First action was on route A
      },
      {
        targetSlice: 'layoutModel',
        routeSnapshot: routeB, // Second action was on route B
      },
      {
        targetSlice: 'pageData',
        routeSnapshot: routeC, // Third action was on route C
      },
    ]);

    // Verify the route snapshots were captured correctly
    const undoItem3 = selectUndoItem(store.getState());
    expect(undoItem3).to.deep.equal({
      targetSlice: 'pageData',
      routeSnapshot: routeC,
    });

    // 4) Undo page title change (3) - should restore to route C
    let undoItem = selectUndoItem(store.getState());
    store.dispatch(UndoRedoActionCreators.undo(undoItem?.targetSlice));
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
    expect(store.getState().ui.redoStack).to.deep.equal([
      {
        targetSlice: 'pageData',
        routeSnapshot: routeC,
      },
    ]);
    expect(store.getState().ui.undoStack).to.deep.equal([
      {
        targetSlice: 'pageData',
        routeSnapshot: routeA, // First action was on route A
      },
      {
        targetSlice: 'layoutModel',
        routeSnapshot: routeB, // Second action was on route B
      },
    ]);

    // Verify current route was restored to route C after undo
    expect(store.getState().ui.currentRoute).to.deep.equal(routeC);

    // 5) Undo layout model change (2) - should restore to route B
    undoItem = selectUndoItem(store.getState());
    expect(undoItem).to.deep.equal({
      targetSlice: 'layoutModel',
      routeSnapshot: routeB,
    });
    store.dispatch(UndoRedoActionCreators.undo(undoItem?.targetSlice));
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
      {
        targetSlice: 'layoutModel',
        routeSnapshot: routeB, // Redo should restore to route B
      },
      {
        targetSlice: 'pageData',
        routeSnapshot: routeC, // Redo should restore to route C
      },
    ]);
    expect(store.getState().ui.undoStack).to.deep.equal([
      {
        targetSlice: 'pageData',
        routeSnapshot: routeA, // First action was on route A
      },
    ]);

    // Verify current route was restored to route B after undo
    expect(store.getState().ui.currentRoute).to.deep.equal(routeB);

    // 6) Redo layout model change (2) - should restore to route C
    let redoItem = selectRedoItem(store.getState());
    expect(redoItem).to.deep.equal({
      targetSlice: 'layoutModel',
      routeSnapshot: routeB,
    });
    store.dispatch(UndoRedoActionCreators.redo(redoItem?.targetSlice));
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
    expect(store.getState().ui.redoStack).to.deep.equal([
      {
        targetSlice: 'pageData',
        routeSnapshot: routeC, // Redo should restore to route C
      },
    ]);
    expect(store.getState().ui.undoStack).to.deep.equal([
      {
        targetSlice: 'pageData',
        routeSnapshot: routeA, // First action was on route A
      },
      {
        targetSlice: 'layoutModel',
        routeSnapshot: routeB, // Second action was on route B
      },
    ]);

    // Verify current route was restored to route B after redo
    expect(store.getState().ui.currentRoute).to.deep.equal(routeB);

    // 7) Redo page title change (3) - should restore to route C
    redoItem = selectRedoItem(store.getState());
    expect(redoItem).to.deep.equal({
      targetSlice: 'pageData',
      routeSnapshot: routeC,
    });
    store.dispatch(UndoRedoActionCreators.redo(redoItem?.targetSlice));
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
      {
        targetSlice: 'pageData',
        routeSnapshot: routeA, // First action was on route A
      },
      {
        targetSlice: 'layoutModel',
        routeSnapshot: routeB, // Second action was on route B
      },
      {
        targetSlice: 'pageData',
        routeSnapshot: routeC, // Third action was on route C
      },
    ]);

    // Verify current route was restored to route C after redo
    expect(store.getState().ui.currentRoute).to.deep.equal(routeC);

    // 8) Undo page title change (7)
    undoItem = selectUndoItem(store.getState());
    expect(undoItem).to.deep.equal({
      targetSlice: 'pageData',
      routeSnapshot: routeC,
    });
    store.dispatch(UndoRedoActionCreators.undo(undoItem?.targetSlice));
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
    expect(store.getState().ui.redoStack).to.deep.equal([
      {
        targetSlice: 'pageData',
        routeSnapshot: routeC, // Redo should restore to route C
      },
    ]);
    expect(store.getState().ui.undoStack).to.deep.equal([
      {
        targetSlice: 'pageData',
        routeSnapshot: routeA, // First action was on route A
      },
      {
        targetSlice: 'layoutModel',
        routeSnapshot: routeB, // Second action was on route B
      },
    ]);

    // 9) Make a layout model change (still on route C)
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
      {
        targetSlice: 'pageData',
        routeSnapshot: routeA, // First action was on route A
      },
      {
        targetSlice: 'layoutModel',
        routeSnapshot: routeB, // Second action was on route B
      },
      {
        targetSlice: 'layoutModel',
        routeSnapshot: routeC, // Third action was on route C
      },
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
      {
        targetSlice: 'pageData',
        routeSnapshot: routeA, // First action was on route A
      },
      {
        targetSlice: 'layoutModel',
        routeSnapshot: routeB, // Second action was on route B
      },
      {
        targetSlice: 'layoutModel',
        routeSnapshot: routeC, // Third action was on route C
      },
      {
        targetSlice: 'pageData',
        routeSnapshot: routeC, // Fourth action was on route C
      },
    ]);

    // 11) Undo page title change (10)
    undoItem = selectUndoItem(store.getState());
    expect(undoItem).to.deep.equal({
      targetSlice: 'pageData',
      routeSnapshot: routeC,
    });
    store.dispatch(UndoRedoActionCreators.undo(undoItem?.targetSlice));
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
    expect(store.getState().ui.redoStack).to.deep.equal([
      {
        targetSlice: 'pageData',
        routeSnapshot: routeC,
      },
    ]);
    expect(store.getState().ui.undoStack).to.deep.equal([
      {
        targetSlice: 'pageData',
        routeSnapshot: routeA,
      },
      {
        targetSlice: 'layoutModel',
        routeSnapshot: routeB,
      },
      {
        targetSlice: 'layoutModel',
        routeSnapshot: routeC,
      },
    ]);

    // 12) Undo layout model change (9)
    undoItem = selectUndoItem(store.getState());
    expect(undoItem?.targetSlice).to.eq('layoutModel');
    store.dispatch(UndoRedoActionCreators.undo(undoItem?.targetSlice));
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
    expect(store.getState().ui.redoStack).to.deep.equal([
      {
        targetSlice: 'layoutModel',
        routeSnapshot: routeC,
      },
      {
        targetSlice: 'pageData',
        routeSnapshot: routeC,
      },
    ]);
    expect(store.getState().ui.undoStack).to.deep.equal([
      {
        targetSlice: 'pageData',
        routeSnapshot: routeA,
      },
      {
        targetSlice: 'layoutModel',
        routeSnapshot: routeB,
      },
    ]);

    // 13) Redo layout model change (9)
    redoItem = selectRedoItem(store.getState());
    expect(redoItem?.targetSlice).to.eq('layoutModel');
    store.dispatch(UndoRedoActionCreators.redo(redoItem?.targetSlice));
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
    expect(store.getState().ui.redoStack).to.deep.equal([
      {
        targetSlice: 'pageData',
        routeSnapshot: routeC,
      },
    ]);
    expect(store.getState().ui.undoStack).to.deep.equal([
      {
        targetSlice: 'pageData',
        routeSnapshot: routeA,
      },
      {
        targetSlice: 'layoutModel',
        routeSnapshot: routeB,
      },
      {
        targetSlice: 'layoutModel',
        routeSnapshot: routeC,
      },
    ]);

    // Simulate a patch update.
    store.dispatch(
      setLayoutModel({
        ...layoutState.present,
        updatePreview: false,
      }),
    );
    layoutState = selectLayoutHistory(store.getState());
    // updatePreview should be false.
    expect(layoutState.present.updatePreview).to.eq(false);

    // And another one.
    // Simulate a patch update.
    store.dispatch(
      setLayoutModel({
        ...layoutState.present,
        updatePreview: false,
      }),
    );
    layoutState = selectLayoutHistory(store.getState());
    // updatePreview should be false.
    expect(layoutState.present.updatePreview).to.eq(false);

    // Now undo the second one.
    undoItem = selectUndoItem(store.getState());
    expect(undoItem?.targetSlice).to.eq('layoutModel');
    store.dispatch(UndoRedoActionCreators.undo(undoItem?.targetSlice));
    layoutState = selectLayoutHistory(store.getState());
    // There should be a future state.
    expect(layoutState.future.length).to.eq(1);
    // updatePreview should be false.
    expect(layoutState.present.updatePreview).to.eq(true);
    // But so should the future state.
    expect(
      layoutState.future.reduce(
        (carry, item) => carry && item.updatePreview,
        true,
      ),
    ).to.eq(true);

    // And a subsequent update.
    store.dispatch(deleteNode('static-static-card1ab'));
    layoutState = selectLayoutHistory(store.getState());
    // updatePreview should be true as the preview would have been updated.
    expect(layoutState.present.updatePreview).to.eq(true);

    // And the setLayoutModel in the past entry should also be true, even though
    // we passed 'false' when dispatching setLayoutModel.
    expect(
      layoutState.past.reduce(
        (carry, item) => carry && item.updatePreview,
        true,
      ),
    ).to.eq(true);

    // Then undo the delete operation.
    undoItem = selectUndoItem(store.getState());
    expect(undoItem?.targetSlice).to.eq('layoutModel');
    store.dispatch(UndoRedoActionCreators.undo(undoItem?.targetSlice));

    // Even though the previous entry from setLayoutModel had updatePreview: false
    // the undo entry we restored from past should have updatePreview true, to
    // trigger a refetch of the preview.
    layoutState = selectLayoutHistory(store.getState());
    expect(layoutState.present.updatePreview).to.eq(true);

    // Now let's undo the setLayoutModel.
    undoItem = selectUndoItem(store.getState());
    expect(undoItem).to.deep.equal({
      targetSlice: 'layoutModel',
      routeSnapshot: routeC,
    });
    store.dispatch(UndoRedoActionCreators.undo(undoItem?.targetSlice));

    // The next redo should be to set the layout.
    redoItem = selectRedoItem(store.getState());
    expect(redoItem).to.deep.equal({
      targetSlice: 'layoutModel',
      routeSnapshot: routeC,
    });
    layoutState = selectLayoutHistory(store.getState());
    // We can redo both the setLayoutModel and the delete operation.
    expect(layoutState.future).to.have.lengthOf(2);
    // The next redo will be to set the model again, this should include the
    // static card.
    expect(
      Object.keys(layoutState.future[0].model).includes(
        'static-static-card1ab',
      ),
    ).to.eq(true);
    // The final redo will be to delete the node, this should not include the
    // static card.
    expect(
      Object.keys(layoutState.future[1].model).includes(
        'static-static-card1ab',
      ),
    ).to.eq(false);
    // A redo of a setLayoutModel should include re-fetching a preview.
    expect(layoutState.future[0].updatePreview).to.eq(true);
    // So should the delete node action.
    expect(layoutState.future[1].updatePreview).to.eq(true);
    // And the undo of the deleteNode 'insertNode' should also have triggered a
    // re-fetching of the preview so updatePreview should be true.
    expect(layoutState.present.updatePreview).to.eq(true);
  });
});
