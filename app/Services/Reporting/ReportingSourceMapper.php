<?php

namespace App\Services\Reporting;

use App\ReservationSource;

class ReportingSourceMapper
{
    public const CHART_WALKIN = 'walkin';

    public const CHART_PHONE = 'phone';

    public const CHART_NETWORK = 'network';

    public const CHART_MORETABLES = 'moretables';

    /**
     * @return array<string, string>
     */
    public static function chartKeyLabels(): array
    {
        return [
            self::CHART_MORETABLES => 'MoreTables Network',
            self::CHART_NETWORK => 'Your Network',
            self::CHART_PHONE => 'Phone/In house',
            self::CHART_WALKIN => 'Walk-in',
        ];
    }

    public static function chartKey(ReservationSource $source): string
    {
        return match ($source) {
            ReservationSource::Customer => self::CHART_MORETABLES,
            ReservationSource::Staff => self::CHART_NETWORK,
            ReservationSource::Phone => self::CHART_PHONE,
            ReservationSource::WalkIn, ReservationSource::Waitlist => self::CHART_WALKIN,
        };
    }

    public static function displayLabel(string $chartKey): string
    {
        return self::chartKeyLabels()[$chartKey] ?? $chartKey;
    }

    public static function isWalkIn(ReservationSource $source): bool
    {
        return in_array($source, [ReservationSource::WalkIn, ReservationSource::Waitlist], true);
    }

    /**
     * @return list<string>
     */
    public static function chartKeys(): array
    {
        return [
            self::CHART_WALKIN,
            self::CHART_PHONE,
            self::CHART_NETWORK,
            self::CHART_MORETABLES,
        ];
    }
}
