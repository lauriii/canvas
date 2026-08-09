import { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Dialog from '@/components/Dialog';
import {
  closeAllDialogs,
  selectDialogStates,
  selectSelectedCodeComponent,
} from '@/features/ui/codeComponentDialogSlice';
import { selectPreviouslyEdited } from '@/features/ui/uiSlice';
import { useUpdateCodeComponentMutation } from '@/services/componentAndLayout';

import type { CodeComponentSerialized } from '@/types/CodeComponent';

// This handles the dialog for adding a JS component to components. This changes
// the component from being "internal" to "exposed".
const AddToComponentsDialog = () => {
  const navigate = useNavigate();
  const selectedComponent = useAppSelector(selectSelectedCodeComponent);
  const [updateCodeComponent, { isLoading, isSuccess, isError, error, reset }] =
    useUpdateCodeComponentMutation();
  const dispatch = useAppDispatch();
  const { isAddToComponentsDialogOpen } = useAppSelector(selectDialogStates);
  const previouslyEdited = useAppSelector(selectPreviouslyEdited);

  const handleSave = async () => {
    if (!selectedComponent) return;

    await updateCodeComponent({
      id: (selectedComponent as CodeComponentSerialized).machineName,
      changes: {
        // @todo: Remove "...selectedComponent" and only send wanted changes in the PATCH request in
        //   https://drupal.org/i/3524274.
        ...selectedComponent,
        // Mark this code component as "exposed", to make it available to content creators.
        // @see docs/config-management.md, section 3.2.1
        // @see \Drupal\canvas\EntityHandlers\JavascriptComponentStorage::createOrUpdateComponentEntity()
        status: true,
      },
      // Indicate this update includes exposing the component, so the query
      // knows to perform additional invalidation.
      isExposing: true,
    });
  };

  const handleOpenChange = (open: boolean) => {
    if (!open) {
      reset();
      dispatch(closeAllDialogs());
    }
  };

  useEffect(() => {
    if (isSuccess) {
      dispatch(closeAllDialogs());
      if (!previouslyEdited.path) {
        navigate('/editor');
      } else {
        navigate(previouslyEdited.path);
      }
    }
  }, [isSuccess, dispatch, navigate, previouslyEdited.path]);

  useEffect(() => {
    if (isError) {
      console.error('Failed to add to components:', error);
    }
  }, [isError, error]);

  // Kept as two sentences so the second one stays a short, reusable string and
  // the HTTP status reaches translators as a placeholder rather than as a
  // fragment they cannot reorder.
  const errorMessage = [
    Drupal.t('An error !status occurred while adding to components.', {
      '!status':
        error && 'status' in error ? '(HTTP ' + error.status + ')' : '',
    }),
    Drupal.t('Please check the browser console for more details.'),
  ].join(' ');

  return (
    <Dialog
      open={isAddToComponentsDialogOpen}
      onOpenChange={handleOpenChange}
      title={Drupal.t('Add to components')}
      description={
        <>
          {Drupal.t('This component will be moved to the')}{' '}
          <b>{Drupal.t('Components')}</b>{' '}
          {Drupal.t('section and will be available to use on the page.')}
          <br />
          <br />
          {Drupal.t('You can remove it from')} <b>{Drupal.t('Components')}</b>{' '}
          {Drupal.t('at any time.')}
        </>
      }
      error={
        isError
          ? {
              title: Drupal.t('Failed to add to components'),
              message: errorMessage,
              resetButtonText: Drupal.t('Try again'),
              onReset: handleSave,
            }
          : undefined
      }
      footer={{
        cancelText: Drupal.t('Cancel'),
        confirmText: Drupal.t('Add'),
        onConfirm: handleSave,
        isConfirmDisabled: false,
        isConfirmLoading: isLoading,
      }}
    />
  );
};

export default AddToComponentsDialog;
