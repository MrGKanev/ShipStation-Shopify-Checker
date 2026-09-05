<?php

namespace Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'label' => fake()->company(),
            'shopify_store' => fake()->unique()->domainWord(),
            'shopify_access_token' => 'shpat_'.fake()->sha1(),
            'shipstation_api_key' => fake()->sha1(),
            'shipstation_api_secret' => fake()->sha1(),
            'store_number' => fake()->unique()->numerify('####'),
        ];
    }
}
