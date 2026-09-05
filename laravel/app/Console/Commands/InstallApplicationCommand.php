<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\User;
use App\UserRole;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('ops:install')]
#[Description('Create the first administrator and store on a fresh database')]
class InstallApplicationCommand extends Command
{
    public function handle(): int
    {
        if (User::query()->exists() || Store::query()->exists()) {
            $this->error('Installation is only available on an empty database.');

            return self::FAILURE;
        }

        $data = [
            'name' => (string) $this->ask('Administrator name'),
            'email' => Str::lower(trim((string) $this->ask('Administrator email'))),
            'password' => (string) $this->secret('Administrator password'),
            'password_confirmation' => (string) $this->secret('Confirm administrator password'),
            'label' => (string) $this->ask('Store name'),
            'slug' => Str::lower(trim((string) $this->ask('Store slug'))),
            'shopify_store' => Str::of((string) $this->ask('Shopify store'))
                ->trim()
                ->lower()
                ->replaceEnd('.myshopify.com', '')
                ->toString(),
            'shopify_access_token' => (string) $this->secret('Shopify access token'),
            'shipstation_api_key' => $this->optionalSecret('ShipStation API key (optional)'),
            'shipstation_api_secret' => $this->optionalSecret('ShipStation API secret (optional)'),
            'store_number' => $this->optionalAnswer('ShipStation store number (optional)'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'label' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:stores,slug'],
            'shopify_store' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:stores,shopify_store'],
            'shopify_access_token' => ['required', 'string', 'max:2048'],
            'shipstation_api_key' => ['nullable', 'string', 'max:2048'],
            'shipstation_api_secret' => ['nullable', 'string', 'max:2048'],
            'store_number' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::INVALID;
        }

        DB::transaction(function () use ($data): void {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => UserRole::Admin,
            ]);

            $store = Store::create([
                'label' => $data['label'],
                'slug' => $data['slug'],
                'shopify_store' => $data['shopify_store'],
                'shopify_access_token' => $data['shopify_access_token'],
                'shipstation_api_key' => $data['shipstation_api_key'],
                'shipstation_api_secret' => $data['shipstation_api_secret'],
                'store_number' => $data['store_number'],
            ]);

            $user->stores()->attach($store);
        });

        $this->info('Installation complete. You can now sign in with the administrator account.');

        return self::SUCCESS;
    }

    private function optionalSecret(string $question): ?string
    {
        $value = trim((string) $this->secret($question));

        return $value === '' ? null : $value;
    }

    private function optionalAnswer(string $question): ?string
    {
        $value = trim((string) $this->ask($question));

        return $value === '' ? null : $value;
    }
}
