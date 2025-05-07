/**
 * @file
 *
 * Auto-saves the code component and global asset library.
 */

import { useEffect, useRef } from 'react';
import { useAppSelector } from '@/app/hooks';
import {
  selectCodeComponentProperty,
  selectGlobalAssetLibraryProperty,
  selectStatus,
} from '@/features/code-editor/codeEditorSlice';
import { useUpdateAutoSaveMutation as updateCodeComponentMutation } from '@/services/componentAndLayout';
import { useUpdateAutoSaveMutation as updateGlobalAssetLibraryMutation } from '@/services/assetLibrary';
import { serializeProps, serializeSlots } from '@/features/code-editor/utils';
import { setStatus } from '@/features/code-editor/codeEditorSlice';
import { useAppDispatch } from '@/app/hooks';

const useAutoSave = (requestedComponentId: string): void => {
  const dispatch = useAppDispatch();

  const [updateCodeComponent, { isLoading: isUpdatingCodeComponent }] =
    updateCodeComponentMutation();
  const [
    updateGlobalAssetLibrary,
    { isLoading: isUpdatingGlobalAssetLibrary },
  ] = updateGlobalAssetLibraryMutation();

  useEffect(() => {
    dispatch(
      setStatus({
        isSaving: isUpdatingCodeComponent || isUpdatingGlobalAssetLibrary,
      }),
    );
  }, [isUpdatingCodeComponent, isUpdatingGlobalAssetLibrary, dispatch]);

  const componentId = useAppSelector(
    selectCodeComponentProperty('machineName'),
  );
  const sourceCodeJs = useAppSelector(
    selectCodeComponentProperty('source_code_js'),
  );
  const compiledJs = useAppSelector(selectCodeComponentProperty('compiled_js'));
  const sourceCodeCss = useAppSelector(
    selectCodeComponentProperty('source_code_css'),
  );
  const compiledCss = useAppSelector(
    selectCodeComponentProperty('compiled_css'),
  );
  const props = useAppSelector(selectCodeComponentProperty('props'));
  const slots = useAppSelector(selectCodeComponentProperty('slots'));
  const required = useAppSelector(selectCodeComponentProperty('required'));

  const globalSourceCodeCss = useAppSelector(
    selectGlobalAssetLibraryProperty(['css', 'original']),
  );
  const globalCompiledCss = useAppSelector(
    selectGlobalAssetLibraryProperty(['css', 'compiled']),
  );
  const globalSourceCodeJs = useAppSelector(
    selectGlobalAssetLibraryProperty(['js', 'original']),
  );
  const globalCompiledJs = useAppSelector(
    selectGlobalAssetLibraryProperty(['js', 'compiled']),
  );

  // Track the values in refs which we need in the effect that auto-saves the
  // code component, but which we don't want to trigger the auto-save.
  const { needsAutoSave } = useAppSelector(selectStatus);
  const needsAutoSaveRef = useRef(false);
  useEffect(() => {
    needsAutoSaveRef.current = needsAutoSave;
  }, [needsAutoSave]);
  const componentStatus = useAppSelector(selectCodeComponentProperty('status'));
  const componentStatusRef = useRef(false);
  useEffect(() => {
    componentStatusRef.current = componentStatus;
  }, [componentStatus]);
  const componentName = useAppSelector(selectCodeComponentProperty('name'));
  const componentNameRef = useRef<string>('');
  useEffect(() => {
    componentNameRef.current = componentName;
  }, [componentName]);
  const importedJsComponents = useAppSelector(
    selectCodeComponentProperty('imported_js_components'),
  );
  const importedJsComponentsRef = useRef<string[]>([]);
  useEffect(() => {
    importedJsComponentsRef.current = importedJsComponents;
  }, [importedJsComponents]);

  const lastInvocationCodeComponentTimeoutRef = useRef<NodeJS.Timeout | null>(
    null,
  );
  const lastInvocationGlobalCssTimeoutRef = useRef<NodeJS.Timeout | null>(null);

  // Auto-save: code component changes.
  useEffect(() => {
    if (
      requestedComponentId !== componentId ||
      !componentId ||
      !needsAutoSaveRef.current
    ) {
      return;
    }
    if (lastInvocationCodeComponentTimeoutRef.current) {
      clearTimeout(lastInvocationCodeComponentTimeoutRef.current);
    }
    lastInvocationCodeComponentTimeoutRef.current = setTimeout(() => {
      updateCodeComponent({
        id: componentId,
        data: {
          status: componentStatusRef.current,
          name: componentNameRef.current,
          machineName: componentId,
          source_code_js: sourceCodeJs,
          source_code_css: sourceCodeCss,
          compiled_js: compiledJs,
          compiled_css: compiledCss,
          props: serializeProps(props),
          slots: serializeSlots(slots),
          required,
          imported_js_components: importedJsComponentsRef.current,
        },
      });
    }, 1000);
    return () => {
      if (lastInvocationCodeComponentTimeoutRef.current) {
        clearTimeout(lastInvocationCodeComponentTimeoutRef.current);
      }
    };
  }, [
    compiledCss,
    compiledJs,
    componentId,
    props,
    requestedComponentId,
    required,
    slots,
    sourceCodeCss,
    sourceCodeJs,
    updateCodeComponent,
  ]);

  // Auto-save: global asset library changes.
  useEffect(() => {
    if (
      requestedComponentId !== componentId ||
      !componentId ||
      !needsAutoSaveRef.current
    ) {
      return;
    }
    if (lastInvocationGlobalCssTimeoutRef.current) {
      clearTimeout(lastInvocationGlobalCssTimeoutRef.current);
    }
    lastInvocationGlobalCssTimeoutRef.current = setTimeout(() => {
      updateGlobalAssetLibrary({
        id: 'global',
        data: {
          css: {
            compiled: globalCompiledCss,
            original: globalSourceCodeCss,
          },
          js: {
            original: globalSourceCodeJs,
            compiled: globalCompiledJs,
          },
        },
      });
    }, 1000);
    return () => {
      if (lastInvocationCodeComponentTimeoutRef.current) {
        clearTimeout(lastInvocationCodeComponentTimeoutRef.current);
      }
    };
  }, [
    componentId,
    globalCompiledCss,
    globalCompiledJs,
    globalSourceCodeCss,
    globalSourceCodeJs,
    requestedComponentId,
    updateGlobalAssetLibrary,
  ]);
};

export default useAutoSave;
