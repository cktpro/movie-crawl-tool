<?php

namespace Movie\Crawler\MovieCrawler\Controllers;


use Backpack\CRUD\app\Http\Controllers\CrudController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Movie\Crawler\MovieCrawler\Crawler;
use Movie\Core\Models\Movie;

/**
 * Class CrawlController
 * @package Movie\Crawler\MovieCrawler\Controllers
 * @property-read \Backpack\CRUD\app\Library\CrudPanel\CrudPanel $crud
 */
class CrawlController extends CrudController
{
    // Fetch Nguonc
    public function fetch_nguonc(Request $request)
    {
        try {
            $data = collect();

            $request['link'] = preg_split('/[\n\r]+/', $request['link']);

            foreach ($request['link'] as $link) {
                if (preg_match('/(.*?)(\/phim\/)(.*?)/', $link)) {
                    $link = sprintf('%s/api/film/%s', config('movie_crawler.domain', 'https://phim.nguonc.com'), explode('phim/', $link)[1]);
                    $response = json_decode(file_get_contents($link), true);
                    $data->push(collect($response['movie'])->only('name', 'slug')->toArray());
                } else {
                    for ($i = $request['from']; $i <= $request['to']; $i++) {
                        $response = json_decode(Http::timeout(30)->get($link, [
                            'page' => $i
                        ]), true);
                        if ($response['status']) {
                            $data->push(...$response['items']);
                        }
                    }
                }
            }

            return $data->shuffle();
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
    // End Fetch Nguonc 
    public function fetch(Request $request)
    {
        try {
            $data = collect();

            $request['link'] = preg_split('/[\n\r]+/', $request['link']);

            foreach ($request['link'] as $link) {
                if (preg_match('/(.*?)(\/phim\/)(.*?)/', $link)) {
                    $link = sprintf('%s/phim/%s', config('movie_crawler.domain', 'https://ophim1.com'), explode('phim/', $link)[1]);
                    $response = json_decode(file_get_contents($link), true);
                    $data->push(collect($response['movie'])->only('name', 'slug')->toArray());
                } else {
                    for ($i = $request['from']; $i <= $request['to']; $i++) {
                        $response = json_decode(Http::timeout(30)->get($link, [
                            'page' => $i
                        ]), true);
                        if ($response['status']) {
                            $data->push(...$response['items']);
                        }
                    }
                }
            }

            return $data->shuffle();
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
// ShowCrawPage Nguonc
    public function showCrawlPageNguonc(Request $request)
    {
        $categories = [];
        $regions = [];
        try {
            $categories = Cache::remember('movie_categories', 86400, function () {
                $data = json_decode(file_get_contents(sprintf('%s/the-loai', config('movie_crawler.domain', 'https://ophim1.com'))), true) ?? [];
                return collect($data)->pluck('name', 'name')->toArray();
            });

            $regions = Cache::remember('movie_regions', 86400, function () {
                $data = json_decode(file_get_contents(sprintf('%s/quoc-gia', config('movie_crawler.domain', 'https://ophim1.com'))), true) ?? [];
                return collect($data)->pluck('name', 'name')->toArray();
            });
        } catch (\Throwable $th) {

        }

        $fields = $this->movieUpdateOptions();

        return view('movie-crawler::crawl_nguonc', compact('fields', 'regions', 'categories'));
    }
// End ShowCrawlPage Nguonc
    public function showCrawlPage(Request $request)
    {
        $categories = [];
        $regions = [];
        try {
            $categories = Cache::remember('movie_categories', 86400, function () {
                $data = json_decode(file_get_contents(sprintf('%s/the-loai', config('movie_crawler.domain', 'https://ophim1.com'))), true) ?? [];
                return collect($data)->pluck('name', 'name')->toArray();
            });

            $regions = Cache::remember('movie_regions', 86400, function () {
                $data = json_decode(file_get_contents(sprintf('%s/quoc-gia', config('movie_crawler.domain', 'https://ophim1.com'))), true) ?? [];
                return collect($data)->pluck('name', 'name')->toArray();
            });
        } catch (\Throwable $th) {

        }

        $fields = $this->movieUpdateOptions();

        return view('movie-crawler::crawl', compact('fields', 'regions', 'categories'));
    }
    // Crawl Nguonc
    public function crawl_nguonc(Request $request)
    {
        $pattern = sprintf('%s/api/film/{slug}', config('movie_crawler.domain', 'https://phim.nguonc.com'));
        try {
            $link = str_replace('{slug}', $request['slug'], $pattern);
            $crawler = (new Crawler($link, request('fields', []), request('excludedCategories', []), request('excludedRegions', []), request('excludedType', []), request('forceUpdate', false)))->handle_nguonc();
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage(), 'wait' => false], 500);
        }
        return response()->json(['message' => 'OK', 'wait' => $crawler ?? true]);
    }
    // End Crawl Nguonc
    public function crawl(Request $request)
    {
        $pattern = sprintf('%s/phim/{slug}', config('movie_crawler.domain', 'https://ophim1.com'));
        try {
            $link = str_replace('{slug}', $request['slug'], $pattern);
            $crawler = (new Crawler($link, request('fields', []), request('excludedCategories', []), request('excludedRegions', []), request('excludedType', []), request('forceUpdate', false)))->handle();
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage(), 'wait' => false], 500);
        }
        return response()->json(['message' => 'OK', 'wait' => $crawler ?? true]);
    }

    protected function movieUpdateOptions(): array
    {
        return [
            'Tiến độ phim' => [
                'episodes' => 'Tập mới',
                'status' => 'Trạng thái phim',
                'episode_time' => 'Thời lượng tập phim',
                'episode_current' => 'Số tập phim hiện tại',
                'episode_total' => 'Tổng số tập phim',
            ],
            'Thông tin phim' => [
                'name' => 'Tên phim',
                'origin_name' => 'Tên gốc phim',
                'content' => 'Mô tả nội dung phim',
                'thumb_url' => 'Ảnh Thumb',
                'poster_url' => 'Ảnh Poster',
                'trailer_url' => 'Trailer URL',
                'quality' => 'Chất lượng phim',
                'language' => 'Ngôn ngữ',
                'notify' => 'Nội dung thông báo',
                'showtimes' => 'Giờ chiếu phim',
                'publish_year' => 'Năm xuất bản',
                'is_copyright' => 'Đánh dấu có bản quyền',
            ],
            'Phân loại' => [
                'type' => 'Định dạng phim',
                'is_shown_in_theater' => 'Đánh dấu phim chiếu rạp',
                'actors' => 'Diễn viên',
                'directors' => 'Đạo diễn',
                'categories' => 'Thể loại',
                'regions' => 'Khu vực',
                'tags' => 'Từ khóa',
                'studios' => 'Studio',
            ]
        ];
    }

    public function getMoviesFromParams(Request $request) {
        $field = explode('-', request('params'))[0];
        $val = explode('-', request('params'))[1];
        if (!$val) {
            return Movie::where($field, $val)->orWhere($field, 'like', '%.com%')->orWhere($field, NULL)->get();
        } else {
            return Movie::where($field, $val)->get();
        }
    }
}
