Every Canvas `dr` command:

1. Is named `canvas:<noun>` or `canvas:<noun>:<verb>`.
2. Meets the operator-facing contract at the top of `docs/cli.md`, and has its own section there with a one-paragraph description and one example invocation.
3. Prints through `SymfonyStyle`. An error goes to `$io->error()`, not to stdout as prose.
4. Works with `--no-interaction`. A command that asks questions must also accept every answer as an argument or option, so automation never blocks on a prompt.
5. Has a kernel test in `tests/src/Kernel/Command/<Command>Test.php` that runs it through `CommandTester`, validates every `--format=json` report against its schema, and covers each invalid input with its exit code.
