<?php
require_once('ooWP_Query.class.php');

/**
 * This class contains functions which are shared across all post types, and across all sites.
 * It should be extended for each site by e.g. oiPost or irrPost, which should in turn be extended by
 * individual post types e.g. irrEvent, irrShopItem
 *
 * These properties of WP_Post are proxied here.
 * @property int $ID;
 * @property int $post_author
 * @property string $post_date
 * @property string $post_date_gmt
 * @property string $post_content
 * @property string $post_title
 * @property string $post_excerpt
 * @property string $post_status
 * @property string $comment_status
 * @property string $ping_status
 * @property string $post_password
 * @property string $post_name
 * @property string $to_ping
 * @property string $pinged
 * @property string $post_modified
 * @property string $post_modified_gmt
 * @property string $post_content_filtered
 * @property int $post_parent
 * @property string $guid
 * @property int $menu_order
 * @property string $post_type
 * @property string $post_mime_type
 * @property int $comment_count
 * @property string $filter
 * @property array $ancestors
 * @property string $page_template
 */
abstract class ooPost
{

	protected $_cache = array();
	protected static $_staticCache = array();

	/**
	 * @var WP_Post
	 */
	protected $post;


#region Getters, Setters, Construct, Init

	/**
	 * @param $data int | array | object
	 */
	public function __construct($data)
	{
		//Make sure it's an object
		$this->post = self::getPostObject($data);
	}

	/**
	 * Converts the data into a wordpress post object
	 * @static
	 * @param mixed $data
	 * @return WP_Post
	 */
	public static function getPostObject($data)
	{
		if (is_array($data)) {
			return new WP_Post((object)$data);
		} else if (is_object($data) && $data instanceof WP_Post) {
			return $data;
		} else if (is_object($data)) {
			return new WP_Post($data);
		} else if (is_numeric($data) && is_integer($data+0)) {
			return get_post($data);
		} else {
			//TODO: should this throw an exception instead?
			global $post;
			return $post;
		}
	}

	/**
	 * @static
	 * Should be run with the wordpress init hook
	 */
	public static function init()
	{
		$className = get_called_class();
		$class = new ReflectionClass($className);
		if (!$class->isAbstract()) {
			static::register();
			add_filter('save_post' , function($postId, $postData) use ($className) {
				if ($postData && $postData->post_type == $className::postType()) {
					$post = ooPost::fetchById($postId);
					if ($post) {
						$post->onSave($postData);
					}
				}
			}, '99', 2); // use high priority value to ensure this happens after acf finishes saving its metadata
		}
	}

	/**
	 * @static
	 * Should be run with the wordpress init hook
	 */
	public static function bruv()
	{
	}

	/**
	 * @static
	 * Called after all oowp classes have been registered
	 * @deprecated Replaced by bruv()
	 */
	public static function postRegistration() { /* do nothing by default */ }

	/**
	 * Override this to hook into the save event. This is called with low priority so
	 * all fields should be already saved
	 */
	public function onSave($postData) {
		// do nothing
	}

	/**
	 * @param $name
	 * @param $args
	 * @return mixed
	 * @throws Exception
	 */
	public function __call($name, $args)
	{
		if (function_exists($name)) {
			oofp('Using default wordpress function ' . $name . ' and global $post');
			global $post;
			$post = $this;
			setup_postdata($this);
			return call_user_func_array($name, $args);
			wp_reset_postdata();
		} elseif (function_exists("wp_" . $name)) {
			$name = "wp_" . $name;
			oofp('Using default wordpress function wp_' . $name . ' and global $post');
			global $post;
			$post = $this;
			setup_postdata($this);
			return call_user_func_array($name, $args);
			wp_reset_postdata();
		} else {
			trigger_error('Attempt to call non existenty method ' . $name . ' on class ' . get_class($this));
			//throw new Exception(sprintf('The required method "%s" does not exist for %s', $name, get_class($this)));
		}
	}

	/**
	 * Proxy magic properties to WP_Post
	 * @param string $name
	 * @return mixed
	 */
	public function __get($name) {
		return $this->post->$name;
	}

	/**
	 * Proxy magic properties to WP_Post
	 * @param string $name
	 * @param mixed $value
	 * @return mixed
	 */
	public function __set($name, $value) {
		return $this->post->$name = $value;
	}

	/**
	 * Proxy magic properties to WP_Post
	 * @param string $name
	 * @return mixed
	 */
	public function __isset($name) {
		return isset($this->post->$name);
	}

	/**
	 * Returns the name of the function that called whatever called the caller :)
	 * e.g. if theFunction() called theOtherFunction(), theOtherFunction() could call getCaller(), which
	 * would return 'theFunction'
	 * @param null $function Don't supply this
	 * @param int $diff Don't supply this
	 * @return string
	 */
	protected function getCaller($function = null, $diff = 1) {
		if (!$function) {
			return $this->getCaller(__FUNCTION__, $diff+2);
		}

		$stack = debug_backtrace();
		$stackSize = count($stack);

		$caller = '';
		for ($i = 0; $i < $stackSize; $i++) {
			if ($stack[$i]['function'] == $function && ($i + $diff) < $stackSize) {
				$caller = $stack[$i + $diff]['function'];
				break;
			}
		}

		return $caller;
	}

