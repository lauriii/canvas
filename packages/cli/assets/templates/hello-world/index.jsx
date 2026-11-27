import { cn } from 'drupal-canvas';

/**
 * HelloWorld example component
 */
const HelloWorld = ({
  greeting = 'Hello world!',
  ctaDisplayText = 'Click me!',
  ctaLink = 'https://example.com',
  content,
  // Styles editors pick, when component.yml accepts styles.
  className,
  style,
}) => {
  return (
    <div className={cn('hello-world-component', className)} style={style}>
      <h2 className="greeting">{greeting}</h2>
      <div className="content">{content}</div>
      <button type="button" className="cta">
        <a href={ctaLink}>{ctaDisplayText}</a>
      </button>
    </div>
  );
};

export default HelloWorld;
