<?php

declare(strict_types=1);

/*
 * The news the tips can be about: pages of motoring clubs, consumer
 * organisations, authorities and magazines, read with URL context (which the
 * free tier of Gemini allows; Google Search it does not), and the recalls of
 * the EU as open data. No RSS for now: a source has a type, so that one of
 * the type "rss" can be added later without changing the rest.
 */
return [
    // The same free tier as the tips, and the same models.
    'provider' => env('VEHICLE_NEWS_PROVIDER', env('VEHICLE_ADVICE_PROVIDER', 'gemini')),
    'model' => env('VEHICLE_NEWS_MODEL', 'gemini-3.5-flash'),
    'fallback_model' => env('VEHICLE_NEWS_FALLBACK_MODEL', 'gemini-3.1-flash-lite'),

    // Seconds to wait for an answer: reading pages takes a while.
    'timeout' => 120,

    // Seconds between two requests, to stay within the requests per minute of the free tier.
    'pause_between' => 6,

    // Pages read in one request, at most: URL context reads up to 20.
    'pages_per_request' => 20,

    // Days an article counts for the tips: older news is no news.
    'max_age_days' => 120,

    // Articles of a vehicle the model gets to see with its facts, at most: the most recent.
    'per_vehicle' => 8,

    // Tips based on the news per advice, at most: they should not crowd out the maintenance.
    'tips' => 2,

    // What an article can be about (see VehicleNewsReader).
    'kinds' => [
        'known_issue' => 'a problem of a make, model or engine that comes to light, a technical service bulletin or a software update',
        'recall' => 'a recall, a goodwill or extended warranty for a known defect, or a claim',
        'reliability' => 'a long-term test, a reliability survey or breakdown statistics of models',
        'product_test' => 'a test of tires, batteries, care products or other products for a vehicle',
        'saving' => 'saving costs or making a vehicle last longer, in a way specific to a kind of vehicle (its powertrain, age, mileage or the season)',
        'rules' => 'a change of rules or costs: road tax, MOT, environmental zones, toll, vignettes, insurance',
        'value' => 'the residual value of models on the used market',
    ],

    // The pages to read, per country of registration (the slugs of config/countries.php).
    // Checked on 2026-11-26: each answered with 200.
    'sources' => [
        'netherlands' => [
            ['type' => 'page', 'url' => 'https://www.rdw.nl/nieuws'],
            ['type' => 'page', 'url' => 'https://www.rijksoverheid.nl/onderwerpen/auto'],
            ['type' => 'page', 'url' => 'https://www.anwb.nl/auto/nieuws'],
            ['type' => 'page', 'url' => 'https://www.anwb.nl/auto/onderhoud-en-reparatie'],
            ['type' => 'page', 'url' => 'https://www.autoweek.nl/autonieuws/'],
            ['type' => 'page', 'url' => 'https://www.consumentenbond.nl/auto'],
            ['type' => 'page', 'url' => 'https://www.kassa.nl/onderwerp/auto'],
        ],
        'belgium' => [
            ['type' => 'page', 'url' => 'https://mobilit.belgium.be/nl/nieuws'],
            ['type' => 'page', 'url' => 'https://www.vab.be/nl/nieuws'],
            ['type' => 'page', 'url' => 'https://www.touring.be/nl/artikels'],
            ['type' => 'page', 'url' => 'https://www.gocar.be/nl/autonieuws'],
        ],
        'germany' => [
            ['type' => 'page', 'url' => 'https://www.kba.de/DE/Presse/Pressemitteilungen/pressemitteilungen_node.html'],
            ['type' => 'page', 'url' => 'https://www.adac.de/news/'],
            ['type' => 'page', 'url' => 'https://www.adac.de/rund-ums-fahrzeug/tests/reifen/'],
            ['type' => 'page', 'url' => 'https://www.adac.de/rund-ums-fahrzeug/unfall-schaden-panne/adac-pannenstatistik/'],
            ['type' => 'page', 'url' => 'https://www.auto-motor-und-sport.de/news/'],
            ['type' => 'page', 'url' => 'https://www.autobild.de/news/'],
        ],
        'austria' => [
            ['type' => 'page', 'url' => 'https://www.oeamtc.at/thema/'],
            ['type' => 'page', 'url' => 'https://www.adac.de/rund-ums-fahrzeug/tests/reifen/'],
        ],
        'luxembourg' => [
            ['type' => 'page', 'url' => 'https://www.acl.lu/'],
        ],
        'france' => [
            ['type' => 'page', 'url' => 'https://www.securite-routiere.gouv.fr/actualites'],
            ['type' => 'page', 'url' => 'https://www.quechoisir.org/'],
            ['type' => 'page', 'url' => 'https://www.caradisiac.com/'],
            ['type' => 'page', 'url' => 'https://www.autoplus.fr/actualite'],
        ],
        'italy' => [
            ['type' => 'page', 'url' => 'https://www.mit.gov.it/comunicazione/news'],
            ['type' => 'page', 'url' => 'https://www.aci.it/'],
            ['type' => 'page', 'url' => 'https://www.altroconsumo.it/auto-e-moto'],
            ['type' => 'page', 'url' => 'https://www.quattroruote.it/news/'],
        ],
        'spain' => [
            ['type' => 'page', 'url' => 'https://www.dgt.es/comunicacion/notas-de-prensa/'],
            ['type' => 'page', 'url' => 'https://www.ocu.org/coches'],
            ['type' => 'page', 'url' => 'https://www.motorpasion.com/'],
        ],
    ],

    // Pages per model whose address follows from the make and the model: read
    // for every vehicle, whatever its country, once they answer (checked
    // before use, see VehicleNewsService::modelPages()). {brand} and {model}
    // are slugs; some makes have a slug of their own.
    'model_pages' => [
        [
            'url' => 'https://www.adac.de/rund-ums-fahrzeug/autokatalog/marken-modelle/{brand}/{model}/',
            'brands' => ['volkswagen' => 'vw'],
        ],
    ],

    // Days a model page that answered, or did not, is not checked again.
    'model_page_checked_for_days' => 30,

    // The recalls of cars in the EU (Safety Gate) as open data, via RappelConso of France.
    'recalls' => [
        'url' => 'https://data.economie.gouv.fr/api/explore/v2.1/catalog/datasets/rappelconso-v2-gtin-espaces/records',
        'subcategory' => 'automobiles, motos, scooters',
        'per_brand' => 20,
    ],
];
