<?php

namespace Movie\Crawler\MovieCrawler;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as SP;
use Movie\Crawler\MovieCrawler\Console\CleanupOrphanImagesCommand;
use Movie\Crawler\MovieCrawler\Console\CrawlerScheduleCommand;
use Movie\Crawler\MovieCrawler\Option;

class MovieCrawlerServiceProvider extends SP
{
    /**
     * Get the policies defined on the provider.
     *
     * @return array
     */
    public function policies()
    {
        return [];
    }

    public function register()
    {

        config(['plugins' => array_merge(config('plugins', []), [
            'hacoidev/movie-crawler' =>
            [
                'name' => 'Movie Crawler',
                'package_name' => 'hacoidev/movie-crawler',
                'icon' => 'la la-hand-grab-o',
                'entries' => [
                    ['name' => 'Crawler', 'icon' => 'la la-hand-grab-o', 'url' => backpack_url('/plugin/movie-crawler')],
                    ['name' => 'Crawler Nguonc', 'icon' => 'la la-hand-grab-o', 'url' => backpack_url('/plugin/nguonc-crawler')],
                    ['name' => 'Option', 'icon' => 'la la-cog', 'url' => backpack_url('/plugin/movie-crawler/options')],
                    ['name' => 'Dọn ảnh rác', 'icon' => 'la la-trash-o', 'url' => backpack_url('/plugin/movie-crawler/images-cleanup')],
                ],
            ]
        ])]);

        config(['logging.channels' => array_merge(config('logging.channels', []), [
            'movie-crawler' => [
                'driver' => 'daily',
                'path' => storage_path('logs/hacoidev/movie-crawler.log'),
                'level' => env('LOG_LEVEL', 'debug'),
                'days' => 7,
            ],
        ])]);

        config(['movie.updaters' => array_merge(config('movie.updaters', []), [
            [
                'name' => 'Movie Crawler',
                'handler' => 'Movie\Crawler\MovieCrawler\Crawler'
            ]
        ])]);
    }

    public function boot()
    {
        $this->commands([
            CrawlerScheduleCommand::class,
            CleanupOrphanImagesCommand::class,
        ]);

        $this->app->booted(function () {
            $this->loadScheduler();
        });

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'movie-crawler');
    }

    protected function loadScheduler()
    {
        $schedule = $this->app->make(Schedule::class);
        $schedule->command('movie:plugins:movie-crawler:schedule')->cron(Option::get('crawler_schedule_cron_config', '*/10 * * * *'))->withoutOverlapping();
    }
}