	/**
	 * Gets the cached value for the function that called this
	 * @return mixed
	 */
	protected function getCacheValue() {
		$functionName = $this->getCaller();
		return (array_key_exists($functionName, $this->_cache) ? $this->_cache[$functionName] : null);
	}

	/**
	 * Sets and returns the cached value for the function that called this
	 * @param $value
	 * @return mixed
	 */
	protected function setCacheValue($value) {
		$this->_cache[$this->getCaller()] = $value;
		return $value;
	}

#endregion

#region Default getters

	protected static $postTypes = array();

	/**
	 * @static
	 * @return string - the post name of this class derived from the classname
	 */
	public static function postType()
	{
		$class = get_called_class();
		if (!array_key_exists($class, static::$postTypes)) {
			$postType = null;
			if (preg_match('/([A-Z].*)/m', $class, $regs)) {
				$match = $regs[1];
				$postType = lcfirst(from_camel_case($match));
			}
			static::$postTypes[$class] = $postType;
		}

		$postType = static::$postTypes[$class];
		if (!$postType) {
			die('Invalid post type');
		}
		return $postType;
	}

	/**
	 * @static
	 * @return string - the human-friendly name of this class, derived from the post name
	 */
	public static function friendlyName() {
		return ucwords(str_replace('_', ' ', static::postType()));
	}

	/**
	 * @static
	 * @return string - the human-friendly name of this class, derived from the post name
	 */
	public static function friendlyNamePlural() {
		return static::friendlyName() . 's';
	}

	/**
	 * Gets all terms associated with this post, indexed by their taxonomy.
	 * @param null $taxonomies - restrict to only one or more taxonomies
	 * @param bool $includeEmpty - return empty
	 * @return array - term objects
	 */
	/*	public function terms($taxonomies = null, $includeEmpty = false)
	 {
		 if (!$taxonomies) {
			 $taxonomies = ooTaxonomy::fetchAllNames();
		 } else if (!is_array($taxonomies)) {
			 $taxonomies = array($taxonomies);
		 }
		 $terms = array();
		 foreach ($taxonomies as $taxonomy) {
			 $currentTerms = wp_get_post_terms($this->ID, $taxonomy);
			 if ($currentTerms || $includeEmpty) {
				 foreach ($currentTerms as $term) {
					 $terms[] = ooTerm::fetch($term);

				 }
			 }
		 }
		 return $terms;
	 }*/

	/**
	 * @deprecated Alias of connected
	 */
	public function getConnected($targetPostType, $single = false, $queryArgs = array(), $hierarchical = false){
		return $this->connected($targetPostType, $single, $queryArgs, $hierarchical);
	}

	public static function getConnectionName($targetType) {
		$types = array($targetType, self::postType());
		sort($types);
		return implode('_', $types);
	}

	/**
	 * @param $posts array Array of posts/post ids
	 */
	public function connectAll($posts) {
		foreach ($posts as $post) {
			$this->connect($post);
		}
	}

	/**
	 * @param $post int|object|ooPost
	 * @param array $meta
	 */
	public function connect($post, $meta = array()) {
		$post = ooPost::createPostObject($post);
		if ($post) {
			$connectionName = self::getConnectionName($post->post_type);
			/** @var P2P_Directed_Connection_Type $connectionType */
			$connectionType = p2p_type($connectionName);
			if ($connectionType) {
				$p2pId = $connectionType->connect($this->ID, $post->ID);
				foreach ($meta as $key=>$value) {
					p2p_update_meta($p2pId, $key, $value);
				}
			}
		}
	}

	/**
	 * @param $targetPostType string e.g. post, event - the type of post you want to connect to
	 * @param bool $single - just return the first/only post?
	 * @param array $queryArgs - augment or overwrite the default parameters for the WP_Query
	 * @param bool $hierarchical - if this is true the the function will return any post that is connected to this post *or any of its descendants*
	 * @return null|ooPost|ooWP_Query
	 */
	public function connected($targetPostType, $single = false, $queryArgs = array(), $hierarchical = false)
	{
		$toReturn = null;
		if (function_exists('p2p_register_connection_type')) {
			if(!is_array($targetPostType)) {
				$targetPostType = array($targetPostType);
			}
			$connection_name = array();
			foreach ($targetPostType as $targetType) {
				$connection_name[] = self::getConnectionName($targetType);
			}

			$defaults = array(
				'connected_type'  => $connection_name,
				'post_type'	   => $targetPostType,
			);

			#todo optimisation: check to see if this post type is hierarchical first
			if ($hierarchical) {
				$defaults['connected_items'] = array_merge($this->getDescendantIds(), array($this->ID));
			} else {
				$defaults['connected_items'] = $this->ID;
			}

			// use the menu order if $hierarchical is true, or any of the target post types are hierarchical
			$useMenuOrder = $hierarchical;
			if (!$useMenuOrder) {
				foreach ($targetPostType as $postType) {
					if (self::isHierarchical($postType)) {
						$useMenuOrder = true;
						break;
					}
				}
			}
			if ($useMenuOrder) {
				$defaults['orderby'] = 'menu_order';
				$defaults['order'] = 'asc';
			}

			$queryArgs   = array_merge($defaults, $queryArgs);
			$result = new ooWP_Query($queryArgs);

			if ($hierarchical) { //filter out any duplicate posts
				$post_ids = array();
				foreach($result->posts as $i => $post){
					if(in_array($post->ID, $post_ids))
						unset($result->posts[$i]);

					$post_ids[] = $post->ID;
				}
			}

			$toReturn = $single ? null : $result;
			if (!$single) {
				$toReturn = $result;
			} else if ($result && $result->posts) {
				$toReturn = $result->posts[0];
			}
		}

		return $toReturn;
	}

