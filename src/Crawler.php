<?php

namespace Movie\Crawler\MovieCrawler;

use Movie\Core\Models\Movie;
use Illuminate\Support\Str;
use Movie\Core\Models\Actor;
use Movie\Core\Models\Category;
use Movie\Core\Models\Director;
use Movie\Core\Models\Episode;
use Movie\Core\Models\Region;
use Movie\Core\Models\Tag;
use Movie\Crawler\MovieCrawler\Contracts\BaseCrawler;

class Crawler extends BaseCrawler
{
    // Handle Nguonc
    public function handle_nguonc()
    {
        $payload = json_decode($body = file_get_contents($this->link), true);

        $this->checkIsInExcludedListNguonc($payload);

        $movie = Movie::where('update_handler', static::class)
            ->where('update_identity', $payload['movie']['id'] ?? null)
            ->first();

        if (!$this->hasChange($movie, md5($body)) && $this->forceUpdate == false) {
            return false;
        }

        $info = (new Collector($payload, $this->fields, $this->forceUpdate))->get_nguonc();

        if ($movie) {
            $movie->updated_at = now();
            $movie->update(collect($info)->only($this->fields)->merge(['update_checksum' => md5($body)])->toArray());
        } else {
            $movie = Movie::create(array_merge($info, [
                'update_handler' => static::class,
                'update_identity' => $payload['movie']['id'] ?? null,
                'update_checksum' => md5($body)
            ]));
        }

        $this->syncActorsNguonc($movie, $payload);
        $this->syncDirectorsNguonc($movie, $payload);
        $this->syncCategoriesNguonc($movie, $payload);
        $this->syncRegionsNguonc($movie, $payload);
        $this->syncTagsNguonc($movie, $payload);
        $this->syncStudiosNguonc($movie, $payload);
        $this->updateEpisodesNguonc($movie, $payload);
    }
    // End handle Nguonc
    public function handle()
    {
        $payload = json_decode($body = file_get_contents($this->link), true);

        $this->checkIsInExcludedList($payload);

        $movie = Movie::where('update_handler', static::class)
            ->where('update_identity', $payload['movie']['_id'])
            ->first();

        if (!$this->hasChange($movie, md5($body)) && $this->forceUpdate == false) {
            return false;
        }

        $info = (new Collector($payload, $this->fields, $this->forceUpdate))->get();

        if ($movie) {
            $movie->updated_at = now();
            $movie->update(collect($info)->only($this->fields)->merge(['update_checksum' => md5($body)])->toArray());
        } else {
            $movie = Movie::create(array_merge($info, [
                'update_handler' => static::class,
                'update_identity' => $payload['movie']['_id'],
                'update_checksum' => md5($body)
            ]));
        }

        $this->syncActors($movie, $payload);
        $this->syncDirectors($movie, $payload);
        $this->syncCategories($movie, $payload);
        $this->syncRegions($movie, $payload);
        $this->syncTags($movie, $payload);
        $this->syncStudios($movie, $payload);
        $this->updateEpisodes($movie, $payload);
    }

