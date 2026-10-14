<?php

declare(strict_types=1);

namespace Pediacare\Domain\Enums;

enum NutritionStatus: string
{
    case SEVERELY_UNDERWEIGHT = 'Severely underweight';
    case UNDERWEIGHT = 'Underweight';
    case NORMAL = 'Normal';
    case HIGH_WEIGHT_FOR_AGE = 'High weight-for-age';
    case SEVERELY_STUNTED = 'Severely stunted';
    case STUNTED = 'Stunted';
    case TALL = 'Tall';
    case SEVERELY_WASTED = 'Severely wasted';
    case WASTED = 'Wasted';
    case OVERWEIGHT = 'Overweight';
    case OBESE = 'Obese';
    case SEVERE_THINNESS = 'Severe thinness';
    case THINNESS = 'Thinness';
}
