<?php
/**
 * Table of Contents Plugin eXtension
 *
 * @author     sonots
 * @license    http://www.gnu.org/licenses/gpl.html GPL v2
 * @link       http://lsx.sourceforge.jp/?Plugin%2Fcontentsx.inc.php
 * @version    $Id: contentsx.inc.php,v 1.11 2007-08-03 07:23:17Z sonots $
 * @package    plugin
 */
 // v1.12 PHP8.0対応 2021-12-15 byはいふん

class PluginContentsx
{
	function __construct()
	{
		// Message
		static $msg = array();
		if (empty($msg)) $msg = array(
			'toctitle'  => _('Table of Contents'),
		);
		// Modify here for default option values
		static $default_options = array(
			'page'	  => array('string',  ''),
			'fromhere'  => array('bool',	true),
			'hierarchy' => array('bool',	true),
			'compact'   => array('bool',	true),
			'num'	   => array('number',  ''),
			'depth'	 => array('number',  ''),
			'except'	=> array('string',  ''),
			'filter'	=> array('string',  ''),
			'include'   => array('bool',	true),
			'cache'	 => array('enum',	'on',  array('on', 'off', 'reset')),
			'link'	  => array('enum',	'on',  array('on', 'off', 'anchor', 'page')),
		);
		// Definitions
		static $conf = array(
			'def_headline' => '/^(\*{1,3})/',
			'max_depth'	=> 3,
			'def_include'  => '/^#include.*\((.+)\)/',
			'def_title'	=> '/^TITLE:(.+)/',
			'use_session'  => TRUE, // action
			'through_if_admin' => TRUE, // action
		);
		$this->msg  = &$msg;
		$this->default_options = &$default_options;
		$this->conf = &$conf;

		// init
		$this->options = $this->default_options;
	}

	function PluginContentsx() {
		$this->__construct();
	}

	// static
	var $msg;
	var $default_options;
	var $conf;
	// var
	var $args;
	var $options;
	var $error = "";
	var $plugin = "contentsx";
	var $metalines;
	var $visited = array(); // page => title(alias)

	function action() // clean cache
	{
		global $vars;

		if (is_admin($vars['pass'], $this->conf['use_session'], $this->conf['through_if_admin']) && 
			$vars['pcmd'] == 'clean') {
			$body = $this->clean_cache();
		} else {
			$body = $this->display_password_form();
		}
		return array('msg'=>'Clean Contentsx Caches', 'body'=>$body);
	}
	
	function clean_cache()
	{
		set_time_limit(0);
		global $vars;

		$page = isset($vars['page']) ? $vars['page'] : '';
		if ($page != '') {
			$file = $this->get_cache_filename($page);
			@unlink($file);
			if (exec_page($page, '/^#contentsx/')) {
				$body = 'Recreated a cache of ';
			} else {
				$body = 'No #contentsx in ';
			}
			$body .= make_pagelink($page);
		} else {
			// remove all files
			$files = $this->get_cache_filenames();
			foreach ($files as $file) {
				unlink($file);
			}
			// execute all pages
			$exec_pages = exec_existpages('/^#contentsx/');
			if (empty($exec_pages)) {
				$body = '';
			} else {
				$links = array_map('make_pagelink', $exec_pages);
				$body = '<p>Following pages were executed to assure:</p>'
					. '<p>' . implode("<br />\n", $links) . '</p>';
			}
		}
		return $body;
	}

	/**
	 * Display a password form
	 *
	 * @param $msg error message or some messages
	 * @return string form html
	 */
	function display_password_form($message = "")
	{
		$cmd  = $this->plugin;
		$pcmd = 'clean';
		$form = array();
		$form[] = '<form action="' . get_script_uri() . '?cmd=' . $cmd . '" method="post">';
		$form[] = '<div>';
		$form[] = ' <input type="hidden" name="pcmd" value="' . $pcmd . '" />';
		$form[] = ' <input type="text" name="page" size="24" value="" /> ' . _('A Page (Blank if All)') . '<br />';
		if (! is_admin(null, $this->conf['use_session'], $this->conf['through_if_admin'])) {
			$form[] = ' <input type="password" name="pass" size="24" value="" /> ' . _('Admin Password') . '<br />';
		} else {
			$form[] = ' <input type="password" name="pass" size="24" value="" style="background-color:#ddd;" disabled="disabled" /> ' . _('Admin Password') . '<br />';
		}
		$form[] = ' <input type="submit" name="submit" value="Submit" /><br />';
		$form[] = '</div>';
		$form[] = '</form>';
		$form = implode("\n", $form);
		
		if ($message != '') {
			$message = '<p><b>' . htmlsc($message) . '</b></p>';
		}
		return $message . $form;
	}

