<?php

use Illuminate\Support\Facades\Route;

// --------------------------
// Custom Backpack Routes
// --------------------------
// This route file is loaded automatically by Backpack\Base.
// Routes you generate using Backpack\Generators will be placed here.

Route::group([
    'prefix'     => config('backpack.base.route_prefix', 'admin'),
    'middleware' => array_merge(
        (array) config('backpack.base.web_middleware', 'web'),
        (array) config('backpack.base.middleware_key', 'admin')
    ),
    'namespace'  => 'Movie\Crawler\MovieCrawler\Controllers',
], function () {
    Route::get('/plugin/movie-crawler', 'CrawlController@showCrawlPage');
    Route::get('/plugin/nguonc-crawler', 'CrawlController@showCrawlPageNguonc');
    Route::get('/plugin/movie-crawler/options', 'CrawlerSettingController@editOptions');
    Route::put('/plugin/movie-crawler/options', 'CrawlerSettingController@updateOptions');
    Route::get('/plugin/movie-crawler/fetch', 'CrawlController@fetch');
    Route::get('/plugin/movie-crawler/fetch_nguonc', 'CrawlController@fetch_nguonc');
    Route::post('/plugin/movie-crawler/crawl', 'CrawlController@crawl');
    Route::post('/plugin/movie-crawler/crawl_nguonc', 'CrawlController@crawl_nguonc');
    Route::post('/plugin/movie-crawler/get-movies', 'CrawlController@getMoviesFromParams');
    Route::get('/plugin/movie-crawler/images-cleanup', 'ImageCleanupController@index');
    Route::post('/plugin/movie-crawler/images-cleanup', 'ImageCleanupController@destroy');
    Route::get('/plugin/movie-crawler/images-r2', 'ImageR2Controller@index');
    Route::post('/plugin/movie-crawler/images-r2', 'ImageR2Controller@migrate');
    Route::get('/plugin/movie-crawler/images-webp', 'ImageWebpController@index');
    Route::post('/plugin/movie-crawler/images-webp', 'ImageWebpController@convert');
});