	static function walkTree($p, &$current_descendants = array())
	{
		$current_descendants = array_merge($p->children, $current_descendants);
		foreach ($p->children as $child) {
			self::walkTree($child, $current_descendants);
		}

		return $current_descendants;

	}

	function getDescendants()
	{
		$posts = self::fetchAll();
		$keyed = array();
		foreach ($posts as $post) {
			$keyed[$post->ID]		   = $post;
			$keyed[$post->ID]->children = array();
		}
		unset($posts);
		foreach ($keyed as $post) { /* This is all a bit complicated but it works */
			if ($post->post_parent)
				$keyed[$post->post_parent]->children[] = $post;
		}

		$p		   = $keyed[$this->ID];
		$descendants = static::walkTree($p);
		return $descendants;
	}

	function getDescendantIds()
	{
		$ids = array();
		foreach ($this->getDescendants() as $d) {
			$ids[] = $d->ID;
		}
		return $ids;
	}

	public function allMetadata() {
		return get_metadata('post', $this->ID);
	}

	/**
	 * Gets the metadata (custom fields) for the post
	 * @param $name
	 * @param bool $single
	 * @return array|string
	 */
	public function metadata($name, $single = true) {
		$meta = null;
		if (function_exists('get_field')) {
			$meta = get_field($name, $this->ID);
		} else {
			$meta = get_post_meta($this->ID, $name, $single);
		}
		if (!$single && !$meta) {
			$meta = array(); // ensure return type is an array
		}
		return $meta;
	}

	/**
	 *
	 * @param $name
	 * @param bool $single
	 * @return array|string
	 * @deprecated use ooPost::metadata() instead. Note change in default value for $single.
	 */
	public function getMetadata($name, $single = false) {
		return $this->metadata($name, $single);
	}

	/***************************************************************************************************************************************
	 *																																	   *
	 *																  TEMPLATE HELPERS													   *
	 *																																	   *
	 ***************************************************************************************************************************************/

	public function title()
	{
		return apply_filters('the_title', $this->post_title, $this->ID);
	}

	public function content()
	{
		return apply_filters('the_content', $this->post_content);
	}

	public function date($format = 'd M Y')
	{
		return date($format, $this->timestamp());
		//		return apply_filters('the_date', $this->post_date);
	}

	public function modifiedDate($format = 'd M Y') {
		return date($format, strtotime($this->post_modified));
	}

	/**
	 * @deprecated Use ooPost#parent()
	 */
	public function getParent() {
		return $this->parent();
	}

	/**
	 * @return ooPost|null Get parent of post (or post type)
	 */
	public function parent() {
		$parentId = !empty($this->post_parent) ? $this->post_parent : static::postTypeParentId();
		return $this->getCacheValue() ?: $this->setCacheValue(
			!empty($parentId) ? ooPost::fetchById($parentId) : null
		);
	}

	/**
	 * @static
	 * @return int returns the root parent type for posts.
	 * If parent of a hierchical post type is a page, for example, this needs to be set to that ID
	 */
	public static function postTypeParentId(){
		return 0;
	}

	/**
	 * Traverses up the getParent() hierarchy until finding one with no parent, which is returned
	 */
	public function getRoot() {
		$parent = $this->parent();
		if ($parent) {
			return $parent->getRoot();
		}
		return $this;
	}

	public function timestamp()
	{
		return strtotime($this->post_date);
	}

	function excerpt($chars = 400, $stuff = null) {
		(!empty($stuff) ?: $stuff = $this->content());
		$content = str_replace("<!--more-->", '<span id="more-1"></span>', $stuff);
		//try to split on more link
		$parts = preg_split('|<span id="more-\d+"></span>|i', $content);
		$content = $parts[0];
		$content = strip_tags($content);
		$excerpt = '';
		$excerpt = '';
		$sentences = array_filter(explode(" ", $content));
		if($sentences){
			foreach($sentences as $sentence){
				if((strlen($excerpt) + strlen($sentence)) < $chars && $sentence){
					$excerpt .= $sentence." ";
				}else{
					break;
				}
			}
		}

		if(!$excerpt){
			$words = array_filter(explode(" ", $content));
			if($words){
				foreach($words as $word){
					if((strlen($excerpt) + strlen($word)) < $chars && $word){
						$excerpt .= $word." ";
					}else{
						break;
					}
				}
			}
		}

		$excerpt = trim(str_replace('&nbsp;', ' ',$excerpt));
		if(preg_match('%\w|,|:%i', substr($excerpt, -1)))
			$excerpt = $excerpt."...";

		return ($excerpt);
	}

	public function permalink() {
		if ($this->isHomepage()) {
			return rtrim(get_bloginfo('url'), '/') . '/';
		}
		return get_permalink($this->ID);
	}

