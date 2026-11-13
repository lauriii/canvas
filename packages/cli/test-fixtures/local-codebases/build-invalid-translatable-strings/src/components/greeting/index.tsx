import { useTranslation } from 'drupal-canvas/react';

import { useFarewell } from '@/lib/farewell';

export default function Greeting() {
  const { t } = useTranslation();
  return (
    <p>
      {t('Hello', {}, { context: 'Month – long' })} {useFarewell()}
    </p>
  );
}
