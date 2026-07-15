export default function StatCard({
  value,
  label,
  detail,
}: {
  value: string;
  label: string;
  detail?: string;
}) {
  return (
    <article className="rounded-2xl border border-cyan-400/20 bg-slate-900 p-6">
      <p className="text-4xl font-bold text-cyan-300">{value}</p>
      <h2 className="mt-2 text-lg font-semibold text-white">{label}</h2>
      {detail && <p className="mt-2 leading-6 text-slate-400">{detail}</p>}
    </article>
  );
}
