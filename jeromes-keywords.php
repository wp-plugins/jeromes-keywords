<?php
/*
Plugin Name: Jerome's Keywords
Plugin URI: http://vapourtrails.ca/wp-keywords
Version: 1.4
Description: Allows keywords to be associated with each post.  These keywords can be used for page meta tags, included in posts for site searching or linked like Technorati tags.
Author: Jerome Lavigne
Author URI: http://vapourtrails.ca
*/

/*	Copyright 2005  Jerome Lavigne  (email : darkcanuck@vapourtrails.ca)
	
	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.
	
	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
	
	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

/* Credits:
	Special thanks to Stephanie Booth (http://climbtothestars.org), whose excellent "Bunny's Technorati Tags" plugin
	was a great learning resource.  Her plugin is responsible for my use of the get/add/delete_post_meta() functions
	rather than trying to access the database directly.
	
	Thanks also to Dave Metzener, Mark Eckenrode, Dan Taarin and others who have provided feedback, spotted bugs,
	and suggested improvements.
*/

/* ChangeLog:

13-Mar-2005:  Version 1.4
		- Added ability to automatically generate .htaccess rewrite rules for keyword searches.
			- Can be turned off with new KEYWORDS_REWRITERULES flag.
			- Necessary for sites that use /index.php/*%blah% style permalinks
			- Thanks to Dave Metzener for finding the original bug and beta testing the fix.
		- Added formatting parameters to the_keywords() and get_the_keywords() to allow more control over the output.
		- Fixed XHTML validation bug:  added a space prior to title attribute in keyword links.
		- Fixed keyword link encoding for links that include '/' (now left as-is rather than encoded)
		- Temporary fix to prevent conflicts with mini-posts plugin:  removes mini-post's filters when a tag search is performed.

1-Mar-2005:  Version 1.3
		- Added ability to do site keyword searches.  This now the default keyword link behaviour.
		- Keyword search can also use its own template file.
		- If including categories, local links will return that category (not the keyword search).
		- Added filter for Atom feed content if not sending rss summaries only.

27-Feb-2005:  Version 1.2
		- Fixed search URL for sites not using permalinks (this is automatically detected)
		- If not using permalinks then the Atom feed will contain Technorati links instead of local search link (local search can't be parsed by Technorati)

26-Feb-2005:  Version 1.1
		- added ability to suppress link title if value passed is an empty string (used for Atom feed)
		- updated keywords_appendtags() to suppress link title.

25-Feb-2005:  Version 1.0 publicly released

*/

/* *****INSTRUCTIONS*****

Entering Keywords - simply type all of your keywords into the keywords field when creating/editing posts.  Keywords
				should be separated by commas and can include spaces (key phrases).

Template Tags - you can use the following php template tags to insert the keywords into your template

	the_keywords() - can be used outside the loop
			Outputs a comma-separated list of all categories & keywords on the current page.  You can use this
			to add a keyword meta tag to your page's title block:
				<meta name="keywords" content="<?php the_keywords(); ?>" />

			This function can take three optional parameters:
				before (default = blank) - text/html to insert before each keyword
				after (default = blank) - text/html to insert after each keyword
				separator (default = ",") - text/html to insert between keywords
			get_the_keywords() is a non-echoing version

	the_post_keywords() - must be used inside the loop
			Outputs a comma-separated list of the keywords for the current post.  This function can take one optional parameters:
				include_cats (default=false) - if true, post categories are included in the list.
			get_the_post_keywords() is a non-echoing version.

	the_post_keytags() - must be used inside the loop
			Outputs the keywords for the current post as a series of links.  By default these link a query for other posts with matching
			keywords (can also link to the WordPress search function or to Technorati's page for that tag)
			This function can take three optional parameters:
				include_cats (default=false) - if true, post categories are included in the list.
				local_search (default="tag") - if false or "technorati", the links will be to Technorati's tag page for the keyword instead,
										if "search", the links will be to the local Wordpress search function.
				link_title (default="") - alternate link title text to use, e.g. "My link title for" (tag name will be added at the end)
				
			An example from my site:
				<?php if (have_posts()) : while (have_posts()) : the_post(); ?>
				 [...]
				<div class="post">
					 [...]
					<div class="subtags">
			>>>			Tags: <?php the_post_keytags(); ?>
					</div>
					 [...]
				<?php endwhile; else: ?>
			
			get_the_post_keytags() is a non-echoing version.

	is_keyword() - can be used outside the loop
			Returns true if the current view is a keyword/tag search

	the_search_keytag() - can be used outside the loop
			Outputs the keyword/tag used for the search
			get_the_search_keytag() is a non-echoing version


Rewrite Rules - The plugin can generate new tag search rewrite rules automatically.  You need to 
			re-save your permalinks settings (Options -> Permalinks) for this to occur.
			If your .htaccess file cannot be written to by WordPress, add the following to your 
			.htaccess file to use the tag search feature, preferably below the "# END WordPress" line:

RewriteRule ^tag/(.+)/feed/(feed|rdf|rss|rss2|atom)/?$ /index.php?tag=$1&feed=$2 [QSA,L]
RewriteRule ^tag/(.+)/(feed|rdf|rss|rss2|atom)/?$ /index.php?tag=$1&feed=$2 [QSA,L]
RewriteRule ^tag/(.+)/page/?([0-9]{1,})/?$ /index.php?tag=$1&paged=$2 [QSA,L]
RewriteRule ^tag/(.+)/?$ /index.php?tag=$1 [QSA,L]

*/


