<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Http;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule)
    {
        // Check for scheduled blogs every minute
        $schedule->call(function () {
            // Make an API call to check scheduled blogs
            // You might want to create a dedicated command for this instead
            $url = config('app.url') . '/api/blogs/check-scheduled';
            
            // This is a simplified approach - in production you'd use proper authentication
            Http::withHeaders([
                'Accept' => 'application/json',
            ])->post($url);
        })->everyMinute();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
    protected function schedule(Schedule $schedule)
{
    // Check for overdue scheduled posts every 5 minutes
    $schedule->call(function () {
        app(\App\Http\Controllers\BlogController::class)->checkOverdueScheduledPosts();
    })->everyFiveMinutes();
}
}
