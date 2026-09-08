<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Replaces a marker-wrapped navigation block in the template with a
 * navigation generated from a WordPress menu - while keeping the
 * designer's own HTML/CSS (Bootstrap classes, buttons etc.) by cutting
 * the template directly out of the template itself and duplicating it
 * for each menu item.
 *
 * Concept (as agreed): the designer marks up the template with
 *  - the complete menu block wrapped in a "wrapper" marker,
 *  - inside it, a sample element wrapped in an "item" marker (template
 *    for menu items without children),
 *  - optionally a sample element wrapped in a "parent item" marker
 *    (template for menu items WITH children), which in turn contains a
 *    "submenu wrapper" marker that can contain a "submenu item" (falls
 *    back to the regular item template otherwise).
 * These three additional markers apply recursively at EVERY depth - a
 * submenu item that itself has children is automatically handled with
 * the parent item template again.
 */
class WPStatic_Navigation {
    /**
     * Replaces wrapper marker blocks in the template with the generated
     * navigation - once for the main navigation, once for the footer
     * navigation (each only if enabled in the settings). $currentUrl
     * determines which menu item gets marked as "active".
     */
    public static function renderTemplate(string $template, string $currentUrl, array $settings): string {
        if (!empty($settings['nav_main_enabled'])) {
            $template = self::renderOneNavigation($template, $currentUrl, $settings, 'nav_main_');
        }

        if (!empty($settings['nav_footer_enabled'])) {
            $template = self::renderOneNavigation($template, $currentUrl, $settings, 'nav_footer_');
        }

        return $template;
    }

    private static function renderOneNavigation(string $template, string $currentUrl, array $settings, string $prefix): string {
        $wrapperMarker = $settings[$prefix . 'wrapper_marker'];

        if ($wrapperMarker === '') {
            return $template;
        }

        $wrapperBlock = self::getSubpart($template, $wrapperMarker);

        if ($wrapperBlock === '') {
            return $template; // Marker not found in the template - nothing to do
        }

        $itemMarker = $settings[$prefix . 'item_marker'];
        $itemTemplate = self::getSubpart($wrapperBlock, $itemMarker);
        $parentItemMarker = $settings[$prefix . 'parent_item_marker'];
        $parentItemTemplate = $parentItemMarker !== '' ? self::getSubpart($wrapperBlock, $parentItemMarker) : '';

        $menuItems = self::getMenuTree((int) $settings[$prefix . 'menu_id']);

        $renderedItems = '';
        foreach ($menuItems as $item) {
            $renderedItems .= self::renderItem(
                $item,
                $itemTemplate,
                $parentItemTemplate,
                $settings,
                $prefix,
                $currentUrl,
                $itemTemplate,
                $itemMarker
            );
        }

        // Determine the insertion point for $renderedItems: the item
        // marker is preferred (if present in the template). If the
        // template has no item marker at all (e.g. a footer navigation
        // where EVERY column uses the parent template and not a single
        // column is "flat"), the parent marker is used as the insertion
        // point instead - otherwise the rendered navigation would have no
        // place to be inserted at all.
        $fullItemMarkerBlock = $itemMarker !== '' ? self::extractFullBlockWithMarkers($wrapperBlock, $itemMarker) : '';
        $fullParentMarkerBlock = ($parentItemMarker !== '' && $parentItemTemplate !== '')
            ? self::extractFullBlockWithMarkers($wrapperBlock, $parentItemMarker)
            : '';

        if ($fullItemMarkerBlock !== '') {
            // The item marker is the insertion point - the parent demo
            // block (if also present) only served as a blueprint, its
            // content is already part of $renderedItems and the raw demo
            // block is removed without replacement.
            $modifiedWrapperBlock = str_replace($fullItemMarkerBlock, $renderedItems, $wrapperBlock);

            if ($fullParentMarkerBlock !== '') {
                $modifiedWrapperBlock = str_replace($fullParentMarkerBlock, '', $modifiedWrapperBlock);
            }
        } elseif ($fullParentMarkerBlock !== '') {
            // No item marker in the template - the parent marker is the
            // insertion point.
            $modifiedWrapperBlock = str_replace($fullParentMarkerBlock, $renderedItems, $wrapperBlock);
        } else {
            // Neither an item nor a parent marker found in the wrapper -
            // nothing to do (a configuration error; won't silently
            // destroy content).
            $modifiedWrapperBlock = $wrapperBlock;
        }

        $fullWrapperWithMarkers = self::extractFullBlockWithMarkers($template, $wrapperMarker);

        if ($fullWrapperWithMarkers === '') {
            return $template;
        }

        return str_replace($fullWrapperWithMarkers, $modifiedWrapperBlock, $template);
    }