	/**
	 * Fetches all posts (of any post_type) whose post_parent is this post, as well as
	 * the root posts of any post_types whose declared postTypeParentId is this post
	 * add 'post_type' to query args to only return certain post types for children
	 * add 'post__not_in to query args to exclude certain pages based on id.
	 * @param array $queryArgs
	 * @return ooPost[]
	 */
	public function children($queryArgs = array())
	{
		$posts = array();
		$postTypes = (array_key_exists ('post_type', $queryArgs) ? $queryArgs['post_type'] : 'any');
		unset($queryArgs['post_type']);
		if (!is_array($postTypes)) $postTypes = array($postTypes);
		foreach($this->childPostClassNames() as $className){
			foreach($postTypes as $postType){
				if($postType == 'any' || ($postType != 'none' && $this->theme()->postClass($postType) == $className)) {
					$posts = array_merge($posts, $className::fetchRoots($queryArgs)->posts);
				}
			}
		}
		$defaults = array('post_parent' => $this->ID);
		$queryArgs = wp_parse_args($queryArgs, $defaults);
		$children = static::fetchAll($queryArgs);
		$children->posts = array_merge($children->posts, $posts);
		return $children;
	}

	/**
	 * @return array Class names of ooPost types having this object as their parent
	 */
	public function childPostClassNames()
	{
		global $_registeredPostClasses;
		$names = array();
		foreach ($_registeredPostClasses as $class) {
			if ($class::postTypeParentId() == $this->ID) $names[] = $class;
		}
		return $names;
	}

	/**
	 * @return array Post types of ooPost types that are connected to this post type
	 */
	public static function connectedPostTypes()
	{
		global $_registeredConnections;
		return isset($_registeredConnections[self::postType()]) ? $_registeredConnections[self::postType()] : array();
	}

	/**
	 * @return array ClassNames of ooPost types that are connected to this post type
	 */
	public static function connectedClassNames()
	{
		$names = array();
		foreach(self::connectedPostTypes() as $post_type){
			$names[] = ooGetClassName($post_type);
		}
		return $names;
	}

	/**
	 * Executes a wordpress function, setting $this as the global $post first, then resets the global post data.
	 * Expects the first argument to be the function, followed by any arguments
	 * @return mixed
	 */
	protected function callGlobalPost()
	{
		$args	 = func_get_args();
		$callback = array_shift($args);
		global $post;
		$post = $this;
		setup_postdata($this);
		$returnVal = call_user_func_array($callback, $args);
		wp_reset_postdata();
		return $returnVal;
	}
	/**
	 * @return mixed
	 * @deprecated use wp_author() instead
	 */
	public function author()
	{
		return $this->callGlobalPost('get_the_author');
	}

	public function wp_author()
	{
		return $this->callGlobalPost('get_the_author');
	}

	/**
	 * @return string the Robots meta tag, should be NOINDEX, NOFOLLOW for some post types
	 */
	public function robots(){
		return "";
	}

	/**
	 * Gets the url for editing this post
	 * @return string
	 */
	public function editUrl() {
		return get_edit_post_link($this->ID);
	}

	/**
	 * Use this with attachment posts to convert the front page of a PDF to a PNG, and create a corresponding attachment.
	 * Typical usage (requires project to define xxAttachment class):
	 * add_action('add_attachment', function($id) {
	 *     //use constructor rather than factory to get around auto-draft post_status issue
	 *     $attachment = new xxAttachment($id);
	 *     $attachment->generatePdfImage();
	 * });
	 * @param string $extension
	 * @param string $namePrefix
	 * @param bool $logDebug
	 */
	protected function generatePdfImage($extension = 'png', $namePrefix = 'pdf-image-', $logDebug = false) {
		$debug = @fopen(get_stylesheet_directory() . '/debug.txt', 'a');
		$log = function($message, $force = false) use ($debug, $logDebug) {
			if ($logDebug || $force) {
				@fwrite($debug, '[' . date('Y-m-d H:i:s') . "]: $message\n");
			}
		};
		$log('checking for suitability (' . $this->ID . ')');
		// IMAGEMAGICK_CONVERT should be defined in wp-config.php
		if (defined('IMAGEMAGICK_CONVERT') && $this->post_mime_type == 'application/pdf') {
			$log('attempting conversion');

			$sourceFile = get_attached_file($this->ID);
			$targetFile = str_replace('.pdf', '.' . $extension, $sourceFile);

			// Converted image will have a fixed size (-extent), centred (-gravity), with the aspect ratio respected (-thumbnail), and
			// excess space filled with transparent colour (-background)
			$size = '260x310';
			$args = array(
				'-density 96',
				'-quality 85',
				'-thumbnail ' . $size,
//				'-extent ' . $size,
				'-gravity center',
				'-background transparent',
				escapeshellarg($sourceFile . '[0]'),
				escapeshellarg($targetFile)
			);
			$cmd = IMAGEMAGICK_CONVERT . ' ' . implode(' ', $args) . ' 2>&1';
			$out = exec($cmd, $output, $returnVar);

			$log($cmd);
			// if the convert fails, log the output
			if ($returnVar != 0) {
				$log('conversion failed', true);
				$log(json_encode(array('out'=>$out, 'output'=>$output, 'returnVar'=>$returnVar)), true);
			} else {
				//create wordpress attachment for thumbnail image
				$attachmentSlug = $namePrefix . $this->ID;
				$log('creating attachment: ' . $attachmentSlug);
				$targetAttachment = self::fetchBySlug($attachmentSlug);
				if ($targetAttachment) {
					$log('deleting existing attachment: ' . $targetAttachment->ID);
					wp_delete_attachment($targetAttachment->ID);
				}
				$id = wp_insert_attachment(array(
					'post_title' => '[Thumb] ' . $this->title(),
					'post_name' => $attachmentSlug,
					'post_content' => '',
					'post_mime_type' => 'image/' . $extension
				), $targetFile);
				$log('created new attachment: ' . print_r($id, true));
			}
		} else {
			if (!defined('IMAGEMAGICK_CONVERT')) {
				$log('ignoring: IMAGEMAGICK_CONVERT not defined');
			} else {
				$log('ignoring: post type is ' . $this->post_mime_type);
			}
		}
		@fclose($debug);
	}

#endregion

#region HTML Template helpers

