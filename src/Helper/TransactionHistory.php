<?php

namespace Cube\Helper;

class TransactionHistory
{
    public const ACTION_INVOICE = 'invoice';
    public const ACTION_CLAIM = 'claim';
    public const ACTION_CONFIRM = 'confirm';
    public const ACTION_CANCEL = 'cancel';
    public const ACTION_PURCHASE = 'purchase';

    public static function getRecords(): array
    {
        $raw = [];
        $file = FileHelper::getTransactionHistoryFile();
        if (file_exists($file)) {
            $raw = json_decode(file_get_contents($file), true);
            if (!is_array($raw)) {
                $raw = [];
            }
        }

        return $raw;
    }

    public static function update(string $orderId, string $action, ?bool $status = null, ?string $error = null)
    {
        $raw = self::getRecords();

        $record = $raw[$orderId] ?? null;
        if (!$record) {
            $record = [
                'orderId' => $orderId,
                'date' => date('Y-m-d'),
                self::ACTION_INVOICE => null,
                self::ACTION_CLAIM => null,
                self::ACTION_CONFIRM => null,
                self::ACTION_CANCEL => null,
                self::ACTION_PURCHASE => null,
                'error' => null,
                'history' => [],
            ];
        }

        $actionResult = $record[$action] ?? null;
        // Only write to current object if it not already set for this action
        // if ($actionResult === null) {
            $record[$action] = $status;

            if ($error) {
                $record['error'] = $action . ': ' . $error;
            }
        // }
        // Add to history
        $record['history'][] = ['orderId' => $orderId, 'action' => $action, 'status' => $status, 'error' => $error];

        $raw[$orderId] = $record;
        file_put_contents(FileHelper::getTransactionHistoryFile(), json_encode($raw));
    }

    /**
     * Check if this API call (action) has been performed successfully before for this orderId.
     * @param string $orderId
     * @param string $action
     * @return bool
     */
    public static function wasActionSuccessful($orderId, string $action): bool
    {
        $raw = self::getRecords();
        $record = $raw[$orderId] ?? null;
        if (!$record) {
            return false;
        }
        $history = array_filter($record['history'], fn($it) => $it['action'] === $action);
        if (!$history) {
            return false;
        }

        return count($history) === 1 && ($history[0]['status'] ?? false) === true;
    }
}