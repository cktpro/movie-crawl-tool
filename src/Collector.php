<?php

namespace Movie\Crawler\MovieCrawler;

use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManagerStatic as Image;

class Collector
{
    protected $fields;
    protected $payload;
    protected $forceUpdate;

    public function __construct(array $payload, array $fields, $forceUpdate)
    {
        $this->fields = $fields;
        $this->payload = $payload;
        $this->forceUpdate = $forceUpdate;
    }
// get nguonc
public function get_nguonc(): array
    {
        $info = $this->payload['movie'] ?? [];
        $episodes = $this->payload['movie']['episodes'] ?? [];

        $data = [
            'name' => $info['name'],
            'origin_name' => $info['original_name'],
            'publish_year' => $info['category']['3']['list'][0]['name'] ?? null,
            'content' => $info['description'],
            'type' =>  $this->getMovieTypeNguonc($info, $episodes),
            'status' => $this->getStatusNguonc($info['current_episode']),
            // 'status' => 'completed',
            'thumb_url' => $this->getThumbImage($info['slug'], $info['thumb_url']),
            'poster_url' => $this->getPosterImage($info['slug'], $info['poster_url']),
            'is_copyright' => $info['is_copyright'] ?? false,
            'trailer_url' => $info['trailer_url'] ?? "",
            'quality' => $info['quality'],
            'language' => $info['language'],
            'episode_time' => $info['time'],
            'episode_current' => $info['current_episode'],
            'episode_total' => $info['total_episodes'],
            'notify' => $info['notify'] ?? "",
            'showtimes' => $info['showtimes'] ?? "",
            'is_shown_in_theater' => $info['chieurap'] ?? false,
        ];

        return $data;
    }
// end get nguonc
    public function get(): array
    {
        $info = $this->payload['movie'] ?? [];
        $episodes = $this->payload['episodes'] ?? [];

        // Mọi key đều đọc qua ?? — payload của API bên ngoài không đảm bảo có đủ. PHP 8
        // + HandleExceptions biến "Undefined array key" thành ErrorException nên chỉ cần
        // một phim thiếu key là dừng cả lượt crawl. Nhánh nguonc (get_nguonc) đã gia cố
        // từ 2026-08-14, nhánh ophim này bị bỏ sót.
        $data = [
            'name' => $info['name'] ?? '',
            'origin_name' => $info['origin_name'] ?? '',
            'publish_year' => $info['year'] ?? null,
            'content' => $info['content'] ?? '',
            'type' =>  $this->getMovieType($info, $episodes),
            'status' => $info['status'] ?? null,
            'thumb_url' => $this->getThumbImage($info['slug'] ?? '', $info['thumb_url'] ?? null),
            'poster_url' => $this->getPosterImage($info['slug'] ?? '', $info['poster_url'] ?? null),
            'is_copyright' => $info['is_copyright'] ?? false,
            'trailer_url' => $info['trailer_url'] ?? "",
            'quality' => $info['quality'] ?? '',
            'language' => $info['lang'] ?? '',
            'episode_time' => $info['time'] ?? '',
            'episode_current' => $info['episode_current'] ?? '',
            'episode_total' => $info['episode_total'] ?? '',
            'notify' => $info['notify'] ?? '',
            'showtimes' => $info['showtimes'] ?? '',
            'is_shown_in_theater' => $info['chieurap'] ?? false,
        ];

        return $data;
    }

    /**
     * Ghép tiền tố cho ảnh khi nguồn trả về đường dẫn tương đối.
     *
     * API ophim hiện trả TÊN FILE TRẦN cho thumb_url/poster_url ("soulm8te-thumb.webp")
     * và trong response không có trường CDN nào để suy ra domain. Nếu đưa thẳng chuỗi
     * này xuống getImage(): bật "Tải ảnh khi crawl" thì tải thất bại rồi lưu lại chính
     * tên file, tắt thì lưu thẳng tên file — cách nào cũng ra một giá trị không dùng
     * được, ảnh vỡ trên site. nguonc thì trả URL tuyệt đối nên không bị ảnh hưởng.
     *
     * Tiền tố lấy từ option "Tiền tố ảnh nguồn"; giá trị đã là URL tuyệt đối hoặc
     * bắt đầu bằng "//" thì giữ nguyên.
     */
    protected function chuanHoaUrlAnh(?string $url): ?string
    {
        if (empty($url) || preg_match('~^(https?:)?//~i', $url)) {
            return $url;
        }

        $base = trim((string) Option::get('image_source_base', 'https://img.ophim.live/uploads/movies/'));
        if ($base === '') {
            return $url;
        }

        return rtrim($base, '/') . '/' . ltrim($url, '/');
    }

    public function getThumbImage($slug, $url)
    {
        return $this->getImage(
            $slug,
            $this->chuanHoaUrlAnh($url),
            Option::get('should_resize_thumb', false),
            Option::get('resize_thumb_width'),
            Option::get('resize_thumb_height')
        );
    }

