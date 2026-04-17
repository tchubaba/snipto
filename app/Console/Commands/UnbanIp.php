<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class UnbanIp extends Command
{
    protected $signature = 'snipto:unban
                            {ip : The IP address to unban}
                            {--limiter= : Specific limiter name to clear (default: all)}';

    protected $description = 'Unban an IP address and reset it to tier 0 for all or a specific limiter';

    public function handle(): int
    {
        $ip      = $this->argument('ip');
        $limiter = $this->option('limiter');

        $limiters = $limiter
            ? [$limiter]
            : array_keys(config('rate_limiting.limiters', []));

        if (empty($limiters)) {
            $this->error('No limiters found in config/rate_limiting.php.');

            return self::FAILURE;
        }

        foreach ($limiters as $name) {
            foreach (['rl', 'window', 'breached', 'offense', 'lockout'] as $type) {
                Cache::forget("pt_{$type}:{$name}:{$ip}");
            }
            $this->line("  Cleared: {$name}");
        }

        $this->info("IP {$ip} has been unbanned and reset to tier 0.");

        return self::SUCCESS;
    }
}
