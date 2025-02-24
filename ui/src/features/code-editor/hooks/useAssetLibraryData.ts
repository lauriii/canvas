import { useEffect, useRef, useState } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import useXbParams from '@/hooks/useXbParams';
import {
  useGetAssetLibraryQuery,
  useGetAutoSaveQuery,
  useUpdateAutoSaveMutation,
} from '@/services/assetLibrary';
import {
  selectIsGlobalCssEditorReady,
  selectSourceCodeGlobalCss,
  setIsGlobalCssEditorReady,
  setSourceCodeGlobalCss,
} from '@/features/code-editor/codeEditorSlice';

const ASSET_LIBRARY_ID = 'global';

const useCodeComponentData = () => {
  const { showBoundary } = useErrorBoundary();
  const { codeComponentId } = useXbParams();
  const dispatch = useAppDispatch();
  const [updateAutoSave] = useUpdateAutoSaveMutation();
  const lastUpdateTimeoutRef = useRef<NodeJS.Timeout | null>(null);
  const isGlobalCssEditorReady = useAppSelector(selectIsGlobalCssEditorReady);
  const sourceCodeGlobalCss = useAppSelector(selectSourceCodeGlobalCss);
  const [shouldLoad, setShouldLoad] = useState(false);

  // Load data only if the currently selected code component changes.
  useEffect(() => {
    if (codeComponentId) {
      setShouldLoad(true);
    }
  }, [codeComponentId]);

  // Get the auto-saved data of the asset library if it exists.
  const {
    currentData: dataGetAutoSave,
    error: errorGetAutoSave,
    isFetching: isLoadingGetAutoSave,
    isSuccess: isSuccessGetAutoSave,
  } = useGetAutoSaveQuery(ASSET_LIBRARY_ID, {
    skip: !shouldLoad,
  });

  // Get the asset library, but skip if auto-saved data exists.
  const {
    currentData: dataGetAssetLibrary,
    error: errorGetAssetLibrary,
    isFetching: isLoadingGetAssetLibrary,
  } = useGetAssetLibraryQuery(ASSET_LIBRARY_ID, {
    skip:
      !shouldLoad ||
      isLoadingGetAutoSave ||
      (isSuccessGetAutoSave && !!dataGetAutoSave),
  });

  useEffect(() => {
    if (errorGetAssetLibrary || errorGetAutoSave) {
      showBoundary(errorGetAssetLibrary || errorGetAutoSave);
    }
  }, [errorGetAssetLibrary, errorGetAutoSave, showBoundary]);

  const isLoading = isLoadingGetAssetLibrary || isLoadingGetAutoSave;
  const data = dataGetAutoSave || dataGetAssetLibrary;

  // Initialize the code editor with the data.
  useEffect(() => {
    if (!isLoading && data) {
      dispatch(setSourceCodeGlobalCss(data.css.original));
    }
  }, [isLoading, data, dispatch]);

  // Update the auto-saved version of the code component on every relevant change.
  // Debounce the updates to one second.
  useEffect(
    () => {
      if (lastUpdateTimeoutRef.current) {
        clearTimeout(lastUpdateTimeoutRef.current);
      }
      lastUpdateTimeoutRef.current = setTimeout(() => {
        if (isGlobalCssEditorReady) {
          updateAutoSave({
            id: ASSET_LIBRARY_ID,
            data: {
              css: {
                original: sourceCodeGlobalCss,
                compiled: '',
              },
            },
          });
        } else {
          dispatch(setIsGlobalCssEditorReady(true));
          setShouldLoad(false);
        }
      }, 1000);

      return () => {
        if (lastUpdateTimeoutRef.current) {
          clearTimeout(lastUpdateTimeoutRef.current);
        }
      };
    },
    // Only update the auto-save when relevant parts of the code editor change.
    // Intentionally not including in the dependencies:
    //  - isGlobalCssEditorReady: it would trigger the hook again when its value is updated to true
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [sourceCodeGlobalCss],
  );

  return { isLoading };
};

export default useCodeComponentData;
