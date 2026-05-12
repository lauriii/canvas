interface ExampleLabelProps {
  children: string;
}

const icon = (
  <svg aria-hidden="true" viewBox="0 0 12 12">
    <circle cx="6" cy="6" r="4" />
  </svg>
);

export function ExampleLabel({ children }: ExampleLabelProps) {
  return (
    <span>
      {icon}
      {children.trim()}
    </span>
  );
}
