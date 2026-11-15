<?php

/**
 * Runs offline, without WordPress: measure and shorten text the way
 * meta-writer does before a suggestion reaches the review screen.
 *
 *     composer install
 *     php examples/fit-and-measure.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use IndianOpinion\MetaWriter\Field;
use IndianOpinion\MetaWriter\Text\Fitter;
use IndianOpinion\MetaWriter\Text\Length;
use IndianOpinion\MetaWriter\Text\ScriptCheck;

$samples = [
    ['title', 'Title: "Heavy monsoon rain floods low lying districts across the southern coast today"'],
    ['description', 'Farmers in several districts welcomed the heavy rain, officials said, and relief camps opened along the coast for families whose homes were flooded overnight.'],
    ['description', 'मानसून इस साल केरल में तीन दिन पहले पहुंचा। कई जिलों में भारी बारिश हुई और किसानों को राहत मिली। मौसम विभाग ने अगले सप्ताह और बारिश की संभावना जताई है।'],
];

foreach ($samples as [$field, $text]) {
    [$min, $max] = $field === Field::TITLE ? [30, 60] : [70, 155];
    $fit = Fitter::fit($text, $min, $max, $field);
    printf(
        "%s: %d bytes, %d characters in, %d out, ok=%s, trimmed=%s\n  %s\n\n",
        $field,
        strlen($text),
        Length::measure($text),
        $fit->length,
        $fit->ok ? 'yes' : 'no (' . $fit->reason . ')',
        $fit->trimmed ? 'yes' : 'no',
        $fit->text,
    );
}

printf("Expected script for hi-IN: %s; share of Devanagari in a Latin title: %.2f\n", ScriptCheck::expectedScript('hi-IN'), ScriptCheck::share('Monsoon arrives early', ScriptCheck::DEVANAGARI));