	public function htmlLink($attrs = array())
	{
		$attrString = self::getAttributeString($attrs);
		return "<a href='" . $this->permalink() . "' $attrString>" . $this->title() . "</a>";
	}

	protected static function htmlList($items)
	{
		$links = array();
		foreach ($items as $term) {
			$links[] = $term->htmlLink();
		}
		return implode(', ', $links);
	}

	/**
	 * @static
	 * Prints each of the post_type roots using the 'menuitem' partial
	 * @param array $queryArgs
	 * @param array $menuArgs
	 */
	public static function printMenuItems($queryArgs = array(), $menuArgs = array()){
		if(!isset($queryArgs['post_parent'])){
			$posts = static::fetchRoots($queryArgs);
		}else{
			$posts = static::fetchAll($queryArgs);
		}

		$menuArgs['max_depth'] = isset($menuArgs['max_depth']) ? $menuArgs['max_depth'] : 0;
		$menuArgs['current_depth'] = isset($menuArgs['current_depth']) ? $menuArgs['current_depth'] : 1;
		foreach($posts as $post){
			$post->printMenuItem($menuArgs);
		}
	}

	// functions for printing with each of the provided partial files
	public function printSidebar() { $this->printPartial('sidebar'); }
	public function printMain() { $this->printPartial('main'); }
	public function printItem() { $this->printPartial('item'); }
	public function printMenuItem($menuArgs = array()) {
		$menuArgs['max_depth'] = isset($menuArgs['max_depth'])? $menuArgs['max_depth'] : 0;
		$menuArgs['current_depth'] = isset($menuArgs['current_depth'])? $menuArgs['current_depth'] : 1;
		$this->printPartial('menuitem', $menuArgs);
	}

	/**
	 * @static turns and array of key=>value attibutes into html string
	 * @param $attrs  key=>value attibutes
	 * @return string html for including in an element
	 */
	public static function getAttributeString($attrs){
		$attributeString = '';
		foreach($attrs as $key => $value){
			$attributeString .= " $key='$value' ";
		}
		return $attributeString;
	}

	/**
	 * Prints the partial into an html string, which is returned
	 * @param $partialType
	 * @return string
	 */
	public final function getPartial($partialType)
	{
		ob_start();
		$this->printPartial($partialType);
		$html = ob_get_contents();
		ob_end_flush();
		return $html;
	}

	public static function theme() {
		return ooTheme::getInstance();
	}

	/**
	 * looks for $partialType-$post_type.php, then $partialType.php in the partials directory of
	 * the theme, then the plugin
	 * @param $partialType  - e.g. main,  item, promo, etc
	 * @param array $args To be used by the partial file
	 */
	public function printPartial($partialType, $args = array())
	{
		// look in the theme directory, then plugin directory
		$places = array(get_stylesheet_directory() . '/partials', dirname(__FILE__) . "/../partials");
		$paths  = array();
		extract($args);
		$specific = array();
		$nonspecific = array();
		foreach ($places as $path) {
			$this->findPartialMatches($path, $partialType, $paths, $specific, $nonspecific);
		}

		// pick the first specific, or the first non-specific to display
		$match = ($specific ? $specific[0] : ($nonspecific ? $nonspecific[0] : null));
		if ($match) {
			$post = $this;
			$_theme = self::theme();
			if (WP_DEBUG) print "\n\n<!--start $match start-->\n";
			include($match);
			if (WP_DEBUG) print "\n<!--end $match end-->\n\n";
		} else {
			// show an error message
			?>
		<div class="oowp-error">
			<span class="oowp-post-type"><?php echo $this->postType(); ?></span>: <span class="oowp-post-id"><?php echo $this->ID; ?></span>
			<div class="oowp-error-message">Partial '<?php echo $partialType; ?>' not found</div>
			<!-- <?php print_r($paths); ?> -->
		</div>
		<?php
//  		throw new Exception(sprintf("Partial $partialType not found", $paths, get_class($this)));
		}
	}

