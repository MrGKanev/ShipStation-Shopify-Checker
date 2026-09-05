<?php

namespace App\Integrations\ShipStation;

use App\Models\Store;
use LogicException;

class ShipStationClientFactory
{
    public function forStore(Store $store): ?ShipStationClientContract
    {
        $apiKey = trim((string) $store->shipstation_api_key);
        $apiSecret = trim((string) $store->shipstation_api_secret);

        if ($apiKey === '' && $apiSecret === '') {
            return null;
        }

        if ($apiKey === '' || $apiSecret === '') {
            throw new LogicException('The ShipStation credentials are incomplete.');
        }

        return new ShipStationClient($apiKey, $apiSecret);
    }
}
