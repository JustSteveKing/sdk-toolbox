# Contributing

Thanks for wanting to help. Bug reports, fixes, docs and new standards support are all welcome.

## Before you start

- **Bugs:** open an issue with the bug report form. A failing test, or the smallest connector and request that show the problem, is the most useful thing you can include.
- **Features:** open an issue before writing code, so we can agree the shape first. The toolbox implements published HTTP standards, so a feature request that names the RFC, draft or PSR section it follows is far easier to say yes to.
- **Security problems:** please don't open a public issue. See [SECURITY.md](SECURITY.md).

## The rules the code follows

[AGENTS.md](AGENTS.md) holds them in full. It's written for AI agents, but it's the same rulebook for people. The short version:

- **Standards first.** A feature follows a published standard where one exists, and its docblock cites the section. Anything built on an Internet-Draft is marked experimental.
- **PHP 8.3 is the floor.** No PHP 8.4 or 8.5 functions or syntax. CI runs 8.3, 8.4 and 8.5.
- **No HTTP implementation in `require`.** PSR interfaces only; an architecture test checks it.
- **Credentials never escape.** Anything that can reach an exception, a log line or a fixture goes through the redactor.
- **Test vectors come from their source.** If a standard publishes examples, the tests use them, fetched and checked rather than typed from memory.
- **Docs change with the code.** A change in behaviour updates the matching page in `docs/` in the same pull request.

## Working on it

```bash
composer install
composer test       # Pest
composer analyse    # PHPStan, at max, no baseline
composer lint       # Pint (vendor/bin/pint to fix)
```

All three have to pass. To run the suite on PHP 8.3, see "Checks" in [AGENTS.md](AGENTS.md).

## Pull requests

- Keep each one to a single change, with a description of what changed and why.
- Add or update tests. A test that passes first time is worth breaking on purpose once, to prove it can fail.
- Add a line to [CHANGELOG.md](CHANGELOG.md) under "Unreleased".

By contributing, you agree that your work is licensed under the project's [MIT licence](LICENSE.md), and to follow the [code of conduct](CODE_OF_CONDUCT.md).
