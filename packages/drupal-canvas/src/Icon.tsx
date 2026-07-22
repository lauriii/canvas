type IconValue = {
  id: string;
  svg?: string;
  url?: string;
};

type IconProps = {
  icon?: IconValue | null;
  as?: 'span' | 'div';
  className?: string;
  id?: string;
  style?: Record<string, string>;
  [key: string]: any;
};

/**
 * Renders an icon prop resolved by Canvas.
 *
 * An icon prop resolves to `{ id, svg?, url? }`: inline SVG markup for the
 * `svg`/`svg_sprite` extractors, or an asset URL for the `path` extractor.
 * This component renders whichever is present, so component authors never embed
 * or manage SVG sources by hand — mirroring how `FormattedText` renders
 * server-processed HTML. Renders nothing when the icon is unset or unresolved.
 */
export default function Icon({ icon, as = 'span', ...props }: IconProps) {
  if (!icon) {
    return null;
  }
  if (icon.svg) {
    const Component = as;
    return (
      <Component dangerouslySetInnerHTML={{ __html: icon.svg }} {...props} />
    );
  }
  if (icon.url) {
    return <img src={icon.url} alt="" {...props} />;
  }
  return null;
}
