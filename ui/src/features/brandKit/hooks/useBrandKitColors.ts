import { useEffect, useMemo, useState } from 'react';

import { useAppDispatch } from '@/app/hooks';
import { BRAND_KIT_ID } from '@/features/brandKit/constants';
import { setBrandKitColors } from '@/features/code-editor/codeEditorSlice';
import { useGetAutoSaveQuery, useGetBrandKitQuery } from '@/services/brandKit';
import { getOptionalQueryErrorMessage } from '@/utils/error-handling';

import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';
import type { BrandKitColor } from '@/types/CodeComponent';

export const useBrandKitColors = () => {
  const dispatch = useAppDispatch();
  const [colors, setColors] = useState<BrandKitColor[]>([]);

  const {
    currentData: canonicalBrandKit,
    isFetching: isFetchingBrandKit,
    error: brandKitError,
  } = useGetBrandKitQuery(BRAND_KIT_ID);
  const {
    currentData: autoSaveBrandKit,
    isFetching: isFetchingAutoSave,
    error: autoSaveError,
  } = useGetAutoSaveQuery(BRAND_KIT_ID);

  const sourceColors = useMemo(() => {
    const draft = autoSaveBrandKit?.data;
    if (draft == null) {
      return canonicalBrandKit?.colors ?? [];
    }
    return draft.colors ?? canonicalBrandKit?.colors ?? [];
  }, [autoSaveBrandKit?.data, canonicalBrandKit?.colors]);

  useEffect(() => {
    setColors(sourceColors ?? []);
    dispatch(
      setBrandKitColors([sourceColors ?? null, { needsAutoSave: false }]),
    );
  }, [dispatch, sourceColors]);

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
    setColors,
  };
};
