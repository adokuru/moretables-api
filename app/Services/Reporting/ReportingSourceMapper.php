<?php

namespace App\Services\Reporting;

use App\ReservationSource;

class ReportingSourceMapper
{
    public const CHART_WALKIN = 'walkin';

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
            self::CHART_WALKIN => 'Walk-in',
        ];
    }

    public static function chartKey(ReservationSource $source): string
    {
        return match ($source) {
            ReservationSource::Customer => self::CHART_MORETABLES,
            // A booking your team took is yours however it reached them — the phone,
            // the host stand, or typed into the dashboard. FrontOfHouseShiftOverviewController
            // already buckets these two together for the Shift Overview page.
            ReservationSource::Staff, ReservationSource::Phone => self::CHART_NETWORK,
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
            self::CHART_NETWORK,
            self::CHART_MORETABLES,
        ];
    }
}