    /**
     * Renders a single menu item (and recursively its children, if any
     * exist and a parent item template is defined).
     *
     * $leafTemplate/$leafMarker are the item template (and its marker
     * name) to use AT THIS nesting level - at the top level the regular
     * item template, inside a submenu the submenu item template (if
     * defined, otherwise falling back to the regular item template).
     */
    private static function renderItem(
        array $item,
        string $itemTemplate,
        string $parentItemTemplate,
        array $settings,
        string $prefix,
        string $currentUrl,
        string $leafTemplate,
        string $leafMarker
    ): string {
        $hasChildren = !empty($item['children']);

        if (!$hasChildren || $parentItemTemplate === '') {
            $effectiveLeaf = $leafTemplate;

            // Safeguard: the item template was never found in the
            // template (e.g. because a footer navigation only consists
            // of columns WITH sub-items, but one particular menu item in
            // WordPress happens to have no children) - in that case,
            // prefer the "shell" of the parent template (title/link,
            // without the submenu part) rather than letting the menu item
            // silently disappear.
            if ($effectiveLeaf === '' && $parentItemTemplate !== '') {
                $effectiveLeaf = self::stripSubmenuShell(
                    $parentItemTemplate,
                    $settings[$prefix . 'submenu_wrapper_marker']
                );
            }

            return self::applyLabelUrlAndActiveState($effectiveLeaf, $item, $settings, $currentUrl);
        }

        $submenuWrapperMarker = $settings[$prefix . 'submenu_wrapper_marker'];
        $submenuItemMarker = $settings[$prefix . 'submenu_item_marker'];

        $submenuWrapperBlock = $submenuWrapperMarker !== '' ? self::getSubpart($parentItemTemplate, $submenuWrapperMarker) : '';

        // Determine the template for this menu item's CHILDREN - its own
        // submenu item template if defined, otherwise falling back to the
        // regular item template.
        $childLeafTemplate = $itemTemplate;
        $childLeafMarker = $settings[$prefix . 'item_marker'];

        if ($submenuItemMarker !== '' && $submenuWrapperBlock !== '') {
            $candidate = self::getSubpart($submenuWrapperBlock, $submenuItemMarker);

            if ($candidate !== '') {
                $childLeafTemplate = $candidate;
                $childLeafMarker = $submenuItemMarker;
            }
        }

        $renderedChildren = '';
        foreach ($item['children'] as $child) {
            $renderedChildren .= self::renderItem(
                $child,
                $itemTemplate,
                $parentItemTemplate,
                $settings,
                $prefix,
                $currentUrl,
                $childLeafTemplate,
                $childLeafMarker
            );
        }

        $itemHtml = $parentItemTemplate;

        if ($submenuWrapperMarker !== '' && $submenuWrapperBlock !== '') {
            $fullChildMarkerBlock = self::extractFullBlockWithMarkers($submenuWrapperBlock, $childLeafMarker);
            $submenuHtml = $fullChildMarkerBlock !== ''
                ? str_replace($fullChildMarkerBlock, $renderedChildren, $submenuWrapperBlock)
                : $renderedChildren;

            $fullSubmenuWrapperBlock = self::extractFullBlockWithMarkers($parentItemTemplate, $submenuWrapperMarker);

            if ($fullSubmenuWrapperBlock !== '') {
                $itemHtml = str_replace($fullSubmenuWrapperBlock, $submenuHtml, $parentItemTemplate);
            }
        }

        return self::applyLabelUrlAndActiveState($itemHtml, $item, $settings, $currentUrl);
    }

