import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { FORM_TYPES } from '@/features/form/constants';
import { selectFormValues } from '@/features/form/formStateSlice';
import { selectPageData } from '@/features/pageData/pageDataSlice';
import { selectLatestUndoRedoActionId } from '@/features/ui/uiSlice';

/**
 * Hook for page data form inputs - provides all necessary data and utilities
 * for managing entity/page field form fields.
 *
 * @returns Object containing all page data form data and utilities
 */
export const usePageDataFormInputInfo = () => {
  const dispatch = useAppDispatch();
  const pageData = useAppSelector(selectPageData);
  const latestUndoRedoActionId = useAppSelector(selectLatestUndoRedoActionId);
  const formState = useAppSelector((state) =>
    selectFormValues(state, FORM_TYPES.ENTITY_FORM),
  );

  return {
    dispatch,
    pageData,
    latestUndoRedoActionId,
    formState,
  };
};
