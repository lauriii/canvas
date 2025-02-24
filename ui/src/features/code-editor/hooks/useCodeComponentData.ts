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
  selectCompiledCss,
  selectCompiledJs,
  selectHasCompletedFirstCompilation,
  selectIsEditorReady,
  selectName,
  selectProps,
  selectRequired,
  selectSlots,
  selectSourceCodeCss,
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
  const isEditorReady = useAppSelector(selectIsEditorReady);
  const isInitiallyCompiled = useAppSelector(
    selectHasCompletedFirstCompilation,
  );
  const status = useAppSelector(selectStatus);
  const name = useAppSelector(selectName);
  const sourceCodeJs = useAppSelector(selectSourceCodeJs);
  const compiledJs = useAppSelector(selectCompiledJs);
  const sourceCodeCss = useAppSelector(selectSourceCodeCss);
  const compiledCss = useAppSelector(selectCompiledCss);
  const props = useAppSelector(selectProps);
  const slots = useAppSelector(selectSlots);
  const required = useAppSelector(selectRequired);
  const [shouldLoad, setShouldLoad] = useState(false);

  // Load data only if the currently selected code component changes.
  useEffect(() => {
    if (componentId) {
      setShouldLoad(true);
    }
  }, [componentId]);

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
      dispatch(
        initializeCodeEditor({
          id: componentId,
          status: data.status,
          name: data.name,
          sourceCodeJs: data.source_code_js || '',
          sourceCodeCss: data.source_code_css || '',
          props: deserializeProps(data.props),
          slots: deserializeSlots(data.slots),
          required: data.required || [],
        }),
      );
    }
  }, [isLoading, data, dispatch, componentId]);

  // Reset the code editor when the component is unmounted.
  useEffect(() => {
    return () => {
      dispatch(resetCodeEditor());
    };
  }, [dispatch]);

  // Update the auto-saved version of the code component on every relevant change.
  // Debounce the updates to one second.
  useEffect(
    () => {
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
    //  - isEditorReady: it would trigger the hook again when its value is updated to true
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [
      compiledCss,
      compiledJs,
      componentId,
      name,
      props,
      required,
      slots,
      sourceCodeCss,
      sourceCodeJs,
      status,
    ],
  );

  return { isLoading };
};

export default useCodeComponentData;
