<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DatasetBuilder\Dto;

final class TaxonGroupEntry
{
    public string $name = '';

    /** @var TaxonGroupChildEntry[] */
    public array $children = [];
}