/* You can change these constants if you wish for further customization*/
define('KEYWORDS_META', 'keywords');							// post meta key used in the wp database
define('KEYWORDS_TECHNORATI', 'http://technorati.com/tag');		// Technorati link to use if local search is false
define('KEYWORDS_ATOMTAGSON', '1');								// flag to add tags to Atom feed (required for Technorati)
define('KEYWORDS_QUERYVAR', 'tag');								// get/post variable name for querying tag/keyword from WP
define('KEYWORDS_TAGURL', 'tag');								// URL to use when querying tags
define('KEYWORDS_TEMPLATE', 'keywords.php');					// template file to use for displaying tag queries
define('KEYWORDS_SEARCHURL', 'search');							// local search URL (from mod_rewrite rules)
define('KEYWORDS_REWRITERULES', '1');							// flag to determine if plugin can change WP rewrite rules

/* Shouldn't need to change this - can set to 0 if you want to force permalinks off */
if (isset($wp_rewrite) && $wp_rewrite->using_permalinks()) {
	define('KEYWORDS_REWRITEON', '1');							// nice permalinks, yes please!
	define('KEYWORDS_LINKBASE', $wp_rewrite->root);				// set to "index.php/" if using that style
} else {
	define('KEYWORDS_REWRITEON', '0');							// old school links
	define('KEYWORDS_LINKBASE', '');							// don't need this
}

/* use in the loop*/
function get_the_post_keywords($include_cats=true) {
	$keywords = '';

	if ($include_cats) {
		$categories = get_the_category();
		foreach($categories as $category) {
			if (!empty($keywords))
				$keywords .= ", ";
			$keywords .= $category->cat_name;
		}
	}	

	$post_keywords = get_post_custom_values(KEYWORDS_META);
	if (!empty($keywords))
		$keywords .= ", ";
	$keywords .= $post_keywords[0];
	
	return( $keywords );
}

/* use in the loop*/
function the_post_keywords($include_cats=true) {
	echo get_the_post_keywords($include_cats);
}

