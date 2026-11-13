import { useTranslation } from 'drupal-canvas/react';

interface ButtonProps {
  label: string;
  clicks?: number;
}

export default function Button({ label, clicks = 0 }: ButtonProps) {
  const { t, formatPlural: plural } = useTranslation();
  return (
    <button type="button" title={t('Open @label', { '@label': label })}>
      {label}{' '}
      {plural(clicks, '1 click', '@count clicks', {}, { context: 'Button' })}
    </button>
  );
}
