import { useEffect, useMemo } from 'react';

import { useAppDispatch } from '@/app/hooks';
import { BRAND_KIT_ID } from '@/features/brandKit/constants';
import { setBrandKitColors } from '@/features/code-editor/codeEditorSlice';
import { useGetAutoSaveQuery, useGetBrandKitQuery } from '@/services/brandKit';
import { getOptionalQueryErrorMessage } from '@/utils/error-handling';

import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';

export const useBrandKitColors = () => {
  const dispatch = useAppDispatch();

  const {
    data: canonicalBrandKit,
    isFetching: isFetchingBrandKit,
    error: brandKitError,
  } = useGetBrandKitQuery(BRAND_KIT_ID);
  const {
    currentData: autoSaveBrandKit,
    isFetching: isFetchingAutoSave,
    error: autoSaveError,
  } = useGetAutoSaveQuery(BRAND_KIT_ID);

  // Colors come from the canonical entry alone. They are separate
  // `canvas.color.*` entities rather than part of the Brand kit's own config,
  // so the auto-save draft derives them from those same entities on every read:
  // it can only repeat the canonical list, or lag it by a response. Preferring
  // the draft would buy nothing and would race an in-flight optimistic write,
  // whose patch this response would replace with the pre-edit color.
  //
  // `data` (not `currentData`) is used so that optimistic cache patches remain
  // visible during the refetch that invalidation triggers; `currentData` freezes
  // at the last confirmed server value while a request is in-flight, reverting
  // the swatch to its pre-edit color.
  const colors = useMemo(
    () => canonicalBrandKit?.colors ?? [],
    [canonicalBrandKit?.colors],
  );

  useEffect(() => {
    dispatch(setBrandKitColors([colors, { needsAutoSave: false }]));
  }, [dispatch, colors]);

  const isLoading =
    !canonicalBrandKit &&
    !autoSaveBrandKit &&
    (isFetchingBrandKit || isFetchingAutoSave);

  const errorMessage =
    getOptionalQueryErrorMessage(
      brandKitError as FetchBaseQueryError | undefined,
    ) ??
    getOptionalQueryErrorMessage(
      autoSaveError as FetchBaseQueryError | undefined,
    );

  return {
    colors,
    errorMessage,
    isLoading,
  };
};
