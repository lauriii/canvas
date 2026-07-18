import clsx from 'clsx';

import type { PackIcon } from '@/types/Icons';

import styles from '@/components/icons/IconPreview.module.css';

/**
 * Renders a preview of a single icon from an installed icon pack.
 *
 * Inline SVG markup is produced server-side by the pack's extractor, so the
 * client never interprets pack internals. Icons without a resolvable preview
 * fall back to their machine name.
 */
const IconPreview = ({
  icon,
  size = 20,
  className,
}: {
  icon: PackIcon;
  size?: number;
  className?: string;
}) => {
  if (icon.svg) {
    return (
      <span
        className={clsx(styles.iconPreview, className)}
        style={{ width: size, height: size }}
        // The SVG markup is produced and sanitized server-side: it comes from
        // installed extensions (trusted code) or CLI-pushed libraries that
        // are sanitized at upload.
        dangerouslySetInnerHTML={{ __html: icon.svg }}
      />
    );
  }
  if (icon.url) {
    return (
      <span
        className={clsx(styles.iconPreview, className)}
        style={{ width: size, height: size }}
      >
        <img src={icon.url} alt={icon.label} width={size} height={size} />
      </span>
    );
  }
  return (
    <span
      className={clsx(
        styles.iconPreview,
        styles.iconPreviewFallback,
        className,
      )}
      style={{ width: size, height: size }}
      title={icon.name}
    >
      {icon.name.charAt(0).toUpperCase()}
    </span>
  );
};

export default IconPreview;
