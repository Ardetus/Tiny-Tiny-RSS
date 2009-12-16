<?php

	/* This is experimental JSON-based API. It has to be manually enabled:
	 * 
	 * Add define('_JSON_API_ENABLED', true) to config.php
	 */

	error_reporting(E_ERROR | E_PARSE);

	require_once "../config.php";
	
	require_once "../db.php";
	require_once "../db-prefs.php";
	require_once "../functions.php";

	if (!defined('_JSON_API_ENABLED')) {
		print json_encode(array("error" => "API_DISABLED"));
		return;
	}

	$link = db_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);	

	$session_expire = SESSION_EXPIRE_TIME; //seconds
	$session_name = (!defined('TTRSS_SESSION_NAME')) ? "ttrss_sid_api" : TTRSS_SESSION_NAME . "_api";

	session_start();

	if (!$link) {
		if (DB_TYPE == "mysql") {
			print mysql_error();
		}
		// PG seems to display its own errors just fine by default.		
		return;
	}

	init_connection($link);

	$op = db_escape_string($_REQUEST["op"]);

//	header("Content-Type: application/json");

	if (!$_SESSION["uid"] && $op != "login" && $op != "isLoggedIn") {
		print json_encode(array("error" => 'NOT_LOGGED_IN'));
		return;
	}

/*	TODO: add pref key to disable/enable API
	if ($_SESSION["uid"] && !get_pref($link, 'API_ENABLED')) {
		print json_encode(array("error" => 'API_DISABLED'));
		return;
	} */

	switch ($op) {
		case "getVersion":
			$rv = array("version" => VERSION);
			print json_encode($rv);
		break;
		case "login":
			$login = db_escape_string($_REQUEST["user"]);
			$password = db_escape_string($_REQUEST["password"]);

			if (authenticate_user($link, $login, $password)) {
				print json_encode(array("uid" => $_SESSION["uid"]));
			} else {
				print json_encode(array("uid" => 0));
			}

			break;
		case "logout":
			logout_user();
			print json_encode(array("uid" => 0));
			break;
		case "isLoggedIn":
			print json_encode(array("status" => $_SESSION["uid"] != ''));
			break;
		case "getFeeds":
			$cat_id = db_escape_string($_REQUEST["cat_id"]);
			$unread_only = (bool)db_escape_string($_REQUEST["unread_only"]);

			if (!$cat_id) {
				$result = db_query($link, "SELECT 
					id, feed_url, cat_id, title, ".
						SUBSTRING_FOR_DATE."(last_updated,1,19) AS last_updated
						FROM ttrss_feeds WHERE owner_uid = " . $_SESSION["uid"]);
			} else {
				$result = db_query($link, "SELECT 
					id, feed_url, cat_id, title, ".
						SUBSTRING_FOR_DATE."(last_updated,1,19) AS last_updated
						FROM ttrss_feeds WHERE 
							cat_id = '$cat_id' AND owner_uid = " . $_SESSION["uid"]);
			}

			$feeds = array();

			while ($line = db_fetch_assoc($result)) {

				$unread = getFeedUnread($link, $line["id"]);

				if ($unread || !$unread_only) {

					$line_struct = array(
							"feed_url" => $line["feed_url"],
							"title" => $line["title"],
							"id" => (int)$line["id"],
							"unread" => (int)$unread,
							"cat_id" => (int)$line["cat_id"],
							"last_updated" => strtotime($line["last_updated"])
						);
	
					array_push($feeds, $line_struct);
				}
			}

			print json_encode($feeds);

			break;
		case "getCategories":
			$result = db_query($link, "SELECT 
					id, title FROM ttrss_feed_categories 
				WHERE owner_uid = " . 
				$_SESSION["uid"]);

			$cats = array();

			while ($line = db_fetch_assoc($result)) {
				$unread = getFeedUnread($link, $line["id"], true);
				array_push($cats, array($line["id"] => 
					array("title" => $line["title"], "unread" => $unread)));
			}

			print json_encode($cats);
			break;
		case "getHeadlines":
			$feed_id = db_escape_string($_REQUEST["feed_id"]);
			$limit = (int)db_escape_string($_REQUEST["limit"]);
			$filter = db_escape_string($_REQUEST["filter"]);
			$is_cat = (bool)db_escape_string($_REQUEST["is_cat"]);
			$show_except = (bool)db_escape_string($_REQUEST["show_excerpt"]);

			/* do not rely on params below */

			$search = db_escape_string($_REQUEST["search"]);
			$search_mode = db_escape_string($_REQUEST["search_mode"]);
			$match_on = db_escape_string($_REQUEST["match_on"]);
			
			$qfh_ret = queryFeedHeadlines($link, $feed_id, $limit, 
				$view_mode, $is_cat, $search, $search_mode, $match_on);

			$result = $qfh_ret[0];
			$feed_title = $qfh_ret[1];

			$headlines = array();

			while ($line = db_fetch_assoc($result)) {
				$is_updated = ($line["last_read"] == "" && 
					($line["unread"] != "t" && $line["unread"] != "1"));

				$headline_row = array(
						"id" => (int)$line["id"],
						"unread" => sql_bool_to_bool($line["unread"]),
						"marked" => sql_bool_to_bool($line["marked"]),
						"updated" => strtotime($line["updated"]),
						"is_updated" => $is_updated,
						"title" => $line["title"],
						"feed_id" => $line["feed_id"],
					);

				if ($show_except) $headline_row["excerpt"] = $line["content_preview"];
			
				array_push($headlines, $headline_row);
			}

			print json_encode($headlines);

			break;
	}

	db_close($link);
	
?>
