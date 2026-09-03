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
 *
 * Both forms render inside the same wrapper element (the `as` prop), which
 * receives `className`, `style`, and any other props. A URL-backed icon's
 * `<img>` fills the wrapper; an inline SVG keeps its own `width`/`height`
 * attributes, so to size it from the wrapper add a descendant rule — with
 * Tailwind, for example: `className="size-6 [&_svg]:size-full"`.
 */
export default function Icon({ icon, as = 'span', ...props }: IconProps) {
  if (!icon) {
    return null;
  }
  const Component = as;
  if (icon.svg) {
    return (
      <Component dangerouslySetInnerHTML={{ __html: icon.svg }} {...props} />
    );
  }
  if (icon.url) {
    return (
      <Component {...props}>
        <img src={icon.url} alt="" style={{ width: '100%', height: '100%' }} />
      </Component>
    );
  }
  return null;
}