	function convert()
	{
		$args = func_get_args();
		if ($GLOBALS['vars']['cmd'] != 'read') {
			return '';
		}
		$body = $this->body($args);
		if ($body != '') {
			$body = '<table border="0" class="toc"><tbody>' . "\n"
				. '<tr><td class="toctitle">' . "\n"
				. '<span>' . $this->msg['toctitle'] . "</span>\n"
				. "</td></tr>\n"
				. '<tr><td class="toclist">' . "\n"
				. $body 
				. "</td></tr>\n"
				. "</tbody></table>\n";
		}
		if ($this->error != "" ) { 
			return "<p>#$this->plugin(): $this->error</p>";
		}
		return $body;
	}
	
	function body($args)
	{
		global $vars;

		$parser = new PluginContentsxOptionParser();
		$this->options = $parser->parse_options($args, $this->options);
		if ($parser->error != "") { $this->error = $parser->error; return; }

		$this->options['page'][1] = $this->check_page($this->options['page'][1]);
		if ($this->error !== "") { return; }

		$this->init_metalines($this->options['page'][1]);
		if ($this->error !== "") { return; }
		
		$this->narrow_metalines();
		if ($this->error !== "") { return; }

		$body = $this->frontend();
		if ($this->error !== "") { return; }
		return $body;
	}

	function get_title($page)
	{
		$page = $this->check_page($page);
		$this->init_metalines($page);
		$title = $this->visited[$page];
		// FYI: $title = strip_htmltag(make_link($title));
		//	  $link  = make_pagelink($page, $title);
		return $title;
	}

	function get_visited($page)
	{
		$page = $this->check_page($page);
		$this->init_metalines($page);
		return array_keys($this->visited);
	}

	function get_metalines($page)
	{
		$page = $this->check_page($page);
		$this->init_metalines($page);
		return $this->metalines;
	}

	function narrow_metalines()
	{
		$this->fromhere_metalines();
		$this->include_metalines();
		$this->filter_metalines();
		$this->except_metalines();
		
		$parser = new PluginContentsxOptionParser();
		$this->options['depth'][1] = $parser->parse_numoption($this->options['depth'][1], 0, $this->conf['max_depth']);
		if ($parser->error != "") { $this->error = $parser->error; return; }
		$this->depth_filter_metalines();
		
		$num = sizeof($this->metalines);
		$this->options['num'][1] = $parser->parse_numoption($this->options['num'][1], 1, $num);
		if ($parser->error != "") { $this->error = $parser->error; return; }
		$this->num_filter_metalines();
	}

	function frontend()
	{
		$this->hierarchy_metalines();
		$this->compact_metalines();
		$this->makelink_metalines();
		
		return $this->list_metalines();
	}

	function list_metalines()
	{
		if (sizeof($this->metalines) == 0) {
			return;
		}
		
		/* HTML validate (without <ul><li style="list-type:none"><ul><li>, we have to do as 
		   <ul><li style="padding-left:16*2px;margin-left:16*2px"> as pukiwiki standard. I did not like it)
		   
		<ul>			  <ul><li>1
		<li>1</li>		</li><li>1
		<li>1			 <ul><li>2
		<ul>			  </li></ul></li><li>1
		<li>2</li>		</li><li>1
		</ul>		=>   <ul><li style="list-type:none"><ul><li>3
		</li>			 </li></ul></li></ul></li></ul>
		<li>1</li>
		<li>1</li>
		<ul><li style="list-type:none"><ul>
		<li>3</li>
		</ul></li></ul>
		</li>
		</ul>
		*/
		
		$html = "";
		$ul = $pdepth = 0;
		foreach ($this->metalines as $metaline) {
			$display  = $metaline['display'];
			$depth = $metaline['listdepth'];
			if ($depth > $pdepth) {
				$diff = $depth - $pdepth;
				$html .= str_repeat('<ul><li style="list-style:none">', $diff - 1);
				if ($depth == 1) { // or $first flag
					$html .= '<ul class="' . $this->plugin . '"><li>';
				} else {
					$html .= '<ul><li>';
				}
				$ul += $diff;
			} elseif ($depth == $pdepth) {
				$html .= '</li><li>';
			} elseif ($depth < $pdepth) {
				$diff = $pdepth - $depth;
				$html .= str_repeat('</li></ul>', $diff);
				$html .= '</li><li>';
				$ul -= $diff;
			}
			$html .= $display;
			$html .= "\n";
			$pdepth = $depth;
		}
		$html .= str_repeat('</li></ul>', $ul);
		return $html;
	}
	
