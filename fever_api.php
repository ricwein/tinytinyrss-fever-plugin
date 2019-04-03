<?php

/**
 * v1.4.7
 */
class FeverAPI extends Handler
{
    const API_LEVEL  = 3;

    const STATUS_OK  = 1;
    const STATUS_ERR = 0;

    // debugging only functions with JSON
    const DEBUG = false; // enable if you need some debug output in your tinytinyrss root
    const DEBUG_USER = 0; // your user id you need to debug - look it up in your mysql database and set it to a value bigger than 0
    const DEBUG_FILE = './debug_fever.txt'; // the file for debugging output
    const ADD_ATTACHED_FILES = 1; //add link in bottom for attached files

    /**
     * @var int
     */
    private $ID_HACK_FOR_MRREADER = 0;

    /**
     * @var string|bool
     */
    private $xml;

    /**
     * @param mixed $args
     */
    public function __construct($args)
    {
		parent::__construct($args);

		if (!isset($_SESSION)) {
			session_start();
		}
    }

    /**
     * always include api_version, status as 'auth'
     * output json/xml
     * @param  int $status
     * @param  array|null $reply
     * @return void
     */
    public function wrap($status, $reply)
    {
        $arr = array('api_version' => self::API_LEVEL, 'auth' => $status);

        if ((int) $status === self::STATUS_OK) {
            $arr['last_refreshed_on_time'] = $this->lastRefreshedOnTime() . '';
            if (!empty($reply) && is_array($reply)) {
                $arr = array_merge($arr, $reply);
            }
        } elseif (self::DEBUG) {
			file_put_contents(self::DEBUG_FILE, 'session  : ' . json_encode($_SESSION) . "\n", FILE_APPEND);
		}

		// debug output
		if (self::DEBUG) {
			file_put_contents(self::DEBUG_FILE, 'answer   : ' . json_encode($arr) . "\n", FILE_APPEND);
		}

        if ($this->xml) {
            echo $this->array_to_xml($arr);
        } else {
            echo json_encode($arr);
        }
    }

    /**
     * fever supports xml wrapped in <response> tags
     * @param  array  $array
     * @param  string  $container
     * @param  bool $is_root
     * @return string
     */
    private function array_to_xml($array, $container = 'response', $is_root = true)
    {
        if (!is_array($array)) {
            return array_to_xml(array($array));
        }

        $xml = '';

        if ($is_root) {
            $xml .= '<?xml version="1.0" encoding="utf-8"?>';
            $xml .= "<{$container}>";
        }

        foreach ($array as $key => $value) {
            // make sure key is a string
            $elem = $key;

            if (!is_string($key) && !empty($container)) {
                $elem = $container;
            }

            $xml .= "<{$elem}>";

            if (is_array($value)) {
                if (array_keys($value) !== array_keys(array_keys($value))) {
                    $xml .= array_to_xml($value, '', false);
                } else {
                    $xml .= array_to_xml($value, r('/s$/', '', $elem), false);
                }
            } else {
                $xml .= (htmlspecialchars($value, ENT_COMPAT, 'ISO-8859-1') != $value) ? "<![CDATA[{$value}]]>" : $value;
            }

            $xml .= "</{$elem}>";
        }

        if ($is_root) {
            $xml .= "</{$container}>";
        }

        return preg_replace('/[\x00-\x1F\x7F]/', '', $xml);
    }

    /**
     * every authenticated method includes last_refreshed_on_time
     * @return int
     */
    private function lastRefreshedOnTime()
    {
		$query = $this->pdo->prepare('SELECT last_updated FROM ttrss_feeds WHERE owner_uid = :ownerID ORDER BY last_updated DESC');
		$query->execute(array(':ownerID' => $_SESSION['uid']));

        if ($query->rowCount() > 0 && $row = $query->fetch(\PDO::FETCH_ASSOC)) {
            $last_refreshed_on_time = strtotime(isset($row['last_updated']) ? $row['last_updated'] : 0);
        } else {
            $last_refreshed_on_time = 0;
        }

        return $last_refreshed_on_time;
    }