    /**
     * Inserts the real title/URL into the template's first <a> tag
     * (without the designer needing explicit placeholders for that) and
     * adds/removes the "active page" CSS class.
     */
    private static function applyLabelUrlAndActiveState(string $itemHtml, array $item, array $settings, string $currentUrl): string {
        $isActive = self::urlsMatch($item['url'], $currentUrl);
        $resolvedUrl = self::resolveStaticUrl($item);

        return self::replaceFirstAnchor($itemHtml, $resolvedUrl, $item['title'], $isActive, $settings['nav_active_class']);
    }

    /**
     * With "Plain" permalinks (WordPress' "Plain" setting), WordPress
     * links posts/pages via a query-string ID (?page_id=2 or ?p=5)
     * instead of via a path segment. A static export can't represent
     * that as a file of its own - posts end up under {slug}.{id}.html
     * instead (see createFilename() in WPHeadlessStaticGenerator.php).
     * The same conversion is reproduced here for navigation links, so
     * they point to the file that's actually generated instead of the
     * ?page_id= URL (which never exists in a static export). With other
     * permalink structures, the URL is left untouched.
     */
    private static function resolveStaticUrl(array $item): string {
        if ($item['type'] !== 'post_type' || $item['object_id'] <= 0) {
            return $item['url'];
        }

        if (!preg_match('/[?&](?:page_id|p)=\d+/', $item['url'])) {
            return $item['url']; // regular permalink structure - leave unchanged
        }

        // Front page: points specifically to "/" (the index.html
        // generated there anyway via copyToRootIndex()), not to its own
        // {slug}.{id}.html filename - that's the more natural URL.
        if (get_option('show_on_front') === 'page' && (int) get_option('page_on_front') === $item['object_id']) {
            return '/';
        }

        $slug = get_post_field('post_name', $item['object_id']);

        if (!$slug) {
            return $item['url']; // fallback: could not determine the slug
        }

        return '/' . $slug . '.' . $item['object_id'] . '.html';
    }

    /**
     * Replaces the href and text of the first <a> tag in $html. First
     * removes any existing "active" demo class (e.g. already present in
     * the designer's sample) and only re-adds it if $isActive is true.
     */
    private static function replaceFirstAnchor(string $html, string $url, string $label, bool $isActive, string $activeClass = ''): string {
        return preg_replace_callback(
            '/<a\b([^>]*)>(.*?)<\/a>/is',
            function (array $matches) use ($url, $label, $isActive, $activeClass) {
                $attributes = $matches[1];

                // Replace href (or add it, if none existed at all).
                if (preg_match('/\shref\s*=\s*(["\'])(.*?)\1/i', $attributes)) {
                    $attributes = preg_replace('/(\shref\s*=\s*)(["\']).*?\2/i', '$1$2' . addcslashes($url, '$\\') . '$2', $attributes);
                } else {
                    $attributes .= ' href="' . esc_attr($url) . '"';
                }

                if ($activeClass !== '') {
                    $attributes = self::toggleClass($attributes, $activeClass, $isActive);
                }

                return '<a' . $attributes . '>' . htmlspecialchars($label, ENT_QUOTES) . '</a>';
            },
            $html,
            1
        );
    }

    /**
     * Adds or removes a CSS class in the class attribute - prevents an
     * "active" demo class already hard-coded in the designer's sample
     * from sticking around on every generated page.
     */
    private static function toggleClass(string $attributes, string $className, bool $add): string {
        if (!preg_match('/\sclass\s*=\s*(["\'])(.*?)\1/i', $attributes, $classMatch)) {
            return $add ? $attributes . ' class="' . esc_attr($className) . '"' : $attributes;
        }

        $classes = array_filter(explode(' ', trim($classMatch[2])), static fn ($c) => $c !== '' && $c !== $className);

        if ($add) {
            $classes[] = $className;
        }

        $newClassAttr = ' class="' . esc_attr(implode(' ', $classes)) . '"';

        return preg_replace('/\sclass\s*=\s*(["\']).*?\1/i', $newClassAttr, $attributes, 1);
    }