	function makelink_metalines()
	{
		$metalines = array();
		foreach ($this->metalines as $metaline) {
			$anchor = $metaline['anchor'];
			$headline = $metaline['headline'];
			$headline = strip_htmltag(make_link($headline)); // convert inline plugin
			$metaline['display'] = $this->make_pagelink($this->options['page'][1], $headline, $anchor);
			$metalines[] = $metaline;
		}
		$this->metalines = $metalines;
	}
	
	function make_pagelink($page, $alias, $anchor)
	{
		global $vars;
		if ($this->options['link'][1] == 'off' || $anchor =='') {
			return htmlsc($alias);
		}
		if (($this->options['link'][1] == 'on' && $page == $vars['page'])
			|| $this->options['link'][1] == 'anchor') {
			$page = '';
		}
		global $show_passage;
		$tmp = $show_passage; $show_passage = 0;
		$link = make_pagelink($page, $alias, $anchor);
		$show_passage = $tmp;
		return $link;
	}

	function compact_metalines()
	{
		// Hmmmmm, complex
		if (!$this->options['compact'][1]) {
			return;
		}
		if (! $this->options['hierarchy'][1]) {
			return;
		}
		// 1) fill in list spaces for each page
		// 1 3 1 1 3 3 1 => 1 2 1 1 2 2 1 (2 was none, move 3 to 2)
		// 2 2 2 => 1 1 1
		$listdepthstack = array();
		foreach ($this->metalines as $metaline) {
			$page  = $metaline['page'];
			$listdepth = $metaline['listdepth'];
			if (empty($listdepthstack[$page])) {
				$listdepthstack[$page][] = $listdepth;
			}
			else if(! in_array($listdepth, $listdepthstack[$page])) {
				$listdepthstack[$page][] = $listdepth;
			}
		}
		foreach (array_keys($listdepthstack) as $page) {
			sort($listdepthstack[$page]);
		}
		// 1 2 4 == (0=>1, 1=>2, 2=>4) -> (1=>1, 1=>2, 3=>4) -exchange keys and values-> (1=>1, 2=>1, 4=>3)
		$listdepthfill = array();
		foreach ($listdepthstack as $page => $stack) {
			foreach($stack as $i => $listdepth) {
				$listdepthfill[$page][$listdepth] = $i + 1;
			}
		}
		$metalines = array();
		foreach ($this->metalines as $metaline) {
			$page  = $metaline['page'];
			$listdepth = $metaline['listdepth'];
			$metaline['listdepth'] = $listdepthfill[$page][$listdepth];
			$metalines[] = $metaline;
		} 
		$this->metalines = $metalines;

		// 2) fill in previous list space, seperately for each page
		// 1 3 2 => 1 2 2
		$pdepth = array(); $plistdepth = array();
		foreach (array_keys($listdepthstack) as $page) {
			$pdepth[$page] = -1;
			$plistdepth[$page] = 0;
		}
		$metalines = array();
		$this->hoge = array();
		foreach ($this->metalines as $metaline) {
			$page = $metaline['page']; 
			if ($metaline['depth'] > $pdepth[$page]) {
				$metaline['listdepth'] = $plistdepth[$page] + 1;
			} elseif($metaline['depth'] == $pdepth[$page]) {
				$metaline['listdepth'] = $plistdepth[$page];
			} else {
				$metaline['listdepth'] = ($plistdepth[$page] < $metaline['listdepth']) ? $plistdepth[$page]: $metaline['listdepth'];
			}
			$pdepth[$page] = $metaline['depth'];
			$plistdepth[$page] = $metaline['listdepth'];
			$metalines[] = $metaline;
		}
		$this->metalines = $metalines;
	}
	
	function hierarchy_metalines()
	{
		$include = 0;
		if ($this->options['include'][1] && sizeof($this->visited) >= 2) { // include (0,1,2,3...) -> (1,2,3,4...)
			$include = 1;
		}
		$metalines = array();
		foreach($this->metalines as $metaline) {
			if ($this->options['hierarchy'][1]) {
				$metaline['listdepth'] = $metaline['depth'] + $include;
			} else {
				$metaline['listdepth'] = 1;
			}
			$metalines[] = $metaline;
		}
		$this->metalines = $metalines;
	}
	
	function num_filter_metalines()
	{
		if ($this->options['num'][1] === '') {
			return;
		}
		$metalines = array();
		foreach ($this->options['num'][1] as $num) {
			$metalines[] = $this->metalines[$num - 1];
		}
		$this->metalines = $metalines;
	}

	function depth_filter_metalines()
	{
		if ($this->options['depth'][1] === '') {
			return;
		}
		$metalines = array();
		foreach ($this->metalines as $metaline) {
			$depth = $metaline['depth'];
			if (in_array($depth, $this->options['depth'][1])) {
				$metalines[] = $metaline;
			}
		}
		$this->metalines = $metalines;
	}
	
