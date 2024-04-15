<?php
require_once("../include/bittorrent.php");
dbconn(true);
require_once(get_langfile_path('torrents.php'));
require_once(get_langfile_path('speical.php'));
loggedinorreturn();
parked();

//#####agsv候选#######$ 通过&不通过按钮
use Nexus\Database\NexusDB;
$action = htmlspecialchars($_GET['action'] ?? '');
if ($action == "tmpAction") {
	$cross = $_POST["tmp_cross"];
	$tmpId = $_POST["tmp_id"];
	$tmpOwner = $_POST["tmp_owner"];
	$tmpDO = NexusDB::table("torrents_tmp")->where("id", $tmpId)->first();
	if ($cross == '通过') {
		//NexusDB::table("torrents_tmp")->where("id", $tmpId)->delete();// 测试先不删除
		NexusDB::table("torrents")->insert(get_object_vars($tmpDO));
		Nexus\Database\NexusDB::table("messages")->insert([
			'sender'=>0,
			'receiver'=>$tmpOwner,
			'added'=>date('Y-m-d H:i:s'),
			'subject'=>"候选通过",
			'msg'=>'你的候选已通过, 种子名称: '.$tmpDO->name,
		]);
	}
	if ($cross == '不通过') {
		Nexus\Database\NexusDB::table("messages")->insert([
			'sender'=>0,
			'receiver'=>$tmpOwner,
			'added'=>date('Y-m-d H:i:s'),
			'subject'=>"候选未通过",
			'msg'=>'你的候选未通过, 请前往候选区修改, 种子名称: '.$tmpDO->name,
		]);
	}
	if ($cross == '删除') {
		NexusDB::table("torrents_tmp")->where("id", $tmpId)->delete();// 删除
	}
	nexus_redirect("" . get_protocol_prefix() . "$BASEURL/torrents_tmp.php?cross-".$cross."-tmpId-".$tmpId."-tmpOwner-".$tmpOwner);
}
//#####agsv候选#######@

//check searchbox
switch (nexus()->getScript()) {
    case 'torrents_tmp':
        $sectiontype = $browsecatmode;
        break;
    case 'special':
        if (get_setting('main.spsct') != 'yes') {
            httperr();
        }
        if (!user_can('view_special_torrent')) {
            stderr($lang_special['std_sorry'],$lang_special['std_permission_denied_only'].get_user_class_name(get_setting('authority.view_special_torrent'),false,true,true).$lang_special['std_or_above_can_view'],false);
        }
        $sectiontype = $specialcatmode;
        break;
    default:
        $sectiontype = 0;
}
/**
 * tags
 */
$tagRep = new \App\Repositories\TagRepository();
$allTags = $tagRep->listAll($sectiontype);
$filterInputWidth = 62;
$searchParams = $_GET;
$searchParams['mode'] = $sectiontype;

$showsubcat = get_searchbox_value($sectiontype, 'showsubcat');//whether show subcategory (i.e. sources, codecs) or not
$showsource = get_searchbox_value($sectiontype, 'showsource'); //whether show sources or not
$showmedium = get_searchbox_value($sectiontype, 'showmedium'); //whether show media or not
$showcodec = get_searchbox_value($sectiontype, 'showcodec'); //whether show codecs or not
$showstandard = get_searchbox_value($sectiontype, 'showstandard'); //whether show standards or not
$showprocessing = get_searchbox_value($sectiontype, 'showprocessing'); //whether show processings or not
$showteam = get_searchbox_value($sectiontype, 'showteam'); //whether show teams or not
$showaudiocodec = get_searchbox_value($sectiontype, 'showaudiocodec'); //whether show audio codec or not
$catsperrow = get_searchbox_value($sectiontype, 'catsperrow'); //show how many cats per line in search box
$catpadding = get_searchbox_value($sectiontype, 'catpadding'); //padding space between categories in pixel

$cats = genrelist($sectiontype);
if ($showsubcat){
	if ($showsource) $sources = searchbox_item_list("sources", $sectiontype);
	if ($showmedium) $media = searchbox_item_list("media", $sectiontype);
	if ($showcodec) $codecs = searchbox_item_list("codecs", $sectiontype);
	if ($showstandard) $standards = searchbox_item_list("standards", $sectiontype);
	if ($showprocessing) $processings = searchbox_item_list("processings", $sectiontype);
	if ($showteam) $teams = searchbox_item_list("teams", $sectiontype);
	if ($showaudiocodec) $audiocodecs = searchbox_item_list("audiocodecs", $sectiontype);
}

$searchstr_ori = htmlspecialchars(trim($_GET["search"] ?? ''));
$searchstr = mysql_real_escape_string(trim($_GET["search"] ?? ''));
if (empty($searchstr)) {
    unset($searchstr);
}

