# hkm-plugin-llm

Provider-neutral inference — **Anthropic, OpenAI, Gemini, Ollama** — with tool
use, structured output and an explicit effort setting, over the kernel's
`HttpClientPort`.

Solves `llm.inference`. Requires `http.client` and nothing else. No database
table, no migration, no routes, no queue job.

```php
use Plugins\Llm\API\Contracts\LlmContract;
use Plugins\Llm\API\{LlmRequest, Schema, StructuredFallback};

// One call, structured output.
$schema = Schema::object('lesson', [
    'title' => ['type' => 'string'],
    'steps' => ['type' => 'array', 'items' => ['type' => 'string']],
]);

$response = $llm->complete(LlmRequest::prompt($pages, system: 'You write lesson plans.')->withSchema($schema));

$response->output;            // a decoded, VALIDATED array — or an LlmException was thrown
$response->structuredMode;    // StructuredMode::Native
```

```php
// The tool loop. The application decides what may run.
$tools = (new ToolBox(new CallbackAuthorizer(
        fn (ToolCallPart $call, Tool $tool, Identity $who): bool => $who->hasPermission("invoice:read"),
    )))
    ->register($invoiceTool, fn (array $args, Identity $who) => $invoices->find($args['id'], $who->tenantId));

$run = $llm->run(LlmRequest::prompt('What is invoice 7?'), $tools);
$run->text();
```

---

## The three things to understand first

**1. This is a plugin contract, not a kernel port, on purpose.** The surface of
model APIs — tool use, reasoning, caching, batch — is still moving, and a kernel
port is a promise the kernel then has to keep. The contract lives in
`API/Contracts/LlmContract.php` and stays here until a second consumer shows
which parts of its shape are real. Nothing in the kernel was changed.

**2. There is no streaming, and the reason is not this plugin.**
`HttpClientPort::request()` returns a finished `HttpClientResponse`. The cURL
adapter buffers with `CURLOPT_RETURNTRANSFER` and exposes no write callback and
no `stream` option, so a response cannot be handed to a caller as it arrives.
A `stream()` method here would have to buffer the whole reply and replay it —
and callers would build progress UIs on a stream that is not one. So the
contract has no such method. When the port (or the adapter) gains a way to read
a response incrementally, `stream()` is an additive change to this contract.

