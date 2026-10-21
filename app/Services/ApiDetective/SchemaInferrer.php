<?php

namespace App\Services\ApiDetective;

/** Derives a JSON Schema from a sample JSON value (used for live-discovered endpoints). */
class SchemaInferrer
{
    public static function infer(mixed $value, int $depth = 0): array
    {
        if ($depth > 6) {
            return [];
        }

        return match (true) {
            is_null($value) => ['type' => 'null'],
            is_bool($value) => ['type' => 'boolean'],
            is_int($value) => ['type' => 'integer'],
            is_float($value) => ['type' => 'number'],
            is_string($value) => array_filter(['type' => 'string', 'format' => self::format($value)]),
            array_is_list($value) => ['type' => 'array', 'items' => $value === [] ? new \stdClass : self::infer($value[0], $depth + 1)],
            default => [
                'type' => 'object',
                'properties' => array_map(fn ($v) => self::infer($v, $depth + 1), array_slice($value, 0, 60, true)),
            ],
        };
    }

    private static function format(string $s): ?string
    {
        return match (true) {
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $s) => 'date-time',
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) => 'date',
            (bool) preg_match('#^https?://#', $s) => 'uri',
            (bool) filter_var($s, FILTER_VALIDATE_EMAIL) => 'email',
            (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s) => 'uuid',
            default => null,
        };
    }
}
