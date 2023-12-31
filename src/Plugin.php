<?php

/**
 * @file
 * Contains \WPGlossarySynonyms\Plugin.
 */

namespace WPGlossarySynonyms;

class Plugin
{
    /**
     * Prefix for naming.
     *
     * @var string
     */
    const PREFIX = 'wp-glossary-synonyms';

    /**
     * Custom post type slug.
     *
     * @var string
     */
    const POST_TYPE = 'glossary-synonym';

    /**
     * Glossary term custom post type slug.
     *
     * @var string
     */
    const PARENT_POST_TYPE = 'glossary';

    /**
     * Gettext localization domain.
     *
     * @var string
     */
    const L10N = self::PREFIX;

    /**
     * Alternative spellings post meta key.
     *
     * @var string
     */
    const ALTERNATIVE_SPELLINGS = 'alternative_spellings';

    /**
     * Alternative spellings post meta key.
     *
     * @var string
     */
    const ASSOCIATED_TERM = 'associated_term';

    /**
     * Plugin initialization method.
     */
    public function init()
    {
        $this->registerSynonymPostType();

        new PostTypes();

        add_action('admin_menu', array($this, 'hideTaxonomies'));

        if (is_admin()) {
            return;
        }

        // Process terms and synonyms for linkifying content.
        add_filter('wpg_glossary_terms_query_args', array($this, 'querySynonyms'));

        // Include sysnonym post type in frontend search query.
        add_filter('wpg_list_query_args', array($this, 'querySynonyms'));

        // Instantiate class only for frontend, since that is where the limit counter is used.
        new Linkify();

        // Replace synonyms permalink with parent glossary term permalink, as requested
        add_filter('post_type_link', array($this, 'maybeReplacePermalink'), 10, 2);

        // Get parent post excerpt if synonym excerpt is empty
        add_filter('wpg_tooltip_excerpt', array($this, 'maybeReplaceExcerpt'));

        // Load plugin js
        add_action('wp_enqueue_scripts', array($this, 'loadScript'));

        //  Custom functionality for sysnonyms to show the parent term title in brackets.
        add_filter('wpg_tooltip_term_title_end', array($this, 'alterTootltipSynonymTitle'), 10, 2);

        // Add shortcode functionliaty to list all synonyms, comma separated.
        add_shortcode('wpgs_list', array($this, 'setShortcode'));

        // Search by sppelings.
        add_filter('wpg_list_item_start', array($this, 'addSpellingsAttributeToListItem'));

        // Remove automatic paragraph tag from glossary tooltip content.
        add_filter('wpg_glossary_tooltip_content', function ($html) {
            return str_replace(['&lt;p&gt;','&lt;/p&gt;'], '', $html);
        });
    }

    public function alterTootltipSynonymTitle($title)
    {
        $postID = get_the_ID();
        if (get_post_type($postID) === self::POST_TYPE && $associatedTerm = $this->getAssociatedTerm($postID)) {
            return ' <br/><a style="color: inherit;" class="main-term" href="' . get_permalink($associatedTerm) . '">(' . $associatedTerm->post_title . ')</a>' . $title;
        }
        return $title;
    }

    private function getAssociatedTerm($synonymID)
    {
        if ($associatedTermID = get_post_meta($synonymID, self::ASSOCIATED_TERM, true)) {
            return get_post($associatedTermID);
        }
        return null;
    }

    public function registerSynonymPostType()
    {
        $labels = [
            'name' => _x('Glosary synonym', 'post type general name', 'wp-glossary-synonyms'),
            'singular_name' => _x('Glosary synonym', 'post type singular name', 'wp-glossary-synonyms'),
            'menu_name' => _x('Glosary synonym', 'admin menu', 'wp-glossary-synonyms'),
            'name_admin_bar' => _x('Glosary synonym', 'add new on admin bar', 'wp-glossary-synonyms'),
            'add_new' => _x('Add New Synonym', 'glossary', 'wp-glossary-synonyms'),
            'add_new_item' => __('Add New Synonym', 'wp-glossary-synonyms'),
            'new_item' => __('New Synonym', 'wp-glossary-synonyms'),
            'edit_item' => __('Edit Synonym', 'wp-glossary-synonyms'),
            'view_item' => __('View Synonym', 'wp-glossary-synonyms'),
            'all_items' => __('All Synonyms', 'wp-glossary-synonyms'),
            'search_items' => __('Search Synonyms', 'wp-glossary-synonyms'),
            'parent_item_colon' => __('Parent Synonyms:', 'wp-glossary-synonyms'),
            'not_found' => __('No synonyms found.', 'wp-glossary-synonyms'),
            'not_found_in_trash' => __('No synonyms found in Trash.', 'wp-glossary-synonyms')
        ];

        $args = apply_filters(
            'wpg_post_type_glossary_args',
            [
                'labels' => $labels,
                'description' => __('Description.', 'wp-glossary-synonyms'),
                'menu_icon' => 'dashicons-editor-spellcheck',
                'capability_type' => 'post',
                'rewrite' => false,
                'public' => false,
                'publicly_queryable' => false,
                'show_ui' => true,
                'show_in_nav_menus' => false,
                'show_in_menu' => 'edit.php?post_type=glossary',
                // 'show_in_menu' => true,
                'query_var' => true,
                'has_archive' => false,
                'hierarchical' => false,
                'menu_position' => 59,
                'supports' => array('title', 'excerpt', 'author'),
                'register_meta_box_cb' => [__CLASS__, 'add_meta_boxes']
            ]
        );

        register_post_type(self::POST_TYPE, $args);
    }

