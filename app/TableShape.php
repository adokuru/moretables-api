<?php

namespace App;

enum TableShape: string
{
    case Round = 'round';
    case Square = 'square';
    case Rectangle = 'rectangle';
    case Oval = 'oval';
    case Pentagon = 'pentagon';
    case Hexagon = 'hexagon';
    case Octagon = 'octagon';
}
