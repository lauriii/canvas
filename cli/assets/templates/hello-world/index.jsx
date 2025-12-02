/**
 * HelloWorld example component
 */
const HelloWorld = ({
  greeting = 'Hello world!',
  ctaDisplayText = 'Click me!',
  ctaLink = 'https://example.com',
  content,
}) => {
  return (
    <div className="hello-world-component">
      <h2 className="greeting">{greeting}</h2>
      <div className="content">{content}</div>
      <button type="button" className="cta">
        <a href={ctaLink}>{ctaDisplayText}</a>
      </button>
    </div>
  );
};

export default HelloWorld;
