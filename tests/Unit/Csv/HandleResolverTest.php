<?php

namespace Tests\Unit\Csv;

use App\Services\Csv\HandleResolver;
use PHPUnit\Framework\TestCase;

class HandleResolverTest extends TestCase
{
    public function test_codici_senza_collisione_ottengono_lo_slug_pulito(): void
    {
        $resolver = new HandleResolver;
        $resolver->add('00040EM286CEF');
        $resolver->add('000LP 0000');
        $resolver->build();

        $this->assertSame('00040em286cef', $resolver->resolve('00040EM286CEF'));
        $this->assertSame('000lp-0000', $resolver->resolve('000LP 0000'));
    }

    /**
     * Collisione reale osservata nel CSV: "56605 0000" e "56605-0000" producono
     * entrambi lo slug "56605-0000". Devono ricevere handle distinti e stabili.
     */
    public function test_codici_articolo_diversi_con_lo_stesso_slug_ricevono_handle_distinti(): void
    {
        $resolver = new HandleResolver;
        $resolver->add('56605 0000');
        $resolver->add('56605-0000');
        $resolver->build();

        $handleA = $resolver->resolve('56605 0000');
        $handleB = $resolver->resolve('56605-0000');

        $this->assertNotSame($handleA, $handleB);
        $this->assertStringStartsWith('56605-0000-', $handleA);
        $this->assertStringStartsWith('56605-0000-', $handleB);
    }

    public function test_la_risoluzione_e_deterministica_a_prescindere_dall_ordine_di_inserimento(): void
    {
        $first = new HandleResolver;
        $first->add('56605 0000');
        $first->add('56605-0000');
        $first->build();

        $second = new HandleResolver;
        $second->add('56605-0000');
        $second->add('56605 0000');
        $second->build();

        $this->assertSame($first->resolve('56605 0000'), $second->resolve('56605 0000'));
        $this->assertSame($first->resolve('56605-0000'), $second->resolve('56605-0000'));
    }

    public function test_collisions_restituisce_solo_gli_slug_condivisi(): void
    {
        $resolver = new HandleResolver;
        $resolver->add('56605 0000');
        $resolver->add('56605-0000');
        $resolver->add('00040EM286CEF');
        $resolver->build();

        $collisions = $resolver->collisions();

        $this->assertCount(1, $collisions);
        $this->assertArrayHasKey('56605-0000', $collisions);
    }

    public function test_resolve_senza_build_lancia_eccezione(): void
    {
        $resolver = new HandleResolver;
        $resolver->add('00040EM286CEF');

        $this->expectException(\RuntimeException::class);
        $resolver->resolve('00040EM286CEF');
    }
}
