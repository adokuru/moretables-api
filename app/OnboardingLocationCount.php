<?php

namespace App;

enum OnboardingLocationCount: string
{
    case One = '1';
    case TwoToFive = '2-5';
    case SixToTen = '6-10';
    case ElevenToTwentyFive = '11-25';
    case TwentyFivePlus = '25+';
}
