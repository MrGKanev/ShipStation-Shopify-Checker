<?php

namespace Tests\Feature\Models;

use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_integration_credentials_are_encrypted_and_hidden_from_serialization(): void
    {
        $store = Store::factory()->create([
            'shopify_access_token' => 'shopify-secret',
            'shipstation_api_key' => 'shipstation-key',
            'shipstation_api_secret' => 'shipstation-secret',
        ]);

        $storedToken = DB::table('stores')
            ->where('id', $store->getKey())
            ->value('shopify_access_token');
        $freshStore = $store->fresh();
        $serializedStore = $freshStore->toArray();

        $this->assertNotSame('shopify-secret', $storedToken);
        $this->assertSame('shopify-secret', $freshStore->shopify_access_token);
        $this->assertArrayNotHasKey('shopify_access_token', $serializedStore);
        $this->assertArrayNotHasKey('shipstation_api_key', $serializedStore);
        $this->assertArrayNotHasKey('shipstation_api_secret', $serializedStore);
    }
}
