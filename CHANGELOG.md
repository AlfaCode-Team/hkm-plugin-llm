# Changelog

All notable changes to `hkm-plugin-llm` are documented here. The format is based
on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **First release: provider-neutral inference.** Solves `llm.inference`; needs
  `http.client` and nothing else. Anthropic (Messages API), OpenAI (Chat
  Completions), Gemini (`generateContent`) and Ollama (`/api/chat`), behind one
  published contract, `LlmContract`, in `API/Contracts/`. It is a plugin
  contract and not a kernel port, on purpose: the surface is too unsettled to
  freeze.

- **Structured output that cannot silently degrade.** A provider that cannot
  constrain its output to a schema refuses the request; the prompt-only fallback
  must be requested (`StructuredFallback::PromptAndValidate`) and is reported as
  `StructuredMode::Prompted`; and in every mode the output is validated locally
  before it is returned. `Schema` accepts only the keywords it can check.

- **Tool use behind an authorization boundary.** `ToolBox` runs a model's tool
  call only after the tool is found, its arguments validate and a required,
  fail-closed `ToolAuthorizer` allows it for the caller's `Identity`. No
  allow-everything authorizer ships. The model is told nothing about why a call
  was refused.

- **Explicit `Effort`**, refused by a provider that cannot honour it rather than
  ignored.

- **`llm:providers`**, which lists configured providers and what the others are
  missing, makes no request and prints no credential.

- **`ScriptedGateway`**, a deterministic provider for a consumer's own tests.

### Notes

- **No streaming.** `HttpClientPort` returns whole responses and the cURL
  adapter exposes no way to read one incrementally; a stream built on it would
  buffer and replay.
- **No live provider was called.** Request shapes follow each provider's
  documented format; reply fixtures are hand-written, not recorded.
- 195 tests, 631 assertions, plus `ground check --strict` clean.
- Guards were broken one at a time and the suite re-run (24 breaks and a no-op
  control, which passed). 23 were noticed on the first run; one was not — a
  `ToolHandler` object receiving the caller's identity was untested, only the
  closure path was covered — and a test now pins it. Re-run, all 24 are noticed.
  Two tests that would have failed for the wrong reason (the scripted provider
  running out of responses) were tightened. A first-pass test run also
  found a real defect — an OpenAI response whose `choices` held a string
  instead of an object was parsed as an empty answer — which now fails as a
  `GatewayException`.
