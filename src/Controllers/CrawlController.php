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
        [$categories, $regions] = $this->taxonomyNguonc();

        $fields = $this->movieUpdateOptions();

        return view('movie-crawler::crawl_nguonc', compact('fields', 'regions', 'categories'));
    }

    /**
     * Danh mục cho trang crawler định dạng nguonc.
     *
     * nguonc KHÔNG có API danh mục — đã dò: /api/quoc-gia và /api/films/quoc-gia đều
     * trả 404, còn /api/films/danh-sach/quoc-gia trả về DANH SÁCH PHIM chứ không phải
     * danh sách quốc gia. Danh mục chỉ xuất hiện trên menu trang chủ nguonc.
     *
     * Trước đây trang này nạp danh mục từ ophim1.com (giá trị mặc định của
     * config('movie_crawler.domain'), vốn luôn NULL vì không có file config nào), tức
     * lấy taxonomy của nguồn khác: 45 quốc gia của ophim trong khi nguonc chỉ có 16,
     * và thiếu hẳn "Âu Mỹ" / "Quốc gia khác" là hai mục chỉ nguonc mới có. Bộ lọc trừ
     * so khớp theo TÊN (array_intersect trong Crawler::checkIsInExcludedListNguonc)
     * nên tên lệch là loại trừ không có tác dụng.
     *
     * Vì vậy dùng danh sách tĩnh chép từ menu nguonc (https://phim.nguonc.com — mục
     * Thể loại và Quốc gia). Cố ý KHÔNG trộn thêm tên trong bảng categories/regions của
     * DB: bộ lọc trừ so khớp với tên do chính nguonc trả về trong payload, còn tên trong
     * DB là tổng hợp của mọi nguồn (chủ yếu ophim) nên chỉ thêm nhiễu — thực tế trộn vào
     * sinh ra các mục trùng chỉ khác hoa/thường như "Bí Ẩn" và "Bí ẩn".
     *
     * Nếu nguonc thêm mục mới thì bổ sung vào đây (đối chiếu menu trang chủ nguonc).
     */
    protected function taxonomyNguonc(): array
    {
        $theLoai = [
            'Hành Động', 'Phiêu Lưu', 'Hoạt Hình', 'Hài', 'Hình Sự', 'Tài Liệu',
            'Chính Kịch', 'Gia Đình', 'Giả Tưởng', 'Lịch Sử', 'Kinh Dị', 'Nhạc',
            'Bí Ẩn', 'Lãng Mạn', 'Khoa Học Viễn Tưởng', 'Gây Cấn', 'Chiến Tranh',
            'Tâm Lý', 'Tình Cảm', 'Cổ Trang', 'Miền Tây', 'Phim 18+',
        ];

        $quocGia = [
            'Âu Mỹ', 'Anh', 'Trung Quốc', 'Indonesia', 'Việt Nam', 'Pháp',
            'Hồng Kông', 'Hàn Quốc', 'Nhật Bản', 'Thái Lan', 'Đài Loan', 'Nga',
            'Hà Lan', 'Philippines', 'Ấn Độ', 'Quốc gia khác',
        ];

        // Blade lặp `@foreach ($regions as $region)` và JS dùng Object.values(), nên
        // trả về dạng [ten => ten] cho khớp với dữ liệu API của trang ophim.
        return [
            array_combine($theLoai, $theLoai),
            array_combine($quocGia, $quocGia),
        ];
    }

    /**
     * Danh mục cho trang crawler định dạng ophim, lấy từ API của chính ophim.
     *
     * Response hiện tại bọc danh sách trong data.items:
     *   {"status":"success","message":"","data":{"items":[{_id,name,slug}, ...]}}
     * Bản cũ pluck('name','name') thẳng trên toàn bộ response nên duyệt đúng 3 khoá
     * status/message/data — không khoá nào có 'name' — và luôn trả về ['' => null],
     * khiến ô chọn chỉ có duy nhất một option rỗng (nút "All" bấm vào không ra gì).
     * data_get(..., 'data.items') xử lý dạng mới, fallback về $data cho dạng phẳng cũ.
     *
     * Mỗi danh mục có try/catch riêng: trước đây một try bọc cả hai, thể loại lỗi là
     * quốc gia không bao giờ được nạp.
     */
    protected function taxonomyOphim(): array
    {
        $lay = function (string $duongDan, string $khoaCache) {
            try {
                return Cache::remember($khoaCache, 86400, function () use ($duongDan) {
                    $url = sprintf('%s/%s', config('movie_crawler.domain', 'https://ophim1.com'), $duongDan);
                    $data = json_decode(file_get_contents($url), true) ?? [];
                    $items = data_get($data, 'data.items', is_array($data) ? $data : []);

                    return collect($items)
                        ->pluck('name', 'name')
                        ->filter(function ($v) {
                            return is_string($v) && trim($v) !== '';
                        })
                        ->toArray();
                });
            } catch (\Throwable $th) {
                return [];
            }
        };

        return [
            $lay('the-loai', 'movie_categories'),
            $lay('quoc-gia', 'movie_regions'),
        ];
    }
// End ShowCrawlPage Nguonc
    public function showCrawlPage(Request $request)
    {
        [$categories, $regions] = $this->taxonomyOphim();

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

    /**
     * Trả danh sách phim theo lựa chọn ở ô "Lấy danh sách" trên trang crawler.
     *
     * Ba điểm đã sửa so với bản cũ:
     * - explode('-', ...)[1] ném "Undefined array key 1" (HTTP 500) khi params rỗng
     *   hoặc không chứa dấu gạch. Dùng array_pad để luôn có đủ 2 phần tử.
     * - $field lấy thẳng từ request rồi đưa vào where() nên chỉ định được cột tuỳ ý
     *   (thử params=id-1 là chạy). Nay kiểm qua danh sách trắng.
     * - orWhere($field, NULL) sinh ra "= NULL" nên không bao giờ khớp; phim có cột
     *   NULL bị bỏ sót. Đổi sang orWhereNull và nhóm các orWhere vào closure để không
     *   nới lỏng điều kiện khác khi về sau có thêm ràng buộc.
     */
    public function getMoviesFromParams(Request $request)
    {
        [$field, $val] = array_pad(explode('-', (string) $request->input('params'), 2), 2, '');

        $cotChoPhep = ['thumb_url', 'poster_url', 'trailer_url', 'status', 'type'];
        if (! in_array($field, $cotChoPhep, true)) {
            return response()->json([
                'message' => 'Tham số không hợp lệ: ' . $field,
            ], 422);
        }

        if ($val === '') {
            return Movie::where(function ($query) use ($field) {
                $query->where($field, '')
                    ->orWhereNull($field)
                    ->orWhere($field, 'like', '%.com%');
            })->get();
        }

        return Movie::where($field, $val)->get();
    }
}
