export default function getStarterComponentTemplate(componentName: string) {
  return `// See https://project.pages.drupalcode.org/canvas/ for documentation on how to build a code component

import { cn } from 'drupal-canvas';

// \`className\` and \`style\` carry the styles editors pick, when the component
// accepts styles (Component data > Styles). Apply them on the root element.
const Component = ({
  text = "${componentName}",
  className,
  style,
}) => {
  return (
    <div className={cn('text-3xl', className)} style={style}>
      {text}
    </div>
  );
};

export default Component;
`;
}
