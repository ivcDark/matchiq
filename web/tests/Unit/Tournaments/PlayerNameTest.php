<?php

namespace Tests\Unit\Tournaments;

use App\Domains\Tournaments\PlayerName;
use PHPUnit\Framework\TestCase;

class PlayerNameTest extends TestCase
{
    public function test_full_names_become_initial_and_surname(): void
    {
        $this->assertSame('A. Sobolev', PlayerName::short('Aleksandr Sobolev'));
        $this->assertSame('М. Глушенков', PlayerName::short('Мартин Глушенков'));
        $this->assertSame('J. Van Dijk', PlayerName::short('  jan   Van Dijk '));
        $this->assertSame('Ж. Анри', PlayerName::short('Жан-Поль Анри'));
    }

    public function test_already_compact_names_are_normalised_not_doubled(): void
    {
        $this->assertSame('А. Соболев', PlayerName::short('А. Соболев'));
        $this->assertSame('М. Глушенков', PlayerName::short('Глушенков М.'));
    }

    public function test_single_token_and_empty_names_are_safe(): void
    {
        $this->assertSame('Pelé', PlayerName::short('Pelé'));
        $this->assertSame('', PlayerName::short(''));
        $this->assertSame('', PlayerName::short('   '));
        $this->assertSame('', PlayerName::short(null));
    }
}
