<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DatasetBuilder;

const AI_CATALOG_SAMPLE_SIZE = 300;
const HTML_MAX_LENGTH = 120_000;
const IMPORT_AI_PRODUCT_LIMIT = 20;
// Products kept from a catalog fetched from a website.
const IMPORT_FETCHED_PRODUCT_LIMIT = 100;
const IMPORT_PRICE_EUR_MIN = 3.0;
const IMPORT_PRICE_EUR_FALLBACK = 19.99;
const IMPORT_PROMO_PRODUCT_COUNT = 5;
const IMPORT_PROMO_ORIGINAL_PRICE_RATIO = 1.33;
const IMPORT_PLATFORM_SHOPIFY_THRESHOLD = 3;
const IMPORT_COLLECTION_AI_THRESHOLD = 3;
// Above this many collections holding products, AI groups them into a two-level menu (e.g. Men > T-shirts).
const IMPORT_TAXON_GROUPING_THRESHOLD = 8;
const IMPORT_TAXON_GROUP_LIMIT = 6;
const IMPORT_TAXON_GROUP_CHILD_LIMIT = 8;
const IMPORT_LOCALE_PREFERENCE = ['fr', 'en', 'es', 'de', 'it', 'nl'];
const IMPORT_IMAGE_MAX_DIMENSION = 2048;