	function filter_metalines()
	{
		if ($this->options['filter'][1] === "") {
			return;
		}
		$metalines = array();
		foreach ($this->metalines as $metaline) {
			$headline = $metaline['headline'];
			if (ereg($this->options['filter'][1], $headline)) {
				$metalines[] = $metaline;
			}
		}
		$this->metalines = $metalines;
	}

	function except_metalines()
	{
		if ($this->options['except'][1] === "") {
			return;
		}
		$metalines = array();
		foreach ($this->metalines as $metaline) {
			$headline = $metaline['headline'];
			if (!ereg($this->options['except'][1], $headline)) {
				$metalines[] = $metaline;
			}
		}
		$this->metalines = $metalines;
	}
	
	function include_metalines()
	{
		if ($this->options['include'][1]) {
			return;
		}
		$metalines = array();
		foreach ($this->metalines as $metaline) {
			if ($metaline['page'] == $this->options['page'][1]) {
				$metalines[] = $metaline;
			}
		}
		$this->metalines = $metalines;
	}

	function fromhere_metalines()
	{
		if (! $this->options['fromhere'][1]) {
			return;
		}
		$metalines = array();
		foreach ($this->metalines as $metaline) {
			if ($metaline['fromhere']) {
				$metalines[] = $metaline;
			}
		}
		$this->metalines = $metalines;
	}

	function init_metalines($page)
	{
		$this->metalines = array();
		$this->visited = array();
		if ($this->read_cache($page) !== false) { return; }
		$this->metalines = $this->r_metalines($page);
		$this->write_cache($page);
	}

	function get_cache_filename($page)
	{
		return CACHE_DIR . encode($page) . ".$this->plugin";
	}

	function get_cache_filenames()
	{
		return get_existfiles(CACHE_DIR, ".$this->plugin");
	}

	/**
	 * Read cache
	 *
	 * @param $apage pagename
	 * @return mixed contents or FALSE if cache should be renewed
	 */
	function read_cache($apage)
	{
		if ($this->options['cache'][1] == 'off' || $this->options['cache'][1] == 'reset') {
			return false;
		}
		if (! is_page($apage)) {
			return false;
		}
		$cache = $this->get_cache_filename($apage);
		if (! $this->file_exists($cache)) {
			return false;
		}
		if (! $this->is_readable($cache)) {
			$this->error = "Cache file, $cache is not readable. ";
			return;
		}

		$lines = file($cache);

		$pages = csv_explode(',', rtrim(array_shift($lines)));
		foreach ($pages as $page) {
			list($page, $title) = csv_explode('=', $page);
			$visited[$page] = $title;
		}

		// renew cache if preview mode
		if (isset($vars['preview']) || isset($vars['realview'])) {
			return false;
		}
		// renew cache if page is newer than cache
		foreach ($visited as $page => $title) {
			if (is_page_newer($page, $cache)) {
				return false;
			}
		}

		$this->visited = $visited;
		$metalines = array();
		foreach ($lines as $line) {
			$metas = csv_explode(',', rtrim($line));
			$metaline = array();
			foreach ($metas as $meta) {
				list($key, $val) = explode('=', $meta, 2);
				$metaline[$key] = $val;
			}
			$metalines[] = $metaline;
		}
		$this->metalines = $metalines;
	}

	function write_cache($apage)
	{
		if ($this->options['cache'][1] == 'off') {
			return;
		}
		if (! is_page($apage)) {
			return;
		}
		$cache = $this->get_cache_filename($apage);
		if ($this->file_exists($cache) && ! $this->is_writable($cache)) {
			$this->error = "Cache file, $cache is not writable. ";
			return;
		}
		
		$pages = array();
		foreach ($this->visited as $page => $title) {
			$pages[] = csv_implode('=', array($page, $title));
		}
		$contents = '';
		$contents .= csv_implode(',', $pages) . "\n";
		foreach ($this->metalines as $metaline) {
			$metas = array();
			foreach ($metaline as $key => $val) {
				$metas[] = "$key=$val";
			}
			$contents .= csv_implode(',', $metas) . "\n";
		}
		// file_put_contents($cache, $contents); // PHP5
		if (! $fp = fopen($cache, "w")) {
			$this->error = "Can not open $cache. ";
			return;
		}
		if (! fwrite($fp, $contents)) {
			$this->error = "Can not write to $cache. ";
			return;
		}
		fclose($fp);
	}

