<?php

declare(strict_types=1);

namespace Plugins\Llm\API;

/**
 * What to do when the chosen provider cannot honour a JSON schema natively.
 *
 * `Refuse` is the default and the point. "Ask nicely in the prompt" is a
 * fallback that works most of the time, so a caller that never asked for it
 * and gets it anyway will build on a guarantee that does not exist, and find
 * out on the one response in a hundred that is not JSON. `PromptAndValidate`
 * has to be requested by name, and the response then says `StructuredMode::Prompted`.
 */
enum StructuredFallback: string
{
    case Refuse = 'refuse';
    case PromptAndValidate = 'prompt-and-validate';
}
