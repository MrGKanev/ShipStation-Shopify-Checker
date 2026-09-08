<?php

namespace Tests\Feature\Console\Commands;

use App\Models\Store;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class InstallApplicationCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_command_creates_the_first_administrator_and_store(): void
    {
        $this->installationCommand('secure-password', 'secure-password')
            ->expectsOutput('Installation complete. You can now sign in with the administrator account.')
            ->assertSuccessful();

        $user = User::query()->sole();
        $store = Store::query()->sole();
        $this->assertSame('admin@example.com', $user->email);
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue(Hash::check('secure-password', $user->password));
        $this->assertSame('example-shop', $store->shopify_store);
        $this->assertSame('shopify-token', $store->shopify_access_token);
        $this->assertTrue($user->stores()->whereKey($store->getKey())->exists());
    }

    public function test_command_refuses_to_change_a_nonempty_installation(): void
    {
        User::factory()->create();

        $this->artisan('ops:install')
            ->expectsOutput('Installation is only available on an empty database.')
            ->assertFailed();

        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, Store::query()->count());
    }

    public function test_command_does_not_persist_an_invalid_installation(): void
    {
        $this->installationCommand('short', 'different')
            ->expectsOutputToContain('password')
            ->assertExitCode(2);

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Store::query()->count());
    }

    private function installationCommand(string $password, string $passwordConfirmation): PendingCommand
    {
        $command = $this->artisan('ops:install');

        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command
            ->expectsQuestion('Administrator name', 'System Admin')
            ->expectsQuestion('Administrator email', ' ADMIN@EXAMPLE.COM ')
            ->expectsQuestion('Administrator password', $password)
            ->expectsQuestion('Confirm administrator password', $passwordConfirmation)
            ->expectsQuestion('Store name', 'Example Store')
            ->expectsQuestion('Store slug', ' Example-Store ')
            ->expectsQuestion('Shopify store', 'Example-Shop.myshopify.com')
            ->expectsQuestion('Shopify access token', 'shopify-token')
            ->expectsQuestion('ShipStation API key (optional)', '')
            ->expectsQuestion('ShipStation API secret (optional)', '')
            ->expectsQuestion('ShipStation store number (optional)', '');
    }
}
