<?php

declare(strict_types=1);

namespace Plugins\Llm\API;

/**
 * How the structured output in a response was obtained. Present on every
 * response that asked for a schema, so a caller can tell the two apart.
 *
 * In BOTH modes the output has been validated locally against the schema
 * before it is returned. The difference is only how much the provider helped:
 *
 *   Native    the provider was given the schema and constrains its decoding
 *             to it. A validation failure here is rare and worth a bug report.
 *   Prompted  the provider could not honour a schema, the caller explicitly
 *             allowed the fallback, and the schema was put in the prompt.
 *             Validation failures are expected, and `Length` finishes more so.
 */
enum StructuredMode: string
{
    case Native = 'native';
    case Prompted = 'prompted';
}