**3. A tool call is the model asking, not the application deciding.** See
[Tool use](#tool-use-is-a-security-boundary).

---

## The contract

```
LlmContract
  complete(LlmRequest, ?provider): LlmResponse     one model call; never runs a tool
  run(LlmRequest, ToolBox, ?provider, maxSteps): LlmRun   the tool loop
  providers(): list<ProviderInfo>                   what is configured; no credential
```

| Type | What it is |
|---|---|
| `Message` | a `Role` and a list of parts: `TextPart`, `ToolCallPart`, `ToolResultPart`, `ReasoningPart` |
| `LlmRequest` | one model call: messages, tools, `ToolChoice`, `Schema`, `StructuredFallback`, `maxTokens`, `temperature`, `Effort`, `stop`, `model` |
| `LlmResponse` | what ONE call returned: the assistant `Message`, `FinishReason`, `Usage`, and — when a schema was asked for — `output` and `structuredMode` |
| `Step` | one pass of the loop: the response, and a `ToolExecution` for each call it made |
| `LlmRun` | the steps, the transcript, whether it `finished`, the summed usage |
| `Schema` | a JSON Schema this plugin can send **and check** |
| `Tool` | what the model is told: a name, a description, an input `Schema`. Carries no code |

The value types sit in `API/`, not `Domain/`, because a consumer has to import
them and `Domain/` may not be imported across modules.

### What was taken from Appwrite's `agents` package, and what was not

Taken, because each earns its place:

- **A message is not a step is not a response.** What was said, what one call
  returned and what the loop did about it answer different questions. Keeping
  them as `Message`, `LlmResponse` and `Step` is what lets a caller see that a
  step's tool call was refused without parsing a transcript.
- **Effort is explicit** (`Effort` or `null` for the provider's own default) —
  and a provider that cannot honour it refuses it rather than ignoring it.
- **Parts, not strings.** A message is a list of typed parts; a reasoning block
  is carried opaquely so a provider that signs its thinking gets it back intact.
- **Usage** with cache-read and reasoning tokens, summed across a run.

Not taken:

- **Running tools straight from a closure on the `Tool`.** Appwrite's `Agent`
  executes whatever the model names, and its only checks are "does the tool
  exist" and "do the arguments fit the schema". There is no point at which the
  application is asked. Here a `Tool` is only a description; running one needs
  a `ToolBox`, and a `ToolBox` cannot be built without a `ToolAuthorizer`.
- **Silently substituting a "respond" tool when a model lacks structured
  output.** That is the degradation this plugin exists to prevent; see below.
- **Sniffing capabilities from the model id** (`before('4.5')`). Capability is a
  per-provider property with an operator switch, not a guess from a string.
- **Streaming events, `Embedder`, per-adapter `options` bags, image and file
  parts.** No transport to stream over, no second consumer asking, and an
  options bag is where an ignored setting hides.

---

## Structured output cannot silently degrade

A caller that believes it has schema-valid output and does not is the failure
this is designed against. Three mechanisms, in order:

**1. The provider either constrains its output to the schema, or the request is
refused.** Each gateway answers `cannotConstrain(Schema)` — per schema, because
the answer depends on it. OpenAI's strict mode and Anthropic's constrained
decoding only take *closed* schemas (`additionalProperties: false`, every
property `required`, an object at the root) and are not guaranteed to accept
range keywords, so a looser schema is refused with the reason. Gemini cannot
express several non-null types on one node. A provider switched off with
`LLM_<P>_NATIVE_SCHEMA=false` refuses every schema.

```
LlmException: LLM provider [openai] cannot constrain its output to this schema natively
(strict mode needs a closed schema: additionalProperties false and every property required).
Pass StructuredFallback::PromptAndValidate to accept a prompt-only fallback …
```

**2. The prompt fallback has to be asked for by name** —
`->withSchema($schema, StructuredFallback::PromptAndValidate)` — and the
response then says `structuredMode === StructuredMode::Prompted`. The schema is
put in the prompt, and a surrounding Markdown fence is tolerated **only** in
this mode.

**3. In every mode the output is validated locally before you see it.** "The
provider constrains its decoding" is the provider's claim; "this value
satisfies the schema" is checked. A violation, invalid JSON, a truncated answer
(`FinishReason::Length`) or a refusal is an `LlmException` — with `violations`
and the raw text as properties, kept out of the message because they are model
output.

For that check to mean something, `Schema` accepts only keywords it can
validate: `type`, `properties`, `required`, `additionalProperties` (boolean),
`items`, `enum`, `const`, `minimum`, `maximum`, `minLength`, `maxLength`,
`minItems`, `maxItems`, `anyOf`, `description`, `title`. `pattern`, `format`,
`$ref`, `oneOf` and the rest are **refused by name at construction** — sent to a
provider and not enforced locally they would read as a stronger guarantee than
they are.

`null` is a valid output, so "was there structured output" is
`structuredMode !== null`, not `output !== null`. A response in which the model
asked for tools has no output yet.

---

## Tool use is a security boundary

A tool is application code reached from model output. The model chooses the
call; the model is steered by everything in its context — including text the
user did not write: a fetched page, a document, a database row, an earlier tool
result. "The model decided to call it" is not a reason to run it.

For every call, in this order, stopping at the first failure
(`API/Tools/ToolBox.php`):

| # | Check | Otherwise |
|---|---|---|
| 1 | the tool is registered | `Unknown` |
| 2 | the arguments match the tool's schema | `Invalid` |
| 3 | the **`ToolAuthorizer`** allows it, for the caller's `Identity` | `Refused` — fail-closed |
| 4 | the handler runs; an exception is caught | `Failed` |

`new ToolBox(...)` **requires** a `ToolAuthorizer` and has no default, and this
plugin ships no allow-everything implementation. Fail-closed: an authorizer that
throws, or a `CallbackAuthorizer` closure that returns anything but `true`
(`1`, `'yes'` and `null` do not pass), is a refusal. `complete()` never runs a
tool; it returns the calls and the caller owns what follows. `run()` replaces
`$request->tools` with `$tools->definitions()`, so what the model is told about
and what can run are one list.

What the model is told on a failure is generic — "not permitted", "failed". The
reason is in `ToolExecution::$reason`, for you: a refusal that explained the
policy would hand a map of it to whatever is steering the model, and a handler
exception can carry a SQL fragment or a secret. Refused and invalid attempts are
also logged (`llm.tool.refused`, tool name, user and tenant — **not** the
arguments) through `LoggerPort` when it is bound.

### What a consumer must do

1. **Write the authorizer in the application's own terms.** Gate on the
   caller's permissions *and on the arguments* — "may this user read **this**
   invoice" — the way any write path would. `Identity` is the caller's, never
   one the model supplied.
2. **Take tenant and user from the `Identity` the handler is given, never from
   `$arguments`.** A tool schema with a `tenant_id` or `user_id` property is a
   tool the model can point at someone else's data.
3. **Treat tool results as untrusted.** They go back into the context, so a
   result containing instructions is an instruction to the next step.
4. **Do not register destructive tools unguarded.** For anything irreversible,
   require a confirmation the model cannot give.
5. **Decide who may call `complete()` / `run()` at all.** They spend money, and
   this plugin has no routes and no authorization of its own on them — the
   application decides.
6. **Bound the loop.** `maxSteps` (default 8, at most 50) counts model calls;
   when the limit is hit with tools still wanted, those calls are **not**
   executed and `LlmRun::$finished` is false.

---

## Providers and configuration

Each provider is built from the shapes in its gateway's docblock. HTTP goes
through `HttpClientPort` — no vendor SDK, no cURL of this plugin's own — so
every call inherits the client's SSRF and DNS-rebind guard. Per call the
gateways ask it for `block_private`, `retry: 0` (a completion costs money and is
not idempotent — retrying is the caller's decision), a `max_response_bytes`
ceiling far under the client's 32 MB default, and `LLM_TIMEOUT`.

| Variable | Default | |
|---|---|---|
| `LLM_DEFAULT_PROVIDER` | `anthropic` | `anthropic` `openai` `gemini` `ollama` |
| `LLM_TIMEOUT` | `60` | seconds; 1–600. Long generations need more |
| `LLM_DEFAULT_MAX_TOKENS` | `4096` | Anthropic requires one; used when a request has none |
| `LLM_MAX_RESPONSE_BYTES` | `8388608` | |
| `LLM_BLOCK_PRIVATE` | `true` | the hosted three; set `false` only behind an internal proxy |
| `LLM_ANTHROPIC_API_KEY` | — | **no default** |
| `LLM_ANTHROPIC_MODEL` | `claude-sonnet-5-5` | configuration, not a constant |
| `LLM_OPENAI_API_KEY` / `_MODEL` | — | no default for either |
| `LLM_GEMINI_API_KEY` / `_MODEL` | — | no default for either |
| `LLM_OLLAMA_MODEL` | — | no default |
| `LLM_<P>_BASE_URL` | the vendor's | `LLM_OLLAMA_BASE_URL` defaults to `http://127.0.0.1:11434` |
| `LLM_<P>_NATIVE_SCHEMA` | `true` | `false` makes that provider refuse every schema |
| `LLM_OLLAMA_BLOCK_PRIVATE` | `false` | a local model server **is** a private address |

**The keys are optional in `module.json` on purpose.** Four providers, four
keys: requiring all of them would make every deployment configure vendors it
does not use. A provider missing its key (or, where there is no safe default, its
model) is simply not configured; using it throws an `LlmException` naming the
exact variables. `hkm plugins enable` writes the keys as commented lines. Only
Anthropic has a default model — ids change faster than a plugin releases, and a
stale default is a quiet 404 months later.

**The Ollama base URL is the operator's** and is never a request field. It is
exactly the SSRF shape `block_private` exists for, which is why that guard is on
for the hosted providers and the choice is explicit for Ollama. Do not build a
feature that lets a user choose the endpoint. A base URL with credentials, a
query or a fragment makes the provider unconfigured (the HTTP client prints
URLs in its errors), and the hosted three must be `https`.

### What each provider can do

| | structured output | tool use | forced tool choice | effort |
|---|---|---|---|---|
| Anthropic | native (closed schemas) | yes | yes | extended thinking budget |
| OpenAI | native strict (closed schemas) | yes | yes | `reasoning_effort` |
| Gemini | native (`responseSchema`) | yes | yes | `thinkingBudget` |
| Ollama | native (`format`) | yes | **no — refused** | **no — refused** |

Refusals happen **before anything is sent**: Ollama has no `tool_choice` and no
effort setting; Anthropic rejects temperature, and forced tool choice, with
extended thinking, and needs `maxTokens` above the thinking budget.
`ToolChoice::none()` is honoured on Ollama by not sending the tools.
Anthropic and OpenAI model ids are taken from config; `LlmRequest::$model`
overrides per call.

---

## Credentials

Every provider's key is held in a `Secret`: it prints as `[redacted]` through
`var_dump`, `print_r`, `var_export`, `json_encode` and string conversion, and
refuses `serialize`. `ProviderInfo` (what `describe()`, `providers()` and
`llm:providers` return) is built from a name, a model id, the **host** of the
base URL and capability flags — there is no variant that adds more.

Exceptions: a transport failure is rewrapped with the key scrubbed from its
message and **not chained** (a logger walking `previous` would print it). An
HTTP error keeps the status and the provider's error *code*; the provider's
message is kept — scrubbed, 300 bytes — except on 401/403, because OpenAI answers
a bad key with `Incorrect API key provided: sk-…ABCD` and a masked key is still
part of the key. Gemini's key travels in the `x-goog-api-key` header, never in
the URL.

Not covered: a string already copied out of a `Secret`, and the arguments an
HTTP client's own stack frames hold while a request is in flight. Run
production with `zend.exception_ignore_args=On` (the `php.ini-production`
default).

---

## `llm:providers`

`hkm cli llm:providers` lists the configured providers, their model, host and
capabilities, and names what each unconfigured one is missing. It makes no
request and works with no network. Exit code 1 when nothing is configured.

---

## Testing a consumer

`Infrastructure/Gateways/ScriptedGateway` is a deterministic provider: it
returns what it was told, in order, and records every request. It does no
schema work of its own — it returns text and the service validates, so a test
that feeds it bad JSON exercises the same path a bad model would. Wrap it in a
`GatewayRegistry` and a `LlmService`:

```php
$gw = (new ScriptedGateway())->thenCall('get_invoice', ['id' => 'inv-7'])->then('Invoice 7 is 100.');
$llm = new LlmService(new GatewayRegistry('scripted', ['scripted' => fn () => $gw]), Identity::asUser('u', 't'));
```

## Testing this plugin

```bash
cd hkm-plugin-llm
/path/to/kernel/vendor/bin/phpunit        # 195 tests, 631 assertions
/path/to/kernel/modules/ground/bin/ground check --strict
```

`tests/bootstrap.php` loads the kernel's `vendor/autoload.php` (set
`HKM_KERNEL_PATH`, or keep the workspace layout) and maps the sibling
`hkm-plugin-http-client` so the integration suite can boot the real
`http.client` provider.

### Verified

- The **request each gateway builds** — URL, headers, JSON body — against an
  expected array written out in full per provider, for a conversation with
  system text, an assistant turn with two tool calls (one with no arguments) and
  their results; plus structured output, effort, every tool-choice form and the
  per-request model override. The empty-arguments case is pinned on the wire
  (`{}`, not `[]`).
- Each gateway **parses** its provider's documented response shape: text, tool
  calls (OpenAI's JSON-string arguments decoded; Gemini's missing ids
  synthesised), usage including cache and reasoning tokens, refusals, blocked
  prompts, and thinking blocks echoed back verbatim.
- Every malformed 200, redirect, 5xx with a hostile body and transport failure
  is a `GatewayException` in layer `gateway.llm.<provider>`; none is an empty
  answer.
- The hardening options each call asks of the HTTP client.
- Structured-output refusal, the explicit fallback and local validation; the
  tool boundary (each outcome, fail-closed, the handler unreachable on a refusal,
  validation before authorization, identity from the caller); the step limit;
  credential redaction in every dump format, in exceptions built from remote
  errors that quote the key, and in `llm:providers` output.
- The plugin on the **real kernel** through the ground: the manifest compiled,
  the contract resolved from a scoped container, `GatewayRegistry` internal, the
  real `http.client` provider in the graph, and the command registered and run
  through the real CLI pipeline.
- Guards were individually broken and the suite re-run — see the CHANGELOG.

### NOT verified

- **No live provider was ever called.** No paid API, no network. The request
  shapes come from each provider's documented format, and the reply fixtures in
  `tests/fixtures/` were **written by hand to that documented shape — none was
  recorded from a real response**. If a provider has changed a field, these
  tests will still pass.
- Specifically unconfirmed against live APIs: Anthropic's
  `output_config.format` for structured output; Gemini's
  `responseSchema` handling of nullable and enum types, and newer Gemini models
  that require thought signatures to be round-tripped (not handled);
  `reasoning_effort` and thinking-budget acceptance per model; whether a given
  model accepts a tool together with a schema; the strictness limits that make
  `cannotConstrain` refuse range keywords (deliberately conservative).
- A real cURL request through `hkm-plugin-http-client` — the transport is a
  recording fake. The SSRF guard is inherited, not re-tested here.
- Behaviour under OpenSwoole and PHP-FPM; nothing here holds static state, but it
  has not run in either.

---

## Deliberately absent

- **Streaming** — see above.
- **Routes.** A route that accepted a prompt is an unauthenticated way to spend
  money and to reach whatever tools are registered.
- **A queue job or an event listener.** Nothing is queued and nothing subscribes,
  so there is no job-name registration to forget (`jobs[]` in a manifest does
  not register a handler — `$worker->job()` does) and no listener to bind in the
  wrong scope. A consumer that runs completions in a worker registers its own job
  with `bind()`, not `bindInternal()`.
- **Persistence.** No prompt log, no completion cache. A consumer that stores
  either owns that write.
- **Embeddings, image/file input, batch, caching controls.** No second consumer
  has asked, and each would widen a contract that is still being learned.
- **Retries.** A completion is not idempotent and costs money; the caller decides.
- **Usage events.** The `Usage` is on every response; what to do with it
  (metering, budgets) is the application's.
