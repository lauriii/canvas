import { useTranslation } from 'drupal-canvas/react';

export function useCardLabel(): string {
  const { t } = useTranslation();
  return t('Card label');
}