	/**
	 * Recurses through the path structure, looking for files that match the partial type
	 * @param $path
	 * @param $partialType
	 * @param $paths array Populated with all discovered files
	 * @param $specific array Populated with all post_type/partial exact matches
	 * @param $nonspecific array Populated with all partial matches (without a post_type)
	 */
	protected function findPartialMatches($path, $partialType, &$paths, &$specific, &$nonspecific) {
		if (file_exists($path)) {
			if (is_dir($path)) {
				$entries = array();
				$fh = opendir($path);
				while (false !== ($entry = readdir($fh))) {
					if (!in_array($entry, array('.', '..'))) {
						$entries[] = $entry;
					}
				}
				closedir($fh);

				sort($entries);
				foreach ($entries as $entry) {
					$this->findPartialMatches($path . DIRECTORY_SEPARATOR . $entry, $partialType, $paths, $specific, $nonspecific);
				}
			} else {
				$paths[] = $path;
				$filename = basename($path);
				if (strpos($filename, $partialType) === 0) {
					// if there is a file that contains the post name too, make that a priority for selection
					$postTypeStart = strpos($filename, '-');
					$postTypeEnd = strpos($filename, '.php');
					if ($postTypeStart > 0) {
						// split everything after the '-' by valid separators: ',', '|', '+' or ' '
						$postTypes = preg_split('/[\s,|\+]+/', substr($filename, $postTypeStart+1, $postTypeEnd - $postTypeStart - 1));
						if (in_array($this->postType(), $postTypes)) {
							$specific[] = $path;
						}
					} else {
						$nonspecific[] = $path;
					}
				}
			}
		}
	}

	/***
	 * Alias for printPartial() - only used for turning this post into html
	 *
	 * @param $partialType - string
	 */
	public function printAsHtml($partialType)
	{
		$this->printPartial($partialType);
	}

	public function toHtml($partialType)
	{
		$this->getPartial($partialType);
	}

	function htmlAuthorLink()
	{
		return $this->callGlobalPost('get_the_author_link');
	}

	/**
	 * @return bool true if this is an ancestor of the page currently being viewed
	 */
	public function isCurrentPage() {
		$x = ooPost::getQueriedObject();
		if (isset($x) && $x->ID == $this->ID) return true;

		return false;
	}
	/**
	 * @return bool true if this is an ancestor of the page currently being viewed
	 */
	public function isCurrentPageParent() {
		$x = ooPost::getQueriedObject();
		if (isset($x) && ($x->post_parent == $this->ID || $x->postTypeParentId() == $this->ID)) return true;

		return false;
	}
	/**
	 * @return bool true if this is an ancestor of the page currently being viewed
	 */
	public function isCurrentPageAncestor() {
		$x = ooPost::getQueriedObject();
		while (isset($x) && $x) {
			if ($x->ID == $this->ID) return true;
			$x = $x->parent();
		}
		return false;
	}


    protected function featuredImageAttachmentId() {
        $image = $this->metadata('featured_image', true) ?: $this->metadata('featured_image', true);
        
        return $image ? $image['id'] : false;
    }

	public function featuredImageUrl($image_size = 'thumbnail'){
		$image = wp_get_attachment_image_src($this->featuredImageAttachmentId(), $image_size);
		return $image[0];
	}

	public function featuredImage($size = 'thumbnail', $attrs = array()){
		return wp_get_attachment_image($this->featuredImageAttachmentId(), $size, 0, $attrs);
	}

	/**
	 * Gets the list of elements that comprise a breadcrumb trail
	 */
	function breadcrumbs(){
		$ancestors = array($this->title());
		$current = $this;
		while($parent = $current->parent()){
			$ancestors[] = $parent->htmlLink();
			$current = $parent;
		}
		$home = self::fetchHomepage();
		if ($home && $this->ID != $home->ID) {
			$ancestors[] = $home->htmlLink();
		}
		return array_reverse($ancestors);
	}

	/**
	 * Prints the breadcrumb trail using the given delimiter
	 * @param string $delimiter The text to separate the breadcrumb elements. Defaults to ' &raquo; ' ( » )
	 * @return string
	 */
	function printBreadcrumbs($delimiter = ' &raquo; ') {
		echo implode($delimiter, $this->breadcrumbs());
	}

	/**
	 * @deprecated
	 * @return ooWP_Query
	 */
	public function attachments(){
		$queryArgs = array( 'post_type' => 'attachment', 'numberposts' => -1, 'post_status' => 'inherit', 'post_parent' => $this->ID );
		return new ooWP_Query($queryArgs);
	}

	protected function listToString($posts, $without_links = false){
		$links = array();
		foreach ( $posts as $item ) {
			$links[] = $without_links ? $item->title() : "<a href='". $item->permalink() ."'>".$item->title()."</a>";
		}

		if(count($links) > 1){
			$a1 = array_pop($links);
			$a2 = array_pop($links);
			$links[] = "$a1 & $a2";
			return implode(', ', $links);
		}elseif($links){
			return $links[0];
		}
	}

#endregion

#region Static functions

	/**
	 * @static
	 * Called by register(), for registering this post type
	 * @param $defaults
	 * @return mixed array of arguments used by register_post
	 */
	static function getRegistrationArgs($defaults) {
		return $defaults;
	}

	/**
	 * @static Registers the post type (if not a built-in type), by calling getRegistrationArgs,
	 * then enables customisation of the admin screens for the post type
	 * @return null|object|WP_Error
	 */
	public static final function register() {
		$postType = static::postType();
		if ($postType == 'page' || $postType == 'post' || $postType == 'attachment' ) {
			$var = null;
		} else {
			$defaults = array(
				'labels'	  => oowp_generate_labels(static::friendlyName(), static::friendlyNamePlural()),
				'public'	  => true,
				'has_archive' => true,
				'rewrite'	 => array('slug'	  => $postType,
					'with_front'=> false),
				'show_ui'	 => true,
				'supports'	=> array(
					'title',
					'editor',
					'revisions',
				)
			);
			$registrationArgs	 = static::getRegistrationArgs($defaults);
			$var	  = register_post_type($postType, $registrationArgs);
		}
		$class = get_called_class();
		add_filter("manage_edit-{$postType}_columns", array($class, 'addCustomAdminColumns_internal'));
		add_action("manage_{$postType}_posts_custom_column", array($class, 'printCustomAdminColumn_internal'), 10, 2);
		add_action('right_now_content_table_end', array($class, 'addRightNowCount'));
		global $_registeredPostClasses;
		global $_registeredConnections;
		$_registeredPostClasses[$postType] = $class;
		$_registeredConnections[$postType] = array();
		return $var;
	}

