@php /** @var \Lostdomain\BlogKit\Support\Seo $seo */ @endphp
<meta name="description" content="{{ $seo->description }}">
<link rel="canonical" href="{{ $seo->canonical }}">
@if ($seo->prev)
<link rel="prev" href="{{ $seo->prev }}">
@endif
@if ($seo->next)
<link rel="next" href="{{ $seo->next }}">
@endif
<link rel="alternate" type="application/rss+xml" title="{{ config('blog-kit.blog_title') }} (RSS)" href="{{ route('blog-kit.feed.rss') }}">
<link rel="alternate" type="application/atom+xml" title="{{ config('blog-kit.blog_title') }} (Atom)" href="{{ route('blog-kit.feed.atom') }}">
<meta property="og:type" content="{{ $seo->type }}">
<meta property="og:title" content="{{ $seo->title }}">
<meta property="og:description" content="{{ $seo->description }}">
<meta property="og:url" content="{{ $seo->canonical }}">
<meta property="og:site_name" content="{{ config('blog-kit.site_name') }}">
<meta property="og:locale" content="{{ config('blog-kit.locale') }}">
@if ($seo->image)
<meta property="og:image" content="{{ $seo->image }}">
@if ($seo->imageWidth && $seo->imageHeight)
<meta property="og:image:width" content="{{ $seo->imageWidth }}">
<meta property="og:image:height" content="{{ $seo->imageHeight }}">
@endif
@if ($seo->imageAlt)
<meta property="og:image:alt" content="{{ $seo->imageAlt }}">
@endif
@endif
@if ($seo->publishedTime)
<meta property="article:published_time" content="{{ $seo->publishedTime->toIso8601String() }}">
@endif
@if ($seo->modifiedTime)
<meta property="article:modified_time" content="{{ $seo->modifiedTime->toIso8601String() }}">
@endif
@foreach ($seo->tags as $tag)
<meta property="article:tag" content="{{ $tag }}">
@endforeach
<meta name="twitter:card" content="{{ $seo->image ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $seo->title }}">
<meta name="twitter:description" content="{{ $seo->description }}">
@if ($seo->image)
<meta name="twitter:image" content="{{ $seo->image }}">
@endif
@if (config('blog-kit.twitter_site'))
<meta name="twitter:site" content="{{ config('blog-kit.twitter_site') }}">
@endif
{!! $seo->jsonLdScripts() !!}
@include('blog-kit::partials.styles')
