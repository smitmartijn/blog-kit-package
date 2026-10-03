<?php

namespace Lostdomain\BlogKit\Http\Controllers;

use Illuminate\Http\Request;
use Lostdomain\BlogKit\Services\SitemapBuilder;
use Symfony\Component\HttpFoundation\Response;

class SitemapController
{
    public function __invoke(Request $request, SitemapBuilder $builder): Response
    {
        return $builder->build()->toResponse($request);
    }
}
