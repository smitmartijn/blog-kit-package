<?php

namespace Lostdomain\BlogKit\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lostdomain\BlogKit\BlogKit;

/**
 * Contract v1. Only the fields listed here are read; unknown fields from
 * newer Marketing OS versions are ignored. "status" and
 * "scheduled_publish_date" are Marketing OS internals and not used.
 */
class ReceivePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $content = 'exclude_if:event,unpublish';

        return [
            'schema_version' => ['required', 'integer', Rule::in([1])],
            'event' => ['required', 'string', Rule::in(['publish', 'update', 'unpublish'])],
            'delivery_id' => ['required', 'string', 'max:191'],
            'sent_at' => ['nullable', 'date'],
            '_test' => ['sometimes', 'boolean'],
            'product_id' => ['required', $this->scalar(), $this->expectedProduct()],
            'content_item_id' => ['required', $this->scalar(), 'max:191'],
            'slug' => ['required', 'string', 'max:191', 'regex:/^'.BlogKit::SLUG_PATTERN.'$/', Rule::notIn(BlogKit::RESERVED_SLUGS)],

            'title' => [$content, 'required', 'string', 'max:255'],
            'meta_title' => [$content, 'nullable', 'string', 'max:255'],
            'meta_description' => [$content, 'nullable', 'string', 'max:1000'],
            'excerpt' => [$content, 'nullable', 'string', 'max:5000'],
            'body_html' => [$content, 'required', 'string'],
            'body_markdown' => [$content, 'nullable', 'string'],

            'featured_image' => [$content, 'nullable', 'array'],
            'featured_image.url' => [$content, 'required_with:featured_image', 'nullable', 'url:http,https', 'max:2048'],
            'featured_image.alt_text' => [$content, 'nullable', 'string', 'max:1000'],
            'featured_image.width' => [$content, 'nullable', 'integer', 'min:1'],
            'featured_image.height' => [$content, 'nullable', 'integer', 'min:1'],

            'faq' => [$content, 'nullable', 'array'],
            'faq.*.question' => [$content, 'required', 'string'],
            'faq.*.answer' => [$content, 'required', 'string'],
            'cta' => [$content, 'nullable', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) && ! is_array($value)) {
                    $fail('The cta must be a string or an object.');
                }
            }],
            'internal_links' => [$content, 'nullable', 'array'],
            'external_sources' => [$content, 'nullable', 'array'],
            'schema_suggestions' => [$content, 'nullable', 'array'],

            'images' => [$content, 'nullable', 'array'],
            'images.*.url' => [$content, 'required', 'url:http,https', 'max:2048'],
            'images.*.slot' => [$content, 'nullable'],
            'images.*.placement' => [$content, 'nullable', 'string'],
            'images.*.kind' => [$content, 'nullable', 'string'],
            'images.*.alt_text' => [$content, 'nullable', 'string'],
            'images.*.caption' => [$content, 'nullable', 'string'],
            'images.*.section_heading' => [$content, 'nullable', 'string'],
            'images.*.aspect_ratio' => [$content, 'nullable'],

            'target_keyword' => [$content, 'nullable', 'string', 'max:255'],
            'secondary_keywords' => [$content, 'nullable', 'array'],
            'secondary_keywords.*' => [$content, 'string'],
            'post_type' => [$content, 'nullable', 'string', 'max:100'],
            'tags' => [$content, 'nullable', 'array'],
            'tags.*' => [$content, 'string', 'max:100'],
            'published_at' => [$content, 'nullable', 'date'],
            'updated_at' => [$content, 'nullable', 'date'],
        ];
    }

    public function isTest(): bool
    {
        return $this->boolean('_test');
    }

    private function scalar(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! is_string($value) && ! is_int($value)) {
                $fail("The {$attribute} must be a string or an integer.");
            }
        };
    }

    private function expectedProduct(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $expected = config('blog-kit.api.product_id');

            if (filled($expected) && (string) $value !== (string) $expected) {
                $fail("This site only accepts posts for product {$expected}.");
            }
        };
    }
}
