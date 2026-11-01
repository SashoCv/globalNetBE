<?php

namespace App\Models\Concerns;

/**
 * Stores JSON columns with Cyrillic as-is instead of \u escapes, so a LIKE
 * search on them (e.g. a municipality's aliases) finds Cyrillic text.
 */
trait StoresUnicodeJson
{
    protected function asJson($value, $flags = 0)
    {
        return parent::asJson($value, $flags | JSON_UNESCAPED_UNICODE);
    }
}