/* use in the loop*/
function get_the_post_keytags($include_cats=false, $localsearch="tag", $linktitle=false) {
	// determine link mode
	$linkmode = strtolower(trim($localsearch));
	switch ($linkmode) {
		case '':
		case 'technorati':
			$linkmode = 'technorati';
			break;
		case 'search':
			$linkmode = 'search';
			break;
		//case 'tag':
		//case 'keyword':
		default:
			$linkmode = 'tag';
			break;
	}

	$output = "";
	if ($linktitle === false)
		$linktitle = ($linkmode == 'technorati') ? "Technorati tag page for" : "Search site for";
	
	// do categories separately to get category links instead of tag links
	if ($include_cats) {
		$categories = get_the_category();
		foreach($categories as $category) {
			$keyword = $category->cat_name;
			if ($linkmode == 'technorati')
				$taglink = KEYWORDS_TECHNORATI . "/" . str_replace('%2F', '/', urlencode($keyword));
			else
				$taglink = get_category_link($category->category_id);
			$tagtitle = empty($linktitle) ? "" : " title=\"$linktitle $keyword\"";
			
			if (!empty($output))
				$output .= ", ";
			$output .= "<a href=\"$taglink\" rel=\"tag\"$tagtitle>$keyword</a>";
		}
	}	
	
	$post_keywords = get_post_custom_values(KEYWORDS_META);
	if (is_array($post_keywords)) {
		$keywordlist = array();
		foreach($post_keywords as $post_keys)
			$keywordlist = array_merge($keywordlist, explode(",", $post_keys));
		
		foreach($keywordlist as $keyword) {
			$keyword = trim($keyword);
			if (!empty($keyword)) {
				switch ($linkmode) {
					case 'tag':
						if (KEYWORDS_REWRITEON)
							$taglink = get_settings('home') . '/' . KEYWORDS_LINKBASE . KEYWORDS_TAGURL . 
										'/' . str_replace('%2F', '/', urlencode($keyword));
						else
							$taglink = get_settings('home') . "/?" . KEYWORDS_TAGURL .  "=" . urlencode($keyword);
						break;
					case 'technorati':
						$taglink = KEYWORDS_TECHNORATI . "/" . str_replace('%2F', '/', urlencode($keyword));
						break;
					case 'search':
						if (KEYWORDS_REWRITEON)
							$taglink = get_settings('home') . '/' . KEYWORDS_LINKBASE . KEYWORDS_SEARCHURL . 
										'/' . str_replace('%2F', '/', urlencode($keyword));
						else
							$taglink = get_settings('home') . '/?s=' . urlencode($keyword) . '&submit=Search';
						break;
				}
				$tagtitle = empty($linktitle) ? "" : " title=\"$linktitle $keyword\"";
				
				if (!empty($output))
					$output .= ", ";
				$output .= "<a href=\"$taglink\" rel=\"tag\"$tagtitle>$keyword</a>";
			}
		}
	}
	return($output);
}

/* use in the loop*/
function the_post_keytags($include_cats=false, $localsearch=true, $linktitle=false) {
	$taglist = get_the_post_keytags($include_cats, $localsearch, $linktitle);
	
	if (empty($taglist))
		echo "none";
	else
		echo $taglist;
}

/* works outside the loop*/
function get_the_keywords($before='', $after='', $separator=',') {
	global $cache_categories, $post_meta_cache;
	
	$keywords = "";
	
	if (isset($cache_categories)) {
		foreach($cache_categories as $category)
			$keywordarray[$category->cat_name] += 1;
	}
		
	if (isset($post_meta_cache)) {
		foreach($post_meta_cache as $post_meta)
			$keywordarray[ $post_meta[KEYWORDS_META][0] ] += 1;
	}

	if (isset($keywordarray)) {
		foreach($keywordarray as $key => $count) {
			if (!empty($keywords))
				$keywords .= $separator;
			$keywords .= $before . $key . $after;
		}
	}
	
	return ($keywords);
}

/* works outside the loop */
function the_keywords($before='', $after='', $separator=',') {
	echo get_the_keywords($before, $after, $separator);
}

function is_keyword() {
	if (!empty($GLOBALS[KEYWORDS_QUERYVAR]))
		return true;
	else
		return false;
}

function get_the_search_keytag() {
	return($GLOBALS[KEYWORDS_QUERYVAR]);
}

function the_search_keytag() {
	echo get_the_search_keytag();
}



/***** Add actions *****/

/* editing */
add_filter('simple_edit_form', 'keywords_edit_form');
add_filter('edit_form_advanced', 'keywords_edit_form');
add_filter('edit_post', 'keywords_update');
add_filter('publish_post', 'keywords_update');
add_filter('save_post', 'keywords_update');

/* for keyword/tag queries */
add_filter('query_vars', 'keywords_addQueryVar');
add_action('parse_query', 'keywords_parseQuery');

/* generate rewrite rules for above queries */
if (KEYWORDS_REWRITEON && KEYWORDS_REWRITERULES)
	add_filter('search_rewrite_rules', 'keywords_createRewriteRules');

/* Atom feed */
if (KEYWORDS_ATOMTAGSON) {
	add_filter('the_excerpt_rss', 'keywords_appendTags');
	if (!get_settings('rss_use_excerpt'))
		add_filter('the_content', 'keywords_appendTags');
}

