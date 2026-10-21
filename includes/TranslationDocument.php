<?php

namespace RRZE\WebT;

defined('ABSPATH') || exit;

use WP_Error;
use WP_HTML_Tag_Processor;

/**
 * Check document boundaries and preserve the source's structural markup.
 *
 * These checks deliberately reject structural changes rather than repairing them.
 * Gutenberg's client-side validator additionally checks registered block types.
 */
class TranslationDocument
{
    /**
     * Split a translated document, requiring intact boundaries for new jobs.
     *
     * @param string $document Decoded provider document.
     * @param bool   $strict Whether a source snapshot exists for this job.
     * @return array{title:string,content:string}|WP_Error Extracted text or invalid boundaries.
     */
    public static function split(string $document, bool $strict)
    {
        $markers = ['<!--RRZE_WEBT_TITLE_START-->', '<!--RRZE_WEBT_TITLE_END-->', '<!--RRZE_WEBT_CONTENT_START-->', '<!--RRZE_WEBT_CONTENT_END-->'];
        $positions = [];
        foreach ($markers as $marker) {
            if (substr_count($document, $marker) !== 1) {
                return $strict ? self::invalid_document() : ['title' => '', 'content' => $document];
            }
            $positions[] = strpos($document, $marker);
        }
        if (! ($positions[0] < $positions[1] && $positions[1] < $positions[2] && $positions[2] < $positions[3])) {
            return self::invalid_document();
        }
        $title = substr($document, $positions[0] + strlen($markers[0]), $positions[1] - $positions[0] - strlen($markers[0]));
        $content = substr($document, $positions[2] + strlen($markers[2]), $positions[3] - $positions[2] - strlen($markers[2]));
        if (trim($content) === '') {
            return self::invalid_document();
        }
        return [
            'title' => html_entity_decode(wp_strip_all_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'content' => trim($content),
        ];
    }

    /**
     * Check block metadata, HTML structure and non-text attributes against the source.
     *
     * @param string $source Exact submitted editor content.
     * @param string $translated Received content segment.
     * @return WP_Error|null Reason for rejection, or null when structural checks pass.
     */
    public static function validate(string $source, string $translated): ?WP_Error
    {
        $before = self::block_shape($source);
        $after = self::block_shape($translated);
        if ($before === null || $after === null || $before !== $after
            || self::markup_shape($source) !== self::markup_shape($translated)) {
            return new WP_Error('rrze_webt_changed_structure', __('The translation changed block structure, block settings or HTML attributes. It cannot be applied safely. The original content is unchanged.', 'rrze-webt'));
        }
        // KSES reserializes block JSON (for example backslashes as \u005c).
        // Compare parsed data so harmless encoding changes do not reject a block.
        if (parse_blocks(wp_kses_post($translated)) !== parse_blocks($translated)) {
            return new WP_Error('rrze_webt_unsafe_content', __('The translation contains HTML that is not allowed in posts. The original content is unchanged.', 'rrze-webt'));
        }
        return null;
    }

    /**
     * Collect balanced block delimiters and their JSON attributes, in document order.
     *
     * WordPress's forgiving parser alone would recover missing closing delimiters.
     * Attribute changes are rejected, including custom/dynamic block configuration.
     *
     * @param string $content Serialized Gutenberg content or classic HTML.
     * @return array|null Delimiter signature, or null for malformed block comments.
     */
    private static function block_shape(string $content): ?array
    {
        preg_match_all('/<!--(.*?)-->/s', $content, $comments);
        $stack = [];
        $shape = [];
        foreach ($comments[1] as $comment) {
            if (! preg_match('/^\s*\/?wp:/', $comment)) {
                continue;
            }
            if (! preg_match('/^\s*(\/?)wp:([a-z0-9_-]+(?:\/[a-z0-9_-]+)?)\s*(\{.*\})?\s*(\/?)\s*$/sD', $comment, $match)) {
                return null;
            }
            $closing = $match[1] === '/';
            $self_closing = ($match[4] ?? '') === '/';
            $attributes = isset($match[3]) && $match[3] !== '' ? json_decode($match[3], true) : [];
            if (! is_array($attributes) || ($closing && ($self_closing || $attributes !== []))) {
                return null;
            }
            ksort($attributes);
            if ($closing) {
                if (array_pop($stack) !== $match[2]) {
                    return null;
                }
            } elseif (! $self_closing) {
                $stack[] = $match[2];
            }
            $shape[] = [$match[2], $closing, $self_closing, $attributes];
        }
        return $stack === [] ? $shape : null;
    }

    /**
     * Preserve element order and attributes, allowing only human-readable attribute text.
     *
     * @param string $content HTML fragment with optional Gutenberg comments.
     * @return array Element signature including closing tags, links, IDs and classes.
     */
    private static function markup_shape(string $content): array
    {
        $processor = new WP_HTML_Tag_Processor($content);
        $shape = [];
        while ($processor->next_tag(['tag_closers' => 'visit'])) {
            $attributes = [];
            foreach ($processor->get_attribute_names_with_prefix('') ?? [] as $name) {
                $attributes[$name] = in_array($name, ['alt', 'title', 'aria-label', 'placeholder'], true)
                    ? true : $processor->get_attribute($name);
            }
            ksort($attributes);
            $shape[] = [$processor->get_tag(), $processor->is_tag_closer(), $attributes];
        }
        return $shape;
    }

    /**
     * Create an inert text preview, never rendered as provider-supplied HTML.
     *
     * @param string $content Stored source or validated translation.
     * @return string Plain text with basic paragraph separation.
     */
    public static function preview(string $content): string
    {
        $content = preg_replace('/<\/(?:p|h[1-6]|li|div|blockquote)>|<br\s*\/?>/i', "$0\n", $content);
        return trim(html_entity_decode(wp_strip_all_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Describe unusable document boundaries without returning untrusted content.
     *
     * @return WP_Error Invalid translated document.
     */
    private static function invalid_document(): WP_Error
    {
        return new WP_Error('rrze_webt_invalid_document', __('The translation is missing intact title/content boundaries or contains no content. The original content is unchanged.', 'rrze-webt'));
    }
}