	function r_metalines($page, $detected = false)
	{
		if (array_key_exists($page, $this->visited)) {
			return array();
		}
		if (! is_page($page)) {
			return array();
		}
		$this->visited[$page] = '';
		$lines = $this->get_source($page);
		$multiline = 0;
		$metalines = array();
		foreach ($lines as $i => $line) {
			// multiline plugin. refer lib/convert_html
			if(defined('PKWKEXP_DISABLE_MULTILINE_PLUGIN_HACK') && PKWKEXP_DISABLE_MULTILINE_PLUGIN_HACK === 0) {
				$matches = array();
				if ($multiline < 2) {
					if(preg_match('/^#([^\(\{]+)(?:\(([^\r]*)\))?(\{*)/', $line, $matches)) {
						$multiline  = strlen($matches[3]);
					}
				} else {
					if (preg_match('/^\}{' . $multiline . '}$/', $line, $matches)) {
						$multiline = 0;
					}
					continue;
				}
			}

			// fromhere
			if ($this->options['page'][1] == $page && !$detected) {
				if (preg_match('/^#' . $this->plugin . '/', $line, $matches)) {
					$detected = true;
					continue;
				}
			}
			
			if (preg_match($this->conf['def_headline'], $line, $matches)) {
				$depth	= strlen($matches[1]);
				$anchor   = '#' . $this->make_heading($line); // *** [id] is removed from $line
				$headline = trim($line);
				$metalines[] = array('page'=>$page, 'headline'=>$headline, 'anchor'=>$anchor, 'depth'=>$depth, 'linenum'=>$i, 'fromhere'=>$detected);
				continue;
			}

			if (preg_match($this->conf['def_include'], $line, $matches)) {
				$args	= csv_explode(',', $matches[1]);
				$inclpage = array_shift($args);
				$options = array();
				foreach ($args as $arg) {
					list($key, $val) = array_pad(explode('=', $arg, 2), 2, true);
					$options[$key] = $val;
				}
				$inclpage = get_fullname($inclpage, $page);
				if (! $this->is_page($inclpage)) {
					continue;
				}
				// $anchor = PluginIncludex::get_page_anchor($inclpage)
				$anchor  = 'z' . md5($inclpage);
				$anchor  = '#' . htmlsc($anchor);
				if (exist_plugin('includex') & is_callable(array('PluginIncludex', 'get_titlestr'))) {
					$titlestr = PluginIncludex::get_titlestr($inclpage, $options['titlestr']);
				} else {
					$titlestr = $inclpage;
				}
				$metalines[] = array('page'=>$inclpage, 'headline'=>$titlestr, 'anchor'=>$anchor, 'depth'=>0, 'linenum'=>$i, 'fromhere'=>$detected);
				$metalines = array_merge($metalines, $this->r_metalines($inclpage, $detected));
				continue;
			}

			if (preg_match($this->conf['def_title'], $line, $matches)) {
				$title = $matches[1];
				$this->visited[$page] = $title;
				continue;
			}
		}
		return $metalines;
	}

	// copy from lib/html.php
	function make_heading(& $str, $strip = TRUE)
	{
		global $NotePattern;

		// Cut fixed-heading anchors
		$id = '';
		$matches = array();
		if (preg_match('/^(\*{0,3})(.*?)\[#([A-Za-z][\w-]+)\](.*?)$/m', $str, $matches)) {
			$str = $matches[2] . $matches[4];
			$id  = & $matches[3];
		} else {
			$str = preg_replace('/^\*{0,3}/', '', $str);
		}
		
		// Cut footnotes and tags
		if ($strip === TRUE) {
			// $str = strip_htmltag(make_link(preg_replace($NotePattern, '', $str))); // sonots
			$str = preg_replace($NotePattern, '', $str); // sonots
		}
		
		return $id;
	}

	function check_page($page)
	{
		global $vars, $defaultpage;
		if ($page == "") {
			$page = isset($vars['page']) ? $vars['page'] : $defaultpage;
		} else {
			$page = get_fullname($page, $vars['page']);
			$this->options['fromhere'][1] = false;
		}
		if (! $this->is_page($page)) {
			$this->error = "No such a page, " . $page;
			return;
		}
		if (! $this->check_readable($page, FALSE, FALSE)) {
			$this->error = "Page, " . $page . ", is not readable.";
		}
		return $page;
	}
	
	// PukiWiki API
	function get_source($page)
	{
		return get_source($page);
	}
	
	function is_page($page)
	{
		return is_page($page);
	}

	function check_readable($page, $flag, $flag2)
	{
		return check_readable($page, $flag, $flag2);
	}

	// PHP API
	function file_exists($file)
	{
		return file_exists($file);
	}

	function is_readable($file)
	{
		return is_readable($file);
	}

	function is_writable($file)
	{
		return is_writable($file);
	}
}

