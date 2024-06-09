<?php

namespace App\Console\Commands;
use Illuminate\Console\Command;
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class HandleOrderTimeout extends Command
{
    protected $signature = 'handle:order-timeout {orderId} {--delay=0}';
    protected $description = 'Handle order status update to TIMEOUT after a delay';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $orderId = $this->argument('orderId');
        $delay = $this->option('delay');

        // Delay execution if specified
        if ($delay > 0) {
            sleep($delay);
        }

        $order = Order::find($orderId);

        if ($order && !in_array($order->status, ['DONE', 'REJECTED', 'ACCEPTED'])) {
            $order->status = 'TIMEOUT';
            $order->save();
            Log::info("Order status updated to TIMEOUT for order ID: {$orderId}");
        }

        return 0;
    }
}