/***** Callback functions *****/
function keywords_edit_form() {
	global $postdata;

	$post_keywords = get_post_meta($postdata->ID, 'keywords', true);

	// output HTML & JS
	echo "
		<fieldset id=\"postkeywords\" style=\"clear: both;\">
			<legend>Keywords</legend>
			<div>
				<textarea rows=\"1\" cols=\"40\" name=\"keywords_list\" tabindex=\"4\" id=\"keywords_list\" style=\"margin-left: 1%; width: 98%; height: 1.8em;\">$post_keywords</textarea>
			</div>
		</fieldset>
		";
}

function keywords_update($id) {
	global $wpdb;

	// remove old value
	delete_post_meta($id, KEYWORDS_META);

	// clean up keywords list & save
	$keyword_list = "";
	$post_keywords = explode(",", $_REQUEST['keywords_list']);
	foreach($post_keywords as $keyword) {
		if ( !empty($keyword ) ) {
			if ( !empty($keyword_list) )
				$keyword_list .= ",";
			$keyword_list .= trim($keyword);
		}
	}

	if (!empty($keyword_list) )
		add_post_meta($id, KEYWORDS_META, $keyword_list);
}

function keywords_appendTags(&$text) {
	global $doing_rss, $feed;
	
	if ( (!$doing_rss) || ($feed != 'atom') )
		return($text);
	
	$local = KEYWORDS_REWRITEON ? "tag" : "technorati";
	
	$taglist = get_the_post_keytags(true, $local, "");
	if (empty($taglist))
		return($text);
	else
		return($text . " \n Tags: " . $taglist);
}

function keywords_addQueryVar($wpvar_array) {
	$wpvar_array[] = KEYWORDS_QUERYVAR;
	return($wpvar_array);
}

function keywords_parseQuery() {
	// if this is a keyword query, then reset other is_x flags and add query filters
	if (is_keyword()) {
		global $wp_query;
		$wp_query->is_single = false;
		$wp_query->is_page = false;
		$wp_query->is_archive = false;
		$wp_query->is_search = false;
		$wp_query->is_home = false;

		// mini-posts plugin doesn't play nice with this plugin
        remove_filter('posts_where', 'mini_posts_where');
        remove_filter('posts_join', 'mini_posts_join');

		add_filter('posts_where', 'keywords_postsWhere');
		add_filter('posts_join', 'keywords_postsJoin');
		add_action('template_redirect', 'keywords_includeTemplate');
	}
}

function keywords_postsWhere($where) {
	global $wpdb;
	$where .= " AND $wpdb->postmeta.meta_key = '" . KEYWORDS_META . "' ";
	$where .= " AND $wpdb->postmeta.meta_value LIKE '%" . $GLOBALS[KEYWORDS_QUERYVAR] . "%' ";
	return ($where);
}

function keywords_postsJoin($join) {
	global $wpdb;
	$join .= " LEFT JOIN $wpdb->postmeta ON ($wpdb->posts.ID = $wpdb->postmeta.post_id) ";
	return ($join);
}

function keywords_includeTemplate() {

	if (is_keyword()) {
		$template = '';
		
		if ( file_exists(TEMPLATEPATH . "/" . KEYWORDS_TEMPLATE) )
			$template = TEMPLATEPATH . "/" . KEYWORDS_TEMPLATE;
		else if ( file_exists(TEMPLATEPATH . "/tags.php") )
			$template = TEMPLATEPATH . "/tags.php";
		else
			$template = get_category_template();
		
		if ($template) {
			include($template);
			exit;
		}
	}
	return;
}

function keywords_createRewriteRules($rewrite) {
	global $wp_rewrite;
	
	// add rewrite tokens
	$keytag_token = '%' . KEYWORDS_QUERYVAR . '%';
	$wp_rewrite->rewritecode[] = $keytag_token;
	$wp_rewrite->rewritereplace[] = '(.+)';
	$wp_rewrite->queryreplace[] = KEYWORDS_QUERYVAR . '=';
	
	$keywords_structure = $wp_rewrite->root . KEYWORDS_QUERYVAR . "/$keytag_token";
	$keywords_rewrite = $wp_rewrite->generate_rewrite_rules($keywords_structure);
	
	return ( $rewrite + $keywords_rewrite );
}

?>