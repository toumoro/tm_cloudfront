<?php

use Toumoro\TmCloudfront\Middleware\CacheTagHeaderMiddleware;

return [
    'frontend' => [
        // Must be inside the cache tag collector middleware to read the tags of the rendered/cached page
        'toumoro/tm-cloudfront/cache-tag-header' => [
            'target' => CacheTagHeaderMiddleware::class,
            'after' => [
                'typo3/cms-core/cache-tags-attribute',
                'typo3/cms-frontend/site',
            ],
        ],
    ],
];
