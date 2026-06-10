<?php

namespace App\Enums;

enum CancellationPolicyPartySizeScope: string
{
    case AllPartySizes = 'all_party_sizes';
    case LargePartiesOnly = 'large_parties_only';
    case Custom = 'custom';
}
