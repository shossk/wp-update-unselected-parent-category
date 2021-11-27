<?php
ini_set("display_errors", 0);
require_once(ABSPATH . '/wp-load.php');

/**
* @package UPPT
* @version 1.0
*/
/*
Plugin Name: Update Unselected Parent Categories
Plugin URI:
Description: Change the parent category in bulk for all unselected categories
Author: Sho Sasaki
Version: 1.0
Author URI:
License: MIT
*/

function search_parent_category ($term_data, $term_ids = [], $array = []) {
  if (!$term_data && empty($term_ids)) return false;

  $term_id = current($term_ids);
  $keyIndex = array_search($term_id, array_column($term_data, 'term_id'));

  if ($keyIndex !== false) {
    $array[] = $term_id;
    $find_term = $term_data[$keyIndex];
    $parent = $find_term->parent;
    if (isset($parent) && $parent !== 0) $term_ids[] = $parent;
  }

  array_shift($term_ids);

  if (empty($term_ids)) {
    return $array;
  } else {
    return search_parent_category($term_data, $term_ids, $array);
  }
}

function update_category($post_type, $taxonomy) {
  $term_data = array_values(get_terms($taxonomy));

  $args = array (
    'post_type' => $post_type,
    'no_found_rows' => true,
    'posts_per_page' => -1,
    'fields' => 'ids'
  );

  $wp_query = new WP_query($args);
  $ids = $wp_query->have_posts() ? $wp_query->posts : [];
  wp_reset_postdata();

  foreach ($ids as $id) {
    $terms = get_the_terms($id, $taxonomy);
    $ids = [];
    $parents = [];

    foreach ( $terms as $term ) {
      $ids[] = $term->term_id;
      $parents[] = $term->parent;
    }

    $parents = array_values(array_unique($parents));

    if (($key = array_search(0, $parents)) !== false) {
      unset($parents[$key]);
      $parents = array_values($parents);
    }

    $diff = array_values(array_diff($parents, $ids));
    if (!empty($diff)) $diff = search_parent_category($term_data, $diff);

    if (count($diff)) {
      echo json_encode($diff);
      wp_set_post_terms($id, $diff, $taxonomy, true);
    }
  }
}

function uppt_echo_html() {
  if (isset($_POST)) {
    if ($_POST['submit'] === 'update') {
      $selected = $_POST['uppt_selected'];
      update_category($selected['post_type'], $selected['taxonomy']);
    }
  }

  $post_types = ['post']; //initial value
  $post_types = array_merge($post_types, array_values(get_post_types(array('public' => true, '_builtin' => false ), 'names')));

  $taxonomies = [];
  foreach ($post_types as $value) {
    $tmp_taxonomies = get_object_taxonomies($value);
    $taxonomies[$value] = array_map(function( $value ) {
      $is_hierarchical = is_taxonomy_hierarchical($value);
      return array('name' => $value, 'hierarchical' => $is_hierarchical);
    }, $tmp_taxonomies);
  }
?>
<style>
  .select-post-types {
    display: flex;
    column-gap: 5px;
  }
</style>

<div class="uppt_content">
  <h2>Select Taxonomy</h2>
  <p>Select the taxonomy you want to update.</p>
  <form id="form" method="POST">
    <div class="select-post-types">
      <select id="post_type" name="uppt_selected[post_type]">
        <?php foreach ($post_types as $post_type) : ?>
        <option value="<?= $post_type ?>"><?= $post_type ?>
        </option>
        <?php endforeach ?>
      </select>
      <select id="taxonomy" name="uppt_selected[taxonomy]"></select>
      <input type="hidden" name="submit" value="update">
      <!-- <button type="submit" class="button">Dry run</button> -->
      <button type="submit" class="button-primary">UPDATE</button>
    </div>
  </form>
</div>

<script>
  var taxonomies = <?php echo json_encode($taxonomies) ?> ;
  var $select_post_type = jQuery(`select#post_type`);
  var $select_taxonomy = jQuery(`select#taxonomy`);

  function change_select() {
    var val = $select_post_type.val()
    var types = taxonomies[val];
    $select_taxonomy.children().remove();
    jQuery.each(types, function(index, value) {
      $select_taxonomy.append(jQuery('<option>', {
        value: value.name,
        hierarchical: value.hierarchical,
        text: value.name
      }));
    })
  }

  change_select();

  $select_post_type.change(function() {
    change_select();
  })

  jQuery('#form').submit(function() {
    if ($select_taxonomy.find('option:selected').attr('hierarchical') == 'false') {
      alert('The selected taxonomy has no parent-child relationship');
      return false;
    } else if (!$select_taxonomy.val()) {
      alert('Please select a taxonomy');
      return false;
    }

    if (!confirm('Are you sure you want to do this?')) {
      return false;
    }
  })
</script>

<?php
}

function uppt_add_pages()
{
  add_menu_page('UPPT', 'UPPT', 'level_0', __FILE__, 'uppt_echo_html', 'dashicons-update-alt', 100);
}

// 管理メニューに追加するフック
add_action('admin_menu', 'uppt_add_pages');
