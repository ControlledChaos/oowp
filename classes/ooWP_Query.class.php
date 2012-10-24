<?php
class ooWP_Query extends WP_Query implements IteratorAggregate, ArrayAccess, Countable
{

	/**
	 * @var ooPost[]
	 */
	var $posts;

	/* Interfaces */

	public function getIterator() {
		return new ArrayIterator($this->posts);
	}

	public function offsetExists($offset) {
		return isset($this->posts[$offset]);
	}

	public function offsetGet($offset) {
		return $this->posts[$offset];
	}

	public function offsetSet($offset, $value) {
		$this->posts[$offset] = $value;
	}

	public function offsetUnset($offset) {
		unset($this->posts[$offset]);
	}

	public function count() {
		return count($this->posts);
	}

	/**
	 * Stores $this as the global $wp_query, executes the passed-in WP function, then reverts $wp_query
	 * @return mixed
	 */
	protected function callGlobalQuery() {
		$args     = func_get_args();
		$function = array_shift($args);
		global $wp_query;
		$oldQuery = $wp_query;
		$wp_query = $this;
		$returnVal = call_user_func_array($function, $args);
		$wp_query = $oldQuery;
		return $returnVal;
	}

	/**
	 * Prints the prev/next links for this query
	 * @param string $sep
	 * @param string $preLabel
	 * @param string $nextLabel
	 */
	public function postsNavLink($sep = '', $preLabel = '', $nextLabel = '') {
		$this->callGlobalQuery('posts_nav_link', $sep, $preLabel, $nextLabel);
	}

	public function queryVars() {
		return new QueryVars($this->query_vars);
	}
}

class QueryVars {
	private $args;

	function __construct($data) {
		if ($data instanceof WP_Query) {
			$this->args = $data->query_vars;
		} else {
			$this->args = $data;
		}
	}

	public function hasArg($arg) {
		return isset($this->args[$arg]);
	}

	public function arg($arg) {
		return $this->args[$arg];
	}

	public function isForPostType($postType) {
		$postTypes = $this->args['post_type'];
		if (is_array($postTypes)) {
			// TODO: Is this correct?
			return in_array($postType, $postTypes);
		} else {
			return $postTypes == $postType;
		}
	}
}