    public static function add_meta_boxes()
    {
        add_meta_box(
            'meta-box-glossary-attributes',
            __('Custom Attributes', 'wp-glossary-synonyms'),
            [__CLASS__, 'meta_box_glossary_synonym_attributes'],
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    public static function meta_box_glossary_synonym_attributes($post)
    {
        wp_nonce_field('wpg_meta_box', 'wpg_meta_box_nonce');

        ?>
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row"><label for="associated_glossary_term">
                            <?php _e('Associated glossary term', 'wp-glossary-synonyms'); ?>
                        </label></th>
                    <td>
                        <?php
                        /**
                         * Since dropdown pages only works with hierarchical defined custom post types,
                         * and `glossary` is defined as non hierachical in the parent plugin,
                         * fake it to be hierarchical and revert after dropdown output.
                         */
                        global $wp_post_types;
                        $selected_post_id = get_post_meta($post->ID, self::ASSOCIATED_TERM, true);
                        $save_hierarchical = $wp_post_types[self::PARENT_POST_TYPE]->hierarchical;
                        $wp_post_types[self::PARENT_POST_TYPE]->hierarchical = true;
                        wp_dropdown_pages(
                            array(
                                'name' => Plugin::ASSOCIATED_TERM,
                                'selected' => empty($selected_post_id) ? 0 : $selected_post_id,
                                'post_type' => self::PARENT_POST_TYPE,
                                'show_option_none' => 'None selected',
                            )
                        );
                        $wp_post_types[self::PARENT_POST_TYPE]->hierarchical = $save_hierarchical;
                        ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="spellings">
                            <?php _e('Alternative spellings', 'wp-glossary-synonyms'); ?>
                        </label></th>
                    <td>
                        <input 
                            type="text" 
                            class="large-text" 
                            name="<?php echo Plugin::ALTERNATIVE_SPELLINGS; ?>" 
                            value="<?php echo esc_attr(get_post_meta($post->ID, Plugin::ALTERNATIVE_SPELLINGS, true)); ?>" 
                        />
                        <p class="description">
                            <?php _e('You can define multiple comma separated spellings here.', 'wp-glossary-synonyms'); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    public function setShortcode($args)
    {
        $query_args = array(
            'post_type' => self::POST_TYPE,
            'posts_per_page' => -1,
            'fields' => ['post_title'],
            'orderby' => 'title',
            'order' => 'ASC'
        );
        $associatedSynonymId = $args['term_id'] ?? (self::PARENT_POST_TYPE === get_post_type() ? get_the_ID() : null);
        if ($associatedSynonymId) {
            $query_args['meta_key'] = self::ASSOCIATED_TERM;
            $query_args['meta_value'] = $associatedSynonymId;
        }
        $synonyms = wp_list_pluck(get_posts($query_args), 'post_title');
        return implode(', ', $synonyms);
    }

    public function maybeReplacePermalink($permalink, $post)
    {
        if ($post->post_type === self::POST_TYPE && $associatedTerm = $this->getAssociatedTerm($post->ID)) {
            return get_permalink($associatedTerm);
        }
        return $permalink;
    }

    public function maybeReplaceExcerpt($content)
    {
        global $post;
        if ($post->post_type === self::POST_TYPE && empty($post->post_excerpt)) {
            $associatedTerm = $this->getAssociatedTerm($post->ID);
            if ($associatedTerm) {
                $content = $associatedTerm->post_excerpt;
                if (empty($content)) {
                    $content = $associatedTerm->post_content;
                }
                return $content;
            }
        }
        return $content;
    }

    public function querySynonyms($args)
    {
        $args['post_type'] = ['glossary', 'glossary-synonym'];
        return $args;
    }

    public function hideTaxonomies()
    {
        remove_submenu_page('edit.php?post_type=glossary', 'edit-tags.php?taxonomy=glossary_cat&amp;post_type=glossary');
        remove_submenu_page('edit.php?post_type=glossary', 'edit-tags.php?taxonomy=glossary_tag&amp;post_type=glossary');
    }

    public function loadScript()
    {
        wp_enqueue_script('wpgs-script', plugins_url('assets/main.js', __DIR__), array('jquery'));
    }

    public function addSpellingsAttributeToListItem($markup)
    {
        global $post;
        if ($spellings = get_post_meta($post->ID, self::ALTERNATIVE_SPELLINGS, true)) {
            $spellings = array_map(function ($spelling) {
                return trim($spelling);
            }, explode(',', strtolower($spellings)));
            $markup = rtrim($markup, '>') . ' data-spellings="' . implode('|', $spellings) . '">';
            return $markup;
        }
        return $markup;
    }
}
