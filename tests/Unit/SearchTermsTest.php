<?php

namespace Tests\Unit;

use App\Shared\Support\SearchTerms;
use PHPUnit\Framework\TestCase;

class SearchTermsTest extends TestCase
{
    public function test_terms_match_in_any_order_with_partial_names_accents_and_spaces(): void
    {
        foreach (['Pablo Paredes', '  PAREDES   PÁBLO ', 'pab pared', '12345678'] as $search) {
            $this->assertTrue(SearchTerms::matches('PAREDES ZEGARRA PÁBLO 12345678', $search));
        }
        $this->assertFalse(SearchTerms::matches('PABLO PAREDES', 'PABLO OTRO'));
        $this->assertFalse(SearchTerms::matches('PABLO PAREDES', '%'));
        $this->assertTrue(SearchTerms::matches('CERRO VERDE MANTENIMIENTO', 'verde cerro'));
    }
}
