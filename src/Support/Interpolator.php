<?php

namespace Splicewire\Beam\Notifications\Support;

use Illuminate\Support\Arr;

/**
 * The logic-less `{{ path }}` substitution engine (spec §V) — explicitly NOT Blade and
 * NOT any code-evaluating template engine.
 *
 * The submission payload is UNTRUSTED user input (the same untrusted-input boundary the
 * submission door drew). A code-evaluating engine on that payload would be a template-
 * injection hole, so this does exactly one thing: replace each `{{ dot.path }}` token with
 * the value at that dot-path in a fixed context, and NOTHING else. There is no expression
 * parsing, no `@directive`, no function calls, no PHP evaluation — a payload value of
 * `@php ... @endphp` or `{{ system('rm') }}` is inert text, never executed.
 *
 * Rules:
 *  - Only `{{ dot.path }}` tokens are recognized (whitespace around the path tolerated).
 *  - The path is resolved against a fixed context array by `Arr::get` (dot notation).
 *  - Unknown paths render EMPTY (never throw) — logged at debug by the caller.
 *  - Values are coerced to a scalar string; a resolved token whose value is itself another
 *    `{{ ... }}` string is NOT re-interpolated (single pass, no recursion into payload
 *    content — so a payload that stores `{{ payload.x }}` cannot pivot to another key).
 */
class Interpolator
{
    /**
     * Substitute `{{ path }}` tokens in `$template` from `$context`, optionally escaping
     * each substituted value for HTML (mail bodies set `$escape = true`).
     *
     * @param  array<string, mixed>  $context
     */
    public static function render(string $template, array $context, bool $escape = false): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.\-]+)\s*\}\}/',
            function (array $m) use ($context, $escape): string {
                $value = Arr::get($context, $m[1]);

                $string = static::stringify($value);

                return $escape ? e($string) : $string;
            },
            $template,
        );
    }

    /**
     * Coerce a resolved value to a flat string. Scalars stringify directly; null and
     * unknown paths render empty; arrays/objects JSON-encode (defensive — templates should
     * target leaf scalars). Booleans render as `true`/`false` rather than `1`/``.
     */
    protected static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value);
    }
}
