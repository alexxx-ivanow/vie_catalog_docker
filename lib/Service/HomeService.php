<?php

namespace MyApp\Catalog\Service;

final class HomeService
{
    public static function getRootSections(): array
    {
        return CatalogService::getSections();
    }
}