$meilisearchEnabled = get_setting('meilisearch.enabled') == 'yes';
$shouldUseMeili = $meilisearchEnabled && !empty($searchstr);
do_log("[SHOULD_USE_MEILI]: $shouldUseMeili");
// sorting by MarkoStamcar
$column = '';
$ascdesc = '';
if (isset($_GET['sort']) && $_GET['sort'] && isset($_GET['type']) && $_GET['type']) {

	switch($_GET['sort']) {
		case '1': $column = "name"; break;
		case '2': $column = "numfiles"; break;
		case '3': $column = "comments"; break;
		case '4': $column = "added"; break;
		case '5': $column = "size"; break;
		case '6': $column = "times_completed"; break;
		case '7': $column = "seeders"; break;
		case '8': $column = "leechers"; break;
		case '9': $column = "owner"; break;
		default: $column = "id"; break;
	}

	switch($_GET['type']) {
		case 'asc': $ascdesc = "ASC"; $linkascdesc = "asc"; break;
		case 'desc': $ascdesc = "DESC"; $linkascdesc = "desc"; break;
		default: $ascdesc = "DESC"; $linkascdesc = "desc"; break;
	}

	if($column == "owner")
	{
		$orderby = "ORDER BY pos_state DESC, torrents.anonymous, users.username " . $ascdesc;
	}
	else
	{
		$orderby = "ORDER BY pos_state DESC, torrents." . $column . " " . $ascdesc;
	}

	$pagerlink = "sort=" . intval($_GET['sort']) . "&type=" . $linkascdesc . "&";

} else {

	$orderby = "ORDER BY pos_state DESC, torrents.id DESC";
	$pagerlink = "";

}

$allCategoryId = \App\Models\SearchBox::listCategoryId($sectiontype);
$addparam = "";
$wherea = array();
$wherecatina = array();
$wheresourceina = array();
$wheremediumina = array();
$wherecodecina = array();
$wherestandardina = array();
$whereprocessingina = array();
$whereteamina = array();
$whereaudiocodecina = array();
//----------------- start whether show torrents from all sections---------------------//
if ($_GET)
	$allsec = intval($_GET["allsec"] ?? 0);
else $allsec = 0;
if ($allsec == 1)		//show torrents from all sections
{
	$addparam .= "allsec=1&";
}
// ----------------- end whether ignoring section ---------------------//
// ----------------- start bookmarked ---------------------//
$inclbookmarked = 0;
if ($_GET)
	$inclbookmarked = intval($_GET["inclbookmarked"] ?? 0);
elseif ($CURUSER['notifs']){
	if (strpos($CURUSER['notifs'], "[inclbookmarked=0]") !== false)
		$inclbookmarked = 0;
	elseif (strpos($CURUSER['notifs'], "[inclbookmarked=1]") !== false)
		$inclbookmarked = 1;
	elseif (strpos($CURUSER['notifs'], "[inclbookmarked=2]") !== false)
		$inclbookmarked = 2;
}

if (!in_array($inclbookmarked,array(0,1,2)))
{
	$inclbookmarked = 0;
	write_log("User " . $CURUSER["username"] . "," . $CURUSER["ip"] . " is hacking inclbookmarked field in" . $_SERVER['SCRIPT_NAME'], 'mod');
}
if ($inclbookmarked == 0)  //all(bookmarked,not)
{
	$addparam .= "inclbookmarked=0&";
}
elseif ($inclbookmarked == 1)		//bookmarked
{
	$addparam .= "inclbookmarked=1&";
	if(isset($CURUSER))
	$wherea[] = "torrents.id IN (SELECT torrentid FROM bookmarks WHERE userid=" . $CURUSER['id'] . ")";
}
elseif ($inclbookmarked == 2)		//not bookmarked
{
	$addparam .= "inclbookmarked=2&";
	if(isset($CURUSER))
	$wherea[] = "torrents.id NOT IN (SELECT torrentid FROM bookmarks WHERE userid=" . $CURUSER['id'] . ")";
}
// ----------------- end bookmarked ---------------------//

if (!isset($CURUSER) || !user_can('seebanned')) {
    $wherea[] = "banned = 'no'";
    $searchParams["banned"] = 'no';
}

// ----------------- start include dead ---------------------//
if (isset($_GET["incldead"]))
	$include_dead = intval($_GET["incldead"] ?? 0);
elseif ($CURUSER['notifs']){
	if (strpos($CURUSER['notifs'], "[incldead=0]") !== false)
		$include_dead = 0;
	elseif (strpos($CURUSER['notifs'], "[incldead=1]") !== false)
		$include_dead = 1;
	elseif (strpos($CURUSER['notifs'], "[incldead=2]") !== false)
		$include_dead = 2;
	else $include_dead = 1;
}
else $include_dead = 1;

if (!in_array($include_dead,array(0,1,2)))
{
	$include_dead = 0;
	write_log("User " . $CURUSER["username"] . "," . $CURUSER["ip"] . " is hacking incldead field in" . $_SERVER['SCRIPT_NAME'], 'mod');
}
if ($include_dead == 0)  //all(active,dead)
{
	$addparam .= "incldead=0&";
}
elseif ($include_dead == 1)		//active
{
	$addparam .= "incldead=1&";
	$wherea[] = "visible = 'yes'";
}
elseif ($include_dead == 2)		//dead
{
	$addparam .= "incldead=2&";
	$wherea[] = "visible = 'no'";
}
// ----------------- end include dead ---------------------//
$special_state = 0;
if ($_GET)
	$special_state = intval($_GET["spstate"] ?? 0);
