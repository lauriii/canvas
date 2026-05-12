interface DefaultLabelProps {
  children: string;
}

export default function DefaultLabel({ children }: DefaultLabelProps) {
  return <strong>{children.trim()}</strong>;
}
