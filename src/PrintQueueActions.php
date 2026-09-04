<?php
declare(strict_types=1);

final class PrintQueueActions
{
    public static function add(): never
    {
        $number = trim($_POST['pq_order_number'] ?? '');
        if ($number === '') {
            $_SESSION['_flash']['pq_error'] = 'Order number cannot be empty.';
            header('Location: ?page=printqueue');
            exit;
        }
        PrintQueue::add($number, trim($_POST['pq_note'] ?? ''));
        UserActionLog::append('pq_add', ['order_number' => $number]);
        $_SESSION['_flash']['pq_message'] = "Order #{$number} added to the print queue.";
        header('Location: ?page=printqueue');
        exit;
    }

    public static function remove(): never
    {
        $number = trim($_POST['pq_order_number'] ?? '');
        PrintQueue::remove($number);
        UserActionLog::append('pq_remove', ['order_number' => $number]);
        $_SESSION['_flash']['pq_message'] = "Order #{$number} removed from the queue.";
        header('Location: ?page=printqueue');
        exit;
    }

    public static function clear(): never
    {
        $count = count(PrintQueue::all());
        PrintQueue::clear();
        UserActionLog::append('pq_clear', ['count' => $count]);
        $_SESSION['_flash']['pq_message'] = 'Print queue cleared.';
        header('Location: ?page=printqueue');
        exit;
    }
}
