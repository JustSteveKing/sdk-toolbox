# AGENTS.md

SDK Toolbox is a PHP library for building API SDKs on PSR-18: typed requests, a connector, and the HTTP standards a client should honour. Read [README.md](README.md) and [docs/README.md](docs/README.md) before changing anything; the docs describe what the code promises.

## The rule that matters

**A feature follows a published standard where one exists, and says which.** Cite the RFC, draft or PSR and the section in the class docblock, and do what its MUST and SHOULD lines say from the client's side. Where there's no standard (cursor pagination, `retry-after-ms`), say so in the docblock and follow the convention the big APIs use. Anything built on an Internet-Draft is experimental: say so in the docblock, in `docs/standards.md` and wherever the feature is documented.

## Boundaries

- **No HTTP implementation.** `require` has PSR interfaces, `php-http/discovery`, and nothing that sends HTTP. `tests/Arch/ArchTest.php` checks both `src/` and `composer.json` for Guzzle, Symfony HttpClient, Nyholm, HTTPlug's client-common and Illuminate. Never weaken that test to make a change pass.
- **PHP 8.3 is the floor.** No 8.4 or 8.5 functions or syntax in `src/` or `tests/`:
  - no `array_any`, `array_find` or `array_first`;
  - no property hooks;
  - no `new Foo()->bar()` without parentheses;
  - no `clone()` with properties;
  - no `Uri\Rfc3986`; use `Http\Uri`.

  Attributes that only mean something on newer PHP (`#[\NoDiscard]`) are fine: they do nothing on 8.3. PHPStan checks the range 8.3 to 8.6 (`phpstan.neon`), but it won't catch syntax, so run the suite on 8.3 (see Checks).
- **Immutable values.** Value classes are `final readonly`, and `tests/Arch` checks the namespaces that must be. A `with*()` method returns a new instance, built through the constructor, and carries `#[\NoDiscard]`. `Connector` is the one class SDKs extend: its `with*()` methods clone and set private properties.
- **No static state, no magic.** Nothing booted by trait name, no global configuration, no `Macroable`, no mutable public properties.
- **Credentials never escape.** Anything that can end up in an exception, a log line, an event, a span, a dump or a fixture goes through `Redaction\Redactor`. Exceptions hold a `RequestSnapshot`, never a PSR-7 request. Secrets in constructors carry `#[SensitiveParameter]`, and classes holding them define `__debugInfo()`.
- **Nothing is unserialized.** Stores keep plain arrays and validate what they read back.
- **Every exception implements `Errors\SdkException`.** `tests/Arch` checks it for `src/Errors`. Testing exceptions that mustn't be mistaken for transport errors (`StrayRequestException`) are deliberately not PSR-18 exceptions.
- **Anything that follows a URL from a response** (a next link, a monitor, a redirect target) checks it against the base URI first.

## Tests

- **`tests/Conformance`** holds the tests of a standard's behaviour, named after it. Use the vectors the standard publishes: RFC 3986 §5.4, RFC 7636 Appendix B, RFC 9421 Appendix B, Standard Webhooks' library tests, GitHub's documented example. **Fetch the vector from its source and check it before using it;** never type one from memory. A remembered PKCE verifier was wrong once already.
- **A test that passes first time gets broken on purpose,** to prove it can fail: change the code it covers, watch it fail, put the code back. Back the file up with a copy, not `git checkout`, which also throws away uncommitted work.
- **`tests/Fixtures/Acme`** is a small SDK built the way the docs say to build one. `tests/Fixtures/Acme/types.php` holds PHPStan `assertType()` checks that generics carry through `send()` and `paginate()`. Add one when you add a generic API.
- **`tests/Memory`** checks bounded memory. Keep pagination in it.
- **Time.** Anything that waits takes a PSR-20 clock and a `Time\Sleeper`; tests use `Testing\FakeTime`. Nothing in the suite sleeps for real.
- **Docs and skill.** `tests/Unit/DocumentationTest.php` checks that every `JustSteveKing\SdkToolbox\...` class named in `docs/`, `README.md` and the agent skill exists, that every relative link resolves, and that every PHP example parses (as a file, class members, arguments or an expression). When you rename or remove something, the test tells you which page to fix.

## Checks

```bash
composer test       # Pest
composer analyse    # PHPStan max, no baseline
composer lint       # Pint (vendor/bin/pint to fix)
```

All three must pass. To run the suite on PHP 8.3, resolve dependencies for 8.3 in a copy of the repository and run Pest in Docker:

```bash
composer config platform.php 8.3.0 && composer update
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/pest
```

CI runs 8.3 (latest and lowest dependencies), 8.4 and 8.5, with 8.6 experimental until it's released.

## Docs

A change to behaviour changes the docs in the same commit:

- the topic page in `docs/`;
- `docs/standards.md` when a standard is added or its coverage changes;
- the agent skill in `resources/boost/skills/sdk-toolbox-development` when it changes how an SDK author would use the toolbox.

The README stays a front page; detail goes in `docs/`.

## Writing

British spelling. Plain, specific prose: name the RFC section, the status code, the number. No em dashes. Docblocks explain why, not what the next line does.

## Commits

No attribution lines: no co-author trailers, no generated-by banners. One commit per piece of work, and its message says what changed and why.