elseif ($CURUSER['notifs']){
	if (strpos($CURUSER['notifs'], "[spstate=0]") !== false)
		$special_state = 0;
	elseif (strpos($CURUSER['notifs'], "[spstate=1]") !== false)
		$special_state = 1;
	elseif (strpos($CURUSER['notifs'], "[spstate=2]") !== false)
		$special_state = 2;
	elseif (strpos($CURUSER['notifs'], "[spstate=3]") !== false)
		$special_state = 3;
	elseif (strpos($CURUSER['notifs'], "[spstate=4]") !== false)
		$special_state = 4;
	elseif (strpos($CURUSER['notifs'], "[spstate=5]") !== false)
		$special_state = 5;
	elseif (strpos($CURUSER['notifs'], "[spstate=6]") !== false)
		$special_state = 6;
	elseif (strpos($CURUSER['notifs'], "[spstate=7]") !== false)
		$special_state = 7;
}

if (!in_array($special_state,array(0,1,2,3,4,5,6,7)))
{
	$special_state = 0;
	write_log("User " . $CURUSER["username"] . "," . $CURUSER["ip"] . " is hacking spstate field in " . $_SERVER['SCRIPT_NAME'], 'mod');
}
if($special_state == 0)	//all
{
	$addparam .= "spstate=0&";
}
elseif ($special_state == 1)	//normal
{
	$addparam .= "spstate=1&";

	$wherea[] = "sp_state = 1";

	if(get_global_sp_state() == 1)
	{
		$wherea[] = "sp_state = 1";
	}
}
elseif ($special_state == 2)	//free
{
	$addparam .= "spstate=2&";

	if(get_global_sp_state() == 1)
	{
		$wherea[] = "sp_state = 2";
	}
	else if(get_global_sp_state() == 2)
	{
		;
	}
}
elseif ($special_state == 3)	//2x up
{
	$addparam .= "spstate=3&";
	if(get_global_sp_state() == 1)	//only sp state
	{
		$wherea[] = "sp_state = 3";
	}
	else if(get_global_sp_state() == 3)	//all
	{
		;
	}
}
elseif ($special_state == 4)	//2x up and free
{
	$addparam .= "spstate=4&";

	if(get_global_sp_state() == 1)	//only sp state
	{
		$wherea[] = "sp_state = 4";
	}
	else if(get_global_sp_state() == 4)	//all
	{
		;
	}
}
elseif ($special_state == 5)	//half down
{
	$addparam .= "spstate=5&";

	if(get_global_sp_state() == 1)	//only sp state
	{
		$wherea[] = "sp_state = 5";
	}
	else if(get_global_sp_state() == 5)	//all
	{
		;
	}
}
elseif ($special_state == 6)	//half down
{
	$addparam .= "spstate=6&";

	if(get_global_sp_state() == 1)	//only sp state
	{
		$wherea[] = "sp_state = 6";
	}
	else if(get_global_sp_state() == 6)	//all
	{
		;
	}
}
elseif ($special_state == 7)	//30% down
{
	$addparam .= "spstate=7&";

	if(get_global_sp_state() == 1)	//only sp state
	{
		$wherea[] = "sp_state = 7";
	}
	else if(get_global_sp_state() == 7)	//all
	{
		;
	}
}

$category_get = intval($_GET["cat"] ?? 0);
$source_get = $medium_get = $codec_get = $standard_get = $processing_get = $team_get = $audiocodec_get = 0;
if ($showsubcat){
if ($showsource) $source_get = intval($_GET["source"] ?? 0);
if ($showmedium) $medium_get = intval($_GET["medium"] ?? 0);
if ($showcodec) $codec_get = intval($_GET["codec"] ?? 0);
if ($showstandard) $standard_get = intval($_GET["standard"] ?? 0);
if ($showprocessing) $processing_get = intval($_GET["processing"] ?? 0);
if ($showteam) $team_get = intval($_GET["team"] ?? 0);
if ($showaudiocodec) $audiocodec_get = intval($_GET["audiocodec"] ?? 0);
}

$all = intval($_GET["all"] ?? 0);