///////////////////////////////////////
class PluginContentsxOptionParser
{
	var $error = "";

	function parse_options($args, $options)
	{
		if (! is_associative_array($args)) {
			$args = $this->associative_args($args, $options);
			if ($this->error != "") { return; }
		}

		foreach ($args as $key => $val) {
			if ( !isset($options[$key]) ) { continue; } // for action ($vars)
			$type = $options[$key][0];

			switch ($type) {
			case 'bool':
				if($val == "" || $val == "on" || $val == "true") {
					$options[$key][1] = true;
				} elseif ($val == "off" || $val == "false" ) {
					$options[$key][1] = false;
				} else {
					$this->error = htmlsc("$key=$val") . " is invalid. ";
					$this->error .= "The option, $key, accepts only a boolean value.";
					$this->error .= "#$this->plugin($key) or #$this->plugin($key=on) or #$this->plugin($key=true) for true. ";
					$this->error .= "#$this->plugin($key=off) or #$this->plugin($key=false) for false. ";
					return;
				}
				break;
			case 'string':
				$options[$key][1] = $val;
				break;
			case 'sanitize':
				$options[$key][1] = htmlsc($val);
				break;
			case 'number':
				// Do not parse yet, parse after getting min and max. Here, just format checking
				if ($val === '') {
					$options[$key][1] = '';
					break;
				}
				if ($val[0] === '(' && $val[strlen($val) - 1] == ')') {
					$val = substr($val, 1, strlen($val) - 2);
				}
				foreach (explode(",", $val) as $range) {
					if (preg_match('/^-?\d+$/', $range)) {
					} elseif (preg_match('/^-?\d*\:-?\d*$/', $range)) {
					} elseif (preg_match('/^-?\d+\+-?\d+$/', $range)) {
					} else {
						$this->error = htmlsc("$key=$val") . " is invalid. ";
						$this->error .= "The option, " . $key . ", accepts number values such as 1, 1:3, 1+3, 1,2,4. ";
						$this->error .= "Specify options as \"$key=1,2,4\" or $key=(1,2,3) when you want to use \",\". ";
						$this->error .= "In more details, a style like (1:3,5:7,9:) is also possible. 9: means from 9 to the last. ";
						$this->error .= "Furtermore, - means backward. -1:-3 means 1,2,3 from the tail. ";
						return;
					}
				}
				$options[$key][1] = $val;
				break;
			case 'enum':
				if($val == "") {
					$options[$key][1] = $options[$key][2][0];
				} elseif (in_array($val, $options[$key][2])) {
					$options[$key][1] = $val;
				} else {
					$this->error = htmlsc("$key=$val") . " is invalid. ";
					$this->error .= "The option, " . $key . ", accepts values from one of (" . join(",", $options[$key][2]) . "). ";
					$this->error .= "By the way, #$this->plugin($key) equals to #$this->plugin($key=" . $options[$key][2][0] . "). ";
					return;
				}
				break;
			case 'array':
				if ($val == '') {
					$options[$key][1] = array();
					break;
				}
				if ($val[0] === '(' && $val[strlen($val) - 1] == ')') {
					$val = substr($val, 1, strlen($val) - 2);
				}
				$val = explode(',', $val);
				//$val = $this->support_paren($val);
				$options[$key][1] = $val;
				break;
			case 'enumarray':
				if ($val == '') {
					$options[$key][1] = $options[$key][2];
					break;
				}
				if ($val[0] === '(' && $val[strlen($val) - 1] == ')') {
					$val = substr($val, 1, strlen($val) - 2);
				}
				$val = explode(',', $val);
				//$val = $this->support_paren($val);
				$options[$key][1] = $val;
				foreach ($options[$key][1] as $each) {
					if (! in_array($each, $options[$key][2])) {
						$this->error = "$key=" . htmlsc(join(",", $options[$key][1])) . " is invalid. ";
						$this->error .= "The option, " . $key . ", accepts sets of values from (" . join(",", $options[$key][2]) . "). ";
						$this->error .= "By the way, #$this->plugin($key) equals to #$this->plugin($key=(" . join(',',$options[$key][2]) . ")). ";
						return;
					}
				} 
				break;
			default:
			}
		}

		return $options;
	}
	
