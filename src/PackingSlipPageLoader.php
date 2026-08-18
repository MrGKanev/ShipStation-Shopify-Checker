<?php
declare(strict_types=1);

/**
 * Loads ShipStation packing slip preview data.
 */
class PackingSlipPageLoader
{
    public static function load(string $action, array $ctx): array
    {
        $slipOrder = null;
        $slipInput = trim($_GET['order'] ?? '');
        $slipError = '';

        if ($err = self::requireSS($ctx)) {
            $slipError = $err;
            return compact('slipOrder', 'slipInput', 'slipError');
        }

        if ($action === 'packingslip') {
            $slipInput = trim($_POST['order_number'] ?? '');
            $clean     = ltrim($slipInput, '#');

            if ($clean === '') {
                $slipError = 'Enter an order number.';
            } else {
                try {
                    $ss     = new ShipStation($ctx['ssKey'], $ctx['ssSecret'], null, $ctx['httpStack'] ?? null);
                    $orders = $ss->findByOrderNumber($clean);
                    if (empty($orders)) {
                        $slipError = "Order #{$clean} not found in ShipStation.";
                    } else {
                        $slipOrder = $orders[0];
                    }
                } catch (Throwable $e) {
                    $slipError = 'Error: ' . $e->getMessage();
                }
            }
        }

        return compact('slipOrder', 'slipInput', 'slipError');
    }

    private static function requireSS(array $ctx): ?string
    {
        return (!$ctx['ssKey'] || !$ctx['ssSecret'])
            ? 'SS_API_KEY / SS_API_SECRET not set in .env.'
            : null;
    }
}
