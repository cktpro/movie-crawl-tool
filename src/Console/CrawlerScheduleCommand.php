<?php

namespace Movie\Crawler\MovieCrawler\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Movie\Crawler\MovieCrawler\Crawler;
use Movie\Crawler\MovieCrawler\Option;

class CrawlerScheduleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'movie:plugins:movie-crawler:schedule';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crawler movie schedule command';

    protected $logger;
    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->logger = Log::channel('movie-crawler');
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if(!$this->checkCrawlerScheduleEnable()) return 0;
        $link = sprintf('%s/api/films/danh-sach/hoat-hinh', 'https://phim.nguonc.com');
        $data = collect();
        $page_from = Option::get('crawler_schedule_page_from', 1);
        $page_to = Option::get('crawler_schedule_page_to', 2);
        $this->logger->notice(sprintf("Crawler Page (FROM: %d | TO: %d)",  $page_from, $page_to));
        for ($i = $page_from; $i <= $page_to; $i++) {
            if(!$this->checkCrawlerScheduleEnable()) {
                $this->logger->notice(sprintf("Stop Crawler Page"));
                return 0;
            }
            $response = json_decode(Http::timeout(30)->get($link, [
                'page' => $i
            ]), true);
            // Guard bằng ?? / !empty: đoạn này nằm NGOÀI try/catch của vòng lặp phim,
            // nên API trả về payload lỗi (thiếu 'status' hoặc 'items') là ném
            // ErrorException và chết cả lượt cron chứ không chỉ bỏ qua một trang.
            if (($response['status'] ?? false) && ! empty($response['items'])) {
                $data->push(...$response['items']);
            }
        }
        $movies = $data->shuffle();
        $count_movies = count($movies);
        $this->logger->notice(sprintf("Start Crawler Movies (TOTAL: %d)",  $count_movies));
        $count_error = 0;
        foreach ($movies as $key => $movie) {
            try {
                if(!$this->checkCrawlerScheduleEnable()) {
                    $this->logger->notice(sprintf("Stop Crawler Movies (TOTAL: %d | CRAWED: %d | ERROR %d)", $count_movies, $key, $count_error));
                    return 0;
                }
                // $link = sprintf('%s/api/film/%s', Option::get('domain'), $movie['slug']);
                $link = sprintf('%s/api/film/%s', "https://phim.nguonc.com", $movie['slug']);
                $crawler = (new Crawler(
                    $link,
                    Option::get('crawler_schedule_fields', Option::getAllOptions()['crawler_schedule_fields']['default']),
                    Option::get('crawler_schedule_excludedCategories', []),
                    Option::get('crawler_schedule_excludedRegions', []),
                    Option::get('crawler_schedule_excludedType', []),
                    false))
                    ->handle_nguonc();
            } catch (\Exception $e) {
                $this->logger->error(sprintf("%s ERROR: %s", $movie['slug'], $e->getMessage()));
                $count_error++;
            }
        }
        $this->logger->notice(sprintf("Finish Crawler Movies (TOTAL: %d | DONE: %d | ERROR: %d)", $count_movies, $count_movies - $count_error, $count_error));
        return 0;
    }

    public function checkCrawlerScheduleEnable()
    {
        return Option::get('crawler_schedule_enable', false);
    }
}
