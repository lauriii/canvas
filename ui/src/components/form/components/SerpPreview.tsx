import { useMemo } from 'react';

import { useAppSelector } from '@/app/hooks';
import InputDescription from '@/components/form/components/drupal/InputDescription';
import FormElementLabel from '@/components/form/components/FormElementLabel';
import { FORM_TYPES } from '@/features/form/constants';
import { selectFormValues } from '@/features/form/formStateSlice';
import { getBaseUrl, getDrupalSettings } from '@/utils/drupal-globals';

import styles from './SerpPreview.module.css';

/** Character limits commonly used by Google on desktop SERPs. */
const GOOGLE_SERP_LIMITS = {
  title: 60,
  description: 160,
  url: 70,
} as const;

const truncate = (text: string, maxLength: number): string =>
  text.length <= maxLength ? text : `${text.slice(0, maxLength)}\u2026`;

const FIELD_KEYS = {
  TITLE: 'title[0][value]',
  META_TITLE: 'metatags[0][basic][title]',
  ALIAS: 'path[0][alias]',
  DESCRIPTION: 'description[0][value]',
} as const;

/**
 * SERP Preview component that displays a preview of search engine results.
 */
const SerpPreview = () => {
  const formValues = useAppSelector((state) =>
    selectFormValues(state, FORM_TYPES.ENTITY_FORM),
  );

  const siteName =
    getDrupalSettings()?.canvasData?.v0?.branding?.siteName || '';
  const baseUrl = window.location.origin + (getBaseUrl() || '/');

  const title = formValues[FIELD_KEYS.TITLE] || '';
  const metaTitle = formValues[FIELD_KEYS.META_TITLE] || '';
  const alias = formValues[FIELD_KEYS.ALIAS] || '';
  const description = formValues[FIELD_KEYS.DESCRIPTION] || '';

  const previewTitle = useMemo(() => {
    let raw: string;
    if (metaTitle) {
      raw = metaTitle
        .replace(/\[current-page:title]/gi, title)
        .replace(/\[canvas_page:title]/gi, title)
        .replace(/\[site:name]/gi, siteName);
    } else {
      raw = title ? `${title} | ${siteName}` : siteName;
    }
    return truncate(raw, GOOGLE_SERP_LIMITS.title);
  }, [metaTitle, title, siteName]);

  const previewUrl = useMemo(
    () =>
      truncate(
        alias ? `${baseUrl}${alias.replace(/^\//, '')}` : baseUrl,
        GOOGLE_SERP_LIMITS.url,
      ),
    [alias, baseUrl],
  );

  const previewDescription = useMemo(
    () => truncate(description, GOOGLE_SERP_LIMITS.description),
    [description],
  );

  return (
    <div className={styles.wrapper}>
      <FormElementLabel className={styles.label}>
        Search Result Preview
      </FormElementLabel>
      <InputDescription
        description="This preview uses the typical character limits for Google search result pages on desktop. Search engines may decide to show different content."
        descriptionDisplay="before"
      >
        <div className={styles.preview}>
          <div className={styles.title}>{previewTitle}</div>
          <div className={styles.url}>{previewUrl}</div>
          <div className={styles.description}>{previewDescription}</div>
        </div>
      </InputDescription>
    </div>
  );
};

export default SerpPreview;
