import type { Action, Middleware, ThunkAction } from '@reduxjs/toolkit';
import { combineSlices, configureStore } from '@reduxjs/toolkit';
import { setupListeners } from '@reduxjs/toolkit/query';
import { v4 as uuidv4 } from 'uuid';
import type { UndoRedoType } from '@/features/ui/uiSlice';
import {
  performUndoOrRedo,
  pushUndo,
  setLatestUndoRedoActionId,
  uiSlice,
} from '@/features/ui/uiSlice';
import { primaryPanelSlice } from '@/features/ui/primaryPanelSlice';
import { dialogSlice } from '@/features/ui/dialogSlice';
import { codeComponentDialogSlice } from '@/features/ui/codeComponentDialogSlice';
import { componentApi } from '@/services/components';
import { layoutApi } from '@/services/layout';
import { previewApi } from '@/services/preview';
import { contentCreateApi } from '@/services/contentCreate';
import undoable from 'redux-undo';
import { layoutModelReducer } from '@/features/layout/layoutModelSlice';
import { pageDataReducer } from '@/features/pageData/pageDataSlice';
import { dummyPropsFormApi } from '@/services/dummyPropsForm';
import { pageDataFormApi } from '@/services/pageDataForm';
import { configurationSlice } from '@/features/configuration/configurationSlice';
import { sectionApi } from '@/services/sections';
import { extensionsSlice } from '@/features/extensions/extensionsSlice';
import { extensionsApi } from '@/services/extensions';
import { codeComponentApi } from '@/services/codeComponents';
import { formStateSlice } from '@/features/form/formStateSlice';
import type { UnknownAction } from 'redux';
import { pendingChangesApi } from '@/services/pendingChangesApi';
import { publishReviewSlice } from '@/components/review/PublishReview.slice';
import codeEditorSlice from '@/features/code-editor/codeEditorSlice';
import { previewSlice } from '@/features/pagePreview/previewSlice';
import { contentApi } from '@/services/content';

// Reducer enhancer to decorate undoable aware reducers and unset future state
// if an action is performed on another undoable slice.
// @see https://redux.js.org/usage/implementing-undo-history#meet-reducer-enhancers
// @see https://en.wikipedia.org/wiki/History_Eraser
const historyEraser = (reducer: any, thisType: UndoRedoType) => {
  return (state: any, action: UnknownAction, ...slices: any[]) => {
    // Pass through to the inner (undoable) reducer.
    let newState = reducer(state, action, ...slices);
    const type = action.type;
    if (
      type === 'ui/pushUndo' &&
      action.payload !== undefined &&
      action.payload !== thisType &&
      newState.future.length > 0
    ) {
      // Discard the future (redo) states for this slice as we've moved into a
      // future state for another slice.
      // E.g. If this reducer is applied to the 'pageData' slice, but we've
      // pushed 'layoutModel' to the undo stack, then any future (redo) state
      // for this slice is no longer valid.
      // Without this historyEraser, slices would retain their future state when
      // they are not needed or reachable.
      return { ...newState, future: [] };
    }
    return newState;
  };
};

// `combineSlices` automatically combines the reducers using
// their `reducerPath`s, therefore we no longer need to call `combineReducers`.
const rootReducer = combineSlices(
  {
    layoutModel: historyEraser(
      undoable(layoutModelReducer, {
        undoType: '@@redux-undo/layoutModel_UNDO',
        redoType: '@@redux-undo/layoutModel_REDO',
        filter: (action, currentState, previousHistory) => {
          const { present } = previousHistory;
          return Object.keys(present.model).length > 0;
        },
      }),
      'layoutModel',
    ),
    pageData: historyEraser(
      undoable(pageDataReducer, {
        undoType: '@@redux-undo/pageData_UNDO',
        redoType: '@@redux-undo/pageData_REDO',
        filter: (action, currentState, previousHistory) => {
          const { present } = previousHistory;
          return Object.keys(present).length > 0;
        },
      }),
      'pageData',
    ),
  },
  componentApi,
  sectionApi,
  extensionsApi,
  codeComponentApi,
  layoutApi,
  previewApi,
  contentCreateApi,
  dummyPropsFormApi,
  pageDataFormApi,
  configurationSlice,
  primaryPanelSlice,
  dialogSlice,
  codeComponentDialogSlice,
  uiSlice,
  formStateSlice,
  extensionsSlice,
  pendingChangesApi,
  publishReviewSlice,
  contentApi,
  codeEditorSlice,
  previewSlice,
);
// Infer the `RootState` type from the root reducer
export type RootState = ReturnType<typeof rootReducer>;

// Middleware to add unique ID to undo/redo actions and store it.
const undoRedoActionIdMiddleware: Middleware<{}, RootState> =
  (store) => (next) => (action) => {
    const type = (action as Action).type;
    // If the action being performed is an UNDO or REDO action we need to move
    // items between the undo and redo stacks.
    const matches = type.match(/@@redux-undo\/[^_]+_(UNDO|REDO)/);
    if (matches && matches.length === 2) {
      const id = uuidv4();
      const [, undoOrRedo] = matches;
      store.dispatch(performUndoOrRedo(undoOrRedo === 'UNDO'));
      store.dispatch(setLatestUndoRedoActionId(id));
      return next({
        ...(action as Action),
        meta: {
          id,
        },
      });
    }
    const [slice] = type.split('/');
    if (slice === 'layoutModel' || slice === 'pageData') {
      store.dispatch(pushUndo(slice as UndoRedoType));
    }
    return next(action);
  };

// The store setup is wrapped in `makeStore` to allow reuse
// when setting up tests that need the same store config
export const makeStore = (preloadedState?: Partial<RootState>) => {
  const store = configureStore({
    reducer: rootReducer,
    // Adding the api middleware enables caching, invalidation, polling,
    // and other useful features of `rtk-query`.
    middleware: (getDefaultMiddleware) => {
      return getDefaultMiddleware().concat(
        componentApi.middleware,
        sectionApi.middleware,
        extensionsApi.middleware,
        codeComponentApi.middleware,
        layoutApi.middleware,
        previewApi.middleware,
        contentCreateApi.middleware,
        dummyPropsFormApi.middleware,
        pageDataFormApi.middleware,
        undoRedoActionIdMiddleware,
        pendingChangesApi.middleware,
        contentApi.middleware,
      );
    },
    preloadedState,
  });
  // configure listeners using the provided defaults
  // optional, but required for `refetchOnFocus`/`refetchOnReconnect` behaviors
  setupListeners(store.dispatch);
  return store;
};

// Infer the type of `store`
export type AppStore = ReturnType<typeof makeStore>;
// Infer the `AppDispatch` type from the store itself
export type AppDispatch = AppStore['dispatch'];
export type AppThunk<ThunkReturnType = void> = ThunkAction<
  ThunkReturnType,
  RootState,
  unknown,
  Action
>;