if (!$all)
{
	if (!$_GET && $CURUSER['notifs'])
	{
		$all = true;
		foreach ($cats as $cat)
		{
			$all &= $cat['id'];
			$mystring = $CURUSER['notifs'];
			$findme  = '[cat'.$cat['id'].']';
			$search = strpos($mystring, $findme);
			if ($search === false)
			$catcheck = false;
			else
			$catcheck = true;

			if ($catcheck)
			{
				$wherecatina[] = $cat['id'];
				$addparam .= "cat$cat[id]=1&";
			}
		}
		if ($showsubcat){
		if ($showsource)
		foreach ($sources as $source)
		{
			$all &= $source['id'];
			$mystring = $CURUSER['notifs'];
			$findme  = '[sou'.$source['id'].']';
			$search = strpos($mystring, $findme);
			if ($search === false)
			$sourcecheck = false;
			else
			$sourcecheck = true;

			if ($sourcecheck)
			{
				$wheresourceina[] = $source['id'];
				$addparam .= "source{$source['id']}=1&";
			}
		}
		if ($showmedium)
		foreach ($media as $medium)
		{
			$all &= $medium['id'];
			$mystring = $CURUSER['notifs'];
			$findme  = '[med'.$medium['id'].']';
			$search = strpos($mystring, $findme);
			if ($search === false)
			$mediumcheck = false;
			else
			$mediumcheck = true;

			if ($mediumcheck)
			{
				$wheremediumina[] = $medium['id'];
				$addparam .= "medium{$medium['id']}=1&";
			}
		}
		if ($showcodec)
		foreach ($codecs as $codec)
		{
			$all &= $codec['id'];
			$mystring = $CURUSER['notifs'];
			$findme  = '[cod'.$codec['id'].']';
			$search = strpos($mystring, $findme);
			if ($search === false)
			$codeccheck = false;
			else
			$codeccheck = true;

			if ($codeccheck)
			{
				$wherecodecina[] = $codec['id'];
				$addparam .= "codec{$codec['id']}=1&";
			}
		}
		if ($showstandard)
		foreach ($standards as $standard)
		{
			$all &= $standard['id'];
			$mystring = $CURUSER['notifs'];
			$findme  = '[sta'.$standard['id'].']';
			$search = strpos($mystring, $findme);
			if ($search === false)
			$standardcheck = false;
			else
			$standardcheck = true;

			if ($standardcheck)
			{
				$wherestandardina[] = $standard['id'];
				$addparam .= "standard{$standard['id']}=1&";
			}
		}
		if ($showprocessing)
		foreach ($processings as $processing)
		{
			$all &= $processing['id'];
			$mystring = $CURUSER['notifs'];
			$findme  = '[pro'.$processing['id'].']';
			$search = strpos($mystring, $findme);
			if ($search === false)
			$processingcheck = false;
			else
			$processingcheck = true;

			if ($processingcheck)
			{
				$whereprocessingina[] = $processing['id'];
				$addparam .= "processing{$processing['id']}=1&";
			}
		}
		if ($showteam)
		foreach ($teams as $team)
		{
			$all &= $team['id'];
			$mystring = $CURUSER['notifs'];
			$findme  = '[tea'.$team['id'].']';
			$search = strpos($mystring, $findme);
			if ($search === false)
			$teamcheck = false;
			else
			$teamcheck = true;

			if ($teamcheck)
			{
				$whereteamina[] = $team['id'];
				$addparam .= "team{$team['id']}=1&";
			}
		}
		if ($showaudiocodec)
		foreach ($audiocodecs as $audiocodec)
		{
			$all &= $audiocodec['id'];
			$mystring = $CURUSER['notifs'];
			$findme  = '[aud'.$audiocodec['id'].']';
			$search = strpos($mystring, $findme);
			if ($search === false)
			$audiocodeccheck = false;
			else
			$audiocodeccheck = true;

			if ($audiocodeccheck)
			{
				$whereaudiocodecina[] = $audiocodec['id'];
				$addparam .= "audiocodec{$audiocodec['id']}=1&";
			}
		}
		}
	}
	// when one clicked the cat, source, etc. name/image
	elseif ($category_get)
	{
		int_check($category_get,true,true,true);
		$wherecatina[] = $category_get;
		$addparam .= "cat=$category_get&";
	}
	elseif ($medium_get)
	{
		int_check($medium_get,true,true,true);
		$wheremediumina[] = $medium_get;
		$addparam .= "medium=$medium_get&";
	}
	elseif ($source_get)
	{
		int_check($source_get,true,true,true);
		$wheresourceina[] = $source_get;
		$addparam .= "source=$source_get&";
	}
	elseif ($codec_get)
	{
		int_check($codec_get,true,true,true);
		$wherecodecina[] = $codec_get;
		$addparam .= "codec=$codec_get&";
	}
	elseif ($standard_get)
	{
		int_check($standard_get,true,true,true);
		$wherestandardina[] = $standard_get;
		$addparam .= "standard=$standard_get&";
	}
	elseif ($processing_get)
	{
		int_check($processing_get,true,true,true);
		$whereprocessingina[] = $processing_get;
		$addparam .= "processing=$processing_get&";
	}
	elseif ($team_get)
	{
		int_check($team_get,true,true,true);
		$whereteamina[] = $team_get;
		$addparam .= "team=$team_get&";
	}
	elseif ($audiocodec_get)
	{
		int_check($audiocodec_get,true,true,true);
		$whereaudiocodecina[] = $audiocodec_get;
		$addparam .= "audiocodec=$audiocodec_get&";
	}
	else	//select and go
	{
		$all = True;
		foreach ($cats as $cat)
		{
		    $__is = (isset($_GET["cat{$cat['id']}"]) && $_GET["cat{$cat['id']}"]);
			$all &= $__is;
			if ($__is)
			{
				$wherecatina[] = $cat['id'];
				$addparam .= "cat{$cat['id']}=1&";
			}
		}
		if ($showsubcat){
		if ($showsource)
		foreach ($sources as $source)
		{
            $__is = (isset($_GET["source{$source['id']}"]) && $_GET["source{$source['id']}"]);
            $all &= $__is;
			if ($__is)
			{
				$wheresourceina[] = $source['id'];
				$addparam .= "source{$source['id']}=1&";
			}
		}
		if ($showmedium)
		foreach ($media as $medium)
		{
            $__is = (isset($_GET["medium{$medium['id']}"]) && $_GET["medium{$medium['id']}"]);
            $all &= $__is;
            if ($__is)
			{
				$wheremediumina[] = $medium['id'];
				$addparam .= "medium{$medium['id']}=1&";
			}
		}
		if ($showcodec)
		foreach ($codecs as $codec)
		{
            $__is = (isset($_GET["codec{$codec['id']}"]) && $_GET["codec{$codec['id']}"]);
            $all &= $__is;
            if ($__is)
			{
				$wherecodecina[] = $codec['id'];
				$addparam .= "codec{$codec['id']}=1&";
			}
		}
		if ($showstandard)
		foreach ($standards as $standard)
		{
            $__is = (isset($_GET["standard{$standard['id']}"]) && $_GET["standard{$standard['id']}"]);
            $all &= $__is;
            if ($__is)
			{
				$wherestandardina[] = $standard['id'];
				$addparam .= "standard{$standard['id']}=1&";
			}
		}
		if ($showprocessing)
		foreach ($processings as $processing)
		{
            $__is = (isset($_GET["processing{$processing['id']}"]) && $_GET["processing{$processing['id']}"]);
            $all &= $__is;
            if ($__is)
			{
				$whereprocessingina[] = $processing['id'];
				$addparam .= "processing{$processing['id']}=1&";
			}
		}
		if ($showteam)
		foreach ($teams as $team)
		{
            $__is = (isset($_GET["team{$team['id']}"]) && $_GET["team{$team['id']}"]);
            $all &= $__is;
            if ($__is)
			{
				$whereteamina[] = $team['id'];
				$addparam .= "team{$team['id']}=1&";
			}
		}
		if ($showaudiocodec)
		foreach ($audiocodecs as $audiocodec)
		{
            $__is = (isset($_GET["audiocodec{$audiocodec['id']}"]) && $_GET["audiocodec{$audiocodec['id']}"]);
            $all &= $__is;
            if ($__is)
			{
				$whereaudiocodecina[] = $audiocodec['id'];
				$addparam .= "audiocodec{$audiocodec['id']}=1&";
			}
		}
		}
	}
}

