import type {
  Action,
  Middleware,
  ThunkAction,
  TypedStartListening,
} from '@reduxjs/toolkit';
import { isAnyOf } from '@reduxjs/toolkit';
import {
  combineSlices,
  configureStore,
  createListenerMiddleware,
} from '@reduxjs/toolkit';
import { setupListeners } from '@reduxjs/toolkit/query';
import { v4 as uuidv4 } from 'uuid';
import {
  selectSelectedComponent,
  setSelectedComponent,
  uiSlice,
} from '@/features/ui/uiSlice';
import { primaryPanelSlice } from '@/features/ui/primaryPanelSlice';
import { dialogSlice } from '@/features/ui/dialogSlice';
import { componentApi } from '@/services/components';
import { layoutApi } from '@/services/layout';
import { previewApi } from '@/services/preview';
import undoable, { ActionCreators as UndoActionCreators } from 'redux-undo';
import { layoutModelReducer } from '@/features/layout/layoutModelSlice';
import { dummyPropsFormApi } from '@/services/dummyPropsForm';
import { pageDataFormApi } from '@/services/pageDataForm';
import { configurationSlice } from '@/features/configuration/configurationSlice';
import { sectionApi } from '@/services/sections';
import { setLatestUndoRedoActionId } from '@/features/ui/uiSlice';
import {
  clearFieldValues,
  formStateSlice,
} from '@/features/form/formStateSlice';

// `combineSlices` automatically combines the reducers using
// their `reducerPath`s, therefore we no longer need to call `combineReducers`.
const rootReducer = combineSlices(
  {
    layoutModel: undoable(layoutModelReducer, {
      filter: (action, currentState, previousHistory) => {
        const { present } = previousHistory;
        return Object.keys(present.model).length > 0;
      },
    }),
  },
  uiSlice,
  componentApi,
  sectionApi,
  layoutApi,
  previewApi,
  dummyPropsFormApi,
  pageDataFormApi,
  configurationSlice,
  primaryPanelSlice,
  dialogSlice,
  formStateSlice,
);
// Infer the `RootState` type from the root reducer
export type RootState = ReturnType<typeof rootReducer>;

// Middleware to add unique ID to undo/redo actions and store it.
const undoRedoActionIdMiddleware: Middleware<{}, RootState> =
  (store) => (next) => (action) => {
    if (
      (action as Action).type === UndoActionCreators.undo().type ||
      (action as Action).type === UndoActionCreators.redo().type
    ) {
      const id = uuidv4();
      store.dispatch(setLatestUndoRedoActionId(id));
      return next({
        ...(action as Action),
        meta: {
          id,
        },
      });
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
      const clearFormStateComponents = createListenerMiddleware();
      const startClearFormStateListening =
        clearFormStateComponents.startListening as TypedStartListening<
          RootState,
          AppDispatch
        >;
      startClearFormStateListening({
        matcher: isAnyOf(setSelectedComponent),
        effect: (action, listenerApi) => {
          if (
            selectSelectedComponent(listenerApi.getOriginalState()) !==
            action.payload
          ) {
            listenerApi.dispatch(clearFieldValues('component_inputs_form'));
          }
        },
      });
      return getDefaultMiddleware().concat(
        componentApi.middleware,
        sectionApi.middleware,
        layoutApi.middleware,
        previewApi.middleware,
        dummyPropsFormApi.middleware,
        pageDataFormApi.middleware,
        undoRedoActionIdMiddleware,
        clearFormStateComponents.middleware,
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
