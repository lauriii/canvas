import { useEffect, useRef, useState } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import useXbParams from '@/hooks/useXbParams';
import {
  useGetCodeComponentQuery,
  useGetAutoSaveQuery,
  useUpdateAutoSaveMutation,
} from '@/services/componentAndLayout';
import {
  initializeCodeEditor,
  resetCodeEditor,
  selectBlockOverride,
  selectCompiledCss,
  selectCompiledJs,
  selectHasCompletedFirstCompilation,
  selectIsEditorReady,
  selectName,
  selectProps,
  selectRequired,
  selectSlots,
  selectSourceCodeCss,
  selectSourceCodeGlobalCss,
  selectSourceCodeJs,
  selectStatus,
  setIsEditorReady,
} from '@/features/code-editor/codeEditorSlice';
import {
  serializeProps,
  deserializeProps,
  serializeSlots,
  deserializeSlots,
} from '@/features/code-editor/utils';

const useCodeComponentData = () => {
  const { showBoundary } = useErrorBoundary();
  const { codeComponentId } = useXbParams();
  const componentId = codeComponentId as string;
  const dispatch = useAppDispatch();
  const [updateAutoSave] = useUpdateAutoSaveMutation();
  const lastUpdateTimeoutRef = useRef<NodeJS.Timeout | null>(null);
  const previousComponentIdRef = useRef<string | null>(null);
  const isEditorReady = useAppSelector(selectIsEditorReady);
  const isInitiallyCompiled = useAppSelector(
    selectHasCompletedFirstCompilation,
  );
  const status = useAppSelector(selectStatus);
  const name = useAppSelector(selectName);
  const sourceCodeJs = useAppSelector(selectSourceCodeJs);
  const compiledJs = useAppSelector(selectCompiledJs);
  const sourceCodeCss = useAppSelector(selectSourceCodeCss);
  const sourceCodeGlobalCss = useAppSelector(selectSourceCodeGlobalCss);
  const compiledCss = useAppSelector(selectCompiledCss);
  const blockOverride = useAppSelector(selectBlockOverride);
  const props = useAppSelector(selectProps);
  const slots = useAppSelector(selectSlots);
  const required = useAppSelector(selectRequired);
  const [shouldLoad, setShouldLoad] = useState(false);

  // Get the auto-saved data of the code component if it exists.
  const {
    currentData: dataGetAutoSave,
    error: errorGetAutoSave,
    isFetching: isLoadingGetAutoSave,
    isSuccess: isSuccessGetAutoSave,
  } = useGetAutoSaveQuery(componentId, {
    skip: !shouldLoad,
  });

  // Get the code component data, but skip if auto-saved data exists.
  const {
    currentData: dataGetCodeComponent,
    error: errorGetCodeComponent,
    isFetching: isLoadingGetCodeComponent,
    isSuccess: isSuccessGetCodeComponent,
  } = useGetCodeComponentQuery(componentId, {
    skip:
      !shouldLoad ||
      isLoadingGetAutoSave ||
      (isSuccessGetAutoSave && !!dataGetAutoSave),
  });

  const data = dataGetAutoSave || dataGetCodeComponent;
  const isLoading = isLoadingGetAutoSave || isLoadingGetCodeComponent;

  useEffect(() => {
    if (errorGetCodeComponent || errorGetAutoSave) {
      showBoundary(errorGetCodeComponent || errorGetAutoSave);
    }
  }, [errorGetCodeComponent, errorGetAutoSave, showBoundary]);

  // Initialize the code editor with the data.
  useEffect(() => {
    if (!isLoading && data) {
      setShouldLoad(false);
      dispatch(
        initializeCodeEditor({
          id: componentId,
          status: data.status,
          blockOverride: data.block_override,
          name: data.name,
          sourceCodeJs: data.source_code_js || '',
          sourceCodeCss: data.source_code_css || '',
          props: deserializeProps(data.props),
          slots: deserializeSlots(data.slots),
          required: data.required || [],
        }),
      );
    }
  }, [isEditorReady, isLoading, data, dispatch, componentId]);

  // Reset the code editor when the component is unmounted.
  useEffect(() => {
    return () => {
      dispatch(resetCodeEditor());
      // Clean up the ref storing the previous component ID, so when the
      // component is unmounted, we'll (re-)load the data.
      previousComponentIdRef.current = null;
    };
  }, [dispatch]);

  // Update the auto-saved version of the code component on every relevant change.
  // Debounce the updates to one second.
  useEffect(
    () => {
      // Load new data only if the currently selected code component changes.
      if (componentId && componentId !== previousComponentIdRef.current) {
        setShouldLoad(true);
        previousComponentIdRef.current = componentId;
        return;
      }

      // Prevent updates if the data is still loading, or new data is about to
      // be loaded.
      if (
        shouldLoad ||
        isLoading ||
        (!isSuccessGetAutoSave && !isSuccessGetCodeComponent)
      ) {
        return;
      }

      if (lastUpdateTimeoutRef.current) {
        clearTimeout(lastUpdateTimeoutRef.current);
      }
      lastUpdateTimeoutRef.current = setTimeout(() => {
        if (isEditorReady) {
          updateAutoSave({
            id: componentId,
            data: {
              status,
              name,
              machineName: componentId,
              source_code_js: sourceCodeJs,
              source_code_css: sourceCodeCss,
              ...(isInitiallyCompiled && {
                compiled_js: compiledJs,
                compiled_css: compiledCss,
              }),
              block_override: blockOverride,
              props: serializeProps(props),
              slots: serializeSlots(slots),
              required,
            },
          });
        } else {
          dispatch(setIsEditorReady(true));
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
    //  - isLoading: we only need to check to make sure initial data is loaded
    //  - isEditorReady: it would trigger the hook again when its value is updated to true
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [
      blockOverride,
      compiledCss,
      compiledJs,
      componentId,
      isSuccessGetCodeComponent,
      isSuccessGetAutoSave,
      name,
      props,
      required,
      shouldLoad,
      slots,
      sourceCodeCss,
      sourceCodeGlobalCss,
      sourceCodeJs,
      status,
    ],
  );

  return { isLoading };
};

export default useCodeComponentData;
