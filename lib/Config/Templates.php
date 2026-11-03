<?php

namespace MyApp\Catalog\Config;

final class Templates
{
    public const VIEWS_BASE = '/local/templates/main/views/';

    public static function viewPath(string $relative): string
    {
        return self::VIEWS_BASE . ltrim($relative, '/');
    }
}