    /**
     * find the user in the db with a particular api key
     * @return void
     */
    private function setUser()
    {
        $apikey = isset($_REQUEST['api_key']) ? $_REQUEST['api_key'] : '';

        // here comes Mr.Reader special API for logging in
        if ((strlen($apikey) === 0)
            && (isset($_REQUEST['action']))
            && ($_REQUEST['action'] === 'login')
            && (isset($_REQUEST['email']))
            && (isset($_REQUEST['password']))) {
            $email = $_REQUEST['email'];
            $password = $_REQUEST['password'];
            $apikey = strtoupper(md5($email . ':' . $password));

            setcookie('fever_auth', $apikey, time() + (60 * 60 * 24 * 30));

            // debug output
            if (self::DEBUG) {
                file_put_contents(self::DEBUG_FILE, 'auth POST: ' . json_encode(array('email' => $email, 'apikey' => $apikey))."\n", FILE_APPEND);
            }
        }
        if ((strlen($apikey) === 0) && isset($_COOKIE['fever_auth'])) {
            // override for Mr.Reader when doing some stuff
            $apikey = $_COOKIE['fever_auth'];
        } elseif (strlen($apikey) > 0) {

			$query = $this->pdo->prepare('SELECT owner_uid FROM ttrss_plugin_storage WHERE content = :content');
			$query->execute(array(':content' => 'a:1:{s:8:"password";s:32:"' . strtolower($apikey) . '";}'));

            if ($query->rowCount() > 0 && $row = $query->fetch(\PDO::FETCH_ASSOC)) {
                $_SESSION['uid'] = isset($row['owner_uid']) ? $row['owner_uid'] : 0;
            }

            // always authenticate and set debug user
            if (self::DEBUG_USER > 0) {
                $_SESSION['uid'] = self::DEBUG_USER;
            }
        }
    }

    /**
     * set whether xml or json
     * @return void
     */
    private function setXml()
    {
        $this->xml = false;
        if (isset($_REQUEST['api'])) {
            if (strtolower($_REQUEST['api']) === 'xml') {
                $this->xml = true;
            }
        }
    }

    /**
     * @param  array $groupsToGroups
     * @param  array $groups
     * @param  array $groupsToTitle
     * @param  int|string $index
     * @return void
     */
    private function flattenGroups(&$groupsToGroups, &$groups, &$groupsToTitle, $index)
    {
        foreach ($groupsToGroups[$index] as $item) {
            $id = substr($item, strpos($item, '-') + 1);
            array_push($groups, array('id' => intval($id), 'title' => $groupsToTitle[$id]));
            if (isset($groupsToGroups[$id])) {
                $this->flattenGroups($groupsToGroups, $groups, $groupsToTitle, $id);
            }
        }
    }

	/**
	 * @return array
	 */
    public function getGroups()
    {
        // TODO: ordering of child categories etc
        $groups = array();
		$groupsToGroups = array();
        $groupsToTitle = array();

		$query = $this->pdo->prepare('SELECT id, title, parent_cat FROM ttrss_feed_categories WHERE owner_uid = :ownerID ORDER BY order_id ASC');
		$query->execute(array(':ownerID' => $_SESSION['uid']));

        while ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
            if ($row['parent_cat'] === null) {
                if (!isset($groupsToGroups[-1])) {
                    $groupsToGroups[-1] = array();
                }

                array_push($groupsToGroups[-1], $row['order_id'] . '-' . $row['id']);
            } else {
                if (!isset($groupsToGroups[$row['parent_cat']])) {
                    $groupsToGroups[$row['parent_cat']] = array();
                }

                array_push($groupsToGroups[$row['parent_cat']], $row['order_id'] . '-' . $row['id']);
            }

            $groupsToTitle[$row['id']] = $row['title'];
        }