if ($all)
{
	//stderr("in if all","");
	$wherecatina = array();
	if ($showsubcat){
	$wheresourceina = array();
	$wheremediumina = array();
	$wherecodecina = array();
	$wherestandardina = array();
	$whereprocessingina = array();
	$whereteamina = array();
	$whereaudiocodecina = array();}
	$addparam .= "";
}
//stderr("", count($wherecatina)."-". count($wheresourceina));
$wherecatin = $wheresourcein = $wheremediumin = $wherecodecin = $wherestandardin = $whereprocessingin = $whereteamin = $whereaudiocodecin = '';
if (empty($wherecatina) && !(in_array($inclbookmarked, [1, 2]) && $allsec == 1)) {
    //require limit in some category
    $wherecatina = $allCategoryId;
}
if (count($wherecatina) > 1)
$wherecatin = implode(",",$wherecatina);
elseif (count($wherecatina) == 1)
$wherea[] = "category = $wherecatina[0]";

if ($showsubcat){
if ($showsource){
if (count($wheresourceina) > 1)
$wheresourcein = implode(",",$wheresourceina);
elseif (count($wheresourceina) == 1)
$wherea[] = "source = $wheresourceina[0]";}

if ($showmedium){
if (count($wheremediumina) > 1)
$wheremediumin = implode(",",$wheremediumina);
elseif (count($wheremediumina) == 1)
$wherea[] = "medium = $wheremediumina[0]";}

if ($showcodec){
if (count($wherecodecina) > 1)
$wherecodecin = implode(",",$wherecodecina);
elseif (count($wherecodecina) == 1)
$wherea[] = "codec = $wherecodecina[0]";}

if ($showstandard){
if (count($wherestandardina) > 1)
$wherestandardin = implode(",",$wherestandardina);
elseif (count($wherestandardina) == 1)
$wherea[] = "standard = $wherestandardina[0]";}

if ($showprocessing){
if (count($whereprocessingina) > 1)
$whereprocessingin = implode(",",$whereprocessingina);
elseif (count($whereprocessingina) == 1)
$wherea[] = "processing = $whereprocessingina[0]";}
}
if ($showteam){
if (count($whereteamina) > 1)
$whereteamin = implode(",",$whereteamina);
elseif (count($whereteamina) == 1)
$wherea[] = "team = $whereteamina[0]";}

if ($showaudiocodec){
if (count($whereaudiocodecina) > 1)
$whereaudiocodecin = implode(",",$whereaudiocodecina);
elseif (count($whereaudiocodecina) == 1)
$wherea[] = "audiocodec = $whereaudiocodecina[0]";}

