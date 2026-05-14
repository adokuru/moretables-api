<?php

namespace App;

enum TableType: string
{
    case Regular = 'regular';
    case Booth = 'booth';
    case HighTop = 'high_top';
    case Bar = 'bar';
    case Communal = 'communal';
    case Outdoor = 'outdoor';
}
