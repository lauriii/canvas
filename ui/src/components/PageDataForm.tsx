import React, { useCallback, useEffect, useRef, useState } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useParams } from 'react-router';
import { Box, Spinner } from '@radix-ui/themes';
import { skipToken } from '@reduxjs/toolkit/query';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import twigToJSXComponentMap from '@/components/form/twig-to-jsx-component-map';
import { FORM_TYPES } from '@/features/form/constants';
import { selectFormValues } from '@/features/form/formStateSlice';
import { setUpdatePreview } from '@/features/layout/layoutModelSlice';
import {
  selectPageData,
  selectPageDataOwner,
  setPageData,
} from '@/features/pageData/pageDataSlice';
import { useDrupalBehaviors } from '@/hooks/useDrupalBehaviors';
import { useEditorNavigation } from '@/hooks/useEditorNavigation';
import hyperscriptify from '@/local_packages/hyperscriptify';
import propsify from '@/local_packages/hyperscriptify/propsify/standard/index.js';
import { useGetPageLayoutQuery } from '@/services/componentAndLayout';
import { useGetPageDataFormQuery } from '@/services/pageDataForm';
import {
  PAGE_VARIANT_ENTITY_TYPE,
  useGetDefaultPageVariantQuery,
} from '@/services/pageVariants';
import { AJAX_UPDATE_FORM_STATE_EVENT } from '@/types/Ajax';
import parseHyperscriptifyTemplate from '@/utils/parse-hyperscriptify-template';

import type { AjaxUpdateFormStateEvent } from '@/types/Ajax';

import styles from '@/components/PageDataForm.module.css';

