import { useEffect, useMemo, useState } from 'react';

import { useAppDispatch } from '@/app/hooks';
import { BRAND_KIT_ID } from '@/features/brandKit/constants';
import { setUpdatePreview } from '@/features/layout/layoutModelSlice';
import {
  brandKitApi,
  useGetAutoSaveQuery,
  useGetBrandKitQuery,
  useUpdateAutoSaveMutation,
} from '@/services/brandKit';
import { stylesApi } from '@/services/styles';
import { getOptionalQueryErrorMessage } from '@/utils/error-handling';

import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';
import type { BrandKitStyles } from '@/types/Styles';

const NONE: BrandKitStyles = {};

/**
 * The brand kit's style options: the draft if there is one, else the
 * published brand kit. Saving writes a draft, then refreshes the editor's
 * styles and the preview so the change shows before publishing.
 */
export const useBrandKitStyles = () => {
  const dispatch = useAppDispatch();
  const { currentData: canonical } = useGetBrandKitQuery(BRAND_KIT_ID);
  const { currentData: autoSave } = useGetAutoSaveQuery(BRAND_KIT_ID);
  const [updateAutoSave, updateState] = useUpdateAutoSaveMutation();

  const source = useMemo(
    () => (autoSave?.data ? autoSave.data.styles : canonical?.styles) ?? NONE,
    [autoSave?.data, canonical?.styles],
  );
  const [styles, setStyles] = useState<BrandKitStyles>(source);
  useEffect(() => setStyles(source), [source]);

  const save = async (next: BrandKitStyles) => {
    setStyles(next);
    const persisted = Object.keys(next).length > 0 ? next : null;
    dispatch(
      brandKitApi.util.updateQueryData('getAutoSave', BRAND_KIT_ID, (draft) => {
        if (draft.data) {
          draft.data.styles = persisted;
        }
      }),
    );
    await updateAutoSave({
      id: BRAND_KIT_ID,
      data: { styles: persisted },
    }).unwrap();
    dispatch(stylesApi.util.invalidateTags([{ type: 'Styles', id: 'LIST' }]));
    dispatch(setUpdatePreview(true));
  };

  return {
    styles,
    save,
    isSaving: updateState.isLoading,
    errorMessage: getOptionalQueryErrorMessage(
      updateState.error as FetchBaseQueryError | undefined,
    ),
  };
};