	/**
	 * Handle associative type option arguments as
	 * ["prefix=Hoge/", "contents=(hoge", "hoge", "hoge)"] => ["prefix"=>"hoge/", "contents"=>"(hoge,hoge,hoge)"]
	 * This has special supports for parentheses type arguments (number, array, enumarray)
	 * Check option in along with.
	 * @access	public
	 * @param	 Array $args	  Original option arguments
	 * @return	Array $result	Converted associative option arguments
	 */
	function associative_args($args, $options)
	{
		$result = array();
		while (($arg = current($args)) !== false) {
			list($key, $val) = array_pad(explode("=", $arg, 2), 2, '');
			if (! isset($options[$key])) {
				$this->error = 'No such a option, ' . htmlsc($key);
				return;
			}
			// paren support
			if ($val[0] === '(' && ($options[$key][0] == 'number' || 
				 $options[$key][0] == 'array' || $options[$key][0] == 'enumarray')) {
				while(true) {
					if ($val[strlen($val)-1] === ')' && substr_count($val, '(') == substr_count($val, ')')) {
						break;
					}
					$arg = next($args);
					if ($arg === false) {
						$this->error = "The # of open and close parentheses of one of your arguments did not match. ";
						return;
					}
					$val .= ',' . $arg;
				}
			}
			$result[$key] = $val;
			next($args);
		}
		return $result;
	}

	function parse_numoption($optionval, $min, $max)
	{
		if ($optionval === '') {
			return '';
		}
		$result = array();
		foreach (explode(",", $optionval) as $range) {
			if (preg_match('/^-?\d+$/', $range)) {
				$left = $right = $range;
			} elseif (preg_match('/^-?\d*\:-?\d*$/', $range)) {
				list($left, $right) = explode(":", $range, 2);
				if ($left == "" && $right == "") {
					$left = $min;
					$right = $max;
				} elseif($left == "") {
					$left = $min;
				} elseif ($right == "") {
					$right = $max;
				}
			} elseif (preg_match('/^-?\d+\+-?\d+$/', $range)) {
				list($left, $right) = explode("+", $range, 2);
				$right += $left;
			}
			if ($left < 0) {
				$left += $max + 1;
			}
			if ($right < 0) {
				$right += $max + 1;
			}
			$result = array_merge($result, range($left, $right));
			// range allows like range(5, 3) also
		}
		// filter
		foreach (array_keys($result) as $i) {
			if ($result[$i] < $min || $result[$i] > $max) {
				unset($result[$i]);
			}
		}
		sort($result);
		$result = array_unique($result);

		return $result;
	}

	function option_debug_print($options) {
		foreach ($options as $key => $val) {
			$type = $val[0];
			$val = $val[1];
			if(is_array($val)) {
				$val=join(',', $val);
			}
			$body .= "$key=>($type, $val),";
		}
		return $body;
	}

	// php extension
	function is_associative_array($array) 
	{
		if (!is_array($array) || empty($array))
			return false;
		$keys = array_keys($array);
		return array_keys($keys) !== $keys;
		// or
		//return is_array($array) && !is_numeric(implode(array_keys($array)));
	}
}

//////////////// PukiWiki API Extension
if (! function_exists('is_admin')) {
	/**
	 * PukiWiki admin login with session
	 *
	 * @param string $pass
	 * @param boolean $use_session Use Session log
	 * @param boolean $use_basicauth Use BasicAuth log
	 * @return boolean
	 */
	function is_admin($pass = null, $use_session = false, $use_basicauth = false)
	{
		$is_admin = FALSE;
		if ($use_basicauth) {
			if (is_callable(array('auth', 'check_role'))) { // Plus!
				$is_admin = ! auth::check_role('role_adm_contents');
			}
		}
		if (! $is_admin && isset($pass)) {
			$is_admin = function_exists('pkwk_login') ? pkwk_login($pass) : 
				md5($pass) === $GLOBALS['adminpass']; // 1.4.3
		}
		if ($use_session) {
			session_start();
			if ($is_admin) $_SESSION['is_admin'] = TRUE;
			return isset($_SESSION['is_admin']) && $_SESSION['is_admin'];
		} else {
			return $is_admin;
		}
	}
}

if (! function_exists('is_page_newer')) {
	/**
	 * Check if the page timestamp is newer than the file timestamp
	 *
	 * PukiWiki API Extension
	 *
	 * @param string $page pagename
	 * @param string $file filename
	 * @param bool $ignore_notimestamp Ignore notimestamp edit and see the real time editted
	 * @return boolean
	 */
	function is_page_newer($page, $file, $ignore_notimestamp = TRUE)
	{
		$filestamp = file_exists($file) ? filemtime($file) : 0;
		if ($ignore_notimestamp) { // See the diff file. PukiWiki Trick. 
			$pagestamp  = is_page($page) ? filemtime(DIFF_DIR . encode($page) . '.txt') : 0;
		} else {
			$pagestamp  = is_page($page) ? filemtime(get_filename($page)) : 0;
		}	
		return $pagestamp > $filestamp;
	}
}

