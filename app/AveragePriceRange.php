<?php

namespace App;

enum AveragePriceRange: string
{
    case TenKAndUnder = '10k and under';
    case TwentyKAndUnder = '20k and under';
    case FiftyKAndUnder = '50k and under';
    case FiftyKAndAbove = '50k and above';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $canonical = collect(self::cases())
            ->first(fn (self $case): bool => strcasecmp($case->value, $trimmed) === 0);

        if ($canonical instanceof self) {
            return $canonical->value;
        }

        $slug = str($trimmed)
            ->lower()
            ->replace(['-', '_'], ' ')
            ->squish()
            ->toString();

        return match ($slug) {
            '10k and under', '10k', 'under 10k', 'budget', '$', '$$' => self::TenKAndUnder->value,
            '20k and under', '20k', 'under 20k', 'mid', '$$$' => self::TwentyKAndUnder->value,
            '50k and under', '50k', 'under 50k' => self::FiftyKAndUnder->value,
            '50k and above', '50k+', 'above 50k', 'fine', '$$$$' => self::FiftyKAndAbove->value,
            default => $trimmed,
        };
    }

    public static function isAllowed(mixed $value): bool
    {
        $normalized = self::normalize($value);

        return $normalized !== null && in_array($normalized, self::values(), true);
    }
}
