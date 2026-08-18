# `spikes/` — throwaway feasibility spikes

**Nothing in this directory ships.** Each subdirectory is a labelled spike: the
smallest real code that proves or disproves one risky assumption behind a
design proposal. Spikes are not tested, not linted by the module's CI, not
installed by any recipe, and are expected to be deleted once the question they
answer is settled.

| Spike | Question | Verdict |
|---|---|---|
| `canvas_code_editor_spike/` | Can a Canvas *page extension* host the in-browser code editor (CodeMirror surface + compilation + preview + save path)? | Partly. See its `FINDINGS.md`. |