$wherebase = $wherea;
$search_area = 0;
if (isset($searchstr))
{
	if (!isset($_GET['notnewword']) || !$_GET['notnewword']){
		insert_suggest($searchstr, $CURUSER['id']);
		$notnewword="";
	}
	else{
		$notnewword="notnewword=1&";
	}
	$search_mode = intval($_GET["search_mode"] ?? 0);
    /**
     * Deprecated search mode: 1(OR)
     * @since 1.8
     */
	if (!in_array($search_mode,array(0,2)))
	{
		$search_mode = 0;
		write_log("User " . $CURUSER["username"] . "," . $CURUSER["ip"] . " is hacking search_mode field in" . $_SERVER['SCRIPT_NAME'], 'mod');
	}

	$search_area = intval($_GET["search_area"] ?? 0) ;

	if ($search_area == 4) {
		$searchstr = (int)parse_imdb_id($searchstr);
	}
	$like_expression_array =array();
	unset($like_expression_array);

	switch ($search_mode)
	{
		case 0:	// AND, OR
		case 1	:
			{
				$searchstr = str_replace(".", " ", $searchstr);
				$searchstr_exploded = explode(" ", $searchstr);
				$searchstr_exploded_count= 0;
				foreach ($searchstr_exploded as $searchstr_element)
				{
					$searchstr_element = trim($searchstr_element);	// furthur trim to ensure that multi space seperated words still work
					$searchstr_exploded_count++;
					if ($searchstr_exploded_count > 10)	// maximum 10 keywords
					break;
					$like_expression_array[] = " LIKE '%" . $searchstr_element. "%'";
				}
				break;
			}
		case 2	:	// exact
		{
			$like_expression_array[] = " LIKE '%" . $searchstr. "%'";
			break;
		}
		/*case 3 :	// parsed
		{
		$like_expression_array[] = $searchstr;
		break;
		}*/
	}
	$ANDOR = ($search_mode == 0 ? " AND " : " OR ");	// only affects mode 0 and mode 1

	switch ($search_area)
	{
		case 0   :	// torrent name
		{
			foreach ($like_expression_array as &$like_expression_array_element)
			$like_expression_array_element = "(torrents.name" . $like_expression_array_element." OR torrents.small_descr". $like_expression_array_element.")";
			$wherea[] =  implode($ANDOR, $like_expression_array);
			break;
		}
		case 1	:	// torrent description
		{
			foreach ($like_expression_array as &$like_expression_array_element)
			$like_expression_array_element = "torrents.descr". $like_expression_array_element;
			$wherea[] =  implode($ANDOR,  $like_expression_array);
			break;
		}
		/*case 2	:	// torrent small description
		{
			foreach ($like_expression_array as &$like_expression_array_element)
			$like_expression_array_element =  "torrents.small_descr". $like_expression_array_element;
			$wherea[] =  implode($ANDOR, $like_expression_array);
			break;
		}*/
		case 3	:	// torrent uploader
		{
			foreach ($like_expression_array as &$like_expression_array_element)
			$like_expression_array_element =  "users.username". $like_expression_array_element;

			if(!isset($CURUSER))	// not registered user, only show not anonymous torrents
			{
				$wherea[] =  implode($ANDOR, $like_expression_array) . " AND torrents.anonymous = 'no'";
			}
			else
			{
				if(user_can('torrentmanage'))	// moderator or above, show all
				{
					$wherea[] =  implode($ANDOR, $like_expression_array);
				}
				else // only show normal torrents and anonymous torrents from hiself
				{
					$wherea[] =   "(" . implode($ANDOR, $like_expression_array) . " AND torrents.anonymous = 'no') OR (" . implode($ANDOR, $like_expression_array). " AND torrents.anonymous = 'yes' AND users.id=" . $CURUSER["id"] . ") ";
				}
			}
			break;
		}
		case 4  :  //imdb url
			foreach ($like_expression_array as &$like_expression_array_element)
			$like_expression_array_element = "torrents.url". $like_expression_array_element;
			$wherea[] =  implode($ANDOR,  $like_expression_array);
			break;
		default :	// unkonwn
		{
			$search_area = 0;
			$wherea[] =  "torrents.name LIKE '%" . $searchstr . "%'";
			write_log("User " . $CURUSER["username"] . "," . $CURUSER["ip"] . " is hacking search_area field in" . $_SERVER['SCRIPT_NAME'], 'mod');
			break;
		}
	}
	$addparam .= "search_area=" . $search_area . "&";
	$addparam .= "search=" . rawurlencode($searchstr) . "&".$notnewword;
	$addparam .= "search_mode=".$search_mode."&";
}

//approval status
$approvalStatusNoneVisible = get_setting('torrent.approval_status_none_visible');
$approvalStatusIconEnabled = get_setting('torrent.approval_status_icon_enabled');
$approvalStatus = null;
$showApprovalStatusFilter = false;
//when enable approval status icon, all user can use this filter, otherwise only staff member and approval none visible is 'no' can use
if ($approvalStatusIconEnabled == 'yes' || (user_can('torrent-approval') && $approvalStatusNoneVisible == 'no')) {
    $showApprovalStatusFilter = true;
}
//when user can use approval status filter, and pass `approval_status` parameter, will affect
//OR if [not approval can not be view] and not staff member, force to view  approval allowed
if ($showApprovalStatusFilter && isset($_REQUEST['approval_status']) && is_numeric($_REQUEST['approval_status'])) {
    $approvalStatus = intval($_REQUEST['approval_status']);
    $wherea[] = "torrents.approval_status = $approvalStatus";
    $searchParams['approval_status'] = $approvalStatus;
    $addparam .= "approval_status=$approvalStatus&";
} elseif ($approvalStatusNoneVisible == 'no' && !user_can('torrent-approval')) {
    $wherea[] = "torrents.approval_status = " . \App\Models\Torrent::APPROVAL_STATUS_ALLOW;
    $searchParams['approval_status'] = \App\Models\Torrent::APPROVAL_STATUS_ALLOW;
}

