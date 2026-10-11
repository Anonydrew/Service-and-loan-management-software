<?php

namespace App\Http\Controllers;

use App\Support\Money;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function perPage(Request $request): int
    {
        return max(1, min(100, $request->integer('per_page', 20)));
    }

    /** Converts validated major-unit amount fields (e.g. "1500.50") to *_minor integers. */
    protected function minor(array $data, array $fields): array
    {
        foreach ($fields as $field => $target) {
            if (array_key_exists($field, $data)) {
                $data[$target] = $data[$field] === null ? null : Money::toMinor($data[$field]);
                unset($data[$field]);
            }
        }

        return $data;
    }
}