    /**
     * Removes the submenu part from a parent item template and returns
     * only the "shell" (e.g. title/link) - a fallback for a menu item
     * without children when no item template of its own exists.
     */
    private static function stripSubmenuShell(string $parentItemTemplate, string $submenuWrapperMarker): string {
        if ($submenuWrapperMarker === '') {
            return $parentItemTemplate;
        }

        $fullSubmenuBlock = self::extractFullBlockWithMarkers($parentItemTemplate, $submenuWrapperMarker);

        return $fullSubmenuBlock !== '' ? str_replace($fullSubmenuBlock, '', $parentItemTemplate) : $parentItemTemplate;
    }

    private static function urlsMatch(string $menuUrl, string $currentUrl): bool {
        $normalize = static fn (string $u) => rtrim(preg_replace('#^https?://#', '', $u), '/');

        return $menuUrl !== '' && $normalize($menuUrl) === $normalize($currentUrl);
    }

    /**
     * Builds the menu tree (nested by menu_item_parent) from a WordPress
     * nav menu.
     *
     * @return array<int, array{title:string, url:string, children:array}>
     */
    private static function getMenuTree(int $menuId): array {
        if ($menuId <= 0) {
            return [];
        }

        $items = wp_get_nav_menu_items($menuId);

        if (!is_array($items)) {
            return [];
        }

        $byParent = [];

        foreach ($items as $item) {
            $byParent[(int) $item->menu_item_parent][] = [
                'id' => (int) $item->ID,
                'title' => $item->title,
                'url' => $item->url,
                'order' => (int) $item->menu_order,
                'object_id' => (int) $item->object_id,
                'type' => $item->type, // 'post_type' | 'post_type_archive' | 'taxonomy' | 'custom'
            ];
        }

        foreach ($byParent as &$group) {
            usort($group, static fn ($a, $b) => $a['order'] <=> $b['order']);
        }
        unset($group);

        $build = static function (int $parentId) use (&$build, $byParent): array {
            $result = [];

            foreach ($byParent[$parentId] ?? [] as $entry) {
                $entry['children'] = $build($entry['id']);
                $result[] = $entry;
            }

            return $result;
        };

        return $build(0);
    }

    /**
     * Looks for a marker pair delimited by HTML comments
     * ("<!-- MARKER ... -->...<!-- MARKER ... -->") and returns both the
     * complete match (including both comments) and just the content in
     * between. Standalone regex-based implementation (no position
     * scanning with post-processing).
     *
     * @return array{full: string, inner: string}|null null if no complete
     *         marker pair was found.
     */
    private static function matchMarkerBlock(string $content, string $marker): ?array {
        if ($marker === '') {
            return null;
        }

        $quotedMarker = preg_quote($marker, '/');
        $pattern = '/<!--\s*' . $quotedMarker . '.*?-->(.*?)<!--\s*' . $quotedMarker . '.*?-->/s';

        if (preg_match($pattern, $content, $matches) !== 1) {
            return null;
        }

        return ['full' => $matches[0], 'inner' => $matches[1]];
    }

    /**
     * Returns only the content between a marker comment pair.
     */
    private static function getSubpart(string $content, string $marker): string {
        return self::matchMarkerBlock($content, $marker)['inner'] ?? '';
    }

    /**
     * Returns the complete match INCLUDING both marker comments - needed
     * to replace the whole block (comments + content) with the rendering
     * result.
     */
    private static function extractFullBlockWithMarkers(string $content, string $marker): string {
        return self::matchMarkerBlock($content, $marker)['full'] ?? '';
    }
}