	/**
	 * @static
	 * append the count(s) to the end of the 'right now' box on the dashboard
	 */
	static function addRightNowCount() {
		$postType = static::postType();
		if ($postType != 'post' && $postType != 'page') {
			$singular = static::friendlyName();
			$plural = static::friendlyNamePlural();

			$numPosts = wp_count_posts($postType);

			oowp_print_right_now_count($numPosts->publish, $postType, $singular, $plural);
			if ($numPosts->pending > 0) {
				oowp_print_right_now_count($numPosts->pending, $postType, $singular . ' Pending', $plural . ' Pending', 'pending');
			}
		}
	}

	/**
	 * This wraps the given array in a helper object, and calls addCustomAdminColumns with it
	 * @static
	 * @param $defaults
	 * @return array
	 */
	static final function addCustomAdminColumns_internal($defaults) {
		if (isset($_GET['post_status']) && $_GET['post_status'] == 'trash') {
			return $defaults;
		} else {
			$helper = new ArrayHelper($defaults);
			static::addCustomAdminColumns($helper);
			return $helper->array;
		}
	}

	/**
	 * @static
	 * This simply calls the non-internal version, after creating an object from the id
	 * @param $column
	 * @param $post_id
	 */
	static final function printCustomAdminColumn_internal($column, $post_id) {
		$key = 'adminColumnPost';
		// try to get the post from the cache, to minimise re-fetching
		if (!isset(ooPost::$_staticCache[$key]) || ooPost::$_staticCache[$key]->ID != $post_id) {
			$status = empty($_GET['post_status']) ? 'publish' : $_GET['post_status'];
			$query = new ooWP_Query(array('p'=>$post_id, 'posts_per_page'=>1, 'post_status'=>$status));
			ooPost::$_staticCache[$key] = ($query->post_count ? $query->post : null);
		}
		if (ooPost::$_staticCache[$key]) {
			static::printCustomAdminColumn($column, ooPost::$_staticCache[$key]);
		}
	}

	/**
	 * @static
	 * Use this in combination with printCustomAdminColumn to add custom columns to the wp admin interface for the post.
	 * Argument should end up with an array of [column name]=>[column header]
	 * Ordering is respected, so use the helper functions insertBefore and insertAfter
	 * @param $helper ArrayHelper Contains the default columns
	 */
	static function addCustomAdminColumns(ArrayHelper $helper) { /* do nothing */ }

	/**
	 * @static
	 * Use this in combination with addCustomAdminColumns to render the column value for a post
	 * @param $column string The name of the column, as given in addCustomAdminColumns
	 * @param $post ooPost The post (subclass) object
	 */
	static function printCustomAdminColumn($column, $post) { /* do nothing */ }

	/**
	 * @static
	 * Gets the queried object (i.e. the post/page currently being viewed)
	 * @return null|ooPost
	 */
	static function getQueriedObject() {
		global $ooQueriedObject;
		if (!isset($ooQueriedObject)) {
			global $wp_the_query;
			$id = $wp_the_query->get_queried_object_id();
			$ooQueriedObject = $id ? ooPost::fetchById($id) : null;
		}
		return $ooQueriedObject;
	}

	/**
	 * @static Creates a p2p connection to another post type
	 * @param $targetPostType - the post_type of the post type you want to connect to
	 * @param array $parameters - these can overwrite the defaults. though if you change the name of the connection you'll need a custom getConnected aswell
	 * @return mixed
	 */
	static function registerConnection($targetPostType, $parameters = array())
	{
		if (!function_exists('p2p_register_connection_type'))
			return;
		$postType = (string)self::postType();

		//register this connection globally so that we can find out about it later
		global $_registeredConnections;
		if(!$_registeredConnections[$postType]) $_registeredConnections[$postType] = array();
		if(!$_registeredConnections[$targetPostType]) $_registeredConnections[$targetPostType] = array();
		if(in_array($targetPostType, $_registeredConnections[$postType]))
			return; //this connection has already been registered
		$_registeredConnections[$targetPostType][] = $postType;
		$_registeredConnections[$postType][] = $targetPostType;

		$types = array($targetPostType, self::postType());
		sort($types);

		$connection_name = self::getConnectionName($targetPostType);
		$defaults		= array(
			'name'		=> $connection_name,
			'from'		=> $types[0],
			'to'		  => $types[1],
			'cardinality' => 'many-to-many',
			'reciprocal'  => true
		);

		$parameters = wp_parse_args($parameters, $defaults);
		p2p_register_connection_type($parameters);
	}