    protected function hasChange(?Movie $movie, $checksum)
    {
        return is_null($movie) || ($movie->update_checksum != $checksum);
    }

//    Lọc phim Nguonc
protected function checkIsInExcludedListNguonc($payload)
{
    $newType = $this->getTypeMovie($payload['movie']['category']['1']['list'][0]['name'] ?? null);
    if (in_array($newType, $this->excludedType)) {
        throw new \Exception("Thuộc định dạng đã loại trừ");
    }

    $newCategories = collect($payload['movie']['category']['2']['list'] ?? [])->pluck('name')->toArray();
    if (array_intersect($newCategories, $this->excludedCategories)) {
        throw new \Exception("Thuộc thể loại đã loại trừ");
    }

    $newRegions = collect($payload['movie']['category']['4']['list'] ?? [])->pluck('name')->toArray();
    if (array_intersect($newRegions, $this->excludedRegions)) {
        throw new \Exception("Thuộc quốc gia đã loại trừ");
    }
}
//  End lọc phim nguồnc
    protected function checkIsInExcludedList($payload)
    {
        $newType = $payload['movie']['type'] ?? null;
        if (in_array($newType, $this->excludedType)) {
            throw new \Exception("Thuộc định dạng đã loại trừ");
        }

        $newCategories = collect($payload['movie']['category'] ?? [])->pluck('name')->toArray();
        if (array_intersect($newCategories, $this->excludedCategories)) {
            throw new \Exception("Thuộc thể loại đã loại trừ");
        }

        $newRegions = collect($payload['movie']['country'] ?? [])->pluck('name')->toArray();
        if (array_intersect($newRegions, $this->excludedRegions)) {
            throw new \Exception("Thuộc quốc gia đã loại trừ");
        }
    }
// Sync Nguonc
protected function syncActorsNguonc($movie, array $payload)
{
    if (!in_array('actors', $this->fields)) return;

    $actors = [];
    // API nguồnc đôi khi trả "casts" là chuỗi (vd: "Đang cập nhật") thay vì mảng tên diễn viên.
    if (!empty($payload['movie']['casts']) && is_array($payload['movie']['casts'])) {
        foreach ($payload['movie']['casts'] as $actor) {
            if (!trim($actor)) continue;
            $actors[] = Actor::firstOrCreate(['name' => trim($actor)])->id;
        }
        $movie->actors()->sync($actors);
    }
}

protected function syncDirectorsNguonc($movie, array $payload)
{
    if (!in_array('directors', $this->fields)) return;

    $directors = [];
    if (!empty($payload['movie']['director']) && is_array($payload['movie']['director'])) {
        foreach ($payload['movie']['director'] as $director) {
            if (!trim($director)) continue;
            $directors[] = Director::firstOrCreate(['name' => trim($director)])->id;
        }
    }
    $movie->directors()->sync($directors);
}

protected function syncCategoriesNguonc($movie, array $payload)
{
    if (!in_array('categories', $this->fields)) return;
    $categories = [];
    foreach ($payload['movie']['category']['2']['list'] ?? [] as $category) {
        if (!trim($category['name'] ?? '')) continue;
        $categories[] = Category::firstOrCreate(['name' => trim($category['name'])])->id;
    }
    // if($payload['movie']['type'] === 'hoathinh') $categories[] = Category::firstOrCreate(['name' => 'Hoạt Hình'])->id;
    // if($payload['movie']['type'] === 'tvshows') $categories[] = Category::firstOrCreate(['name' => 'TV Shows'])->id;
    $movie->categories()->sync($categories);
}

protected function syncRegionsNguonc($movie, array $payload)
{
    if (!in_array('regions', $this->fields)) return;

    $regions = [];
    foreach ($payload['movie']['category']['4']['list'] ?? [] as $region) {
        if (!trim($region['name'] ?? '')) continue;
        $regions[] = Region::firstOrCreate(['name' => trim($region['name'])])->id;
    }
    $movie->regions()->sync($regions);
}

protected function syncTagsNguonc($movie, array $payload)
{
    if (!in_array('tags', $this->fields)) return;

    $tags = [];
    $tags[] = Tag::firstOrCreate(['name' => trim($movie->name)])->id;
    $tags[] = Tag::firstOrCreate(['name' => trim($movie->origin_name)])->id;

    $movie->tags()->sync($tags);
}

protected function syncStudiosNguonc($movie, array $payload)
{
    if (!in_array('studios', $this->fields)) return;
}
protected function getTypeMovie($type)
{
    return $type==='Phim bộ'?'series':'single';
}
// end Sync Nguonc
    protected function syncActors($movie, array $payload)
    {
        if (!in_array('actors', $this->fields)) return;

        $actors = [];
        if (!empty($payload['movie']['actor']) && is_array($payload['movie']['actor'])) {
            foreach ($payload['movie']['actor'] as $actor) {
                if (!trim($actor)) continue;
                $actors[] = Actor::firstOrCreate(['name' => trim($actor)])->id;
            }
        }
        $movie->actors()->sync($actors);
    }

    protected function syncDirectors($movie, array $payload)
    {
        if (!in_array('directors', $this->fields)) return;

        $directors = [];
        if (!empty($payload['movie']['director']) && is_array($payload['movie']['director'])) {
            foreach ($payload['movie']['director'] as $director) {
                if (!trim($director)) continue;
                $directors[] = Director::firstOrCreate(['name' => trim($director)])->id;
            }
        }
        $movie->directors()->sync($directors);
    }

