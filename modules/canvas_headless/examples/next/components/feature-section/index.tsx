import type { ReactNode } from "react";

export default function FeatureSection({
  eyebrow,
  title,
  description,
  content,
}: {
  eyebrow?: string;
  title: string;
  description?: string;
  content?: ReactNode;
}) {
  return (
    <section className="mx-auto my-10 max-w-5xl rounded-3xl bg-slate-950 px-6 py-10 text-white shadow-xl sm:px-10">
      {eyebrow && (
        <p className="mb-3 text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">
          {eyebrow}
        </p>
      )}
      <h1 className="max-w-3xl text-3xl font-bold tracking-tight sm:text-5xl">
        {title}
      </h1>
      {description && (
        <p className="mt-4 max-w-2xl text-lg leading-8 text-slate-300">
          {description}
        </p>
      )}
      <div className="mt-8 grid gap-5 md:grid-cols-2">{content}</div>
    </section>
  );
}