const PageDataFormRenderer = () => {
  const pageData = useAppSelector(selectPageData);
  const pageDataOwner = useAppSelector(selectPageDataOwner);
  const { showBoundary } = useErrorBoundary();
  const [jsxFormContent, setJsxFormContent] =
    useState<React.ReactElement | null>(null);
  const dispatch = useAppDispatch();
  const formState = useAppSelector((state) =>
    selectFormValues(state, FORM_TYPES.ENTITY_FORM),
  );
  const { entityId, entityType } = useParams();
  const {
    currentData: formTemplate,
    error,
    isFetching,
    refetch,
  } = useGetPageDataFormQuery(
    entityId && entityType ? { entityId, entityType } : skipToken,
  );
  const { isFetching: isFetchingLayout } = useGetPageLayoutQuery(
    entityId && entityType ? { entityId, entityType } : skipToken,
  );

  const formRef = useRef<HTMLDivElement>(null);
  const { navigateToEditor } = useEditorNavigation();
  const { data: defaultVariant } = useGetDefaultPageVariantQuery();
  const defaultVariantId = defaultVariant?.default_page_variant ?? null;
  // The edit-template link is server-rendered Drupal markup, not a react
  // router <Link>; intercept it so it navigates without a full page load.
  const interceptEditTemplateLink = useCallback(
    (event: React.MouseEvent) => {
      const link = (event.target as HTMLElement).closest(
        '[data-testid="canvas-page-template-edit"]',
      );
      if (!link) {
        return;
      }
      // Edit the template currently chosen in the form — which may be an
      // unsaved, pending selection — rather than the server-rendered href,
      // which reflects the saved selection. An empty or "_none" value means
      // "site default", which edits the configured default variant.
      const select = link
        .closest('[data-testid="canvas-page-data-form"]')
        ?.querySelector<HTMLSelectElement>('select[name="page_variant"]');
      let variantId: string | undefined;
      if (select) {
        const selected = select.value;
        variantId =
          !selected || selected === '_none'
            ? (defaultVariantId ?? undefined)
            : selected;
      } else {
        variantId = link
          .getAttribute('href')
          ?.split(`/editor/${PAGE_VARIANT_ENTITY_TYPE}/`)[1];
      }
      if (variantId) {
        event.preventDefault();
        navigateToEditor(PAGE_VARIANT_ENTITY_TYPE, variantId);
      }
    },
    [navigateToEditor, defaultVariantId],
  );
  const loading = isFetching || isFetchingLayout;
  useDrupalBehaviors(formRef, jsxFormContent, loading);

  const pageDataExists = !!Object.keys(pageData).length;
  const pageDataIsCurrent =
    pageDataOwner?.entityType === entityType &&
    pageDataOwner?.entityId === entityId;

  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
  }, [error, showBoundary]);

  useEffect(() => {
    if (entityId && entityType) {
      refetch();
    }
  }, [refetch, entityId, entityType]);

  useEffect(() => {
    // If the HTML for the form has not yet loaded OR the JSON for the current
    // page data has not, don't render the form.
    // Were we pulling this data *directly* from an API, doing this would be
    // best accomplished by the isLoading property provided by RTK. This serves
    // the same purpose without adding complexity to our reducers.
    if (!formTemplate || !pageDataIsCurrent) {
      return;
    }

    const template = parseHyperscriptifyTemplate(formTemplate as string);
    if (!template) {
      return;
    }

    setJsxFormContent(
      <div
        data-testid="canvas-page-data-form"
        className={styles.pageDataForm}
        onClick={interceptEditTemplateLink}
      >
        {hyperscriptify(
          template,
          React.createElement,
          React.Fragment,
          twigToJSXComponentMap,
          { propsify },
        )}
      </div>,
    );
  }, [
    entityId,
    entityType,
    formTemplate,
    pageDataIsCurrent,
    interceptEditTemplateLink,
  ]);

  useEffect(() => {
    const ajaxUpdateFormStateListener: (
      e: AjaxUpdateFormStateEvent,
    ) => void = ({ detail }) => {
      const { updates, formId } = detail;
      // We only care about the entity form, not the component instance form.
      if (formId === FORM_TYPES.ENTITY_FORM) {
        if (Object.keys(updates).length === 0) {
          // Nothing has changed, no need to change the state.
          return;
        }

        // Flag that we need to update the preview.
        dispatch(setUpdatePreview(true));
        const normalizedFormState = Object.entries({
          ...formState,
          ...updates,
        }).reduce((acc: Record<string, any>, [key, value]) => {
          // Before merging formState into pageData, convert the multi-select
          // entries (where the value is an array) to indexed keys,
          // e.g. `field_name[] = ['a','b']` → `field_name[0]='a', field_name[1]='b'`.
          // Without this conversion, http_build_query() in PHP's
          // ClientDataToEntityConverter::setEntityFields() produces nested arrays
          // that break Select::valueCallback() with "Array to string conversion".
          // @see \Drupal\canvas\ClientDataToEntityConverter::setEntityFields()
          // @see ui/src/components/form/react-hook-form/fields/PageDataFormField.tsx formStateToPageDataStore
          if (
            Array.isArray(value) &&
            // @todo replace this with a better solution in https://www.drupal.org/i/3587609.
            document.querySelector(
              `select[data-is-multiselect="true"][name="${key}"]`,
            )
          ) {
            const baseKey = key.slice(0, -2);
            (value as any[]).forEach((item, index) => {
              acc[`${baseKey}[${index}]`] = item;
            });
            return acc;
          }
          return { ...acc, [key]: value };
        }, {});
        dispatch(setPageData(normalizedFormState));
      }
    };
    document.addEventListener(
      AJAX_UPDATE_FORM_STATE_EVENT,
      ajaxUpdateFormStateListener as unknown as EventListener,
    );

    return () => {
      document.removeEventListener(
        AJAX_UPDATE_FORM_STATE_EVENT,
        ajaxUpdateFormStateListener as unknown as EventListener,
      );
    };
  }, [formState, dispatch]);

  if (isFetching || isFetchingLayout || !pageDataExists) {
    return (
      <Spinner size="3" loading={true}>
        <Box mt="9" />
      </Spinner>
    );
  }

  /* Wrap the JSX form in a ref, so we can send it as a stable DOM element
      argument to Drupal.attachBehaviors() anytime jsxFormContent changes.
      See the useEffect just above this. */
  return <div ref={formRef}>{jsxFormContent}</div>;
};

export default PageDataFormRenderer;
