export default function ActionLink({
  label,
  href,
}: {
  label: string;
  href: string;
}) {
  return (
    <a
      className="inline-flex min-h-12 items-center justify-center self-start rounded-full bg-cyan-300 px-6 py-3 font-semibold text-slate-950 transition hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-cyan-300"
      href={href}
    >
      {label}
    </a>
  );
}