    public function getPosterImage($slug, $url)
    {
        return $this->getImage(
            $slug,
            $this->chuanHoaUrlAnh($url),
            Option::get('should_resize_poster', false),
            Option::get('resize_poster_width'),
            Option::get('resize_poster_height')
        );
    }
    // GetStatusNguonc
   protected function slugify($str, $divider = '-')
    {
        $str = trim(mb_strtolower($str));
        $str = preg_replace('/(à|á|ạ|ả|ã|â|ầ|ấ|ậ|ẩ|ẫ|ă|ằ|ắ|ặ|ẳ|ẵ)/', 'a', $str);
        $str = preg_replace('/(è|é|ẹ|ẻ|ẽ|ê|ề|ế|ệ|ể|ễ)/', 'e', $str);
        $str = preg_replace('/(ì|í|ị|ỉ|ĩ)/', 'i', $str);
        $str = preg_replace('/(ò|ó|ọ|ỏ|õ|ô|ồ|ố|ộ|ổ|ỗ|ơ|ờ|ớ|ợ|ở|ỡ)/', 'o', $str);
        $str = preg_replace('/(ù|ú|ụ|ủ|ũ|ư|ừ|ứ|ự|ử|ữ)/', 'u', $str);
        $str = preg_replace('/(ỳ|ý|ỵ|ỷ|ỹ)/', 'y', $str);
        $str = preg_replace('/(đ)/', 'd', $str);
        $str = preg_replace('/[^a-z0-9-\s]/', '', $str);
        $str = preg_replace('/([\s]+)/', $divider, $str);
        return $str;
    }
    protected function getStatusNguonc($status)
    {
        $slugifyStatus = $this->slugify($status,'_');
        $type = 'completed';
        if(strpos($slugifyStatus, 'tap')!==false || strpos($slugifyStatus, 'dang')!==false) {
            $type = 'ongoing';
        } elseif(strpos($slugifyStatus, 'hoan')!==false || strpos($slugifyStatus, 'full')!==false) {
            $type = 'completed';
        }else{
            $type = 'is_trailer';
        };
        return $type;


    }


    // Get type nguonc
    protected function getMovieTypeNguonc($info, $episodes)
    {
        return ($info['category']['1']['list'][0]['name'] ?? null) == 'Phim bộ' ? 'series'
            : 'single';
    }
    // End get type nguonc
    protected function getMovieType($info, $episodes)
    {
        $type = $info['type'] ?? null;

        if ($type === 'series' || $type === 'single') {
            return $type;
        }

        // reset() trên mảng rỗng trả về false, nên phải kiểm tra is_array trước khi
        // đọc ['server_data'] — bản cũ đọc thẳng và ném lỗi khi payload không có tập.
        $server = is_array($episodes) && $episodes ? reset($episodes) : null;
        $danhSachTap = is_array($server) ? ($server['server_data'] ?? []) : [];

        return count($danhSachTap) > 1 ? 'series' : 'single';
    }

    protected function getImage($slug, ?string $url, $shouldResize = false, $width = null, $height = null): ?string
    {
        if (!Option::get('download_image', false) || empty($url)) {
            return $url;
        }
        try {
            $url = strtok($url, '?');
            $filename = substr($url, strrpos($url, '/') + 1);

            $toWebp = Option::get('image_format', 'original') === 'webp';
            if ($toWebp && !ImageStorage::supportsWebp()) {
                Log::warning('[crawler] PHP chưa hỗ trợ encode WebP, giữ định dạng gốc: ' . $url);
                $toWebp = false;
            }
            if ($toWebp) {
                $filename = preg_replace('/\.[a-zA-Z0-9]+$/', '', $filename) . '.webp';
            }

            $path = "images/{$slug}/{$filename}";
            $storage = ImageStorage::disk();

            if ($this->forceUpdate == false && $storage->exists($path)) {
                return ImageStorage::url($path);
            }

            $image_data = $this->downloadImage($url);
            if (empty($image_data)) {
                Log::error('[crawler] Không tải được ảnh: ' . $url);
                return $url;
            }

            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($image_data) ?: null;

            // Chỉ decode/encode lại khi thật sự cần (đổi sang webp hoặc resize),
            // còn lại giữ nguyên bytes gốc để không giảm chất lượng.
            if ($toWebp || $shouldResize) {
                $img = Image::make($image_data);

                if ($shouldResize) {
                    $img->resize($width, $height, function ($constraint) {
                        $constraint->aspectRatio();
                    });
                }

                $quality = (int) Option::get('image_quality', 85) ?: 85;
                $encoded = $toWebp ? $img->encode('webp', $quality) : $img->encode(null, $quality);

                $image_data = (string) $encoded;
                $mime = $encoded->mime() ?: $mime;
                $img->destroy();
            }

            ImageStorage::put($path, $image_data, $mime);

            return ImageStorage::url($path);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $url;
        }
    }

    protected function downloadImage(string $url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/111.0.0.0 Safari/537.36");
        $image_data = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($status >= 200 && $status < 300) ? $image_data : null;
    }
}