        foreach ($groupsToGroups as $key => $value) {
            sort($value);
        }

        if (isset($groupsToGroups[-1])) {
            $this->flattenGroups($groupsToGroups, $groups, $groupsToTitle, -1);
        }

        return $groups;
    }

	/**
	 * @return array
	 */
    public function getFeeds()
    {
        $feeds = array();

		$query = $this->pdo->prepare('SELECT id, title, feed_url, site_url, last_updated FROM ttrss_feeds WHERE owner_uid = :ownerID ORDER BY order_id ASC');
		$query->execute(array(':ownerID' => $_SESSION['uid']));

        while ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
            array_push($feeds, array(
				'id' => intval($row['id']),
                'favicon_id' => intval($row['id']),
				'title' => $row['title'],
				'url' => $row['feed_url'],
				'site_url' => $row['site_url'],
                'is_spark' => 0, // unsported
                'last_updated_on_time' => strtotime($row['last_updated'])
            ));
        }

        return $feeds;
    }

	/**
	 * @return array
	 */
    public function getFavicons()
    {
        $favicons = array();

		$query = $this->pdo->prepare('SELECT id FROM ttrss_feeds WHERE owner_uid = :ownerID ORDER BY order_id ASC');
		$query->execute(array(':ownerID' => $_SESSION['uid']));


        // data = "image/gif;base64,<base64 encoded image>
        while ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
            $filename = 'feed-icons/' . $row['id'] . '.ico';
            if (file_exists($filename)) {
                array_push($favicons, array(
					'id' => intval($row['id']),
                    'data' => image_type_to_mime_type(exif_imagetype($filename)) . ';base64,' . base64_encode(file_get_contents($filename))
                ));
            }
        }

        return $favicons;
    }

	/**
	 * @return array
	 */
    public function getLinks()
    {
        // TODO: is there a 'hot links' alternative in ttrss?
        // use ttrss_user_entries / score>0
        $links = array();

        return $links;
    }

	/**
	 * @param  string $html
	 * @return string
	 */
    public function rewrite_urls($html)
    {
        libxml_use_internal_errors(true);

        $charset_hack = '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"/></head>';

        $doc = new DOMDocument();
        $doc->loadHTML($charset_hack . $html);
        $xpath = new DOMXPath($doc);

        $entries = $xpath->query('//*/text()');

        foreach ($entries as $entry) {
            if (strstr($entry->wholeText, '://') !== false) {
                $text = preg_replace(
                    '/((?<!=.)((http|https|ftp)+):\/\/[^ ,!]+)/i',
                    '<a target="_blank" href="\\1">\\1</a>',
                    $entry->wholeText
                );

                if ($text !== $entry->wholeText) {
                    $cdoc = new DOMDocument();
                    $cdoc->loadHTML($charset_hack . $text);

                    foreach ($cdoc->childNodes as $cnode) {
                        $cnode = $doc->importNode($cnode, true);

                        if ($cnode) {
                            $entry->parentNode->insertBefore($cnode);
                        }
                    }

                    $entry->parentNode->removeChild($entry);
                }
            }
        }

        $node = $doc->getElementsByTagName('body')->item(0);

        // http://tt-rss.org/forum/viewtopic.php?f=1&t=970
        if ($node) {
            return $doc->saveXML($node);
        } else {
            return $html;
        }
    }

	/**
	 * @param  string  $str
	 * @param  bool $site_url
	 * @return string
	 */
    public function my_sanitize($str, $site_url = false)
    {
        $res = trim($str);
        if (!$res) {
            return '';
        }

        if (strpos($res, 'href=') === false) {
            $res = $this->rewrite_urls($res);
        }

        $charset_hack = '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8" /></head>';

        $res = trim($res);
        if (!$res) {
            return '';
        }

        libxml_use_internal_errors(true);

        $doc = new DOMDocument();
        $doc->loadHTML($charset_hack . $res);
        $xpath = new DOMXPath($doc);

        $entries = $xpath->query('(//a[@href]|//img[@src])');

        foreach ($entries as $entry) {
            if ($site_url) {
                if ($entry->hasAttribute('href')) {
                    $entry->setAttribute('href', rewrite_relative_url($site_url, $entry->getAttribute('href')));
                }

                if ($entry->hasAttribute('src')) {
                    $src = rewrite_relative_url($site_url, $entry->getAttribute('src'));
                    $entry->setAttribute('src', $src);
                }
            }

            if (strtolower($entry->nodeName) === 'a') {
                $entry->setAttribute('target', '_blank');
            }
        }

        $entries = $xpath->query('//iframe');
        foreach ($entries as $entry) {
            $entry->setAttribute('sandbox', 'allow-scripts allow-same-origin');
        }

        $disallowed_attributes = array('id', 'style', 'class');

        $entries = $xpath->query('//*');
        foreach ($entries as $entry) {
            if ($entry->hasAttributes()) {
                $attrs_to_remove = array();
                foreach ($entry->attributes as $attr) {

					//remove onclick and other on* attributes
                    if (strpos($attr->nodeName, 'on') === 0) {
                        array_push($attrs_to_remove, $attr);
                    }

                    if (in_array($attr->nodeName, $disallowed_attributes)) {
                        array_push($attrs_to_remove, $attr);
                    }
                }
                foreach ($attrs_to_remove as $attr) {
                    $entry->removeAttributeNode($attr);
                }
            }
        }

		//remove doctype
        $doc->removeChild($doc->firstChild);

        $res = $doc->saveHTML();
        return $res;
    }

	/**
	 * @param  int $bytes
	 * @param  int $precision
	 * @return string
	 */
    public function formatBytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        // Uncomment one of the following alternatives
        $bytes /= pow(1024, $pow);
        // $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

	/**
	 * @return array
	 */
    public function getItems()
    {
        // items from specific groups, feeds
        $items = array();

        $item_limit = 50;
        $where = ' owner_uid = \'' . db_escape_string($_SESSION['uid']) . '\' AND ref_id = id ';

        if (isset($_REQUEST['feed_ids']) || isset($_REQUEST['group_ids'])) { // added 0.3
            $feed_ids = array();

            if (isset($_REQUEST['feed_ids'])) {
                $feed_ids = explode(',', $_REQUEST['feed_ids']);
            }

            if (isset($_REQUEST['group_ids'])) {
                $group_ids = explode(',', $_REQUEST['group_ids']);
                $num_group_ids = sizeof($group_ids);
                $groups_query = ' AND cat_id IN (';
                foreach ($group_ids as $group_id) {
                    if (is_numeric($group_id)) {
                        $groups_query .= db_escape_string(intval($group_id)) . ',';
                    } else {
                        $num_group_ids--;
                    }
                }
                if ($num_group_ids <= 0) {
                    $groups_query = ' AND cat_id IN (\'\') ';
                } else {
                    $groups_query = trim($groups_query, ',') . ')';
                }

				$query = $this->pdo->prepare('SELECT id FROM ttrss_feeds WHERE owner_uid = :ownerID ' . $groups_query);
				$query->execute(array(':ownerID' => $_SESSION['uid']));

                $group_feed_ids = array();
                while ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
                    array_push($group_feed_ids, $row['id']);
                }

                $feed_ids = array_unique(array_merge($feed_ids, $group_feed_ids));
            }

            $query = ' feed_id IN (';
            $num_feed_ids = sizeof($feed_ids);
            foreach ($feed_ids as $feed_id) {
                if (is_numeric($feed_id)) {
                    $query.= db_escape_string(intval($feed_id)) . ',';
                } else {
                    $num_feed_ids--;
                }
            }

            if ($num_feed_ids <= 0) {
                $query = ' feed_id IN (\'\') ';
            } else {
                $query = trim($query, ',') . ')';
            }

            if (!empty($where)) {
                $where .= ' AND ';
            }
            $where .= $query;
        }

        if (isset($_REQUEST['max_id'])) { // descending from most recently added
            // use the max_id argument to request the previous $item_limit items
            if (is_numeric($_REQUEST['max_id'])) {
                $max_id = ($_REQUEST['max_id'] > 0) ? intval($_REQUEST['max_id']) : 0;
                if ($max_id) {
                    if (!empty($where)) {
                        $where .= ' AND ';
                    }
                    $where .= 'id < ' . db_escape_string($max_id) . ' ';
                } elseif (empty($where)) {
                    $where .= '1';
                }

                $where .= ' ORDER BY id DESC';
            }
        } elseif (isset($_REQUEST['with_ids'])) { // selective
            if (!empty($where)) {
                $where .= ' AND ';
            } // group_ids & feed_ids don't make sense with this query but just in case

            $item_ids = explode(',', $_REQUEST['with_ids']);
            $query = 'id IN (';
            $num_ids = sizeof($item_ids);
            foreach ($item_ids as $item_id) {
                if (is_numeric($item_id)) {
                    $query .= db_escape_string(intval($item_id)) . ',';
                } else {
                    $num_ids--;
                }
            }

            if ($num_ids <= 0) {
                $query = 'id IN (\'\') ';
            } else {
                $query = trim($query, ',') . ') ';
            }

            $where .= $query;
        } else { // ascending from first added
            if (is_numeric($_REQUEST['since_id'])) {
                // use the since_id argument to request the next $item_limit items
                $since_id 	= isset($_GET['since_id']) ? intval($_GET['since_id']) : 0;

                if ($since_id) {
                    if (!empty($where)) {
                        $where .= ' AND ';
                    }
                    if ($this->ID_HACK_FOR_MRREADER) {
                        $where .= 'id > ' . db_escape_string($since_id * 1000) . ' '; // NASTY hack for Mr. Reader 2.0 on iOS and TinyTiny RSS Fever
                    } else {
                        $where .= 'id > ' . db_escape_string($since_id) . ' ';
                    }
                } elseif (empty($where)) {
                    $where .= '1';
                }

                $where .= ' ORDER BY id ASC';
            }
        }

        $where .= ' LIMIT ' . $item_limit;

        // id, feed_id, title, author, html, url, is_saved, is_read, created_on_time
		$query = $this->pdo->prepare('SELECT ref_id, feed_id, title, link, content, id, marked, unread, author, updated FROM ttrss_entries, ttrss_user_entries WHERE ' . $where);
		$query->execute();

        while ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
            $row_content = $this->my_sanitize($row['content'], $row['link']);

            if (self::ADD_ATTACHED_FILES) {

                $enclosures = Article::get_article_enclosures($row['id']);

                if (count($enclosures) > 0) {
                    $row_content .= '<ul type="lower-greek">';
                    foreach ($enclosures as $enclosure) {
                        if (!empty($enclosure['content_url'])) {
                            $enc_type = '';
                            if (!empty($enclosure['content_type'])) {
                                $enc_type = ', '.$enclosure['content_type'];
                            }
                            $enc_size = '';
                            if (!empty($enclosure['duration'])) {
                                $enc_size = ' , '.$this->formatBytes($enclosure['duration']);
                            }
                            $row_content .= '<li><a href="' . $enclosure['content_url'] . '" target="_blank">' . basename($enclosure['content_url']) . $enc_type.$enc_size.'</a>' . '</li>';
                        }
                    }
                    $row_content .= '</ul>';
                }
            }
            array_push($items, array(
				'id' => intval($row['id']),
				'feed_id' => intval($row['feed_id']),
				'title' => $row['title'],
				'author' => $row['author'],
				'html' => $row_content,
				'url' => $row['link'],
				'is_saved' => (sql_bool_to_bool($row['marked']) ? 1 : 0),
				'is_read' => ((!sql_bool_to_bool($row['unread'])) ? 1 : 0),
				'created_on_time' => strtotime($row['updated'])
            ));
        }

        return $items;
    }

	/**
	 * @return int
	 */
    public function getTotalItems()
    {
        // number of total items
        $total_items = 0;

		$query = $this->pdo->prepare('SELECT COUNT(ref_id) as total_items FROM ttrss_user_entries WHERE owner_uid = :ownerID');
		$query->execute(array(':ownerID' => $_SESSION['uid']));


        if ($query->rowCount() > 0 && $row = $query->fetch(\PDO::FETCH_ASSOC)) {
            $total_items = isset($row['total_items']) ? $row['total_items'] : 0;
        }

        return $total_items;
    }

	/**
	 * @return array
	 */
    public function getFeedsGroup()
    {
        $feeds_groups = array();
		$groupsToFeeds = array();

		$query = $this->pdo->prepare('SELECT id, cat_id FROM ttrss_feeds WHERE owner_uid = :ownerID AND cat_id IS NOT NULL ORDER BY id ASC');
		$query->execute(array(':ownerID' => $_SESSION['uid']));

        while ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
            if (!array_key_exists($row['cat_id'], $groupsToFeeds)) {
                $groupsToFeeds[$row['cat_id']] = array();
            }

            array_push($groupsToFeeds[$row['cat_id']], $row['id']);
        }

        foreach ($groupsToFeeds as $group => $feeds) {
            $feedsStr = '';
            foreach ($feeds as $feed) {
                $feedsStr .= $feed . ',';
            }
            $feedsStr = trim($feedsStr, ',');

            array_push($feeds_groups, array(
				'group_id' => $group,
                'feed_ids' => $feedsStr
			));
        }
        return $feeds_groups;
    }

	/**
	 * @return string
	 */
    public function getUnreadItemIds()
    {
        $unreadItemIdsCSV = '';

		// ORDER BY red_id DESC
		$query = $this->pdo->prepare('SELECT ref_id FROM ttrss_user_entries WHERE owner_uid = :ownerID AND unread = 1');
		$query->execute(array(':ownerID' => $_SESSION['uid']));

        while ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
            $unreadItemIdsCSV .= $row['ref_id'] . ',';
        }
        $unreadItemIdsCSV = trim($unreadItemIdsCSV, ',');

        return $unreadItemIdsCSV;
    }

	/**
	 * @return string
	 */
    public function getSavedItemIds()
    {
        $savedItemIdsCSV = '';

		$query = $this->pdo->prepare('SELECT ref_id FROM ttrss_user_entries WHERE owner_uid = :ownerID AND marked = 1');
		$query->execute(array(':ownerID' => $_SESSION['uid']));

		while ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
            $savedItemIdsCSV .= $row['ref_id'] . ',';
        }
        $savedItemIdsCSV = trim($savedItemIdsCSV, ',');

        return $savedItemIdsCSV;
    }

	/**
	 * @param string|int  $id
	 * @param int  $field_raw
	 * @param int  $mode
	 * @param int $before
	 */
    public function setItem($id, $field_raw, $mode, $before = 0)
    {
        $field = '';
        $set_to = '';

        switch ($field_raw) {
            case 0:
                $field = 'marked';
                $additional_fields = ',last_marked = NOW()';
                break;
            case 1:
                $field = 'unread';
                $additional_fields = ',last_read = NOW()';
                break;
        };

        switch ($mode) {
            case 1:
                $set_to = 'true';
                break;
            case 0:
                $set_to = 'false';
                break;
        }

        if ($field && $set_to) {
            $article_ids = db_escape_string($id);

			$query = $this->pdo->prepare('UPDATE ttrss_user_entries SET ' . $field . ' = ' . $set_to . ' ' . $additional_fields . ' WHERE ref_id IN (' . $article_ids . ') AND owner_uid = :ownerID');
			$query->execute(array(':ownerID' => $_SESSION['uid']));

            if ($query->rowCount() > 0 && $field === 'unread') {

				$querySelect = $this->pdo->prepare('SELECT DISTINCT feed_id FROM ttrss_user_entries WHERE ref_id IN (' . $article_ids . ')');
				$querySelect->execute();

                while ($row = $querySelect->fetch(\PDO::FETCH_ASSOC)) {
                    CCache::ccache_update($row['feed_id'], $_SESSION['uid']);
                }
            }
        }
    }

	/**
	 * @param int $id
	 * @return void
	 */
    public function setItemAsRead($id)
    {
        $this->setItem($id, 1, 0);
    }

	/**
	 * @param int $id
	 * @return void
	 */
    public function setItemAsUnread($id)
    {
        $this->setItem($id, 1, 1);
    }

	/**
	 * @param int $id
	 * @return void
	 */
    public function setItemAsSaved($id)
    {
        $this->setItem($id, 0, 1);
    }

	/**
	 * @param int $id
	 * @return void
	 */
    public function setItemAsUnsaved($id)
    {
        $this->setItem($id, 0, 0);
    }

	/**
	 * @param int $id
	 * @param mixed $cat
	 * @param int $beforce
	 * @return void
	 */
    public function setFeed($id, $cat, $before=0)
    {
        // if before is zero, set it to now so feeds all items are read from before this point in time
        if ($before == 0) {
            $before = time();
        }

        if (!is_numeric($id)) {
			return;
		}

        // this is a category
        if ($cat) {

            if ($id > 0) {

				// if not special feed
				$query = $this->pdo->prepare('UPDATE ttrss_user_entries SET unread = false, last_read = NOW() WHERE ref_id IN (SELECT id FROM (SELECT id FROM ttrss_entries, ttrss_user_entries WHERE ref_id = id AND owner_uid = :ownerID AND unread = true AND feed_id IN (SELECT id FROM ttrss_feeds WHERE cat_id IN (:id)) AND date_entered < :date) as tmp)');
				$query->execute(array(':ownerID' => $_SESSION['uid'], ':id' => (int) $id, ':date' => date('Y-m-d H:i:s', $before)));
            } elseif ($id == 0) {

				// this is "all" to fever, but internally "all" is -4
                $id = -4;
				$query = $this->pdo->prepare('UPDATE ttrss_user_entries SET unread = false, last_read = NOW() WHERE ref_id IN (SELECT id FROM (SELECT id FROM ttrss_entries, ttrss_user_entries WHERE ref_id = id AND owner_uid = :ownerID AND unread = true AND date_entered < :date) as tmp)');
				$query->execute(array(':ownerID' => $_SESSION['uid'], ':date' => date('Y-m-d H:i:s', $before)));
            }

        } elseif ($id > 0) {

			// not a category
			$query = $this->pdo->prepare('UPDATE ttrss_user_entries SET unread = false, last_read = NOW() WHERE ref_id IN (SELECT id FROM (SELECT id FROM ttrss_entries, ttrss_user_entries WHERE ref_id = id AND owner_uid = :ownerID AND unread = true AND feed_id = :id AND date_entered < :date) as tmp)');
			$query->execute(array(':ownerID' => $_SESSION['uid'], ':id' => (int) $id, ':date' => date('Y-m-d H:i:s', $before)));
        }

        CCache::ccache_update($id, $_SESSION['uid'], $cat);
    }

	/**
	 * @param int $id
	 * @param int $before
	 * @return void
	 */
    public function setFeedAsRead($id, $before)
    {
        $this->setFeed($id, false, $before);
    }

	/**
	 * @param int $id
	 * @param int $before
	 * @return void
	 */
    public function setGroupAsRead($id, $before)
    {
        $this->setFeed($id, true, $before);
    }

    /**
     * this does all the processing, since the fever api does not have a specific variable that specifies the operation
     * @return void
     */
    public function index()
    {
        $response_arr = array();

        if (isset($_REQUEST['groups'])) {
            $response_arr['groups'] = $this->getGroups();
            $response_arr['feeds_groups'] = $this->getFeedsGroup();
        }
        if (isset($_REQUEST['feeds'])) {
            $response_arr['feeds'] = $this->getFeeds();
            $response_arr['feeds_groups'] = $this->getFeedsGroup();
        }
        // TODO: favicon support
        if (isset($_REQUEST['favicons'])) {
            $response_arr['favicons'] = $this->getFavicons();
        }
        if (isset($_REQUEST['items'])) {
            $response_arr['total_items'] = $this->getTotalItems();
            $response_arr['items'] = $this->getItems();
        }
        if (isset($_REQUEST['links'])) {
            $response_arr['links'] = $this->getLinks();
        }
        if (isset($_REQUEST['unread_item_ids'])) {
            $response_arr['unread_item_ids'] = $this->getUnreadItemIds();
        }
        if (isset($_REQUEST['saved_item_ids'])) {
            $response_arr['saved_item_ids'] = $this->getSavedItemIds();
        }

        if (isset($_REQUEST['mark'], $_REQUEST['as'], $_REQUEST['id'])) {
            if (is_numeric($_REQUEST['id'])) {
                $before	= (isset($_REQUEST['before'])) ? $_REQUEST['before'] : null;
                if ($before > pow(10, 10)) {
                    $before = round($before / 1000);
                }
                $method_name = 'set' . ucfirst($_REQUEST['mark']) . 'As' . ucfirst($_REQUEST['as']);

                if (method_exists($this, $method_name)) {
                    $id = intval($_REQUEST['id']);
                    $this->$method_name($id, $before);

                    switch ($_REQUEST['as']) {
                        case 'read':
                        case 'unread':
                            $response_arr['unread_item_ids'] = $this->getUnreadItemIds();
                        break;

                        case 'saved':
                        case 'unsaved':
                            $response_arr['saved_item_ids'] = $this->getSavedItemIds();
                        break;
                    }
                }
            }
        }

        if ($_SESSION['uid']) {
            $this->wrap(self::STATUS_OK, $response_arr);
        } elseif (!$_SESSION['uid']) {
            $this->wrap(self::STATUS_ERR, null);
        }
    }

    /**
     * validate the api_key, user preferences
     * @param  mixed $method
     * @return bool
     */
    public function before($method)
    {
        // Check for all client in Android except ReadKit in Mac, Mr. Reader and Dalvik
        if (strpos($_SERVER['HTTP_USER_AGENT'], 'Dalvik') !== false
            || strpos($_SERVER['HTTP_USER_AGENT'], 'ReadKit') !== false
            || strpos($_SERVER['HTTP_USER_AGENT'], 'Mr. Reader') !== false) {
            $this->ID_HACK_FOR_MRREADER = 0;
        } else {

			// and readkit and dalvik...
            $this->ID_HACK_FOR_MRREADER = 1;
		}


        if (parent::before($method)) {

			// add request to debug log
            if (self::DEBUG) {
                file_put_contents(self::DEBUG_FILE, 'parameter: ' . json_encode($_REQUEST) . "\n", FILE_APPEND);
            }

            // set the user from the db
            $this->setUser();

            // are we xml or json?
            $this->setXml();

            if ($this->xml) {
                header('Content-Type: text/xml');
            } else {
                header('Content-Type: application/json');
            }

            // check we have a valid user
            if (!$_SESSION['uid']) {
                $this->wrap(self::STATUS_ERR, null);
                return false;
            }

            // check if user has api access enabled
            if ($_SESSION['uid'] && !get_pref('ENABLE_API_ACCESS')) {
                $this->wrap(self::STATUS_ERR, null);
                return false;
            }

            return true;
        }
        return false;
    }
}