    protected function syncCategories($movie, array $payload)
    {
        if (!in_array('categories', $this->fields)) return;
        $categories = [];
        foreach ($payload['movie']['category'] ?? [] as $category) {
            if (!trim($category['name'] ?? '')) continue;
            $categories[] = Category::firstOrCreate(['name' => trim($category['name'])])->id;
        }
        if (($payload['movie']['type'] ?? null) === 'hoathinh') $categories[] = Category::firstOrCreate(['name' => 'Hoạt Hình'])->id;
        if (($payload['movie']['type'] ?? null) === 'tvshows') $categories[] = Category::firstOrCreate(['name' => 'TV Shows'])->id;
        $movie->categories()->sync($categories);
    }

    protected function syncRegions($movie, array $payload)
    {
        if (!in_array('regions', $this->fields)) return;

        $regions = [];
        foreach ($payload['movie']['country'] ?? [] as $region) {
            if (!trim($region['name'] ?? '')) continue;
            $regions[] = Region::firstOrCreate(['name' => trim($region['name'])])->id;
        }
        $movie->regions()->sync($regions);
    }

    protected function syncTags($movie, array $payload)
    {
        if (!in_array('tags', $this->fields)) return;

        $tags = [];
        $tags[] = Tag::firstOrCreate(['name' => trim($movie->name)])->id;
        $tags[] = Tag::firstOrCreate(['name' => trim($movie->origin_name)])->id;

        $movie->tags()->sync($tags);
    }

    protected function syncStudios($movie, array $payload)
    {
        if (!in_array('studios', $this->fields)) return;
    }

    // UpdateEpisodes Nguonc
    protected function updateEpisodesNguonc($movie, $payload)
    {
        if (!in_array('episodes', $this->fields)) return;
        $flag = 0;
        foreach ($payload['movie']['episodes'] ?? [] as $server) {
            // Không phải tập nào cũng có cả 2 link "embed" và "m3u8" -> dùng empty() để tránh
            // "Undefined array key" khi thiếu key (chứ không phải chỉ khi giá trị rỗng).
            foreach ($server['items'] ?? [] as $episode) {
                if (!empty($episode['embed'])) {
                    Episode::updateOrCreate([
                        'id' => $movie->episodes[$flag]->id ?? null
                    ], [
                        'name' => $episode['name'],
                        'movie_id' => $movie->id,
                        'server' => $server['server_name'],
                        'type' => 'embed',
                        'link' => $episode['embed'],
                        'slug' => 'tap-' . Str::slug($episode['name'])
                    ]);
                    $flag++;
                }
                if (!empty($episode['m3u8'])) {
                    Episode::updateOrCreate([
                        'id' => $movie->episodes[$flag]->id ?? null
                    ], [
                        'name' => $episode['name'],
                        'movie_id' => $movie->id,
                        'server' => $server['server_name'],
                        'type' => 'm3u8',
                        'link' => $episode['m3u8'],
                        'slug' => 'tap-' . Str::slug($episode['name'])
                    ]);
                    $flag++;
                }
            }
        }
        for ($i=$flag; $i < count($movie->episodes); $i++) {
            $movie->episodes[$i]->delete();
        }
    }
    // End UpdateEpisodes Nguonc
    protected function updateEpisodes($movie, $payload)
    {
        if (!in_array('episodes', $this->fields)) return;
        $flag = 0;
        foreach ($payload['episodes'] ?? [] as $server) {
            foreach ($server['server_data'] ?? [] as $episode) {
                if (!empty($episode['link_m3u8'])) {
                    Episode::updateOrCreate([
                        'id' => $movie->episodes[$flag]->id ?? null
                    ], [
                        'name' => $episode['name'],
                        'movie_id' => $movie->id,
                        'server' => $server['server_name'],
                        'type' => 'm3u8',
                        'link' => $episode['link_m3u8'],
                        'slug' => 'tap-' . Str::slug($episode['name'])
                    ]);
                    $flag++;
                }
                if (!empty($episode['link_embed'])) {
                    Episode::updateOrCreate([
                        'id' => $movie->episodes[$flag]->id ?? null
                    ], [
                        'name' => $episode['name'],
                        'movie_id' => $movie->id,
                        'server' => $server['server_name'],
                        'type' => 'embed',
                        'link' => $episode['link_embed'],
                        'slug' => 'tap-' . Str::slug($episode['name'])
                    ]);
                    $flag++;
                }
            }
        }
        for ($i=$flag; $i < count($movie->episodes); $i++) {
            $movie->episodes[$i]->delete();
        }
    }
}