if (isset($_GET['size_begin']) && ctype_digit($_GET['size_begin'])) {
    $wherea[] = "torrents.size >= " . intval($_GET['size_begin']) * 1024 * 1024 * 1024;
    $addparam .= "size_begin=" . intval($_GET['size_begin']) . "&";
}
if (isset($_GET['size_end']) && ctype_digit($_GET['size_end'])) {
    $wherea[] = "torrents.size <= " . intval($_GET['size_end']) * 1024 * 1024 * 1024;
    $addparam .= "size_end=" . intval($_GET['size_end']) . "&";
}

if (isset($_GET['seeders_begin']) && ctype_digit($_GET['seeders_begin'])) {
    $wherea[] = "torrents.seeders >= " . (int)$_GET['seeders_begin'];
    $addparam .= "seeders_begin=" . intval($_GET['seeders_begin']) . "&";
}
if (isset($_GET['seeders_end']) && ctype_digit($_GET['seeders_end'])) {
    $wherea[] = "torrents.seeders <= " . (int)$_GET['seeders_end'];
    $addparam .= "seeders_end=" . intval($_GET['seeders_end']) . "&";
}

if (isset($_GET['leechers_begin']) && ctype_digit($_GET['leechers_begin'])) {
    $wherea[] = "torrents.leechers >= " . (int)$_GET['leechers_begin'];
    $addparam .= "leechers_begin=" . intval($_GET['leechers_begin']) . "&";
}
if (isset($_GET['leechers_end']) && ctype_digit($_GET['leechers_end'])) {
    $wherea[] = "torrents.leechers <= " . (int)$_GET['leechers_end'];
    $addparam .= "leechers_end=" . intval($_GET['leechers_end']) . "&";
}

if (isset($_GET['times_completed_begin']) && ctype_digit($_GET['times_completed_begin'])) {
    $wherea[] = "torrents.times_completed >= " . (int)$_GET['times_completed_begin'];
    $addparam .= "times_completed_begin=" . intval($_GET['times_completed_begin']) . "&";
}
if (isset($_GET['times_completed_end']) && ctype_digit($_GET['times_completed_end'])) {
    $wherea[] = "torrents.times_completed <= " . (int)$_GET['times_completed_end'];
    $addparam .= "times_completed_end=" . intval($_GET['times_completed_end']) . "&";
}

if (isset($_GET['added_begin']) && !empty($_GET['added_begin'])) {
    $wherea[] = "torrents.added >= " . sqlesc($_GET['added_begin']);
    $addparam .= "added_begin=" . $_GET['added_begin'] . "&";
}
if (isset($_GET['added_end']) && !empty($_GET['added_end'])) {
    $wherea[] = "torrents.added <= " . sqlesc(\Carbon\Carbon::parse($_GET['added_end'])->endOfDay()->toDateTimeString());
    $addparam .= "added_end=" . $_GET['added_end'] . "&";
}

$where = implode(" AND ", $wherea);

if ($wherecatin)
$where .= ($where ? " AND " : "") . "category IN(" . $wherecatin . ")";
if ($showsubcat){
if ($wheresourcein)
$where .= ($where ? " AND " : "") . "source IN(" . $wheresourcein . ")";
if ($wheremediumin)
$where .= ($where ? " AND " : "") . "medium IN(" . $wheremediumin . ")";
if ($wherecodecin)
$where .= ($where ? " AND " : "") . "codec IN(" . $wherecodecin . ")";
if ($wherestandardin)
$where .= ($where ? " AND " : "") . "standard IN(" . $wherestandardin . ")";
if ($whereprocessingin)
$where .= ($where ? " AND " : "") . "processing IN(" . $whereprocessingin . ")";
if ($whereteamin)
$where .= ($where ? " AND " : "") . "team IN(" . $whereteamin . ")";
if ($whereaudiocodecin)
$where .= ($where ? " AND " : "") . "audiocodec IN(" . $whereaudiocodecin . ")";
}

$tagFilter = "";
$tagId = intval($_REQUEST['tag_id'] ?? 0);
if ($tagId > 0) {
    $tagFilter = " inner join torrent_tags on torrents.id = torrent_tags.torrent_id and torrent_tags.tag_id = $tagId ";
    $addparam .= "tag_id={$tagId}&";
}
if ($allsec == 1 || $enablespecial != 'yes')
{
	if ($where != "")
		$where = "WHERE $where ";
	else $where = "";
	$sql = "SELECT COUNT(*) FROM torrents_tmp " . ($search_area == 3 || $column == "owner" ? "LEFT JOIN users ON torrents_tmp.owner = users.id " : "") . $tagFilter . $where;
}
else
{
//	if ($where != "")
//		$where = "WHERE $where AND categories.mode = '$sectiontype'";
//	else $where = "WHERE categories.mode = '$sectiontype'";

    if ($where != "")
        $where = "WHERE $where";
    else $where = "";
//	$sql = "SELECT COUNT(*), categories.mode FROM torrents LEFT JOIN categories ON category = categories.id " . ($search_area == 3 || $column == "owner" ? "LEFT JOIN users ON torrents.owner = users.id " : "") . $tagFilter . $where . " GROUP BY categories.mode";
	$sql = "SELECT COUNT(*) FROM torrents_tmp " . ($search_area == 3 || $column == "owner" ? "LEFT JOIN users ON torrents_tmp.owner = users.id " : "") . $tagFilter . $where;
}

