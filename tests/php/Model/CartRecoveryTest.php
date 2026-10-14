<?php

declare(strict_types=1);

namespace SilverShop\AbandonedCart\Tests\Model;

use SilverShop\AbandonedCart\Model\CartRecovery;
use SilverStripe\Dev\SapphireTest;

class CartRecoveryTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testTokenAndDefaultsGeneratedOnWrite(): void
    {
        $recovery = CartRecovery::create();
        $recovery->Email = 'Guest@Example.com';
        $recovery->write();

        $this->assertNotEmpty($recovery->Token, 'A token is minted on first write.');
        $this->assertSame(32, strlen((string) $recovery->Token), 'bin2hex(random_bytes(16)) is 32 hex chars.');
        $this->assertSame(CartRecovery::STATUS_ACTIVE, $recovery->Status, 'Status defaults to Active.');
        $this->assertSame(CartRecovery::STEP_NONE, (int) $recovery->LastStepSent, 'LastStepSent defaults to the -1 sentinel.');
        $this->assertSame('guest@example.com', $recovery->Email, 'Email is lower-cased.');
    }

    public function testTerminalStatesNullTheTokenAndItIsNotRegenerated(): void
    {
        $recovery = CartRecovery::create();
        $recovery->Email = 'x@example.com';
        $recovery->write();
        $this->assertNotEmpty($recovery->Token);

        $recovery->markUnsubscribed();
        $recovery->write();

        $this->assertSame(CartRecovery::STATUS_UNSUBSCRIBED, $recovery->Status);
        $this->assertEmpty($recovery->Token, 'Going terminal nulls the token.');

        // A subsequent write must NOT resurrect a token on a terminal row.
        $recovery->write();
        $this->assertEmpty($recovery->Token, 'A terminal row never regains a token.');
    }

    public function testMarkRecoveredStampsValueAndKillsToken(): void
    {
        $recovery = CartRecovery::create();
        $recovery->Email = 'x@example.com';
        $recovery->write();

        $recovery->markRecovered(49.90);
        $recovery->write();

        $this->assertSame(CartRecovery::STATUS_RECOVERED, $recovery->Status);
        $this->assertEqualsWithDelta(49.90, (float) $recovery->RecoveredValue, 0.001);
        $this->assertNotEmpty($recovery->RecoveredAt);
        $this->assertEmpty($recovery->Token);
        $this->assertTrue($recovery->isTerminal());
        $this->assertFalse($recovery->isActive());
    }

    public function testMaskEmail(): void
    {
        $this->assertSame('m•••@example.com', CartRecovery::maskEmail('member@example.com'));
        $this->assertSame('', CartRecovery::maskEmail(''));
        $this->assertSame('•••', CartRecovery::maskEmail('@broken'));
    }

    public function testSnapshotAccessors(): void
    {
        $recovery = CartRecovery::create();
        $recovery->Email = 'x@example.com';
        $recovery->CartData = (string) json_encode([
            'Total' => 37.50,
            'AbandonedAt' => '2026-01-02 03:04:05',
            'Items' => [
                ['BuyableClass' => 'Foo', 'BuyableID' => 1, 'Quantity' => 2, 'Title' => 'Widget', 'UnitPrice' => 12.50],
                ['BuyableClass' => 'Foo', 'BuyableID' => 2, 'Quantity' => 1, 'Title' => 'Gadget', 'UnitPrice' => 12.50],
            ],
        ]);
        $recovery->write();

        $this->assertSame(2, $recovery->CartItems()->count(), 'Each snapshot line becomes a display row.');
        $this->assertSame(3, $recovery->getItemCount(), 'Item count sums the line quantities.');
        $this->assertEqualsWithDelta(37.50, (float) $recovery->getCartTotal()->getValue(), 0.001);
        $this->assertSame('2026-01-02 03:04:05', $recovery->getAbandonedAt());

        $first = $recovery->CartItems()->first();
        $this->assertSame('Widget', (string) $first->Title);
        $this->assertEqualsWithDelta(25.0, (float) $first->LineTotal->getValue(), 0.001, 'LineTotal = unit * qty.');
    }
}
