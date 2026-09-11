<?php

namespace App\Http\Controllers\Content;

use App\Domains\Content\Services\BannerService;
use App\Domains\Content\Services\ContentService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PageController extends Controller
{
    /**
     * A page written in the Admin Panel.
     *
     * Draft and scheduled pages 404 rather than 403: whether a page exists at
     * all is not something an anonymous visitor should be able to probe.
     */
    public function show(string $slug, ContentService $content): View
    {
        $page = $content->page($slug);

        abort_if($page === null, 404);

        return view('content.page', ['page' => $page]);
    }

    public function dismissBanner(string $uuid, BannerService $banners): RedirectResponse
    {
        $banners->dismiss($uuid);

        return back();
    }
}