if ($shouldUseMeili) {
    $searchRep = new \App\Repositories\MeiliSearchRepository();
    $resultFromSearchRep = $searchRep->search($searchParams, $CURUSER['id']);
    $count = $resultFromSearchRep['total'];
} else {
    $res = sql_query($sql);
    $count = 0;
    while($row = mysql_fetch_array($res)) {
        $count += $row[0];
    }
}

if ($CURUSER["torrentsperpage"])
$torrentsperpage = (int)$CURUSER["torrentsperpage"];
elseif ($torrentsperpage_main)
	$torrentsperpage = $torrentsperpage_main;
else $torrentsperpage = 100;

do_log("[TORRENT_COUNT_SQL] $sql", 'debug');

if ($count)
{
	if ($addparam != "")
	{
		if ($pagerlink != "")
		{
			if ($addparam[strlen($addparam)-1] != ";")
			{ // & = &amp;
				$addparam = $addparam . "&" . $pagerlink;
			}
			else
			{
				$addparam = $addparam . $pagerlink;
			}
		}
	}
	else
	{
		//stderr("in else","");
		$addparam = $pagerlink;
	}
	//stderr("addparam",$addparam);
	//echo $addparam;

	list($pagertop, $pagerbottom, $limit, $offset, $size, $page) = pager($torrentsperpage, $count, "?" . $addparam);
	$fieldsStr = implode(', ', \App\Models\Torrent::getFieldsForList(true));
//    if ($allsec == 1 || $enablespecial != 'yes') {
//        $query = "SELECT $fieldsStr FROM torrents ".($search_area == 3 || $column == "owner" ? "LEFT JOIN users ON torrents.owner = users.id " : "")." $tagFilter $where $orderby $limit";
//    } else {
//        $query = "SELECT $fieldsStr, categories.mode as search_box_id FROM torrents ".($search_area == 3 || $column == "owner" ? "LEFT JOIN users ON torrents.owner = users.id " : "")." LEFT JOIN categories ON torrents.category=categories.id $tagFilter $where $orderby $limit";
        $query = "SELECT * from torrents_tmp order by added desc";
//        $query = "SELECT $fieldsStr, $sectiontype as search_box_id FROM torrents ".($search_area == 3 || $column == "owner" ? "LEFT JOIN users ON torrents.owner = users.id " : "")."$tagFilter $where $orderby $limit";
//    }
    do_log("[TORRENT_LIST_SQL] $query", 'debug');
    if (!$shouldUseMeili) {
        $res = sql_query($query);
    }
} else {
    unset($res);
}

if (isset($searchstr))
	stdhead($lang_torrents['head_search_results_for'].$searchstr_ori);
elseif ($sectiontype == $browsecatmode)
	stdhead($lang_torrents['head_torrents']);
else stdhead($lang_torrents['head_special']);
print("<table width=\"97%\" class=\"main\" border=\"0\" cellspacing=\"0\" cellpadding=\"0\"><tr><td class=\"embedded\">");

displayHotAndClassic();
$searchBoxRightTdStyle = 'padding: 1px;padding-left: 10px;white-space: nowrap';
if ($allsec != 1 || $enablespecial != 'yes'){ //do not print searchbox if showing bookmarked torrents from all sections;
?>

<?php
}
	if ($Advertisement->enable_ad()){
        $belowsearchboxad = $Advertisement->get_ad('belowsearchbox');
        if (!empty($belowsearchboxad[0])) {
            echo "<div align=\"center\" style=\"margin-top: 10px\" id=\"\">".$belowsearchboxad[0]."</div>";
        }
	}
if($inclbookmarked == 1)
{
	print("<h1 align=\"center\">" . get_username($CURUSER['id']) . $lang_torrents['text_s_bookmarked_torrent'] . "</h1>");
}
elseif($inclbookmarked == 2)
{
	print("<h1 align=\"center\">" . get_username($CURUSER['id']) . $lang_torrents['text_s_not_bookmarked_torrent'] . "</h1>");
}

if ($count) {
    $rows = [];
    if ($shouldUseMeili) {
        $rows = $resultFromSearchRep['list'];
    } else {
        while ($row = mysql_fetch_assoc($res)) {
            $rows[] = $row;
        }
    }
    $rows = apply_filter('torrent_list', $rows, $page, $sectiontype, $_GET['search'] ?? '');
	print($pagertop);
	// #################种子列表部分#################
	torrentTmptable($rows, "torrents", $sectiontype);
// 不需要再判断了, 这里都是种子
//	if ($sectiontype == $browsecatmode)
//		torrenttable($rows, "torrents", $sectiontype);
//	elseif ($sectiontype == $specialcatmode)
//		torrenttable($rows, "music", $sectiontype);
//	else
//		torrenttable($rows, "bookmarks", $sectiontype);
	// #################种子列表部分#################
	print($pagerbottom);
}
else {
	if (isset($searchstr)) {
		print("<br />");
		stdmsg($lang_torrents['std_search_results_for'] . $searchstr_ori . "\"",$lang_torrents['std_try_again']);
	}
	else {
		stdmsg($lang_torrents['std_nothing_found'],$lang_torrents['std_no_active_torrents']);
	}
}
if ($CURUSER){
	if ($sectiontype == $browsecatmode)
		$USERUPDATESET[] = "last_browse = ".TIMENOW;
	else	$USERUPDATESET[] = "last_music = ".TIMENOW;
}
print("</td></tr></table>");
stdfoot();

echo "
<script>

</script>
";