if (! function_exists('exec_page')) {
	/**
	 * Execute (convert_html) this page
	 *
	 * PukiWiki API Extension
	 *
	 * @param string $page
	 * @param string $regexp execute only matched lines (preg_grep)
	 * @return boolean executed
	 */
	function exec_page($page, $regexp = null)
	{
		global $vars, $get, $post;
		$lines = get_source($page);
		if (isset($regexp)) {
			$lines = preg_grep($regexp, $lines);
		}
		if (empty($lines)) return FALSE;
		$tmp_page = $vars['page'];
		$tmp_cmd  = $vars['cmd'];
		$vars['cmd'] = $get['cmd'] = $post['cmd'] = 'read';
		$vars['page'] = $get['page'] = $post['page'] = $page;
		convert_html($lines);
		$vars['page'] = $get['page'] = $post['page'] = $tmp_page;
		$vars['cmd'] = $get['cmd'] = $post['cmd'] = $tmp_cmd;
		return TRUE;
	}
}

if (! function_exists('exec_existpages')) {
	/**
	 * Execute (convert_html) all pages
	 *
	 * PukiWiki API Extension
	 *
	 * @param string $regexp execute only matched lines (preg_grep)
	 * @return array executed pages
	 */
	function exec_existpages($regexp = null)
	{
		global $vars, $get, $post;
		$pages = get_existpages();
		$exec_pages = array();
		$tmp_page = $vars['page'];
		$tmp_cmd  = $vars['cmd'];
		$vars['cmd'] = $get['cmd'] = $post['cmd'] = 'read';
		foreach ($pages as $page) {
			$vars['page'] = $get['page'] = $post['page'] = $page;
			$lines = get_source($page);
			if (isset($regexp)) {
				$lines = preg_grep($regexp, $lines);
			}
			if (empty($lines)) continue;
			convert_html($lines);
			$exec_pages[] = $page;
		}
		$vars['page'] = $get['page'] = $post['page'] = $tmp_page;
		$vars['cmd'] = $get['cmd'] = $post['cmd'] = $tmp_cmd;
		return $exec_pages;
	}
}

////////////// PHP API Extension
if (! function_exists('get_existfiles')) {
	/**
	 * Get list of files in a directory
	 *
	 * PHP Extension
	 *
	 * @access public
	 * @param string $dir Directory Name
	 * @param string $ext File Extension
	 * @param bool $recursive Traverse Recursively
	 * @return array array of filenames
	 * @uses is_dir()
	 * @uses opendir()
	 * @uses readdir()
	 */
	function &get_existfiles($dir, $ext = '', $recursive = FALSE) 
	{
		if (($dp = @opendir($dir)) == FALSE)
			return FALSE;
		$pattern = '/' . preg_quote($ext, '/') . '$/';
		$dir = ($dir[strlen($dir)-1] == '/') ? $dir : $dir . '/';
		$dir = ($dir == '.' . '/') ? '' : $dir;
		$files = array();
		while (($file = readdir($dp)) !== false ) {
			if($file != '.' && $file != '..' && is_dir($dir . $file) && $recursive) {
				$files = array_merge($files, get_existfiles($dir . $file, $ext, $recursive));
			} else {
				$matches = array();
				if (preg_match($pattern, $file, $matches)) {
					$files[] = $dir . $file;
				}
			}
		}
		closedir($dp);
		return $files;
	}
}

if (! function_exists('is_associative_array')) {
	/**
	 * Check if an array is an associative array
	 *
	 * PHP Extension
	 *
	 * @param array $array
	 * @return boolean
	 */
	function is_associative_array($array) 
	{
		if (!is_array($array) || empty($array))
			return false;
		$keys = array_keys($array);
		return array_keys($keys) !== $keys;
		// or
		//return is_array($array) && !is_numeric(implode(array_keys($array)));
	}
}

if (! function_exists('_')) {
	function &_($str)
	{
		return $str;
	}
}

///////////////////////////////////
function plugin_contentsx_common_init()
{
	global $plugin_contentsx;
	if (class_exists('PluginContentsxUnitTest')) {
		$plugin_contentsx = new PluginContentsxUnitTest();
	} elseif (class_exists('PluginContentsxUser')) {
		$plugin_contentsx = new PluginContentsxUser();
	} else {
		$plugin_contentsx = new PluginContentsx();
	}
}

function plugin_contentsx_convert()
{
	global $plugin_contentsx; plugin_contentsx_common_init();
	$args = func_get_args();
	return call_user_func_array(array(&$plugin_contentsx, 'convert'), $args);
}
function plugin_contentsx_action()
{
	global $plugin_contentsx; plugin_contentsx_common_init();
	return call_user_func(array(&$plugin_contentsx, 'action'));
}

?>