	/**
	 * @static factory class creates a post of the appropriate ooPost subclass, populated with the given data
	 * @param null $data
	 * @return ooPost - an ooPost object or subclass if it exists
	 * @deprecated Use ooPost::createPostObject()
	 */
	public static function fetch($data = null)
	{
		return self::createPostObject($data);
	}

	/**
	 * Factory method for creating a post of the appropriate ooPost subclass, for the given data
	 * @static
	 * @param object $data
	 * @return ooPost|null
	 */
	public static function createPostObject($data = null) {
		if ($data) {
			if ($data instanceof ooPost) {
				return $data;
			}
			$postData = self::getPostObject($data);
			if ($postData) {
				$className = ooGetClassName($postData->post_type);
				if ($className == get_class($postData)) {
					return $postData;
				} else {
					return new $className($postData);
				}
			}
		}
		return null;
	}

	/**
	 * Factory method for creating a post of the appropriate ooPost subclass, for the given post ID
	 * @static
	 * @param $ids int|int[]
	 * @return ooPost|ooWP_Query|null
	 */
	public static function fetchById($ids) {
		if (is_array($ids) && $ids){
			return new ooWP_Query(array('post__in' => $ids));
		}elseif($ids){
			return static::fetchOne(array('p' => $ids));
		}else{
			throw new Exception("no Ids supplied to ooPost::fetchById()");
		}
	}

	public static function fetchBySlug($slug){
		return static::fetchOne(array(
			'name' => $slug,
			'post_type' => static::postType(),
			'numberposts' => 1
		));
	}

	/**
	 * @static
	 * @param array $queryArgs - accepts a wp_query $queryArgs array which overwrites the defaults
	 * @return ooWP_Query
	 */
	public static function fetchAll($queryArgs = array())
	{
		$defaults = array(
			'post_type' => static::postType()
		);
		if (static::isHierarchical()) {
			$defaults['orderby'] = 'menu_order';
			$defaults['order'] = 'asc';
		}

		$queryArgs = wp_parse_args($queryArgs, $defaults);
		$query	= new ooWP_Query($queryArgs);

		return $query;
	}

	/**
	 * @deprecated
	 */
	static function fetchAllQuery($queryArgs = array())
	{
		return static::fetchAll($queryArgs);
	}

	/**
	 * @static
	 * @return null|ooPost
	 */
	static function fetchHomepage() {
		$key = 'homepage';
		if (!array_key_exists($key, ooPost::$_staticCache)) {
			$id = get_option('page_on_front');
			ooPost::$_staticCache[$key] = $id ? self::fetchById($id) : null;
		}
		return ooPost::$_staticCache[$key];
	}

	/**
	 * @return bool true if this is the site homepage
	 */
	public function isHomepage() {
		return $this->ID == get_option('page_on_front');
	}

	/**
	 * Return the first post matching the arguments
	 * @static
	 * @param $queryArgs
	 * @return null|ooPost
	 */
	static function fetchOne($queryArgs)
	{
		$queryArgs['posts_per_page'] = 1;
		$query = new ooWP_Query($queryArgs);
		return $query->posts ? $query->post : null;
	}

	/**
	 * @static Returns the roots of this post type (i.e those whose post_parent is self::postTypeParentId)
	 * @param array $queryArgs
	 * @return ooWP_Query
	 */
	static function fetchRoots($queryArgs = array())
	{
		#todo perhaps the post_parent should be set properly in the database
//		$queryArgs['post_parent'] = static::postTypeParentId();
		$queryArgs['post_parent'] = self::postTypeParentId();
		return static::fetchAll($queryArgs);
	}


	/**
	 * @static
	 * @param null $postType
	 * @return bool Whether or not the post type is declared as hierarchical
	 */
	static function isHierarchical($postType = null) {
		if (!$postType) {
			$postType = static::postType();
		}
		return is_post_type_hierarchical($postType);
	}

#endregion


}

/**
 * As ooPost is abstract, this class is only used for instantiating oowp objects without a corresponding class
 */
class ooMiscPost extends ooPost {

}

/**
 * As ooPost is abstract, this class can be used for entities that have no real existence, e.g. 404 pages
 */
class ooFakePost extends ooPost {
	public function __construct($args = array()) {
		//set defaults
		$postArray = wp_parse_args($args, array(
			'ID' => 0,
			'post_parent' => 0,
			'post_title' => '',
			'post_name' => '',
			'post_content' => '',
			'post_type' => 'fake',
			'post_status' => 'publish',
			'post_date' => date('Y-m-d')
		));

		//slugify title
		if ($postArray['post_title'] && !$postArray['post_name']) {
			$postArray['post_name'] = sanitize_title_with_dashes($postArray['post_title']);
		}

		parent::__construct($postArray);
	}

	public function permalink() {
		if (!empty($this->permalink)) {
			return $this->permalink;
		}
		return parent::permalink();
	}

	/**
	 * @return string the Robots meta tag, should be NOINDEX, NOFOLLOW for some post types
	 */
	public function robots(){
		return "NOINDEX, NOFOLLOW";
	}
}

class ArrayHelper {
	public $array = array();

	function __construct($array = array()) {
		$this->array = $array;
	}

	function insertBefore($beforeKey, $key, $value) {
		$this->array = array_insert_before($this->array, $beforeKey, $key, $value);
	}

	function insertAfter($afterKey, $key, $value) {
		$this->array = array_insert_after($this->array, $afterKey, $key, $value);
	}
}
?>
